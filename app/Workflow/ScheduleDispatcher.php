<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Evaluation\EvaluationReport;
use Allfather\Loopia\Jobs\{JobMachine,MonitorStepHandler,SchedulerRunner};
use Allfather\Loopia\Ops\SourceFingerprint;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\KeyRing;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Support\Env;

/**
 * Turns admitted schedules into reserved occurrences and typed MONITOR_STEP jobs (P07).
 *
 * Every tick may reserve AT MOST ONE occurrence per schedule, for the LATEST due slot. That is the
 * whole catch-up policy (LATEST_ONLY): an outage of a week at an hourly cadence produces one
 * occurrence with `skipped_slots` recording what was missed — never a replay storm of 168 captures.
 *
 * An occurrence is reserved only while ALL of these hold, checked under a lock on the schedule's
 * versions so two concurrent ticks cannot both reserve:
 *
 *   - the schedule version is ADMITTED now (owner decision re-read) and its SCHEDULED_OBSERVATION
 *     grant is ADMITTED now — stand-down, revocation and expiry stop new occurrences;
 *   - the slot is not already reserved (also a UNIQUE key: schedule, version, scheduled instant);
 *   - no earlier occurrence of the series is still unfinished (non-overlap);
 *   - fewer than MAX_WAITING runs of the series sit queued for a human (a queue nobody reads does not
 *     keep spending capture budget);
 *   - the schedule's occurrence cap and the grant's capture budget both have room.
 *
 * Reserving an occurrence spends nothing. The capture unit is spent at capture time, by the existing
 * admission path, under the source lock.
 */
final class ScheduleDispatcher
{
    public const MAX_SCHEDULES_PER_TICK = 20;
    public const MAX_WAITING = 3;
    public const MONITOR_STEP_MAX_ATTEMPTS = 3;
    private const JOB_TERMINAL = ['WAITING_FOR_OPERATOR', 'SETTLED', 'ABANDONED'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ScheduleService $schedules,
        private readonly ExecutionGrantStore $grants,
        private readonly JobMachine $jobs,
    ) {}

    /**
     * One bounded reservation pass.
     *
     * @return array{considered: int, reserved: list<array{monitor_schedule_occurrence_id: string, monitor_schedule_id: string, scheduled_for: string, skipped_slots: int}>, skipped: array<string, int>}
     */
    public function dispatch(int $maxSchedules = self::MAX_SCHEDULES_PER_TICK): array
    {
        $receipt = ['considered' => 0, 'reserved' => [], 'skipped' => []];
        $skip = static function (string $reason) use (&$receipt): void {
            $receipt['skipped'][$reason] = ($receipt['skipped'][$reason] ?? 0) + 1;
        };
        foreach ($this->schedules->admittedCandidates(max(1, min(self::MAX_SCHEDULES_PER_TICK, $maxSchedules))) as $versionId) {
            $receipt['considered']++;
            $reserved = $this->reserve((string) $versionId);
            if (is_string($reserved)) {
                $skip($reserved);
            } else {
                $receipt['reserved'][] = $reserved;
            }
        }

        return $receipt;
    }

