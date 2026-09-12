<?php

declare(strict_types=1);

/**
 * Structural proof that the served directory contains only what is meant to be served.
 *
 * Loopia serves a domain from `<domän>/public_html/` and states it does not support pointing at any
 * other application folder, so the security boundary of this deployment is WHICH DIRECTORY gets
 * pointed at — not a rule inside it. Files ABOVE `public_html/` are reachable over FTP/SSH and not
 * from the web, which is why every private directory here is a SIBLING of the served one rather
 * than a parent of it: `<domain>/` is never itself a document root.
 *
 * The repository layout IS the host layout — that is the point of the directory being named
 * `public_html/` — so these assertions are about the shape a deployment will actually have.
 *
 * The way the boundary breaks in practice is somebody adding one more file to it: a diagnostic
 * script, a phpinfo page, a forgotten backup of a config file.
 *
 * These assertions are STRUCTURAL and this file says so plainly. Apache is not running here, no
 * Loopia host has ever served this code, and a passing `.htaccess` assertion proves the directive
 * is written — never that a web server honoured it. The runbook's verification curls are what
 * establish that, on a real host, after a real deployment. What this file can prove is the part
 * that does not depend on the host: that nothing has crept into the served directory, and that the
 * files whose whole job is to deny access still say so.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);

// ============================================================ the served directory is a closed set

$served = array_values(array_diff(scandir($root . '/public_html') ?: [], ['.', '..']));
sort($served);
Assert::same(
    ['.htaccess', 'assets', 'index.php', 'scheduler.php'],
    $served,
    'public_html/ holds exactly the two entry points, the stylesheet directory and its own .htaccess',
);

// The two entry points are the only executable things in there, at any depth. A diagnostic script,
// a phpinfo page or a forgotten config backup under assets/ would be served as code.
$publicPhp = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/public_html', FilesystemIterator::SKIP_DOTS)
);
foreach ($walk as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
        $publicPhp[] = substr($file->getPathname(), strlen($root . '/public_html/'));
    }
}
sort($publicPhp);
Assert::same(
    ['index.php', 'scheduler.php'],
    $publicPhp,
    'and NO other PHP file exists anywhere beneath it',
);

// Nothing sensitive has been placed there. Each of these lives outside the served directory by
// design, and a copy inside it would be served in full.
foreach (['.env', '.env.testing', '.env.example', 'composer.json'] as $mustNotBeServed) {
    Assert::false(
        is_file($root . '/public_html/' . $mustNotBeServed),
        "public_html/{$mustNotBeServed} does not exist — configuration is never inside the served directory",
    );
}
// EVERY private directory of the port is a SIBLING of the served one, and none is inside it. Both
// halves are asserted: "not in public_html" alone would pass for a directory that does not exist at
// all, and then a rename could quietly move one under the served tree with nothing noticing.
//
// This list is what the REPOSITORY carries. An earlier revision also named `config/`, which this
// port does not have — git carries no empty directory, so the assertion passed on a developer
// machine that happened to have one and failed in every fresh clone. A test that asserts the shape
// of somebody's working copy is worse than no test, because it is green where it matters least.
foreach (['app', 'database', 'ops', 'frontend', 'worker', 'tests', 'storage', 'docs'] as $private) {
    Assert::true(
        is_dir($root . '/' . $private),
        "{$private}/ exists as a sibling of the served directory",
    );
    Assert::false(
        is_dir($root . '/public_html/' . $private),
        "and NOT inside it — the application is outside the served tree, not merely excluded from it",
    );
}
// Names that must never appear under the served tree, whether or not this port has one. `config/`
// is here rather than above: the boundary rule is about where such a directory may live IF it is
// ever added, and that rule holds now, before anyone adds it.
foreach (['config', 'vendor', 'node_modules', '.git'] as $neverServed) {
    Assert::false(
        is_dir($root . '/public_html/' . $neverServed),
        "public_html/{$neverServed} does not exist — it would be reachable over the web",
    );
}

// The entry points reach the application by a path that is stable under the real layout: from
// `<domain>/public_html/index.php`, `../app` is `<domain>/app`. A deployment is a copy into
// `<domain>/`, so the same expression works in the repository and on the host.
foreach (['/public_html/index.php', '/public_html/scheduler.php'] as $entry) {
    Assert::contains(
        "require __DIR__ . '/../app/autoload.php';",
        (string) file_get_contents($root . $entry),
        "{$entry} loads the application from its sibling directory, which is where Loopia puts it",
    );
}
Assert::true(
    is_file($root . '/app/autoload.php'),
    'and that path resolves — public_html/../app/autoload.php is a real file',
);

// ============================================================ the deny files still deny

// Defence in depth, for the deployment that points a domain at the repository root rather than at
// public_html/. Every directive is <IfModule>-guarded and is therefore a silent no-op on a host missing
// the module — which is exactly why the layout, not this file, is the boundary.
foreach (['/.htaccess' => 'the repository root', '/storage/.htaccess' => 'runtime state'] as $path => $what) {
    $deny = @file_get_contents($root . $path);
    Assert::true(is_string($deny), "{$what} carries a deny-all .htaccess");
    Assert::contains('Require all denied', (string) $deny, "{$what}: denied under Apache 2.4");
    Assert::contains('Deny from all', (string) $deny, "{$what}: and under the 2.2 compatibility module");
    Assert::contains('Options -Indexes', (string) $deny, "{$what}: with no directory listing either");
}

$publicHt = (string) file_get_contents($root . '/public_html/.htaccess');
Assert::contains('Options -Indexes', $publicHt, 'the served directory lists no contents');
Assert::contains('-MultiViews', $publicHt, 'and does not negotiate an extension onto a path');
Assert::contains('<FilesMatch "^\.">', $publicHt, 'dotfiles are denied by shape, not by naming .env');
Assert::contains(
    'RewriteRule ^ index.php [L]',
    $publicHt,
    'and every unmatched path is the front controller\'s to answer — including its 404, which '
    . 'carries the security headers the server\'s own error page does not',
);
Assert::contains(
    'RewriteRule ^(assets/.*|index\.php|scheduler\.php)$ - [L]',
    $publicHt,
    'while the two entry points and the stylesheet are served as themselves',
);

// ============================================================ the CLI tick refuses a web SAPI

// The same posture as the deny files, in code rather than configuration: worker/tick.php lives
// outside the document root, but "outside the document root" is a property of a deployment's
// configuration, not of the file — so the file checks too, where no <IfModule> guard can be a
// silent no-op.
$tick = (string) file_get_contents($root . '/worker/tick.php');
Assert::contains(
    "if (PHP_SAPI !== 'cli') {",
    $tick,
    'the shell-cron tick refuses to run under any web SAPI',
);
Assert::true(
    strpos($tick, "PHP_SAPI !== 'cli'") < strpos($tick, 'autoload.php'),
    'and refuses BEFORE it loads the application — a bootstrap that ran first would have already '
    . 'read .env by the time the check happened',
);

// ============================================================ no entry point loads config itself

// The fail-open expression this repository used to carry, in four places:
//
//     Env::load(is_file($root . '/.env') ? $root . '/.env' : $root . '/.env.testing');
//
// Every entry point now goes through `Bootstrap::load`, which refuses rather than falling back. A
// fifth entry point added later would be the way that comes back, so the absence is asserted
// rather than assumed.
// Every PHP entry point OUTSIDE the served tree refuses a web SAPI before it loads anything. The
// directory being private is the primary boundary and is asserted above; this is the second layer,
// for the day a deployment mistake exposes it.
foreach (glob(dirname(__DIR__, 2) . '/ops/*.php') ?: [] as $opsEntry) {
    $source = (string) file_get_contents($opsEntry);
    $name = basename($opsEntry);
    Assert::contains("PHP_SAPI !== 'cli'", $source, "ops/{$name} refuses any web SAPI");
    Assert::true(
        strpos($source, "PHP_SAPI !== 'cli'") < strpos($source, 'require'),
        "ops/{$name} refuses BEFORE it loads anything",
    );
}
foreach (glob(dirname(__DIR__, 2) . '/ops/*/*.php') ?: [] as $opsEntry) {
    $source = (string) file_get_contents($opsEntry);
    $name = basename(dirname($opsEntry)) . '/' . basename($opsEntry);
    Assert::contains("PHP_SAPI !== 'cli'", $source, "ops/{$name} refuses any web SAPI");
    Assert::true(
        strpos($source, "PHP_SAPI !== 'cli'") < strpos($source, 'require'),
        "ops/{$name} refuses BEFORE it loads anything",
    );
}

