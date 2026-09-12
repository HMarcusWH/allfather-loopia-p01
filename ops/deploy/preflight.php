<?php

declare(strict_types=1);

/**
 * Release preflight. Validates a release BEFORE it is copied anywhere.
 *
 * THIS IS NOT A DEPLOYER. It opens no connection to the host, copies nothing, changes nothing, and
 * has no `--apply`. The copy is still an operator running `rsync`, deliberately: automating it
 * would remove the one moment where somebody looks at what is about to be sent.
 *
 * WHAT IT IS FOR. The deployment runbook is a sequence of things that must be true, and every one
 * of them is a thing somebody can forget — the release ref in `.env` left over from the last
 * deployment, the Operator Console staged from a build two commits old, a scheduler token filled in
 * on a host that is meant to be inert, an evidence claim asserted in the lock without a record. All
 * of those produce a deployment that looks healthy and is wrong, and all of them are cheap to check
 * before the copy and expensive to discover after it.
 *
 * EVERY PROBLEM, NOT THE FIRST. An operator fixing a release wants the whole list. A preflight that
 * stopped at the first fault would turn one pass into five.
 *
 * IT PRINTS NO SECRET. `APP_MASTER_KEY` is validated by DECODING it and reported as SET or MISSING;
 * the value, its length and its prefix never appear. The same for the database password.
 */

// CLI ONLY, refused BEFORE anything is loaded. This command reads the deployment's private
// configuration; reachable over the web it would be a configuration oracle.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Provider\SecretBox;
use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Support\Env;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'preflight: ' . $message . PHP_EOL);
    exit($code);
};

/**
 * Usage:
 *   php ops/deploy/preflight.php --env=<path to the private staging .env> \
 *                                --release-ref=<40-character git SHA> \
 *                                --mode=staging
 *
 * `--mode=staging` is the only mode. A production mode is deliberately absent: production
 * deployment is not authorized, the pinned canonical identity vocabulary defines no production
 * tenant/workspace/environment class, and a preflight that offered the mode would be the first
 * half of a deployment path nobody has granted. See docs/LOOPIA_CAPABILITY_REPORT.md §5.
 */
$args = BetaCli::parseArgs($argv, ['env', 'release-ref', 'mode'], $fail);

$mode = $args['mode'] ?? '';
if ($mode !== 'staging') {
    $fail('--mode=staging is required; it is the only supported mode (production is not authorized)');
}
$releaseRef = $args['release-ref'] ?? '';
if (preg_match('/^[0-9a-f]{40}$/D', $releaseRef) !== 1) {
    // THE FULL SHA. An abbreviation is ambiguous the day the repository grows a colliding prefix,
    // and a release identity is the wrong place to discover that.
    $fail('--release-ref must be a full 40-character lowercase git SHA');
}
$envPath = $args['env'] ?? '';
if ($envPath === '' || $envPath === '1') {
    $fail('--env=<path> is required — the private configuration this release will run with');
}
if (!is_file($envPath)) {
    $fail("no such configuration file: {$envPath}", 4);
}

$root = dirname(__DIR__, 2);
$env = Env::load($envPath);

/** @var list<string> $problems */
$problems = [];
$note = static function (string $problem) use (&$problems): void {
    $problems[] = $problem;
};

// ============================================================ configuration

$environment = Bootstrap::resolveEnvironment($env->get('APP_ENV'));
if ($environment !== 'staging') {
    // An unrecognised APP_ENV resolves to `production`, which is stricter and therefore safe at
    // boot — but it is not what a staging release is supposed to be running as, and the difference
    // decides which identity vocabulary provisioning may use.
    $note("APP_ENV resolves to '{$environment}', not 'staging'");
}

$baseUrl = $env->get('APP_BASE_URL');
if ($baseUrl === null) {
    $note('APP_BASE_URL is not set');
} else {
    $parts = parse_url($baseUrl);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
        $note('APP_BASE_URL must be an https:// URL');
    }
    if (($parts['host'] ?? '') === '') {
        $note('APP_BASE_URL names no host');
    }
    if (isset($parts['query']) || isset($parts['fragment'])) {
        $note('APP_BASE_URL carries a query or fragment');
    }
    if (str_ends_with($baseUrl, '/')) {
        $note('APP_BASE_URL must not end in a slash — paths are appended to it verbatim');
    }
}

$configuredRelease = $env->get('APP_RELEASE_REF');
if ($configuredRelease === null) {
    $note('APP_RELEASE_REF is not set — the support page would show no release');
} elseif ($configuredRelease !== $releaseRef) {
    // The commonest wrong-but-healthy-looking deployment: new code, last deployment's release ref.
    // A partner then quotes a reference that resolves to a build nobody deployed.
    $note('APP_RELEASE_REF does not match --release-ref');
}

foreach (['DB_NAME', 'DB_USER'] as $key) {
    if (!$env->has($key)) {
        $note("{$key} is not set");
    }
}

