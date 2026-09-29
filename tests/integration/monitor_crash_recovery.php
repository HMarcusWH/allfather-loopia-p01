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
// A different run/process holding the same source lock must not let us choose a stale shared
// baseline. The refusal leaves NEW intact and performs no fetch; releasing the lock makes progress
// possible without changing the run identity.
$otherConnection = Harness::secondConnection();
if ($otherConnection !== null) {
    $sourceLock = 'monitor-source:' . substr(hash('sha256', $fx['monitored_source_id']), 0, 48);
    Assert::same(1, (int) $otherConnection->scalar('SELECT GET_LOCK(?, 0)', [$sourceLock]), 'second session can hold the source serialization lock');
    Assert::same('MONITOR_SOURCE_BUSY', $service->advance($scope, $baseline['monitor_run_id'])->reasonCode(), 'another in-flight capture of this source refuses before fetch');
    Assert::same('NEW', (new RunJournal($db, $clock))->find($scope, $baseline['monitor_run_id'])['stage'], 'source contention does not terminalize the run');
    Assert::same(0, $fx['source']->calls, 'source contention performs no HTTP fetch');
    $otherConnection->scalar('SELECT RELEASE_LOCK(?)', [$sourceLock]);
}
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


// A durable provider refusal is also a known result. If the process dies before the journal can
// record BLOCKED, reconciliation adopts that exact refusal without another provider call.
$refusalFx = X::create($db, $clock, ['The pricing note says November.', 'The pricing note says December.']);
$refusalScope = $refusalFx['scope'];
$refusalApprovals = X::approveAll($refusalFx, 'MANUAL_MONITOR');
X::admitted($refusalFx, X::manualDefinition($db, $clock, $refusalFx, $refusalApprovals));
$quotaTransport = new \Allfather\Loopia\Tests\LiveFlaggedFixtureTransport(
    static fn ($request) => new \Allfather\Loopia\Provider\ModelResponse(
        429,
        '{"error":{"code":"insufficient_quota","message":"PRIVATE_PROVIDER_TEXT"}}',
    ),
);
$refusalService = X::service(
    $db, $clock, $refusalFx, $refusalFx['source'], $quotaTransport,
    $refusalFx['resolver'], $refusalFx['admission'],
);
$refusalBaseline = X::value($refusalService->startConfigured($refusalScope, $refusalFx['mission_id'], 'p05-refusal-baseline'));
X::value($refusalService->advance($refusalScope, $refusalBaseline['monitor_run_id']));
$clock->advanceDays(1);
$refusalRun = X::value($refusalService->startConfigured($refusalScope, $refusalFx['mission_id'], 'p05-refusal-changed'));
$refusalId = $refusalRun['monitor_run_id'];
X::value($refusalService->advance($refusalScope, $refusalId));
X::value($refusalService->advance($refusalScope, $refusalId));
$refusalDetail = X::value($refusalService->detail($refusalScope, $refusalId));

$db->run("CREATE TRIGGER test_p05_refusal_journal_failure BEFORE INSERT ON monitor_run_events FOR EACH ROW BEGIN IF NEW.event_kind = 'MODEL_REFUSED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected p05 refusal journal crash'; END IF; END");
$refusalCrash = $refusalService->advance($refusalScope, $refusalId, $refusalDetail['preview_request']['request_digest']);
Assert::same('MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED', $refusalCrash->reasonCode(), 'known provider refusal survives a journal crash as reconciliation work');
$db->run('DROP TRIGGER test_p05_refusal_journal_failure');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($refusalScope, $refusalId)['stage'], 'refusal crash leaves the model stage intact');
Assert::same(1, $quotaTransport->calls, 'quota provider path ran once');

$refusalReconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($refusalFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $refusalFx['evidence_store']),
    new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $refusalFx['evidence_store']),
    $refusalFx['admission'],
);
$refusalInspection = X::value($refusalReconciliation->inspect($refusalScope, $refusalId));
Assert::same('RECOVERABLE_REFUSAL', $refusalInspection['status'], 'durable refusal is distinguishable from an unknown outcome');
Assert::same('MODEL_QUOTA_EXHAUSTED', $refusalInspection['reason_code'], 'specific governed refusal is preserved');
$refusalAdopted = X::value($refusalReconciliation->adopt($refusalScope, $refusalId, $refusalInspection['run_revision']));
Assert::same('ADOPTED_REFUSAL', $refusalAdopted['disposition'], 'operator adopts the exact durable refusal');
Assert::same('BLOCKED', $refusalAdopted['run']['stage'], 'known terminal refusal becomes the ordinary BLOCKED journal outcome');
Assert::same(1, $quotaTransport->calls, 'refusal adoption never calls the provider again');
Assert::false(str_contains(json_encode($refusalAdopted), 'PRIVATE_PROVIDER_TEXT'), 'provider error text never enters reconciliation output');

