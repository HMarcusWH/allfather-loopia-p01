<?php

declare(strict_types=1);

/**
 * The P03 release path — where a qualified prospect becomes released internal intelligence.
 *
 * This is the end of the vertical the product actually promises: catalogue → ICP → fit hypothesis →
 * qualification → priority queue → RELEASED prospect output → an authorized internal surface. The
 * port stopped at the admitted queue item, so everything downstream of it was a promise nothing
 * could keep.
 *
 * THREE PROPERTIES ARE THE POINT, and each is a different authority:
 *
 *   M10 OWNS RELEASE. Nothing here mints one. The released row is a REFERENCE to a decision record
 *   the ledger wrote, which is why revoking the release has exactly one place to happen.
 *
 *   THE SOURCE CARD IS RE-READ AT THE RELEASE BOUNDARY. The arbiter gated on it at verdict time,
 *   and the gap between a verdict and a release is real time. A card unapproved, expired, or no
 *   longer tenant-owned after the verdict must not reach a release.
 *
 *   THE SURFACE HAS TWO GATES. A role grant is not a purchase. Either gate denying makes the list
 *   empty rather than partial, because a partial answer to "may I see this" is a yes.
 *
 * P03 DOES NOT ROUTE THROUGH M16, and that is asserted here rather than assumed: upstream's M16
 * adoption covers P01 briefings and P02 snapshots, and a released prospect output is neither.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
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
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
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
$ledger = new DecisionLedger($db, $clock);
$access = new AccessGuard($db, $clock);
$entitlements = new Entitlements($db, $clock);
$icp = new Icp($db, $clock);
$cards = new SourceCards($db, $clock);
$catalogue = new Catalogue($db, $clock);
$fit = new FitHypotheses($db, $clock, $evidence, $icp);
$queue = new ProspectQueue(
    $db, $clock, $proposals, $evidence, new Readiness($db, $clock),
    new Arbiter($db, $clock, new Readiness($db, $clock))
);
$release = new P03Release($db, $clock, $ledger, $proposals, $access, $entitlements);

$policySetId = (new PolicySets($db, $clock))
    ->seed(PolicySets::P03_PROSPECT_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

// --- the tenant-owned catalogue the whole lineage hangs from -------------------------------------

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
$cardId = $card['source_license_card_id'];
$catalogueId = $catalogue->registerCatalogue(
    $scope['tenant_id'], $scope['workspace_id'], 'main', 'Main',
    $card['source_ref_id'], $cardId
)->value()['customer_product_catalogue_id'];
$productId = $catalogue->addProduct(
    $catalogueId, 'pricing-suite', 'Pricing Suite', 'PRODUCT',
    ['pricing', 'renewal', 'billing', 'contract']
)->value()['customer_product_id'];

// A PUBLIC card cannot be a customer catalogue's authority. The gate lives at registration, and
// the release-boundary re-read is the defence in depth over it — without this case, dropping the
// registration gate would change no outcome any test observes.
$publicCard = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => "Somebody else's price page",
    'source_url_safe' => 'https://competitor.example/prices',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'review_status' => 'approved',
]);
Assert::same(
    'PUBLIC',
    (string) $db->scalar(
        'SELECT data_ownership FROM source_license_cards WHERE source_license_card_id = ?',
        [$publicCard['source_license_card_id']]
    ),
    'a P01-style card defaults to PUBLIC ownership',
);
Assert::same(
    'SOURCE_CARD_NOT_TENANT_OWNED',
    $catalogue->registerCatalogue(
        $scope['tenant_id'], $scope['workspace_id'], 'borrowed', 'Borrowed',
        $publicCard['source_ref_id'], $publicCard['source_license_card_id']
    )->reasonCode(),
    "and cannot authorize a customer catalogue — a tenant's product list is the tenant's own material",
);

$producer = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Lead Qualifier',
    'COMPETITION_MONITOR_VALKYRIE', 'Derives fit hypotheses.', $scope['operator_id'], 'ACTIVE'
);
$contractId = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p03.qualify',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE']
)->value()['capability_contract_id'];
$governance = [
    'mission_id' => $proposals->createMission(
        $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
        'Qualify leads', 'Pair released signals with catalogue products.',
        ['OBSERVE', 'ANALYZE'], 'OPERATOR', $scope['operator_id']
    ),
    'producing_valkyrie_id' => $producer,
    'capability_contract_id' => $contractId,
];

/** One admitted PRIORITY_OUTREACH_QUEUE_ITEM, built through the real modules. */
$admitQueueItem = static function (string $summary)
use ($db, $clock, $scope, $fit, $productId, $queue, $governance, $policySetId): array {
    $governed = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF');
    $signalId = Ids::new();
    $db->insert('company_signals', [
        'company_signal_id' => $signalId,
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'competitor_id' => $governed['competitor_id'],
        'source_diff_id' => $governed['source_diff_id'],
        // A buying TRIGGER, directly OBSERVED — the only combination the priority queue admits.
        'signal_class' => 'PRICING_CHANGE_SIGNAL',
        'interpretation_kind' => 'FACT_OBSERVED',
        'confidence_band' => 'HIGH',
        'summary_safe' => $summary,
        'proposal_id' => $governed['proposal_id'],
        'status' => 'RELEASED',
        'created_at' => $clock->now(),
    ]);
    $paired = $fit->pair([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'customer_product_id' => $productId,
        'company_signal_id' => $signalId,
    ]);
    Assert::true($paired->isOk(), 'the signal pairs into a fit hypothesis: ' . ($paired->isOk() ? '' : $paired->reasonCode()));
    $hypothesisId = $paired->value()['fit_hypothesis_id'];

    $submitted = $queue->submitForAdmission([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'fit_hypothesis_id' => $hypothesisId,
        'admission_path' => 'PRIORITY_OUTREACH_QUEUE_ADMISSION',
        'governance' => $governance,
    ]);
    Assert::true($submitted->isOk(), 'and submits for priority-queue admission: ' . ($submitted->isOk() ? '' : $submitted->reasonCode()));

    $admittedOutcome = $queue->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'fit_hypothesis_id' => $hypothesisId,
        'arbiter_policy_set_id' => $policySetId,
    ]);
    Assert::true($admittedOutcome->isOk(), 'and is admitted: ' . ($admittedOutcome->isOk() ? '' : $admittedOutcome->reasonCode()));

    return $admittedOutcome->value();
};

