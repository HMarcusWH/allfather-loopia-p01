<?php
declare(strict_types=1);

/**
 * P07 — recurring observation, end to end, offline.
 *
 * An owner-admitted schedule, under an owner-admitted SCHEDULED_OBSERVATION grant, drives the SAME
 * MonitorService a human uses through a SERVICE principal: reserve an occurrence, capture, compare,
 * and stop at the model-request inspection boundary with the run queued for a human. The source
 * transport is a fixture; the model transport claims to be live and must never be called.
 */

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Workflow\{MonitorPrincipal,RunJournal,ScheduleDispatcher,ScheduleService};
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,ScheduleFixture as S,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock('2026-03-02T09:00:00.000Z');
$sx = S::create($db, $clock, ['Seminar in November.', 'Seminar in December.', 'Seminar in December.']);
$scope = $sx['scope'];
$schedules = $sx['schedules'];

// ============================================================ planning refuses what it must

$refused = static fn (array $overrides): string => $schedules->plan($scope, S::input($sx, $clock, $overrides))->reasonCode();
Assert::same('SCHEDULE_CADENCE_OUT_OF_BOUNDS', $refused(['cadence_seconds' => 600]), 'a cadence faster than hourly is refused');
Assert::same('SCHEDULE_CADENCE_BELOW_SOURCE_FLOOR', $refused(['cadence_seconds' => 3600]), "and so is one faster than the source policy's own cadence floor");
Assert::same('SCHEDULE_CADENCE_OUT_OF_BOUNDS', $refused(['cadence_seconds' => 3600 * 24 * 40]), 'and one slower than 31 days');
Assert::same('SCHEDULE_BUDGET_EXCEEDS_GRANT', $refused(['max_occurrences' => 21]), 'the occurrence cap cannot exceed the parent grant capture budget');
Assert::same('SCHEDULE_WINDOW_OUTSIDE_GRANT', $refused(['valid_until' => Clock::format(Clock::parse($clock->now())->modify('+90 days'))]), 'the window must sit inside the grant window');
Assert::same('SCHEDULE_WINDOW_INVALID', $refused(['valid_from' => 'tomorrow']), 'a non-canonical window is refused');
$manualApprovals = X::approveAll($sx, 'MANUAL_MONITOR');
$manual = X::admitted($sx, X::manualDefinition($db, $clock, $sx, $manualApprovals));
Assert::same('SCHEDULE_GRANT_MISMATCH', $refused(['execution_grant_id' => $manual['execution_grant_id']]), 'a MANUAL_MONITOR grant cannot parent a schedule');
$wrongClass = S::serviceIdentity($db, $clock, $scope['tenant_id'], $scope['workspace_id'], 'M11_REPLAY_RUNNER');
Assert::same('SCHEDULE_SERVICE_IDENTITY_INVALID', $refused(['service_identity_id' => $wrongClass]), 'only a SYSTEM_SCHEDULER identity may be delegated to');
$unassigned = S::serviceIdentity($db, $clock, $scope['tenant_id'], $scope['workspace_id'], 'SYSTEM_SCHEDULER', false);
Assert::same('SCHEDULE_PRINCIPAL_NOT_AUTHORIZED', $refused(['service_identity_id' => $unassigned]), 'a scheduler without its own membership holds no authority to delegate to');
$stranger = Seed::operator($db, $clock, $scope['tenant_id'], 'Stranger');
Assert::same('SCHEDULE_ADMINISTRATION_DENIED', $schedules->plan(['operator_id' => $stranger] + $scope, S::input($sx, $clock))->reasonCode(), 'an operator without administration cannot even plan');

// ============================================================ proposal is not admission

