<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Provider\ModelArtifactStore;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness};
use Allfather\Loopia\Workflow\{ContextEnvelope,MissionConfiguration,RunArtifacts,RunJournal,RunReconciliation};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];
$approvals = X::approveAll($fx, 'MANUAL_MONITOR');
X::admitted($fx, X::manualDefinition($db, $clock, $fx, $approvals));
$service = $fx['service'];

$baseline = X::value($service->startConfigured($scope, $fx['mission_id'], 'p05-baseline'));
$baseline = X::value($service->advance($scope, $baseline['monitor_run_id']));
Assert::same('BASELINE', $baseline['stage'], 'first capture remains a baseline');
$clock->advanceDays(1);

$run = X::value($service->startConfigured($scope, $fx['mission_id'], 'p05-changed'));
$id = $run['monitor_run_id'];
Assert::same('CAPTURED', X::value($service->advance($scope, $id))['stage'], 'second capture recorded');
Assert::same('DIFFED', X::value($service->advance($scope, $id))['stage'], 'real diff reaches first model stage');
$detail = X::value($service->detail($scope, $id));
$requestDigest = $detail['preview_request']['request_digest'];

// Crash after the provider/result artifacts are durable but before the run-journal transition.
$db->run("CREATE TRIGGER test_p05_journal_failure BEFORE INSERT ON monitor_run_events FOR EACH ROW BEGIN IF NEW.event_kind = 'MODEL_CANDIDATE_RECORDED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected p05 journal crash'; END IF; END");
$crashed = $service->advance($scope, $id, $requestDigest);
Assert::same('MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED', $crashed->reasonCode(), 'post-result journal fault requests reconciliation rather than terminalizing history');
$db->run('DROP TRIGGER test_p05_journal_failure');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($scope, $id)['stage'], 'crash left the pre-call journal stage intact');
Assert::same(1, $fx['model']->calls, 'the provider transport ran exactly once');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_results WHERE outcome=?', ['CANDIDATE']), 'candidate provider result is durable');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_candidate_artifacts'), 'exact candidate bytes are durably bound to the attempt');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM monitor_model_attempt_bindings WHERE monitor_run_id=?', [$id]), 'the inspected request artifact is durably bound before the journal transition');

// The normal path now sees the governed replay and still does not call the provider.
$replay = $service->advance($scope, $id, $requestDigest);
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $replay->reasonCode(), 'idempotent replay waits for explicit reconciliation');
Assert::same(1, $fx['model']->calls, 'replay never makes a second provider call');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($scope, $id)['stage'], 'replay does not rewrite the run into BLOCKED');

$reconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($fx['evidence_store']),
    new ModelArtifactStore($db, $clock, $fx['evidence_store']),
    new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $fx['evidence_store']),
    $fx['admission'],
);
$inspection = X::value($reconciliation->inspect($scope, $id));
Assert::same('RECOVERABLE_CANDIDATE', $inspection['status'], 'exact provider/result/artifact facts establish a recoverable candidate');
Assert::same(false, $inspection['will_call_provider'], 'inspection is read-only and provider-free');
Assert::false(isset($inspection['candidate']), 'operator inspection does not print private candidate bytes');

$adopted = X::value($reconciliation->adopt($scope, $id, $inspection['run_revision']));
Assert::same('ADOPTED_CANDIDATE', $adopted['disposition'], 'operator explicitly adopts the durable candidate');
Assert::same('ANALYZED', $adopted['run']['stage'], 'adoption uses the ordinary DIFFED -> ANALYZED transition');
Assert::same(1, $fx['model']->calls, 'adoption never repeats the model call');
Assert::same(1, (int) $db->scalar("SELECT COUNT(*) FROM monitor_run_reconciliations WHERE disposition='ADOPTED_CANDIDATE'"), 'adoption is append-only evidence');
Assert::same('MONITOR_RUN_REVISION_CHANGED_RELOAD_REQUIRED', $reconciliation->adopt($scope, $id, $inspection['run_revision'])->reasonCode(), 'stale adoption cannot consume a result twice');

// Next model call: inject loss of provider_model_results after M01/M06 have finalized. The exact
// candidate artifact exists, but the final provider-result row does not, so the outcome is unknown.
$draftDetail = X::value($service->detail($scope, $id));
$draftDigest = $draftDetail['preview_request']['request_digest'];
$db->run("CREATE TRIGGER test_p05_result_failure BEFORE INSERT ON provider_model_results FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected p05 result finalization crash'; END");
$missingResult = $service->advance($scope, $id, $draftDigest);
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $missingResult->reasonCode(), 'result-finalization loss is not converted into a retry or false refusal');
$db->run('DROP TRIGGER test_p05_result_failure');
Assert::same('ANALYZED', (new RunJournal($db, $clock))->find($scope, $id)['stage'], 'unknown result leaves the current journal stage intact');
Assert::same(2, $fx['model']->calls, 'the second provider attempt happened once');
Assert::same(2, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_candidate_artifacts'), 'candidate bytes can outlive a failed final result write');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_results'), 'the injected result write really is absent');

Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $service->advance($scope, $id, $draftDigest)->reasonCode(), 'replaying an unknown attempt still refuses automatic execution');
Assert::same(2, $fx['model']->calls, 'unknown attempt is never automatically retried');

$unknown = X::value($reconciliation->inspect($scope, $id));
Assert::same('UNRESOLVED', $unknown['status'], 'missing final provider result remains honestly unresolved');
Assert::same('MONITOR_RECONCILIATION_RESULT_NOT_RECORDED', $unknown['reason_code'], 'inspection names the missing durable fact');
$marked = X::value($reconciliation->markUnresolved($scope, $id, $unknown['run_revision'], 'TEST_RESULT_FINALIZATION_FAILED'));
Assert::same('UNRESOLVED', $marked['disposition'], 'operator can append an unresolved acknowledgement');
Assert::same('ANALYZED', (new RunJournal($db, $clock))->find($scope, $id)['stage'], 'UNRESOLVED acknowledgement does not manufacture a continuation');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'), 'reconciliation never releases product output');
