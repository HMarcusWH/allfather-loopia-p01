<?php

declare(strict_types=1);

/**
 * P08 — the named P02/P03/P05 expansion concerns, re-run against CURRENT code.
 *
 * The canonical parity baseline (docs/reconciliation/canonical-parity-baseline-2026-09-27.md)
 * reproduced several shortcomings that gate broader product use. This suite runs each named scenario
 * and pins what the code does TODAY, classified one of three ways:
 *
 *   HOLDS                       the protection the scenario asks about is present
 *   KNOWN_DEFECT                reproduced; the repair belongs upstream first, then a port. The
 *                               assertion pins the CURRENT behaviour, so a repair has to flip it
 *                               on purpose rather than drift past it unnoticed
 *   NO_SEMANTIC_EVALUATION      not a bug in a check, but a check that does not exist: deterministic
 *                               markers cannot establish meaning, and nothing here claims they do
 *
 * Nothing in this file repairs anything. A green run says the register in
 * docs/P03_ASSURANCE_PORT.md §5 still describes the code; it does not say the products are ready to
 * expand. P02 foreign-scope working-state writes are NOT re-run here and remain recorded open there.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P05\MarketingPatterns;
use Allfather\Loopia\Substrate\M08\RuleEngine;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

// ============================================================ P05 lexical coverage (pure)

$lexical = [
    // [copy, comparative?, forbidden?, classification]
    ['Faster than the rival suite, at half the price.', true, false, 'HOLDS'],
    ['Unlike competitor X, we ship weekly.', true, false, 'HOLDS'],
    ['Fake scarcity: only 5 minutes left!', false, true, 'HOLDS'],
    ['Target pregnant shoppers with this offer.', false, true, 'HOLDS'],
    // Swedish comparative markers: "faster than the competitor", "compared with", "better than".
    ['Snabbare än konkurrenten, till halva priset.', false, false, 'KNOWN_DEFECT'],
    ['Jämfört med andra leverantörer är vi billigare.', false, false, 'KNOWN_DEFECT'],
    ['Bättre än alternativet på marknaden.', false, false, 'KNOWN_DEFECT'],
    // Swedish urgency / trait targeting: "only 5 minutes left", "target pregnant".
    ['Bara 5 minuter kvar – agera nu!', false, false, 'KNOWN_DEFECT'],
    ['Rikta erbjudandet mot gravida kunder.', false, false, 'KNOWN_DEFECT'],
];
foreach ($lexical as [$copy, $comparative, $forbidden, $classification]) {
    Assert::same(
        [$comparative, $forbidden],
        [MarketingPatterns::hasComparativeClaim($copy), MarketingPatterns::hasForbiddenPattern($copy)],
        "P05 lexical [{$classification}]: {$copy}",
    );
}

// NO_SEMANTIC_EVALUATION: a NEGATED comparison still triggers the citation requirement (over-trigger
// is the safe direction), and a comparison stated without any marker word is invisible. Neither is
// fixable by adding words; both need semantic evaluation that does not exist here.
Assert::true(MarketingPatterns::hasComparativeClaim('We are not better than any rival.'), 'P05 [NO_SEMANTIC_EVALUATION]: negation still over-triggers');
Assert::false(MarketingPatterns::hasComparativeClaim('Others take a week; we take a day.'), 'P05 [NO_SEMANTIC_EVALUATION]: an unmarked comparison is invisible');

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'expansion');

// ============================================================ P05 title scan

// KNOWN_DEFECT: the admission context the two P05 content rules read carries the draft BODY only. A
// comparative or forbidden claim placed in the TITLE is never evaluated by the gate. The draft row is
// injected with foreign-key checks suspended because the question is only what the context loader
// reads, not whether a governed path could reach this row.
$proposalId = Ids::new();
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    $db->insert('marketing_asset_drafts', [
        'marketing_asset_draft_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'], 'asset_kind' => 'COPY_VARIANT', 'proposal_id' => $proposalId,
        'released_briefing_id' => Ids::new(),
        'title_safe' => 'Better than the rival — fake scarcity edition', 'draft_content_safe' => 'A neutral body.',
        'asset_status' => 'IN_REVIEW', 'asset_digest' => str_repeat('f', 64), 'created_at' => $clock->now(),
    ]);
} catch (\PDOException $error) {
    Assert::fail('the title-scan scenario could not seed its draft: ' . $error->getMessage());
} finally {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}
$context = RuleEngine::loadP05Context($db, $proposalId);
Assert::true($context !== null, 'P05 title scan: the context loads');
Assert::false(array_key_exists('title_safe', $context ?? []), 'P05 title scan [KNOWN_DEFECT]: the gate context carries no title');
Assert::false(
    MarketingPatterns::hasComparativeClaim((string) ($context['draft_content_safe'] ?? ''))
        || MarketingPatterns::hasForbiddenPattern((string) ($context['draft_content_safe'] ?? '')),
    'P05 title scan [KNOWN_DEFECT]: so a comparative, forbidden title passes both content rules',
);

// ============================================================ P03 owner-upload licence mapping

// KNOWN_DEFECT: canonical projects a tenant's catalogue upload as `customer_upload` /
// `customer_owned_tenant_scoped` / LICENSED_INTERNAL_ONLY and refuses a public mapping. Here the
// catalogue owner checks ownership and approval only, so a TENANT_OWNED card carrying a PUBLIC web
// licence is accepted as catalogue authority.
$card = (new SourceCards($db, $clock))->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Tenant price list',
    'source_url_safe' => 'https://tenant.example/prices',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
    'data_ownership' => 'TENANT_OWNED',
]);
$registered = (new Catalogue($db, $clock))->registerCatalogue(
    $scope['tenant_id'], $scope['workspace_id'], 'public-mapped', 'Public-mapped', $card['source_ref_id'], $card['source_license_card_id']
);
Assert::true($registered->isOk(), 'P03 owner-upload mapping [KNOWN_DEFECT]: a public-licence tenant card still backs a catalogue');

// HOLDS: the three canonical P03 post-outcome audits now exist (P08) — the parity baseline's
// "assurance profiles are not ported" finding is closed by tests/conformance/p03_assurance.php.
Assert::same(
    ['CATALOGUE_FRESHNESS', 'SIGNAL_PAIRING_FRESHNESS', 'BUYER_INTENT_INVARIANT'],
    P03Assurance::CATEGORIES,
    'P03 assurance [HOLDS]: all three canonical audit families are ported',
);
