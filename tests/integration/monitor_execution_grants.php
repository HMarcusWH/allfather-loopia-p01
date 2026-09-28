<?php
declare(strict_types=1);

/**
 * Prompt approvals and execution grants against real MariaDB: authority from reviewed decisions
 * only, immutable definitions, append-only lifecycle, and a budget that concurrent work cannot
 * overdraw.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Core\{Ids,Outcome};
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];

// ---------------------------------------------------------------- approvals need a decision naming them
$identity = X::promptIdentities('MODEL_EVALUATION', ['P01_SIGNAL_CLASSIFIER']);
$none = X::decision($fx, ['MODEL_EVALUATION'], []);
Assert::same('OWNER_DECISION_DOES_NOT_AUTHORIZE_PROMPT', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $none['ref'], $none['sha256'])->reasonCode(), 'a decision that does not list the prompt approves nothing');
Assert::same('OWNER_DECISION_NOT_FOUND', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', 'docs/decisions/absent.json', str_repeat('a', 64))->reasonCode(), 'no document, no approval');
Assert::same('OWNER_DECISION_REFERENCE_INVALID', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', '../../etc/passwd', str_repeat('a', 64))->reasonCode(), 'a reference cannot leave the decisions directory');
$evalDecision = X::decision($fx, ['MODEL_EVALUATION'], $identity);
$approved = X::value($fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $evalDecision['ref'], $evalDecision['sha256']));
Assert::same(false, $approved['grants_execution'], 'an approval grants no execution by itself');
Assert::same('PROMPT_APPROVAL_REQUIRES_EVALUATION', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MANUAL_MONITOR', $evalDecision['ref'], $evalDecision['sha256'])->reasonCode(), 'a product approval must cite its evaluation');
Assert::same('PROMPT_APPROVAL_PURPOSE_INVALID', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $evalDecision['ref'], $evalDecision['sha256'], 'evaluation:x')->reasonCode(), 'an evaluation approval cites no evaluation');
Assert::same($approved['prompt_approval_id'], X::value($fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $evalDecision['ref'], $evalDecision['sha256']))['prompt_approval_id'], 'the same approval is idempotent');
$outsider = Seed::tenantWorkspace($db, $clock, 'approval-outsider');
Assert::same('PROMPT_APPROVAL_DENIED', $fx['approvals']->approve($outsider, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $evalDecision['ref'], $evalDecision['sha256'])->reasonCode(), 'an operator without administration cannot approve');
X::value($fx['approvals']->withdraw($scope, $approved['prompt_approval_id'], 'TEST_WITHDRAWN'));
Assert::same('PROMPT_APPROVAL_WITHDRAWN', $fx['approvals']->current($scope['tenant_id'], $scope['workspace_id'], $approved['prompt_approval_id'])->reasonCode(), 'withdrawal takes effect');
Assert::same('PROMPT_APPROVAL_WITHDRAWN', $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $evalDecision['ref'], $evalDecision['sha256'])->reasonCode(), 'approving the exact withdrawn authorization again does not resurrect it');
$successorDecision = X::decision($fx, ['MODEL_EVALUATION'], $identity, [], 'eval-successor.json');
$successorApproval = X::value($fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MODEL_EVALUATION', $successorDecision['ref'], $successorDecision['sha256']));
Assert::true($successorApproval['prompt_approval_id'] !== $approved['prompt_approval_id'], 'a new reviewed owner decision may authorize the same immutable prompt as a new approval record');
Assert::true($fx['approvals']->current($scope['tenant_id'], $scope['workspace_id'], $successorApproval['prompt_approval_id'])->isOk(), 'the successor authorization is current while the withdrawn record remains historical');

// Product approvals are authorization instances too: a later accepted evaluation of unchanged
// prompt bytes is a new immutable approval, never an edit of the earlier evaluation reference.
$manualDecision = X::decision($fx, ['MANUAL_MONITOR'], X::promptIdentities('MANUAL_MONITOR', ['P01_SIGNAL_CLASSIFIER']), [], 'manual-reeval.json');
$manualA = X::value($fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MANUAL_MONITOR', $manualDecision['ref'], $manualDecision['sha256'], 'evaluation:00000000-0000-0000-0000-000000000001'));
$manualB = X::value($fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MANUAL_MONITOR', $manualDecision['ref'], $manualDecision['sha256'], 'evaluation:00000000-0000-0000-0000-000000000002'));
Assert::true($manualA['prompt_approval_id'] !== $manualB['prompt_approval_id'], 'a new evaluation reference produces a new immutable product approval');

// ---------------------------------------------------------------- grant definitions are narrow and exact
$approvals = X::approveAll($fx, 'MANUAL_MONITOR');
// Product-use approval currentness includes the accepted evaluation whenever a deployed resolver is
// present; an evaluation that later ceases to qualify withdraws the prompt on the next selection.
$staleEvaluation = new PromptApprovalStore($db, $clock, $fx['decisions'], static fn (array $facts,string $reference): Outcome => Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED'));
Assert::same('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED',$staleEvaluation->current($scope['tenant_id'],$scope['workspace_id'],$approvals[0])->reasonCode(),'manual prompt currentness rechecks its evaluation evidence');
$definition = X::manualDefinition($db, $clock, $fx, $approvals);
$plan = X::value($fx['grants']->plan($scope, $definition));
Assert::same(false, $plan['grants_execution'], 'planning grants nothing');
foreach ([
    ['limits', ['max_model_attempts' => 9, 'max_source_captures' => 2], 'EXECUTION_GRANT_LIMIT_EXCEEDS_CEILING'],
    ['model_policy_digest', str_repeat('0', 64), 'EXECUTION_GRANT_MODEL_POLICY_NOT_CURRENT'],
    ['definition_digest', str_repeat('1', 64), 'EXECUTION_GRANT_CONFIGURATION_MISMATCH'],
    ['source_scope', ['monitored_source_id' => $fx['monitored_source_id'], 'capture_url' => 'https://sources.example/elsewhere'], 'EXECUTION_GRANT_CONFIGURATION_MISMATCH'],
    ['target_org_role', 'MARKETING', 'EXECUTION_GRANT_CONFIGURATION_MISMATCH'],
    ['credential_digest', str_repeat('2', 64), 'EXECUTION_GRANT_CREDENTIAL_NOT_CURRENT'],
    ['prompt_approval_ids', array_slice($approvals, 0, 3), 'EXECUTION_GRANT_PROMPTS_INCOMPLETE'],
    ['prompt_approval_ids', array_reverse($approvals), 'EXECUTION_GRANT_PROMPTS_INVALID'],
    ['valid_until', '2027-01-01T00:00:00.000Z', 'EXECUTION_GRANT_WINDOW_INVALID'],
    ['principal_operator_id', $outsider['operator_id'], 'EXECUTION_GRANT_PRINCIPAL_NOT_ACTIVE'],
    ['evaluation_manifest_sha256', str_repeat('3', 64), 'EXECUTION_GRANT_DEFINITION_INVALID'],
] as [$key, $changed, $reason]) {
    Assert::same($reason, $fx['grants']->plan($scope, [$key => $changed] + $definition)->reasonCode(), "grant refuses changed {$key}");
}
Assert::same('EXECUTION_GRANT_DEFINITION_INVALID', $fx['grants']->plan($scope, $definition + ['browser_enable' => true])->reasonCode(), 'no field beyond the closed definition');
Assert::same('EXECUTION_GRANT_ADMINISTRATION_DENIED', $fx['grants']->propose($outsider, $definition)->reasonCode(), 'an outsider cannot propose');
$proposed = X::value($fx['grants']->propose($scope, $definition));
Assert::same($plan['grant_digest'], $proposed['grant_digest'], 'the planned digest is the proposed digest');
Assert::same($proposed['execution_grant_id'], X::value($fx['grants']->propose($scope, $definition))['execution_grant_id'], 'proposing the same definition converges');

// An evaluation grant carries no mission, source or target, and cannot be used for a mission.
$evalApprovals = X::approveAll($fx, 'MODEL_EVALUATION', null, ['BATTLECARD_GENERATOR']);
$evaluation = array_replace($definition, ['purpose' => 'MODEL_EVALUATION', 'mission_id' => null, 'definition_digest' => null, 'evaluation_manifest_sha256' => str_repeat('4', 64), 'source_scope' => null, 'target_org_role' => null, 'prompt_approval_ids' => $evalApprovals, 'limits' => ['max_model_attempts' => 20, 'max_source_captures' => 0]]);
Assert::true($fx['grants']->plan($scope, $evaluation)->isOk(), 'a bounded evaluation grant is expressible');
Assert::same('EXECUTION_GRANT_LIMIT_EXCEEDS_CEILING', $fx['grants']->plan($scope, ['limits' => ['max_model_attempts' => 20, 'max_source_captures' => 1]] + $evaluation)->reasonCode(), 'an evaluation grant cannot fetch sources');
Assert::same('EXECUTION_GRANT_PROMPT_PURPOSE_MISMATCH', $fx['grants']->plan($scope, ['prompt_approval_ids' => $approvals] + $evaluation)->reasonCode(), 'product approvals do not serve an evaluation grant');

// ---------------------------------------------------------------- lifecycle is append-only
$decision = X::decision($fx, ['MANUAL_MONITOR'], [], [$proposed['grant_digest']]);
$id = $proposed['execution_grant_id'];
X::value($fx['grants']->admit($scope, $id, $decision['ref'], $decision['sha256']));
Assert::same('ADMITTED', $fx['grants']->status($id), 'admitted');
Assert::same('EXECUTION_GRANT_TRANSITION_INVALID', $fx['grants']->admit($scope, $id, $decision['ref'], $decision['sha256'])->reasonCode(), 'admitting twice is not a transition');
Assert::same('EXECUTION_GRANT_AMBIGUOUS', (function () use ($fx, $scope, $db, $clock, $approvals, $definition) {
    $second = X::admitted($fx, ['limits' => ['max_model_attempts' => 3, 'max_source_captures' => 2]] + $definition);
    $r = $fx['grants']->currentFor($scope['tenant_id'], $scope['workspace_id'], 'MANUAL_MONITOR', $fx['mission_id'], $fx['definition_digest'])->reasonCode();
    X::value($fx['grants']->revoke($scope, $second['execution_grant_id'], 'TEST_CLEANUP'));
    return $r;
})(), 'two admitted grants for one configuration are refused as ambiguous, never picked between');
Assert::same($id, X::value($fx['grants']->currentFor($scope['tenant_id'], $scope['workspace_id'], 'MANUAL_MONITOR', $fx['mission_id'], $fx['definition_digest']))['execution_grant_id'], 'exactly one admitted grant is current');
Assert::same('EXECUTION_GRANT_STAND_DOWN_DENIED', $fx['grants']->suspend($outsider, $id, 'TEST')->reasonCode(), 'an outsider cannot stand a grant down');
Assert::same('EXECUTION_GRANT_REASON_REQUIRED', $fx['grants']->suspend($scope, $id, 'not a code')->reasonCode(), 'stand-down names its reason');

// A row to protect: every check records its decision.
Assert::true($fx['admission']->check('SOURCE_CAPTURE', ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $scope['operator_id'], 'execution_grant_id' => $id, 'mission_id' => $fx['mission_id'], 'definition_digest' => $fx['definition_digest'], 'monitored_source_id' => $fx['monitored_source_id'], 'capture_url' => $fx['capture_url']])->isOk(), 'the admitted grant admits its exact capture');
Assert::same(1, (int) $db->scalar("SELECT COUNT(*) FROM monitor_admission_decisions WHERE execution_grant_id=? AND outcome='ALLOW'", [$id]), 'and the allow is on record');
foreach ([
    ['UPDATE monitor_execution_grants SET valid_until=? WHERE execution_grant_id=?', ['2030-01-01T00:00:00.000Z', $id]],
    ['DELETE FROM monitor_execution_grants WHERE execution_grant_id=?', [$id]],
    ["UPDATE monitor_execution_grant_events SET event_kind='REVOKED' WHERE execution_grant_id=?", [$id]],
    ['DELETE FROM monitor_execution_grant_events WHERE execution_grant_id=?', [$id]],
    ["UPDATE monitor_prompt_approvals SET purpose='MODEL_EVALUATION'", []],
    ['DELETE FROM monitor_prompt_approval_events', []],
    ['DELETE FROM monitor_admission_decisions', []],
] as [$sql, $params]) {
    try { $db->run($sql, $params); Assert::fail('immutable control table accepted: ' . $sql); } catch (\PDOException) { Assert::pass(); }
}
foreach ([
    ['monitor_execution_grants', ['execution_grant_id' => Ids::new(), 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'purpose' => 'MANUAL_MONITOR', 'mission_id' => null, 'definition_digest' => null, 'evaluation_manifest_sha256' => null, 'principal_operator_id' => $scope['operator_id'], 'definition_json' => '{}', 'grant_digest' => str_repeat('5', 64), 'valid_from' => '2026-01-01T00:00:00.000Z', 'valid_until' => '2026-01-02T00:00:00.000Z', 'proposed_by_operator_id' => $scope['operator_id'], 'created_at' => '2026-01-01T00:00:00.000Z'], 'a manual grant with no mission'],
    ['monitor_execution_grant_events', ['execution_grant_event_id' => Ids::new(), 'execution_grant_id' => $id, 'sequence_no' => 99, 'event_kind' => 'ADMITTED', 'owner_decision_ref' => null, 'owner_decision_sha256' => null, 'reason_code' => 'X', 'recorded_by_operator_id' => $scope['operator_id'], 'recorded_at' => '2026-01-01T00:00:00.000Z'], 'an admission citing no decision'],
    ['monitor_prompt_approvals', ['prompt_approval_id' => Ids::new(), 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'purpose' => 'MANUAL_MONITOR', 'role' => 'X', 'pack_version' => 'x', 'prompt_version_id' => 'x', 'binding_id' => 'x', 'prompt_sha256' => str_repeat('6', 64), 'schema_sha256' => str_repeat('6', 64), 'model_policy_id' => 'x', 'model_policy_digest' => str_repeat('6', 64), 'evaluation_reference' => null, 'owner_decision_ref' => 'x', 'owner_decision_sha256' => str_repeat('6', 64), 'approval_digest' => str_repeat('6', 64), 'approved_by_operator_id' => $scope['operator_id'], 'created_at' => '2026-01-01T00:00:00.000Z'], 'a product approval citing no evaluation'],
] as [$table, $row, $what]) {
    try { $db->insert($table, $row); Assert::fail("the database accepted {$what}"); } catch (\PDOException) { Assert::pass(); }
}

// ---------------------------------------------------------------- the budget holds under concurrency
// Two real connections: the first reserves the last unit and holds its transaction open; the
// second must wait on the grant row's lock rather than count the same free unit.
$small = X::admitted($fx, ['limits' => ['max_model_attempts' => 1, 'max_source_captures' => 1]] + X::manualDefinition($db, $clock, $fx, $approvals, ['valid_until' => '2026-01-05T00:00:00.000Z']));
X::value($fx['grants']->revoke($scope, $id, 'TEST_MAKE_ROOM'));
$second = Harness::secondConnection();
if ($second === null) {
    Assert::skip('the concurrency proof needs a second connection');
} else {
    $second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $otherAdmission = new \Allfather\Loopia\Workflow\ExecutionAdmission($second, $clock, $fx['grants'], $fx['approvals'], $fx['decisions'], X::FINGERPRINT);
    $db->pdo()->beginTransaction();
    Assert::true($fx['admission']->reserve($db, $small['execution_grant_id'], 'MODEL_ATTEMPT', 'first', null)->isOk(), 'the first reservation takes the only unit');
    $blocked = false;
    try {
        $second->pdo()->beginTransaction();
        $r = $otherAdmission->reserve($second, $small['execution_grant_id'], 'MODEL_ATTEMPT', 'second', null);
        $second->pdo()->rollBack();
        Assert::false($r->isOk(), 'the concurrent reservation cannot also succeed');
    } catch (\PDOException) {
        $blocked = true;
        if ($second->pdo()->inTransaction()) { $second->pdo()->rollBack(); }
    }
    Assert::true($blocked, 'the concurrent reservation waits on the grant lock instead of reading a stale count');
    $db->pdo()->commit();
    $second->pdo()->beginTransaction();
    Assert::same('EXECUTION_GRANT_BUDGET_EXHAUSTED', $otherAdmission->reserve($second, $small['execution_grant_id'], 'MODEL_ATTEMPT', 'second', null)->reasonCode(), 'once committed, the unit is visibly spent');
    $second->pdo()->rollBack();
    Assert::same(1, $fx['grants']->consumed($small['execution_grant_id'], 'MODEL_ATTEMPT'), 'exactly the limit was spent');
}
Assert::same(ModelPolicy::digest(), ModelPolicy::digest(), 'the model policy digest is stable');
