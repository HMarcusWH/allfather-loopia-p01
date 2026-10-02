<?php
declare(strict_types=1);
namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Workflow\{MonitorOperations,MonitorPrincipal,RunJournal,ScheduleService};

/**
 * The step a MONITOR_STEP job performs: one scheduled occurrence, observed and queued (P07).
 *
 * The job names ONE occurrence and nothing else — no callback, SQL, URL or workflow definition. The
 * handler acts as the schedule's SERVICE principal under its delegating operator, through the SAME
 * MonitorService a human uses: start the run the occurrence names, capture, compare, and stop. It
 * never prepares a model request, never supplies a request digest, never records a review and never
 * releases; MonitorService refuses all of those to a service before anything is read or sent.
 *
 * Its results are deliberately not job failures:
 *
 *   WAITING_FOR_OPERATOR  the run changed and waits at DIFFED, the model-request inspection boundary.
 *                         A successful resting state: the lease is released and nothing retries.
 *   SETTLED               the occurrence has its final outcome — a first baseline, no change, a
 *                         blocked run, or a refusal (schedule or grant no longer admitted).
 *   release               the source is held by another run; the claim is handed back unattempted.
 *
 * Only an unexpected exception fails the job (and the runner retries it with backoff). A retry is
 * safe: the run's idempotency key is the occurrence's, the journal resumes from the recorded stage,
 * and the capture's M06 idempotency key is the run's, so a retry cannot capture twice.
 */
final class MonitorStepHandler
{
    /** Stages a scheduled run is advanced through, at most once each per claim. */
    private const SERVICE_STAGES = ['NEW', 'CAPTURED'];
    /** Refusals that mean "try this occurrence again later", not "this occurrence is over". */
    private const BUSY = ['MONITOR_SOURCE_BUSY', 'MONITOR_RUN_BUSY'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ScheduleService $schedules,
        private readonly MonitorOperations $monitor,
        private readonly RunJournal $journal,
    ) {}

    /** @return array{to_state?: string, reason_code?: ?string, detail_safe?: ?string, release?: bool} */
    public function __invoke(array $job): array
    {
        if (($job['job_type'] ?? null) !== 'MONITOR_STEP' || ($job['subject_kind'] ?? null) !== 'MONITOR_SCHEDULE_OCCURRENCE') {
            throw new \LogicException('MonitorStepHandler received a job that is not a MONITOR_STEP');
        }
        $occurrence = $this->db->one(
            'SELECT * FROM monitor_schedule_occurrences WHERE monitor_schedule_occurrence_id=? AND tenant_id=? AND workspace_id=?',
            [(string) $job['subject_id'], (string) $job['tenant_id'], (string) $job['workspace_id']]
        );
        if ($occurrence === null) {
            throw new \RuntimeException('MONITOR_STEP job names no occurrence in its own scope');
        }
        // Idempotent re-entry: an occurrence that already has its outcome is simply settled again.
        $prior = $this->outcome((string) $occurrence['monitor_schedule_occurrence_id']);
        if ($prior !== null) {
            return self::resting($prior['outcome'], $prior['reason_code']);
        }
        $version = $this->schedules->findById((string) $occurrence['monitor_schedule_version_id']);
        if ($version === null) {
            throw new \RuntimeException('MONITOR_STEP occurrence names no schedule version');
        }
        $principal = MonitorPrincipal::service($version['service_identity_id'], $version['delegating_operator_id'], (string) $occurrence['monitor_schedule_occurrence_id']);
        $scope = $principal->scope((string) $occurrence['tenant_id'], (string) $occurrence['workspace_id']);

        $delegation = $this->schedules->delegation($scope['tenant_id'], $scope['workspace_id'], $principal);
        if (!$delegation->isOk()) {
            return $this->finish($occurrence, $version, null, 'REFUSED', null, $delegation->reasonCode());
        }
        $started = $this->monitor->startConfigured($scope, $version['mission_id'], ScheduleService::runKey((string) $occurrence['monitor_schedule_occurrence_id']));
        if (!$started->isOk()) {
            return $this->finish($occurrence, $version, null, 'REFUSED', null, $started->reasonCode());
        }
        $runId = (string) $started->value()['monitor_run_id'];

        foreach (self::SERVICE_STAGES as $ignored) {
            $run = $this->journal->find($scope, $runId);
            if ($run === null || !in_array($run['stage'], self::SERVICE_STAGES, true)) {
                break;
            }
            $advanced = $this->monitor->advance($scope, $runId, null);
            if (!$advanced->isOk()) {
                if (in_array($advanced->reasonCode(), self::BUSY, true)) {
                    return ['release' => true, 'reason_code' => $advanced->reasonCode()];
                }
                $run = $this->journal->find($scope, $runId);

                return $this->finish($occurrence, $version, $run, 'REFUSED', null, $advanced->reasonCode());
            }
        }

        $run = $this->journal->find($scope, $runId);
        $stage = (string) ($run['stage'] ?? '');

        return match ($stage) {
            'DIFFED' => $this->finish($occurrence, $version, $run, 'WAITING_FOR_OPERATOR', $stage, null),
            'BASELINE' => $this->finish($occurrence, $version, $run, 'BASELINE_RECORDED', $stage, null),
            'NO_CHANGE' => $this->finish($occurrence, $version, $run, 'NO_CHANGE', $stage, null),
            'BLOCKED', 'FAILED', 'STOPPED' => $this->finish($occurrence, $version, $run, 'BLOCKED', $stage, (string) ($run['cursor']['reason_code'] ?? 'MONITOR_RUN_' . $stage)),
            default => $this->finish($occurrence, $version, $run, 'BLOCKED', $stage === '' ? null : $stage, 'SCHEDULED_STEP_INCOMPLETE'),
        };
    }

