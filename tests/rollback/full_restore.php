<?php
declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ops\{BackupManifest,MaintenanceGate};
use Allfather\Loopia\Product\P01\{Battlecards,Release as P01Release};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M05\{EvidenceSpine,Readiness};
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};
use Allfather\Loopia\Workflow\{NativeOperations,RunJournal};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'backup-restore');

// Populated M10 history, not a zero-row schema rehearsal.
$ledger = new DecisionLedger($db, $clock);
$stream = $ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'TEST');
Assert::true($stream->isOk(), 'restore fixture has an M10 stream');
$streamId = $stream->value()['decision_ledger_stream_id'];
$appended = $ledger->append(
    $streamId,
    'PROPOSAL_HELD',
    'GOVERNANCE_MATERIAL',
    'HELD',
    'backup restore fixture',
    [[
        'owner_module_id' => 'M04',
        'input_object_type' => 'PROPOSAL',
        'input_object_id' => Ids::new(),
        'reference_role' => 'PROPOSAL_BASIS',
    ]],
    'service:backup-test',
    ['reason_codes' => ['BACKUP_RESTORE_FIXTURE']],
);
Assert::true($appended->isOk(), 'restore fixture has populated M10 history');
Assert::same('PASS', $ledger->verifyStream($streamId)['status'], 'fixture M10 chain begins valid');

// Retained output is populated through the real P01 -> M10 -> M16 release path. The restore verifier
// must prove these immutable output rows still decode, re-digest and serialize as a read model.
$governedRelease = Seed::governedReleaseChain(
    $db,
    $clock,
    $scope,
    [],
    'PROCEED_TO_ARBITER',
    'COMPANY_SIGNAL_BRIEF',
    true,
);
$signalId = Ids::new();
$db->insert('company_signals', [
    'company_signal_id' => $signalId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'competitor_id' => $governedRelease['competitor_id'],
    'source_diff_id' => $governedRelease['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'List price line changed.',
    'proposal_id' => $governedRelease['proposal_id'],
    'status' => 'UNDER_REVIEW',
    'created_at' => $clock->now(),
]);
$battlecards = new Battlecards($db, $clock, new Arbiter($db, $clock, new Readiness($db, $clock)));
$verdict = $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $signalId,
    'arbiter_policy_set_id' => $governedRelease['arbiter_policy_set_id'],
    'content' => [
        'why_it_matters_safe' => 'The competitor raised list pricing on its public page.',
        'customer_impact_hypothesis_safe' => 'Existing customers MAY face a renewal increase; not observed.',
        'talk_track_safe' => 'Ask whether the customer has seen a renewal notice.',
        'recommended_response_safe' => 'Reconfirm our own price stability in writing.',
        'rejected_claims_safe' => 'Not supported: that existing contracts were repriced.',
    ],
]);
Assert::true($verdict->isOk(), 'restore fixture materializes a governed P01 verdict');
Assert::same('ADMITTED', $verdict->value()['outcome'], 'retained output fixture is admitted');
$release = new P01Release($db, $clock, $ledger, new ReleaseOutput($db, $clock, $ledger));
$released = $release->releaseBriefing([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'battlecard_brief_id' => $verdict->value()['battlecard_brief_id'],
]);
Assert::true($released->isOk(), 'restore fixture creates a retained released briefing');
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM customer_output_projections WHERE status='PROJECTED'"),
    'restore fixture includes a concrete renderable M16 projection',
);

// A real Monitor journal history. RunJournal intentionally needs only a valid active mission scope.
$chain = Seed::governedToolChain($db, $clock, $scope);
$journal = new RunJournal($db, $clock);
$run = $journal->create($scope, ['mission_id' => $chain['mission_id']], 'backup-restore-run', 'FIXTURE');
Assert::true($run->isOk(), 'restore fixture creates a Monitor run');
$run = $journal->advance($scope, $run->value(), 'BASELINE', ['fixture' => 'retained'], 'SOURCE_CAPTURED');
Assert::true($run->isOk(), 'restore fixture has a Monitor event');
Assert::same('PASS', $journal->replay($scope, $run->value()['monitor_run_id'])->value()['integrity'], 'fixture Monitor chain begins valid');

