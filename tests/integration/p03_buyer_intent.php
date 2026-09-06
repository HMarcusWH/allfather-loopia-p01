<?php

declare(strict_types=1);

/**
 * The buyer-intent invariant, proved on the path that could break it.
 *
 * P03 has two lead-qualification admissions and they make DIFFERENT claims:
 *
 *   WATCH LIST      "There is a reason to look at this."   — a claim about a MATCH.
 *   PRIORITY QUEUE  "There is a timely reason to act."     — a claim about a MOMENT.
 *
 * Only the second can overclaim, and only the second is constrained. Asserting a moment requires a
 * buying TRIGGER signal that was directly OBSERVED; without both, the admission BLOCKS as a
 * buyer-intent overclaim and terminates the hypothesis.
 *
 * The invariant is held in four independent places, and this file exercises all four: the pinned
 * claim kind, the arbiter rule, the required trigger column, and the storage vocabulary.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P03\FitHypotheses;
use Allfather\Loopia\Product\P03\Icp;
use Allfather\Loopia\Product\P03\ProspectQueue;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
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
$evidence = new EvidenceSpine($db, $clock);
$proposals = new Proposals($db, $clock);
$m03 = new M03Registry($db, $clock);
$icp = new Icp($db, $clock);
$cards = new SourceCards($db, $clock);
$catalogue = new Catalogue($db, $clock);
$fit = new FitHypotheses($db, $clock, $evidence, $icp);
$queue = new ProspectQueue(
    $db, $clock, $proposals, $evidence, new Readiness($db, $clock),
    new Arbiter($db, $clock, new Readiness($db, $clock))
);

$policySetId = (new PolicySets($db, $clock))
    ->seed(PolicySets::P03_PROSPECT_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

// --- the catalogue and the governance the admissions ride ----------------------------------------

$card = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Our product list',
    'source_url_safe' => 'https://tenant.example/products',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
    // A customer catalogue is the TENANT's own material; the card must say so.
    'data_ownership' => 'TENANT_OWNED',
]);
$catalogueId = $catalogue->registerCatalogue(
    $scope['tenant_id'], $scope['workspace_id'], 'main', 'Main',
    $card['source_ref_id'], $card['source_license_card_id']
)->value()['customer_product_catalogue_id'];
$productId = $catalogue->addProduct(
    $catalogueId, 'pricing-suite', 'Pricing Suite', 'PRODUCT',
    ['pricing', 'renewal', 'billing', 'contract']
)->value()['customer_product_id'];

$producer = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Lead Qualifier',
    'COMPETITION_MONITOR_VALKYRIE', 'Derives fit hypotheses.', $scope['operator_id'], 'ACTIVE'
);
$contractId = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p03.qualify',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE']
)->value()['capability_contract_id'];
$missionId = $proposals->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
    'Qualify leads', 'Pair released signals with catalogue products.',
    ['OBSERVE', 'ANALYZE'], 'OPERATOR', $scope['operator_id']
);
$governance = [
    'mission_id' => $missionId,
    'producing_valkyrie_id' => $producer,
    'capability_contract_id' => $contractId,
];

/**
 * A RELEASED signal of a chosen class and interpretation, paired into a fit hypothesis.
 *
 * The class and interpretation are the two things the buyer-intent rule reads, so every case below
 * varies exactly one of them and holds the rest constant.
 */
$hypothesisFor = static function (string $signalClass, string $interpretationKind, string $summary)
use ($db, $clock, $scope, $fit, $productId): string {
    $governed = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF');
    $signalId = Ids::new();
    $db->insert('company_signals', [
        'company_signal_id' => $signalId,
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'competitor_id' => $governed['competitor_id'],
        'source_diff_id' => $governed['source_diff_id'],
        'signal_class' => $signalClass,
        'interpretation_kind' => $interpretationKind,
        'confidence_band' => 'HIGH',
        'summary_safe' => $summary,
        'proposal_id' => $governed['proposal_id'],
        'status' => 'RELEASED',
        'created_at' => $clock->now(),
    ]);

    return $fit->pair([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'customer_product_id' => $productId,
        'company_signal_id' => $signalId,
    ])->value()['fit_hypothesis_id'];
};

$run = static function (string $hypothesisId, string $path)
use ($queue, $scope, $governance, $policySetId): array {
    $submitted = $queue->submitForAdmission([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'fit_hypothesis_id' => $hypothesisId,
        'admission_path' => $path,
        'governance' => $governance,
    ]);
    Assert::true($submitted->isOk(), "the {$path} admission submits");

    return $queue->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'fit_hypothesis_id' => $hypothesisId,
        'arbiter_policy_set_id' => $policySetId,
    ])->value();
};

$triggerSummary = 'Competitor raised renewal pricing and changed contract billing terms.';

// ============================================================ the watch list never overclaims

// A NON-trigger class, only HYPOTHESIZED — the weakest possible evidence — is still admissible to
// the WATCH LIST, because a watch-list entry asserts only a match. A rule that fired here would be
// refusing a claim nobody made.
$watchListed = $run(
    $hypothesisFor('MARKET_CONTEXT_SIGNAL', 'HYPOTHESIS', 'Competitor may be repositioning around billing and renewal contracts.'),
    'FIT_HYPOTHESIS'
);
Assert::same(
    'ADMITTED_TO_WATCH_LIST',
    $watchListed['outcome'],
    'the weakest evidence still reaches the watch list — it asserts only a match',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM priority_outreach_queue_items'),
    'and creates NO queue item — the two paths produce different things',
);

