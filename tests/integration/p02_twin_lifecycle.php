<?php

declare(strict_types=1);

/**
 * The P02 entity-twin lifecycle — from a recorded version to a governed internal release.
 *
 * `appendVersion` records what the tenant knows. It makes nothing visible to anyone. THIS is the
 * path from a private working note to intelligence an authorized colleague can read:
 *
 *   version → state-change binding → M04 proposal → M05 bundle → M07 review → M08 verdict
 *           → M10 decision record → immutable snapshot → a role-gated surface
 *
 * FIVE PROPERTIES ARE THE POINT, and each belongs to a different authority:
 *
 *   ONLY AN ALLOW ADVANCES. A block terminates the change rather than leaving it as a draft to
 *   soften — several of the fifteen rules refuse content that was never available to release.
 *
 *   M10 OWNS RELEASE. Nothing in P02 mints one; the snapshot is bound to what the ledger wrote.
 *
 *   THE M10 BINDING FLOOR IS A TYPED REFUSAL BEFORE THE INSERT. An unbound snapshot is refused by
 *   name, not by a NOT NULL driver error, and a decision record already bound cannot be replayed.
 *
 *   THE RELEASE SCOPE IS THE ONE THE ARBITER JUDGED. It is read back off the binding, so a review
 *   of one visibility scope cannot become a release into a wider one.
 *
 *   THE SURFACE HAS TWO GATES AND A LENS. M02 and the tenant's tier must BOTH grant the read, and
 *   only snapshots released to the requested scope come back.
 *
 * P02 DOES NOT ROUTE THROUGH M16 IN THIS PORT, and that is asserted here rather than assumed.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\M10BindingFloor;
use Allfather\Loopia\Product\P02\TwinAdmission;
use Allfather\Loopia\Product\P02\TwinAssurance;
use Allfather\Loopia\Product\P02\TwinRelease;
use Allfather\Loopia\Product\P02\TwinStateChange;
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
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
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
$cards = new SourceCards($db, $clock);

$twins = new EntityTwins($db, $clock);
$stateChange = new TwinStateChange($db, $clock, $proposals, $evidence, $readiness, $council);
$admission = new TwinAdmission($db, $clock, $arbiter, $stateChange);
$m16Output = new ReleaseOutput($db, $clock, $ledger);
$p02Adapter = new P02Adapter($db, $m16Output);
$release = new TwinRelease($db, $clock, $ledger, $proposals, $twins, $access, $entitlements, $p02Adapter);

$policySetId = (new PolicySets($db, $clock))
    ->seed(PolicySets::P02_TWIN_STATE_ADMISSION, $scope['tenant_id'], $scope['workspace_id'])
    ->value()['arbiter_policy_set_id'];

// --- the governance the state change is produced under -------------------------------------------

$curator = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Entity Twin Curator',
    'ENTITY_TWIN_CURATOR_VALKYRIE', 'Curates twin state.', $scope['operator_id'], 'ACTIVE'
);
$critic = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Review Critic',
    'REVIEW_CRITIC_VALKYRIE', 'Independent review.', $scope['operator_id'], 'ACTIVE'
);
$contractId = $m03->issueContract(
    $curator, $scope['tenant_id'], $scope['workspace_id'], 'p02.curate',
    'ENTITY_TWIN_CURATOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];
$governance = [
    'mission_id' => $proposals->createMission(
        $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
        'Curate twin state', 'Maintain evidence-backed twins of the commercial environment.',
        ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']
    ),
    'producing_valkyrie_id' => $curator,
    'capability_contract_id' => $contractId,
];

/**
 * A twin version carrying one supported fact and one source link.
 *
 * The source link is what makes the version releasable at all: `p02_unsupported_twin_claim` blocks
 * a version that asserts a fact nobody recorded a basis for, and the storage gate refuses it again.
 */
$makeVersion = static function (array $overrides = [], array $fieldOverrides = [])
use ($scope, $twins, $cards): array {
    $twinId = $twins->registerTwin(
        $scope['tenant_id'], $scope['workspace_id'],
        $overrides['entity_kind'] ?? 'COMPETITOR',
        $overrides['display_name'] ?? 'Rival AB',
        [], $scope['operator_id']
    )->value()['entity_twin_id'];

    // The card is projected through the real P01 path rather than inserted: the twin's source
    // link points at a source reference whose licence and review status the arbiter re-reads, and a
    // hand-written row could satisfy the test while failing the rule the product runs.
    $card = $cards->project([
        'tenant_id' => $scope['tenant_id'],
        'source_name' => 'Rival about page',
        'source_url_safe' => 'https://rival.example/about',
        'source_type' => 'website',
        'allowed_use_class' => 'derived_summary_allowed',
        'license_class' => 'PUBLIC_WEB_ALLOWED',
        'review_status' => $overrides['review_status'] ?? 'approved',
    ]);
    $sourceRefId = $card['source_ref_id'];

    $version = $twins->appendVersion([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_id' => $twinId,
        'claim_ceiling' => $overrides['claim_ceiling'] ?? 'INTERNAL_REVIEWED',
        'fields' => $fieldOverrides !== [] ? $fieldOverrides : [
            ['field_key' => 'industry', 'field_value_safe' => 'Retail software', 'value_status' => 'PUBLIC_SOURCE'],
            ['field_key' => 'segment', 'field_value_safe' => 'SME', 'value_status' => 'DERIVED_SUMMARY'],
        ],
        'uncertainty' => [
            'summary_safe' => $overrides['uncertainty_summary']
                ?? 'Headcount and revenue are unknown; segment is inferred from the public site.',
            'has_conflict' => $overrides['has_conflict'] ?? false,
        ],
        'source_links' => [[
            'source_ref_id' => $sourceRefId,
            'source_basis' => 'PUBLIC_SOURCE',
            'allowed_use' => 'DERIVED_SUMMARY_ALLOWED',
            'license_basis_safe' => 'Public web page, derived summary allowed.',
        ]],
    ]);

    return [
        'entity_twin_id' => $twinId,
        'entity_twin_version_id' => $version->value()['entity_twin_version_id'],
        'source_ref_id' => $sourceRefId,
    ];
};

