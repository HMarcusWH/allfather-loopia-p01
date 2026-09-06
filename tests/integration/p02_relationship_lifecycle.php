<?php

declare(strict_types=1);

/**
 * The P02 RELATIONSHIP lifecycle — a line between two companies, released under governance.
 *
 * A twin says what we know about one company. A relationship says something about the line BETWEEN
 * two of them — supplies, competes with, depends on — and that is the stronger claim, because it
 * asserts something about a party who is not the tenant and who never agreed to be described. It
 * therefore gets the same governed path as the twin, not a shortcut:
 *
 *   relationship version → state-change binding → M04 proposal → M05 bundle → M07 review
 *     → M08 verdict → M10 record → immutable snapshot → M16 snapshot view → role-gated surface
 *
 * THE STORAGE GATE IS NOT THE PRODUCT PATH. `RelationshipTwins::releaseSnapshot` can write a
 * snapshot given a decision record, and that is deliberately not how a release happens: the product
 * path is `RelationshipRelease::release`, which requires an admitted state change and composes the
 * M16 artifact. Asserting on the storage gate alone would prove the schema works and the product
 * does not.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\RelationshipAdmission;
use Allfather\Loopia\Product\P02\RelationshipRelease;
use Allfather\Loopia\Product\P02\RelationshipStateChange;
use Allfather\Loopia\Product\P02\RelationshipTwins;
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

$evidence = new EvidenceSpine($db, $clock);
$proposals = new Proposals($db, $clock);
$readiness = new Readiness($db, $clock);
$council = new Council($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);
$ledger = new DecisionLedger($db, $clock);
$m03 = new M03Registry($db, $clock);
$access = new AccessGuard($db, $clock);
$entitlements = new Entitlements($db, $clock);
$m16 = new ReleaseOutput($db, $clock, $ledger);
$adapter = new P02Adapter($db, $m16);

$twins = new EntityTwins($db, $clock);
$relationships = new RelationshipTwins($db, $clock);
$stateChange = new RelationshipStateChange($db, $clock, $proposals, $evidence, $readiness, $council);
$admission = new RelationshipAdmission($db, $clock, $arbiter, $stateChange);
$release = new RelationshipRelease(
    $db, $clock, $ledger, $relationships, $access, $entitlements, $adapter
);

$policySetId = (new PolicySets($db, $clock))
    ->seed(PolicySets::P02_RELATIONSHIP_STATE_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

// --- the governance a relationship claim must be produced under ----------------------------------

$curator = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Entity Twin Curator',
    'ENTITY_TWIN_CURATOR_VALKYRIE', 'Curates twin and relationship state.', $scope['operator_id'], 'ACTIVE'
);
$critic = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Review Critic',
    'REVIEW_CRITIC_VALKYRIE', 'Independent review.', $scope['operator_id'], 'ACTIVE'
);
$contractId = $m03->issueContract(
    $curator, $scope['tenant_id'], $scope['workspace_id'], 'p02.curate.relationships',
    'ENTITY_TWIN_CURATOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];
$m03->bindOutputClass($contractId, 'ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL', 'RELEASE_INTELLIGENCE');

$missionId = $proposals->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
    'Curate the relationship graph', 'Maintain evidence-backed edges between commercial entities.',
    ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id'], 'ACTIVE',
    ['INTERNAL_INFORMATIONAL', 'ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL'],
);
$governance = [
    'mission_id' => $missionId,
    'producing_valkyrie_id' => $curator,
    'capability_contract_id' => $contractId,
];

/** Two twins and an evidence-backed edge between them. */
$makeEdgeVersion = static function (array $overrides = [])
use ($db, $clock, $scope, $twins, $relationships, $evidence, $missionId): array {
    $source = $twins->registerTwin(
        $scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Rival AB',
        [], $scope['operator_id']
    )->value()['entity_twin_id'];
    $target = $twins->registerTwin(
        $scope['tenant_id'], $scope['workspace_id'], 'SUPPLIER', 'Parts Leverantör AB',
        [], $scope['operator_id']
    )->value()['entity_twin_id'];

    $edgeId = $relationships->createEdge(
        $scope['tenant_id'], $scope['workspace_id'], $source, $target, 'BUYS_FROM'
    )->value()['relationship_edge_id'];

    // Real captured evidence, through the real spine — the arbiter's approval rule reads the
    // evidence STATUS, so a hand-inserted link would pass a gate the product would fail.
    $sourceRefId = $evidence->registerSource(
        $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
        'https://rival.example/suppliers', 'public_web', 'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
    );
    $evidenceId = $evidence->capture([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'mission_id' => $missionId,
        'source_ref_id' => $sourceRefId,
        'evidence_type' => 'SOURCE_CAPTURE',
        'captured_content_reference_safe' => 'Supplier listing naming the counterparty.',
        'content_hash' => str_repeat('a', 64),
        'capture_method' => 'MANUAL',
        'permitted_use_scope' => 'public_web',
        'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
    ])->value()['evidence_id'];

    $version = $relationships->appendVersion([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'relationship_edge_id' => $edgeId,
        'confidence' => $overrides['confidence'] ?? 'MEDIUM',
        'freshness_observed_at' => $clock->now(),
        'business_relevance' => 'MEDIUM',
        'allowed_use' => $overrides['allowed_use'] ?? 'INTERNAL_ONLY',
        'uncertainty_summary_safe' => $overrides['uncertainty']
            ?? 'Observed from a public supplier listing; volume and terms are unknown.',
        'claim_ceiling' => $overrides['claim_ceiling'] ?? 'INTERNAL_REVIEWED',
        'evidence_links' => [[
            'evidence_id' => $evidenceId,
            'source_ref_id' => $sourceRefId,
            'source_basis' => 'PUBLIC_SOURCE',
        ]],
    ]);

    return [
        'relationship_edge_id' => $edgeId,
        'relationship_version_id' => $version->value()['relationship_version_id'],
        'evidence_id' => $evidenceId,
        'source_ref_id' => $sourceRefId,
    ];
};

