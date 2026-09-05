<?php

declare(strict_types=1);

/**
 * The bounded scheduler tick, proved against real MariaDB.
 *
 * This is the one surface that drives governed work with no operator behind it, so the properties
 * that matter are the ones that bound it: how much it can do in one fire, what it will accept from
 * the caller, and what happens to a job whose step nothing is registered to perform.
 *
 * The registry is EMPTY in this projection — `LIVE_READ_ACTIVATED` is false, so no step that
 * performs an external read may be registered. That is exercised as a first-class case rather than
 * left as an untested absence: a job whose state has no handler must FAIL by name, because a skip
 * would leave it claimed, leased, and indistinguishable from work in progress.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Jobs\JobMachine;
use Allfather\Loopia\Jobs\SchedulerAuth;
use Allfather\Loopia\Jobs\SchedulerRunner;
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$machine = new JobMachine($db, $clock);

$enqueue = static function (string $key) use ($machine, $scope): string {
    return (string) $machine->enqueue(
        $scope['tenant_id'], $scope['workspace_id'], 'P01_SOURCE_MONITOR',
        'MONITORED_SOURCE', Ids::new(), $key
    )->value()['job_id'];
};

// ============================================================ the credential

// A deployment that forgot to configure the scheduler has one NOBODY can call — never an open one.
$unconfigured = SchedulerAuth::verify(Env::fromArray([]), str_repeat('a', 43));
Assert::false($unconfigured['authorized'], 'with no credential configured, no token is accepted');
Assert::same(
    'SCHEDULER_CREDENTIAL_NOT_CONFIGURED',
    $unconfigured['reason_code'],
    'and the reason distinguishes an unconfigured server from a rejected token',
);

$token = Ids::token(32);
foreach ([
    'the plaintext form' => ['SCHEDULER_TOKEN' => $token],
    'the digest form' => ['SCHEDULER_TOKEN_HASH' => hash('sha256', $token)],
] as $label => $config) {
    $env = Env::fromArray($config);
    Assert::true(SchedulerAuth::verify($env, $token)['authorized'], "the correct token is accepted with {$label}");
    Assert::same(
        'SCHEDULER_TOKEN_REJECTED',
        SchedulerAuth::verify($env, Ids::token(32))['reason_code'],
        "and a different token is rejected with {$label}",
    );
    // The digest is what a `.env` read yields in the preferred form, and it is not presentable:
    // presenting the digest itself must not authenticate.
    Assert::false(
        SchedulerAuth::verify($env, hash('sha256', $token))['authorized'],
        "presenting the DIGEST does not authenticate with {$label} — only the token itself does",
    );
}

// Both forms configured: the DIGEST wins, so a deployment that has rotated to the hash form is not
// still accepting whatever stale plaintext was left in the file beside it.
$rotated = Env::fromArray([
    'SCHEDULER_TOKEN' => 'stale-plaintext-token-left-behind-000000',
    'SCHEDULER_TOKEN_HASH' => hash('sha256', $token),
]);
Assert::true(SchedulerAuth::verify($rotated, $token)['authorized'], 'the digest form is authoritative');
Assert::false(
    SchedulerAuth::verify($rotated, 'stale-plaintext-token-left-behind-000000')['authorized'],
    'and a stale plaintext value left beside it authenticates nothing',
);

// A short or empty presentation is refused before any comparison, and reports its own reason.
foreach (['' => 'empty', 'short' => 'short'] as $presented => $label) {
    Assert::same(
        'SCHEDULER_TOKEN_MISSING_OR_MALFORMED',
        SchedulerAuth::verify(Env::fromArray(['SCHEDULER_TOKEN' => $token]), $presented)['reason_code'],
        "a {$label} token is refused as malformed, not compared",
    );
}

// The credential never reaches a diagnostic surface, in either form.
$snapshot = Env::fromArray([
    'SCHEDULER_TOKEN' => $token,
    'SCHEDULER_TOKEN_HASH' => hash('sha256', $token),
    'APP_ENV' => 'production',
])->redactedSnapshot();
Assert::same('SET', $snapshot['SCHEDULER_TOKEN'], 'the token renders as SET, never as itself');
Assert::same('SET', $snapshot['SCHEDULER_TOKEN_HASH'], 'and so does its digest');
Assert::same('production', $snapshot['APP_ENV'], 'while non-secret configuration renders normally');

// ============================================================ an unhandled step FAILS by name

$unhandledId = $enqueue('tick-unhandled');
$emptyRegistry = new SchedulerRunner($machine, []);
$first = $emptyRegistry->tick(10, 20_000);

Assert::same(1, $first['claimed'], 'the tick claims the eligible job');
Assert::same(0, $first['advanced'], 'and advances nothing — no handler is registered for its state');
Assert::same(1, $first['failed'], 'the job is FAILED rather than skipped');
Assert::same('COMPLETED', $first['stopped_reason'], 'and the tick finishes its batch');

Assert::same(
    'FAILED',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$unhandledId]),
    'the job is left FAILED, not claimed-and-silent',
);
Assert::same(
    SchedulerRunner::NO_HANDLER_REASON,
    (string) $db->scalar('SELECT last_error_code FROM jobs WHERE job_id = ?', [$unhandledId]),
    'and the reason names the missing handler — the honest record of a capability nobody granted',
);
Assert::same(
    null,
    $db->scalar('SELECT lease_owner FROM jobs WHERE job_id = ?', [$unhandledId]),
    'the lease is released, so the job is not held by a tick that has exited',
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM job_transitions WHERE job_id = ? AND reason_code = ?',
        [$unhandledId, SchedulerRunner::NO_HANDLER_REASON]
    ),
    'and the transition ledger records why nothing ran',
);

// ============================================================ a registered handler advances

$handledId = $enqueue('tick-handled');
$seen = [];
$handled = (new SchedulerRunner($machine, [
    'PENDING' => static function (array $job) use (&$seen): array {
        $seen[] = (string) $job['job_id'];

        return ['to_state' => 'PREFLIGHT', 'reason_code' => 'STEP_OK'];
    },
]))->tick(10, 20_000);

Assert::true($handled['advanced'] >= 1, 'a registered handler advances its job');
Assert::true(in_array($handledId, $seen, true), 'and the handler is given the claimed job row itself');
Assert::same(
    'PREFLIGHT',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$handledId]),
    'the job reaches the state the handler named',
);

// A THROWING handler fails its own job and no other. One broken step must not strand the batch —
// and the reason carries the exception CLASS, never a driver message, which can name a host, a
// user or a fragment of a query.
$throwId = $enqueue('tick-throws');
$alsoId = $enqueue('tick-alongside');
$mixed = (new SchedulerRunner($machine, [
    'PENDING' => static function (array $job) use ($throwId): array {
        if ((string) $job['job_id'] === $throwId) {
            throw new \LogicException('the connection to db-primary.internal as root failed');
        }

        return ['to_state' => 'PREFLIGHT'];
    },
]))->tick(10, 20_000);

Assert::true($mixed['failed'] >= 1, 'the throwing job fails');
Assert::true($mixed['advanced'] >= 1, 'and the job alongside it still advances');
Assert::same(
    'PREFLIGHT',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$alsoId]),
    'one broken step does not strand the rest of the batch',
);
Assert::same(
    'STEP_HANDLER_THREW',
    (string) $db->scalar('SELECT last_error_code FROM jobs WHERE job_id = ?', [$throwId]),
    'the failure is named',
);
$detail = (string) $db->scalar(
    "SELECT detail_safe FROM job_transitions WHERE job_id = ? AND reason_code = 'STEP_HANDLER_THREW'",
    [$throwId]
);
Assert::same('LogicException', $detail, 'and the detail is the exception CLASS');
Assert::false(
    str_contains($detail, 'db-primary.internal'),
    'never its message — which can carry a hostname, a user name, or a fragment of a query',
);

// ============================================================ the tick is bounded, two ways

// BY COUNT, and the ceiling is the machine's, not the caller's. A tick asked for more than the hard
// cap gets the cap: the bound is a property of the design, not of whoever fires the scheduler.
for ($i = 0; $i < 8; $i++) {
    $enqueue("bulk-{$i}");
}
$bounded = (new SchedulerRunner($machine, []))->tick(3, 20_000);
Assert::same(3, $bounded['claimed'], 'a tick claims no more than it was asked for');

// The ceiling only means something when there is more work than it allows, so there is: enough
// eligible jobs that an uncapped tick would take more than the cap, and the tick still takes
// exactly the cap.
for ($i = 0; $i < 60; $i++) {
    $enqueue("ceiling-{$i}");
}
$eligible = (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE state = 'PENDING' AND lease_owner IS NULL");
Assert::true(
    $eligible > SchedulerRunner::MAX_JOBS_PER_TICK,
    'there is more eligible work than one tick may take, so the ceiling has something to bind',
);
$capped = (new SchedulerRunner($machine, []))->tick(10_000, 20_000);
Assert::same(
    SchedulerRunner::MAX_JOBS_PER_TICK,
    $capped['claimed'],
    'and a tick takes exactly the hard ceiling, whatever configuration asks for',
);

// BY WALL CLOCK, checked BETWEEN jobs so a tick always stops on a whole job. A budget checked
// mid-step would leave work half done with a live lease, recoverable only by expiry.
for ($i = 0; $i < 6; $i++) {
    $enqueue("budget-{$i}");
}
$slow = (new SchedulerRunner($machine, [
    'PENDING' => static function (array $job): array {
        usleep(3_000);

        return ['to_state' => 'PREFLIGHT'];
    },
]))->tick(10, 1);
Assert::same(
    'BUDGET_EXHAUSTED',
    $slow['stopped_reason'],
    'a tick that runs out of budget stops and says so',
);
Assert::true(
    $slow['advanced'] < $slow['claimed'],
    'having advanced fewer jobs than it claimed — the rest are left for the next tick',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM jobs WHERE lease_owner = ? AND state = 'PENDING' AND lease_expires_at IS NULL",
        [$slow['lease_owner']]
    ),
    'and no job is left in an impossible half-state',
);

// ============================================================ overlapping ticks claim disjoint sets

// Two ticks running at once are SAFE and are not prevented: the claim is a conditional write the
// database arbitrates. A lock that stopped the second would add a way to deadlock the machine in
// exchange for a property the leases already give.
for ($i = 0; $i < 4; $i++) {
    $enqueue("overlap-{$i}");
}
$runnerA = new SchedulerRunner($machine, []);
$runnerB = new SchedulerRunner($machine, []);
$tickA = $runnerA->tick(2, 20_000);
$tickB = $runnerB->tick(2, 20_000);
Assert::notSame($tickA['lease_owner'], $tickB['lease_owner'], 'each tick leases under its OWN identity');
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM job_transitions WHERE lease_owner = ? AND job_id IN (
             SELECT job_id FROM job_transitions WHERE lease_owner = ?
         )',
        [$tickA['lease_owner'], $tickB['lease_owner']]
    ),
    'and no job is touched by both — two ticks claim disjoint sets',
);

// ============================================================ the endpoint accepts no work definition

// STRUCTURAL, and it is the property that keeps the tick from being a remote execution surface
// with a timer attached: `tick()` takes bounds and nothing else. A job id, a job type or a step
// name in its signature would mean the token in front of it was the only thing between the
// internet and arbitrary governed work.
$signature = new ReflectionMethod(SchedulerRunner::class, 'tick');
Assert::same(
    ['maxJobs', 'budgetMs', 'leaseSeconds'],
    array_map(static fn (ReflectionParameter $p): string => $p->getName(), $signature->getParameters()),
    'tick() takes BOUNDS and nothing else — no job id, no job type, no step',
);

$endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/scheduler.php');
Assert::false(
    str_contains($endpoint, '$_GET') || str_contains($endpoint, '$_POST'),
    'and the endpoint reads no request body or query at all — there is nothing for a caller to name',
);
Assert::contains(
    "!== 'POST'",
    $endpoint,
    'the endpoint is POST-only — a GET that mutates is a GET a crawler will eventually perform',
);
Assert::false(
    str_contains($endpoint, '$_COOKIE'),
    'it reads no cookie, so it has no ambient authority for a cross-site request to borrow',
);
