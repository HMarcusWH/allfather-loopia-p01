<?php

declare(strict_types=1);

/**
 * Verify a rollback or a stand-down actually took effect. Read-only, and structurally so.
 *
 * WHY A VERIFIER RATHER THAN A ROLLBACK COMMAND. A rollback in this system is never a delete — the
 * storage refuses one, because a rollback that destroyed the record would also destroy the proof
 * the rollback happened. What an application rollback IS is a code restoration: previous release,
 * matching Operator Console manifest, same database, same governed history. None of that is
 * automatable safely from inside the application it is restoring, and all of it is checkable
 * afterwards. So the procedure is `ops/rollback/README.md` and this command is the part that says
 * whether it worked.
 *
 * IT MAKES NO WRITE OF ANY KIND. No table, no file, no incident closed, no flag set. The most
 * dangerous thing a verifier could do is resolve the situation it was asked to describe: an
 * operator reading a green result needs to know it is a measurement and not an action taken on
 * their behalf.
 *
 * WHAT IT INSPECTS, all of it safe to print:
 *
 *   the release the deployment believes it is running;
 *   the lifecycle and session state of ONE explicitly named test account;
 *   the scheduler's inert posture;
 *   the live-read posture, from the evidence registry rather than from a feature flag;
 *   M10 stream integrity, recomputed rather than read from a status column.
 *
 * The last one is the one that matters most after a restore. `decision_ledger_streams.status` is a
 * column somebody could have written; `verifyStream` walks the chain and recomputes it, which is
 * the only thing that can tell a restored ledger from a plausible-looking one.
 */

// CLI ONLY, refused BEFORE anything is loaded. This command reads account state and governed
// history; over the web it would be a lifecycle oracle for any address somebody guessed.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Jobs\SchedulerAuth;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'verify-stand-down: ' . $message . PHP_EOL);
    exit($code);
};

/**
 * Usage:
 *   php ops/rollback/verify-stand-down.php --expect-release=<40-character git SHA> [--env=path]
 *   php ops/rollback/verify-stand-down.php --expect-release=<sha> --email=<test account>
 *
 * `--email` is OPTIONAL and names ONE account. There is deliberately no "check every account": a
 * verifier that enumerated logins would be a lifecycle oracle for the whole deployment, and what an
 * operator needs after a restore is the state of the test account they are about to sign in with.
 */
$args = BetaCli::parseArgs($argv, ['expect-release', 'email', 'env'], $fail);

$expectRelease = $args['expect-release'] ?? '';
if (preg_match('/^[0-9a-f]{40}$/D', $expectRelease) !== 1) {
    $fail('--expect-release must be the full 40-character lowercase git SHA of the release that should now be live');
}

$root = dirname(__DIR__, 2);
$configuration = BetaCli::configuration($root, $args, $fail);
$env = $configuration['env'];
$environment = $configuration['environment'];

/** @var list<string> $findings */
$findings = [];
$note = static function (string $finding) use (&$findings): void {
    $findings[] = $finding;
};

// ============================================================ the release that is live

$runningRelease = $env->get('APP_RELEASE_REF');
if ($runningRelease === null) {
    $note('APP_RELEASE_REF is not set — the deployment cannot say which release it is running');
} elseif ($runningRelease !== $expectRelease) {
    $note('APP_RELEASE_REF is not the release the rollback was meant to restore');
}

// The Operator Console manifest has to match the RESTORED code, not the one that was rolled back
// from. A restored application tree beside a newer staged bundle is the failure mode that looks
// entirely healthy until a browser executes a bundle the server-rendered document does not expect.
$manifestPath = $root . '/public_html/assets/operator-console/manifest.json';
$consoleManifest = ['present' => is_file($manifestPath), 'script' => null, 'valid' => false];
if (!$consoleManifest['present']) {
    $note('the Operator Console manifest is absent — /console/app answers 503 until it is restored');
} else {
    try {
        $decoded = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && is_string($decoded['entry']['script'] ?? null)) {
            $consoleManifest['script'] = $decoded['entry']['script'];
            $consoleManifest['valid'] = true;
        } else {
            $note('the Operator Console manifest does not name an entry script');
        }
    } catch (\JsonException) {
        $note('the Operator Console manifest is not valid JSON');
    }
}

// ============================================================ the scheduler is still inert

// TWO SEPARATE FACTS. The registry being empty is a property of the restored CODE; the credential
// being absent is a property of the restored CONFIGURATION. A rollback can get one right and the
// other wrong, and the combination that matters is both.
$tick = (string) @file_get_contents($root . '/worker/tick.php');
$schedulerEntry = (string) @file_get_contents($root . '/public_html/scheduler.php');
$emptyRegistry = str_contains($tick, 'new SchedulerRunner(new JobMachine($db, new Clock()), [])')
    && str_contains($schedulerEntry, 'new SchedulerRunner(new JobMachine($db, $clock), [])');
