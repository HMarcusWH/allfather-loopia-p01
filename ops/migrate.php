<?php

declare(strict_types=1);

/**
 * Migration entry point.
 *
 * Usage:  php ops/migrate.php up [--env=path] [--quiet]
 *         php ops/migrate.php status [--env=path]
 *
 * Loopia offers no shell for the production host, so this is also invoked through the deployment
 * runbook's one-shot maintenance route. It never runs implicitly on a web request.
 */

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Support\Env;

$argvCopy = $argv;
array_shift($argvCopy);
$command = $argvCopy[0] ?? 'up';
$envPath = null;
$quiet = false;
foreach ($argvCopy as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    }
    if ($arg === '--quiet') {
        $quiet = true;
    }
}

$root = dirname(__DIR__);

// An EXPLICIT --env is honoured as given: the operator naming a file has said which deployment
// they mean, and second-guessing that would make the flag useless. Everything else goes through
// the same fail-closed rules as the web entry points — including the one that matters most here,
// that a strict environment may not have been configured from the test file. Migrations run
// against whatever database this configuration names, so a silent fallback would mean applying a
// schema change to the wrong one.
if ($envPath !== null) {
    if (!is_file($envPath)) {
        fwrite(STDERR, "no such configuration file: {$envPath}" . PHP_EOL);
        exit(2);
    }
    $env = Env::load($envPath);
    $environment = Bootstrap::resolveEnvironment($env->get('APP_ENV'));
} else {
    try {
        $boot = Bootstrap::load($root);
    } catch (\RuntimeException $error) {
        fwrite(STDERR, 'migrate: ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
    $env = $boot['env'];
    $environment = $boot['environment'];
    $envPath = $boot['source'];
}

if (!$quiet) {
    // Which deployment is about to be changed, before it is changed. A migrator that ran silently
    // against an unnamed target is how a schema change reaches the wrong database.
    fwrite(STDERR, sprintf(
        "migrate: %s using %s (APP_ENV=%s)%s",
        $command,
        basename((string) $envPath),
        $environment,
        PHP_EOL,
    ));
}

try {
    $db = Database::connect(
        $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
        $env->int('DB_PORT', 3306),
        $env->require('DB_NAME'),
        $env->require('DB_USER'),
        $env->get('DB_PASSWORD', '') ?? '',
        $env->get('DB_SOCKET'),
    );
} catch (\Throwable $error) {
    fwrite(STDERR, 'database unreachable: ' . $error->getMessage() . PHP_EOL);
    exit(3);
}

$migrator = new Migrator($db, $root . '/database/migrations');

if ($command === 'status') {
    $migrator->ensureLedger();
    $applied = $migrator->applied();
    foreach ($migrator->available() as $migration) {
        $state = 'PENDING';
        if (isset($applied[$migration['id']])) {
            $state = $applied[$migration['id']]['content_digest'] === $migration['digest'] ? 'APPLIED' : 'DRIFTED';
        }
        printf("%-10s %s\n", $state, $migration['id']);
    }
    exit(0);
}

if ($command !== 'up') {
    fwrite(STDERR, "unknown command: {$command}\n  usage: migrate.php [up|status]\n");
    exit(2);
}

try {
    $result = $migrator->up($quiet ? null : static fn (string $m) => print("  {$m}\n"));
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

printf(
    "applied=%d skipped=%d drifted=%d  (MariaDB %s)\n",
    count($result['applied']),
    count($result['skipped']),
    count($result['drifted']),
    $db->serverVersion(),
);

if ($result['drifted'] !== []) {
    // A migration whose content changed after being applied means the database and the repository
    // disagree about what the schema IS. That is never auto-repaired.
    fwrite(STDERR, "DRIFT: " . implode(', ', $result['drifted']) . PHP_EOL);
    fwrite(STDERR, "The applied content digest no longer matches the file. Resolve deliberately; see DEPLOYMENT_RUNBOOK.md.\n");
    exit(1);
}
