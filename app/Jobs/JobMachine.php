<?php

declare(strict_types=1);

namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * The durable, DB-backed finite-state job machine.
 *
 * Loopia gives no persistent worker, so governed work is driven by bounded scheduler HTTP
 * requests. Each request claims a bounded number of eligible jobs under a lease, advances each by
 * one step, commits, and exits. Nothing here assumes a process that outlives the request.
 *
 * Load-bearing properties:
 *
 *  - **Exclusive claim.** A claim is a conditional `UPDATE ... WHERE lease is free` whose affected
 *    row count decides the winner. Two concurrent schedulers cannot both hold one job, because the
 *    database — not the application — arbitrates.
 *  - **Lease expiry recovers dead work.** A request that dies mid-step (PHP timeout, killed
 *    process) leaves a lease that expires; the next request reclaims the job. No job is lost, and
 *    none is stuck forever.
 *  - **Bounded.** Per-invocation job count and a wall-clock budget are both enforced. The loop
 *    checks the budget between jobs and stops cleanly.
 *  - **Idempotent retry.** `enqueue` converges on the existing job for the same idempotency key
 *    instead of creating a second one, so a re-fired scheduler cannot double-capture or
 *    double-release.
 *  - **Typed states.** Transitions are validated against an explicit map. There is no generic
 *    "run this payload" executor, and the scheduler endpoint accepts no work definition.
 */
final class JobMachine
{
    /**
     * The conceptual source-monitoring lifecycle. Terminal states have no outbound transition.
     *
     * Every non-terminal state can reach FAILED: a step can fail wherever it runs, including
     * before the first one (the subject was revoked, the source policy is gone). Omitting
     * PENDING -> FAILED made `fail()` refuse as an illegal transition and leave the job PENDING —
     * a failure that reported itself and changed nothing, which is the worst of both.
     */
    public const TRANSITIONS = [
        'PENDING' => ['PREFLIGHT', 'FAILED', 'ABANDONED'],
        'PREFLIGHT' => ['FETCH', 'FAILED', 'ABANDONED'],
        'FETCH' => ['CAPTURE', 'FAILED', 'ABANDONED'],
        'CAPTURE' => ['DIFF', 'FAILED', 'ABANDONED'],
        'DIFF' => ['ANALYZE', 'VISIBLE', 'FAILED', 'ABANDONED'],
        'ANALYZE' => ['ADJUDICATE', 'FAILED', 'ABANDONED'],
        'ADJUDICATE' => ['RELEASE', 'VISIBLE', 'FAILED', 'ABANDONED'],
        'RELEASE' => ['VISIBLE', 'FAILED', 'ABANDONED'],
        'VISIBLE' => [],
        'FAILED' => ['PENDING', 'PREFLIGHT', 'FAILED', 'ABANDONED'],
        'ABANDONED' => [],
    ];

    /**
     * Job types with their OWN small graph (P07), instead of the source-monitoring lifecycle above.
     *
     * A MONITOR_STEP job advances one scheduled occurrence through the Monitor's capture-and-compare
     * stages in a single claim, then rests. WAITING_FOR_OPERATOR is a SUCCESSFUL resting state — the
     * run waits at the human request-inspection boundary — and is distinct from FAILED and from any
     * release. SETTLED means the occurrence has its final outcome (baseline, no change, blocked or
     * refused). The Monitor journal owns the run's stages; this graph owns only the job.
     */
    public const TYPE_TRANSITIONS = [
        'MONITOR_STEP' => [
            'PENDING' => ['WAITING_FOR_OPERATOR', 'SETTLED', 'FAILED', 'ABANDONED'],
            'FAILED' => ['WAITING_FOR_OPERATOR', 'SETTLED', 'FAILED', 'ABANDONED'],
            'WAITING_FOR_OPERATOR' => [],
            'SETTLED' => [],
            'ABANDONED' => [],
        ],
    ];

    public const TERMINAL_STATES = ['VISIBLE', 'ABANDONED', 'WAITING_FOR_OPERATOR', 'SETTLED'];

    /** States a typed job may be claimed from. */
    private const TYPE_CLAIMABLE_STATES = ['MONITOR_STEP' => ['PENDING', 'FAILED']];