/** Drive one state change through binding, submission, council review and verdict. */
$admitStateChange = static function (array $version, array $createOverrides = [])
use ($scope, $stateChange, $council, $admission, $critic, $governance, $policySetId): array {
    $created = $stateChange->create([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $version['entity_twin_version_id'],
        'requested_visibility_scope' => $createOverrides['requested_visibility_scope'] ?? 'BROAD_INTERNAL_BI',
        'requested_release_status' => $createOverrides['requested_release_status'] ?? 'INTERNAL_REVIEWED',
        ...$createOverrides,
    ]);
    if ($created->isRefusal()) {
        return ['created' => $created];
    }
    $changeId = $created->value()['entity_twin_state_change_proposal_id'];

    $submitted = $stateChange->submit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_state_change_proposal_id' => $changeId,
        'governance' => $governance,
    ]);
    if ($submitted->isRefusal()) {
        return ['created' => $created, 'change_id' => $changeId, 'submitted' => $submitted];
    }

    // The council leg is real: the proposal asks for HMWH_THEN_ARBITER, and the arbiter holds a
    // proposal whose required review never happened.
    $opened = $stateChange->openReview([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_state_change_proposal_id' => $changeId,
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
        'entity_twin_state_change_proposal_id' => $changeId,
        'arbiter_policy_set_id' => $policySetId,
    ]);

    return [
        'created' => $created, 'change_id' => $changeId, 'submitted' => $submitted,
        'review_session_id' => $sessionId, 'admitted' => $admitted,
    ];
};

// --- 1. the happy path, end to end ---------------------------------------------------------------

Assert::case('P02: a supported twin version reaches a governed internal release');

$version = $makeVersion();
$flow = $admitStateChange($version);

Assert::true($flow['submitted']->isOk(), 'the state change submits through M04/M05');
Assert::same(
    'ENTITY_TWIN_STATE_CHANGE',
    (string) $db->scalar(
        'SELECT proposal_type FROM proposal_envelopes WHERE proposal_id = ?',
        [$flow['submitted']->value()['proposal_id']]
    ),
    'as an ENTITY_TWIN_STATE_CHANGE proposal, which is what the P02 rules apply to',
);
Assert::same(
    'RELEASE_INTELLIGENCE',
    (string) $db->scalar(
        'SELECT requested_action_class FROM proposal_envelopes WHERE proposal_id = ?',
        [$flow['submitted']->value()['proposal_id']]
    ),
    'requesting RELEASE_INTELLIGENCE and no higher — the two classes above it are unreachable',
);
Assert::true(
    $flow['admitted']->isOk(),
    'and the arbiter admits it: ' . ($flow['admitted']->isOk() ? 'ok' : $flow['admitted']->reasonCode()),
);
Assert::same('ADMITTED', $flow['admitted']->value()['outcome'], 'the outcome is an admission');
Assert::same(
    'VERDICT_ISSUED',
    (string) $db->scalar(
        'SELECT change_status FROM entity_twin_state_change_proposals
         WHERE entity_twin_state_change_proposal_id = ?',
        [$flow['change_id']]
    ),
    'and the binding advances to VERDICT_ISSUED — bound to the verdict, not yet released',
);

$released = $release->release([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_state_change_proposal_id' => $flow['change_id'],
    'released_by_actor_id' => $scope['operator_id'],
]);
Assert::true(
    $released->isOk(),
    'the admitted change releases: ' . ($released->isOk() ? 'ok' : $released->reasonCode()),
);

$snapshotId = $released->value()['released_entity_twin_snapshot_id'];
$decisionRecordId = $released->value()['decision_record_id'];

Assert::same(
    'INTERNAL_INFORMATIONAL_RELEASE',
    (string) $db->scalar(
        'SELECT record_type FROM decision_records WHERE decision_record_id = ?',
        [$decisionRecordId]
    ),
    'M10 wrote the release record, and it is an INTERNAL informational one',
);
Assert::same(
    $decisionRecordId,
    (string) $db->scalar(
        'SELECT decision_record_id FROM released_entity_twin_snapshots
         WHERE released_entity_twin_snapshot_id = ?',
        [$snapshotId]
    ),
    'and the snapshot is BOUND to it — the snapshot references the release, it does not mint one',
);
Assert::same(
    'BROAD_INTERNAL_BI',
    (string) $db->scalar(
        'SELECT visibility_scope FROM released_entity_twin_snapshots
         WHERE released_entity_twin_snapshot_id = ?',
        [$snapshotId]
    ),
    'released to the scope the BINDING carried — the one the arbiter judged',
);
Assert::same(
    'RELEASED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?',
        [$flow['submitted']->value()['proposal_id']]
    ),
    'and the M04 envelope advances through markReleased, against the record just written',
);

Assert::same(
    'ALREADY_RELEASED',
    $release->release([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_state_change_proposal_id' => $flow['change_id'],
    ])->reasonCode(),
    'a second release refuses by name rather than minting a second decision record',
);

// --- 2. the release ADOPTS M16, as canonical does -----------------------------------------------

