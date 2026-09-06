<?php

declare(strict_types=1);

/**
 * M07 HMWH Council, proved against real MariaDB.
 *
 * The rule this suite exists for: a producing Valkyrie cannot be an INDEPENDENT reviewer of its
 * own proposal. Without it, "independent review" is a producer agreeing with itself, and every
 * downstream gate that trusts the review is trusting nothing.
 *
 * Also proved: the council is ADVICE, not authority — `not_approval_or_execution_authority` is
 * storage-pinned to 1 — and dissent is preserved rather than averaged into a consensus that never
 * existed.
 */

use Allfather\Loopia\Substrate\M03\Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M07\Council;
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
$m07 = new Council($db, $clock);

// --- the action-class ceiling, enforced at contract issue -------------------------------------------

$producer = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Competition Monitor',
    'COMPETITION_MONITOR_VALKYRIE', 'Monitors approved competitor sources.',
    $scope['operator_id'], 'ACTIVE'
);

$aboveCeiling = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.monitor',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'EXECUTE_CONSEQUENTIAL_ACTION']
);
Assert::true($aboveCeiling->isRefusal(), 'a contract cannot grant an action class above the projection ceiling');
Assert::same('CONTRACT_ACTION_CLASS_ABOVE_CEILING', $aboveCeiling->reasonCode(), 'refused by name');
Assert::same('S5', $aboveCeiling->severity, 'at release-blocking severity');

$contract = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.monitor',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'],
    ['CRM_SEND', 'PUBLIC_PUBLISH', 'EXTERNAL_DISPATCH']
);
Assert::true($contract->isOk(), 'an in-ceiling contract issues');
$contractId = $contract->value()['capability_contract_id'];

Assert::same(
    0,
    (int) $db->scalar('SELECT human_approval_or_execution_authority FROM capability_contracts WHERE capability_contract_id = ?', [$contractId]),
    'human_approval_or_execution_authority is CHECK-pinned to 0 — a contract never carries approval authority',
);
try {
    $db->run(
        'UPDATE capability_contracts SET human_approval_or_execution_authority = 1 WHERE capability_contract_id = ?',
        [$contractId]
    );
    Assert::fail('the approval-authority pin should reject being flipped to 1');
} catch (\PDOException) {
    Assert::pass();
}

// --- a proposal to review ----------------------------------------------------------------------------

$missionId = $m04->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
    'Monitor competitor pricing', 'Detect and brief material pricing changes.',
    ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']
);

$draft = $m04->createDraft([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'mission_id' => $missionId,
    'producing_valkyrie_id' => $producer,
    'capability_contract_id' => $contractId,
    'proposal_type' => 'COMPANY_SIGNAL_BRIEF',
    'requested_action_class' => 'RELEASE_INTELLIGENCE',
    'title' => 'Competitor A raised list pricing',
    'safe_purpose_summary' => 'Brief the customer on an observed pricing change.',
    'proposed_content_or_action_summary' => 'List price moved 449 -> 499 SEK on the public pricing page.',
    'uncertainty_summary' => 'Whether the change applies to existing customers is NOT observed.',
]);
Assert::true($draft->isOk(), 'a proposal draft is created');
$proposalId = $draft->value()['proposal_id'];

// A proposal asking for an external action is refused BEFORE any review can happen.
$externalDraft = $m04->createDraft([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'mission_id' => $missionId,
    'producing_valkyrie_id' => $producer,
    'capability_contract_id' => $contractId,
    'proposal_type' => 'CRM_OUTREACH_EXECUTION',
    'requested_action_class' => 'EXECUTE_CONSEQUENTIAL_ACTION',
    'title' => 'Send the email',
    'safe_purpose_summary' => 'x',
    'proposed_content_or_action_summary' => 'x',
]);
Assert::true($externalDraft->isRefusal(), 'a proposal REQUESTING an external action is refused at creation');
Assert::same(
    'PROPOSAL_ACTION_CLASS_ABOVE_CEILING',
    $externalDraft->reasonCode(),
    'so no downstream stage ever has to decide about one',
);

// --- review requires a SUBMITTED (immutable) proposal -------------------------------------------------

$tooEarly = $m07->openSession(
    $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId,
    'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
);
Assert::true($tooEarly->isRefusal(), 'a DRAFT proposal cannot be reviewed — it is still mutable');
Assert::same(Council::HMWH_PROPOSAL_NOT_SUBMITTED_OR_MUTABLE, $tooEarly->reasonCode(), 'refused by name');