$admitted = $admitQueueItem('Competitor raised renewal pricing and changed contract billing terms.');
$queueItemId = (string) $admitted['priority_outreach_queue_item_id'];

// ============================================================ the release

$released = $release->releaseProspectOutput([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
    'output_id' => $queueItemId,
]);
Assert::true($released->isOk(), 'an admitted queue item releases');
Assert::same('RELEASED', $released->value()['outcome'], 'as a RELEASED prospect output');

$decisionRecordId = (string) $released->value()['decision_record_id'];

// M10 OWNS RELEASE. The released row REFERENCES the decision record; it does not duplicate the
// decision, so there is one place a release can be revoked from.
Assert::same(
    'INTERNAL_INFORMATIONAL_RELEASE',
    (string) $db->scalar('SELECT record_type FROM decision_records WHERE decision_record_id = ?', [$decisionRecordId]),
    'the release IS an M10 decision record of the canonical type',
);
Assert::same(
    $decisionRecordId,
    (string) $db->scalar(
        'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
        [$released->value()['released_prospect_output_id']]
    ),
    'and the projected reference points at exactly that record',
);
Assert::same(
    'PASS',
    $ledger->verifyStream((string) $db->scalar(
        'SELECT decision_ledger_stream_id FROM decision_ledger_streams WHERE tenant_id = ? LIMIT 1',
        [$scope['tenant_id']]
    ))['status'],
    'and the hash chain still verifies with the release in it',
);

