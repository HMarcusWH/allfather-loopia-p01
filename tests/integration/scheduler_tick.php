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

// ============================================================ an INACTIVE tick consumes nothing

// The invariant this section exists for: a capability that is off must not eat the jobs waiting
// for it. An earlier revision claimed every claimable job, failed the ones it had no handler for,
// and burned the retry budget — three ticks and a monitoring job was ABANDONED because live read
// had not been activated. Nobody had asked for that work to be discarded.
$untouchedId = $enqueue('tick-untouched');
$before = $db->one('SELECT state, attempt_count, lease_owner, run_after, updated_at FROM jobs WHERE job_id = ?', [$untouchedId]);

$emptyRegistry = new SchedulerRunner($machine, []);
$idle = $emptyRegistry->tick(10, 20_000);

Assert::same(0, $idle['claimed'], 'a tick with an empty registry claims NOTHING');
Assert::same(0, $idle['advanced'], 'advances nothing');
Assert::same(0, $idle['failed'], 'and fails nothing — an inactive capability is not a failure');
Assert::same(0, $idle['released'], 'with nothing to hand back, because nothing was taken');
Assert::same(
    SchedulerRunner::INACTIVE_REASON,
    $idle['stopped_reason'],
    'the tick reports itself INACTIVE — idle by configuration, not for want of work',
);
Assert::same([], $idle['executable_states'], 'and names the empty executable set that made it so');

// THE ROW IS BYTE-FOR-BYTE UNTOUCHED. Not "left in a reasonable state" — untouched.
$after = $db->one('SELECT state, attempt_count, lease_owner, run_after, updated_at FROM jobs WHERE job_id = ?', [$untouchedId]);
Assert::same($before, $after, 'and the waiting job is completely unchanged — state, attempts, lease, backoff, timestamp');
Assert::same(0, (int) $after['attempt_count'], 'no attempt was consumed');
Assert::same(null, $after['lease_owner'], 'no lease was taken');
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM job_transitions WHERE job_id = ? AND from_state IS NOT NULL', [$untouchedId]),
    'and no transition was written — the ledger is silent because nothing happened',
);

// Repeated ticks do not accumulate damage either. A scheduler firing every fifteen minutes against
// an inactive capability must still leave the job claimable on the day it is switched on.
for ($i = 0; $i < 5; $i++) {
    $emptyRegistry->tick(10, 20_000);
}
Assert::same(
    0,
    (int) $db->scalar('SELECT attempt_count FROM jobs WHERE job_id = ?', [$untouchedId]),
    'five more inactive ticks still consume no attempt — the retry budget is intact',
);
Assert::same(
    'PENDING',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$untouchedId]),
    'and the job is still PENDING, waiting for the capability rather than abandoned by it',
);

// ============================================================ a PARTIAL registry claims only its own

// The rule is per STATE, not per tick: a deployment with the internal steps wired and the live-read
// step not wired must run the internal ones and leave the others completely alone.
$pendingId = $enqueue('partial-pending');
$preflightId = $enqueue('partial-preflight');
$advanceOne = new SchedulerRunner($machine, [
    'PENDING' => static fn (array $job): array => ['to_state' => 'PREFLIGHT', 'reason_code' => 'STEP_OK'],
]);
$advanceOne->tick(10, 20_000);
Assert::same(
    'PREFLIGHT',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$preflightId]),
    'both PENDING jobs advance while PENDING is the executable state',
);

// Now only PREFLIGHT is wired. The PENDING job enqueued next must be untouched.
$stillPendingId = $enqueue('partial-still-pending');
$preflightBefore = (int) $db->scalar('SELECT attempt_count FROM jobs WHERE job_id = ?', [$stillPendingId]);
$partial = (new SchedulerRunner($machine, [
    'PREFLIGHT' => static fn (array $job): array => ['to_state' => 'FETCH', 'reason_code' => 'STEP_OK'],
]))->tick(10, 20_000);

Assert::same(['PREFLIGHT'], $partial['executable_states'], 'the tick names PREFLIGHT as its only executable state');
Assert::true($partial['advanced'] >= 1, 'and advances the PREFLIGHT work');
Assert::same(
    'PENDING',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$stillPendingId]),
    'while a PENDING job is not claimed at all — the bound is per state, not per tick',
);
Assert::same(
    $preflightBefore,
    (int) $db->scalar('SELECT attempt_count FROM jobs WHERE job_id = ?', [$stillPendingId]),
    'and consumes none of its attempts',
);