/** Drive one relationship state change through binding, submission, review and verdict. */
$admitChange = static function (array $version, array $createOverrides = [])
use ($scope, $stateChange, $council, $admission, $critic, $governance, $policySetId): array {
    $created = $stateChange->create([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'relationship_version_id' => $version['relationship_version_id'],
        'requested_visibility_scope' => $createOverrides['requested_visibility_scope'] ?? 'PROCUREMENT_FINANCE_OPS',
        'requested_release_status' => $createOverrides['requested_release_status'] ?? 'INTERNAL_REVIEWED',
        ...$createOverrides,
    ]);
    if ($created->isRefusal()) {
        return ['created' => $created];
    }
    $changeId = $created->value()['entity_relationship_state_change_proposal_id'];

    $submitted = $stateChange->submit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_relationship_state_change_proposal_id' => $changeId,
        'governance' => $governance,
    ]);
    if ($submitted->isRefusal()) {
        return ['created' => $created, 'change_id' => $changeId, 'submitted' => $submitted];
    }

    $opened = $stateChange->openReview([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_relationship_state_change_proposal_id' => $changeId,
        'initiated_by_actor_type' => 'OPERATOR',
        'initiated_by_actor_id' => $scope['operator_id'],
    ]);
    $sessionId = $opened->value()['review_session_id'];
    $assignmentId = $council
        ->assignParticipant($sessionId, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
        ->value()['participant_assignment_id'];
    $council->recordAssessment($sessionId, $assignmentId, $critic, 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.');
    $council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

    $admitted = $admission->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_relationship_state_change_proposal_id' => $changeId,
        'arbiter_policy_set_id' => $policySetId,
    ]);

    return [
        'created' => $created, 'change_id' => $changeId, 'submitted' => $submitted,
        'review_session_id' => $sessionId, 'admitted' => $admitted,
    ];
};

// --- 1. the golden path -------------------------------------------------------------------------

Assert::case('P02 relationship: an evidence-backed edge reaches a governed internal release');

$version = $makeEdgeVersion();
$flow = $admitChange($version);

Assert::true(
    $flow['submitted']->isOk(),
    'the state change submits: ' . ($flow['submitted']->isOk() ? '' : $flow['submitted']->reasonCode()),
);
Assert::same(
    'ENTITY_RELATIONSHIP_STATE_CHANGE',
    (string) $db->scalar(
        'SELECT proposal_type FROM proposal_envelopes WHERE proposal_id = ?',
        [$flow['submitted']->value()['proposal_id']]
    ),
    'as its own proposal type — the relationship rules apply to this and nothing else',
);
Assert::true(
    $flow['admitted']->isOk(),
    'and the arbiter admits it: ' . ($flow['admitted']->isOk() ? '' : $flow['admitted']->reasonCode()),
);
Assert::same(
    'VERDICT_ISSUED',
    (string) $db->scalar(
        'SELECT change_status FROM entity_relationship_state_change_proposals
         WHERE entity_relationship_state_change_proposal_id = ?',
        [$flow['change_id']]
    ),
    'the binding advances to VERDICT_ISSUED, bound to the verdict',
);

$released = $release->release([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_relationship_state_change_proposal_id' => $flow['change_id'],
    'released_by_actor_id' => $scope['operator_id'],
]);
Assert::true(
    $released->isOk(),
    'and it releases: ' . ($released->isOk() ? '' : $released->reasonCode()),
);