Assert::case('P02: the released snapshot carries a governed M16 snapshot-view artifact');

$artifactId = (string) $released->value()['release_artifact_id'];
Assert::same(
    'ENTITY_TWIN_SNAPSHOT_VIEW',
    (string) $db->scalar(
        'SELECT artifact_kind FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'the artifact kind is the canonical P02 entity family, not an invented one',
);
Assert::same(
    $snapshotId,
    (string) $db->scalar(
        'SELECT released_entity_twin_snapshot_id FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'and it references the released snapshot as its consumer',
);
Assert::same(
    $decisionRecordId,
    (string) $db->scalar(
        'SELECT decision_record_id FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'bound to the SAME M10 record the snapshot is — M16 is a projection, not a second release',
);
Assert::same(
    'BROAD_INTERNAL_BI',
    (string) $db->scalar(
        'SELECT internal_scope_lens FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    "and it carries the released snapshot's own need-to-know lens into the M16 read law",
);

// This twin's sources are a PUBLIC card-backed reference — an external basis with no governed twin
// card — so the source-use compile HOLDS. That is a governed answer about the SOURCES, not a fault:
// the release stands, the artifact is frozen, and only an operator can read it.
Assert::same(
    'HELD',
    (string) $released->value()['artifact_outcome'],
    'an external, cardless twin source HOLDS the artifact rather than refusing the release',
);
Assert::equalsList(
    ['SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE'],
    $released->value()['artifact_hold_reasons'],
    'and the hold names which law held it',
);
Assert::same(
    'HELD_SOURCE_USE',
    (string) $db->scalar(
        'SELECT status FROM release_artifacts WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'the artifact records the hold as its status',
);

// THE HOLD IS NOT SILENCE. An operator can read it; nobody wider can.
Assert::same(
    'PROJECTED',
    (string) $db->scalar(
        "SELECT status FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class = 'OPERATOR_ONLY'",
        [$artifactId]
    ),
    'the OPERATOR_ONLY hold-view exists — a held artifact nobody could read would be pointless',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class = 'TENANT_INTERNAL'",
        [$artifactId]
    ),
    'and NO tenant-internal projection was made — the hold is what stopped it',
);
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM release_visibility_activations WHERE release_artifact_id = ?',
        [$artifactId]
    ),
    'nothing was activated, so a cardless external source cannot become tenant-visible',
);

// THE RELEASE ITSELF IS UNAFFECTED. A hold governs the artifact's audience, not the release.
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM released_entity_twin_snapshots WHERE released_entity_twin_snapshot_id = ?',
        [$snapshotId]
    ),
    'the released snapshot stands — HELD is not a rollback',
);

// --- 3. the M10 binding floor --------------------------------------------------------------------

Assert::case('P02: a snapshot may not be written M10-unbound');

$floor = new M10BindingFloor($db);
$unboundVersion = $makeVersion();

Assert::same(
    'DECISION_RECORD_MISSING',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $unboundVersion['entity_twin_version_id'],
        'visibility_scope' => 'BROAD_INTERNAL_BI',
        'release_status' => 'INTERNAL_REVIEWED',
    ])->reasonCode(),
    'no decision record: a TYPED refusal before the insert, not a NOT NULL driver error',
);
Assert::same(
    'DECISION_RECORD_NOT_FOUND',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $unboundVersion['entity_twin_version_id'],
        'visibility_scope' => 'BROAD_INTERNAL_BI',
        'release_status' => 'INTERNAL_REVIEWED',
        'decision_record_id' => Ids::new(),
    ])->reasonCode(),
    'an unknown record id is refused',
);
Assert::same(
    'DECISION_RECORD_ALREADY_BOUND',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $unboundVersion['entity_twin_version_id'],
        'visibility_scope' => 'BROAD_INTERNAL_BI',
        'release_status' => 'INTERNAL_REVIEWED',
        'decision_record_id' => $decisionRecordId,
    ])->reasonCode(),
    'and a record already bound to a snapshot cannot be replayed onto a second one',
);

// A foreign-tenant record must read exactly like an absent one — a probe that answered differently
// would tell one tenant whether another's record exists.
$other = Seed::tenantWorkspace($db, $clock, 'rival');
$otherStream = $ledger->ensureActiveStream($other['tenant_id'], $other['workspace_id'], 'TEST');
$otherRecordId = (string) $db->scalar(
    'SELECT decision_record_id FROM decision_records WHERE decision_record_id = ?',
    [$decisionRecordId]
);
Assert::same(
    'DECISION_RECORD_NOT_FOUND',
    ($floor->check($other['tenant_id'], $other['workspace_id'], $otherRecordId))['reason_code'],
    "another tenant's record reads as absent, not as forbidden",
);
Assert::true($otherStream !== '', 'the second tenant has its own ledger stream');

// --- 4. the storage gate's own refusals ----------------------------------------------------------

Assert::case('P02: the storage release gate refuses what may never be released');

/** A decision record the floor will accept, so each gate below is the ONLY thing refusing. */
$freshRecord = static function () use ($db, $clock, $scope, $ledger, $proposals, $arbiter, $council, $m03): string {
    $chain = Seed::governedReleaseChain($db, $clock, $scope);

    return (string) $chain['release']['decision_record_id'];
};