// One referenced private artifact, through the real M05 owner-upload shape.
$root = sys_get_temp_dir() . '/allfather-full-restore-' . bin2hex(random_bytes(6));
mkdir($root . '/storage/evidence', 0700, true);

// BackupManifest binds the exact operator tooling bytes. The fixture root is deliberately private
// and synthetic, so copy only those tracked tooling files from the checkout rather than weakening
// the production fingerprint rule for tests.
$repoRoot = dirname(__DIR__, 2);
foreach ([
    'ops/backup/export.sh',
    'ops/backup/restore-rehearsal.sh',
    'ops/backup/maintenance.php',
    'ops/backup/verify-restore.php',
    'ops/monitor/stand-down.php',
    'ops/rollback/verify-stand-down.php',
    'ops/loopia-probe/probe.php',
] as $relative) {
    $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    Assert::true(copy($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $target), 'fixture copies exact P10 tooling: ' . $relative);
}
$store = new EvidenceStore($root . '/storage/evidence');
$bytes = 'private retained owner profile for backup restore fixture';
$stored = $store->put($bytes);
Assert::true($stored->isOk(), 'private fixture artifact stored');
$digest = $stored->value()['digest'];
$m05 = new EvidenceSpine($db, $clock);
$source = $m05->registerSource(
    $scope['tenant_id'],
    $scope['workspace_id'],
    'UPLOADED_ARTIFACT',
    'internal://backup/fixture',
    'INTERNAL_ANALYSIS',
    'INTERNAL_DEMO',
    'ACTIVE_FOR_CAPTURE',
);
$captured = $m05->capture([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'source_ref_id' => $source,
    'evidence_type' => 'UPLOADED_SOURCE_CAPTURE',
    'captured_content_reference_safe' => 'evidence://' . $digest,
    'content_hash' => $digest,
    'capture_method' => 'OWNER_UPLOAD',
    'permitted_use_scope' => 'INTERNAL_ANALYSIS',
    'sensitivity_class' => 'INTERNAL_DEMO',
]);
Assert::true($captured->isOk(), 'artifact is referenced by governed evidence');

file_put_contents($root . '/storage/request.log', "af-request id=fixture method=GET path=/healthz status=200\n");
file_put_contents(
    $root . '/dump.sql',
    "-- synthetic native-dump placeholder for package-contract test only\nSELECT 1;\n",
);

// A future-dated but already ADMITTED grant is execution-capable future state. It must not survive
// a backup merely because status() currently calls it NOT_YET_VALID.
$futureFx = X::create($db, $clock);
// ExecutionFixture keeps its owner-profile bytes in its own private temp store. Because P10 backs
// up EVERY referenced content-addressed artifact in the database, mirror that exact referenced byte
// into this test's simulated runtime evidence root before building the package.
$futureProfileEvidenceId = $futureFx['context_selection']['profile_evidence_ids'][0];
$futureProfileDigest = (string) $db->scalar(
    'SELECT content_hash FROM evidence_objects WHERE evidence_id=?',
    [$futureProfileEvidenceId],
);
$futureProfileBytes = $futureFx['evidence_store']->get($futureProfileDigest);
Assert::true($futureProfileBytes->isOk(), 'future-grant fixture profile bytes are available');
Assert::true($store->put((string) $futureProfileBytes->value())->isOk(), 'all referenced fixture bytes are present in the simulated runtime evidence store');