$masterKeyState = 'MISSING';
if (!$env->has('APP_MASTER_KEY')) {
    $note('APP_MASTER_KEY is not set — a strict environment refuses to start without it');
} else {
    try {
        // DECODED, not merely present. A key that is present but 31 bytes is a configuration error
        // a deployment should meet here rather than at the first credential it tries to seal.
        SecretBox::fromMasterKey((string) $env->get('APP_MASTER_KEY'));
        $masterKeyState = 'SET';
    } catch (\RuntimeException) {
        $masterKeyState = 'INVALID';
        $note('APP_MASTER_KEY does not decode to 32 bytes');
    }
}

if (!$env->has('SUPPORT_CONTACT_TEXT')) {
    // PR7's support page renders what an operator wrote, and deliberately invents no default. A
    // beta deployment without one is a support page that tells a partner to contact nobody.
    $note('SUPPORT_CONTACT_TEXT is not set — the support page would name no route to a human');
}

// INERT STAGING HAS NO SCHEDULER CREDENTIAL. With neither key set the tick endpoint is unreachable
// rather than open, which is the posture a deployment with an empty handler registry should have.
// A token configured here would be a live door to a machine that has nothing to run.
foreach (['SCHEDULER_TOKEN', 'SCHEDULER_TOKEN_HASH'] as $key) {
    if ($env->has($key)) {
        $note("{$key} is set — an inert staging deployment leaves the scheduler door unreachable");
    }
}

// ============================================================ the staged Operator Console

$stageDir = $root . '/public_html/assets/operator-console';
$console = ['staged' => false, 'script' => null, 'style' => null, 'assets' => 0];

try {
    $manifest = (new ConsoleAssetManifest($stageDir))->load();
    $console = [
        'staged' => true,
        'script' => basename($manifest['script']),
        'style' => basename($manifest['style']),
        'assets' => count($manifest['assets']),
    ];

    // `ConsoleAssetManifest` proves every manifest path is local, hashed, present and not
    // symlinked. What it does not ask — because at runtime it does not need to — is whether
    // anything ELSE is in the directory. A stale bundle from a previous build, or a source map, is
    // served if it is there, and the manifest would never mention it.
    //
    // The expected set is derived from the manifest rather than re-declared, so this cannot drift
    // from `frontend/console/stage-assets.mjs`, which enforces the same equality on the build side.
    $prefix = '/assets/operator-console/';
    $expected = ['manifest.json'];
    foreach ([$manifest['script'], $manifest['style'], ...$manifest['assets']] as $url) {
        $expected[] = substr($url, strlen($prefix));
    }
    sort($expected, SORT_STRING);

    $actual = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($stageDir) + 1));
        if ($entry->isLink()) {
            $note("staged Operator Console contains a symlink: {$relative}");
            continue;
        }
        if (!$entry->isFile()) {
            $note("staged Operator Console contains a non-file entry: {$relative}");
            continue;
        }
        $actual[] = $relative;
    }
    sort($actual, SORT_STRING);

    foreach (array_diff($actual, $expected) as $unmanifested) {
        $note("staged Operator Console carries a file the manifest does not name: {$unmanifested}");
    }
    foreach (array_diff($expected, $actual) as $missing) {
        $note("staged Operator Console is missing a manifest file: {$missing}");
    }
    foreach ($actual as $relative) {
        if (str_ends_with($relative, '.map')) {
            $note("staged Operator Console carries a source map: {$relative}");
        }
    }

    // A DEVELOPMENT BUNDLE IS NOT A HASH PROBLEM, so the hashed-name rules would not catch it. Two
    // markers that only a development build leaves behind: a source-map comment, and React's
    // development build, which ships the full warning machinery and is several times the size.
    $scriptPath = $stageDir . '/' . substr($manifest['script'], strlen($prefix));
    if (is_file($scriptPath)) {
        $source = (string) file_get_contents($scriptPath);
        if (str_contains($source, 'sourceMappingURL')) {
            $note('the staged Operator Console script references a source map');
        }
        if (str_contains($source, 'react-dom.development') || str_contains($source, 'react.development')) {
            $note('the staged Operator Console script is a development React build');
        }
    }
} catch (\RuntimeException $error) {
    $note('the Operator Console stage is unusable: ' . $error->getMessage());
}

// ============================================================ the served layout

$served = array_values(array_diff(scandir($root . '/public_html') ?: [], ['.', '..']));
sort($served, SORT_STRING);
if ($served !== ['.htaccess', 'assets', 'index.php', 'scheduler.php']) {
    $note('public_html/ holds something other than the two entry points, assets/ and .htaccess: '
        . implode(', ', $served));
}
foreach (['app', 'database', 'docs', 'frontend', 'ops', 'storage', 'tests', 'worker'] as $private) {
    if (!is_dir($root . '/' . $private)) {
        $note("{$private}/ is missing from the release tree");
    }
    if (is_dir($root . '/public_html/' . $private)) {
        $note("{$private}/ is INSIDE the served directory — it would be reachable over the web");
    }
}
foreach (['.env', '.env.testing', 'composer.json', 'deployment-evidence.json', 'upstream.lock.json'] as $private) {
    if (is_file($root . '/public_html/' . $private)) {
        $note("public_html/{$private} exists — configuration and governance files are never served");
    }
}

