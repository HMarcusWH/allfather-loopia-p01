<?php

declare(strict_types=1);

/**
 * M08 Arbiter, proved against real MariaDB.
 *
 * The evaluation ORDER is the law, so this suite tests the order as much as the outcomes:
 * non-overridable seeds first, in fixed sequence; then ordinary policy rules priority-descending,
 * where the FIRST refusal decides. A policy rule that would allow can never outrank a seed that
 * refuses, and no permissive rule can be reached past an earlier refusal.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M03\Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M08\Verdicts;
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
$m03 = new Registry($db, $clock);
$m04 = new Proposals($db, $clock);
$m05 = new EvidenceSpine($db, $clock);
$readiness = new Readiness($db, $clock);
$m07 = new Council($db, $clock);
$policies = new PolicySets($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);

// ============================================================ the pure verdict mappings
// Tested as data, because they are the fail-closed core.

Assert::same(null, Verdicts::readinessToVerdict('SATISFIED_FOR_EVALUATION'), 'satisfied readiness passes');
foreach ([
    'QUARANTINED' => 'QUARANTINE',
    'FORBIDDEN_USE' => 'BLOCK',
    'CONFLICTED' => 'HOLD',
    'STALE_FOR_USE' => 'REQUIRE_MORE_EVIDENCE',
    'INCOMPLETE' => 'REQUIRE_MORE_EVIDENCE',
] as $status => $expected) {
    Assert::same($expected, Verdicts::readinessToVerdict($status)['verdict'], "readiness {$status} routes to {$expected}");
}

// THE fail-closed default: a status nobody anticipated must never become permission.
Assert::same(
    'HOLD',
    Verdicts::readinessToVerdict('SOMETHING_NOBODY_DEFINED')['verdict'],
    'an UNKNOWN readiness status fails CLOSED to HOLD',
);
Assert::same(
    'HOLD',
    Verdicts::hmwhRecommendationToVerdict('SOMETHING_NOBODY_DEFINED')['verdict'],
    'an unrecognized council recommendation fails CLOSED to HOLD',
);

// No mapping ever produces an ALLOW.
foreach (['QUARANTINED', 'FORBIDDEN_USE', 'CONFLICTED', 'STALE_FOR_USE', 'INCOMPLETE', 'UNKNOWN'] as $status) {
    $verdict = Verdicts::readinessToVerdict($status)['verdict'];
    Assert::true(!str_starts_with($verdict, 'ALLOW'), "readiness {$status} never reaches an ALLOW");
}
foreach (['BLOCK', 'QUARANTINE_OR_INCIDENT_EVALUATION', 'REQUIRE_MORE_EVIDENCE', 'HOLD'] as $rec) {
    $verdict = Verdicts::hmwhRecommendationToVerdict($rec)['verdict'];
    Assert::true(!str_starts_with($verdict, 'ALLOW'), "council {$rec} never reaches an ALLOW");
}

// ============================================================ a full evaluable chain

$policy = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $scope['tenant_id'], $scope['workspace_id']);
Assert::true($policy->isOk(), 'the P01 policy set seeds');
$policySetId = $policy->value()['arbiter_policy_set_id'];

Assert::same(
    1,
    (int) $db->scalar('SELECT does_not_approve_or_execute FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?', [$policySetId]),
    'does_not_approve_or_execute is CHECK-pinned to 1',
);
try {
    $db->run('UPDATE arbiter_policy_sets SET does_not_approve_or_execute = 0 WHERE arbiter_policy_set_id = ?', [$policySetId]);
    Assert::fail('the no-approve pin should reject being flipped to 0');
} catch (\PDOException) {
    Assert::pass();
}

// The three non-overridable seed rules are DECLARED for the record.
Assert::same(
    3,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM arbiter_policy_rules WHERE arbiter_policy_set_id = ? AND rule_category IN ('V1_SCOPE','M05_EVIDENCE','M07_HMWH')",
        [$policySetId]
    ),
    'the three seed guarantees are declared in the policy set for the record',
);

$producer = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Monitor', 'COMPETITION_MONITOR_VALKYRIE',
    'Monitors approved sources.', $scope['operator_id'], 'ACTIVE'
);
$critic = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Critic', 'REVIEW_CRITIC_VALKYRIE',
    'Independent review.', $scope['operator_id'], 'ACTIVE'
);
$contractId = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.monitor',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];

$missionId = $m04->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
    'Monitor pricing', 'Detect material pricing changes.',
    ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']
);

/** Build a submitted proposal with a frozen evidence bundle behind it. */
$buildProposal = static function (bool $withEvidence = true) use (
    $m04, $m05, $scope, $missionId, $producer, $contractId
): array {
    $proposalId = $m04->createDraft([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'mission_id' => $missionId,
        'producing_valkyrie_id' => $producer,
        'capability_contract_id' => $contractId,
        'proposal_type' => 'COMPANY_SIGNAL_BRIEF',
        'requested_action_class' => 'RELEASE_INTELLIGENCE',
        'title' => 'Pricing change',
        'safe_purpose_summary' => 'Brief an observed pricing change.',
        'proposed_content_or_action_summary' => 'List price moved 449 -> 499 SEK.',
    ])->value()['proposal_id'];

    $bundleId = null;
    if ($withEvidence) {
        $sourceRefId = $m05->registerSource(
            $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
            'https://competitor.example/pricing', 'OBSERVE_AND_DERIVE',
            'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
        );
        $evidenceId = $m05->capture([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'mission_id' => $missionId,
            'source_ref_id' => $sourceRefId,
            'evidence_type' => 'SOURCE_CAPTURE',
            'captured_content_reference_safe' => 'evidence://pricing',
            'content_hash' => Digest::contentDigest('pricing ' . $proposalId),
            'capture_method' => 'GOVERNED_HTTPS_GET',
            'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
        ])->value()['evidence_id'];

        $bundleId = $m05->createBundleDraft(
            $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId, 'P01 signal basis'
        );
        $m05->addBundleItem($bundleId, $evidenceId);
        $m05->submitBundle($bundleId);
    }

    return ['proposal_id' => $proposalId, 'evidence_bundle_id' => $bundleId];
};

