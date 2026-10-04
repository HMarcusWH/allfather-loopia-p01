<?php
declare(strict_types=1);

/**
 * P07 — the scheduler under contention and under attack.
 *
 * Concurrency is exercised with REAL second connections, not by calling twice on one handle: a race
 * through one PDO connection races nothing. The security half forges principals, scopes and rows,
 * and proves each is refused by the service or by the database itself.
 */

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Jobs\JobMachine;
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorPrincipal,RunJournal,ScheduleDispatcher,ScheduleService};
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,ScheduleFixture as S,Seed};

$db = Harness::freshDb();
$other = Harness::secondConnection();
if ($db === null || $other === null) { Assert::skip(Harness::unavailableReason()); return; }
$DAY = 86_400_000;
$clock = Harness::clock('2026-08-03T06:00:00.000Z');
$sx = S::create($db, $clock, ['Page one.', 'Page two.', 'Page two.', 'Page two.']);
$scope = $sx['scope'];
$schedule = S::admitted($sx, S::input($sx, $clock));
$versionId = $schedule['monitor_schedule_version_id'];

// ============================================================ duplicate ticks: one reservation

$otherSchedules = new ScheduleService($other, $clock, new \Allfather\Loopia\Workflow\ExecutionGrantStore($other, $clock, new \Allfather\Loopia\PromptOps\PromptApprovalStore($other, $clock, $sx['decisions']), $sx['decisions']), $sx['decisions'], new MissionConfiguration($other, $clock));
$otherDispatcher = new ScheduleDispatcher($other, $clock, $otherSchedules, new \Allfather\Loopia\Workflow\ExecutionGrantStore($other, $clock, new \Allfather\Loopia\PromptOps\PromptApprovalStore($other, $clock, $sx['decisions']), $sx['decisions']), new JobMachine($other, $clock));

// Connection B holds the series lock mid-transaction; A's reservation must not slip past it.
$other->pdo()->beginTransaction();
$other->all('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE monitor_schedule_id = ? FOR UPDATE', [$schedule['monitor_schedule_id']]);
$db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
$blocked = $sx['dispatcher']->dispatch();
Assert::same(['RESERVATION_CONTENDED' => 1], $blocked['skipped'], 'a tick that cannot take the series lock reserves nothing and says why');
$other->pdo()->rollBack();
$db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');