    /**
     * States a claim may pick up.
     *
     * `FAILED` is included: a job that failed with attempts remaining is retryable, and its
     * `run_after` backoff is what stops the retry from being immediate. Excluding it made a
     * failed job unreachable forever — the retry budget was enforced by never retrying.
     */
    private const CLAIMABLE_STATES = [
        'PENDING', 'PREFLIGHT', 'FETCH', 'CAPTURE', 'DIFF', 'ANALYZE', 'ADJUDICATE', 'RELEASE', 'FAILED',
    ];

    /** Exponential backoff base. Attempt n waits BACKOFF_BASE_SECONDS * 2^(n-1). */
    private const BACKOFF_BASE_SECONDS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Enqueue a job, or return the existing one for the same idempotency key.
     *
     * @return Outcome Outcome::ok(array{job_id: string, created: bool})
     */
    public function enqueue(
        string $tenantId,
        string $workspaceId,
        string $jobType,
        string $subjectKind,
        string $subjectId,
        string $idempotencyKey,
        ?string $runAfter = null,
        int $maxAttempts = 3,
    ): Outcome {
        $existing = $this->db->one(
            'SELECT job_id, state FROM jobs
             WHERE tenant_id = ? AND workspace_id = ? AND job_type = ? AND subject_id = ? AND idempotency_key = ?',
            [$tenantId, $workspaceId, $jobType, $subjectId, $idempotencyKey]
        );
        if ($existing !== null) {
            return Outcome::ok(['job_id' => (string) $existing['job_id'], 'created' => false]);
        }

        $now = $this->clock->now();
        $jobId = Ids::new();
        $correlationId = Ids::new();

        try {
            $this->db->transaction(function (Database $tx) use (
                $jobId, $tenantId, $workspaceId, $jobType, $subjectKind, $subjectId,
                $idempotencyKey, $runAfter, $maxAttempts, $correlationId, $now
            ): void {
                $tx->insert('jobs', [
                    'job_id' => $jobId,
                    'schema_version' => '1',
                    'tenant_id' => $tenantId,
                    'workspace_id' => $workspaceId,
                    'job_type' => $jobType,
                    'state' => 'PENDING',
                    'subject_kind' => $subjectKind,
                    'subject_id' => $subjectId,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_count' => 0,
                    'max_attempts' => $maxAttempts,
                    'run_after' => $runAfter ?? $now,
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->appendTransition($tx, $jobId, null, 'PENDING', 0, 'ENQUEUED', null, null, $now);
            });
        } catch (\PDOException $error) {
            // A concurrent enqueue won the unique key; converge on its job rather than failing.
            $raced = $this->db->one(
                'SELECT job_id FROM jobs
                 WHERE tenant_id = ? AND workspace_id = ? AND job_type = ? AND subject_id = ? AND idempotency_key = ?',
                [$tenantId, $workspaceId, $jobType, $subjectId, $idempotencyKey]
            );
            if ($raced !== null) {
                return Outcome::ok(['job_id' => (string) $raced['job_id'], 'created' => false]);
            }

            return Database::classify($error, 'JOB_ALREADY_ENQUEUED');
        }

        return Outcome::ok(['job_id' => $jobId, 'created' => true]);
    }

    /**
     * Claim up to `$limit` eligible jobs for `$leaseOwner`.
     *
     * Each claim is an individual conditional UPDATE. Claiming a batch in one statement would be
     * faster and would lose the per-row outcome that proves exclusivity, so this stays per-row.
     *
     * `$executableStates` BOUNDS THE CANDIDATE SET to states the caller can actually advance. It is
     * the fix for a capability that is off: a scheduler with no handler registered for a state used
     * to claim those jobs anyway, fail them for having no handler, and burn the retry budget of
     * work nobody had activated — so an inactive capability quietly consumed the jobs that were
     * waiting for it. Passing the states that CAN run means the rest are never touched.
     *
     * Two properties keep that from becoming a way to widen anything. The list is INTERSECTED with
     * `CLAIMABLE_STATES`, so a caller cannot name a state the machine does not consider claimable;
     * and an empty intersection returns immediately WITHOUT QUERYING, so "nothing can run" costs a
     * function call rather than a lease. `null` means every claimable state, which is what a fully
     * wired deployment asks for.
     *
     * A TYPED job (see TYPE_TRANSITIONS) is never claimed through `$executableStates`: it is claimed
     * only when `$typedStates` names its type and state, so wiring a legacy PENDING handler cannot
     * pick up a MONITOR_STEP job and run it through the wrong graph.
     *
     * @param list<string>|null $executableStates
     * @param array<string, list<string>> $typedStates job_type => states the caller can advance
     * @return list<array<string, mixed>>
     */
    public function claim(
        string $leaseOwner,
        int $limit,
        int $leaseSeconds = 300,
        ?array $executableStates = null,
        array $typedStates = [],
    ): array {
        $states = $executableStates === null
            ? self::CLAIMABLE_STATES
            : array_values(array_intersect(self::CLAIMABLE_STATES, $executableStates));
        $typed = [];
        foreach ($typedStates as $type => $wanted) {
            $allowed = array_values(array_intersect(self::TYPE_CLAIMABLE_STATES[$type] ?? [], is_array($wanted) ? $wanted : []));
            if ($allowed !== []) {
                $typed[$type] = $allowed;
            }
        }
        if ($states === [] && $typed === []) {
            // No query, no lease, no row touched. A tick that can run nothing must be indistinguish-
            // able from a tick that never happened, as far as the jobs are concerned.
            return [];
        }

        $now = $this->clock->now();
        $leaseExpiry = $this->plusSeconds($now, $leaseSeconds);
        [$eligibility, $eligibilityParams] = $this->eligibility($states, $typed);

        $candidates = $this->db->all(
            "SELECT job_id FROM jobs
             WHERE ({$eligibility})
               AND run_after <= ?
               AND (lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at < ?)
               AND attempt_count < max_attempts
             ORDER BY run_after ASC, job_id ASC
             LIMIT " . max(1, min(100, $limit)),
            [...$eligibilityParams, $now, $now]
        );

        $claimed = [];
        foreach ($candidates as $candidate) {
            $jobId = (string) $candidate['job_id'];
            // THE WHOLE ELIGIBILITY PREDICATE IS REPEATED HERE, not just the lease. Between the
            // SELECT and this UPDATE another scheduler may have claimed the job, failed it and
            // written a backoff — and by then it is ineligible for THREE independent reasons, only
            // one of which is the lease. Re-checking the lease alone would let this claim take a
            // job whose `run_after` is in the future (hammering a source the backoff exists to back
            // away from) and push `attempt_count` past `max_attempts` (spending an attempt the
            // retry budget had already exhausted). The row count is the only honest answer, and it
            // is only honest if it answers the same question the SELECT asked.
            $affected = $this->db->run(
                "UPDATE jobs
                 SET lease_owner = ?, lease_expires_at = ?, attempt_count = attempt_count + 1, updated_at = ?
                 WHERE job_id = ?
                   AND ({$eligibility})
                   AND run_after <= ?
                   AND attempt_count < max_attempts
                   AND (lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at < ?)",
                [$leaseOwner, $leaseExpiry, $now, $jobId, ...$eligibilityParams, $now, $now]
            );
            if ($affected === 1) {
                $job = $this->db->one('SELECT * FROM jobs WHERE job_id = ?', [$jobId]);
                if ($job !== null) {
                    $claimed[] = $job;
                }
            }
        }

        return $claimed;
    }

    /**
     * Reverse a claim that produced no attempt.
     *
     * An ATTEMPT is a try. A job claimed and then handed back because nothing could run it was
     * never tried, so leaving `attempt_count` incremented would spend a retry on work that never
     * happened — and three such ticks would ABANDON a job for a capability nobody had switched on.
     *
     * This is not rewriting history: no transition is recorded by a claim, so there is no history
     * to rewrite. The ledger stays silent because nothing occurred.
     *
     * Pinned to the lease owner, like every other write here: a worker whose lease expired and was
     * reclaimed by somebody else must not decrement the new owner's attempt.
     */
    public function releaseClaim(string $jobId, string $leaseOwner): Outcome
    {
        $affected = $this->db->run(
            'UPDATE jobs
             SET lease_owner = NULL, lease_expires_at = NULL, updated_at = ?,
                 attempt_count = GREATEST(attempt_count - 1, 0)
             WHERE job_id = ? AND lease_owner = ?',
            [$this->clock->now(), $jobId, $leaseOwner]
        );
        if ($affected !== 1) {
            return Outcome::refuse('JOB_LEASE_LOST', [
                'job_id' => $jobId,
                'lease_owner' => $leaseOwner,
            ], 'CONCURRENCY');
        }

        return Outcome::ok(['job_id' => $jobId, 'released' => true]);
    }

    /** Count leases that had expired and were therefore reclaimable at `$now`. */
    public function expiredLeaseCount(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM jobs
             WHERE lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL AND lease_expires_at < ?',
            [$this->clock->now()]
        );
    }

    /**
     * Advance a claimed job to `$toState`, releasing the lease.
     *
     * TWO THINGS PIN THE WRITE, and they answer different questions.
     *
     *   THE LEASE OWNER, so a worker whose lease expired and was reclaimed by someone else cannot
     *   write its stale result over the new owner's work.
     *
     *   THE FROM-STATE, because the transition was validated against a state read in a SEPARATE
     *   statement. Without it a second advance under the same lease would apply a transition
     *   checked against a state the job has already left, and would record a `job_transitions` row
     *   whose `from_state` never happened — a ledger entry describing a step nobody took.
     *
     * `$runAfter` rides the SAME write for the same reason a backoff exists at all. Applied as a
     * second statement it would land after the lease was released, on a job another scheduler may
     * already have claimed and moved on — re-delaying work that is in flight, and doing it to
     * whoever owns the job now.
     */
    public function advance(
        string $jobId,
        string $leaseOwner,
        string $toState,
        ?string $reasonCode = null,
        ?string $detailSafe = null,
        ?string $runAfter = null,
    ): Outcome {
        $job = $this->db->one('SELECT state, attempt_count, job_type FROM jobs WHERE job_id = ?', [$jobId]);
        if ($job === null) {
            return Outcome::refuse('JOB_NOT_FOUND', ['job_id' => $jobId]);
        }
        $fromState = (string) $job['state'];
        $graph = self::TYPE_TRANSITIONS[(string) $job['job_type']] ?? self::TRANSITIONS;

        if (!in_array($toState, $graph[$fromState] ?? [], true)) {
            return Outcome::refuse('JOB_ILLEGAL_TRANSITION', [
                'from_state' => $fromState,
                'to_state' => $toState,
            ]);
        }

        $now = $this->clock->now();
        $terminal = in_array($toState, self::TERMINAL_STATES, true);

        return $this->db->transaction(function (Database $tx) use (
            $jobId, $leaseOwner, $fromState, $toState, $reasonCode, $detailSafe, $runAfter, $now, $job, $terminal
        ): Outcome {
            $affected = $tx->run(
                'UPDATE jobs
                 SET state = ?, lease_owner = NULL, lease_expires_at = NULL, updated_at = ?,
                     last_error_code = ?, last_error_at = ?, run_after = COALESCE(?, run_after)
                 WHERE job_id = ? AND lease_owner = ? AND state = ?',
                [
                    $toState,
                    $now,
                    $toState === 'FAILED' ? $reasonCode : null,
                    $toState === 'FAILED' ? $now : null,
                    $runAfter,
                    $jobId,
                    $leaseOwner,
                    $fromState,
                ]
            );
            if ($affected !== 1) {
                // WHICH of the two pins failed is what an operator needs. A lost lease is a slow
                // step and a lease window worth tuning; a moved state is two callers advancing one
                // job, which is a bug in the caller. Reporting both as one code would hide the
                // second behind the first, which is the ordinary explanation.
                $current = $tx->one('SELECT state, lease_owner FROM jobs WHERE job_id = ?', [$jobId]);

                return Outcome::refuse(
                    $current !== null && (string) $current['lease_owner'] === $leaseOwner
                        ? 'JOB_STATE_CHANGED'
                        : 'JOB_LEASE_LOST',
                    [
                        'job_id' => $jobId,
                        'lease_owner' => $leaseOwner,
                        'expected_state' => $fromState,
                        'observed_state' => $current === null ? null : (string) $current['state'],
                    ],
                    'CONCURRENCY',
                );
            }

            $this->appendTransition(
                $tx,
                $jobId,
                $fromState,
                $toState,
                (int) $job['attempt_count'],
                $reasonCode,
                $detailSafe,
                $leaseOwner,
                $now,
            );

            return Outcome::ok(['job_id' => $jobId, 'state' => $toState, 'terminal' => $terminal]);
        });
    }

    /**
     * Record a failure. A job that has exhausted its attempts is ABANDONED rather than retried
     * forever — an unbounded retry against a live source is exactly the abusive-crawler behavior
     * the source policy exists to prevent.
     */
    public function fail(string $jobId, string $leaseOwner, string $reasonCode, ?string $detailSafe = null): Outcome
    {
        $job = $this->db->one('SELECT state, attempt_count, max_attempts FROM jobs WHERE job_id = ?', [$jobId]);
        if ($job === null) {
            return Outcome::refuse('JOB_NOT_FOUND', ['job_id' => $jobId]);
        }
        $attempt = (int) $job['attempt_count'];
        $exhausted = $attempt >= (int) $job['max_attempts'];

        // The backoff before the retry becomes eligible. Without it a source that is refusing
        // requests would be hammered as fast as the scheduler fires — the abusive-crawler behavior
        // the source policy exists to prevent. It is computed here and APPLIED BY `advance`, in the
        // same write as the state change: as a follow-up statement it would land after the lease
        // was released, and could delay a job another scheduler had already claimed and moved on.
        $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1));

