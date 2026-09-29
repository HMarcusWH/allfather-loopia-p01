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
    $sourceLock = 'monitor-source:' . substr(hash('sha256', (string) $db->scalar('SELECT DATABASE()') . "\0" . $fx['monitored_source_id']), 0, 48);
    Assert::same(1, (int) $otherConnection->scalar('SELECT GET_LOCK(?, 0)', [$sourceLock]), 'second session can hold the source serialization lock');
    Assert::same('MONITOR_SOURCE_BUSY', $service->advance($scope, $baseline['monitor_run_id'])->reasonCode(), 'another in-flight capture of this source refuses before fetch');
    Assert::same('NEW', (new RunJournal($db, $clock))->find($scope, $baseline['monitor_run_id'])['stage'], 'source contention does not terminalize the run');
    Assert::same(0, $fx['source']->calls, 'source contention performs no HTTP fetch');
    Assert::same(0, $fx['grants']->consumed(X::value($fx['grants']->currentFor($scope['tenant_id'], $scope['workspace_id'], 'MANUAL_MONITOR', $fx['mission_id'], $fx['definition_digest']))['execution_grant_id'], 'SOURCE_CAPTURE'), 'source-lock contention spends no capture budget');
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
Assert::same('MANUAL_MONITOR', $inspection['execution_purpose'], 'inspection exposes the exact recorded execution purpose');
Assert::true(is_string($inspection['execution_grant_id']) && $inspection['execution_grant_id'] !== '', 'inspection exposes the exact recorded execution grant identity');
Assert::true(is_string($inspection['tool_execution_outcome_id']) && $inspection['tool_execution_outcome_id'] !== '', 'inspection exposes the exact M06 tool outcome identity');
Assert::same('SUCCEEDED', $inspection['tool_outcome_status'], 'recoverable candidate shows the exact successful M06 outcome');
Assert::false(isset($inspection['candidate']), 'operator inspection does not print private candidate bytes');

$adopted = X::value($reconciliation->adopt($scope, $id, $inspection['run_revision']));
Assert::same('ADOPTED_CANDIDATE', $adopted['disposition'], 'operator explicitly adopts the durable candidate');
Assert::same('ANALYZED', $adopted['run']['stage'], 'adoption uses the ordinary DIFFED -> ANALYZED transition');
Assert::same($inspection['request_artifact_digest'], $adopted['run']['cursor']['classifier_request_artifact'], 'recovered run preserves the exact inspected request artifact in its cursor');
Assert::same(1, $fx['model']->calls, 'adoption never repeats the model call');
Assert::same('PASS', X::value((new RunJournal($db, $clock))->replay($scope, $id))['integrity'], 'recovered history preserves the existing journal digest law');
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
Assert::same($refusalInspection['request_artifact_digest'], $refusalAdopted['run']['cursor']['classifier_request_artifact'], 'reconciled refusal preserves the inspected request artifact exactly like the ordinary path');
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


// ---------------------------------------------------------------- additional crash windows