$prohibited = $makeVersion([], [
    ['field_key' => 'industry', 'field_value_safe' => 'Retail software', 'value_status' => 'PUBLIC_SOURCE'],
    ['field_key' => 'market_position', 'field_value_safe' => 'Guessed', 'value_status' => 'UNSUPPORTED_CLAIM'],
]);
Assert::same(
    'PROHIBITED_STATE_FIELD',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $prohibited['entity_twin_version_id'],
        'visibility_scope' => 'BROAD_INTERNAL_BI',
        'release_status' => 'INTERNAL_REVIEWED',
        'decision_record_id' => $freshRecord(),
    ])->reasonCode(),
    'an UNSUPPORTED_CLAIM field may never enter a released snapshot',
);

Assert::same(
    'VISIBILITY_RULE_MISSING',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $unboundVersion['entity_twin_version_id'],
        'visibility_scope' => 'EVERYONE',
        'release_status' => 'INTERNAL_REVIEWED',
        'decision_record_id' => $freshRecord(),
    ])->reasonCode(),
    'an unrecognized visibility scope is default-denied, never treated as "all"',
);

Assert::same(
    'INVALID_RELEASE_STATUS',
    $twins->releaseSnapshot([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_version_id' => $unboundVersion['entity_twin_version_id'],
        'visibility_scope' => 'BROAD_INTERNAL_BI',
        // SUPERSEDED exists in storage as the OUTCOME of a later event. It is not something a
        // caller may ask for, and asking is refused rather than reaching the CHECK constraint.
        'release_status' => 'SUPERSEDED',
        'decision_record_id' => $freshRecord(),
    ])->reasonCode(),
    'and a release status that is an outcome, not a request, is refused by name',
);

// --- 5. only an ALLOW advances -------------------------------------------------------------------

Assert::case('P02: a blocked state change terminates rather than waiting to be softened');

$profiling = $makeVersion(
    ['display_name' => 'Rival AB', 'uncertainty_summary' => 'Renewal timing is unknown; the CFO fits an anxious personality profile.'],
);
$blocked = $admitStateChange($profiling);

Assert::true(
    $blocked['admitted']->isRefusal(),
    'a state text carrying personal profiling is refused, and by the ARBITER: the version itself'
        . ' recorded fine, because the storage screen reads field keys and values, not the'
        . ' uncertainty prose a profile can hide in',
);
Assert::same(
    'BLOCKED',
    (string) $db->scalar(
        'SELECT change_status FROM entity_twin_state_change_proposals
         WHERE entity_twin_state_change_proposal_id = ?',
        [$blocked['change_id']]
    ),
    'and the binding TERMINATES — a block is not an invitation to reword',
);
Assert::same(
    'STATE_CHANGE_NOT_VERDICT_ISSUED',
    $release->release([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_state_change_proposal_id' => $blocked['change_id'],
    ])->reasonCode(),
    'so the release path refuses it outright',
);

$intent = $makeVersion(
    ['uncertainty_summary' => 'The company intends to buy a replacement platform this quarter.'],
);
$intentFlow = $admitStateChange($intent);
Assert::true(
    $intentFlow['admitted']->isRefusal(),
    'and an asserted buyer intent is refused here as it is in P03 — a fit is not an intent',
);

// --- 6. the two-gate surface ---------------------------------------------------------------------

Assert::case('P02: the released snapshot surfaces behind BOTH gates and a role lens');

