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
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Jobs\SchedulerAuth;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Ops\SourceFingerprint;
use Allfather\Loopia\Ops\StandDownPosture;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'verify-stand-down: ' . $message . PHP_EOL);
    exit($code);
};

/**
 * Usage:
 *   php ops/rollback/verify-stand-down.php --expect-release=<40-character git SHA> \
 *                                          --expect-fingerprint=<64-character sha256> [--env=path]
 *   php ops/rollback/verify-stand-down.php --expect-release=<sha> --email=<test account>
 *
 * `--expect-fingerprint` is the `source_fingerprint` that `ops/deploy/preflight.php` printed for
 * the release being restored. It is what makes the release answer a MEASUREMENT rather than a
 * restatement of `.env`; omitting it is permitted and produces a finding, because a verifier that
 * quietly accepted the configuration value would be reporting the operator's intention back to them.
 *
 * `--email` is OPTIONAL and names ONE account. There is deliberately no "check every account": a
 * verifier that enumerated logins would be a lifecycle oracle for the whole deployment, and what an
 * operator needs after a restore is the state of the test account they are about to sign in with.
 */
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

// OBSERVED HERE, DECIDED IN `StandDownPosture`. This file opens a database, reads a lock and walks
// a ledger — none of which is testable without a host, which is exactly how an earlier revision
// managed to compute the scheduler posture, print it, and let none of it reach the verdict. The
// observations are gathered below and the rule is applied once, at the end, by code every
// combination of which is exercised in `tests/rollback/stand_down_verifier.php`.
/** @var array<string, mixed> $observed */
$observed = ['expected_release' => $expectRelease];

// ============================================================ the release that is live

$runningRelease = $env->get('APP_RELEASE_REF');
$observed['running_release'] = $runningRelease;

// WHAT IS RUNNING, MEASURED. `APP_RELEASE_REF` is a line an operator maintains by hand, and a
// rollback is two steps: restore the code, update the configuration. If the restore fails or is
// skipped while the configuration step succeeds, that value equals the release you asked for and
// the OLD CODE IS STILL SERVING — with a valid console manifest from the previous build and an
// unchanged scheduler posture, so every other signal looks healthy. The verifier would then agree
// with the operator about something neither of them had checked.
//
// So the tree is hashed. `--expect-fingerprint` is the digest `ops/deploy/preflight.php` printed
// for the release being restored, and comparing the two answers what is on disk rather than what
// `.env` claims. Omitting it is allowed and is itself a finding: the release claim is then
// configuration only, and saying so is the honest verdict.
$deployedFingerprint = SourceFingerprint::compute($root);
$observed['expected_fingerprint'] = $expectFingerprint === '' || $expectFingerprint === '1'
    ? null
    : $expectFingerprint;
$observed['deployed_fingerprint'] = $deployedFingerprint;

// The Operator Console manifest has to match the RESTORED code, not the one that was rolled back
// from. A restored application tree beside a newer staged bundle is the failure mode that looks
// entirely healthy until a browser executes a bundle the server-rendered document does not expect.
// THE RUNTIME'S OWN VALIDATOR, not a weaker restatement of it.
//
// An earlier revision decoded the JSON here and accepted any object with a string at `entry.script`.
// That passes a manifest naming an UNHASHED or ABSENT script, one whose stylesheet key is gone, one
// with no `assets` list, and one at an unsupported schema version — every case `ConsoleAssetManifest`
// refuses at runtime. So `/console/app` would answer 503 while this command, whose entire job is to
// establish that the restored tree is serviceable, returned `STAND_DOWN_VERIFIED`. A verifier that
// is more permissive than the thing it verifies reports a rollback as complete when it is not.
//
// Loading it through the runtime class also checks the referenced FILES: local, content-hashed, not
// symlinked, and present on disk. A partial or stale rollback is precisely a manifest that survived
// while the bundle it names did not.
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
        // The validator's messages name the fault and never a path outside the stage, so the
        // message is what an operator needs; it is the same sentence the runtime would log.
        $consoleManifest['fault'] = $error->getMessage();
    }
}
$observed['console_manifest_present'] = $consoleManifest['present'];
$observed['console_manifest_valid'] = $consoleManifest['valid'];

// ============================================================ the scheduler is still inert

// TWO SEPARATE FACTS. The registry being empty is a property of the restored CODE; the credential
// being absent is a property of the restored CONFIGURATION. A rollback can get one right and the
// other wrong, and the combination that matters is both.
$tick = (string) @file_get_contents($root . '/worker/tick.php');
$schedulerEntry = (string) @file_get_contents($root . '/public_html/scheduler.php');
$emptyRegistry = str_contains($tick, 'new SchedulerRunner(new JobMachine($db, new Clock()), [])')
    && str_contains($schedulerEntry, 'new SchedulerRunner(new JobMachine($db, $clock), [])');
