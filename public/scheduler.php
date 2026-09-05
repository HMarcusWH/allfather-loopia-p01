<?php

declare(strict_types=1);

/**
 * The scheduler tick endpoint — a SEPARATE entry point, deliberately.
 *
 * It does not go through the console kernel, and that is the design rather than an oversight. The
 * kernel's CSRF rule is absolute: every non-safe method is checked, by method and never by a route
 * list, because a route list is something a future change can forget to update. A machine caller
 * has no session and no CSRF token, so mounting the tick inside the kernel would mean carving an
 * exemption into that rule — and an exemption is the thing the rule exists to not have.
 *
 * So the scheduler gets its own door with its own single authority. It reads no cookie and sets
 * none, which is also what makes CSRF inapplicable here rather than merely waived: there is no
 * ambient credential for a browser to be tricked into attaching.
 *
 * POST ONLY. A tick advances governed work, and a GET that mutates is a GET any crawler, prefetch
 * or link preview will eventually perform. Loopia's shell cron issues the POST; see the deployment
 * runbook for the exact line.
 */

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Jobs\JobMachine;
use Allfather\Loopia\Jobs\SchedulerAuth;
use Allfather\Loopia\Jobs\SchedulerRunner;
use Allfather\Loopia\Support\Bootstrap;

/** Every response from this endpoint. No HTML, no template, no session. */
$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: no-store, private', true);
    header('X-Content-Type-Options: nosniff', true);
    // A scheduler endpoint is not a browser surface. Nothing here should ever be framed, styled,
    // linked to, or fetched by a page.
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'", true);
    header('Referrer-Policy: no-referrer', true);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $respond(405, ['status' => 'METHOD_NOT_ALLOWED']);

    return;
}

$root = dirname(__DIR__);

try {
    $boot = Bootstrap::load($root);
} catch (\RuntimeException $error) {
    // BEFORE the credential check, deliberately. A deployment that may not start must not first
    // decide whether the caller is the scheduler: the answer would depend on configuration this
    // has just established cannot be trusted. The message names only configuration keys.
    $respond(503, ['status' => 'MISCONFIGURED', 'detail' => $error->getMessage()]);

    return;
}
$env = $boot['env'];
Bootstrap::applyErrorPosture($boot['strict']);

$auth = SchedulerAuth::verify($env, (string) ($_SERVER[SchedulerAuth::SERVER_KEY] ?? ''));
if (!$auth['authorized']) {
    // The reason is returned because an operator wiring the cron entry needs to tell "I sent the
    // wrong token" from "the server has no token configured", and neither answer reveals a
    // credential. It is deliberately NOT a 404: pretending the endpoint does not exist would make
    // a misconfigured scheduler indistinguishable from a deployment that never had one.
    $respond(401, ['status' => 'UNAUTHORIZED', 'reason_code' => $auth['reason_code']]);

    return;
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
} catch (\Throwable) {
    // The reason is deliberately not echoed: a connection error can carry a hostname, a user name
    // or a driver detail, and this caller has proved only that it holds the scheduler token.
    $respond(503, ['status' => 'UNAVAILABLE']);

    return;
}

$clock = new Clock();

// THE HANDLER REGISTRY IS EMPTY, and that is the current state of this projection rather than a
// placeholder. `LIVE_READ_ACTIVATED` is false, so no step that performs an external read may be
// registered; the internal steps are not wired either, because wiring them is a separate act with
// its own review. A claimed job whose state has no handler FAILS by name, so the transition ledger
// records why nothing ran instead of leaving jobs leased and silently going nowhere.
$runner = new SchedulerRunner(new JobMachine($db, $clock), []);

$result = $runner->tick(
    $env->int('SCHEDULER_MAX_JOBS_PER_TICK', 10),
    $env->int('SCHEDULER_BUDGET_MS', 20_000),
    $env->int('SCHEDULER_LEASE_SECONDS', 300),
);

$respond(200, ['status' => 'OK', ...$result]);
