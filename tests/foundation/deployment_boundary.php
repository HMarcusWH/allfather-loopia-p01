<?php

declare(strict_types=1);

/**
 * Structural proof that the served directory contains only what is meant to be served.
 *
 * Loopia serves a domain from a fixed `public_html` root and gives no way to move it, so the
 * security boundary of this deployment is WHICH DIRECTORY gets pointed at — not a rule inside it.
 * `public/` is the only candidate, and the way that boundary breaks in practice is somebody adding
 * one more file to it: a diagnostic script, a phpinfo page, a forgotten backup of a config file.
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

$served = array_values(array_diff(scandir($root . '/public') ?: [], ['.', '..']));
sort($served);
Assert::same(
    ['.htaccess', 'assets', 'index.php', 'scheduler.php'],
    $served,
    'public/ holds exactly the two entry points, the stylesheet directory and its own .htaccess',
);

// The two entry points are the only executable things in there, at any depth. A diagnostic script,
// a phpinfo page or a forgotten config backup under assets/ would be served as code.
$publicPhp = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/public', FilesystemIterator::SKIP_DOTS)
);
foreach ($walk as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
        $publicPhp[] = substr($file->getPathname(), strlen($root . '/public/'));
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
        is_file($root . '/public/' . $mustNotBeServed),
        "public/{$mustNotBeServed} does not exist — configuration is never inside the served directory",
    );
}
foreach (['app', 'database', 'ops', 'worker', 'tests', 'storage', 'config', 'docs'] as $mustStayOutside) {
    Assert::false(
        is_dir($root . '/public/' . $mustStayOutside),
        "public/{$mustStayOutside}/ does not exist — the application stays outside the served directory",
    );
}

// ============================================================ the deny files still deny

// Defence in depth, for the deployment that points a domain at the repository root rather than at
// public/. Every directive is <IfModule>-guarded and is therefore a silent no-op on a host missing
// the module — which is exactly why the layout, not this file, is the boundary.
foreach (['/.htaccess' => 'the repository root', '/storage/.htaccess' => 'runtime state'] as $path => $what) {
    $deny = @file_get_contents($root . $path);
    Assert::true(is_string($deny), "{$what} carries a deny-all .htaccess");
    Assert::contains('Require all denied', (string) $deny, "{$what}: denied under Apache 2.4");
    Assert::contains('Deny from all', (string) $deny, "{$what}: and under the 2.2 compatibility module");
    Assert::contains('Options -Indexes', (string) $deny, "{$what}: with no directory listing either");
}

$publicHt = (string) file_get_contents($root . '/public/.htaccess');
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
