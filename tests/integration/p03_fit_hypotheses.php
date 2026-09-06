<?php

declare(strict_types=1);

/**
 * P03 catalogue, ICP and fit hypotheses.
 *
 * THE CLAIM UNDER TEST IS A NEGATIVE ONE: this path cannot say a prospect wants to buy.
 *
 * A fit hypothesis is a term-overlap measurement between what a customer sells and what a
 * competitor's page said. The strongest thing it can express is "there is a reason to investigate",
 * and the tests below prove that ceiling is structural rather than conventional — the claim kind is
 * not a parameter, and the storage vocabulary has no member meaning "intends to purchase".
 *
 * A product-fit hypothesis and buyer intent are claims about different things: one about a match,
 * the other about a person's state of mind. Promoting the first into the second would be
 * manufacturing evidence about people out of evidence about pages.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P03\FitHypotheses;
use Allfather\Loopia\Product\P03\FitScoring;
use Allfather\Loopia\Product\P03\Icp;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$cards = new SourceCards($db, $clock);
$catalogue = new Catalogue($db, $clock);
$icp = new Icp($db, $clock);
$fit = new FitHypotheses($db, $clock, new EvidenceSpine($db, $clock), $icp);

// ============================================================ the scorers are pure and modest

// Identical inputs always give identical bands. A score that could not be recomputed months later
// would not be evidence of anything.
Assert::same(
    FitScoring::scoreFit(['crm', 'billing', 'invoice'], ['crm', 'billing', 'invoice']),
    FitScoring::scoreFit(['crm', 'billing', 'invoice'], ['crm', 'billing', 'invoice']),
    'the scorer is deterministic',
);
Assert::same('INSUFFICIENT', FitScoring::scoreFit([], ['crm'])['kind'], 'nothing to compare is not a weak match');
Assert::same('INSUFFICIENT', FitScoring::scoreFit(['crm'], [])['kind'], 'in either direction');
Assert::same('UNFIT', FitScoring::scoreFit(['crm'], ['catering'])['band'], 'no overlap at all is UNFIT');
Assert::same(
    'STRONG',
    FitScoring::scoreFit(['crm', 'billing', 'invoice'], ['crm', 'billing', 'invoice', 'other'])['band'],
    'full coverage over three or more terms is STRONG',
);
Assert::same(
    'WEAK',
    FitScoring::scoreFit(['crm', 'billing', 'invoice', 'ledger', 'tax'], ['crm', 'unrelated'])['band'],
    'one term out of five is WEAK',
);

// The two tokenizers differ on purpose, and the difference is load-bearing. A tenant declaring
// "signal filter" as an excluded segment means those two words; the fit tokenizer's stopword list
// would degrade it to "filter" and match segments they never excluded.
Assert::equalsList(['filter'], FitScoring::fitTerms('signal filter'), 'the fit tokenizer drops stopwords');
Assert::equalsList(
    ['signal', 'filter'],
    FitScoring::icpTerms('signal filter'),
    'the ICP tokenizer does NOT — a declared segment means the words the tenant wrote',
);

// ============================================================ the catalogue rides a licence

$card = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Our own product list',
    'source_url_safe' => 'https://tenant.example/products',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
    // A customer catalogue is the TENANT's own material; the card must say so.
    'data_ownership' => 'TENANT_OWNED',
]);
Assert::same('PROJECTED', $card['outcome'], 'the tenant\'s own catalogue source is admissible');

// A catalogue is not free-floating data: it needs the same three authorities a competitor source
// needs. A tenant's own product list is still material the product reasons over.
$unapproved = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Draft list',
    'source_url_safe' => 'https://tenant.example/draft',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'draft',
]);
Assert::same(
    'SOURCE_CARD_NOT_APPROVED',
    $catalogue->registerCatalogue(
        $scope['tenant_id'], $scope['workspace_id'], 'draft', 'Draft',
        $unapproved['source_ref_id'], $unapproved['source_license_card_id']
    )->reasonCode(),
    'an unapproved card cannot back a catalogue',
);
// A SECOND approved card, so the mismatch case isolates the reference-binding check rather than
// tripping the approval gate first.
$otherApproved = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'A different list',
    'source_url_safe' => 'https://tenant.example/other',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
    // A customer catalogue is the TENANT's own material; the card must say so.
    'data_ownership' => 'TENANT_OWNED',
]);
Assert::same(
    'SOURCE_CARD_REFERENCE_MISMATCH',
    $catalogue->registerCatalogue(
        $scope['tenant_id'], $scope['workspace_id'], 'mismatch', 'Mismatch',
        $card['source_ref_id'], $otherApproved['source_license_card_id']
    )->reasonCode(),
    "and one source's approved card is not authority for another source",
);

$catalogueId = $catalogue->registerCatalogue(
    $scope['tenant_id'], $scope['workspace_id'], 'main', 'Main catalogue',
    $card['source_ref_id'], $card['source_license_card_id']
)->value()['customer_product_catalogue_id'];

// A product with no attributes could never match anything — recording one would create an entry
// that silently never produces a hypothesis.
Assert::same(
    'PRODUCT_ATTRIBUTES_REQUIRED',
    $catalogue->addProduct($catalogueId, 'empty', 'Empty', 'PRODUCT', [])->reasonCode(),
    'a product with no attributes is refused rather than silently unmatched',
);

$productId = $catalogue->addProduct(
    $catalogueId, 'pricing-suite', 'Pricing Suite', 'PRODUCT',
    ['pricing', 'renewal', 'billing', 'contract']
)->value()['customer_product_id'];

// ============================================================ the ICP is a declaration

// A profile is a hypothesis about a market. One declaring no limitations would be claiming a
// certainty nothing supports.
Assert::same(
    'ICP_UNCERTAINTY_NOTES_REQUIRED',
    $icp->declareVersion($scope['tenant_id'], $scope['workspace_id'], 'ICP', [
        'industries' => ['software'],
        'uncertainty_notes_safe' => '   ',
    ])->reasonCode(),
    'an ICP version must state its uncertainty',
);

$profile = $icp->declareVersion($scope['tenant_id'], $scope['workspace_id'], 'Nordic SME software', [
    'industries' => ['software', 'saas'],
    'geographies' => ['sweden'],
    'company_size_band' => 'SMALL_10_49',
    // ROLE CLASSES, never individuals. No field here can hold a named person.
    'buyer_role_classes' => ['finance decision maker'],
    'excluded_segments' => ['public sector'],
    'uncertainty_notes_safe' => 'Declared from three closed deals; not market-researched.',
]);
Assert::true($profile->isOk(), 'a complete ICP version is declared');
Assert::same(1, $profile->value()['version_number'], 'as version 1');

// Profiles are VERSIONED, not overwritten: a hypothesis derived last month was derived against the
// profile as it was then, and re-reading it against today's would misdescribe what happened.
$v2 = $icp->declareVersion($scope['tenant_id'], $scope['workspace_id'], 'Nordic SME software', [
    'industries' => ['software'],
    'uncertainty_notes_safe' => 'Narrowed after a lost deal.',
], $profile->value()['icp_profile_id']);
Assert::same(2, $v2->value()['version_number'], 'a change creates a new version');
Assert::same(
    2,
    (int) $db->scalar('SELECT COUNT(*) FROM icp_profile_versions WHERE icp_profile_id = ?', [
        $profile->value()['icp_profile_id'],
    ]),
    'and the earlier version stays readable',
);

// ============================================================ pairing needs a RELEASED signal

$governed = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF');
$signalId = Ids::new();
$db->insert('company_signals', [
    'company_signal_id' => $signalId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'competitor_id' => $governed['competitor_id'],
    'source_diff_id' => $governed['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'Competitor raised renewal pricing and changed contract billing terms.',
    'proposal_id' => $governed['proposal_id'],
    'status' => 'UNDER_REVIEW',
    'created_at' => $clock->now(),
]);

$pairInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'customer_product_id' => $productId,
    'company_signal_id' => $signalId,
];

// A draft or under-review signal cannot back a lead: it would be building on something the review
// plane had not yet admitted.
Assert::same(
    'SIGNAL_NOT_RELEASED',
    $fit->pair($pairInput)->reasonCode(),
    'an unreleased signal cannot back a fit hypothesis',
);

$db->run("UPDATE company_signals SET status = 'RELEASED' WHERE company_signal_id = ?", [$signalId]);

// A suspended catalogue stops new hypotheses IMMEDIATELY — status is read at pairing time, which is
// what makes it a control rather than a label.
$catalogue->changeCatalogueStatus($catalogueId, 'SUSPENDED', 'PAUSED_BY_OPERATOR');
Assert::same(
    'CATALOGUE_NOT_ACTIVE',
    $fit->pair($pairInput)->reasonCode(),
    'a suspended catalogue stops new hypotheses at once',
);
$catalogue->changeCatalogueStatus($catalogueId, 'ACTIVE', 'RESUMED');

// ============================================================ the derived claim, and its ceiling

$derived = $fit->pair($pairInput);
Assert::true($derived->isOk(), 'a matching product and released signal derive a hypothesis');
Assert::same('DERIVED', $derived->value()['outcome'], 'as a new derivation');

// THE CEILING. The strongest thing this row can say is that somebody should look.
Assert::same(
    'REASON_TO_INVESTIGATE',
    $derived->value()['claim_kind'],
    'and the claim it makes is REASON_TO_INVESTIGATE — never buyer intent',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT uncertainty_explicit FROM fit_hypotheses WHERE fit_hypothesis_id = ?', [
        $derived->value()['fit_hypothesis_id'],
    ]),
    'with its uncertainty explicitly flagged, not optionally',
);

// The ceiling is STRUCTURAL, not conventional: the storage vocabulary has no member that could
// express buyer intent, so no code path — however written — could store one.
Assert::throws(
    static fn () => $db->run(
        'UPDATE fit_hypotheses SET claim_kind = ? WHERE fit_hypothesis_id = ?',
        ['BUYER_INTENT', $derived->value()['fit_hypothesis_id']]
    ),
    \PDOException::class,
    'and the storage vocabulary cannot express buyer intent at all',
);

// The derivation is EVIDENCE, so the lead has a citable basis rather than being a conclusion with
// nothing behind it.
Assert::same(
    'FIT_HYPOTHESIS_BASIS_REFERENCE',
    (string) $db->scalar('SELECT evidence_type FROM evidence_objects WHERE evidence_id = ?', [
        $derived->value()['basis_evidence_id'],
    ]),
    'the derivation itself is recorded as evidence',
);
Assert::same(
    'FIT_MATCH_DERIVATION',
    (string) $db->scalar('SELECT capture_method FROM evidence_objects WHERE evidence_id = ?', [
        $derived->value()['basis_evidence_id'],
    ]),
    'captured by derivation — nothing was observed about anyone',
);

// The ICP component is SEPARATE from the fit band and pinned to a profile VERSION, so "why was this
// surfaced?" stays answerable.
$icpFit = $derived->value()['icp_fit'];
Assert::notSame(null, $icpFit, 'the ICP component is scored');
Assert::same(
    $v2->value()['icp_profile_version_id'],
    $icpFit['icp_profile_version_id'],
    'against the LATEST profile version, pinned by id',
);
Assert::same(
    FitScoring::ICP_SCORER_VERSION,
    $icpFit['scorer_version'],
    'and pinned to a scorer version, so an unbumped drift is a finding rather than an explanation',
);

// Deriving the same assessment again RECOGNIZES itself rather than accumulating a near-duplicate.
$again = $fit->pair($pairInput);
Assert::same('ALREADY_DERIVED', $again->value()['outcome'], 'a repeat derivation replays');
Assert::same(
    $derived->value()['fit_hypothesis_id'],
    $again->value()['fit_hypothesis_id'],
    'returning the same hypothesis',
);

// ============================================================ a non-match is refused, not recorded weakly

$unrelatedProductId = $catalogue->addProduct(
    $catalogueId, 'catering', 'Catering Services', 'SERVICE', ['catering', 'venue', 'hospitality']
)->value()['customer_product_id'];
Assert::same(
    'OUTSIDE_CUSTOMER_INTEREST',
    $fit->pair([...$pairInput, 'customer_product_id' => $unrelatedProductId])->reasonCode(),
    'a product with no overlap is refused outright rather than recorded as a weak lead',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM fit_hypotheses WHERE tenant_id = ?', [$scope['tenant_id']]),
    'and leaves no hypothesis row behind',
);

// ============================================================ nothing here is about a person

Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM fit_hypotheses WHERE claim_kind <> 'REASON_TO_INVESTIGATE'
           AND tenant_id = ?",
        [$scope['tenant_id']]
    ),
    'every hypothesis in this tenant carries the same modest claim',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM fit_hypotheses WHERE uncertainty_explicit = 0'),
    'and every one of them declares its uncertainty',
);