$first = $sx['dispatcher']->dispatch();
$second = $otherDispatcher->dispatch();
Assert::same(1, count($first['reserved']) + count($second['reserved']), 'two dispatchers on two connections reserve the slot once');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id = ?', [$versionId]), 'exactly one occurrence row exists');
Assert::same(1, (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE job_type = 'MONITOR_STEP' AND tenant_id = ?", [$scope['tenant_id']]), 'and exactly one MONITOR_STEP job');

// A direct duplicate of the slot is refused by the database, not only by the service.
$occurrence = $db->one('SELECT * FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id = ?', [$versionId]);
Assert::throws(fn () => $db->insert('monitor_schedule_occurrences', ['monitor_schedule_occurrence_id' => Ids::new()] + array_diff_key($occurrence, ['monitor_schedule_occurrence_id' => 1])), \PDOException::class, 'the (schedule, version, instant) identity is UNIQUE in storage');

// Two runners claim disjoint sets: only one holds the job.
$claimA = $sx['jobs']->claim('tick:A', 10, 300, [], ['MONITOR_STEP' => ['PENDING']]);
$claimB = (new JobMachine($other, $clock))->claim('tick:B', 10, 300, [], ['MONITOR_STEP' => ['PENDING']]);
Assert::same(1, count($claimA) + count($claimB), 'two ticks on two connections claim the MONITOR_STEP job once');
$sx['jobs']->releaseClaim((string) $claimA[0]['job_id'], 'tick:A');

// A legacy PENDING handler can never pick up a typed job.
Assert::same([], $sx['jobs']->claim('tick:legacy', 10, 300, ['PENDING']), 'a legacy PENDING registry claims no MONITOR_STEP job');
Assert::same([], $sx['jobs']->claim('tick:wide', 10, 300, null), 'nor does the "every legacy state" claim');

// ============================================================ a held source lock hands the claim back unattempted

$database = (string) $db->scalar('SELECT DATABASE()');
$sourceLock = 'monitor-source:' . substr(hash('sha256', $database . "\0" . $sx['monitored_source_id']), 0, 48);
Assert::same(1, (int) $other->scalar('SELECT GET_LOCK(?, 0)', [$sourceLock]), 'another connection holds the source');
$busy = $sx['runner']->tick(5, 20000, 300);
Assert::same([1, 0, 1], [$busy['claimed'], $busy['advanced'], $busy['released']], 'the step is claimed, cannot start, and is handed back');
$job = $db->one("SELECT state, attempt_count, lease_owner FROM jobs WHERE subject_id = ?", [$occurrence['monitor_schedule_occurrence_id']]);
Assert::same(['PENDING', 0, null], [$job['state'], (int) $job['attempt_count'], $job['lease_owner']], 'no attempt spent, no lease left, still pending');
Assert::same(0, $sx['source']->calls, 'and nothing was fetched');
Assert::same(0, $sx['grants']->consumed($sx['scheduled_grant']['execution_grant_id'], 'SOURCE_CAPTURE'), 'and no capture unit was spent on contention');
$other->scalar('SELECT RELEASE_LOCK(?)', [$sourceLock]);
$sx['runner']->tick(5, 20000, 300);
Assert::same('BASELINE_RECORDED', (string) $db->scalar('SELECT outcome FROM monitor_schedule_occurrence_outcomes WHERE monitor_schedule_occurrence_id = ?', [$occurrence['monitor_schedule_occurrence_id']]), 'once the source is free the occurrence completes');

// ============================================================ two schedules, one source

Assert::same('SCHEDULE_SOURCE_ALREADY_SCHEDULED', $sx['schedules']->propose($scope, S::input($sx, $clock, ['cadence_seconds' => 172800]))->reasonCode(), 'a second series on the same source is refused before it exists');

// ============================================================ forged principals

$clock->advanceMs($DAY);
$sx['dispatcher']->dispatch();
$pending = $db->one("SELECT o.monitor_schedule_occurrence_id FROM monitor_schedule_occurrences o LEFT JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id = o.monitor_schedule_occurrence_id WHERE o.monitor_schedule_version_id = ? AND x.monitor_schedule_outcome_id IS NULL", [$versionId]);
Assert::true($pending !== null, 'a fresh occurrence is pending');
$occurrenceId = (string) $pending['monitor_schedule_occurrence_id'];
$monitor = $sx['monitor'];

$impostor = S::serviceIdentity($db, $clock, $scope['tenant_id'], $scope['workspace_id']);
$asImpostor = MonitorPrincipal::service($impostor, $scope['operator_id'], $occurrenceId)->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same('SCHEDULE_PRINCIPAL_MISMATCH', $monitor->startConfigured($asImpostor, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'another scheduler identity cannot act on this occurrence');

$asScheduler = MonitorPrincipal::service($sx['service_identity_id'], $scope['operator_id'], $occurrenceId)->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same('MONITOR_SERVICE_RUN_OUT_OF_DELEGATION', $monitor->startConfigured($asScheduler, $sx['mission_id'], 'any-other-key')->reasonCode(), 'the service may start only the run its occurrence names');
$unknown = MonitorPrincipal::service($sx['service_identity_id'], $scope['operator_id'], Ids::new())->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same('SCHEDULE_OCCURRENCE_NOT_FOUND', $monitor->startConfigured($unknown, $sx['mission_id'], ScheduleService::runKey((string) $unknown['execution_principal']['occurrence_id']))->reasonCode(), 'an invented occurrence delegates nothing');
$delegatorSwap = Seed::operator($db, $clock, $scope['tenant_id'], 'Swapped delegator');
$swapped = MonitorPrincipal::service($sx['service_identity_id'], $delegatorSwap, $occurrenceId)->scope($scope['tenant_id'], $scope['workspace_id']);
Assert::same('MONITOR_DELEGATOR_ACCESS_DENIED', $monitor->startConfigured($swapped, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'a different delegator without authority is refused');
$malformed = $scope + ['execution_principal' => ['type' => 'SERVICE', 'service_identity_id' => $sx['service_identity_id'], 'occurrence_id' => $occurrenceId, 'release' => true]];
Assert::same('MONITOR_SCOPE_REQUIRED', $monitor->startConfigured($malformed, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'a principal carrying extra fields is malformed, not quietly an operator');
$operatorAsService = $scope + ['execution_principal' => ['type' => 'OPERATOR', 'service_identity_id' => $sx['service_identity_id'], 'occurrence_id' => $occurrenceId]];
Assert::same('MONITOR_SCOPE_REQUIRED', $monitor->list($operatorAsService)->reasonCode(), 'and no principal type other than SERVICE may ride in the field');

// A service cannot touch a run a human started.
$human = S::value($monitor->startConfigured($scope, $sx['mission_id'], 'human-run-' . substr(Ids::new(), 0, 8)));
Assert::same('MONITOR_SERVICE_RUN_OUT_OF_DELEGATION', $monitor->advance($asScheduler, $human['monitor_run_id'])->reasonCode(), "the service cannot advance a human's run");

// Losing its own membership stops the service even with a valid occurrence.
$db->run("UPDATE workspace_memberships SET status = 'REVOKED' WHERE principal_type = 'SERVICE' AND principal_id = ?", [$sx['service_identity_id']]);
Assert::same('MONITOR_SERVICE_ACCESS_DENIED', $monitor->startConfigured($asScheduler, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'a scheduler whose membership was revoked holds nothing');
$db->run("UPDATE workspace_memberships SET status = 'ACTIVE' WHERE principal_type = 'SERVICE' AND principal_id = ?", [$sx['service_identity_id']]);
$db->run("UPDATE service_identities SET status = 'SUSPENDED' WHERE service_identity_id = ?", [$sx['service_identity_id']]);
Assert::same('MONITOR_SERVICE_PRINCIPAL_INVALID', $monitor->startConfigured($asScheduler, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'a suspended scheduler identity holds nothing');
$db->run("UPDATE service_identities SET status = 'ACTIVE' WHERE service_identity_id = ?", [$sx['service_identity_id']]);

// The schedule's own owner decision is re-read: removing it withdraws the delegation.
$decisionPath = $sx['decision_dir'] . '/' . basename($schedule['decision']['ref']);
$bytes = (string) file_get_contents($decisionPath);
unlink($decisionPath);
Assert::same('OWNER_DECISION_NOT_FOUND', $monitor->startConfigured($asScheduler, $sx['mission_id'], ScheduleService::runKey($occurrenceId))->reasonCode(), 'a withdrawn owner decision stops the very next act');
file_put_contents($decisionPath, $bytes);
Assert::same(0, $sx['model']->calls, 'no forged principal reached a model');

// ============================================================ storage pins

$rejects = static function (callable $work, string $message): void {
    Assert::throws($work, \PDOException::class, $message);
};
$rejects(fn () => $db->run('UPDATE monitor_schedules SET cadence_seconds = 3600 WHERE monitor_schedule_version_id = ?', [$versionId]), 'a schedule version cannot be edited');
$rejects(fn () => $db->run('DELETE FROM monitor_schedule_events WHERE monitor_schedule_version_id = ?', [$versionId]), 'its lifecycle cannot be erased');
$rejects(fn () => $db->run('UPDATE monitor_schedule_occurrences SET skipped_slots = 0 WHERE monitor_schedule_version_id = ?', [$versionId]), 'an occurrence cannot be rewritten');
$rejects(fn () => $db->run("UPDATE monitor_schedule_occurrence_outcomes SET outcome = 'NO_CHANGE'"), 'an outcome cannot be rewritten');
$row = $db->one('SELECT * FROM monitor_schedules WHERE monitor_schedule_version_id = ?', [$versionId]);
$copy = array_replace($row, ['monitor_schedule_version_id' => Ids::new(), 'monitor_schedule_id' => Ids::new(), 'schedule_digest' => str_repeat('9', 64)]);
$rejects(fn () => $db->insert('monitor_schedules', array_replace($copy, ['cadence_seconds' => 60])), 'storage refuses a sub-hourly cadence');
$rejects(fn () => $db->insert('monitor_schedules', array_replace($copy, ['observation_mode' => 'OBSERVE_AND_INFER'])), 'storage has no unattended-inference mode');
$rejects(fn () => $db->insert('monitor_schedule_occurrence_outcomes', [
    'monitor_schedule_outcome_id' => Ids::new(), 'monitor_schedule_occurrence_id' => $occurrenceId, 'monitor_run_id' => null,
    'outcome' => 'WAITING_FOR_OPERATOR', 'run_stage' => null, 'run_revision' => null, 'reason_code' => null,
    'recorded_by_service_identity_id' => $sx['service_identity_id'], 'recorded_at' => $clock->now(),
]), 'a WAITING outcome must name a run at DIFFED');
$rejects(fn () => $db->insert('monitor_schedule_occurrence_outcomes', [
    'monitor_schedule_outcome_id' => Ids::new(), 'monitor_schedule_occurrence_id' => $occurrenceId, 'monitor_run_id' => $human['monitor_run_id'],
    'outcome' => 'WAITING_FOR_OPERATOR', 'run_stage' => 'REVIEW_REQUIRED', 'run_revision' => 3, 'reason_code' => null,
    'recorded_by_service_identity_id' => $sx['service_identity_id'], 'recorded_at' => $clock->now(),
]), 'and can never claim a later, human stage');
$rejects(fn () => $db->insert('jobs', [
    'job_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'job_type' => 'MONITOR_STEP', 'state' => 'PENDING', 'subject_kind' => 'MONITORED_SOURCE', 'subject_id' => Ids::new(),
    'idempotency_key' => 'forged', 'attempt_count' => 0, 'max_attempts' => 3, 'run_after' => $clock->now(),
    'correlation_id' => Ids::new(), 'created_at' => $clock->now(), 'updated_at' => $clock->now(),
]), 'a MONITOR_STEP job is only ever about an occurrence');
$rejects(fn () => $db->insert('jobs', [
    'job_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'job_type' => 'P01_SOURCE_MONITOR', 'state' => 'WAITING_FOR_OPERATOR', 'subject_kind' => 'MONITORED_SOURCE', 'subject_id' => Ids::new(),
    'idempotency_key' => 'forged-legacy', 'attempt_count' => 0, 'max_attempts' => 3, 'run_after' => $clock->now(),
    'correlation_id' => Ids::new(), 'created_at' => $clock->now(), 'updated_at' => $clock->now(),
]), 'a legacy job cannot borrow the typed resting states');
$runId = (string) $db->scalar('SELECT monitor_run_id FROM monitor_schedule_occurrence_outcomes WHERE monitor_run_id IS NOT NULL LIMIT 1');
$rejects(fn () => $db->insert('monitor_run_events', [
    'monitor_event_id' => Ids::new(), 'monitor_run_id' => $runId, 'sequence_no' => 999, 'event_kind' => 'FORGED', 'event_version' => 2,
    'actor_operator_id' => $scope['operator_id'], 'actor_service_identity_id' => $sx['service_identity_id'], 'delegating_operator_id' => $scope['operator_id'],
    'previous_digest' => null, 'event_digest' => str_repeat('0', 64), 'payload_json' => '{}', 'recorded_at' => $clock->now(),
]), 'a v2 event cannot ALSO name an operator actor');
$rejects(fn () => $db->insert('monitor_run_events', [
    'monitor_event_id' => Ids::new(), 'monitor_run_id' => $runId, 'sequence_no' => 998, 'event_kind' => 'FORGED', 'event_version' => 1,
    'actor_operator_id' => null, 'actor_service_identity_id' => $sx['service_identity_id'], 'delegating_operator_id' => null,
    'previous_digest' => null, 'event_digest' => str_repeat('0', 64), 'payload_json' => '{}', 'recorded_at' => $clock->now(),
]), 'and a v1 event must name the operator who acted');

// The two digest formats cannot be confused: the same transition digests differently as v1 and v2.
$digest = new ReflectionMethod(RunJournal::class, 'eventDigest');
$v1 = $digest->invoke(null, ['event_version' => 1, 'actor_operator_id' => $scope['operator_id'], 'actor_service_identity_id' => null, 'delegating_operator_id' => null], $runId, 1, 'K', null, ['x' => 1], '2026-01-01T00:00:00.000Z');
$v2 = $digest->invoke(null, ['event_version' => 2, 'actor_operator_id' => null, 'actor_service_identity_id' => $sx['service_identity_id'], 'delegating_operator_id' => $scope['operator_id']], $runId, 1, 'K', null, ['x' => 1], '2026-01-01T00:00:00.000Z');
Assert::notSame($v1, $v2, 'a service event can never be re-read as the delegating operator\'s act');