$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'Analyst');
$role = Seed::role($db, $clock, 'p02-reader-' . substr(Ids::new(), 0, 8), ['entity_twin.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $reader, $role['role_id']);

$surface = static fn (string $actorId, string $lens): array => $release->listVisibleSnapshots([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $actorId,
    'visibility_scope' => $lens,
]);

$denied = $surface($reader, 'BROAD_INTERNAL_BI');
Assert::same('DENIED', $denied['decision'], 'an M02 grant alone does not open the surface');
Assert::equalsList(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $denied['reason_codes'],
    'the tier is the second gate, and it says so — a role grant is not a purchase',
);
Assert::same([], $denied['rows'], 'and the list is EMPTY rather than partial');

$tierId = $entitlements->defineTier(
    'p02_test_tier', 'P02 Test', 149900, ['entity_twin.read']
)->value()['subscription_tier_id'];
$entitlements->subscribe($scope['tenant_id'], $tierId);

$visible = $surface($reader, 'BROAD_INTERNAL_BI');
Assert::same('VISIBLE', $visible['decision'], 'with both gates satisfied the surface opens');
Assert::same(1, count($visible['rows']), 'and shows the one released snapshot');
Assert::same(
    $snapshotId,
    (string) $visible['rows'][0]['released_entity_twin_snapshot_id'],
    'which is the snapshot the governed path released',
);

Assert::same(
    [],
    $surface($reader, 'SALES')['rows'],
    'a different role lens sees nothing — a snapshot released to one scope does not leak into another',
);
Assert::same(
    'DENIED',
    $surface($reader, 'EVERYONE')['decision'],
    'and an unrecognized lens is default-denied rather than treated as unfiltered',
);

$stranger = Seed::operator($db, $clock, $scope['tenant_id'], 'Stranger');
Assert::same(
    'DENIED',
    $surface($stranger, 'BROAD_INTERNAL_BI')['decision'],
    'an operator with no membership is denied even though the tier now grants the permission',
);

// --- 7. the twelve audits ------------------------------------------------------------------------

Assert::case('P02: the twelve audits re-scan what was actually released');

$m11 = new M11Assurance($db, $clock, $ledger);
$assurance = new TwinAssurance($db, $clock, $m11);
$profiles = $assurance->seedProfiles($scope['tenant_id'], $scope['workspace_id']);

Assert::equalsList(
    TwinAssurance::AUDIT_CATEGORIES,
    array_keys($profiles),
    'all twelve profiles are seeded, one per control',
);
Assert::same(
    $profiles,
    $assurance->seedProfiles($scope['tenant_id'], $scope['workspace_id']),
    'and seeding again converges — one profile per control per scope, not one per run',
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT MIN(does_not_grant_authority) FROM audit_profiles WHERE tenant_id = ?',
        [$scope['tenant_id']]
    ),
    'every profile is pinned as granting no authority — assurance is not a second authority plane',
);

// The released snapshot from part 1 is clean, so every control passes over it. This is the state
// the port ships in, and a control that could not pass would be a control nobody could satisfy.
foreach (TwinAssurance::AUDIT_CATEGORIES as $category) {
    $run = $assurance->runAudit(['audit_profile_id' => $profiles[$category]]);
    Assert::true($run->isOk(), "{$category} runs: " . ($run->isOk() ? '' : $run->reasonCode()));
    if ($category === 'RETENTION_POLICY_COVERAGE') {
        // The one control the released twin does NOT yet satisfy: no anchor has been recorded.
        // A retention policy nobody declared is not a retention policy.
        Assert::same(1, $run->value()['violation_count'], 'RETENTION_POLICY_COVERAGE finds the undeclared twin');
        continue;
    }
    Assert::same(
        0,
        $run->value()['violation_count'],
        "{$category}: the governed release satisfies this control",
    );
    // A PASS is recorded as a finding too, at the lowest severity and requiring nothing. An absent
    // row would be indistinguishable from an audit that never ran.
    $pass = $db->one(
        'SELECT finding_type, severity, incident_evaluation_required
         FROM audit_findings WHERE audit_finding_id = ?',
        [$run->value()['audit_finding_id']]
    );
    Assert::same('CONTROL_PASS', (string) $pass['finding_type'], "{$category}: the pass is recorded as one");
    Assert::same('S1', (string) $pass['severity'], "{$category}: at the lowest severity");
    Assert::same(0, (int) $pass['incident_evaluation_required'], "{$category}: and requires no evaluation");
}

$anchored = $twins->recordLedgerAnchor([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $version['entity_twin_id'],
    'anchor_kind' => 'RETENTION',
    'anchor_summary_safe' => 'Twin state retained for the licence review window.',
    'retention_policy_safe' => 'short',
]);
Assert::true($anchored->isOk(), 'a RETENTION anchor records');
Assert::same(
    0,
    $assurance->runAudit(['audit_profile_id' => $profiles['RETENTION_POLICY_COVERAGE']])
        ->value()['violation_count'],
    'and the control now passes — the audit was reporting a real gap, not a defect in itself',
);

// An anchor is release-gating AUTHORITY, so it must come from inside the twin's own workspace.
Assert::same(
    'ENTITY_TWIN_SCOPE_MISMATCH',
    $twins->recordLedgerAnchor([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => Ids::new(),
        'entity_twin_id' => $version['entity_twin_id'],
        'anchor_kind' => 'RETENTION',
        'anchor_summary_safe' => 'Declared from outside.',
    ])->reasonCode(),
    'a caller outside the workspace cannot declare its retention policy',
);
Assert::same(
    'INVALID_ANCHOR_KIND',
    $twins->recordLedgerAnchor([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_id' => $version['entity_twin_id'],
        'anchor_kind' => 'WHATEVER',
        'anchor_summary_safe' => 'Invented.',
    ])->reasonCode(),
    'and the anchor kinds are a closed set',
);

// --- 8. an audit that has something to find ------------------------------------------------------

Assert::case('P02: an audit finds a real violation, records it, and opens an incident');

// The released row is APPEND-ONLY, so the violation cannot be introduced by editing one. It is
// introduced the way it would really arrive: a SECOND fresh release of the same version, which is
// two current answers to one question. The state-change binding refuses this, so the fixture goes
// through the storage gate directly — which is exactly the layer this audit exists to re-check.
$freshRecordId = (string) Seed::governedReleaseChain($db, $clock, $scope)['release']['decision_record_id'];
$second = $twins->releaseSnapshot([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_version_id' => $version['entity_twin_version_id'],
    'visibility_scope' => 'BROAD_INTERNAL_BI',
    'release_status' => 'INTERNAL_REVIEWED',
    'decision_record_id' => $freshRecordId,
]);
Assert::true($second->isOk(), 'a second fresh release of one version is storable: ' . ($second->isOk() ? '' : $second->reasonCode()));

$integrity = $assurance->runAudit(['audit_profile_id' => $profiles['TWIN_STATE_VERSION_INTEGRITY']]);
Assert::same(2, $integrity->value()['violation_count'], 'and the integrity audit finds BOTH fresh releases');
Assert::equalsList(
    ['MULTIPLE_FRESH_RELEASES_FOR_VERSION'],
    $integrity->value()['reason_codes'],
    'naming the reason rather than reporting a count',
);
Assert::notSame(null, $integrity->value()['incident_case_id'], 'a violation OPENS AN INCIDENT');
Assert::same(
    'FAIL',
    (string) $db->scalar(
        'SELECT decision FROM audit_check_records WHERE audit_run_id = ?',
        [$integrity->value()['audit_run_id']]
    ),
    'the check record says FAIL',
);
Assert::same(
    2,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM released_entity_twin_snapshots WHERE entity_twin_version_id = ?',
        [$version['entity_twin_version_id']]
    ),
    'and the audit REPAIRED NOTHING — both rows are still there, which is the whole point',
);