    /** @return string|array{monitor_schedule_occurrence_id: string, monitor_schedule_id: string, scheduled_for: string, skipped_slots: int} */
    private function reserve(string $versionId): string|array
    {
        $current = $this->schedules->current($versionId);
        if (!$current->isOk()) {
            return $current->reasonCode();
        }
        $v = $current->value();
        $now = $this->clock->now();
        $nowAt = Clock::parse($now);
        $fromAt = Clock::parse($v['valid_from']);
        if ($nowAt === null || $fromAt === null) {
            return 'SCHEDULE_TIME_INVALID';
        }
        $elapsedMs = ((int) $nowAt->format('Uv')) - ((int) $fromAt->format('Uv'));
        if ($elapsedMs < 0) {
            return 'NOT_DUE';
        }
        $cadenceMs = (int) $v['cadence_seconds'] * 1000;
        $slot = intdiv($elapsedMs, $cadenceMs);
        $scheduledFor = Clock::format($fromAt->modify('+' . ($slot * (int) $v['cadence_seconds']) . ' seconds'));

        try {
            return $this->db->transaction(function (Database $tx) use ($v, $slot, $scheduledFor): string|array {
                // Serialize every reservation for this series behind its version rows.
                $tx->all('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE monitor_schedule_id=? ORDER BY schedule_version FOR UPDATE', [$v['monitor_schedule_id']]);
                if ($tx->one('SELECT 1 FROM monitor_schedule_occurrences WHERE monitor_schedule_id=? AND schedule_version=? AND scheduled_for=?', [$v['monitor_schedule_id'], $v['schedule_version'], $scheduledFor]) !== null) {
                    return 'ALREADY_RESERVED';
                }
                $unfinished = (int) $tx->scalar(
                    "SELECT COUNT(*) FROM monitor_schedule_occurrences o
                     LEFT JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id=o.monitor_schedule_occurrence_id
                     LEFT JOIN jobs j ON j.subject_id=o.monitor_schedule_occurrence_id AND j.job_type='MONITOR_STEP' AND j.tenant_id=o.tenant_id
                     WHERE o.monitor_schedule_id=? AND x.monitor_schedule_outcome_id IS NULL
                       AND (j.job_id IS NULL OR j.state NOT IN ('" . implode("','", self::JOB_TERMINAL) . "'))",
                    [$v['monitor_schedule_id']]
                );
                if ($unfinished > 0) {
                    return 'PREVIOUS_OCCURRENCE_UNFINISHED';
                }
                $waiting = (int) $tx->scalar(
                    "SELECT COUNT(*) FROM monitor_schedule_occurrence_outcomes x
                     JOIN monitor_schedule_occurrences o ON o.monitor_schedule_occurrence_id=x.monitor_schedule_occurrence_id
                     JOIN monitor_runs r ON r.monitor_run_id=x.monitor_run_id
                     WHERE o.monitor_schedule_id=? AND x.outcome='WAITING_FOR_OPERATOR' AND r.stage='DIFFED'",
                    [$v['monitor_schedule_id']]
                );
                if ($waiting >= self::MAX_WAITING) {
                    return 'OPERATOR_QUEUE_FULL';
                }
                $used = (int) $tx->scalar('SELECT COUNT(*) FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id=?', [$v['monitor_schedule_version_id']]);
                if ($used >= (int) $v['max_occurrences']) {
                    return 'SCHEDULE_BUDGET_EXHAUSTED';
                }
                $grant = $this->grants->find($v['tenant_id'], $v['workspace_id'], $v['execution_grant_id']);
                if ($grant === null || $this->grants->consumed($v['execution_grant_id'], 'SOURCE_CAPTURE') >= (int) $grant['definition']['limits']['max_source_captures']) {
                    return 'GRANT_BUDGET_EXHAUSTED';
                }
                $last = $tx->scalar('SELECT MAX(slot_index) FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id=?', [$v['monitor_schedule_version_id']]);
                if ($last !== null && (int) $last >= $slot) {
                    return 'ALREADY_RESERVED';
                }
                $skipped = $last === null ? $slot : $slot - (int) $last - 1;

                $occurrenceId = Ids::new();
                $tx->insert('monitor_schedule_occurrences', [
                    'monitor_schedule_occurrence_id' => $occurrenceId, 'monitor_schedule_version_id' => $v['monitor_schedule_version_id'],
                    'monitor_schedule_id' => $v['monitor_schedule_id'], 'schedule_version' => $v['schedule_version'],
                    'tenant_id' => $v['tenant_id'], 'workspace_id' => $v['workspace_id'], 'scheduled_for' => $scheduledFor,
                    'slot_index' => $slot, 'skipped_slots' => $skipped, 'reserved_at' => $this->clock->now(),
                ]);
                $job = $this->jobs->enqueue(
                    $v['tenant_id'], $v['workspace_id'], 'MONITOR_STEP', 'MONITOR_SCHEDULE_OCCURRENCE', $occurrenceId,
                    'occurrence:' . $occurrenceId, null, self::MONITOR_STEP_MAX_ATTEMPTS,
                );
                if (!$job->isOk()) {
                    throw new \RuntimeException('MONITOR_STEP job could not be enqueued for a reserved occurrence');
                }

                return ['monitor_schedule_occurrence_id' => $occurrenceId, 'monitor_schedule_id' => $v['monitor_schedule_id'], 'scheduled_for' => $scheduledFor, 'skipped_slots' => $skipped];
            });
        } catch (\PDOException $error) {
            // A concurrent tick won the UNIQUE slot, or holds the series lock right now. Either way
            // nothing was reserved twice; the next tick looks again.
            return str_contains($error->getMessage(), 'uq_monitor_schedule_occurrence') ? 'ALREADY_RESERVED' : 'RESERVATION_CONTENDED';
        }
    }

    /**
     * Does this deployment hold ANY currently admitted schedule? The scheduler composition roots ask
     * this before building anything that can capture. A database that predates migration 250, or any
     * read failure, answers no.
     */
    public static function admittedAnywhere(Database $db, Clock $clock, string $root): bool
    {
        try {
            return self::admittedIn(self::schedules($db, $clock, OwnerDecisionRegistry::forRoot($root), $root));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether any schedule this owner can see is current right now (bounded to 50 candidates). */
    public static function admittedIn(ScheduleService $schedules): bool
    {
        foreach ($schedules->admittedCandidates(50) as $versionId) {
            if ($schedules->current((string) $versionId)->isOk()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one non-empty scheduler composition. Reached only when admittedAnywhere() said yes; every
     * occurrence is still re-checked against its own schedule, grant and source at every step.
     *
     * The Monitor composition it drives has NO live model transport at all (MonitorFactory::
     * forScheduledObservation), and its only handler advances MONITOR_STEP jobs. The legacy
     * source-monitoring states stay unregistered.
     */
    public static function runner(Database $db, Clock $clock, string $root, Env $env): SchedulerRunner
    {
        $decisions = OwnerDecisionRegistry::forRoot($root);
        $schedules = self::schedules($db, $clock, $decisions, $root);
        $approvals = new PromptApprovalStore($db, $clock, $decisions, EvaluationReport::approvalEvidence($db, $root));
        $grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
        $monitor = MonitorFactory::forScheduledObservation(
            $db, $clock, new EvidenceStore($root . '/storage/evidence'), KeyRing::fromEnv($env), $decisions,
            static fn (): string => SourceFingerprint::compute($root), $schedules,
        );
        $jobs = new JobMachine($db, $clock);
        $handler = new MonitorStepHandler($db, $clock, $schedules, $monitor, new RunJournal($db, $clock));
        $dispatcher = new self($db, $clock, $schedules, $grants, $jobs);

        return new SchedulerRunner($jobs, [
            'MONITOR_STEP:PENDING' => $handler,
            'MONITOR_STEP:FAILED' => $handler,
        ], static fn (): array => $dispatcher->dispatch());
    }

    private static function schedules(Database $db, Clock $clock, OwnerDecisionRegistry $decisions, string $root): ScheduleService
    {
        $approvals = new PromptApprovalStore($db, $clock, $decisions, EvaluationReport::approvalEvidence($db, $root));

        return new ScheduleService($db, $clock, new ExecutionGrantStore($db, $clock, $approvals, $decisions), $decisions, new MissionConfiguration($db, $clock));
    }
}