$futureApprovals = X::approveAll($futureFx, 'MANUAL_MONITOR');
$from = \Allfather\Loopia\Core\Clock::parse($clock->now());
$futureGrant = X::admitted($futureFx, X::manualDefinition($db, $clock, $futureFx, $futureApprovals, [
    'valid_from' => \Allfather\Loopia\Core\Clock::format($from->modify('+1 day')),
    'valid_until' => \Allfather\Loopia\Core\Clock::format($from->modify('+6 days')),
]));
Assert::same('NOT_YET_VALID', $futureFx['grants']->status($futureGrant['execution_grant_id']), 'fixture has an owner-admitted future grant');

$gate = new MaintenanceGate($root, $clock);
$maintenance = $gate->enter($scope['operator_id'], 'testing', 'BACKUP', 1);
Assert::true($maintenance->isOk(), 'manifest can only be built under quiesced maintenance');

$operation = $gate->beginOperation($maintenance->value()['maintenance_id'], $scope['operator_id'], 'BACKUP_EXPORT');
Assert::true($operation->isOk(), 'backup operation is pinned before manifest construction');
$manifest = new BackupManifest($db, $clock, $root, str_repeat('a', 40));
Assert::same(
    'BACKUP_EXECUTION_GRANTS_STILL_ADMITTED',
    $manifest->build($root . '/dump.sql', $root . '/package')->reasonCode(),
    'future-dated admitted grant prevents a supposedly inert backup',
);
$nativeOps = new NativeOperations(
    $db,
    $clock,
    $futureFx['grants'],
    $futureFx['approvals'],
    $futureFx['decisions'],
    X::FINGERPRINT,
    $futureFx['decision_dir'],
);
$stoodDown = $nativeOps->standDown($futureFx['scope'], 'suspend', 'BACKUP_QUIESCENCE', null, null);
Assert::true($stoodDown->isOk(), 'deployment stand-down discovers a future admitted grant without naming it individually');
Assert::same('SUSPENDED', $futureFx['grants']->status($futureGrant['execution_grant_id']), 'future grant is inert after stand-down');
$built = $manifest->build($root . '/dump.sql', $root . '/package');
$builtReason = $built->isOk() ? 'OK' : $built->reasonCode();
Assert::true($built->isOk(), 'backup manifest packages dump, referenced blobs and request-log prefix: ' . $builtReason);
if (!$built->isOk()) {
    return;
}
$value = $built->value()['manifest'];
Assert::same(str_repeat('a', 40), $value['source']['release_ref'], 'manifest binds the exact release identity supplied by the deployment');
Assert::true($manifest->verifyRuntimeIdentity($value)->isOk(), 'restore verifier accepts the exact backup release identity');
$wrongReleaseManifest = $value;
$wrongReleaseManifest['source']['release_ref'] = str_repeat('b', 40);
Assert::same('RESTORE_RELEASE_REF_MISMATCH', $manifest->verifyRuntimeIdentity($wrongReleaseManifest)->reasonCode(), 'restore refuses a different application release before import');
Assert::same($operation->value()['operation_id'], $value['operation']['operation_id'], 'manifest binds the exact BACKUP_EXPORT operation that pinned the snapshot');
$manifestJson = json_encode($value, JSON_THROW_ON_ERROR);
Assert::false(str_contains($manifestJson, $bytes), 'manifest never embeds private retained artifact bytes');
Assert::false(str_contains($manifestJson, 'synthetic-offline-fixture-key'), 'manifest never embeds provider credential plaintext from the execution fixture');
Assert::false(str_contains($manifestJson, 'DB_PASSWORD'), 'manifest does not carry secret configuration names/values');
Assert::true($value['semantic_state']['counts']['decision_records'] > 0, 'manifest records populated M10 state');
Assert::true($value['semantic_state']['counts']['monitor_runs'] > 0, 'manifest records populated Monitor state');
Assert::true($value['semantic_state']['retained_output_count'] > 0, 'manifest proves at least one retained output can be rendered');
Assert::true(is_string($value['semantic_state']['retained_output_digest']) && strlen($value['semantic_state']['retained_output_digest']) === 64, 'manifest commits to the rendered retained-output read models');
Assert::true(count($value['source']['migration_state']) > 0, 'manifest carries the exact migration ledger rows');
Assert::true(count($value['source']['trigger_definitions']) > 0, 'manifest carries the exact trigger definitions, not only a count');
Assert::true(($value['data_state']['tables']['decision_records']['count'] ?? 0) > 0, 'manifest commits to populated database rows without exporting their contents');
Assert::true(($value['data_state']['tables']['monitor_run_events']['count'] ?? 0) > 0, 'data-state digest includes Monitor journal rows');
$artifactDigests = array_column($value['artifacts'], 'digest');
sort($artifactDigests, SORT_STRING);
$expectedArtifactDigests = [$digest, $futureProfileDigest];
sort($expectedArtifactDigests, SORT_STRING);
Assert::equalsList($expectedArtifactDigests, $artifactDigests, 'only the exact governed private artifact set is copied, including the future-grant profile reference');
Assert::true(BackupManifest::verifyPackage($root . '/package')->isOk(), 'fresh package verifies byte-for-byte');
$manifestPath = $root . '/package/' . BackupManifest::FILE;
$manifestBytes = (string) file_get_contents($manifestPath);
$tamperedManifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
$tamperedManifest['source']['server_version'] = 'DB_PASSWORD=must-never-be-here';
file_put_contents($manifestPath, json_encode($tamperedManifest, JSON_THROW_ON_ERROR));
Assert::same('BACKUP_MANIFEST_SECRET_PATTERN_DETECTED', BackupManifest::verifyPackage($root . '/package')->reasonCode(), 'restore refuses a manifest carrying an obvious live-secret pattern');
file_put_contents($manifestPath, $manifestBytes);
Assert::true(BackupManifest::verifyPackage($root . '/package')->isOk(), 'restoring the exact manifest bytes restores package validity');