// The profile's category is authority for what it may conclude.
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_P02',
    $assurance->runAudit([
        'audit_profile_id' => $m11->createAuditProfile(
            $scope['tenant_id'], $scope['workspace_id'], 'ledger-' . substr(Ids::new(), 0, 8),
            'LEDGER_INTEGRITY', 'Ledger integrity'
        ),
    ])->reasonCode(),
    'a profile of another category cannot back a P02 finding',
);

// --- 9. every control is proved to BITE ----------------------------------------------------------

Assert::case('P02: each of the twelve audits finds the violation it exists to find');

/**
 * The violations below are written STRAIGHT INTO STORAGE, and that is the point.
 *
 * Every one of them is refused by the write path — `appendVersion` screens the field keys, the
 * admission rules screen the state text, the release gate screens the statuses and the ceiling. So
 * a fixture built through the product modules could not produce a single one, and the audits would
 * pass vacuously forever while proving nothing.
 *
 * These rows are the anomaly the audits exist for: state that reached the released surface without
 * passing the gate that should have stopped it — a future code path, a migration, a direct write.
 * If the audit cannot see it here, it will not see it there either.
 */
$bypass = $makeVersion(['display_name' => 'Bypass AB']);
$bypassSnapshotId = $twins->releaseSnapshot([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_version_id' => $bypass['entity_twin_version_id'],
    'visibility_scope' => 'PRODUCT',
    'release_status' => 'INTERNAL_REVIEWED',
    'decision_record_id' => $freshRecord(),
])->value()['released_entity_twin_snapshot_id'];
$twins->recordLedgerAnchor([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $bypass['entity_twin_id'],
    'anchor_kind' => 'RETENTION',
    'anchor_summary_safe' => 'Retained for the licence review window.',
]);

$field = static function (string $key, string $status) use ($db, $clock, $scope, $bypass): void {
    $db->insert('entity_twin_state_summaries', [
        'entity_twin_state_summary_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'entity_twin_version_id' => $bypass['entity_twin_version_id'],
        'field_key' => $key,
        'field_value_safe' => 'x',
        'value_status' => $status,
        'created_at' => $clock->now(),
    ]);
};
$field('employee_productivity_score', 'PUBLIC_SOURCE');   // NO_EMPLOYEE_SCORING
$field('buyer_intent', 'KNOWN_FACT');                     // BUYER_INTENT_OVERCLAIM
$field('acquisition_rumour', 'UNSUPPORTED_CLAIM');        // TWIN_STATE_EVIDENCE_COVERAGE
$field('internal_headcount', 'PROHIBITED');               // NO_PRIVATE_EXTERNAL_DATA
$field('pricing_direction', 'CONFLICTING');               // TWIN_UNCERTAINTY_VISIBILITY (has_conflict = 0)

$link = static function (string $basis, string $allowedUse) use ($db, $clock, $scope, $bypass): void {
    $db->insert('entity_twin_source_links', [
        'entity_twin_source_link_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'entity_twin_version_id' => $bypass['entity_twin_version_id'],
        // The storage CHECK requires a cited basis unless it is manual or a prior snapshot: an
        // unsourced link cannot be written even to build a violation.
        'source_ref_id' => $bypass['source_ref_id'],
        'source_basis' => $basis,
        'allowed_use' => $allowedUse,
        'created_at' => $clock->now(),
    ]);
};
$link('LICENSED_SOURCE', 'RESTRICTED');    // SOURCE_LICENSE_CONFORMANCE
$link('TENANT_OWNED', 'INTERNAL_ONLY');    // FIRST_PARTY_EXTERNAL_BOUNDARY (the twin is a COMPETITOR)

// A snapshot whose declared ceiling is one of the two WITHDRAWN values. The release gate refuses
// this outright, so the row goes in directly — which is the only way it could ever exist.
$db->insert('released_entity_twin_snapshots', [
    'released_entity_twin_snapshot_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $bypass['entity_twin_id'],
    'entity_twin_version_id' => $bypass['entity_twin_version_id'],
    'entity_twin_uncertainty_summary_id' => (string) $db->scalar(
        'SELECT entity_twin_uncertainty_summary_id FROM entity_twin_uncertainty_summaries
         WHERE entity_twin_version_id = ?',
        [$bypass['entity_twin_version_id']]
    ),
    'claim_ceiling' => 'RETRACTED',
    'visibility_scope' => 'PRODUCT',
    'snapshot_digest' => str_repeat('c', 64),
    'release_status' => 'INTERNAL_REVIEWED',
    'decision_record_id' => $freshRecord(),
    'released_at' => $clock->now(),
]);

// A stakeholder role profile carrying a forbidden attribute. Nothing in this port writes these
// tables — the control is a STANDING one, and this is what proves it is watching rather than merely
// declared.
$profileRowId = Ids::new();
$db->insert('stakeholder_role_profiles', [
    'stakeholder_role_profile_id' => $profileRowId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $bypass['entity_twin_id'],
    'display_name' => 'Finance function',
    'business_role' => 'Finance',
    'decision_role_category' => 'DECISION_MAKER',
    'account_relationship' => 'PROSPECT_CONTACT',
    'source_basis' => 'PUBLIC_SOURCE',
    'contact_permission' => 'NO_CONTACT',
    'visibility_scope' => 'PRODUCT',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
    'updated_at' => $clock->now(),
]);
$db->insert('stakeholder_role_attributes', [
    'stakeholder_role_attribute_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'stakeholder_role_profile_id' => $profileRowId,
    'attribute_key' => 'personality_profile',
    'attribute_value_safe' => 'x',
    'created_at' => $clock->now(),
]);