$snapshotId = (string) $released->value()['released_relationship_snapshot_id'];
$decisionRecordId = (string) $released->value()['decision_record_id'];
$artifactId = (string) $released->value()['release_artifact_id'];

Assert::same(
    'INTERNAL_INFORMATIONAL_RELEASE',
    (string) $db->scalar(
        'SELECT record_type FROM decision_records WHERE decision_record_id = ?',
        [$decisionRecordId]
    ),
    'M10 wrote the release record',
);
Assert::same(
    $decisionRecordId,
    (string) $db->scalar(
        'SELECT decision_record_id FROM released_entity_relationship_snapshots
         WHERE released_relationship_snapshot_id = ?',
        [$snapshotId]
    ),
    'and the snapshot is bound to it',
);

// --- 2. the relationship leg adopts M16 too -----------------------------------------------------

Assert::case('P02 relationship: the release composes an ENTITY_RELATIONSHIP_SNAPSHOT_VIEW');

Assert::same(
    'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW',
    (string) $db->scalar(
        'SELECT artifact_kind FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'the canonical relationship artifact kind',
);
Assert::same(
    $snapshotId,
    (string) $db->scalar(
        'SELECT released_relationship_snapshot_id FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'referencing the released relationship snapshot as its consumer',
);
Assert::same(
    'PROCUREMENT_FINANCE_OPS',
    (string) $db->scalar(
        'SELECT internal_scope_lens FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    "carrying the snapshot's own need-to-know lens",
);
// A public-source edge is a cardless external basis, so the compile HOLDS — the release stands and
// only an operator can read it.
Assert::same(
    'HELD',
    (string) $released->value()['artifact_outcome'],
    'a public-source edge HOLDS the artifact rather than refusing the release',
);
Assert::same(
    'PROJECTED',
    (string) $db->scalar(
        "SELECT status FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class = 'OPERATOR_ONLY'",
        [$artifactId]
    ),
    'with an operator hold-view',
);
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM release_visibility_activations WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'and nothing activated — a cardless external basis never becomes tenant-visible',
);

// --- 3. the storage gate is not the product path ------------------------------------------------

Assert::case('P02 relationship: the governed path is the only one that releases');

Assert::same(
    'ALREADY_RELEASED',
    $release->release([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_relationship_state_change_proposal_id' => $flow['change_id'],
    ])->reasonCode(),
    'a second release of the same change refuses rather than minting a second record',
);
Assert::same(
    'ALREADY_RELEASED_VERSION',
    $relationships->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'relationship_version_id' => $version['relationship_version_id'],
        'visibility_scope' => 'SALES',
        'release_status' => 'INTERNAL_REVIEWED',
        'decision_record_id' => $decisionRecordId,
    ])->reasonCode(),
    'and the storage gate refuses a second release of the same VERSION',
);

// --- 4. the gates that refuse -------------------------------------------------------------------

Assert::case('P02 relationship: scope, evidence and ceiling each refuse by name');

$other = Seed::tenantWorkspace($db, $clock, 'rival');
Assert::same(
    'SCOPE_MISMATCH',
    $stateChange->create([
        'tenant_id' => $other['tenant_id'],
        'workspace_id' => $other['workspace_id'],
        'relationship_version_id' => $version['relationship_version_id'],
        'requested_visibility_scope' => 'SALES',
        'requested_release_status' => 'INTERNAL_REVIEWED',
    ])->reasonCode(),
    "another tenant cannot bind a state change to this tenant's version",
);
Assert::same(
    'VISIBILITY_RULE_MISSING',
    $stateChange->create([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'relationship_version_id' => $version['relationship_version_id'],
        'requested_visibility_scope' => 'EVERYONE',
        'requested_release_status' => 'INTERNAL_REVIEWED',
    ])->reasonCode(),
    'an unrecognized visibility scope is default-denied',
);

// An edge with no evidence cannot be released. The arbiter blocks it, and the storage gate would
// too — the same invariant, held at two layers.
$unsupportedEdge = $relationships->createEdge(
    $scope['tenant_id'], $scope['workspace_id'],
    $twins->registerTwin($scope['tenant_id'], $scope['workspace_id'], 'PARTNER', 'A', [], $scope['operator_id'])->value()['entity_twin_id'],
    $twins->registerTwin($scope['tenant_id'], $scope['workspace_id'], 'VENDOR', 'B', [], $scope['operator_id'])->value()['entity_twin_id'],
    'PARTNERS_WITH'
)->value()['relationship_edge_id'];
$unsupported = $relationships->appendVersion([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'relationship_edge_id' => $unsupportedEdge,
    'confidence' => 'LOW',
    'freshness_observed_at' => $clock->now(),
    'business_relevance' => 'LOW',
    'allowed_use' => 'INTERNAL_ONLY',
    'uncertainty_summary_safe' => 'Asserted without a citation.',
    'claim_ceiling' => 'INTERNAL_DRAFT',
    'evidence_links' => [],
]);
Assert::true(
    $unsupported->isRefusal(),
    'a version citing nothing is refused at the storage layer: ' . $unsupported->reasonCode(),
);

