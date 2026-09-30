<?php

declare(strict_types=1);

/**
 * Verify a rollback or stand-down without mutating the deployment. The command observes; the
 * decision rule lives in StandDownPosture so every contradiction is testable without a host.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Jobs\SchedulerAuth;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Ops\MaintenanceGate;
use Allfather\Loopia\Ops\SourceFingerprint;
use Allfather\Loopia\Ops\StandDownPosture;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'verify-stand-down: ' . $message . PHP_EOL);
    exit($code);
};

$args = BetaCli::parseArgs($argv, ['expect-release', 'expect-fingerprint', 'email', 'env'], $fail);

$expectRelease = $args['expect-release'] ?? '';
if (preg_match('/^[0-9a-f]{40}$/D', $expectRelease) !== 1) {
    $fail('--expect-release must be the full 40-character lowercase git SHA of the release that should now be live');
}
$expectFingerprint = $args['expect-fingerprint'] ?? '';
if ($expectFingerprint !== '' && $expectFingerprint !== '1'
    && preg_match('/^[0-9a-f]{64}$/D', $expectFingerprint) !== 1
) {
    $fail('--expect-fingerprint must be the 64-character lowercase sha256 that preflight printed as source_fingerprint');
}

$root = dirname(__DIR__, 2);
$configuration = BetaCli::configuration($root, $args, $fail);
$env = $configuration['env'];
$environment = $configuration['environment'];

/** @var array<string, mixed> $observed */
$observed = ['expected_release' => $expectRelease];
$maintenanceGate = new MaintenanceGate($root);
$maintenance = $maintenanceGate->status();
$maintenanceOperation = $maintenanceGate->operationStatus();
$observed['maintenance_state'] = (string) ($maintenance['state'] ?? 'INVALID');
$observed['maintenance_operation_state'] = (string) ($maintenanceOperation['state'] ?? 'INVALID');
$observed['maintenance_operation_kind'] = $maintenanceOperation['kind'] ?? null;

$runningRelease = $env->get('APP_RELEASE_REF');
$observed['running_release'] = $runningRelease;
$deployedFingerprint = SourceFingerprint::compute($root);
$observed['expected_fingerprint'] = $expectFingerprint === '' || $expectFingerprint === '1'
    ? null
    : $expectFingerprint;
$observed['deployed_fingerprint'] = $deployedFingerprint;

$manifestPath = $root . '/public_html/assets/operator-console/manifest.json';
$consoleManifest = ['present' => is_file($manifestPath), 'script' => null, 'valid' => false, 'fault' => null];
if ($consoleManifest['present']) {
    try {
        $manifest = (new ConsoleAssetManifest($root . '/public_html/assets/operator-console'))->load();
        $consoleManifest['script'] = basename($manifest['script']);
        $consoleManifest['style'] = basename($manifest['style']);
        $consoleManifest['assets'] = count($manifest['assets']);
        $consoleManifest['valid'] = true;
    } catch (\Throwable $error) {
        $consoleManifest['fault'] = $error->getMessage();
    }
}
$consoleUnexpected = SourceFingerprint::unexpectedConsoleEntries($root);
$observed['console_manifest_present'] = $consoleManifest['present'];
$observed['console_manifest_valid'] = $consoleManifest['valid'];
$observed['console_unexpected_entries'] = $consoleUnexpected;

$tick = (string) @file_get_contents($root . '/worker/tick.php');
$schedulerEntry = (string) @file_get_contents($root . '/public_html/scheduler.php');
$emptyRegistry = str_contains($tick, 'new SchedulerRunner(new JobMachine($db, new Clock()), [])')
    && str_contains($schedulerEntry, 'new SchedulerRunner(new JobMachine($db, $clock), [])');
$emptyCredentialAttempt = SchedulerAuth::verify($env, '');
$postOnly = str_contains($schedulerEntry, "!== 'POST'");
$credentialConfigured = $env->has('SCHEDULER_TOKEN') || $env->has('SCHEDULER_TOKEN_HASH');
$schedulerPosture = [
    'handler_registry_empty' => $emptyRegistry,
    'endpoint_post_only' => $postOnly,
    'credential_configured' => $credentialConfigured,
    'unauthenticated_call_authorized' => $emptyCredentialAttempt['authorized'],
    'unauthenticated_call_reason_code' => $emptyCredentialAttempt['reason_code'],
];
$observed['handler_registry_empty'] = $emptyRegistry;
$observed['endpoint_post_only'] = $postOnly;
$observed['credential_configured'] = $credentialConfigured;
$observed['unauthenticated_call_authorized'] = $emptyCredentialAttempt['authorized'] === true;

$lock = [];
try {
    $decoded = json_decode((string) @file_get_contents($root . '/upstream.lock.json'), true, 64, JSON_THROW_ON_ERROR);
    $lock = is_array($decoded) ? $decoded : [];
} catch (\JsonException) {
    $lock = [];
}
$claimBoundary = is_array($lock['claim_boundary'] ?? null) ? $lock['claim_boundary'] : [];
$registry = DeploymentEvidence::loadFile($root . '/deployment-evidence.json', [
    'projection_id' => (string) ($lock['projection_id'] ?? ''),
    'canonical_pin' => (string) ($lock['projection_pinned_head'] ?? ''),
]);
$supported = $registry->supportedClaims();
$observed['evidence_problems'] = $registry->problems();
$observed['unsupported_claims'] = $registry->unsupportedClaims($claimBoundary);
$observed['live_read_claimed'] = ($claimBoundary['live_read_activated'] ?? null) === true;
$observed['live_read_supported'] = $supported['live_read_activated'] === true;