// A released relationship snapshot citing no evidence. Same standing-control reasoning: the
// relationship release lifecycle is not ported, so nothing else can put a row here.
$edgeId = Ids::new();
$db->insert('entity_relationship_edges', [
    'relationship_edge_id' => $edgeId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'source_entity_twin_id' => $bypass['entity_twin_id'],
    'target_entity_twin_id' => $version['entity_twin_id'],
    'relationship_type' => 'COMPETES_WITH',
    'direction' => 'BIDIRECTIONAL',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
    'updated_at' => $clock->now(),
]);
$relVersionId = Ids::new();
$db->insert('entity_relationship_versions', [
    'relationship_version_id' => $relVersionId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'relationship_edge_id' => $edgeId,
    'version_sequence' => 1,
    'confidence' => 'MEDIUM',
    'freshness_observed_at' => $clock->now(),
    'business_relevance' => 'MEDIUM',
    'allowed_use' => 'INTERNAL_ONLY',
    'uncertainty_summary_safe' => 'Observed from public listings only.',
    'claim_ceiling' => 'INTERNAL_REVIEWED',
    'version_digest' => str_repeat('d', 64),
    'created_at' => $clock->now(),
]);
$db->insert('released_entity_relationship_snapshots', [
    'released_relationship_snapshot_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'relationship_edge_id' => $edgeId,
    'relationship_version_id' => $relVersionId,
    'claim_ceiling' => 'INTERNAL_REVIEWED',
    'visibility_scope' => 'PRODUCT',
    'snapshot_digest' => str_repeat('e', 64),
    'release_status' => 'INTERNAL_REVIEWED',
    'decision_record_id' => $freshRecord(),
    'released_at' => $clock->now(),
]);

// Every control now has something to find. NOT ONE of them may report zero.
foreach ([
    'TWIN_STATE_EVIDENCE_COVERAGE' => 'UNSUPPORTED_RELEASED_TWIN_CLAIM',
    'TWIN_RELATIONSHIP_EDGE_SUPPORT' => 'RELEASED_RELATIONSHIP_EDGE_UNSUPPORTED',
    'TWIN_UNCERTAINTY_VISIBILITY' => 'UNCERTAINTY_NOT_VISIBLE',
    'STAKEHOLDER_PROFILE_MINIMIZATION' => 'FORBIDDEN_STAKEHOLDER_ATTRIBUTE',
    'NO_EMPLOYEE_SCORING' => 'EMPLOYEE_SCORING_FIELD_RELEASED',
    'NO_PRIVATE_EXTERNAL_DATA' => 'PRIVATE_EXTERNAL_DATA_RELEASED',
    'SOURCE_LICENSE_CONFORMANCE' => 'RESTRICTED_SOURCE_RELEASED',
    'BUYER_INTENT_OVERCLAIM' => 'BUYER_INTENT_OVERCLAIM_RELEASED',
    'FIRST_PARTY_EXTERNAL_BOUNDARY' => 'FIRST_PARTY_DATA_ON_EXTERNAL_TWIN',
    'RELEASE_CEILING_DECLARATION' => 'INVALID_RELEASE_CLAIM_CEILING',
] as $category => $reasonCode) {
    $run = $assurance->runAudit(['audit_profile_id' => $profiles[$category]])->value();
    Assert::true($run['violation_count'] > 0, "{$category}: finds the violation planted for it");
    Assert::equalsList([$reasonCode], $run['reason_codes'], "{$category}: names {$reasonCode}");
    Assert::notSame(null, $run['incident_case_id'], "{$category}: opens an incident");

    // The FINDING ROW carries the escalation, not just the return value. A finding that recorded a
    // violation while marking it as needing no evaluation would be a fault filed as a formality.
    $finding = $db->one(
        'SELECT finding_type, severity, current_reliance_consequence, incident_evaluation_required,
                decision_ledger_record_required
         FROM audit_findings WHERE audit_finding_id = ?',
        [$run['audit_finding_id']]
    );
    Assert::notSame('CONTROL_PASS', (string) $finding['finding_type'], "{$category}: the finding is not a pass");
    Assert::same('S3', (string) $finding['severity'], "{$category}: recorded at S3");
    Assert::same(
        'INCIDENT_EVALUATION_REQUIRED',
        (string) $finding['current_reliance_consequence'],
        "{$category}: what is currently relied on is affected, and the finding says so",
    );
    Assert::same(1, (int) $finding['incident_evaluation_required'], "{$category}: requires evaluation");
    Assert::same(
        1,
        (int) $finding['decision_ledger_record_required'],
        "{$category}: and a decision-ledger record — a violation of a released claim is a ledger event",
    );
    Assert::true(
        in_array($bypassSnapshotId, $run['violating_refs'], true)
            || $category === 'TWIN_RELATIONSHIP_EDGE_SUPPORT'
            || $category === 'STAKEHOLDER_PROFILE_MINIMIZATION'
            || $category === 'RELEASE_CEILING_DECLARATION',
        "{$category}: names the offending released snapshot, not just a count",
    );
}

// --- 10. the M16 outcome characterization -------------------------------------------------------

Assert::case('P02 M16: a tenant-owned basis COMPOSES; a cardless external basis HOLDS');