$plan = S::value($schedules->plan($scope, S::input($sx, $clock)));
Assert::same(false, $plan['grants_execution'], 'a plan grants nothing');
Assert::same(['OBSERVE_AND_QUEUE', 'LATEST_ONLY', $scope['operator_id']], [$plan['definition']['observation_mode'], $plan['definition']['catch_up_policy'], $plan['definition']['delegating_operator_id']], 'observe-and-queue only, latest-only catch-up, delegated by the grant principal');
$proposed = S::value($schedules->propose($scope, S::input($sx, $clock)));
Assert::same('PROPOSED', $schedules->status($proposed['monitor_schedule_version_id']), 'a proposed schedule is PROPOSED');
Assert::same(['considered' => 0, 'reserved' => [], 'skipped' => []], $sx['dispatcher']->dispatch(), 'nothing is dispatched before admission');
Assert::false(ScheduleDispatcher::admittedIn($schedules), 'and no schedule counts as admitted');

$grantOnly = X::decision($sx, ['SCHEDULED_OBSERVATION'], [], [$sx['scheduled_grant']['grant_digest']]);
Assert::same('OWNER_DECISION_DOES_NOT_AUTHORIZE_SCHEDULE', $schedules->admit($scope, $proposed['monitor_schedule_version_id'], $grantOnly['ref'], $grantOnly['sha256'])->reasonCode(), 'admitting the grant budget does not admit the recurrence');
$wrongPurpose = X::decision($sx, ['MANUAL_MONITOR'], [], [], '', [$proposed['schedule_digest']]);
Assert::same('OWNER_DECISION_DOES_NOT_AUTHORIZE_SCHEDULE', $schedules->admit($scope, $proposed['monitor_schedule_version_id'], $wrongPurpose['ref'], $wrongPurpose['sha256'])->reasonCode(), 'a decision for another purpose does not admit a schedule');
$decision = X::decision($sx, ['SCHEDULED_OBSERVATION'], [], [], '', [$proposed['schedule_digest']]);
$admitted = S::value($schedules->admit($scope, $proposed['monitor_schedule_version_id'], $decision['ref'], $decision['sha256']));
Assert::same('ADMITTED', $admitted['status'], 'the exact decision admits the exact version');
Assert::true(ScheduleDispatcher::admittedIn($schedules), 'now one schedule is current');
Assert::false(ScheduleDispatcher::admittedAnywhere($db, $clock, dirname(__DIR__, 2)), "a decision outside the deployed tree's docs/decisions admits nothing for the deployed scheduler");
$versionId = $proposed['monitor_schedule_version_id'];

// ============================================================ occurrence 1: the baseline