// --- 5. the producer's authority ----------------------------------------------------------------

Assert::case('P02 relationship: curating twins does not authorize asserting relationships');

$unboundValkyrie = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Twin-only Curator',
    'ENTITY_TWIN_CURATOR_VALKYRIE', 'Twins only.', $scope['operator_id'], 'ACTIVE'
);
$unboundContract = $m03->issueContract(
    $unboundValkyrie, $scope['tenant_id'], $scope['workspace_id'], 'p02.curate.twins-only',
    'ENTITY_TWIN_CURATOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];
// Deliberately NO ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL output-class binding.

$second = $makeEdgeVersion();
$unauthorized = $stateChange->create([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'relationship_version_id' => $second['relationship_version_id'],
    'requested_visibility_scope' => 'SALES',
    'requested_release_status' => 'INTERNAL_REVIEWED',
]);
$unauthorizedSubmit = $stateChange->submit([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_relationship_state_change_proposal_id' => $unauthorized->value()['entity_relationship_state_change_proposal_id'],
    'governance' => [
        'mission_id' => $missionId,
        'producing_valkyrie_id' => $unboundValkyrie,
        'capability_contract_id' => $unboundContract,
    ],
]);
Assert::same(
    'PRODUCER_NOT_AUTHORIZED',
    $unauthorizedSubmit->reasonCode(),
    'a contract with no relationship output-class binding cannot produce one',
);
Assert::same(
    'output_class',
    (string) $unauthorizedSubmit->jsonSerialize()['detail']['detail'],
    'and the refusal names WHICH half of the authority was missing',
);

// A mission that does not permit the output class refuses too, even with a bound contract.
$narrowMission = $proposals->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
    'Narrow mission', 'Internal informational only.',
    ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id'], 'ACTIVE',
    ['INTERNAL_INFORMATIONAL'],
);
$third = $makeEdgeVersion();
$narrowChange = $stateChange->create([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'relationship_version_id' => $third['relationship_version_id'],
    'requested_visibility_scope' => 'SALES',
    'requested_release_status' => 'INTERNAL_REVIEWED',
]);
Assert::same(
    'MISSION_OUTPUT_NOT_AUTHORIZED',
    $stateChange->submit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_relationship_state_change_proposal_id' => $narrowChange->value()['entity_relationship_state_change_proposal_id'],
        'governance' => [
            'mission_id' => $narrowMission,
            'producing_valkyrie_id' => $curator,
            'capability_contract_id' => $contractId,
        ],
    ])->reasonCode(),
    'and a mission that does not permit the output class refuses it too',
);

// --- 6. the two-gate surface --------------------------------------------------------------------

Assert::case('P02 relationship: the released snapshot surfaces behind both gates and a lens');

$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'Procurement analyst');
$role = Seed::role($db, $clock, 'p02-rel-reader-' . substr(Ids::new(), 0, 8), ['entity_twin.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $reader, $role['role_id']);

$surface = static fn (string $lens): array => $release->listVisibleSnapshots([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $reader,
    'visibility_scope' => $lens,
]);

Assert::equalsList(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $surface('PROCUREMENT_FINANCE_OPS')['reason_codes'],
    'an M02 grant alone does not open the relationship surface',
);

$tierId = $entitlements->defineTier(
    'p02_rel_tier', 'P02 Relationships', 149900, ['entity_twin.read']
)->value()['subscription_tier_id'];
$entitlements->subscribe($scope['tenant_id'], $tierId);

$visible = $surface('PROCUREMENT_FINANCE_OPS');
Assert::same('VISIBLE', $visible['decision'], 'with both gates satisfied the surface opens');
Assert::same(1, count($visible['rows']), 'and shows the released relationship snapshot');
Assert::same(
    $snapshotId,
    (string) $visible['rows'][0]['released_relationship_snapshot_id'],
    'which is the one the governed path released',
);
Assert::same(
    [],
    $surface('SALES')['rows'],
    'a different role lens sees nothing — no cross-scope leak',
);
Assert::same(
    'DENIED',
    $surface('EVERYONE')['decision'],
    'and an unrecognized lens is default-denied',
);

// A canary. This file drives a long governed chain, and a silent early return partway through would
// leave the suite green while proving progressively less — the exact failure mode this project has
// already been bitten by once. If this assertion is missing from a run, the file did not finish.
Assert::true(true, 'the relationship lifecycle file ran to completion');
