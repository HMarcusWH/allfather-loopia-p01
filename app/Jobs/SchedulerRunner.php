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
 *   THE STEP REGISTRY IS CLOSED, and it BOUNDS WHAT IS CLAIMED. There is no generic executor: a
 *   job is claimed only when a handler is registered for its exact current state. The registry is
 *   read from THIS OBJECT, never from the request, so bounding the claim by it cannot become a way
 *   for a caller to choose which work runs.
 *
 *   AN INACTIVE CAPABILITY CONSUMES NOTHING. An earlier revision claimed every claimable job and
 *   failed the ones it could not run, which burned a retry budget for work nobody had activated —
 *   three ticks and a monitoring job was ABANDONED because a capability was off. Now those jobs are
 *   not claimed at all: no lease, no attempt, no transition, no row touched. A tick with an empty
 *   registry is indistinguishable, from the jobs' point of view, from a tick that never happened.
 *
 * WHAT THIS DOES NOT DO is activate anything. `LIVE_READ_ACTIVATED` is false, so no handler that
 * performs an external read is registered — and the jobs that would need one simply wait, which is
 * the correct state for work whose capability nobody has granted.
 */
final class SchedulerRunner
{
    /** Hard ceiling on jobs per tick, whatever configuration asks for. */
    public const MAX_JOBS_PER_TICK = 50;

    /** Hard ceiling on a tick's wall-clock budget. */
    public const MAX_BUDGET_MS = 50_000;

    /**
     * Reported when a tick has no executable handler at all.
     *
     * It is a property of the TICK, not of any job: nothing was claimed, so there is nothing to
     * record against. An operator reading this in the scheduler log is being told the machine is
     * idle by configuration rather than by absence of work.
     */
    public const INACTIVE_REASON = 'SCHEDULER_INACTIVE';

    /**
     * Reported when a claimed job turns out to have no handler after all.
     *
     * Unreachable by construction — the claim is bounded by the registry — and kept because the
     * construction is what makes it unreachable, and a future edit could change that. If it ever
     * fires, the claim is REVERSED rather than the job failed: the job was never tried, so it must
     * not spend an attempt on having been picked up by mistake.
     */
    public const NO_HANDLER_REASON = 'STEP_HANDLER_NOT_REGISTERED';

    /**
     * @param array<string, callable(array<string, mixed>): array{to_state?: string, reason_code?: ?string, detail_safe?: ?string, release?: bool}> $handlers
     *        Keyed by the job state the handler advances FROM, or — for a typed job with its own graph
     *        (JobMachine::TYPE_TRANSITIONS) — by `TYPE:STATE`. Empty unless an owner-admitted schedule
     *        exists (see ScheduleDispatcher::runner). A handler may return `release => true` to hand a
     *        claim back unattempted when the work could not even start (a busy source lock).
     * @param null|\Closure(): array<string, mixed> $beforeClaim bounded pre-claim work (occurrence
     *        reservation), reported in the receipt. It is the runner's own, never the caller's.
     */
    public function __construct(
        private readonly JobMachine $jobs,
        private readonly array $handlers = [],
        private readonly ?\Closure $beforeClaim = null,
    ) {
    }

    /**
     * Run one bounded tick.
     *
     * @return array{
     *     tick_id: string, claimed: int, advanced: int, failed: int, released: int,
     *     executable_states: list<string>, stopped_reason: string, elapsed_ms: int, lease_owner: string
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

        // THE STATES THIS TICK CAN ACTUALLY ADVANCE, derived from the registry rather than asked
        // for. `claim` intersects them with its own claimable set, so this can only ever narrow.
        $executableStates = array_values(array_map('strval', array_keys($this->handlers)));
        sort($executableStates);
        $legacyStates = [];
        $typedStates = [];
        foreach ($executableStates as $key) {
            if (str_contains($key, ':')) {
                [$type, $state] = explode(':', $key, 2);
                $typedStates[$type][] = $state;
            } else {
                $legacyStates[] = $key;
            }
        }

        $dispatch = null;
        if ($this->beforeClaim !== null && $executableStates !== []) {
            $dispatch = ($this->beforeClaim)();
        }

        $claimed = $this->jobs->claim($leaseOwner, $maxJobs, $leaseSeconds, $legacyStates, $typedStates);

        $advanced = 0;
        $failed = 0;
        $released = 0;
        $stopped = $executableStates === [] ? self::INACTIVE_REASON : 'COMPLETED';

        foreach ($claimed as $job) {
            // BETWEEN jobs, never inside one. A budget checked mid-step would abandon work half
            // done; checked here, the tick always stops on a whole job with its lease released.
            if (($this->monotonicMs() - $startedAt) >= $budgetMs) {
                $stopped = 'BUDGET_EXHAUSTED';
                break;
            }

            $state = (string) $job['state'];
            $typedKey = (string) $job['job_type'] . ':' . $state;
            $handler = array_key_exists((string) $job['job_type'], JobMachine::TYPE_TRANSITIONS)
                ? ($this->handlers[$typedKey] ?? null)
                : ($this->handlers[$state] ?? null);
            if ($handler === null) {
                // Unreachable while the claim is bounded by the registry. If it ever fires, HAND
                // THE JOB BACK: it was never tried, so it must not spend an attempt on having been
                // picked up by mistake.
                $this->jobs->releaseClaim((string) $job['job_id'], $leaseOwner);
                $released++;
                continue;
            }

            // A handler that throws is a FAILURE of that job, not of the tick. One broken step must
            // not stop every other job in the batch — and the job's own lease is what would
            // otherwise be left dangling until it expired.
            try {
                $step = $handler($job);
                if (($step['release'] ?? false) === true) {
                    // The work could not start (another holder has the source). Hand the claim back
                    // unattempted: it did not happen, so it must not spend a retry.
                    $this->jobs->releaseClaim((string) $job['job_id'], $leaseOwner);
                    $released++;
                    continue;
                }
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
            'released' => $released,
            // Reported so an operator can see WHY a tick was idle. The list is the registry's, and
            // a caller cannot influence it — it is output, never input.
            'executable_states' => $executableStates,
            'stopped_reason' => $stopped,
            'elapsed_ms' => $this->monotonicMs() - $startedAt,
            'lease_owner' => $leaseOwner,
            'dispatch' => $dispatch,
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