// ============================================================ the queue is constrained

// A trigger class that was only HYPOTHESIZED is a GUESS about a moment.
$guessedMoment = $run(
    $hypothesisFor('PRICING_CHANGE_SIGNAL', 'HYPOTHESIS', $triggerSummary),
    'PRIORITY_OUTREACH_QUEUE_ADMISSION'
);
Assert::same('REFUSED', $guessedMoment['outcome'], 'a hypothesized trigger cannot support a queue admission');
Assert::same('BLOCK', $guessedMoment['verdict'], 'and it BLOCKS rather than holding');
Assert::true(
    in_array('BUYER_INTENT_OVERCLAIM', $guessedMoment['reason_codes'], true),
    'named as a buyer-intent overclaim',
);
Assert::true(
    $guessedMoment['hypothesis_terminated'],
    'terminating the hypothesis — a block here is not an invitation to revise, because the claim was never available',
);

// An OBSERVED fact of the WRONG class is a real event with no bearing on buying.
$wrongClass = $run(
    $hypothesisFor('NEGATIVE_FEEDBACK_SIGNAL', 'FACT_OBSERVED', $triggerSummary),
    'PRIORITY_OUTREACH_QUEUE_ADMISSION'
);
Assert::same('REFUSED', $wrongClass['outcome'], 'an observed fact of the wrong class cannot support it either');
Assert::true(
    in_array('BUYER_INTENT_OVERCLAIM', $wrongClass['reason_codes'], true),
    'by the same code — both halves of the rule are required',
);

// BOTH halves together: a trigger class, directly observed. NOW the queue admission is available.
$admitted = $run(
    $hypothesisFor('PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED', $triggerSummary),
    'PRIORITY_OUTREACH_QUEUE_ADMISSION'
);
Assert::same('ADMITTED', $admitted['outcome'], 'an observed trigger supports a queue admission');
Assert::same('ALLOW_INFORMATIONAL_RELEASE', $admitted['verdict'], 'with an allowing verdict');
Assert::notSame(null, $admitted['priority_outreach_queue_item_id'], 'and a queue item is created');

// The queue item CITES its trigger. The column is required, so an item that cited none could not be
// written even if the code tried — the storage says the same thing the arbiter rule does.
$item = $db->one(
    'SELECT triggering_company_signal_id, queue_status FROM priority_outreach_queue_items
     WHERE priority_outreach_queue_item_id = ?',
    [$admitted['priority_outreach_queue_item_id']]
);
Assert::same(
    $admitted['triggering_company_signal_id'],
    (string) $item['triggering_company_signal_id'],
    'citing the exact signal that established the moment',
);
Assert::throws(
    static fn () => $db->insert('priority_outreach_queue_items', [
        'priority_outreach_queue_item_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'fit_hypothesis_id' => $admitted['triggering_company_signal_id'],
        'triggering_company_signal_id' => null,
        'arbiter_verdict_id' => $admitted['arbiter_verdict_id'],
        'queue_status' => 'OPEN',
        'created_at' => $clock->now(),
    ]),
    \PDOException::class,
    'and a queue item citing NO trigger cannot be written at all',
);

// ============================================================ a queue item is not an outreach act

Assert::same(
    'OPEN',
    (string) $item['queue_status'],
    'the item is OPEN — a signal to a human that outreach MIGHT be warranted',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM tool_execution_outcomes WHERE outcome_classification = 'DEMO_ACTION_OUTCOME'"
    ),
    'nothing in this path performed an action of any kind',
);
Assert::same(
    0,
    (int) $db->scalar("SELECT COUNT(*) FROM runtime_host_execution_refs WHERE status = 'SUCCEEDED'"),
    'and no host was contacted — no message left this module',
);

// ============================================================ the ICP exclusion is a tenant declaration

// A tenant declaring a segment out of scope has said something the positive dimensions must not
// outvote. The declaration is matched WHOLE — "public sector" means both words.
$icp->declareVersion($scope['tenant_id'], $scope['workspace_id'], 'Nordic SME', [
    'industries' => ['software'],
    'excluded_segments' => ['renewal billing'],
    'uncertainty_notes_safe' => 'Declared from two lost deals.',
]);
$excluded = $run(
    $hypothesisFor('PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED', $triggerSummary),
    'PRIORITY_OUTREACH_QUEUE_ADMISSION'
);
Assert::same('REFUSED', $excluded['outcome'], 'a prospect matching a declared excluded segment is refused');
Assert::true(
    in_array('ICP_EXCLUDED_SEGMENT_MATCH', $excluded['reason_codes'], true),
    'named as an excluded-segment match, not as a weak fit',
);

// ============================================================ nothing here claims intent

Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM fit_hypotheses WHERE claim_kind <> 'REASON_TO_INVESTIGATE'"
    ),
    'every hypothesis, admitted or refused, carries the same modest claim',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM priority_outreach_queue_items'),
    'and exactly one queue item exists — the one whose moment was actually observed',
);
