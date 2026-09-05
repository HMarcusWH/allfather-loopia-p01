<?php

declare(strict_types=1);

namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Core\Ids;

/**
 * The bounded scheduler tick — one invocation of governed work, and then nothing.
 *
 * Loopia gives no daemon and no persistent worker, so the machine is driven by a scheduler that
 * fires a request on a timer. Every property below exists because that request can be fired again
 * while the previous one is still running, fired by something that is not the scheduler, or killed
 * halfway through.
 *
 *   BOUNDED TWO WAYS. A job count and a wall-clock budget, both enforced, and the budget is checked
 *   BETWEEN jobs so a tick stops cleanly on a whole job rather than being killed inside one. A tick
 *   that ran until the host cut it off would leave its last job mid-step with a live lease, and the
 *   only thing recovering it would be the lease expiry — the slow path, used for every job.
 *
 *   OVERLAP IS SAFE, and is not prevented. Two ticks running at once claim disjoint sets, because
 *   the claim is a conditional write the database arbitrates. A lock that stopped the second tick
 *   would add a way to deadlock the whole machine in exchange for a property the leases already
 *   give.
 *
 *   IT ACCEPTS NO WORK DEFINITION. The tick takes no payload saying what to run — not a job id, not
 *   a job type, not a step. It runs the next eligible bounded batch and nothing else. A scheduler
 *   endpoint that accepted a work definition would be a remote execution surface with a timer
 *   attached, and the token in front of it would be the only thing between the internet and
 *   arbitrary governed work.
 *
 *   THE STEP REGISTRY IS CLOSED, and it is EMPTY in this projection. There is no generic executor:
 *   a claimed job is advanced only by a handler registered for its exact state, and a state with no
 *   handler is FAILED by name rather than skipped. Skipping would leave a job claimed, leased and
 *   silently going nowhere — indistinguishable from work that is progressing.
 *
 * WHAT THIS DOES NOT DO is activate anything. `LIVE_READ_ACTIVATED` is false, so no handler that
 * performs an external read is registered, and a monitoring job therefore fails with that reason
 * recorded in its transition ledger rather than quietly waiting for a capability nobody granted.
 */
final class SchedulerRunner
{
    /** Hard ceiling on jobs per tick, whatever configuration asks for. */
    public const MAX_JOBS_PER_TICK = 50;

    /** Hard ceiling on a tick's wall-clock budget. */
    public const MAX_BUDGET_MS = 50_000;

    /**
     * The reason a claimed job cannot be advanced because nothing is registered to advance it.
     *
     * It is a FAILURE, not a skip. The retry budget then abandons the job after its attempts, and
     * the transition ledger says exactly why — which is the honest record of a projection whose
     * live-read capability has not been activated.
     */
    public const NO_HANDLER_REASON = 'STEP_HANDLER_NOT_REGISTERED';

    /**
     * @param array<string, callable(array<string, mixed>): array{to_state: string, reason_code?: ?string, detail_safe?: ?string}> $handlers
     *        Keyed by the job state the handler advances FROM. Empty in this projection.
     */
    public function __construct(
        private readonly JobMachine $jobs,
        private readonly array $handlers = [],
    ) {
    }

    /**
     * Run one bounded tick.
     *
     * @return array{
     *     tick_id: string, claimed: int, advanced: int, failed: int, stopped_reason: string,
     *     elapsed_ms: int, lease_owner: string
     * }
     */
    public function tick(int $maxJobs = 10, int $budgetMs = 20_000, int $leaseSeconds = 300): array
    {
        $maxJobs = max(1, min(self::MAX_JOBS_PER_TICK, $maxJobs));
        $budgetMs = max(1, min(self::MAX_BUDGET_MS, $budgetMs));

        // The lease owner names THIS tick, not the host or the scheduler. Two ticks on one host
        // must be distinguishable, or a second tick could advance a job the first one holds.
        $tickId = Ids::new();
        $leaseOwner = 'tick:' . $tickId;

        $startedAt = $this->monotonicMs();
        $claimed = $this->jobs->claim($leaseOwner, $maxJobs, $leaseSeconds);

        $advanced = 0;
        $failed = 0;
        $stopped = 'COMPLETED';

        foreach ($claimed as $job) {
            // BETWEEN jobs, never inside one. A budget checked mid-step would abandon work half
            // done; checked here, the tick always stops on a whole job with its lease released.
            if (($this->monotonicMs() - $startedAt) >= $budgetMs) {
                $stopped = 'BUDGET_EXHAUSTED';
                break;
            }

            $state = (string) $job['state'];
            $handler = $this->handlers[$state] ?? null;
            if ($handler === null) {
                $this->jobs->fail(
                    (string) $job['job_id'],
                    $leaseOwner,
                    self::NO_HANDLER_REASON,
                    'no handler is registered for state ' . $state,
                );
                $failed++;
                continue;
            }

            // A handler that throws is a FAILURE of that job, not of the tick. One broken step must
            // not stop every other job in the batch — and the job's own lease is what would
            // otherwise be left dangling until it expired.
            try {
                $step = $handler($job);
                $outcome = $this->jobs->advance(
                    (string) $job['job_id'],
                    $leaseOwner,
                    $step['to_state'],
                    $step['reason_code'] ?? null,
                    $step['detail_safe'] ?? null,
                );
                if ($outcome->isOk()) {
                    $advanced++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $error) {
                // The reason code is the exception's CLASS and message, and the message is written
                // by this projection's own handlers — never a driver string, which can carry a
                // hostname, a user name, or a fragment of a query.
                $this->jobs->fail(
                    (string) $job['job_id'],
                    $leaseOwner,
                    'STEP_HANDLER_THREW',
                    $error::class,
                );
                $failed++;
            }
        }

        return [
            'tick_id' => $tickId,
            'claimed' => count($claimed),
            'advanced' => $advanced,
            'failed' => $failed,
            'stopped_reason' => $stopped,
            'elapsed_ms' => $this->monotonicMs() - $startedAt,
            'lease_owner' => $leaseOwner,
        ];
    }

    /**
     * Elapsed milliseconds from a monotonic source.
     *
     * NOT the governed clock. The governed clock is pinned in tests and replay, so a budget read
     * from it would never advance and the bound would never bind — the one place in this projection
     * where wall time is the right question.
     */
    private function monotonicMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }
}
