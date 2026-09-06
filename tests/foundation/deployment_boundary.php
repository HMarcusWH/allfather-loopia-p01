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
// EVERY private directory is a SIBLING of the served one, and none is inside it. Both halves are
// asserted: "not in public_html" alone would pass for a directory that does not exist at all, and
// then a rename could quietly move one under the served tree with nothing noticing.
foreach (['app', 'database', 'ops', 'worker', 'tests', 'storage', 'config', 'docs'] as $private) {
    Assert::true(
        is_dir($root . '/' . $private),
        "{$private}/ exists as a sibling of the served directory",
    );
    Assert::false(
        is_dir($root . '/public_html/' . $private),
        "and NOT inside it — the application is outside the served tree, not merely excluded from it",
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