// P03 DOES NOT ROUTE THROUGH M16. Asserted, not assumed — forcing it through would change what the
// artifact is, and would re-decide an adoption boundary the owner gate set.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts WHERE tenant_id = ?', [$scope['tenant_id']]),
    'a released prospect output mints NO M16 artifact — P03 carries its own release projection',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM customer_output_projections WHERE tenant_id = ?', [$scope['tenant_id']]),
    'and no M16 audience projection either',
);
// STRUCTURAL, so the count above cannot be vacuously true: the released row has no column that
// could hold an M16 artifact at all. The topology is in the schema, not only in what this path
// happens not to write.
$releasedColumns = array_map(
    static fn (array $r): string => (string) $r['COLUMN_NAME'],
    $db->all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'released_prospect_outputs'"
    )
);
Assert::true(
    in_array('decision_record_id', $releasedColumns, true),
    'a released prospect output references an M10 decision record',
);
Assert::false(
    in_array('release_artifact_id', $releasedColumns, true),
    'and has NO M16 artifact column — P03 release is an M10 reference, not an M16 artifact',
);

// The brief column stays NULL: upstream's release path mints no brief, so neither does this one.
Assert::same(
    null,
    $db->scalar(
        'SELECT prospect_battlecard_brief_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
        [$released->value()['released_prospect_output_id']]
    ),
    'the battlecard-brief reference is NULL — this path mints no brief',
);
Assert::same(
    'OUTPUT_KIND_NOT_RELEASABLE',
    $release->releaseProspectOutput([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'output_kind' => 'PROSPECT_BATTLECARD_BRIEF',
        'output_id' => $queueItemId,
    ])->reasonCode(),
    'and asking for that kind is refused by name rather than reaching a storage CHECK',
);

// Releasing twice is refused. Defence in depth over the proposal advance.
Assert::same(
    'ALREADY_RELEASED',
    $release->releaseProspectOutput([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
        'output_id' => $queueItemId,
    ])->reasonCode(),
    'the same output cannot be released twice',
);

// Cross-scope is a scope failure, not a not-found — the row exists and is another workspace's.
$otherScope = Seed::tenantWorkspace($db, $clock, 'rival');
Assert::same(
    'OUTPUT_SCOPE_MISMATCH',
    $release->releaseProspectOutput([
        'tenant_id' => $otherScope['tenant_id'],
        'workspace_id' => $otherScope['workspace_id'],
        'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
        'output_id' => $queueItemId,
    ])->reasonCode(),
    "another tenant cannot release this workspace's prospect output",
);

// ============================================================ the release-boundary card re-check

// Each condition is isolated: the card is bent AFTER the verdict, which is exactly the window the
// re-read exists for.
foreach ([
    ['SOURCE_CARD_NOT_APPROVED_AT_RELEASE', "UPDATE source_license_cards SET review_status = 'draft' WHERE source_license_card_id = ?", "UPDATE source_license_cards SET review_status = 'approved' WHERE source_license_card_id = ?"],
    ['SOURCE_CARD_REVIEW_EXPIRED_AT_RELEASE', "UPDATE source_license_cards SET review_expires_at = '2025-01-01T00:00:00.000Z' WHERE source_license_card_id = ?", 'UPDATE source_license_cards SET review_expires_at = NULL WHERE source_license_card_id = ?'],
    ['SOURCE_CARD_NOT_TENANT_OWNED_AT_RELEASE', "UPDATE source_license_cards SET data_ownership = 'PUBLIC' WHERE source_license_card_id = ?", "UPDATE source_license_cards SET data_ownership = 'TENANT_OWNED' WHERE source_license_card_id = ?"],
] as [$expected, $bend, $restore]) {
    $fresh = $admitQueueItem('Competitor changed contract billing terms again.');
    $db->run($bend, [$cardId]);
    $outcome = $release->releaseProspectOutput([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
        'output_id' => (string) $fresh['priority_outreach_queue_item_id'],
    ]);
    Assert::same($expected, $outcome->reasonCode(), "{$expected}: refused at the RELEASE boundary");
    Assert::same(
        0,
        (int) $db->scalar(
            'SELECT COUNT(*) FROM released_prospect_outputs WHERE priority_outreach_queue_item_id = ?',
            [(string) $fresh['priority_outreach_queue_item_id']]
        ),
        "{$expected}: and nothing was released",
    );
    $db->run($restore, [$cardId]);
}

