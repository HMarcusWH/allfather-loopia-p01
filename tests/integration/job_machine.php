<?php

declare(strict_types=1);

/**
 * The job machine, proved against real MariaDB.
 *
 * The properties tested here are the ones that make a URL-triggered scheduler safe as a
 * replacement for a persistent worker. Each is a way the Loopia design could silently fail:
 * double-capture, lost work, an unbounded retry loop against a live source, or a stale worker
 * overwriting a newer owner's result.
 *
 * TWO KINDS OF ASSERTION APPEAR BELOW, and the difference is stated rather than blurred.
 *
 *   BEHAVIOURAL, which is most of it: real jobs through the real machine against real MariaDB.
 *   A racing scheduler can be SIMULATED where its effect is a row this test can write itself — a
 *   reclaimed lease, a state moved under an owner — and those cases are exercised directly.
 *
 *   STRUCTURAL, for the two guarantees that live INSIDE one invocation: that the conditional claim
 *   re-checks everything the candidate query asked, and that the retry backoff rides the same write
 *   as the state change. A single-threaded PHP test cannot interleave a second scheduler between
 *   two statements of one method call, and a test that pretended to would be proving its own
 *   fixture rather than the machine. So those are asserted against the statements themselves, and
 *   labelled as what they are.
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

// --- a moved state is not a lost lease, and the refusal says which ---------------------------------

// A REAL INTERLEAVING, not a simulated one. `advance()` reads the current state in one statement
// and writes in another, so the write has to pin that state too — otherwise a second advance under
// the same lease applies a transition checked against a state the job has already left, and records
// a `job_transitions` row whose `from_state` never happened.
//
// The window is reached through the injected CLOCK, which `advance()` reads exactly once, between
// the state read and the transaction. A one-shot hook there runs the racing scheduler's write at
// precisely the moment a second scheduler would — which is a genuine test of the window rather than
// a fixture that describes it.
$racingClock = new class ('2026-01-01T02:00:00.000Z') extends \Allfather\Loopia\Core\Clock {
    /** @var null|callable():void */
    public $onNow = null;

    public function __construct(private readonly string $iso)
    {
    }

    public function now(): string
    {
        if ($this->onNow !== null) {
            $hook = $this->onNow;
            // One shot. A hook that fired on every read would re-run the racing write inside the
            // transaction as well, which is a different race and not the one under test.
            $this->onNow = null;
            $hook();
        }

        return $this->iso;
    }
};
$racingMachine = new JobMachine($db, $racingClock);

$movedId = (string) $reclaimed[1]['job_id'];
Assert::same(
    'PENDING',
    (string) $db->scalar('SELECT state FROM jobs WHERE job_id = ?', [$movedId]),
    'the second reclaimed job is still PENDING, held under scheduler-C\'s lease',
);
$racingClock->onNow = static function () use ($db, $movedId): void {
    // The racing scheduler's write: same lease owner, state moved on.
    $db->run("UPDATE jobs SET state = 'PREFLIGHT' WHERE job_id = ?", [$movedId]);
};

$moved = $racingMachine->advance($movedId, 'scheduler-C', 'PREFLIGHT');
Assert::true($moved->isRefusal(), 'an advance whose from-state moved underneath it is refused');
Assert::same(
    'JOB_STATE_CHANGED',
    $moved->reasonCode(),
    'and NOT as a lost lease — the lease is intact, two callers advanced one job',
);
Assert::same('PENDING', $moved->detail['expected_state'], 'the refusal names the state it expected');
Assert::same('PREFLIGHT', $moved->detail['observed_state'], 'and the state it found instead');
Assert::same(
    'scheduler-C',
    (string) $db->scalar('SELECT lease_owner FROM jobs WHERE job_id = ?', [$movedId]),
    'the lease is left where it was — a refused advance releases nothing',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM job_transitions WHERE job_id = ? AND from_state = 'PENDING' AND to_state = 'PREFLIGHT'",
        [$movedId]
    ),
    'and no transition was recorded for a step that did not happen',
);

// The same window with the LEASE gone instead of the state is still a lost lease, so the two
// refusals stay distinguishable rather than one absorbing the other.
$racingClock->onNow = static function () use ($db, $movedId): void {
    $db->run("UPDATE jobs SET lease_owner = 'scheduler-D' WHERE job_id = ?", [$movedId]);
};
$stolen = $racingMachine->advance($movedId, 'scheduler-C', 'FETCH');
Assert::same(
    'JOB_LEASE_LOST',
    $stolen->reasonCode(),
    'a lease taken inside the same window is still reported as a lost lease',
);

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

// --- STRUCTURAL: the guarantees that live inside one invocation -------------------------------------

$machineSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Jobs/JobMachine.php');

// THE CONDITIONAL CLAIM MUST ASK WHAT THE CANDIDATE QUERY ASKED. Between the SELECT that picks
// candidates and the UPDATE that takes one, another scheduler may have claimed the job, failed it
// and written a backoff — by then it is ineligible for four independent reasons, and a predicate
// that re-checks only the lease would take it anyway: claiming a job whose backoff has not elapsed
// (hammering a source the backoff exists to back away from) and spending an attempt the retry
// budget had already exhausted.
preg_match(
    '/UPDATE jobs\s+SET lease_owner.*?"/s',
    $machineSource,
    $claimUpdate,
);
Assert::true($claimUpdate !== [], 'the conditional claim statement is found');
// Since P07 the state (and, for a typed job, its type) is re-checked through the SAME eligibility
// predicate the candidate SELECT used, so the two statements cannot drift apart. The predicate itself
// must still test state for every branch.
preg_match('/private function eligibility\(.*?\n    \}/s', $machineSource, $eligibilitySource);
Assert::true($eligibilitySource !== [], 'the shared claim eligibility predicate is found');
Assert::same(
    substr_count($eligibilitySource[0] ?? '', "\$clauses[] ="),
    substr_count($eligibilitySource[0] ?? '', 'state IN ('),
    'every eligibility branch — legacy and typed — re-checks the job state',
);
foreach ([
    '({$eligibility})' => 'the state is re-checked, so a job that has since become terminal is not claimed',
    'run_after <= ?' => 'the backoff is re-checked, so a just-failed job is not claimed before it elapses',
    'attempt_count < max_attempts' => 'the attempt budget is re-checked, so a claim cannot spend an exhausted one',
    'lease_expires_at <' => 'and the lease is re-checked, so two schedulers cannot both hold one job',
] as $condition => $because) {
    Assert::contains($condition, $claimUpdate[0], "the claim UPDATE re-checks {$condition}: {$because}");
}

// THE BACKOFF RIDES THE STATE CHANGE. As a follow-up statement it would land after the lease was
// released, on a job another scheduler may already have claimed and moved on — delaying work that
// is in flight, and doing it to whoever owns the job now. `fail()` therefore computes the delay and
// hands it to `advance()`; it must not write `run_after` itself.
preg_match('/public function fail\(.*?\n    \}/s', $machineSource, $failBody);
Assert::true($failBody !== [], 'the fail() body is found');
Assert::false(
    str_contains($failBody[0], 'UPDATE jobs SET run_after'),
    'fail() does not write the backoff in a statement of its own',
);
Assert::contains(
    'run_after = COALESCE(?, run_after)',
    $machineSource,
    'the backoff is applied by the same UPDATE that changes the state',
);
