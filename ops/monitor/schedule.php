<?php
declare(strict_types=1);

/**
 * Private CLI for recurring Monitor observation schedules (P07). There is no HTTP mutation route.
 *
 * Usage (every command also takes --env=<private env> --tenant-id --workspace-id --operator-id):
 *   plan            --schedule=<definition.json>   normalize and print the digest a decision must list
 *   propose         --schedule=<definition.json>   record the version as PROPOSED (grants nothing)
 *   admit           --schedule-version-id --decision=docs/decisions/<file>.json --decision-sha256=<hex>
 *   suspend|revoke  --schedule-version-id --reason=<REASON_CODE>
 *   status          --schedule-version-id
 *   queue                                          runs waiting for a human at request inspection
 *
 * The definition file is a JSON object with exactly: monitor_schedule_id (null for a new series, or
 * the series id for a successor version), mission_id, execution_grant_id (an existing
 * SCHEDULED_OBSERVATION grant), service_identity_id (a tenant-bound SYSTEM_SCHEDULER identity with
 * its own workspace membership), cadence_seconds (hourly floor, 31-day ceiling, and never below the
 * source policy's own floor), max_occurrences (no more than the grant's capture budget), valid_from,
 * valid_until (inside the grant window).
 *
 * AUTHORITY IS NOT CREATED HERE. `admit` succeeds only when a reviewed owner decision under
 * docs/decisions/ lists this version's exact digest under `authorizes.monitor_schedules` for the
 * SCHEDULED_OBSERVATION purpose, and the parent grant is itself ADMITTED. An admitted schedule
 * observes and queues: it never calls a model, never inspects a request and never records a review.
 * Output is identifiers, digests, counts and states only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Workflow\{ExecutionGrantStore,MissionConfiguration,OwnerDecisionRegistry,ScheduleService};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'schedule: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
$known = ['env', 'tenant-id', 'workspace-id', 'operator-id', 'schedule', 'schedule-version-id', 'decision', 'decision-sha256', 'reason'];
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], $known, $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
if (!in_array($command, ['plan', 'propose', 'admit', 'suspend', 'revoke', 'status', 'queue'], true)) {
    $fail('usage: schedule.php <plan|propose|admit|suspend|revoke|status|queue> --env=... --tenant-id=... --workspace-id=... --operator-id=... [...]');
}

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$decisions = OwnerDecisionRegistry::forRoot($root);
$grants = new ExecutionGrantStore($db, $clock, new PromptApprovalStore($db, $clock, $decisions), $decisions);
$schedules = new ScheduleService($db, $clock, $grants, $decisions, new MissionConfiguration($db, $clock));
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];

$definition = static function () use ($need, $fail, $root): array {
    $path = realpath($need('schedule'));
    $public = realpath($root . '/public_html');
    if ($path === false || !is_file($path) || filesize($path) > 16384 || ($public !== false && str_starts_with($path, $public . DIRECTORY_SEPARATOR))) {
        $fail('the schedule definition file is missing, too large, or under the served root');
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $fail('the schedule definition is not valid JSON');
    }
    if (!is_array($value)) {
        $fail('the schedule definition must be a JSON object');
    }

    return $value;
};

/** Read-only commands still need a current read permission in the scope, like any other read. */
$mayRead = static function () use ($db, $clock, $scope): bool {
    return (new AccessGuard($db, $clock))->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], 'mission.read', 'MONITOR_SCHEDULE')['decision'] === 'ALLOW';
};

$result = match ($command) {
    'plan' => $schedules->plan($scope, $definition()),
    'propose' => $schedules->propose($scope, $definition()),
    'admit' => $schedules->admit($scope, $need('schedule-version-id'), $need('decision'), $need('decision-sha256')),
    'suspend' => $schedules->suspend($scope, $need('schedule-version-id'), $need('reason')),
    'revoke' => $schedules->revoke($scope, $need('schedule-version-id'), $need('reason')),
    'status' => (static function () use ($schedules, $scope, $need, $mayRead, $db): \Allfather\Loopia\Core\Outcome {
        if (!$mayRead()) {
            return \Allfather\Loopia\Core\Outcome::refuse('SCHEDULE_READ_DENIED');
        }
        $version = $schedules->find($scope['tenant_id'], $scope['workspace_id'], $need('schedule-version-id'));
        if ($version === null) {
            return \Allfather\Loopia\Core\Outcome::refuse('SCHEDULE_NOT_FOUND');
        }
        $current = $schedules->current($version['monitor_schedule_version_id']);

        return \Allfather\Loopia\Core\Outcome::ok([
            'monitor_schedule_version_id' => $version['monitor_schedule_version_id'],
            'monitor_schedule_id' => $version['monitor_schedule_id'], 'schedule_version' => (int) $version['schedule_version'],
            'schedule_digest' => $version['schedule_digest'], 'status' => $schedules->status($version['monitor_schedule_version_id']),
            'usable_now' => $current->isOk(), 'not_usable_reason' => $current->isOk() ? null : $current->reasonCode(),
            'occurrences' => (int) $db->scalar('SELECT COUNT(*) FROM monitor_schedule_occurrences WHERE monitor_schedule_version_id=?', [$version['monitor_schedule_version_id']]),
            'max_occurrences' => (int) $version['max_occurrences'], 'cadence_seconds' => (int) $version['cadence_seconds'],
            'observation_mode' => $version['observation_mode'], 'grants_model_execution' => false,
        ]);
    })(),
    'queue' => $mayRead()
        ? \Allfather\Loopia\Core\Outcome::ok(['waiting' => $db->all(
            "SELECT o.monitor_schedule_id, o.schedule_version, o.scheduled_for, o.skipped_slots, x.monitor_run_id, x.run_revision, x.recorded_at
             FROM monitor_schedule_occurrence_outcomes x JOIN monitor_schedule_occurrences o ON o.monitor_schedule_occurrence_id = x.monitor_schedule_occurrence_id
             JOIN monitor_runs r ON r.monitor_run_id = x.monitor_run_id
             WHERE o.tenant_id = ? AND o.workspace_id = ? AND x.outcome = 'WAITING_FOR_OPERATOR' AND r.stage = 'DIFFED'
             ORDER BY x.recorded_at LIMIT 100",
            [$scope['tenant_id'], $scope['workspace_id']]
        )])
        : \Allfather\Loopia\Core\Outcome::refuse('SCHEDULE_READ_DENIED'),
};

BetaCli::emit(
    $result->isOk() ? ['ok' => true, 'command' => $command, 'result' => $result->value()] : ['ok' => false, 'command' => $command, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? 0 : 1,
);