// --- SEED 2: no evidence -> REQUIRE_MORE_EVIDENCE, before any policy rule runs ------------------

$noEvidence = $buildProposal(false);
$m04->submit($noEvidence['proposal_id']);
$verdict = $arbiter->evaluateProposal($noEvidence['proposal_id'], $policySetId);
Assert::true($verdict->isOk(), 'the arbiter issues a verdict');
Assert::same('REQUIRE_MORE_EVIDENCE', $verdict->value()['verdict'], 'no evidence bundle -> REQUIRE_MORE_EVIDENCE');
Assert::true(
    in_array('ARBITER_EVIDENCE_NOT_READY', $verdict->value()['reason_codes'], true),
    'and carries the canonical readiness reason code',
);
Assert::same(
    'M05_ADDITIONAL_EVIDENCE_PATH',
    $verdict->value()['permitted_next_stage'],
    'routed to the additional-evidence path, not to release',
);

// --- SEED 3: a required council review that is MISSING holds -------------------------------------

$withEvidence = $buildProposal();
$m04->submit($withEvidence['proposal_id']);
$held = $arbiter->evaluateProposal($withEvidence['proposal_id'], $policySetId);
Assert::same('HOLD', $held->value()['verdict'], 'a required council review that is missing HOLDs');
Assert::true(
    in_array('ARBITER_REQUIRED_HMWH_MISSING', $held->value()['reason_codes'], true),
    'refused by the canonical code',
);

// --- the council BLOCKs -> the arbiter BLOCKs -----------------------------------------------------

