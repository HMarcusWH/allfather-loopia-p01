<?php

declare(strict_types=1);

/**
 * ACCEPTANCE: the three golden flows, end to end, in one tenant.
 *
 * Every other test proves a hop. This one proves the JOURNEY — that a competitor's public page
 * becomes a briefing an authorized colleague can read, that a catalogue and a signal become a
 * prioritized prospect, and that a twin version becomes released internal intelligence. Each is
 * driven through the real product modules from end to end, in the SAME scope, because a vertical
 * that works alone and breaks beside its neighbours is not a product.
 *
 * The three flows share a substrate, and the shared invariants are checked ACROSS all of them at
 * the end rather than per-flow. That is the interesting part: the ceiling, the M10 binding, and the
 * absence of any external effect are properties of the whole system, and a test that checked them
 * one vertical at a time would miss the case where the third one is the exception.
 *
 * WHAT THIS TEST DOES NOT DO, and could not: reach the network, send anything, or write to any
 * external system. Those absences are asserted at the end, over the whole database.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\TwinAdmission;
use Allfather\Loopia\Product\P02\TwinRelease;
use Allfather\Loopia\Product\P02\TwinStateChange;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P03\FitHypotheses;
use Allfather\Loopia\Product\P03\Icp;
use Allfather\Loopia\Product\P03\ProspectQueue;
use Allfather\Loopia\Product\P03\Release as P03Release;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\P02Adapter;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
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

$ledger = new DecisionLedger($db, $clock);
$proposals = new Proposals($db, $clock);
$evidence = new EvidenceSpine($db, $clock);
$readiness = new Readiness($db, $clock);
$council = new Council($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);
$access = new AccessGuard($db, $clock);
$entitlements = new Entitlements($db, $clock);
$m03 = new M03Registry($db, $clock);
$policySets = new PolicySets($db, $clock);

// The reader is ONE operator holding ONE role across all three verticals. A product whose surfaces
// each needed their own operator would not be one product.
$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'Commercial analyst');
$role = Seed::role($db, $clock, 'analyst-' . substr(Ids::new(), 0, 8), [
    'battlecard.read', 'priority_outreach_queue.read', 'entity_twin.read',
]);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $reader, $role['role_id']);
$tierId = $entitlements->defineTier('golden_flows', 'Golden Flows', 199900, [
    'battlecard.read', 'priority_outreach_queue.read', 'entity_twin.read',
])->value()['subscription_tier_id'];
$entitlements->subscribe($scope['tenant_id'], $tierId);

// ============================================================ P01 — a public change becomes a briefing

Assert::case('GOLDEN P01: a competitor\'s published change reaches an authorized colleague');

$p01 = Seed::p01ReleasedBriefing($db, $clock, $scope);

$briefings = (new Surfacing($db, $access, $ledger, $entitlements))->listVisibleReleasedBriefings([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $reader,
]);
Assert::same('VISIBLE', $briefings['decision'], 'the briefing surface opens for the analyst');
Assert::same(1, count($briefings['briefings']), 'and carries the released briefing');
Assert::same(
    $p01['released_briefing_id'],
    (string) $briefings['briefings'][0]['released_briefing_id'],
    'which is the one the governed path released',
);

// The claim the product is allowed to make about it: the briefing separates what was OBSERVED from
// what is HYPOTHESISED, and says which claims it refused to make.
// `battlecard_briefs` is P01's; `prospect_battlecard_briefs` is P03's, and they are different
// objects with different columns. The two names are close enough to confuse, so this reads the
// P01 one by name rather than by whichever happens to answer.
$brief = $db->one(
    'SELECT customer_impact_hypothesis_safe, rejected_claims_safe FROM battlecard_briefs
     WHERE battlecard_brief_id = ?',
    [$p01['battlecard_brief_id']]
);
Assert::true($brief !== null, 'the brief row is readable');
Assert::contains('not observed', strtolower((string) $brief['customer_impact_hypothesis_safe']),
    'the customer-impact line marks itself as a hypothesis, not an observation');
Assert::contains('not supported', strtolower((string) $brief['rejected_claims_safe']),
    'and the brief records what it REFUSED to claim — the half a summary would drop');

// ============================================================ P03 — catalogue plus signal becomes a prospect

Assert::case('GOLDEN P03: the tenant\'s own catalogue turns a signal into a prioritized prospect');

$cards = new SourceCards($db, $clock);
$catalogue = new Catalogue($db, $clock);
$icp = new Icp($db, $clock);
$fit = new FitHypotheses($db, $clock, $evidence, $icp);
$queue = new ProspectQueue($db, $clock, $proposals, $evidence, $readiness, $arbiter);
$p03Release = new P03Release($db, $clock, $ledger, $access, $entitlements);

$card = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Our product list',
    'source_url_safe' => 'https://tenant.example/products',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
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

$p03Chain = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF');
$signalId = Ids::new();
$db->insert('company_signals', [
    'company_signal_id' => $signalId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'competitor_id' => $p03Chain['competitor_id'],
    'source_diff_id' => $p03Chain['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'Competitor raised renewal pricing and changed contract billing terms.',
    'proposal_id' => $p03Chain['proposal_id'],
    'status' => 'RELEASED',
    'created_at' => $clock->now(),
]);

$hypothesisId = $fit->pair([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'customer_product_id' => $productId,
    'company_signal_id' => $signalId,
])->value()['fit_hypothesis_id'];

$p03Governance = [
    'mission_id' => $p03Chain['mission_id'],
    'producing_valkyrie_id' => $p03Chain['producing_valkyrie_id'],
    'capability_contract_id' => $p03Chain['capability_contract_id'],
];
$p03PolicySetId = $policySets
    ->seed(PolicySets::P03_PROSPECT_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

$queue->submitForAdmission([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'fit_hypothesis_id' => $hypothesisId,
    'admission_path' => 'PRIORITY_OUTREACH_QUEUE_ADMISSION',
    'governance' => $p03Governance,
]);
$queueItemId = (string) $queue->admit([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'fit_hypothesis_id' => $hypothesisId,
    'arbiter_policy_set_id' => $p03PolicySetId,
])->value()['priority_outreach_queue_item_id'];

$p03Released = $p03Release->releaseProspectOutput([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
    'output_id' => $queueItemId,
]);
Assert::true($p03Released->isOk(), 'the admitted queue item releases');

$prospects = $p03Release->listVisibleProspectOutputs([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $reader,
]);
Assert::same('VISIBLE', $prospects['decision'], 'and the SAME analyst sees it on the prospect surface');
Assert::same(1, count($prospects['rows']), 'one prioritized prospect');

// A FIT IS NOT AN INTENT, and the surface says so in the row itself rather than in a footnote.
// The closed vocabulary has exactly two members, and NEITHER of them asserts intent: a fit
// hypothesis says the shapes match, a reason to investigate says it is worth a look. There is no
// third value the product could surface that would mean "this company wants to buy".
Assert::true(
    in_array((string) $prospects['rows'][0]['claim_kind'], ['FIT_HYPOTHESIS', 'REASON_TO_INVESTIGATE'], true),
    'the surfaced claim is one of the two the vocabulary allows',
);
// Read back from the SERVER, not from a constant: the vocabulary is closed at the storage layer,
// so there is no third value any code path could write, whatever it wanted to say.
$claimKinds = [];
foreach ($db->all(
    "SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'fit_hypotheses'
       AND CHECK_CLAUSE LIKE '%claim_kind%'"
) as $row) {
    if (preg_match('/`claim_kind`\s+in\s+\((.*?)\)/is', (string) $row['clause'], $m) === 1) {
        preg_match_all("/'([^']+)'/", $m[1], $literals);
        $claimKinds = array_merge($claimKinds, $literals[1]);
    }
}
sort($claimKinds);
Assert::equalsList(
    ['FIT_HYPOTHESIS', 'REASON_TO_INVESTIGATE'],
    $claimKinds,
    'and storage admits exactly two claim kinds, neither of which asserts that anyone wants to buy',
);

// ============================================================ P02 — a twin version becomes released state

Assert::case('GOLDEN P02: a curated twin version becomes released internal intelligence');

$twins = new EntityTwins($db, $clock);
$stateChange = new TwinStateChange($db, $clock, $proposals, $evidence, $readiness, $council);
$twinAdmission = new TwinAdmission($db, $clock, $arbiter, $stateChange);
$m16Output = new ReleaseOutput($db, $clock, $ledger);
$p02Adapter = new P02Adapter($db, $m16Output);
$twinRelease = new TwinRelease($db, $clock, $ledger, $twins, $access, $entitlements, $p02Adapter);
$p02PolicySetId = $policySets
    ->seed(PolicySets::P02_TWIN_STATE_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

$twinId = $twins->registerTwin(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Rival AB', [], $scope['operator_id']
)->value()['entity_twin_id'];
$twinVersionId = $twins->appendVersion([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $twinId,
    'claim_ceiling' => 'INTERNAL_REVIEWED',
    'fields' => [
        ['field_key' => 'industry', 'field_value_safe' => 'Retail software', 'value_status' => 'PUBLIC_SOURCE'],
        ['field_key' => 'headcount', 'field_value_safe' => null, 'value_status' => 'UNKNOWN'],
    ],
    'uncertainty' => ['summary_safe' => 'Headcount is unknown; segment is inferred from the public site.'],
    'source_links' => [[
        'source_ref_id' => $card['source_ref_id'],
        'source_basis' => 'PUBLIC_SOURCE',
        'allowed_use' => 'DERIVED_SUMMARY_ALLOWED',
    ]],
])->value()['entity_twin_version_id'];

$changeId = $stateChange->create([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_version_id' => $twinVersionId,
    'requested_visibility_scope' => 'BROAD_INTERNAL_BI',
    'requested_release_status' => 'INTERNAL_REVIEWED',
])->value()['entity_twin_state_change_proposal_id'];
$stateChange->submit([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_state_change_proposal_id' => $changeId,
    'governance' => [
        'mission_id' => $p03Chain['mission_id'],
        'producing_valkyrie_id' => $p03Chain['producing_valkyrie_id'],
        'capability_contract_id' => $p03Chain['capability_contract_id'],
    ],
]);
$sessionId = $stateChange->openReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_state_change_proposal_id' => $changeId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
])->value()['review_session_id'];
$assignmentId = $council->assignParticipant(
    $sessionId, $p03Chain['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
)->value()['participant_assignment_id'];
$council->recordAssessment(
    $sessionId, $assignmentId, $p03Chain['critic_valkyrie_id'], 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.'
);
$council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

$twinAdmitted = $twinAdmission->admit([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_state_change_proposal_id' => $changeId,
    'arbiter_policy_set_id' => $p02PolicySetId,
]);
Assert::true($twinAdmitted->isOk(), 'the twin state change is admitted');
Assert::true(
    $twinRelease->release([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_state_change_proposal_id' => $changeId,
    ])->isOk(),
    'and released',
);

$snapshots = $twinRelease->listVisibleSnapshots([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $reader,
    'visibility_scope' => 'BROAD_INTERNAL_BI',
]);
Assert::same('VISIBLE', $snapshots['decision'], 'the same analyst sees it on the twin surface');
Assert::same(1, count($snapshots['rows']), 'one released twin snapshot');

// The unknown travelled with the claim. A twin that presented a resolved picture of an unresolved
// question would be the failure this whole vertical exists to avoid.
Assert::contains(
    'unknown',
    strtolower((string) $db->scalar(
        'SELECT u.summary_safe FROM released_entity_twin_snapshots s
         JOIN entity_twin_uncertainty_summaries u
           ON u.entity_twin_uncertainty_summary_id = s.entity_twin_uncertainty_summary_id
         WHERE s.released_entity_twin_snapshot_id = ?',
        [$snapshots['rows'][0]['released_entity_twin_snapshot_id']]
    )),
    'and the snapshot carries an uncertainty summary naming what is not known',
);

// ============================================================ what holds ACROSS all three

Assert::case('GOLDEN: the invariants that belong to the whole system, not to one vertical');

// One authority for release. Every released row in every vertical points at an M10 decision record
// of the canonical type — so revoking a release has ONE place to happen, whichever product made it.
foreach ([
    'released_briefings' => 'released_briefing_id',
    'released_prospect_outputs' => 'released_prospect_output_id',
    'released_entity_twin_snapshots' => 'released_entity_twin_snapshot_id',
] as $table => $idColumn) {
    $rows = $db->all("SELECT {$idColumn} AS id, decision_record_id FROM {$table}");
    Assert::true($rows !== [], "{$table}: this flow released something");
    foreach ($rows as $row) {
        Assert::same(
            'INTERNAL_INFORMATIONAL_RELEASE',
            (string) $db->scalar(
                'SELECT record_type FROM decision_records WHERE decision_record_id = ?',
                [$row['decision_record_id']]
            ),
            "{$table}: every released row is bound to an M10 internal release record",
        );
    }
}

// The ledger the three verticals share verifies as a whole. Three products writing into one stream
// is the case where a hash chain actually earns its keep.
Assert::same(
    'PASS',
    $ledger->verifyStream((string) $db->scalar(
        'SELECT decision_ledger_stream_id FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ?',
        [$scope['tenant_id'], $scope['workspace_id']]
    ))['status'],
    'and the shared decision-ledger stream verifies end to end',
);

// THE CEILING. Not one proposal in any vertical asked for an external action — and it could not
// have: M04 refuses the request, M03 refuses the contract, M02 refuses the permission.
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM proposal_envelopes
         WHERE requested_action_class IN ('PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')"
    ),
    'no proposal in any of the three flows requested an action class above the ceiling',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records WHERE record_type <> 'INTERNAL_INFORMATIONAL_RELEASE'
           AND record_type LIKE '%EXTERNAL%'"
    ),
    'and no decision record of any external kind exists',
);

// NOTHING LEFT THE MACHINE. The three flows together produced no external send of any kind, and
// the tables that would record one are empty because no code path can write them.
foreach ([
    'outreach_dispatch_records' => 'an outreach dispatch',
    'outreach_channel_bindings' => 'a channel binding',
] as $table => $what) {
    $exists = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
    if ($exists === 1) {
        Assert::same(
            0,
            (int) $db->scalar("SELECT COUNT(*) FROM {$table}"),
            "no row records {$what} — the three flows reached no external system",
        );
    }
}

// ONE RELEASE AUTHORITY, ACROSS EVERY FLOW IN THIS FILE.
//
// The verticals above release through different product modules with different projections — a P01
// briefing, a P02 twin snapshot, a P03 prospect output — and until this pass each of them advanced
// the proposal envelope itself, through M04, in a transaction of its own. These four assertions are
// what "M10 owns release" means once the products are wired to it, checked over the state three
// complete flows actually produced rather than over a fixture built for the purpose.

$releasedProposals = $db->all(
    "SELECT proposal_id FROM proposal_envelopes WHERE status = 'RELEASED' ORDER BY proposal_id"
);
Assert::true(count($releasedProposals) >= 3, 'the flows released at least one proposal each');

foreach ($releasedProposals as $row) {
    $proposalId = (string) $row['proposal_id'];
    Assert::same(
        1,
        (int) $db->scalar(
            "SELECT COUNT(*) FROM decision_records
             WHERE proposal_id = ? AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'",
            [$proposalId]
        ),
        'each RELEASED proposal rests on exactly one M10 release record',
    );
    Assert::same(
        1,
        (int) $db->scalar(
            "SELECT COUNT(*) FROM proposal_status_transitions t
             JOIN decision_records r ON r.decision_record_id = t.initiated_by_actor_id
             WHERE t.proposal_id = ? AND t.to_status = 'RELEASED'
               AND t.initiated_by_actor_type = 'M10'
               AND t.reason_code = 'M10_DECISION_RECORD_APPENDED'
               AND r.record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
               AND r.proposal_id = t.proposal_id",
            [$proposalId]
        ),
        'and on exactly one M10 transition naming the record that caused it',
    );
}

// No release record without the status it is supposed to have caused, and no RELEASED status
// without a record. Either direction alone would let the two drift apart.
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records r
         JOIN proposal_envelopes p ON p.proposal_id = r.proposal_id
         WHERE r.record_type = 'INTERNAL_INFORMATIONAL_RELEASE' AND p.status <> 'RELEASED'"
    ),
    'no release record stands against a proposal that is not RELEASED',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM proposal_envelopes p
         WHERE p.status = 'RELEASED' AND NOT EXISTS (
           SELECT 1 FROM decision_records r
           WHERE r.proposal_id = p.proposal_id
             AND r.record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
         )"
    ),
    'and no RELEASED proposal exists without one',
);
