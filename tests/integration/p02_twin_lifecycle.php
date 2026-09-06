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
$release = new TwinRelease($db, $clock, $ledger, $proposals, $twins, $access, $entitlements);

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

// --- 2. the release does NOT route through M16 ---------------------------------------------------

Assert::case('P02: the release is M10-bound and carries no M16 artifact');

Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts'),
    'no M16 release artifact is composed — P02 adoption is not ported, and nothing pretends it is',
);
Assert::false(
    in_array('release_artifact_id', array_map(
        static fn (array $c): string => (string) $c['Field'],
        $db->all('SHOW COLUMNS FROM released_entity_twin_snapshots')
    ), true),
    'and the released row has no artifact column to bind one to — the reference is to M10',
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