$db = BetaCli::connect($env, $fail, true);
$clock = new Clock();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));

$accountState = null;
if (($args['email'] ?? '') !== '' && ($args['email'] ?? '') !== '1') {
    $account = $accounts->findByEmailAddress((string) $args['email']);
    if ($account === null) {
        $accountState = ['found' => false];
    } else {
        $inspected = $accounts->inspect((string) $account['platform_user_id']);
        $accountState = [
            'found' => true,
            'status' => $inspected['status'] ?? null,
            'must_change_password' => $inspected['must_change_password'] ?? null,
            'active_sessions' => (int) $db->scalar(
                'SELECT COUNT(*) FROM platform_sessions WHERE platform_user_id = ? AND revoked_at IS NULL',
                [(string) $account['platform_user_id']]
            ),
        ];
    }
}

// Execution grants still ADMITTED anywhere in this runtime database. Read-only.
$decisions = \Allfather\Loopia\Workflow\OwnerDecisionRegistry::forRoot($root);
$grantStore = new \Allfather\Loopia\Workflow\ExecutionGrantStore($db, $clock, new \Allfather\Loopia\PromptOps\PromptApprovalStore($db, $clock, $decisions), $decisions);
$observed['admitted_execution_grants'] = 0;
try {
    foreach ($db->all('SELECT execution_grant_id FROM monitor_execution_grants') as $row) {
        $status = $grantStore->status((string) $row['execution_grant_id']);
        $observed['admitted_execution_grants'] += in_array($status, ['ADMITTED', 'NOT_YET_VALID'], true) ? 1 : 0;
    }
} catch (\PDOException) {
    // A release older than migration 210 has no grants table, and therefore no grant.
}

$ledger = new DecisionLedger($db, $clock);
$streams = $db->all(
    'SELECT decision_ledger_stream_id, environment_class, status, head_sequence_number
     FROM decision_ledger_streams
     ORDER BY decision_ledger_stream_id'
);
$integrity = ['streams_checked' => 0, 'passed' => 0, 'failed' => 0, 'records_verified' => 0, 'failing_streams' => []];
/** @var list<string> $failingStreamIds */
$failingStreamIds = [];
foreach ($streams as $stream) {
    $streamId = (string) $stream['decision_ledger_stream_id'];
    $result = $ledger->verifyStream($streamId);
    $integrity['streams_checked']++;
    $integrity['records_verified'] += (int) ($result['records_verified'] ?? 0);
    if (($result['status'] ?? '') === 'PASS') {
        $integrity['passed']++;
        continue;
    }
    $integrity['failed']++;
    $integrity['failing_streams'][] = [
        'decision_ledger_stream_id' => $streamId,
        'environment_class' => (string) $stream['environment_class'],
        'status' => (string) ($result['status'] ?? 'UNKNOWN'),
        'reason_codes' => array_values(array_unique(array_map(
            static fn (array $failure): string => (string) ($failure['reason_code'] ?? 'UNKNOWN'),
            is_array($result['failures'] ?? null) ? $result['failures'] : [],
        ))),
    ];
    $failingStreamIds[] = $streamId;
}
$observed['failing_stream_ids'] = $failingStreamIds;

$findings = StandDownPosture::findings($observed);
$status = $findings === [] ? 'STAND_DOWN_VERIFIED' : 'STAND_DOWN_NOT_VERIFIED';

BetaCli::emit([
    'verifier' => 'ROLLBACK_STAND_DOWN',
    'status' => $status,
    'environment' => $environment,
    'expected_release_ref' => $expectRelease,
    'release_claim_source' => $observed['expected_fingerprint'] === null ? 'CONFIGURATION' : 'MEASURED',
    'expected_source_fingerprint' => $observed['expected_fingerprint'],
    'deployed_source_fingerprint' => $deployedFingerprint,
    'source_fingerprint_covers' => SourceFingerprint::DIRECTORIES,
    'running_release_ref' => $runningRelease,
    'operator_console_manifest' => $consoleManifest,
    'operator_console_unexpected_entries' => $consoleUnexpected,
    'scheduler' => $schedulerPosture,
    'live_read_activated_claim' => ($claimBoundary['live_read_activated'] ?? null) === true,
    'live_read_supported_by_evidence' => $supported['live_read_activated'],
    'evidence_supported_claims' => $supported,
    'account' => $accountState,
    'm10_integrity' => $integrity,
    'admitted_execution_grants' => $observed['admitted_execution_grants'],
    'maintenance' => $maintenance,
    'maintenance_operation' => $maintenanceOperation,
    'findings' => $findings,
    'writes_performed' => 0,
    'records_evidence' => false,
    'closes_incidents' => false,
    'deletes_governed_data' => false,
], $status === 'STAND_DOWN_VERIFIED' ? 0 : 1);