$submitted = $m04->submit($proposalId);
Assert::true($submitted->isOk(), 'the proposal submits');
$submissionDigest = $submitted->value()['submission_digest'];
Assert::same(64, strlen($submissionDigest), 'and freezes with a submission digest');

$session = $m07->openSession(
    $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId,
    'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
);
Assert::true($session->isOk(), 'a SUBMITTED proposal opens a review session');
$sessionId = $session->value()['review_session_id'];

Assert::same(
    $submissionDigest,
    (string) $db->scalar('SELECT proposal_submission_digest FROM hmwh_review_sessions WHERE review_session_id = ?', [$sessionId]),
    'the session BINDS the exact submission digest it is reviewing',
);

// --- THE producer-self-review rule ---------------------------------------------------------------------

$selfIndependent = $m07->assignParticipant($sessionId, $producer, 'GENERAL_REVIEWER', 'INDEPENDENT_DECLARED_REVIEWER');
Assert::true($selfIndependent->isRefusal(), 'the PRODUCER cannot be an independent reviewer of its own proposal');
Assert::same(
    Council::HMWH_SELF_REVIEW_MISREPRESENTED_AS_INDEPENDENT,
    $selfIndependent->reasonCode(),
    'refused by name',
);
Assert::same(
    'SELF_REVIEW_ONLY',
    $selfIndependent->detail['conflict_or_separation_check'],
    'and the eligibility check records SELF_REVIEW_ONLY as the PROOF',
);

// The denial leaves a record, not an absence.
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM hmwh_eligibility_checks WHERE review_session_id = ? AND m03_rejection_reason_code = ?',
        [$sessionId, 'PRODUCER_SELF_REVIEW']
    ),
    'the refusal is recorded in the append-only eligibility ledger',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM hmwh_participant_assignments WHERE review_session_id = ?', [$sessionId]),
    'and NO participant assignment was created',
);

// Self-critique IS allowed — but only labelled non-independent, so nothing can mistake it.
$selfCritique = $m07->assignParticipant($sessionId, $producer, 'GENERAL_REVIEWER', 'NON_INDEPENDENT_SELF_CRITIQUE');
Assert::true($selfCritique->isOk(), 'self-critique is permitted when labelled non-independent');
Assert::same(
    'SELF_REVIEW_ONLY',
    $selfCritique->detail['conflict_or_separation_check'] ?? $selfCritique->value()['conflict_or_separation_check'],
    'and is still marked SELF_REVIEW_ONLY',
);

// --- an independent reviewer ------------------------------------------------------------------------------

$critic = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Review Critic',
    'REVIEW_CRITIC_VALKYRIE', 'Independent review.', $scope['operator_id'], 'ACTIVE'
);
$independent = $m07->assignParticipant($sessionId, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER');
Assert::true($independent->isOk(), 'a different Valkyrie CAN be an independent reviewer');
Assert::same('CLEARED', $independent->value()['conflict_or_separation_check'], 'with no conflict recorded');
$criticAssignment = $independent->value()['participant_assignment_id'];

// A REVOKED Valkyrie is ineligible.
$revoked = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Revoked Critic',
    'REVIEW_CRITIC_VALKYRIE', 'x', $scope['operator_id'], 'ACTIVE'
);
$m03->changeStatus($revoked, 'REVOKED', 'STAND_DOWN');
$revokedAssign = $m07->assignParticipant($sessionId, $revoked, 'RISK_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER');
Assert::true($revokedAssign->isRefusal(), 'a REVOKED Valkyrie cannot review');
Assert::same(Council::HMWH_PARTICIPANT_NOT_ELIGIBLE, $revokedAssign->reasonCode(), 'refused by name');

// Revocation is TERMINAL — a stand-down that could be undone would not be a stand-down.
$reactivate = $m03->changeStatus($revoked, 'ACTIVE', 'OOPS');
Assert::true($reactivate->isRefusal(), 'a REVOKED Valkyrie cannot be re-activated');
Assert::same('VALKYRIE_ILLEGAL_TRANSITION', $reactivate->reasonCode(), 'refused by name');

// --- assessments, dissent, and the frozen result ---------------------------------------------------------------

$noAssessments = $m07->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'nothing behind this');
Assert::true($noAssessments->isRefusal(), 'a result cannot be frozen with NO submitted assessment');
Assert::same(
    Council::HMWH_NO_SUBMITTED_ASSESSMENTS,
    $noAssessments->reasonCode(),
    'a council result with no deliberation behind it is an opinion, not a review',
);