// Failure while reserving the attempt occurs before provider dispatch. M01/M06 still leave a
// governed failed tool receipt, but there is no provider attempt and no safe automatic second call.
$preFx = X::create($db, $clock, ['The page says old.', 'The page says new.']);
$preScope = $preFx['scope'];
$preApprovals = X::approveAll($preFx, 'MANUAL_MONITOR');
X::admitted($preFx, X::manualDefinition($db, $clock, $preFx, $preApprovals));
$preService = $preFx['service'];
$preBaseline = X::value($preService->startConfigured($preScope, $preFx['mission_id'], 'p05-pre-reserve-baseline'));
X::value($preService->advance($preScope, $preBaseline['monitor_run_id']));
$clock->advanceDays(1);
$preRun = X::value($preService->startConfigured($preScope, $preFx['mission_id'], 'p05-pre-reserve-changed'));
$preId = $preRun['monitor_run_id'];
X::value($preService->advance($preScope, $preId));
X::value($preService->advance($preScope, $preId));
$preDetail = X::value($preService->detail($preScope, $preId));
$db->run("CREATE TRIGGER test_p05_attempt_reservation_failure BEFORE INSERT ON provider_model_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected reservation failure'");
$preAdvance = $preService->advance($preScope, $preId, $preDetail['preview_request']['request_digest']);
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $preAdvance->reasonCode(), 'reservation failure is not turned into an automatic retry');
$db->run('DROP TRIGGER test_p05_attempt_reservation_failure');
Assert::same(0, $preFx['model']->calls, 'reservation failure occurs before provider transport');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($preScope, $preId)['stage'], 'pre-dispatch failure leaves the inspected model stage intact');
$preReconciliation = new RunReconciliation(
    $db, $clock, new RunJournal($db, $clock), new RunArtifacts($preFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $preFx['evidence_store']), new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $preFx['evidence_store']), $preFx['admission'],
);
$preInspection = X::value($preReconciliation->inspect($preScope, $preId));
Assert::same('UNRESOLVED', $preInspection['status'], 'a failed reservation has no fabricated attempt');
Assert::same('MONITOR_RECONCILIATION_ATTEMPT_NOT_RECORDED', $preInspection['reason_code'], 'the missing attempt is named');
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $preService->advance($preScope, $preId, $preDetail['preview_request']['request_digest'])->reasonCode(), 'same governed request replays instead of dispatching anew');
Assert::same(0, $preFx['model']->calls, 'replay after reservation failure still makes no provider call');

// Provider bytes returned, but candidate persistence failed. The response cannot become a recoverable
// candidate; it is a known durable refusal and remains single-spend.
$artifactFx = X::create($db, $clock, ['The item says bronze.', 'The item says silver.']);
$artifactScope = $artifactFx['scope'];
$artifactApprovals = X::approveAll($artifactFx, 'MANUAL_MONITOR');
X::admitted($artifactFx, X::manualDefinition($db, $clock, $artifactFx, $artifactApprovals));
$artifactService = $artifactFx['service'];
$artifactBaseline = X::value($artifactService->startConfigured($artifactScope, $artifactFx['mission_id'], 'p05-artifact-baseline'));
X::value($artifactService->advance($artifactScope, $artifactBaseline['monitor_run_id']));
$clock->advanceDays(1);
$artifactRun = X::value($artifactService->startConfigured($artifactScope, $artifactFx['mission_id'], 'p05-artifact-changed'));
$artifactId = $artifactRun['monitor_run_id'];
X::value($artifactService->advance($artifactScope, $artifactId));
X::value($artifactService->advance($artifactScope, $artifactId));
$artifactDetail = X::value($artifactService->detail($artifactScope, $artifactId));
$db->run("CREATE TRIGGER test_p05_candidate_persist_failure BEFORE INSERT ON provider_model_candidate_artifacts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected candidate persistence failure'");
$db->run("CREATE TRIGGER test_p05_candidate_refusal_journal_failure BEFORE INSERT ON monitor_run_events FOR EACH ROW BEGIN IF NEW.event_kind = 'MODEL_REFUSED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected refusal journal failure'; END IF; END");
$artifactAdvance = $artifactService->advance($artifactScope, $artifactId, $artifactDetail['preview_request']['request_digest']);
Assert::same('MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED', $artifactAdvance->reasonCode(), 'journal crash after candidate-persistence refusal requires reconciliation');
$db->run('DROP TRIGGER test_p05_candidate_refusal_journal_failure');
$db->run('DROP TRIGGER test_p05_candidate_persist_failure');
Assert::same(1, $artifactFx['model']->calls, 'provider returned exactly once before persistence failed');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_candidate_artifacts ca JOIN provider_model_attempts a ON a.model_attempt_id=ca.model_attempt_id WHERE a.tenant_id=?', [$artifactScope['tenant_id']]), 'failed persistence left no candidate artifact');
$artifactReconciliation = new RunReconciliation(
    $db, $clock, new RunJournal($db, $clock), new RunArtifacts($artifactFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $artifactFx['evidence_store']), new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $artifactFx['evidence_store']), $artifactFx['admission'],
);
$artifactInspection = X::value($artifactReconciliation->inspect($artifactScope, $artifactId));
Assert::same('RECOVERABLE_REFUSAL', $artifactInspection['status'], 'failed candidate persistence is recoverable only as its refusal');
Assert::same('MODEL_CANDIDATE_ARTIFACT_PERSIST_FAILED', $artifactInspection['reason_code'], 'the durable refusal names the lost-candidate boundary');