    /** Record the occurrence's one outcome (append-only, unique), then say where the job rests. */
    private function finish(array $occurrence, array $version, ?array $run, string $outcome, ?string $stage, ?string $reason): array
    {
        $occurrenceId = (string) $occurrence['monitor_schedule_occurrence_id'];
        $runStage = $stage ?? ($run === null ? null : (string) $run['stage']);
        if (!in_array($runStage, [null, 'NEW', 'CAPTURED', 'BASELINE', 'DIFFED', 'NO_CHANGE', 'BLOCKED', 'FAILED', 'STOPPED'], true)) {
            $runStage = null;
        }
        try {
            $this->db->insert('monitor_schedule_occurrence_outcomes', [
                'monitor_schedule_outcome_id' => Ids::new(), 'monitor_schedule_occurrence_id' => $occurrenceId,
                'monitor_run_id' => $run === null ? null : (string) $run['monitor_run_id'], 'outcome' => $outcome,
                'run_stage' => $runStage, 'run_revision' => $run === null ? null : (int) $run['revision'],
                'reason_code' => $reason === null ? null : substr($reason, 0, 96),
                'recorded_by_service_identity_id' => $version['service_identity_id'], 'recorded_at' => $this->clock->now(),
            ]);
        } catch (\PDOException $error) {
            // A concurrent holder already recorded this occurrence's outcome; it is the one that stands.
            $prior = $this->outcome($occurrenceId);
            if ($prior === null) {
                throw $error;
            }

            return self::resting($prior['outcome'], $prior['reason_code']);
        }

        return self::resting($outcome, $reason);
    }

    private function outcome(string $occurrenceId): ?array
    {
        return $this->db->one('SELECT outcome, reason_code FROM monitor_schedule_occurrence_outcomes WHERE monitor_schedule_occurrence_id=?', [$occurrenceId]);
    }

    /** @return array{to_state: string, reason_code: string, detail_safe: ?string} */
    private static function resting(string $outcome, ?string $reason): array
    {
        return [
            'to_state' => $outcome === 'WAITING_FOR_OPERATOR' ? 'WAITING_FOR_OPERATOR' : 'SETTLED',
            'reason_code' => 'SCHEDULED_' . $outcome,
            'detail_safe' => $reason,
        ];
    }
}
