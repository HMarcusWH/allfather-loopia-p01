<?php

declare(strict_types=1);

/**
 * The job machine, proved against real MariaDB.
 *
 * The properties tested here are the ones that make a URL-triggered scheduler safe as a
 * replacement for a persistent worker. Each is a way the Loopia design could silently fail:
 * double-capture, lost work, an unbounded retry loop against a live source, or a stale worker
 * overwriting a newer owner's result.
 */

use Allfather\Loopia\Jobs\JobMachine;
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

$subjectId = \Allfather\Loopia\Core\Ids::new();

// --- enqueue is idempotent -----------------------------------------------------------------------

$first = $machine->enqueue(
    $scope['tenant_id'], $scope['workspace_id'], 'P01_SOURCE_MONITOR',
    'MONITORED_SOURCE', $subjectId, 'cycle-2026-01-01'
);
Assert::true($first->isOk(), 'the first enqueue succeeds');
Assert::same(true, $first->value()['created'], 'the first enqueue creates a job');

$second = $machine->enqueue(
    $scope['tenant_id'], $scope['workspace_id'], 'P01_SOURCE_MONITOR',
    'MONITORED_SOURCE', $subjectId, 'cycle-2026-01-01'
);
Assert::same(false, $second->value()['created'], 'a repeated enqueue does NOT create a second job');
Assert::same(
    $first->value()['job_id'],
    $second->value()['job_id'],
    'a repeated enqueue converges on the SAME job (no duplicate capture)',
);
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM jobs'), 'exactly one job row exists');

// A different idempotency key IS a different unit of work.
$third = $machine->enqueue(
    $scope['tenant_id'], $scope['workspace_id'], 'P01_SOURCE_MONITOR',
    'MONITORED_SOURCE', $subjectId, 'cycle-2026-01-02'
);
Assert::same(true, $third->value()['created'], 'a different cycle key is a different job');

// --- claiming is exclusive -------------------------------------------------------------------------

$claimedA = $machine->claim('scheduler-A', 10);
Assert::same(2, count($claimedA), 'scheduler A claims both eligible jobs');

$claimedB = $machine->claim('scheduler-B', 10);
Assert::same(0, count($claimedB), 'scheduler B claims NOTHING while A holds the leases');

// --- the lease bounds the claim ---------------------------------------------------------------------

$expiredClock = Harness::clock('2026-01-01T02:00:00.000Z');   // past the 300 s lease
$laterMachine = new JobMachine($db, $expiredClock);
Assert::same(2, $laterMachine->expiredLeaseCount(), 'both leases are visible as expired');

$reclaimed = $laterMachine->claim('scheduler-C', 10);
Assert::same(2, count($reclaimed), 'an expired lease is reclaimable — dead work is never lost');

// --- a stale owner cannot overwrite the new owner's work ------------------------------------------

$jobId = (string) $reclaimed[0]['job_id'];
$stale = $laterMachine->advance($jobId, 'scheduler-A', 'PREFLIGHT');
Assert::true($stale->isRefusal(), 'the previous lease owner is refused');
Assert::same('JOB_LEASE_LOST', $stale->reasonCode(), 'and the refusal names the concurrency reason');

$fresh = $laterMachine->advance($jobId, 'scheduler-C', 'PREFLIGHT');
Assert::true($fresh->isOk(), 'the current lease owner advances the job');

// --- transitions are typed ---------------------------------------------------------------------------

$reclaimedAgain = $laterMachine->claim('scheduler-C', 10);
$preflightJob = null;
foreach ($reclaimedAgain as $job) {
    if ((string) $job['state'] === 'PREFLIGHT') {
        $preflightJob = $job;
    }
}
Assert::true($preflightJob !== null, 'the PREFLIGHT job is claimable again for its next step');

$illegal = $laterMachine->advance((string) $preflightJob['job_id'], 'scheduler-C', 'RELEASE');
Assert::true($illegal->isRefusal(), 'PREFLIGHT -> RELEASE is not a legal transition');
Assert::same('JOB_ILLEGAL_TRANSITION', $illegal->reasonCode(), 'and it is refused by name');