$assessment = $m07->recordAssessment(
    $sessionId, $criticAssignment, $critic, 'SUPPORT_PROCEED_TO_ARBITER',
    'The diff is material and the source card permits derived output.',
    ['evidence-1'], ['Applicability to existing customers is unknown.'], 'MEDIUM'
);
Assert::true($assessment->isOk(), 'an assessment is recorded');
$assessmentId = $assessment->value()['assessment_id'];

$dissentId = $m07->recordDissent(
    $sessionId, [$assessmentId], 'MATERIAL_EVIDENCE_DISAGREEMENT',
    'One reviewer holds that a single snapshot pair is thin for a pricing claim.'
);
$vetoId = $m07->recordRiskVeto(
    $sessionId, $assessmentId, 'SCOPE_VIOLATION_CONCERN',
    'The brief must not imply the change applies to existing contracts.',
    'ARBITER_HOLD'
);

$frozen = $m07->freezeResult(
    $sessionId, 'PROCEED_WITH_CONDITIONS', 'Proceed, with the applicability limitation stated.',
    'Medium confidence.', ['Applicability to existing customers not observed.']
);
Assert::true($frozen->isOk(), 'the council result freezes');
$resultId = $frozen->value()['hmwh_result_id'];

// The digest commits to the exact deliberation.
Assert::equalsList([$assessmentId], $frozen->value()['submitted_assessment_ids'], 'the result cites its assessments');
Assert::equalsList([$dissentId], $frozen->value()['dissent_record_ids'], 'and its dissents');
Assert::equalsList([$vetoId], $frozen->value()['risk_veto_record_ids'], 'and its risk vetoes');

// --- M07 is ADVICE, never authority ----------------------------------------------------------------------------

Assert::same(
    1,
    (int) $db->scalar('SELECT not_approval_or_execution_authority FROM hmwh_results WHERE hmwh_result_id = ?', [$resultId]),
    'not_approval_or_execution_authority is storage-pinned to 1 — the council never approves or executes',
);
try {
    $db->run(
        'UPDATE hmwh_results SET not_approval_or_execution_authority = 0 WHERE hmwh_result_id = ?',
        [$resultId]
    );
    Assert::fail('the advice-only pin should reject being flipped to 0');
} catch (\PDOException) {
    Assert::pass();
}

// Dissent and risk veto are preserved for routing, never averaged away.
Assert::same(
    1,
    (int) $db->scalar('SELECT preserved_for_routing FROM hmwh_dissent_records WHERE dissent_record_id = ?', [$dissentId]),
    'dissent is CHECK-pinned as preserved for routing',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT mandatory_visibility_to_arbiter FROM hmwh_risk_veto_records WHERE risk_veto_record_id = ?', [$vetoId]),
    'a risk veto is CHECK-pinned as always visible to the Arbiter',
);

// --- a session freezes exactly once -----------------------------------------------------------------------------

$secondFreeze = $m07->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'competing result');
Assert::true($secondFreeze->isRefusal(), 'a session cannot produce a SECOND frozen result');
// Canonical checks the SESSION STATUS before the already-frozen guard, and freezing moves the
// session to AGGREGATED — so the status guard is what a second freeze hits, and the reason code is
// HMWH_SESSION_NOT_READY_FOR_AGGREGATION rather than HMWH_RESULT_ALREADY_FROZEN. Both refuse; the
// port asserts the code canonical actually returns, because the reason code is the conformance
// surface and "also a refusal" is not the same answer.
Assert::same(
    Council::HMWH_SESSION_NOT_READY_FOR_AGGREGATION,
    $secondFreeze->reasonCode(),
    'refused by the code canonical returns — the status guard runs before the already-frozen guard',
);

// HMWH_RESULT_ALREADY_FROZEN is the guard for the narrower case: a result exists while the session
// is still in an aggregatable status, which is what a partially-applied freeze would leave behind.
$db->run('UPDATE hmwh_review_sessions SET status = ? WHERE review_session_id = ?', ['AWAITING_ASSESSMENTS', $sessionId]);
$alreadyFrozen = $m07->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'competing result');
Assert::true($alreadyFrozen->isRefusal(), 'and a second result is still refused once the status guard passes');
Assert::same(Council::HMWH_RESULT_ALREADY_FROZEN, $alreadyFrozen->reasonCode(), 'by its own reason code');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM hmwh_results WHERE review_session_id = ?', [$sessionId]),
    'exactly one frozen result exists for the session',
);