$blocked = $buildProposal();
$m04->submit($blocked['proposal_id']);
$session = $m07->openSession(
    $scope['tenant_id'], $scope['workspace_id'], $missionId, $blocked['proposal_id'],
    'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
)->value()['review_session_id'];
$assignment = $m07->assignParticipant($session, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
    ->value()['participant_assignment_id'];
$m07->recordAssessment($session, $assignment, $critic, 'BLOCK', 'The claim is not supported.');
$m07->freezeResult($session, 'BLOCK', 'Do not proceed.');

$blockedVerdict = $arbiter->evaluateProposal($blocked['proposal_id'], $policySetId);
Assert::same('BLOCK', $blockedVerdict->value()['verdict'], 'a council BLOCK becomes an arbiter BLOCK');
Assert::true(
    in_array('ARBITER_HMWH_RECOMMENDED_BLOCK', $blockedVerdict->value()['reason_codes'], true),
    'refused by the canonical code',
);
Assert::same('NO_PROGRESSION', $blockedVerdict->value()['permitted_next_stage'], 'with no onward route');

// --- the happy path: every check passes -> ALLOW_INFORMATIONAL_RELEASE ------------------------------

// The P01 signal chain is built by the shared seed helper, the way the product builds it:
// competitor -> source card -> monitored source -> two snapshots -> diff -> signal. Each override
// below bends exactly one link, so a single refusal path can be isolated.
$attachSignal = static function (string $proposalId, array $overrides = []) use ($db, $clock, $scope, $missionId): void {
    Seed::p01SignalChain($db, $clock, $scope, $missionId, $proposalId, $overrides);
};

/** Run a proposal all the way to a verdict with a supportive council result. */
$evaluateWithCouncil = static function (array $signalOverrides = []) use (
    $buildProposal, $m04, $m07, $arbiter, $attachSignal, $scope, $missionId, $critic, $policySetId
): array {
    $built = $buildProposal();
    $attachSignal($built['proposal_id'], $signalOverrides);
    $m04->submit($built['proposal_id']);

    $sessionId = $m07->openSession(
        $scope['tenant_id'], $scope['workspace_id'], $missionId, $built['proposal_id'],
        'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
    )->value()['review_session_id'];
    $assignmentId = $m07->assignParticipant($sessionId, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
        ->value()['participant_assignment_id'];
    $m07->recordAssessment($sessionId, $assignmentId, $critic, 'SUPPORT_PROCEED_TO_ARBITER', 'Supported.');
    $m07->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Proceed.');

    return $arbiter->evaluateProposal($built['proposal_id'], $policySetId)->value();
};

$allowed = $evaluateWithCouncil();
Assert::same('ALLOW_INFORMATIONAL_RELEASE', $allowed['verdict'], 'every check passing reaches ALLOW_INFORMATIONAL_RELEASE');
Assert::same('M10_INTERNAL_RELEASE_RECORDING', $allowed['permitted_next_stage'], 'routed to the ledger');
Assert::same(64, strlen($allowed['basis_hash']), 'the verdict carries a basis hash binding its exact inputs');

// --- the four P01_RELEVANCE rules, each proven to refuse ---------------------------------------------

foreach ([
    ['overrides' => ['competitor_class' => 'MARKET_REFERENCE'], 'reason' => 'OUTSIDE_CUSTOMER_INTEREST',
     'why' => 'a competitor outside the customer\'s competitive interest'],
    ['overrides' => ['customer_output_allowed' => 0], 'reason' => 'SOURCE_AUTHORITY_TOO_LOW',
     'why' => 'a source card that does not permit customer-visible output'],
    ['overrides' => ['license_class' => 'LICENSED_NO_CUSTOMER_OUTPUT'], 'reason' => 'SOURCE_AUTHORITY_TOO_LOW',
     'why' => 'a licence class that forbids customer output, even when the flag allows it'],
    ['overrides' => ['change_significance' => 'UNKNOWN'], 'reason' => 'INSUFFICIENT_EVIDENCE',
     'why' => 'an unclassified diff'],
    ['overrides' => ['review_status' => 'expired'], 'reason' => 'STALE_INPUT',
     'why' => 'an expired source-card review'],
    ['overrides' => ['review_expires_at' => '2020-01-01T00:00:00.000Z'], 'reason' => 'STALE_INPUT',
     'why' => 'a review window that lapsed without anyone re-classifying it'],
] as $case) {
    $result = $evaluateWithCouncil($case['overrides']);
    Assert::notSame(
        'ALLOW_INFORMATIONAL_RELEASE',
        $result['verdict'],
        'refused: ' . $case['why'],
    );
    Assert::true(
        in_array($case['reason'], $result['reason_codes'], true),
        'and by the canonical reason code ' . $case['reason'] . ' (' . $case['why'] . ')',
    );
    Assert::true(
        in_array('ARBITER_P01_RELEVANCE_DENIED', $result['reason_codes'], true),
        'plus the family code, so the transcript names which family refused',
    );
}

// --- policy preconditions ------------------------------------------------------------------------------

$fresh = $buildProposal();
$attachSignal($fresh['proposal_id']);

$notSubmitted = $arbiter->evaluateProposal($fresh['proposal_id'], $policySetId);
Assert::true($notSubmitted->isRefusal(), 'a DRAFT proposal cannot be evaluated');
Assert::same(
    Arbiter::ARBITER_PROPOSAL_NOT_SUBMITTED,
    $notSubmitted->reasonCode(),
    'a verdict must be about a FROZEN artifact',
);
$m04->submit($fresh['proposal_id']);

$unknownPolicy = $arbiter->evaluateProposal($fresh['proposal_id'], Ids::new());
Assert::same(Arbiter::ARBITER_POLICY_NOT_FOUND, $unknownPolicy->reasonCode(), 'an unknown policy set is refused');

$missingProposal = $arbiter->evaluateProposal(Ids::new(), $policySetId);
Assert::same(Arbiter::ARBITER_PROPOSAL_NOT_FOUND, $missingProposal->reasonCode(), 'an unknown proposal is refused');

// --- THE cross-tenant policy guard ------------------------------------------------------------------------

$rival = Seed::tenantWorkspace($db, $clock, 'rival');
$rivalPolicySetId = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $rival['tenant_id'], $rival['workspace_id'])
    ->value()['arbiter_policy_set_id'];

$foreignPolicy = $arbiter->evaluateProposal($fresh['proposal_id'], $rivalPolicySetId);
Assert::true($foreignPolicy->isRefusal(), 'a proposal cannot be evaluated under ANOTHER tenant\'s policy set');
Assert::same(
    Arbiter::ARBITER_POLICY_OUT_OF_SCOPE,
    $foreignPolicy->reasonCode(),
    'refused by name — otherwise one tenant could decide another tenant\'s proposals',
);

// --- TF7-01: the named policy must also be the EFFECTIVE binding ---------------------------------------------

// A tenant-wide binding of the same family exists alongside the exact-workspace one. Naming the
// broader set must be refused: scope compatibility proves a policy MAY see the proposal, not that
// it GOVERNS it.
$tenantWideId = Ids::new();
$db->insert('arbiter_policy_sets', [
    'arbiter_policy_set_id' => $tenantWideId, 'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => null,
    'policy_key' => 'P01_COMPETITION_RELEVANCE', 'policy_version' => 'v1',
    'display_name' => 'Tenant-wide P01', 'purpose_summary' => 'Broader binding',
    'applicable_proposal_types' => json_encode(['COMPANY_SIGNAL_BRIEF']),
    'applicable_requested_action_classes' => json_encode(['RELEASE_INTELLIGENCE']),
    'available_verdicts' => json_encode(['ALLOW_INFORMATIONAL_RELEASE', 'HOLD']),
    'default_fail_closed_verdict' => 'HOLD',
    'requires_decision_ledger_for_material_outcome' => 1,
    'does_not_approve_or_execute' => 1,
    'status' => 'ACTIVE', 'created_at' => $clock->now(), 'activated_at' => $clock->now(),
]);

$notEffective = $arbiter->evaluateProposal($fresh['proposal_id'], $tenantWideId);
Assert::true($notEffective->isRefusal(), 'naming a broader binding while an exact one exists is refused');
Assert::same(
    Arbiter::ARBITER_POLICY_NOT_EFFECTIVE_FOR_SCOPE,
    $notEffective->reasonCode(),
    'TF7-01 — a caller cannot pick its own authority by naming the looser policy',
);

// Two ACTIVE bindings at the SAME specificity are AMBIGUOUS, not "pick one".
//
// THE STATE IS NOW UNREACHABLE THROUGH THE APPLICATION, and this test still matters. Migration 160
// enforces one ACTIVE policy set per scope with a real unique index, so `seed()` can no longer
// produce this and neither can a race. The arbiter's ambiguity guard is therefore defence in depth
// — against a restored dump, a manual insert, a future migration that widens the predicate — and a
// control believed unreachable and never re-checked is one nobody would notice the loss of.
//
// So the row is planted the way something WITH database privileges would plant it: by removing the
// index first. The index is restored immediately afterwards and asserted present, so this suite
// cannot leave the schema weaker than production's for the suites that follow it.
$db->pdo()->exec('DROP INDEX ux_arbiter_policy_sets_scope_one_active ON arbiter_policy_sets');
$duplicateId = Ids::new();
$db->insert('arbiter_policy_sets', [
    'arbiter_policy_set_id' => $duplicateId, 'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'policy_key' => 'P01_COMPETITION_RELEVANCE', 'policy_version' => 'v2',
    'display_name' => 'Duplicate exact binding', 'purpose_summary' => 'Competing authority',
    'applicable_proposal_types' => json_encode(['COMPANY_SIGNAL_BRIEF']),
    'applicable_requested_action_classes' => json_encode(['RELEASE_INTELLIGENCE']),
    'available_verdicts' => json_encode(['ALLOW_INFORMATIONAL_RELEASE', 'HOLD']),
    'default_fail_closed_verdict' => 'HOLD',
    'requires_decision_ledger_for_material_outcome' => 1,
    'does_not_approve_or_execute' => 1,
    'status' => 'ACTIVE', 'created_at' => $clock->now(), 'activated_at' => $clock->now(),
]);

$ambiguous = $arbiter->evaluateProposal($fresh['proposal_id'], $policySetId);
Assert::true($ambiguous->isRefusal(), 'two competing ACTIVE bindings are refused');
Assert::same(
    Arbiter::ARBITER_POLICY_SCOPE_AMBIGUOUS,
    $ambiguous->reasonCode(),
    'a coin flip between two authorities is not an authority',
);
$db->run('DELETE FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?', [$duplicateId]);

// The schema goes back exactly as it was, and that is asserted rather than assumed — a suite that
// silently left the invariant off would make every suite after it prove less than it claims.
$db->pdo()->exec(
    'CREATE UNIQUE INDEX ux_arbiter_policy_sets_scope_one_active
     ON arbiter_policy_sets (tenant_id, workspace_id, policy_key, active_guard)'
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'arbiter_policy_sets'
           AND index_name = 'ux_arbiter_policy_sets_scope_one_active' AND non_unique = 0"
    ),
    'the ACTIVE-scope uniqueness index is restored before any later suite runs',
);
// And it BITES again: the row just deleted cannot come back while the original is still ACTIVE.
Assert::throws(
    static fn () => $db->insert('arbiter_policy_sets', [
        'arbiter_policy_set_id' => Ids::new(), 'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'policy_key' => 'P01_COMPETITION_RELEVANCE', 'policy_version' => 'v3',
        'display_name' => 'Second competing binding', 'purpose_summary' => 'Competing authority',
        'applicable_proposal_types' => json_encode(['COMPANY_SIGNAL_BRIEF']),
        'applicable_requested_action_classes' => json_encode(['RELEASE_INTELLIGENCE']),
        'available_verdicts' => json_encode(['ALLOW_INFORMATIONAL_RELEASE', 'HOLD']),
        'default_fail_closed_verdict' => 'HOLD',
        'requires_decision_ledger_for_material_outcome' => 1,
        'does_not_approve_or_execute' => 1,
        'status' => 'ACTIVE', 'created_at' => $clock->now(), 'activated_at' => $clock->now(),
    ]),
    \PDOException::class,
    'and a second ACTIVE binding for the same scope is refused by the database, not by timing',
);