/** A twin version whose only source basis is the tenant's own material. */
$tenantOwnedVersion = static function () use ($db, $clock, $scope, $twins): array {
    $twinId = $twins->registerTwin(
        $scope['tenant_id'], $scope['workspace_id'], 'CUSTOMER', 'Our own account view',
        [], $scope['operator_id']
    )->value()['entity_twin_id'];
    $version = $twins->appendVersion([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'entity_twin_id' => $twinId,
        'claim_ceiling' => 'INTERNAL_REVIEWED',
        'fields' => [
            ['field_key' => 'segment', 'field_value_safe' => 'SME', 'value_status' => 'TENANT_PROVIDED'],
        ],
        'uncertainty' => ['summary_safe' => 'Recorded by the tenant; no external source consulted.'],
        // TENANT_PROVIDED_MANUAL needs no evidence or source reference — the storage CHECK permits
        // it precisely because the tenant's own statement IS the basis.
        'source_links' => [[
            'source_basis' => 'TENANT_PROVIDED_MANUAL',
            'allowed_use' => 'INTERNAL_ONLY',
        ]],
    ]);

    return ['entity_twin_id' => $twinId, 'entity_twin_version_id' => $version->value()['entity_twin_version_id']];
};

$owned = $tenantOwnedVersion();
$ownedFlow = $admitStateChange($owned, ['requested_visibility_scope' => 'SALES']);
Assert::true($ownedFlow['admitted']->isOk(), 'the tenant-owned state change is admitted');

$ownedReleased = $release->release([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_state_change_proposal_id' => $ownedFlow['change_id'],
]);
Assert::true(
    $ownedReleased->isOk(),
    'and releases: ' . ($ownedReleased->isOk() ? '' : $ownedReleased->reasonCode()),
);
Assert::same(
    'COMPOSED',
    (string) $ownedReleased->value()['artifact_outcome'],
    'a first-party basis folds at the top of the lattice and COMPOSES — it never holds itself',
);

$ownedArtifactId = (string) $ownedReleased->value()['release_artifact_id'];
foreach (['OPERATOR_ONLY', 'TENANT_INTERNAL'] as $audience) {
    Assert::same(
        'PROJECTED',
        (string) $db->scalar(
            'SELECT status FROM customer_output_projections
             WHERE release_artifact_id = ? AND audience_class = ?',
            [$ownedArtifactId, $audience]
        ),
        "a composed artifact projects for {$audience}",
    );
    Assert::same(
        'ACTIVATED',
        (string) $db->scalar(
            'SELECT event_kind FROM release_visibility_activations
             WHERE release_artifact_id = ? AND audience_class = ?
             ORDER BY event_sequence DESC LIMIT 1',
            [$ownedArtifactId, $audience]
        ),
        "and its {$audience} visibility is activated",
    );
}

// --- 11. the P02 access rule is its own family --------------------------------------------------

Assert::case('P02 M16: entity_twin.read governs the P02 families, whatever registered first');

foreach (['ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW'] as $kind) {
    foreach (['OPERATOR_ONLY', 'TENANT_INTERNAL'] as $audience) {
        Assert::same(
            'entity_twin.read',
            (string) $db->scalar(
                "SELECT required_permission_key FROM output_access_rules
                 WHERE tenant_id = ? AND audience_class = ? AND artifact_kind = ? AND status = 'ACTIVE'",
                [$scope['tenant_id'], $audience, $kind]
            ),
            "{$kind}/{$audience} is governed by entity_twin.read",
        );
    }
}

// The P01 families keep their own permission. One tenant, two product families, two authorities —
// which is the whole reason the rule is keyed by artifact kind rather than by audience alone.
$m16Output->seedVisibilityProfiles(
    $scope['tenant_id'], 'briefing.read', ['COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING']
);
Assert::same(
    'briefing.read',
    (string) $db->scalar(
        "SELECT required_permission_key FROM output_access_rules
         WHERE tenant_id = ? AND audience_class = 'TENANT_INTERNAL'
           AND artifact_kind = 'BATTLECARD_BRIEFING' AND status = 'ACTIVE'",
        [$scope['tenant_id']]
    ),
    'and the P01 briefing family keeps briefing.read, unaffected by the P02 registration',
);
Assert::same(
    'entity_twin.read',
    $m16Output->requiredPermissionFor($scope['tenant_id'], 'TENANT_INTERNAL', 'ENTITY_TWIN_SNAPSHOT_VIEW'),
    'the twin family still answers entity_twin.read after the P01 registration ran second',
);

// A registration that would REDEFINE a standing rule is refused before it writes anything.
Assert::same(
    'ACCESS_RULE_CONFLICT',
    $m16Output->seedVisibilityProfiles(
        $scope['tenant_id'], 'something_else.read', ['ENTITY_TWIN_SNAPSHOT_VIEW']
    )->reasonCode(),
    'and a conflicting re-registration is refused rather than silently rebinding the family',
);
Assert::same(
    'entity_twin.read',
    $m16Output->requiredPermissionFor($scope['tenant_id'], 'TENANT_INTERNAL', 'ENTITY_TWIN_SNAPSHOT_VIEW'),
    'the standing rule is untouched — the preflight refused BEFORE any write',
);

// --- 12. replay mints nothing twice -------------------------------------------------------------

Assert::case('P02 M16: a replayed composition returns the same artifact');

$artifactsBefore = (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts');
$replay = $p02Adapter->composeTwinSnapshotArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'released_entity_twin_snapshot_id' => (string) $ownedReleased->value()['released_entity_twin_snapshot_id'],
    'decision_record_id' => (string) $ownedReleased->value()['decision_record_id'],
]);
Assert::true($replay->isOk(), 'the replay succeeds');
Assert::same(
    $ownedArtifactId,
    (string) $replay->value()['release_artifact_id'],
    'returning the SAME artifact rather than minting a second one',
);
Assert::same(
    $artifactsBefore,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts'),
    'and the artifact count is unchanged — a retry replays, it does not duplicate',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class = 'TENANT_INTERNAL'",
        [$ownedArtifactId]
    ),
    'nor does it mint a second projection',
);