// Candidate bytes persisted, then M06 outcome finalization failed. The bytes alone do not establish a
// completed governed result, and the same idempotency key cannot cause a second provider call.
$outcomeFx = X::create($db, $clock, ['The page says north.', 'The page says south.']);
$outcomeScope = $outcomeFx['scope'];
$outcomeApprovals = X::approveAll($outcomeFx, 'MANUAL_MONITOR');
X::admitted($outcomeFx, X::manualDefinition($db, $clock, $outcomeFx, $outcomeApprovals));
$outcomeService = $outcomeFx['service'];
$outcomeBaseline = X::value($outcomeService->startConfigured($outcomeScope, $outcomeFx['mission_id'], 'p05-outcome-baseline'));
X::value($outcomeService->advance($outcomeScope, $outcomeBaseline['monitor_run_id']));
$clock->advanceDays(1);
$outcomeRun = X::value($outcomeService->startConfigured($outcomeScope, $outcomeFx['mission_id'], 'p05-outcome-changed'));
$outcomeId = $outcomeRun['monitor_run_id'];
X::value($outcomeService->advance($outcomeScope, $outcomeId));
X::value($outcomeService->advance($outcomeScope, $outcomeId));
$outcomeDetail = X::value($outcomeService->detail($outcomeScope, $outcomeId));
$db->run("CREATE TRIGGER test_p05_tool_outcome_failure BEFORE INSERT ON tool_execution_outcomes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected m06 completion failure'");
$outcomeAdvance = $outcomeService->advance($outcomeScope, $outcomeId, $outcomeDetail['preview_request']['request_digest']);
Assert::same('MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED', $outcomeAdvance->reasonCode(), 'M06 completion crash is caught at the workflow boundary');
$db->run('DROP TRIGGER test_p05_tool_outcome_failure');
Assert::same(1, $outcomeFx['model']->calls, 'provider was called once before M06 completion failed');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_candidate_artifacts ca JOIN provider_model_attempts a ON a.model_attempt_id=ca.model_attempt_id WHERE a.tenant_id=?', [$outcomeScope['tenant_id']]), 'candidate bytes survived the M06 completion crash');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_results r JOIN provider_model_attempts a ON a.model_attempt_id=r.model_attempt_id WHERE a.tenant_id=?', [$outcomeScope['tenant_id']]), 'provider result finalization never ran after the M06 exception');
$outcomeReconciliation = new RunReconciliation(
    $db, $clock, new RunJournal($db, $clock), new RunArtifacts($outcomeFx['evidence_store']),
    new ModelArtifactStore($db, $clock, $outcomeFx['evidence_store']), new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $outcomeFx['evidence_store']), $outcomeFx['admission'],
);
$outcomeInspection = X::value($outcomeReconciliation->inspect($outcomeScope, $outcomeId));
Assert::same('UNRESOLVED', $outcomeInspection['status'], 'candidate bytes without the final governed result stay unresolved');
Assert::same('MONITOR_RECONCILIATION_RESULT_NOT_RECORDED', $outcomeInspection['reason_code'], 'reconciliation names the missing result receipt');
Assert::same('MONITOR_MODEL_REQUIRES_RECONCILIATION', $outcomeService->advance($outcomeScope, $outcomeId, $outcomeDetail['preview_request']['request_digest'])->reasonCode(), 'post-crash replay still refuses a second dispatch');
Assert::same(1, $outcomeFx['model']->calls, 'M06-completion replay does not call the provider again');