if (!$emptyRegistry) {
    $note('a composition root no longer constructs the scheduler with an EMPTY handler registry');
}
// THE DOOR IS ASKED, NOT DESCRIBED. Presenting an empty token to the real verifier answers what a
// caller would actually get, rather than restating a reason code this file would then have to keep
// in step with `SchedulerAuth`. With neither key configured the answer is
// `SCHEDULER_CREDENTIAL_NOT_CONFIGURED`: unreachable, which is not the same as open.
$emptyCredentialAttempt = SchedulerAuth::verify($env, '');
$schedulerPosture = [
    'handler_registry_empty' => $emptyRegistry,
    'endpoint_post_only' => str_contains($schedulerEntry, "!== 'POST'"),
    'credential_configured' => $env->has('SCHEDULER_TOKEN') || $env->has('SCHEDULER_TOKEN_HASH'),
    'unauthenticated_call_authorized' => $emptyCredentialAttempt['authorized'],
    'unauthenticated_call_reason_code' => $emptyCredentialAttempt['reason_code'],
];
if ($emptyCredentialAttempt['authorized'] === true) {
    $note('the scheduler endpoint authorizes a caller presenting no token');
}

// ============================================================ the live-read posture

// FROM THE EVIDENCE REGISTRY, not from a feature flag. "Live read is off" is a claim about what
// happened, and the thing that can answer it is the record of what happened.
$lock = [];
try {
    $decoded = json_decode((string) @file_get_contents($root . '/upstream.lock.json'), true, 64, JSON_THROW_ON_ERROR);
    $lock = is_array($decoded) ? $decoded : [];
} catch (\JsonException) {
    $note('upstream.lock.json is not valid JSON');
}
$claimBoundary = is_array($lock['claim_boundary'] ?? null) ? $lock['claim_boundary'] : [];
$registry = DeploymentEvidence::loadFile($root . '/deployment-evidence.json', [
    'projection_id' => (string) ($lock['projection_id'] ?? ''),
    'canonical_pin' => (string) ($lock['projection_pinned_head'] ?? ''),
]);
foreach ($registry->problems() as $problem) {
    $note('deployment evidence: ' . $problem);
}
foreach ($registry->unsupportedClaims($claimBoundary) as $claim) {
    $note("claim_boundary.{$claim} is asserted with no evidence supporting it");
}
$supported = $registry->supportedClaims();

// ============================================================ the named test account

$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));

$accountState = null;
if (($args['email'] ?? '') !== '' && ($args['email'] ?? '') !== '1') {
    $account = $accounts->findByEmailAddress((string) $args['email']);
    if ($account === null) {
        $accountState = ['found' => false];
        $note('the named test account does not exist in this deployment');
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

// ============================================================ M10 stream integrity

// RECOMPUTED, never read from `decision_ledger_streams.status`. That column is something somebody
// could have written; the chain is not. After a restore this is the difference between a ledger
// that verifies and one that merely says it does.
$ledger = new DecisionLedger($db, $clock);
$streams = $db->all(
    "SELECT decision_ledger_stream_id, environment_class, status, head_sequence_number
     FROM decision_ledger_streams
     WHERE status = 'ACTIVE'
     ORDER BY decision_ledger_stream_id"
);
$integrity = ['streams_checked' => 0, 'passed' => 0, 'failed' => 0, 'records_verified' => 0, 'failing_streams' => []];
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
    // THE STREAM ID AND THE REASON CODES, never a record payload. A verifier that printed what a
    // failing record contained would export governed content into a terminal at the exact moment
    // somebody is pasting output into an incident thread.
    $integrity['failing_streams'][] = [
        'decision_ledger_stream_id' => $streamId,
        'environment_class' => (string) $stream['environment_class'],
        'status' => (string) ($result['status'] ?? 'UNKNOWN'),
        'reason_codes' => array_values(array_unique(array_map(
            static fn (array $failure): string => (string) ($failure['reason_code'] ?? 'UNKNOWN'),
            is_array($result['failures'] ?? null) ? $result['failures'] : [],
        ))),
    ];
    $note("M10 stream {$streamId} does not verify");
}

// ============================================================ verdict

$status = $findings === [] ? 'STAND_DOWN_VERIFIED' : 'STAND_DOWN_NOT_VERIFIED';

BetaCli::emit([
    'verifier' => 'ROLLBACK_STAND_DOWN',
    'status' => $status,
    'environment' => $environment,
    'expected_release_ref' => $expectRelease,
    'running_release_ref' => $runningRelease,
    'operator_console_manifest' => $consoleManifest,
    'scheduler' => $schedulerPosture,
    'live_read_activated_claim' => ($claimBoundary['live_read_activated'] ?? null) === true,
    'live_read_supported_by_evidence' => $supported['live_read_activated'],
    'evidence_supported_claims' => $supported,
    'account' => $accountState,
    'm10_integrity' => $integrity,
    'findings' => $findings,
    // What this command is, restated where the operator reads the result. A rollback is never a
    // delete; governed history survives it, and withdrawal happens through append-only correction.
    'writes_performed' => 0,
    'records_evidence' => false,
    'closes_incidents' => false,
    'deletes_governed_data' => false,
], $status === 'STAND_DOWN_VERIFIED' ? 0 : 1);