        $outcome = $this->advance(
            $jobId,
            $leaseOwner,
            $exhausted ? 'ABANDONED' : 'FAILED',
            $reasonCode,
            $detailSafe,
            $exhausted ? null : $this->plusSeconds($this->clock->now(), $delay),
        );

        return $outcome->isOk()
            ? Outcome::ok([
                'job_id' => $jobId,
                'state' => $exhausted ? 'ABANDONED' : 'FAILED',
                'retryable' => !$exhausted,
            ])
            : $outcome;
    }

    /**
     * The claim predicate: legacy job types by state, each typed job type only by its own states.
     *
     * @param list<string> $states
     * @param array<string, list<string>> $typed
     * @return array{0: string, 1: list<string>}
     */
    private function eligibility(array $states, array $typed): array
    {
        $clauses = [];
        $params = [];
        $typedTypes = array_keys(self::TYPE_TRANSITIONS);
        if ($states !== []) {
            $clauses[] = '(job_type NOT IN (' . implode(', ', array_fill(0, count($typedTypes), '?')) . ') AND state IN ('
                . implode(', ', array_fill(0, count($states), '?')) . '))';
            $params = [...$params, ...$typedTypes, ...$states];
        }
        foreach ($typed as $type => $typeStates) {
            $clauses[] = '(job_type = ? AND state IN (' . implode(', ', array_fill(0, count($typeStates), '?')) . '))';
            $params = [...$params, $type, ...$typeStates];
        }

        return [implode(' OR ', $clauses), $params];
    }

    /** @return list<array<string, mixed>> */
    public function history(string $jobId): array
    {
        return $this->db->all(
            'SELECT * FROM job_transitions WHERE job_id = ? ORDER BY transitioned_at ASC, job_transition_id ASC',
            [$jobId]
        );
    }

    private function appendTransition(
        Database $db,
        string $jobId,
        ?string $fromState,
        string $toState,
        int $attemptNumber,
        ?string $reasonCode,
        ?string $detailSafe,
        ?string $leaseOwner,
        string $at,
    ): void {
        $db->insert('job_transitions', [
            'job_transition_id' => Ids::new(),
            'schema_version' => '1',
            'job_id' => $jobId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'attempt_number' => $attemptNumber,
            'reason_code' => $reasonCode,
            'detail_safe' => $detailSafe,
            'lease_owner' => $leaseOwner,
            'transitioned_at' => $at,
        ]);
    }

    private function plusSeconds(string $iso, int $seconds): string
    {
        $at = Clock::parse($iso);
        if ($at === null) {
            throw new \InvalidArgumentException("non-canonical timestamp: {$iso}");
        }

        return Clock::format($at->modify("+{$seconds} seconds"));
    }
}