// Reservation is durable before provider dispatch. If authority disappears in that exact window,
// the spent attempt remains truthful and zero provider bytes leave.
$reserveFx = X::create($db, $clock, ['The policy says east.', 'The policy says west.']);
$reserveScope = $reserveFx['scope'];
$reserveApprovals = X::approveAll($reserveFx, 'MANUAL_MONITOR');
X::admitted($reserveFx, X::manualDefinition($db, $clock, $reserveFx, $reserveApprovals));
$reserveService = $reserveFx['service'];
$reserveBaseline = X::value($reserveService->startConfigured($reserveScope, $reserveFx['mission_id'], 'p05-after-reserve-baseline'));
X::value($reserveService->advance($reserveScope, $reserveBaseline['monitor_run_id']));
$clock->advanceDays(1);
$reserveRun = X::value($reserveService->startConfigured($reserveScope, $reserveFx['mission_id'], 'p05-after-reserve-changed'));
$reserveId = $reserveRun['monitor_run_id'];
X::value($reserveService->advance($reserveScope, $reserveId));
X::value($reserveService->advance($reserveScope, $reserveId));
$reserveDetail = X::value($reserveService->detail($reserveScope, $reserveId));
$reserveGrant = X::value($reserveFx['grants']->currentFor(
    $reserveScope['tenant_id'], $reserveScope['workspace_id'], 'MANUAL_MONITOR',
    $reserveFx['mission_id'], $reserveFx['definition_digest'],
));
foreach ([$reserveGrant['execution_grant_id'], $reserveScope['operator_id'], $reserveScope['tenant_id']] as $safeId) {
    Assert::true(preg_match('/^[a-f0-9-]{36}$/D', $safeId) === 1, 'fixture id is safe for the fault-injection trigger');
}
$db->run(sprintf(
    "CREATE TRIGGER test_p05_suspend_after_reserve AFTER INSERT ON provider_model_attempts FOR EACH ROW
     BEGIN
       IF NEW.tenant_id='%s' THEN
         INSERT INTO monitor_execution_grant_events
           (execution_grant_event_id,execution_grant_id,sequence_no,event_kind,owner_decision_ref,owner_decision_sha256,reason_code,recorded_by_operator_id,recorded_at)
         VALUES (UUID(),'%s',2,'SUSPENDED',NULL,NULL,'TEST_SUSPEND_AFTER_RESERVE','%s',NEW.reserved_at);
       END IF;
     END",
    $reserveScope['tenant_id'],
    $reserveGrant['execution_grant_id'],
    $reserveScope['operator_id'],
));
$reserveAdvance = X::value($reserveService->advance($reserveScope, $reserveId, $reserveDetail['preview_request']['request_digest']));
$db->run('DROP TRIGGER test_p05_suspend_after_reserve');
Assert::same('BLOCKED', $reserveAdvance['stage'], 'fresh pre-dispatch admission refusal becomes the ordinary terminal refusal');
Assert::same('EXECUTION_GRANT_SUSPENDED', $reserveAdvance['cursor']['reason_code'], 'the exact post-reservation authority loss is retained');
Assert::same(0, $reserveFx['model']->calls, 'authority loss after reservation sends no provider request');
Assert::same(1, $reserveFx['grants']->consumed($reserveGrant['execution_grant_id'], 'MODEL_ATTEMPT'), 'the reserved attempt remains consumed');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM provider_model_attempts WHERE tenant_id=? AND workspace_id=? AND mission_id=?', [$reserveScope['tenant_id'], $reserveScope['workspace_id'], $reserveFx['mission_id']]),
    'the exact reserved attempt remains durable',
);
Assert::same(
    'EXECUTION_GRANT_SUSPENDED',
    $db->scalar("SELECT failure_code FROM provider_model_results r JOIN provider_model_attempts a ON a.model_attempt_id=r.model_attempt_id WHERE a.tenant_id=? AND a.workspace_id=? AND a.mission_id=?", [$reserveScope['tenant_id'], $reserveScope['workspace_id'], $reserveFx['mission_id']]),
    'the durable provider-result refusal names why dispatch never occurred',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM provider_model_candidate_artifacts ca JOIN provider_model_attempts a ON a.model_attempt_id=ca.model_attempt_id WHERE a.tenant_id=? AND a.workspace_id=? AND a.mission_id=?', [$reserveScope['tenant_id'], $reserveScope['workspace_id'], $reserveFx['mission_id']]),
    'no candidate artifact exists when provider dispatch never occurred',
);