file_put_contents($root . '/package/undeclared.txt', 'not part of the backup contract');
Assert::same('BACKUP_PACKAGE_UNDECLARED_CONTENT', BackupManifest::verifyPackage($root . '/package')->reasonCode(), 'undeclared package files are refused');
unlink($root . '/package/undeclared.txt');
Assert::true(BackupManifest::verifyPackage($root . '/package')->isOk(), 'removing undeclared content restores package validity');

$artifactPath = sprintf(
    '%s/package/evidence/sha256/%s/%s/%s',
    $root,
    substr($digest, 0, 2),
    substr($digest, 2, 2),
    $digest,
);
file_put_contents($artifactPath, 'corrupt');
Assert::same('BACKUP_ARTIFACT_INTEGRITY_FAILED', BackupManifest::verifyPackage($root . '/package')->reasonCode(), 'corrupt private artifact fails package verification');
file_put_contents($artifactPath, $bytes);
Assert::true(BackupManifest::verifyPackage($root . '/package')->isOk(), 'restoring exact artifact bytes restores package validity');

$prefixPath = $root . '/package/' . BackupManifest::REQUEST_LOG_PREFIX;
$prefix = file_get_contents($prefixPath);
file_put_contents($prefixPath, (string) $prefix . 'tamper');
Assert::same('BACKUP_REQUEST_LOG_PREFIX_INTEGRITY_FAILED', BackupManifest::verifyPackage($root . '/package')->reasonCode(), 'request-log prefix tamper is detected');
file_put_contents($prefixPath, (string) $prefix);
Assert::true(BackupManifest::verifyPackage($root . '/package')->isOk(), 'exact request-log prefix verifies again');

// Exercise the complete restore verifier over the populated state. This uses the same test DB but
// substitutes only the SOURCE identity so the verifier reaches its M10/Monitor/artifact/semantic
// checks; the native restore script is what proves a genuinely separate empty database in operation.
$restoredStorage = $root . '/restored-storage';
mkdir($restoredStorage . '/evidence', 0750, true);
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    foreach (array_diff(scandir($from) ?: [], ['.','..']) as $child) {
        $src = $from . DIRECTORY_SEPARATOR . $child;
        $dst = $to . DIRECTORY_SEPARATOR . $child;
        if (is_dir($src)) {
            mkdir($dst, 0750, true);
            $copyTree($src, $dst);
        } else {
            copy($src, $dst);
        }
    }
};
$copyTree($root . '/package/evidence', $restoredStorage . '/evidence');
copy($root . '/package/' . BackupManifest::REQUEST_LOG_PREFIX, $restoredStorage . '/request.log');