// ============================================================ the surface has TWO gates

$surfaceInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $scope['operator_id'],
    'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM',
];

// Gate 1 closed: no role, no permission.
$noRole = $release->listVisibleProspectOutputs($surfaceInput);
Assert::same('DENIED', $noRole['decision'], 'with no role the surface denies');
Assert::same('priority_outreach_queue.read', $noRole['required_permission'], 'naming the permission it wanted');
Assert::same([], $noRole['rows'], 'and the list is EMPTY rather than partial');

// Gate 1 open, gate 2 closed: a role grant is not a purchase.
$role = Seed::role($db, $clock, 'queue_reader', ['priority_outreach_queue.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$noTier = $release->listVisibleProspectOutputs($surfaceInput);
Assert::same('DENIED', $noTier['decision'], 'a role alone is not enough');
Assert::same(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $noTier['reason_codes'],
    'and the second gate names itself — a role grant is not a purchase',
);
Assert::same([], $noTier['rows'], 'the list stays empty');

// Both gates open.
$tier = $entitlements->defineTier(
    'p03_prospects', 'P03 Prospect Intelligence', 149_900,
    // Only the permission this surface needs. A tier that granted more than the test exercises
    // would leave the second gate proved for a permission nothing here reads.
    ['priority_outreach_queue.read']
);
Assert::true($tier->isOk(), 'the P03 tier is defined: ' . ($tier->isOk() ? '' : $tier->reasonCode()));
$subscribed = $entitlements->subscribe($scope['tenant_id'], $tier->value()['subscription_tier_id']);
Assert::true($subscribed->isOk(), 'and the tenant subscribes to it');

$visible = $release->listVisibleProspectOutputs($surfaceInput);
Assert::same('VISIBLE', $visible['decision'], 'with both gates open the surface renders');
Assert::same(['GRANTED'], $visible['reason_codes'], 'and says so');
Assert::same(1, count($visible['rows']), 'showing exactly the one released output');
Assert::same(
    $released->value()['released_prospect_output_id'],
    (string) $visible['rows'][0]['released_prospect_output_id'],
    'which is the one that was released',
);

// A FIT IS NOT AN INTENT — the surfaced claim kind is the hypothesis's own, unmodified. Nothing in
// the release path upgrades a fit into a statement about what a company wants.
Assert::same(
    'REASON_TO_INVESTIGATE',
    (string) $visible['rows'][0]['claim_kind'],
    'the surfaced claim is a REASON TO INVESTIGATE — release adds no claim of its own',
);
Assert::true(
    $visible['rows'][0]['fit_band'] !== null,
    'and the fit band travels as the safe derived label it is',
);

// Nothing PRE-release is ever surfaced: the admitted-but-unreleased items are invisible.
$unreleased = $admitQueueItem('A third pricing change.');
$after = $release->listVisibleProspectOutputs($surfaceInput);
Assert::same(1, count($after['rows']), 'an admitted but UNRELEASED item does not appear on the surface');
Assert::false(
    in_array((string) $unreleased['priority_outreach_queue_item_id'], array_map(
        static fn (array $r): string => (string) ($r['priority_outreach_queue_item_id'] ?? ''),
        $after['rows']
    ), true),
    'the surface reads released rows only — never anything pre-release',
);

// Another tenant sees nothing, whatever it holds in its own workspace.
$crossTenant = $release->listVisibleProspectOutputs([
    ...$surfaceInput,
    'tenant_id' => $otherScope['tenant_id'],
    'workspace_id' => $otherScope['workspace_id'],
    'actor_id' => $otherScope['operator_id'],
]);
Assert::same('DENIED', $crossTenant['decision'], "another tenant's operator sees nothing here");
Assert::same([], $crossTenant['rows'], 'and gets an empty list, not a filtered one');