foreach (['/public_html/index.php', '/public_html/scheduler.php', '/worker/tick.php', '/ops/migrate.php'] as $entry) {
    $body = (string) file_get_contents($root . $entry);
    Assert::contains(
        'Bootstrap::',
        $body,
        "{$entry} resolves its configuration through the fail-closed bootstrap",
    );
    Assert::false(
        str_contains($body, "'/.env' : ") || str_contains($body, "'/.env.testing')"),
        "{$entry} carries no inline fallback from .env to the test file beside it",
    );
}

// ============================================================ provisioning and Sources stay aligned

// A deployment projection may adapt PHP/MariaDB/Loopia seams; it may not invent production identity
// vocabulary that canonical does not define. The provisioner used to carry dead branches for
// CUSTOMER / PRODUCTION that could only fail later at a CHECK constraint. Refuse that target BEFORE
// opening the database, and pin the canonical TEST vocabulary that staging/testing actually use.
$provisioner = (string) file_get_contents($root . '/ops/provision-staging.php');
$productionGate = strpos($provisioner, "if (\$environment === 'production') {");
$databaseConnect = strpos($provisioner, 'Database::connect(');
Assert::true(
    is_int($productionGate) && is_int($databaseConnect) && $productionGate < $databaseConnect,
    'production provisioning is refused before DB access because its identity vocabulary is not canonical',
);
Assert::contains(
    "'tenant_type' => 'TEST'",
    $provisioner,
    'supported provisioning uses canonical TEST tenant vocabulary',
);
Assert::contains(
    "'workspace_type' => 'TEST'",
    $provisioner,
    'supported provisioning uses canonical TEST workspace vocabulary',
);
Assert::contains(
    "'environment_class' => 'TEST'",
    $provisioner,
    'supported provisioning uses canonical TEST environment vocabulary',
);
Assert::contains(
    "\$available = ['P01', 'P02', 'P03', 'P04', 'P05'];",
    $provisioner,
    'the Ops composer exposes the five selected product registrations, including P02',
);
Assert::contains(
    'registerEntityTwinCurator($scope)',
    $provisioner,
    'P02 is actually provisioned through its governed registration rather than merely named',
);