// A caller cannot widen the executable set. `claim` intersects whatever it is given with its own
// claimable states, so naming a state the machine does not consider claimable buys nothing — and
// the probe is aimed at a job that IS in one of those states, or it would prove nothing.
$terminalId = $enqueue('terminal-probe-subject');
// The state is FIXTURE, not the behaviour under test, and is set directly: driving it through the
// machine would spend the job's whole attempt budget on setup and prove nothing about the probe.
$db->run("UPDATE jobs SET state = 'VISIBLE' WHERE job_id = ?", [$terminalId]);
Assert::same(
    'VISIBLE',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$terminalId]),
    'a job is driven to the terminal VISIBLE state, so the widening probe has a real target',
);

$widened = $machine->claim('probe-owner', 100, 300, ['VISIBLE', 'ABANDONED', 'NOT_A_STATE']);
$widenedIds = array_map(static fn (array $j): string => (string) $j['job_id'], $widened);
Assert::false(
    in_array($terminalId, $widenedIds, true),
    'naming a TERMINAL state claims nothing — the intersection is with the machine\'s own claimable set',
);
Assert::same([], $widened, 'and nothing else is claimed by inventing state names either');
Assert::same(
    null,
    $db->scalar('SELECT lease_owner FROM jobs WHERE job_id = ?', [$terminalId]),
    'the terminal job took no lease from the probe',
);
Assert::same(
    null,
    $db->scalar('SELECT lease_owner FROM jobs WHERE job_id = ?', [$stillPendingId]),
    'and that probe took no lease on anything',
);

// A reversed claim costs no attempt. Reached directly, because the runner's claim is bounded by its
// registry and cannot hand itself a job it has no handler for.
$reverseId = $enqueue('reverse-claim');
$reverseClaimed = $machine->claim('reversal-owner', 100, 300, ['PENDING']);
$reverseJob = null;
foreach ($reverseClaimed as $candidate) {
    if ((string) $candidate['job_id'] === $reverseId) {
        $reverseJob = $candidate;
    }
}
if ($reverseJob === null) {
    Assert::fail('the reversal fixture job was not claimed');
} else {
    Assert::same(1, (int) $reverseJob['attempt_count'], 'a claim consumes an attempt while it is held');
    Assert::true($machine->releaseClaim($reverseId, 'reversal-owner')->isOk(), 'and the claim can be reversed');
    $reversed = $db->one('SELECT attempt_count, lease_owner FROM jobs WHERE job_id = ?', [$reverseId]);
    Assert::same(0, (int) $reversed['attempt_count'], 'which gives the attempt back — the job was never tried');
    Assert::same(null, $reversed['lease_owner'], 'and releases the lease');
    Assert::same(
        'JOB_LEASE_LOST',
        $machine->releaseClaim($reverseId, 'reversal-owner')->reasonCode(),
        'reversing twice is refused — the second call does not drive the count below the truth',
    );
}

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
// A registry that CAN run the work, because the count bound is only observable once something is
// claimable at all — an empty registry now claims nothing by design, which is a different property.
$countable = static fn (): SchedulerRunner => new SchedulerRunner($machine, [
    'PENDING' => static fn (array $job): array => ['to_state' => 'PREFLIGHT', 'reason_code' => 'STEP_OK'],
]);
$bounded = $countable()->tick(3, 20_000);
Assert::same(3, $bounded['claimed'], 'a tick claims no more than it was asked for');

// The ceiling only means something when there is more work than it allows, so there is: enough
// eligible jobs that an uncapped tick would take more than the cap, and the tick still takes
// exactly the cap.
for ($i = 0; $i < 60; $i++) {
    $enqueue("ceiling-{$i}");
}
$eligible = (int) $db->scalar(
    "SELECT COUNT(*) FROM jobs WHERE state = 'PENDING' AND lease_owner IS NULL AND run_after <= ?",
    [$clock->now()]
);
Assert::true(
    $eligible > SchedulerRunner::MAX_JOBS_PER_TICK,
    'there is more eligible work than one tick may take, so the ceiling has something to bind',
);
$capped = $countable()->tick(10_000, 20_000);
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
$overlapHandlers = ['PENDING' => static fn (array $job): array => ['to_state' => 'PREFLIGHT', 'reason_code' => 'STEP_OK']];
$runnerA = new SchedulerRunner($machine, $overlapHandlers);
$runnerB = new SchedulerRunner($machine, $overlapHandlers);
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
