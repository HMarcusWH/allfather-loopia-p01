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

    public const TERMINAL_STATES = ['VISIBLE', 'ABANDONED'];

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
     * @return list<array<string, mixed>>
     */
    public function claim(string $leaseOwner, int $limit, int $leaseSeconds = 300): array
    {
        $now = $this->clock->now();
        $leaseExpiry = $this->plusSeconds($now, $leaseSeconds);
        $placeholders = implode(', ', array_fill(0, count(self::CLAIMABLE_STATES), '?'));

        $candidates = $this->db->all(
            "SELECT job_id FROM jobs
             WHERE state IN ({$placeholders})
               AND run_after <= ?
               AND (lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at < ?)
               AND attempt_count < max_attempts
             ORDER BY run_after ASC, job_id ASC
             LIMIT " . max(1, min(100, $limit)),
            [...self::CLAIMABLE_STATES, $now, $now]
        );

        $claimed = [];
        foreach ($candidates as $candidate) {
            $jobId = (string) $candidate['job_id'];
            // The conditional predicate is repeated here on purpose: between the SELECT and this
            // UPDATE another scheduler may have taken the job, and the row count is the only
            // honest answer about who owns it.
            $affected = $this->db->run(
                'UPDATE jobs
                 SET lease_owner = ?, lease_expires_at = ?, attempt_count = attempt_count + 1, updated_at = ?
                 WHERE job_id = ?
                   AND (lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at < ?)',
                [$leaseOwner, $leaseExpiry, $now, $jobId, $now]
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
     * The lease owner is part of the WHERE clause: a worker whose lease expired and was reclaimed
     * by someone else cannot write its stale result over the new owner's work.
     */
    public function advance(
        string $jobId,
        string $leaseOwner,
        string $toState,
        ?string $reasonCode = null,
        ?string $detailSafe = null,
    ): Outcome {
        $job = $this->db->one('SELECT state, attempt_count FROM jobs WHERE job_id = ?', [$jobId]);
        if ($job === null) {
            return Outcome::refuse('JOB_NOT_FOUND', ['job_id' => $jobId]);
        }
        $fromState = (string) $job['state'];

        if (!in_array($toState, self::TRANSITIONS[$fromState] ?? [], true)) {
            return Outcome::refuse('JOB_ILLEGAL_TRANSITION', [
                'from_state' => $fromState,
                'to_state' => $toState,
            ]);
        }

        $now = $this->clock->now();
        $terminal = in_array($toState, self::TERMINAL_STATES, true);

        return $this->db->transaction(function (Database $tx) use (
            $jobId, $leaseOwner, $fromState, $toState, $reasonCode, $detailSafe, $now, $job, $terminal
        ): Outcome {
            $affected = $tx->run(
                'UPDATE jobs
                 SET state = ?, lease_owner = NULL, lease_expires_at = NULL, updated_at = ?,
                     last_error_code = ?, last_error_at = ?
                 WHERE job_id = ? AND lease_owner = ?',
                [
                    $toState,
                    $now,
                    $toState === 'FAILED' ? $reasonCode : null,
                    $toState === 'FAILED' ? $now : null,
                    $jobId,
                    $leaseOwner,
                ]
            );
            if ($affected !== 1) {
                return Outcome::refuse('JOB_LEASE_LOST', [
                    'job_id' => $jobId,
                    'lease_owner' => $leaseOwner,
                ], 'CONCURRENCY');
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

        $outcome = $this->advance(
            $jobId,
            $leaseOwner,
            $exhausted ? 'ABANDONED' : 'FAILED',
            $reasonCode,
            $detailSafe,
        );

        if ($outcome->isOk() && !$exhausted) {
            // Back off before the retry becomes eligible. Without this, a source that is refusing
            // requests would be hammered as fast as the scheduler fires — the abusive-crawler
            // behavior the source policy exists to prevent.
            $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1));
            $this->db->run(
                'UPDATE jobs SET run_after = ? WHERE job_id = ?',
                [$this->plusSeconds($this->clock->now(), $delay), $jobId]
            );
        }

        return $outcome->isOk()
            ? Outcome::ok([
                'job_id' => $jobId,
                'state' => $exhausted ? 'ABANDONED' : 'FAILED',
                'retryable' => !$exhausted,
            ])
            : $outcome;
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