// ============================================================ authority and evidence posture

$lockPath = $root . '/upstream.lock.json';
$lock = [];
try {
    $decoded = json_decode((string) file_get_contents($lockPath), true, 64, JSON_THROW_ON_ERROR);
    $lock = is_array($decoded) ? $decoded : [];
} catch (\JsonException) {
    $note('upstream.lock.json is not valid JSON');
}
$claimBoundary = is_array($lock['claim_boundary'] ?? null) ? $lock['claim_boundary'] : [];

foreach (DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS as $key) {
    if (($claimBoundary[$key] ?? null) !== false) {
        $note("claim_boundary.{$key} is not false — this release may not be deployed");
    }
}
if (($claimBoundary['no_external_charge_executed'] ?? null) !== true) {
    $note('claim_boundary.no_external_charge_executed is not true');
}
// AN INERT STAGING COPY IS NOT A LIVE-READ DEPLOYMENT. Copying a release whose lock already claimed
// live read would be copying a claim nobody could satisfy from an empty handler registry.
if (($claimBoundary['live_read_activated'] ?? null) !== false) {
    $note('claim_boundary.live_read_activated is not false — this is an INERT staging copy');
}

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

// ============================================================ the scheduler is inert

$tick = (string) file_get_contents($root . '/worker/tick.php');
$schedulerEntry = (string) file_get_contents($root . '/public_html/scheduler.php');
foreach (['worker/tick.php' => $tick, 'public_html/scheduler.php' => $schedulerEntry] as $where => $source) {
    if (!str_contains($source, 'new SchedulerRunner(new JobMachine($db, $clock), [])')
        && !str_contains($source, 'new SchedulerRunner(new JobMachine($db, new Clock()), [])')
    ) {
        $note("{$where} does not construct the runner with an EMPTY handler registry");
    }
}
if (!str_contains($schedulerEntry, "!== 'POST'")) {
    $note('public_html/scheduler.php is no longer POST-only');
}

// ============================================================ no external action is wired

// The structural claim, re-checked at the moment of deployment rather than trusted from a test run
// on somebody else's machine. `tests/foundation/no_external_action_surface.php` asserts the same
// thing on every commit; this asks it of the tree that is about to be copied.
$wired = [];
foreach (['app', 'public_html', 'worker', 'ops'] as $dir) {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace($root . '/', '', $file->getPathname());
        if (str_contains($relative, 'Ingestion/CurlHttpsTransport.php')
            || str_contains($relative, 'Ingestion/DnsHostResolver.php')
        ) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('/new\s+(\\\\?[A-Za-z\\\\]*\\\\)?(CurlHttpsTransport|DnsHostResolver)\s*\(/', $source) === 1) {
            $wired[] = $relative;
        }
    }
}
foreach ($wired as $file) {
    $note("{$file} constructs the live-read transport — live read is unwired, not merely flagged off");
}

// ============================================================ is this tree the named release?

// ADVISORY, because `.git` is not part of a deployment and this command is expected to run both
// from a checkout (where it can answer) and on a host (where it cannot). Answering NOT_AVAILABLE is
// honest; refusing would make the command unusable in half the places it belongs.
$gitHead = 'NOT_AVAILABLE';
$headFile = $root . '/.git/HEAD';
if (is_file($headFile)) {
    $head = trim((string) file_get_contents($headFile));
    if (preg_match('/^[0-9a-f]{40}$/D', $head) === 1) {
        $gitHead = $head;
    } elseif (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        $refFile = $root . '/.git/' . $ref;
        if (is_file($refFile)) {
            $gitHead = trim((string) file_get_contents($refFile));
        } else {
            $packed = @file_get_contents($root . '/.git/packed-refs');
            if (is_string($packed)
                && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) === 1
            ) {
                $gitHead = $m[1];
            }
        }
    }
}
if ($gitHead !== 'NOT_AVAILABLE' && $gitHead !== $releaseRef) {
    $note("the checkout is at {$gitHead}, not the release being preflighted");
}

// ============================================================ verdict

$status = $problems === [] ? 'READY_FOR_INERT_STAGING_COPY' : 'NOT_READY';

BetaCli::emit([
    'status' => $status,
    'mode' => $mode,
    'release_ref' => $releaseRef,
    'environment' => $environment,
    'base_url' => $baseUrl,
    'git_head' => $gitHead,
    'problems' => $problems,
    'operator_console' => $console,
    'app_master_key' => $masterKeyState,
    'db_password' => $env->has('DB_PASSWORD') ? 'SET' : 'MISSING',
    // The posture this release carries, restated as the answer to the question an operator is about
    // to act on. All four are properties of the code being copied, not settings of this command.
    'live_read' => false,
    'scheduler_handlers' => false,
    'external_action' => false,
    'customer_visible_output' => false,
    'production_launch_authorized' => false,
    // And what a PASS does and does not mean.
    'authorizes_deployment' => false,
    'copies_anything' => false,
], $status === 'READY_FOR_INERT_STAGING_COPY' ? 0 : 1);