$simulatedRestored = $value;
$simulatedRestored['source']['database_identity_sha256'] = str_repeat('0', 64);
$verified = $manifest->verifyRestoredDatabase($simulatedRestored, $restoredStorage);
Assert::true($verified->isOk(), 'populated retained state passes the complete restore verifier');
Assert::same(true, $verified->value()['native_execution_requires_separate_re_admission'], 'verified restore remains execution-inert by contract');
Assert::true($verified->value()['retained_outputs_rendered'] > 0, 'restore verifier actually materialized retained output read models');


// Corrupt the M10 HEAD pointer, then the Monitor run head, one at a time. These are deliberately
// fields whose tables permit operational head advancement; verification must detect that the stored
// head no longer agrees with the immutable append-only chain beneath it.
$originalM10Head = (string) $db->scalar(
    'SELECT current_head_chain_hash FROM decision_ledger_streams WHERE decision_ledger_stream_id=?',
    [$streamId],
);
$db->run(
    'UPDATE decision_ledger_streams SET current_head_chain_hash=? WHERE decision_ledger_stream_id=?',
    [str_repeat('0', 64), $streamId],
);
Assert::same(
    'RESTORE_M10_CHAIN_INVALID',
    $manifest->verifyRestoredDatabase($simulatedRestored, $restoredStorage)->reasonCode(),
    'a corrupt M10 chain/head is a failed restore',
);
$db->run(
    'UPDATE decision_ledger_streams SET current_head_chain_hash=? WHERE decision_ledger_stream_id=?',
    [$originalM10Head, $streamId],
);

$monitorId = $run->value()['monitor_run_id'];
$originalMonitorHead = (string) $db->scalar(
    'SELECT event_digest FROM monitor_runs WHERE monitor_run_id=?',
    [$monitorId],
);
$db->run(
    'UPDATE monitor_runs SET event_digest=? WHERE monitor_run_id=?',
    [str_repeat('f', 64), $monitorId],
);
Assert::same(
    'RESTORE_MONITOR_CHAIN_INVALID',
    $manifest->verifyRestoredDatabase($simulatedRestored, $restoredStorage)->reasonCode(),
    'a corrupt Monitor journal head is a failed restore',
);
$db->run(
    'UPDATE monitor_runs SET event_digest=? WHERE monitor_run_id=?',
    [$originalMonitorHead, $monitorId],
);

file_put_contents($restoredStorage . '/request.log', "tampered\n");
Assert::same('RESTORE_REQUEST_LOG_PREFIX_MISMATCH', $manifest->verifyRestoredDatabase($simulatedRestored, $restoredStorage)->reasonCode(), 'restored request-log prefix is part of full restore verification');
copy($root . '/package/' . BackupManifest::REQUEST_LOG_PREFIX, $restoredStorage . '/request.log');

// A rehearsal pointed at the source database is categorically not a restore.
$same = $manifest->verifyRestoredDatabase($value, $restoredStorage);
Assert::same('RESTORE_TARGET_EQUALS_SOURCE', $same->reasonCode(), 'restore verifier refuses the runtime/source database identity');

Assert::true($gate->endOperation($operation->value()['operation_id'], $scope['operator_id'])->isOk(), 'fixture backup operation ends cleanly');
Assert::true($gate->leave($maintenance->value()['maintenance_id'], $scope['operator_id'])->isOk(), 'fixture maintenance leaves cleanly');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.','..']) as $child) {
            $remove($path . DIRECTORY_SEPARATOR . $child);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$remove($root);