// --- deactivation is the stand-down lever -----------------------------------------------------------------------

$policies->deactivate($policySetId, 'OWNER_STAND_DOWN');
$standDown = $arbiter->evaluateProposal($fresh['proposal_id'], $policySetId);
Assert::true($standDown->isRefusal(), 'a deactivated policy set admits nothing');
Assert::same(
    Arbiter::ARBITER_POLICY_NOT_ACTIVE,
    $standDown->reasonCode(),
    'deactivating the policy is the stand-down lever',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?', [$policySetId]),
    'and the set stays queryable as history rather than being deleted',
);

// --- a verdict is not an approval ---------------------------------------------------------------------------------

$anyVerdictId = (string) $db->scalar('SELECT arbiter_verdict_id FROM arbiter_verdicts LIMIT 1');
Assert::same(
    1,
    (int) $db->scalar('SELECT not_human_approval_or_execution_authority FROM arbiter_verdicts WHERE arbiter_verdict_id = ?', [$anyVerdictId]),
    'a verdict is a DECISION, never an approval or an execution authority',
);

// --- every check is recorded, whatever it decided --------------------------------------------------------------------

Assert::true(
    (int) $db->scalar('SELECT COUNT(*) FROM arbiter_check_records') > 20,
    'every evaluated check is recorded, passes included — the transcript shows what was considered',
);
// --- a rule family with NO context stands aside rather than passing ------------------------------------