$tick = $sx['runner']->tick(5, 20000, 300);
Assert::same(1, count($tick['dispatch']['reserved']), 'the tick reserves the due occurrence');
Assert::same(1, $tick['claimed'], 'and claims its MONITOR_STEP job');
Assert::same(1, $tick['advanced'], 'and advances it');
$first = $db->one('SELECT o.*, x.outcome, x.monitor_run_id, x.run_stage FROM monitor_schedule_occurrences o JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id = o.monitor_schedule_occurrence_id WHERE o.monitor_schedule_version_id = ?', [$versionId]);
Assert::same(['BASELINE_RECORDED', 'BASELINE'], [$first['outcome'], $first['run_stage']], 'the first capture records a baseline and settles');
$job = $db->one("SELECT state, attempt_count, lease_owner FROM jobs WHERE subject_id = ? AND job_type = 'MONITOR_STEP'", [$first['monitor_schedule_occurrence_id']]);
Assert::same(['SETTLED', 1, null], [$job['state'], (int) $job['attempt_count'], $job['lease_owner']], 'one attempt, lease released, job settled');
$run = $db->one('SELECT * FROM monitor_runs WHERE monitor_run_id = ?', [$first['monitor_run_id']]);
Assert::same([$scope['operator_id'], $sx['service_identity_id'], ScheduleService::runKey($first['monitor_schedule_occurrence_id'])], [$run['initiated_by_operator_id'], $run['initiated_by_service_identity_id'], $run['idempotency_key']], 'the run names the delegator AND the service, keyed by its occurrence');
$events = $db->all('SELECT event_version, actor_operator_id, actor_service_identity_id, delegating_operator_id FROM monitor_run_events WHERE monitor_run_id = ? ORDER BY sequence_no', [$first['monitor_run_id']]);
Assert::true($events !== [], 'the scheduled capture is journaled');
foreach ($events as $event) {
    Assert::same([2, null, $sx['service_identity_id'], $scope['operator_id']], [(int) $event['event_version'], $event['actor_operator_id'], $event['actor_service_identity_id'], $event['delegating_operator_id']], 'every scheduled event is v2: the service acted, under the operator\'s delegation — never recorded as the human');
}
$replay = (new RunJournal($db, $clock))->replay($scope, $first['monitor_run_id']);
Assert::same('PASS', $replay->value()['integrity'] ?? null, 'a v2 chain replays with integrity');
Assert::same(1, $sx['grants']->consumed($sx['scheduled_grant']['execution_grant_id'], 'SOURCE_CAPTURE'), 'one capture unit of the SCHEDULED_OBSERVATION grant is spent');
Assert::same(0, $sx['grants']->consumed($manual['execution_grant_id'], 'SOURCE_CAPTURE'), 'and none of the manual grant');
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM tool_requests t JOIN tool_execution_outcomes o ON o.tool_request_id = t.tool_request_id WHERE t.correlation_id = ? AND o.outcome_status = 'SUCCEEDED'", [$first['monitor_run_id']]),
    'exactly one governed source read succeeded for the occurrence',
);
$occurrenceScope = MonitorPrincipal::service($sx['service_identity_id'], $scope['operator_id'], $first['monitor_schedule_occurrence_id'])->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same(
    ['actor_type' => 'SERVICE', 'actor_id' => $sx['service_identity_id']],
    array_intersect_key(\Allfather\Loopia\Workflow\SourceCaptureStage::contextScope($occurrenceScope), ['actor_type' => 1, 'actor_id' => 1]),
    'context reads and the M06 source request name the SERVICE principal, not the delegating operator',
);

// The same slot is not reserved twice.
$again = $sx['runner']->tick(5, 20000, 300);
Assert::same([], $again['dispatch']['reserved'], 'a second tick in the same slot reserves nothing');
Assert::same(0, $again['claimed'], 'and claims nothing');

// ============================================================ occurrence 2: a change, queued for a human

$clock->advanceMs(86_400_000);
$second = $sx['runner']->tick(5, 20000, 300);
Assert::same(1, count($second['dispatch']['reserved']), 'the next slot is reserved a day later');
$queued = $db->one(
    "SELECT o.monitor_schedule_occurrence_id, x.outcome, x.monitor_run_id, x.run_stage, x.run_revision FROM monitor_schedule_occurrences o
     JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id = o.monitor_schedule_occurrence_id
     WHERE o.monitor_schedule_version_id = ? AND o.slot_index = 1",
    [$versionId]
);
Assert::same(['WAITING_FOR_OPERATOR', 'DIFFED'], [$queued['outcome'], $queued['run_stage']], 'a changed page is compared and queued at the inspection boundary');
$waitingJob = $db->one("SELECT state, attempt_count, lease_owner FROM jobs WHERE subject_id = ?", [$queued['monitor_schedule_occurrence_id']]);
Assert::same(['WAITING_FOR_OPERATOR', 1, null], [$waitingJob['state'], (int) $waitingJob['attempt_count'], $waitingJob['lease_owner']], 'waiting is a successful resting state: one attempt, lease released');
Assert::same(0, $sx['model']->calls, 'no model call was made, by anything');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_attempts'), 'and no model attempt was even reserved');

$list = S::value($sx['monitor']->list($scope));
Assert::same([$queued['monitor_run_id']], array_column($list['scheduled_queue'], 'monitor_run_id'), 'the operator sees the run in the scheduled queue');
$third = $sx['runner']->tick(5, 20000, 300);
Assert::same(0, $third['claimed'], 'a waiting occurrence is not re-claimed — no repeated calls');

// ============================================================ the service stops where a human must start