// --- the transition ledger is complete and append-only -------------------------------------------------

$history = $laterMachine->history($jobId);
Assert::true(count($history) >= 2, 'every transition is recorded');
Assert::same('PENDING', (string) $history[0]['to_state'], 'the ledger starts at PENDING');

try {
    $db->run('DELETE FROM job_transitions WHERE job_id = ?', [$jobId]);
    Assert::fail('the job transition ledger should reject DELETE');
} catch (\PDOException) {
    Assert::pass();
}

// --- retry is bounded -----------------------------------------------------------------------------------

$boundedId = \Allfather\Loopia\Core\Ids::new();
$bounded = $machine->enqueue(
    $scope['tenant_id'], $scope['workspace_id'], 'M15_INGESTION_CAPTURE',
    'INGESTION_SOURCE_POLICY', $boundedId, 'bounded', null, 2
);
$boundedJobId = $bounded->value()['job_id'];

$retryClock = Harness::clock('2026-02-01T00:00:00.000Z');
$retryMachine = new JobMachine($db, $retryClock);

$retryMachine->claim('scheduler-R', 10);
$failed = $retryMachine->fail($boundedJobId, 'scheduler-R', 'TRANSPORT_ERROR');
Assert::true($failed->isOk(), 'recording a failure succeeds — a failure that silently no-ops is worse than none');
$state = (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$boundedJobId]);
Assert::same('FAILED', $state, 'the first failure leaves the job retryable');
Assert::same(
    'TRANSPORT_ERROR',
    (string) $db->scalar('SELECT last_error_code FROM jobs WHERE job_id = ?', [$boundedJobId]),
    'the failure reason code is recorded verbatim, not flattened to a generic error',
);

// The retry is not immediate: the backoff must elapse first.
$immediate = $retryMachine->claim('scheduler-R', 10);
$immediateIds = array_map(static fn (array $j): string => (string) $j['job_id'], $immediate);
Assert::true(
    !in_array($boundedJobId, $immediateIds, true),
    'a just-failed job is NOT retried immediately — the backoff holds it',
);

$afterBackoff = new JobMachine($db, Harness::clock('2026-02-01T00:10:00.000Z'));
$retried = $afterBackoff->claim('scheduler-R', 10);
$retriedIds = array_map(static fn (array $j): string => (string) $j['job_id'], $retried);
Assert::true(in_array($boundedJobId, $retriedIds, true), 'once the backoff elapses the job IS retried');

$exhausted = $afterBackoff->fail($boundedJobId, 'scheduler-R', 'TRANSPORT_ERROR');
Assert::true($exhausted->isOk(), 'recording the exhausting failure succeeds');
$state = (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$boundedJobId]);
Assert::same('ABANDONED', $state, 'the attempt budget is enforced — the job is ABANDONED, not retried forever');

$afterExhaustion = $afterBackoff->claim('scheduler-R', 10);
$stillClaimable = array_filter($afterExhaustion, static fn (array $j): bool => (string) $j['job_id'] === $boundedJobId);
Assert::same(0, count($stillClaimable), 'an ABANDONED job is never claimed again');

// --- the claim is bounded per invocation ------------------------------------------------------------------

$bulkClock = Harness::clock('2026-03-01T00:00:00.000Z');
$bulkMachine = new JobMachine($db, $bulkClock);
for ($i = 0; $i < 12; $i++) {
    $bulkMachine->enqueue(
        $scope['tenant_id'], $scope['workspace_id'], 'M11_AUDIT_RUN',
        'AUDIT_PROFILE', \Allfather\Loopia\Core\Ids::new(), "bulk-{$i}"
    );
}
$batch = $bulkMachine->claim('scheduler-BULK', 5);
Assert::same(5, count($batch), 'a claim never exceeds its per-invocation limit');
