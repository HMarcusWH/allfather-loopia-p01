<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Provider\ModelArtifactStore;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};
use Allfather\Loopia\Workflow\{ContextEnvelope,MissionConfiguration,RunArtifacts,RunJournal,RunReconciliation};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];
$approvals = X::approveAll($fx, 'MANUAL_MONITOR');
X::admitted($fx, X::manualDefinition($db, $clock, $fx, $approvals));
$service = $fx['service'];

$baseline = X::value($service->startConfigured($scope, $fx['mission_id'], 'p05-security-baseline'));
X::value($service->advance($scope, $baseline['monitor_run_id']));
$clock->advanceDays(1);
$run = X::value($service->startConfigured($scope, $fx['mission_id'], 'p05-security-changed'));
$id = $run['monitor_run_id'];
X::value($service->advance($scope, $id));
X::value($service->advance($scope, $id));
$detail = X::value($service->detail($scope, $id));

$db->run("CREATE TRIGGER test_p05_security_journal_failure BEFORE INSERT ON monitor_run_events FOR EACH ROW BEGIN IF NEW.event_kind = 'MODEL_CANDIDATE_RECORDED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected security crash'; END IF; END");
Assert::same('MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED', $service->advance($scope, $id, $detail['preview_request']['request_digest'])->reasonCode(), 'fixture creates one recoverable interrupted candidate');
$db->run('DROP TRIGGER test_p05_security_journal_failure');

$artifacts = new ModelArtifactStore($db, $clock, $fx['evidence_store']);
$reconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($fx['evidence_store']),
    $artifacts,
    new MissionConfiguration($db, $clock),
    new ContextEnvelope($db, $clock, $fx['evidence_store']),
    $fx['admission'],
);
$inspection = X::value($reconciliation->inspect($scope, $id));
Assert::same('RECOVERABLE_CANDIDATE', $inspection['status'], 'fixture is recoverable before scope attacks');

$foreign = Seed::tenantWorkspace($db, $clock, 'p05-reconciliation-foreign');
Assert::same('MONITOR_RECONCILIATION_ACCESS_DENIED', $reconciliation->inspect($foreign, $id)->reasonCode(), 'foreign tenant/workspace cannot inspect reconciliation');
Assert::same('MONITOR_RUN_REVISION_CHANGED_RELOAD_REQUIRED', $reconciliation->adopt($scope, $id, $inspection['run_revision'] + 1)->reasonCode(), 'operator cannot adopt against an uninspected revision');

Assert::same(
    'MODEL_CANDIDATE_ARTIFACT_NOT_FOUND',
    $artifacts->read($foreign['tenant_id'], $foreign['workspace_id'], $fx['mission_id'], $inspection['model_attempt_id'], $inspection['request_digest'])->reasonCode(),
    'provider candidate bytes cannot cross tenant/workspace scope',
);
Assert::same(
    'MODEL_CANDIDATE_ARTIFACT_REQUEST_MISMATCH',
    $artifacts->read($scope['tenant_id'], $scope['workspace_id'], $fx['mission_id'], $inspection['model_attempt_id'], str_repeat('0', 64))->reasonCode(),
    'a candidate cannot be borrowed by another request digest',
);
Assert::same(
    'MONITOR_RECONCILIATION_RESULT_IS_RECOVERABLE',
    $reconciliation->markUnresolved($scope, $id, $inspection['run_revision'], 'TEST_MUST_NOT_DOWNGRADE_KNOWN_RESULT')->reasonCode(),
    'known durable result cannot be mislabeled unresolved',
);

$sourceRefId = (string) $db->scalar('SELECT source_ref_id FROM monitored_sources WHERE monitored_source_id=?', [$fx['monitored_source_id']]);
$sourceStatus = (string) $db->scalar('SELECT status FROM source_references WHERE source_ref_id=?', [$sourceRefId]);
$db->run("UPDATE source_references SET status='REVOKED' WHERE source_ref_id=?", [$sourceRefId]);
$staleAdoption = $reconciliation->adopt($scope, $id, $inspection['run_revision']);
Assert::false($staleAdoption->isOk(), 'reconciliation re-checks current source/context authority before consuming a candidate');
Assert::same('DIFFED', (new RunJournal($db, $clock))->find($scope, $id)['stage'], 'currentness refusal leaves interrupted history untouched');
$db->run('UPDATE source_references SET status=? WHERE source_ref_id=?', [$sourceStatus, $sourceRefId]);

// Storage pins reject a forged cross-scope reconciliation even when every referenced identity exists.
$foreignForWrite = Seed::tenantWorkspace($db, $clock, 'p05-reconciliation-direct-write');
try {
    $db->insert('monitor_run_reconciliations', [
        'reconciliation_id' => \Allfather\Loopia\Core\Ids::new(),
        'tenant_id' => $foreignForWrite['tenant_id'],
        'workspace_id' => $foreignForWrite['workspace_id'],
        'monitor_run_id' => $id,
        'run_revision' => $inspection['run_revision'],
        'model_stage' => 'classifier',
        'disposition' => 'UNRESOLVED',
        'tool_request_id' => null,
        'model_attempt_id' => null,
        'model_result_id' => null,
        'request_digest' => $inspection['request_digest'],
        'candidate_digest' => null,
        'artifact_digest' => null,
        'observed_reason_code' => 'FORGED_SCOPE',
        'operator_reason_code' => 'TEST_FORGED_SCOPE',
        'recorded_by_operator_id' => $foreignForWrite['operator_id'],
        'recorded_at' => $clock->now(),
    ]);
    Assert::fail('database accepted a reconciliation whose tenant/workspace do not own the run');
} catch (\PDOException) {
    Assert::pass();
}

foreach ([
    "UPDATE provider_model_candidate_artifacts SET candidate_digest='" . str_repeat('0', 64) . "'",
    'DELETE FROM provider_model_candidate_artifacts',
] as $sql) {
    try { $db->run($sql); Assert::fail('provider candidate artifact history mutated: ' . $sql); }
    catch (\PDOException) { Assert::pass(); }
}

$adopted = X::value($reconciliation->adopt($scope, $id, $inspection['run_revision']));
Assert::same('ANALYZED', $adopted['run']['stage'], 'the exact scoped adoption succeeds once');
foreach ([
    "UPDATE monitor_run_reconciliations SET observed_reason_code='REWRITTEN'",
    'DELETE FROM monitor_run_reconciliations',
] as $sql) {
    try { $db->run($sql); Assert::fail('reconciliation history mutated: ' . $sql); }
    catch (\PDOException) { Assert::pass(); }
}

$row = $db->one('SELECT candidate_digest,artifact_digest FROM provider_model_candidate_artifacts WHERE model_attempt_id=?', [$inspection['model_attempt_id']]);
Assert::same($row['candidate_digest'], $row['artifact_digest'], 'canonical candidate bytes and candidate digest share one content identity');
Assert::true(preg_match('/^[a-f0-9]{64}$/D', $row['artifact_digest']) === 1, 'artifact identity is a sha256 digest');
