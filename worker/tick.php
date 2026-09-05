<?php

declare(strict_types=1);

/**
 * The scheduler tick, run from a shell cron.
 *
 * The same bounded runner as the HTTP endpoint, reached without the network. Where Loopia's
 * crontab is available this is the better door: the authority is filesystem access to `.env`
 * rather than a token travelling over the wire, so there is no credential to rotate, leak or
 * misconfigure.
 *
 * It refuses to run from a web request. This file lives outside the document root, but a
 * deployment that mapped the wrong directory would otherwise expose an unauthenticated tick — and
 * "outside the document root" is a property of a configuration file, not of this code.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    return;
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Jobs\JobMachine;
use Allfather\Loopia\Jobs\SchedulerRunner;
use Allfather\Loopia\Support\Env;

$root = dirname(__DIR__);
$env = Env::load(is_file($root . '/.env') ? $root . '/.env' : $root . '/.env.testing');

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
    // A cron writes to a log, not to a caller, so the reason IS useful here — and this output never
    // reaches an unauthenticated reader. It still names no credential: `Database::connect` does not
    // put the password in its message.
    fwrite(STDERR, 'scheduler: database unavailable: ' . $error->getMessage() . "\n");
    exit(1);
}

// Empty for the same reason as the HTTP entry point: no step handler may perform an external read
// while LIVE_READ_ACTIVATED is false, and the internal steps are a separate act to wire.
$runner = new SchedulerRunner(new JobMachine($db, new Clock()), []);

$result = $runner->tick(
    $env->int('SCHEDULER_MAX_JOBS_PER_TICK', 10),
    $env->int('SCHEDULER_BUDGET_MS', 20_000),
    $env->int('SCHEDULER_LEASE_SECONDS', 300),
);

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