$unknownFx = X::create($db, $clock, ['The offer says Q1.', 'The offer says Q2.']);
$unknownScope = $unknownFx['scope'];
$unknownApprovals = X::approveAll($unknownFx, 'MANUAL_MONITOR');
X::admitted($unknownFx, X::manualDefinition($db, $clock, $unknownFx, $unknownApprovals));
$unknownTransport = new \Allfather\Loopia\Tests\LiveFlaggedFixtureTransport(
    static fn ($request) => new \Allfather\Loopia\Provider\ModelResponse(
        0, '', null, 'MODEL_NETWORK_OUTCOME_UNKNOWN'
    ),
);
$unknownService = X::service(
    $db, $clock, $unknownFx, $unknownFx['source'], $unknownTransport,
    $unknownFx['resolver'], $unknownFx['admission'],
);
$unknownBaseline = X::value($unknownService->startConfigured($unknownScope, $unknownFx['mission_id'], 'p05-network-baseline'));
X::value($unknownService->advance($unknownScope, $unknownBaseline['monitor_run_id']));
$clock->advanceDays(1);
$unknownRun = X::value($unknownService->startConfigured($unknownScope, $unknownFx['mission_id'], 'p05-network-changed'));
$unknownId = $unknownRun['monitor_run_id'];
X::value($unknownService->advance($unknownScope, $unknownId));
X::value($unknownService->advance($unknownScope, $unknownId));
$unknownDetail = X::value($unknownService->detail($unknownScope, $unknownId));
$unknownAdvance = $unknownService->advance($unknownScope, $unknownId, $unknownDetail['preview_request']['request_digest']);
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $unknownAdvance->reasonCode(), 'network-unknown result routes to reconciliation rather than ordinary BLOCKED');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($unknownScope, $unknownId)['stage'], 'ambiguous network outcome leaves the model stage intact');
Assert::same(1, $unknownTransport->calls, 'ambiguous transport is never automatically retried');

$unknownReconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($unknownFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $unknownFx['evidence_store']),
    new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $unknownFx['evidence_store']),
    $unknownFx['admission'],
);
$unknownInspection = X::value($unknownReconciliation->inspect($unknownScope, $unknownId));
Assert::same('UNRESOLVED', $unknownInspection['status'], 'network-unknown provider result stays explicitly unresolved');
Assert::same('MODEL_NETWORK_OUTCOME_UNKNOWN', $unknownInspection['reason_code'], 'the ambiguous transport reason is preserved');
Assert::same(
    'MONITOR_RECONCILIATION_NOT_RECOVERABLE',
    $unknownReconciliation->adopt($unknownScope, $unknownId, $unknownInspection['run_revision'])->reasonCode(),
    'an ambiguous provider outcome cannot be adopted as a known refusal',
);
Assert::same(1, $unknownTransport->calls, 'failed adoption still performs no provider call');


// "Nothing was dispatched" is not an unresolved provider outcome and cannot be filed as one.
$noDispatchFx = X::create($db, $clock, ['The note says alpha.', 'The note says beta.']);
$noDispatchScope = $noDispatchFx['scope'];
$noDispatchApprovals = X::approveAll($noDispatchFx, 'MANUAL_MONITOR');
X::admitted($noDispatchFx, X::manualDefinition($db, $clock, $noDispatchFx, $noDispatchApprovals));
$noDispatchService = $noDispatchFx['service'];
$noDispatchBaseline = X::value($noDispatchService->startConfigured($noDispatchScope, $noDispatchFx['mission_id'], 'p05-no-dispatch-baseline'));
X::value($noDispatchService->advance($noDispatchScope, $noDispatchBaseline['monitor_run_id']));
$clock->advanceDays(1);
$noDispatchRun = X::value($noDispatchService->startConfigured($noDispatchScope, $noDispatchFx['mission_id'], 'p05-no-dispatch-changed'));
$noDispatchId = $noDispatchRun['monitor_run_id'];
X::value($noDispatchService->advance($noDispatchScope, $noDispatchId));
Assert::same('DIFFED', X::value($noDispatchService->advance($noDispatchScope, $noDispatchId))['stage'], 'fixture reaches a model stage without dispatching it');
$noDispatchReconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($noDispatchFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $noDispatchFx['evidence_store']),
    new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $noDispatchFx['evidence_store']),
    $noDispatchFx['admission'],
);
$cleanInspection = X::value($noDispatchReconciliation->inspect($noDispatchScope, $noDispatchId));
Assert::same('NO_DISPATCH_RECORDED', $cleanInspection['status'], 'an untouched model stage reports that nothing was dispatched');
Assert::same(
    'MONITOR_RECONCILIATION_NOT_UNRESOLVED',
    $noDispatchReconciliation->markUnresolved($noDispatchScope, $noDispatchId, $cleanInspection['run_revision'], 'TEST_NOT_AN_UNKNOWN_OUTCOME')->reasonCode(),
    'operator cannot relabel a safe no-dispatch state as an unknown provider outcome',
);