// THE DOOR IS ASKED, NOT DESCRIBED. Presenting an empty token to the real verifier answers what a
// caller would actually get, rather than restating a reason code this file would then have to keep
// in step with `SchedulerAuth`. With neither key configured the answer is
// `SCHEDULER_CREDENTIAL_NOT_CONFIGURED`: unreachable, which is not the same as open.
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
// EVERY ONE OF THESE IS A FINDING, not a field — decided in `StandDownPosture`, where each
// combination is exercised without a host. An earlier revision computed this posture and printed it
// without letting any of it reach the verdict, so a deployment with a configured scheduler token
// and an endpoint that had lost its POST-only guard was reported `STAND_DOWN_VERIFIED` with both
// facts visible in the very JSON that said so. A verifier whose output contradicts its own verdict
// is worse than no verifier: the verdict is the part people quote.
$observed['handler_registry_empty'] = $emptyRegistry;
$observed['endpoint_post_only'] = $postOnly;
$observed['credential_configured'] = $credentialConfigured;
$observed['unauthenticated_call_authorized'] = $emptyCredentialAttempt['authorized'] === true;

// ============================================================ the live-read posture

// FROM THE EVIDENCE REGISTRY, not from a feature flag. "Live read is off" is a claim about what
// happened, and the thing that can answer it is the record of what happened.
$lock = [];
try {
    $decoded = json_decode((string) @file_get_contents($root . '/upstream.lock.json'), true, 64, JSON_THROW_ON_ERROR);
    $lock = is_array($decoded) ? $decoded : [];
} catch (\JsonException) {
    // An unreadable lock leaves `claim_boundary` empty, which the evidence registry then reports as
    // a mismatched projection/pin — so the fault surfaces as an evidence problem rather than being
    // swallowed here.
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

// THE POSTURE THIS COMMAND EXISTS TO VERIFY. `unsupportedClaims()` catches a claim asserted WITHOUT
// evidence; it says nothing about a claim that is fully supported — and a supported
// `live_read_activated` is exactly the state a stand-down was performed to end. Reporting it in a
// field while returning STAND_DOWN_VERIFIED would defeat the whole purpose of running this after a
// live-read stand-down.
//
// Both directions are findings, because they are different faults: the lock still asserting it is a
// governance edit nobody made, and the registry still supporting it is a withdrawal that did not
// happen or was never recorded.
$observed['live_read_claimed'] = ($claimBoundary['live_read_activated'] ?? null) === true;
$observed['live_read_supported'] = $supported['live_read_activated'] === true;

// ============================================================ the named test account

$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));

$accountState = null;
if (($args['email'] ?? '') !== '' && ($args['email'] ?? '') !== '1') {
    $account = $accounts->findByEmailAddress((string) $args['email']);
    if ($account === null) {
        // Diagnostic rather than a stand-down fault: the operator named an account this deployment
        // does not have, which they need to know before they try to sign in with it.
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

// ============================================================ M10 stream integrity

// RECOMPUTED, never read from `decision_ledger_streams.status`. That column is something somebody
// could have written; the chain is not. After a restore this is the difference between a ledger
// that verifies and one that merely says it does.
$ledger = new DecisionLedger($db, $clock);
// EVERY STREAM, NOT ONLY THE ACTIVE ONES. A rollback's claim is that governed history SURVIVED it,
// and a SUSPENDED, SEALED or SUPERSEDED stream is history — it is exactly the part nobody is
// writing to and therefore the part whose corruption would go unnoticed. An earlier revision filtered
// to `status = 'ACTIVE'`, which verified only the chains still being appended to: the ones most
// likely to be fine. `verifyStream()` works for any status.
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
    $failingStreamIds[] = $streamId;
}

// ============================================================ verdict

$observed['failing_stream_ids'] = $failingStreamIds;

// ONE RULE, APPLIED ONCE. Everything above observes; nothing above decides.
$findings = StandDownPosture::findings($observed);
$status = $findings === [] ? 'STAND_DOWN_VERIFIED' : 'STAND_DOWN_NOT_VERIFIED';

BetaCli::emit([
    'verifier' => 'ROLLBACK_STAND_DOWN',
    'status' => $status,
    'environment' => $environment,
    'expected_release_ref' => $expectRelease,
    // THE SOURCE OF THE RELEASE CLAIM, stated beside it. CONFIGURATION means the only thing backing
    // it is a line in `.env`; MEASURED means the deployed tree was hashed and matched.
    'release_claim_source' => $observed['expected_fingerprint'] === null ? 'CONFIGURATION' : 'MEASURED',
    'expected_source_fingerprint' => $observed['expected_fingerprint'],
    'deployed_source_fingerprint' => $deployedFingerprint,
    'source_fingerprint_covers' => SourceFingerprint::DIRECTORIES,
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