// A COMPANY_SIGNAL_BRIEF that passes both seeds but binds NO company signal: the P01_RELEVANCE
// rules have nothing to judge. They must record NOT_APPLICABLE — distinguishable in the transcript
// from a PASS, because "we had nothing to check" and "we checked and it was fine" are different
// claims about the same verdict.
$rebuiltPolicy = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $rival['tenant_id'], $rival['workspace_id']);
$rivalPolicyId = $rebuiltPolicy->value()['arbiter_policy_set_id'];

$rivalProducer = $m03->registerValkyrie(
    $rival['tenant_id'], $rival['workspace_id'], 'Rival Monitor', 'COMPETITION_MONITOR_VALKYRIE',
    'x', $rival['operator_id'], 'ACTIVE'
);
$rivalCritic = $m03->registerValkyrie(
    $rival['tenant_id'], $rival['workspace_id'], 'Rival Critic', 'REVIEW_CRITIC_VALKYRIE',
    'x', $rival['operator_id'], 'ACTIVE'
);
$rivalContract = $m03->issueContract(
    $rivalProducer, $rival['tenant_id'], $rival['workspace_id'], 'p01.monitor',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];
$rivalMission = $m04->createMission(
    $rival['tenant_id'], $rival['workspace_id'], 'COMPETITION_MONITORING',
    'Monitor', 'x', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $rival['operator_id']
);