$servicePrincipal = MonitorPrincipal::service($sx['service_identity_id'], $scope['operator_id'], $queued['monitor_schedule_occurrence_id']);
$serviceScope = $servicePrincipal->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same('MONITOR_STAGE_REQUIRES_HUMAN_OPERATOR', $sx['monitor']->advance($serviceScope, $queued['monitor_run_id'], str_repeat('a', 64))->reasonCode(), 'a service cannot advance a model stage, digest or not');
Assert::same('MONITOR_REVIEW_REQUIRES_HUMAN_OPERATOR', $sx['monitor']->review($serviceScope, $queued['monitor_run_id'], ['decision' => 'APPROVE', 'assessment' => str_repeat('x', 40), 'candidate_digest' => str_repeat('b', 64)])->reasonCode(), 'a service cannot record a human review');
Assert::same('MONITOR_OPERATOR_REQUIRED', $sx['monitor']->stop($serviceScope, $queued['monitor_run_id'])->reasonCode(), 'or stop a run');
Assert::same('MONITOR_OPERATOR_REQUIRED', $sx['monitor']->detail($serviceScope, $queued['monitor_run_id'])->reasonCode(), 'or read the workbench');
Assert::same(0, $sx['model']->calls, 'still no model call');

// The human takes over at the boundary: the request is inspected under the MANUAL_MONITOR grant.
$detail = S::value($sx['monitor']->detail($scope, $queued['monitor_run_id']));
Assert::same('DIFFED', $detail['run']['stage'], 'the operator opens the queued run at DIFFED');
Assert::true(is_array($detail['preview_request']) && isset($detail['preview_request']['request_digest']), 'and is shown the exact request to inspect');
Assert::same('MONITOR_REQUEST_INSPECTION_REQUIRED', $sx['monitor']->advance($scope, $queued['monitor_run_id'], null)->reasonCode(), 'the human must still supply the inspected digest');
$advanced = $sx['monitor']->advance($scope, $queued['monitor_run_id'], $detail['preview_request']['request_digest']);
Assert::true($advanced->isOk(), 'with it, the human advances the model stage: ' . ($advanced->isOk() ? '' : $advanced->reasonCode()));
Assert::same(1, $sx['model']->calls, 'the one model call is the human-inspected one');
$humanEvent = $db->one('SELECT event_version, actor_operator_id, actor_service_identity_id FROM monitor_run_events WHERE monitor_run_id = ? ORDER BY sequence_no DESC LIMIT 1', [$queued['monitor_run_id']]);
Assert::same([1, $scope['operator_id'], null], [(int) $humanEvent['event_version'], $humanEvent['actor_operator_id'], $humanEvent['actor_service_identity_id']], 'and the human step is a v1 operator event in the same chain');
Assert::same('PASS', (new RunJournal($db, $clock))->replay($scope, $queued['monitor_run_id'])->value()['integrity'] ?? null, 'a mixed v2/v1 chain replays');

// ============================================================ occurrence 3: no change settles

$clock->advanceMs(86_400_000);
$sx['runner']->tick(5, 20000, 300);
$nochange = $db->one('SELECT x.outcome, x.run_stage FROM monitor_schedule_occurrences o JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id = o.monitor_schedule_occurrence_id WHERE o.monitor_schedule_version_id = ? AND o.slot_index = 2', [$versionId]);
Assert::same(['NO_CHANGE', 'NO_CHANGE'], [$nochange['outcome'] ?? null, $nochange['run_stage'] ?? null], 'an unchanged page settles as NO_CHANGE without queuing anyone');

// ============================================================ revocation stops the next occurrence