$identitySchema = (string) file_get_contents($root . '/database/migrations/001_identity_scope.sql');
Assert::contains(
    "CHECK (tenant_type IN ('INTERNAL_DEMO_OWNER', 'TEST', 'FUTURE_RESERVED'))",
    $identitySchema,
    'tenant identity vocabulary remains the pinned canonical set',
);
Assert::contains(
    "CHECK (workspace_type IN ('DEMO', 'INTERNAL', 'TEST'))",
    $identitySchema,
    'workspace identity vocabulary remains the pinned canonical set',
);
Assert::contains(
    "CHECK (environment_class IN ('LOCAL_DEV', 'TEST', 'DEMO'))",
    $identitySchema,
    'workspace environment vocabulary remains the pinned canonical set',
);

// The launch-facing Sources document must not lag the code it describes. Count the migration files
// rather than baking today's number into the test, and explicitly reject the two P02 statements that
// survived from the pre-lifecycle build.
$capabilityReport = (string) file_get_contents($root . '/docs/LOOPIA_CAPABILITY_REPORT.md');
$migrationCount = count(glob($root . '/database/migrations/*.sql') ?: []);
Assert::contains(
    sprintf('**Storage** — %d migrations', $migrationCount),
    $capabilityReport,
    'the capability report states the migration count that actually exists',
);
Assert::contains(
    sprintf('| schema migrations | complete, %d files, idempotent |', $migrationCount),
    $capabilityReport,
    'the operational-readiness table uses the same real migration count',
);
Assert::false(
    str_contains($capabilityReport, 'P02 is refused'),
    'the launch-facing report no longer claims the provisioner refuses P02',
);
Assert::false(
    str_contains($capabilityReport, 'no governed lifecycle past a recorded version'),
    'the launch-facing report no longer describes the pre-lifecycle P02 implementation',
);

// ---------------------------------------------------------------- the host model, in the code

// Loopia's SSH page is explicit that recurring jobs go through the Kundzon scheduler rather than a
// crontab. An earlier revision of this port claimed shell cron in three places; each was a comment,
// which is why none of them failed anything. This is the assertion that would have.
foreach (glob(dirname(__DIR__, 2) . '/{worker,public_html,ops}/*.php', GLOB_BRACE) ?: [] as $file) {
    $source = (string) file_get_contents($file);
    $name = basename(dirname($file)) . '/' . basename($file);
    foreach (["Loopia's shell cron", "Loopia's crontab", 'Loopia shell cron'] as $claim) {
        Assert::false(
            stripos($source, $claim) !== false,
            "{$name} does not claim Loopia has a shell cron",
        );
    }
}

