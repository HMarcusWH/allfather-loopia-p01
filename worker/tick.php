<?php

declare(strict_types=1);

/**
 * The scheduler tick, invoked from a shell.
 *
 * The same bounded runner as the HTTP endpoint, reached without the network. THIS IS THE BETTER
 * DOOR: the authority is filesystem access to `.env` rather than a token travelling over the wire,
 * so there is no credential to rotate, leak or misconfigure.
 *
 * LOOPIA HAS NO SHELL CRON. Its support documentation is explicit — recurring jobs go through the
 * Kundzon scheduler, which visits a URL — so on Loopia this door is reached over SSH from a
 * scheduler running elsewhere. That is the recommended arrangement, and `docs/LOOPIA_HOST_MODEL.md`
 * §4 records why, along with the one question about the Kundzon scheduler's HTTP method that is
 * still open. Where a real crontab exists, it can invoke this directly.
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
use Allfather\Loopia\Ops\MaintenanceGate;
use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Workflow\ScheduleDispatcher;

$root = dirname(__DIR__);

$maintenance = (new MaintenanceGate($root))->acquireRuntimeLease();
if (!$maintenance->isOk()) {
    echo json_encode([
        'status' => 'MAINTENANCE',
        'reason_code' => $maintenance->reasonCode(),
        'claimed' => 0,
        'advanced' => 0,
    ], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$maintenanceLease = $maintenance->value()['lease'];

try {
    $boot = Bootstrap::load($root);
} catch (\RuntimeException $error) {
    fwrite(STDERR, 'scheduler: ' . $error->getMessage() . "\n");
    exit(2);
}
$env = $boot['env'];
Bootstrap::applyErrorPosture($boot['strict']);

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

// EMPTY unless this deployment holds an owner-admitted monitor schedule (P07). Without one this is
// exactly the inert runner it always was. With one, the only handler advances MONITOR_STEP jobs —
// capture and compare under that schedule's admitted SCHEDULED_OBSERVATION grant, then a queue for a
// human — through a Monitor composition that has no live model transport at all. Every occurrence is
// re-checked against its own schedule, grant and source at every step; see docs/MONITOR_SCHEDULING.md.
$runner = ScheduleDispatcher::admittedAnywhere($db, new Clock(), $root)
    ? ScheduleDispatcher::runner($db, new Clock(), $root, $env)
    : new SchedulerRunner(new JobMachine($db, new Clock()), []);

$result = $runner->tick(
    $env->int('SCHEDULER_MAX_JOBS_PER_TICK', 10),
    $env->int('SCHEDULER_BUDGET_MS', 20_000),
    $env->int('SCHEDULER_LEASE_SECONDS', 300),
);

// Loopia's scheduler emails the response, so this stays small and carries no credential. An
// INACTIVE tick is a normal, successful, zero-effect run — exit 0.
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