$clock->advanceMs(86_400_000);
$sx['dispatcher']->dispatch();
$pending = $db->one('SELECT monitor_schedule_occurrence_id FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id = ? AND slot_index = 3', [$versionId]);
Assert::true(is_array($pending), 'the dispatcher reserves slot 3 on its own');
$sourceCallsBefore = $sx['source']->calls;
S::value($sx['grants']->suspend($scope, $sx['scheduled_grant']['execution_grant_id'], 'OPERATOR_STAND_DOWN'));
$afterSuspend = $sx['runner']->tick(5, 20000, 300);
Assert::same(1, $afterSuspend['claimed'], 'the already reserved occurrence is still claimed');
$refusedOutcome = $db->one('SELECT outcome, reason_code FROM monitor_schedule_occurrence_outcomes WHERE monitor_schedule_occurrence_id = ?', [$pending['monitor_schedule_occurrence_id']]);
Assert::same(['REFUSED', 'SCHEDULE_GRANT_NOT_ADMITTED'], [$refusedOutcome['outcome'], $refusedOutcome['reason_code']], 'but its delegation is re-read and refused before any capture');
Assert::same($sourceCallsBefore, $sx['source']->calls, 'no source fetch happened');
$clock->advanceMs(86_400_000);
$stopped = $sx['dispatcher']->dispatch();
Assert::same(['SCHEDULE_GRANT_NOT_ADMITTED' => 1], $stopped['skipped'], 'and no new occurrence is reserved while the parent grant is suspended');

S::value($schedules->suspend($scope, $versionId, 'OPERATOR_STAND_DOWN'));
Assert::same('SUSPENDED', $schedules->status($versionId), 'the schedule itself can be suspended');
Assert::same([], $schedules->admittedCandidates(10), 'and leaves the candidate set');
Assert::same(
    'MONITOR_SERVICE_DELEGATION_NOT_ADMITTED',
    X::service($db, $clock, $sx, $sx['source'], $sx['model'], $sx['resolver'], $sx['admission'])->startConfigured($serviceScope, $sx['mission_id'], ScheduleService::runKey($queued['monitor_schedule_occurrence_id']))->reasonCode(),
    'a composition without the schedule owner refuses every service principal',
);

// ============================================================ the private CLI

$envFile = Harness::childEnvFile();
$definitionFile = sys_get_temp_dir() . '/schedule-definition-' . bin2hex(random_bytes(4)) . '.json';
try {
    $cli = static function (array $args) use ($envFile): array {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/ops/monitor/schedule.php', ...$args, '--env=' . $envFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), json_decode((string) $out, true), (string) $err];
    };
    $ids = ['--tenant-id=' . $scope['tenant_id'], '--workspace-id=' . $scope['workspace_id'], '--operator-id=' . $scope['operator_id']];
    [$code, $out] = $cli(['status', ...$ids, '--schedule-version-id=' . $versionId]);
    Assert::same([0, 'SUSPENDED', false, false], [$code, $out['result']['status'] ?? null, $out['result']['usable_now'] ?? null, $out['result']['grants_model_execution'] ?? null], 'status reports the suspended version and that it grants no model execution');
    [$code, $out] = $cli(['queue', ...$ids]);
    Assert::same([0, []], [$code, $out['result']['waiting'] ?? null], 'the queue is empty once the human took the run over');
    file_put_contents($definitionFile, json_encode(S::input($sx, $clock, ['monitor_schedule_id' => $proposed['monitor_schedule_id']]), JSON_THROW_ON_ERROR));
    [$code, $out] = $cli(['plan', ...$ids, '--schedule=' . $definitionFile]);
    Assert::same([0, 2, false], [$code, $out['result']['definition']['schedule_version'] ?? null, $out['result']['grants_execution'] ?? null], 'plan prints a successor digest and grants nothing');
    [$code, $out] = $cli(['admit', ...$ids, '--schedule-version-id=' . $versionId, '--decision=' . $decision['ref'], '--decision-sha256=' . $decision['sha256']]);
    Assert::same([1, 'OWNER_DECISION_NOT_FOUND'], [$code, $out['reason_code'] ?? null], "the deployed CLI reads only the deployed tree's docs/decisions — a decision anywhere else admits nothing");
    [$code] = $cli(['enable', ...$ids]);
    Assert::same(2, $code, 'there is no enable command');
} finally {
    @unlink($envFile);
    @unlink($definitionFile);
}