// And the blocker is RECORDED rather than resolved by weakening the endpoint. A scheduler door
// that quietly started accepting GET would be the cheap way to close a gap that is not ours to
// close, so the POST-only check and the recorded blocker are asserted together.
$schedulerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/scheduler.php');
Assert::contains(
    "!== 'POST'",
    $schedulerSource,
    'the scheduler endpoint is POST only',
);
Assert::contains(
    'LOOPIA_SCHEDULER_TRANSPORT_BLOCKER',
    (string) file_get_contents(dirname(__DIR__, 2) . '/docs/LOOPIA_HOST_MODEL.md'),
    'and the unresolved question about the Kundzon scheduler is recorded as a named blocker',
);

// ---------------------------------------------------------------- the web-PHP cross-site isolation gate

// This check deliberately DOES NOT add assertion-count drift to the conformance matrix: the
// repository publishes measured assertion totals, and this policy is a structural prerequisite
// over launch documentation rather than a new product behaviour. A violation throws and the runner
// records the file as failed; a clean policy adds no synthetic PASS count.
$betaRunbook = (string) file_get_contents($root . '/docs/BETA_RUNBOOK.md');
$hostModel = (string) file_get_contents($root . '/docs/LOOPIA_HOST_MODEL.md');
$securityBoundary = (string) file_get_contents($root . '/docs/SECURITY_BOUNDARY.md');
foreach ([
    'BETA_RUNBOOK.md' => $betaRunbook,
    'LOOPIA_HOST_MODEL.md' => $hostModel,
    'SECURITY_BOUNDARY.md' => $securityBoundary,
] as $name => $document) {
    foreach ([
        'LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER',
        'WEB_PHP_ISOLATION_REQUIRED_BEFORE_SECRETS',
        'WEB_PHP_ISOLATION_WAIVER_ALLOWED = false',
    ] as $requiredPolicy) {
        if (!str_contains($document, $requiredPolicy)) {
            throw new RuntimeException("{$name} lost the non-waivable web-PHP isolation policy: {$requiredPolicy}");
        }
    }
}
foreach ([
    '**The owner accepts the risk, explicitly**',
    'The third route is a decision',
    'explicit recorded owner decision to accept the risk',
] as $oldEscapeHatch) {
    if (str_contains($betaRunbook, $oldEscapeHatch)) {
        throw new RuntimeException("BETA_RUNBOOK.md restored the forbidden web-PHP isolation waiver: {$oldEscapeHatch}");
    }
}
if (preg_match('/There are exactly two\s+accepted closure routes/', $betaRunbook) !== 1) {
    throw new RuntimeException('BETA_RUNBOOK.md must keep exactly two accepted web-PHP isolation closure routes');
}
$isolationCanary = (string) file_get_contents($root . '/ops/loopia-probe/isolation-canary.php');
foreach (["'identity_measured' => 'SSH_CLI'", "'web_php_cross_site_isolation' => 'UNVERIFIED'"] as $boundedClaim) {
    if (!str_contains($isolationCanary, $boundedClaim)) {
        throw new RuntimeException("the CLI canary widened its claim beyond the identity it measures: {$boundedClaim}");
    }
}

// ---------------------------------------------------------------- the web surface, as classified

// `PORT_SCOPE_MATRIX.md` classifies P02 and P03 as RUNTIME_IMPLEMENTED /
// WEB_SURFACE_NOT_INCLUDED: their gated read models exist and are tested, and no console screen
// renders them. That is a UX scope decision, and it is only honest while it stays TRUE — a screen
// added later without updating the classification would leave the document describing a product
// that no longer matches, which is the failure this file exists to catch elsewhere too.
$surfaceController = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/Http/Controllers/SurfaceController.php'
);
foreach (['P01\\Surfacing' => true, 'P04\\Release' => true, 'P05\\Release' => true] as $served => $_) {
    Assert::contains(
        $served,
        $surfaceController,
        "the console serves {$served}",
    );
}
foreach (['P02\\TwinRelease', 'P02\\RelationshipRelease', 'P03\\Release'] as $notServed) {
    Assert::false(
        str_contains($surfaceController, $notServed),
        "{$notServed} has no console screen — classified WEB_SURFACE_NOT_INCLUDED, and still true",
    );
}