$contextlessId = $m04->createDraft([
    'tenant_id' => $rival['tenant_id'],
    'workspace_id' => $rival['workspace_id'],
    'mission_id' => $rivalMission,
    'producing_valkyrie_id' => $rivalProducer,
    'capability_contract_id' => $rivalContract,
    'proposal_type' => 'COMPANY_SIGNAL_BRIEF',
    'requested_action_class' => 'RELEASE_INTELLIGENCE',
    'title' => 'Brief with no bound signal',
    'safe_purpose_summary' => 'x',
    'proposed_content_or_action_summary' => 'x',
])->value()['proposal_id'];

$rivalSourceRef = $m05->registerSource(
    $rival['tenant_id'], $rival['workspace_id'], 'WEB_RESOURCE', 'https://x.example/',
    'OBSERVE_AND_DERIVE', 'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
);
$rivalEvidence = $m05->capture([
    'tenant_id' => $rival['tenant_id'],
    'workspace_id' => $rival['workspace_id'],
    'mission_id' => $rivalMission,
    'source_ref_id' => $rivalSourceRef,
    'evidence_type' => 'SOURCE_CAPTURE',
    'captured_content_reference_safe' => 'evidence://x',
    'content_hash' => Digest::contentDigest('x'),
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
])->value()['evidence_id'];
$rivalBundle = $m05->createBundleDraft(
    $rival['tenant_id'], $rival['workspace_id'], $rivalMission, $contextlessId, 'basis'
);
$m05->addBundleItem($rivalBundle, $rivalEvidence);
$m05->submitBundle($rivalBundle);
$m04->submit($contextlessId);

$rivalSession = $m07->openSession(
    $rival['tenant_id'], $rival['workspace_id'], $rivalMission, $contextlessId,
    'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $rival['operator_id']
)->value()['review_session_id'];
$rivalAssignment = $m07->assignParticipant($rivalSession, $rivalCritic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
    ->value()['participant_assignment_id'];
$m07->recordAssessment($rivalSession, $rivalAssignment, $rivalCritic, 'SUPPORT_PROCEED_TO_ARBITER', 'ok');
$m07->freezeResult($rivalSession, 'PROCEED_TO_ARBITER', 'ok');

$contextless = $arbiter->evaluateProposal($contextlessId, $rivalPolicyId);
Assert::true($contextless->isOk(), 'a proposal with no P01 signal context still reaches a verdict');

$naCount = (int) $db->scalar(
    "SELECT COUNT(*) FROM arbiter_check_records
     WHERE arbiter_evaluation_run_id = ? AND decision = 'NOT_APPLICABLE' AND rule_category = 'P01_RELEVANCE'",
    [$contextless->value()['arbiter_evaluation_run_id']]
);
Assert::same(
    4,
    $naCount,
    'all four P01_RELEVANCE rules record NOT_APPLICABLE when there is no signal to judge — '
    . 'a family with no context stands aside rather than passing by omission',
);
