<?php
declare(strict_types=1);
namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Clock,FixedClock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Jobs\{JobMachine,MonitorStepHandler,SchedulerRunner};
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorService,RunJournal,ScheduleDispatcher,ScheduleService};

require_once __DIR__ . '/ExecutionFixture.php';

/**
 * A NATIVE Monitor mission prepared for scheduled observation, for offline tests only (P07).
 *
 * Built on ExecutionFixture, so the source and model transports are network-free and the model one
 * claims to be live. Adds what a real deployment would need before any occurrence could run: a
 * tenant-bound SYSTEM_SCHEDULER service identity with its OWN workspace membership, an admitted
 * SCHEDULED_OBSERVATION grant naming the configuring operator as principal, the schedule owner, and
 * the scheduler composition exactly as ScheduleDispatcher::runner() builds it — but pointed at the
 * fixture's decision directory and transports instead of the deployed ones.
 */
final class ScheduleFixture
{
    public const SERVICE_PERMISSIONS = ['mission.read', 'mission.transition', 'evidence.read', 'tool.invoke'];

    public static function create(Database $db, FixedClock $clock, array $pages, array $grantOverrides = []): array
    {
        $fx = ExecutionFixture::create($db, $clock, $pages);
        $scope = $fx['scope'];
        $serviceId = self::serviceIdentity($db, $clock, $scope['tenant_id'], $scope['workspace_id']);
        $from = Clock::parse($clock->now());
        $grantDefinition = ExecutionFixture::manualDefinition($db, $clock, $fx, [], array_replace([
            'purpose' => 'SCHEDULED_OBSERVATION', 'prompt_approval_ids' => [], 'credential_id' => null, 'credential_digest' => null,
            'limits' => ['max_model_attempts' => 0, 'max_source_captures' => 20],
            'valid_from' => Clock::format($from->modify('-1 hour')), 'valid_until' => Clock::format($from->modify('+60 days')),
        ], $grantOverrides));
        $grant = ExecutionFixture::admitted($fx, $grantDefinition);
        $schedules = new ScheduleService($db, $clock, $fx['grants'], $fx['decisions'], new MissionConfiguration($db, $clock));
        $monitor = ExecutionFixture::service($db, $clock, $fx, $fx['source'], $fx['model'], $fx['resolver'], $fx['admission'], true, $schedules);
        $jobs = new JobMachine($db, $clock);
        $dispatcher = new ScheduleDispatcher($db, $clock, $schedules, $fx['grants'], $jobs);
        $handler = new MonitorStepHandler($db, $clock, $schedules, $monitor, new RunJournal($db, $clock));
        $runner = new SchedulerRunner($jobs, ['MONITOR_STEP:PENDING' => $handler, 'MONITOR_STEP:FAILED' => $handler], static fn (): array => $dispatcher->dispatch());

        return $fx + [
            'service_identity_id' => $serviceId, 'scheduled_grant' => $grant, 'schedules' => $schedules,
            'monitor' => $monitor, 'jobs' => $jobs, 'dispatcher' => $dispatcher, 'handler' => $handler, 'runner' => $runner,
        ];
    }

    /** A tenant-bound scheduler identity holding exactly the Monitor permissions it needs, in one workspace. */
    public static function serviceIdentity(Database $db, Clock $clock, string $tenantId, string $workspaceId, string $class = 'SYSTEM_SCHEDULER', bool $member = true): string
    {
        $id = Ids::new();
        $db->insert('service_identities', [
            'service_identity_id' => $id, 'tenant_id' => $tenantId, 'schema_version' => '1',
            'display_name' => 'Monitor scheduler', 'service_key' => 'monitor-scheduler-' . substr($id, 0, 8),
            'service_purpose' => 'Scheduled capture and compare only', 'service_class' => $class, 'status' => 'ACTIVE',
            'created_at' => $clock->now(),
        ]);
        if ($member) {
            $role = Seed::role($db, $clock, 'scheduler-' . substr(Ids::new(), 0, 8), self::SERVICE_PERMISSIONS);
            $db->insert('workspace_memberships', [
                'membership_id' => Ids::new(), 'tenant_id' => $tenantId, 'workspace_id' => $workspaceId,
                'principal_type' => 'SERVICE', 'principal_id' => $id, 'role_id' => $role['role_id'], 'role_version' => 1,
                'status' => 'ACTIVE', 'valid_from' => $clock->now(), 'created_at' => $clock->now(),
            ]);
        }

        return $id;
    }

    /** A schedule input with sensible defaults, anchored one minute before "now". */
    public static function input(array $sx, FixedClock $clock, array $overrides = []): array
    {
        $now = Clock::parse($clock->now());

        return array_replace([
            'monitor_schedule_id' => null, 'mission_id' => $sx['mission_id'],
            'execution_grant_id' => $sx['scheduled_grant']['execution_grant_id'], 'service_identity_id' => $sx['service_identity_id'],
            'cadence_seconds' => 86400, 'max_occurrences' => 10,
            'valid_from' => Clock::format($now->modify('-1 minute')), 'valid_until' => Clock::format($now->modify('+30 days')),
        ], $overrides);
    }

    /** Propose a schedule, write the decision that lists its digest, admit it. */
    public static function admitted(array $sx, array $input): array
    {
        $proposed = ExecutionFixture::value($sx['schedules']->propose($sx['scope'], $input));
        $decision = ExecutionFixture::decision($sx, ['SCHEDULED_OBSERVATION'], [], [], '', [$proposed['schedule_digest']]);
        ExecutionFixture::value($sx['schedules']->admit($sx['scope'], $proposed['monitor_schedule_version_id'], $decision['ref'], $decision['sha256']));

        return $proposed + ['decision' => $decision];
    }

    public static function value(Outcome $outcome): mixed
    {
        return ExecutionFixture::value($outcome);
    }

    /** The model policy is untouched by scheduling; kept here so tests can assert it. */
    public static function modelPolicyDigest(): string
    {
        return ModelPolicy::digest();
    }
}
