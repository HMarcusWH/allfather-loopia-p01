<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Workflow\MonitorOperations;

/**
 * GET /console/api/v1/monitor/runs and /console/api/v1/monitor/run?run=<id> (P09).
 *
 * READ-ONLY METADATA for the React console. It goes through the same MonitorOperations a human uses
 * (list/detail authorize as the session's operator and replay the journal), and it exposes structure
 * — stage, revision, mode, who acted (operator or scheduler) and when, admission state, artifact
 * KINDS and digests, current reliance — but never a candidate's text, a prompt, the exact request
 * body or a review assessment. Those are inspected, and every mutation is made, in the scriptless
 * SSR workbench at /console/monitor; this adapter only links there. A GET here fetches nothing,
 * calls no model and releases nothing.
 */
final class MonitorReadAdapter
{
    /** MonitorService::detail() authorizes mission.read AND evidence.read; evidence.read is the narrower. */
    private const DETAIL_PERMISSION = 'evidence.read';

    /** @param \Closure(array<string, string>): MonitorOperations $monitorFor */
    public function __construct(private readonly \Closure $monitorFor)
    {
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function runs(?array $session): array
    {
        $scope = $this->scope($session);
        if ($scope === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }
        $listed = ($this->monitorFor)($scope)->list($scope);
        if (!$listed->isOk()) {
            return self::refusal($listed->reasonCode(), 'mission.read');
        }
        $value = $listed->value();

        return [
            'decision' => 'VISIBLE',
            'required_permission' => 'mission.read',
            'reason_codes' => ['GRANTED'],
            'data' => [
                'rows' => array_map(static fn (array $run): array => [
                    'monitor_run_id' => (string) $run['monitor_run_id'],
                    'mission_id' => (string) $run['mission_id'],
                    'execution_mode' => (string) $run['execution_mode'],
                    'stage' => (string) $run['stage'],
                    'revision' => (int) $run['revision'],
                    'created_at' => (string) $run['created_at'],
                    'updated_at' => (string) $run['updated_at'],
                    'workbench_href' => '/console/monitor?run=' . rawurlencode((string) $run['monitor_run_id']),
                ], $value['runs'] ?? []),
                'scheduled_queue' => array_map(static fn (array $item): array => [
                    'monitor_run_id' => (string) $item['monitor_run_id'],
                    'scheduled_for' => (string) $item['scheduled_for'],
                    'skipped_slots' => (int) $item['skipped_slots'],
                    'queued_at' => (string) $item['recorded_at'],
                    'workbench_href' => '/console/monitor?run=' . rawurlencode((string) $item['monitor_run_id']),
                ], $value['scheduled_queue'] ?? []),
                'missions' => array_map(static fn (array $m): array => [
                    'mission_id' => (string) $m['mission_id'], 'title' => (string) ($m['title'] ?? ''),
                    'status' => (string) ($m['status'] ?? ''), 'execution_mode' => (string) ($m['execution_mode'] ?? ''),
                ], $value['missions'] ?? []),
            ],
        ];
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function run(?array $session, string $selector): array
    {
        $scope = $this->scope($session);
        if ($scope === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }
        if (!Ids::isValid($selector)) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => self::DETAIL_PERMISSION, 'reason_codes' => ['SELECTOR_INVALID']];
        }
        $found = ($this->monitorFor)($scope)->detail($scope, $selector);
        if (!$found->isOk()) {
            return self::refusal($found->reasonCode(), self::DETAIL_PERMISSION);
        }
        $d = $found->value();
        $run = $d['run'];
        $events = array_map(static fn (array $e): array => [
            'sequence_no' => (int) $e['sequence_no'],
            'event_kind' => (string) $e['event_kind'],
            'from_stage' => (string) ($e['payload']['from_stage'] ?? ''),
            'to_stage' => (string) ($e['payload']['to_stage'] ?? ''),
            'actor' => (int) ($e['event_version'] ?? 1) === 2
                ? ['type' => 'SERVICE', 'service_identity_id' => (string) $e['actor_service_identity_id'], 'delegating_operator_id' => (string) $e['delegating_operator_id']]
                : ['type' => 'OPERATOR', 'operator_id' => (string) $e['actor_operator_id']],
            'recorded_at' => (string) $e['recorded_at'],
        ], $d['events'] ?? []);

        return [
            'decision' => 'VISIBLE',
            'required_permission' => self::DETAIL_PERMISSION,
            'reason_codes' => ['GRANTED'],
            'data' => [
                'run' => [
                    'monitor_run_id' => (string) $run['monitor_run_id'], 'mission_id' => (string) $run['mission_id'],
                    'execution_mode' => (string) $run['execution_mode'], 'stage' => (string) $run['stage'],
                    'revision' => (int) $run['revision'], 'created_at' => (string) $run['created_at'], 'updated_at' => (string) $run['updated_at'],
                    'started_by_scheduler' => ($run['initiated_by_service_identity_id'] ?? null) !== null,
                    'reason_code' => isset($run['cursor']['reason_code']) ? (string) $run['cursor']['reason_code'] : null,
                ],
                'integrity' => (string) ($d['integrity'] ?? 'UNKNOWN'),
                'events' => $events,
                'artifacts' => array_map(static fn (array $a): array => ['kind' => (string) $a['kind'], 'digest' => (string) $a['digest']], $d['artifacts'] ?? []),
                'awaiting' => match (true) {
                    in_array($run['stage'], ['REVIEW_REQUIRED', 'ROUTING_REVIEW'], true) => 'HUMAN_REVIEW',
                    in_array($run['stage'], ['DIFFED', 'ANALYZED', 'DRAFTED', 'ROUTING_PREPARED'], true) => 'REQUEST_INSPECTION',
                    in_array($run['stage'], ['NEW', 'CAPTURED', 'P01_RELEASED'], true) => 'OPERATOR_STEP',
                    default => 'NOTHING',
                },
                'request_preview_available' => is_array($d['preview_request'] ?? null),
                'request_preview_reason_code' => $d['preview_reason_code'] ?? null,
                'execution_admission' => array_intersect_key((array) ($d['execution_admission'] ?? []), array_flip(['required', 'admitted', 'reason_code', 'valid_until', 'model_attempts', 'source_captures'])),
                'current_reliance' => $d['current_reliance'] ?? [],
                'workbench_href' => '/console/monitor?run=' . rawurlencode((string) $run['monitor_run_id']),
            ],
        ];
    }

    /** @return array<string, string>|null */
    private function scope(?array $session): ?array
    {
        if ($session === null || empty($session['tenant_id']) || empty($session['operator_id']) || empty($session['active_workspace_id'])) {
            return null;
        }

        return ['tenant_id' => (string) $session['tenant_id'], 'workspace_id' => (string) $session['active_workspace_id'], 'operator_id' => (string) $session['operator_id']];
    }

    /** @return array<string, mixed> */
    private static function refusal(string $reason, string $permission): array
    {
        return match ($reason) {
            'MONITOR_RUN_NOT_FOUND' => ['decision' => 'NOT_FOUND', 'required_permission' => $permission, 'reason_codes' => [$reason]],
            'MONITOR_ACCESS_DENIED', 'MONITOR_SCOPE_REQUIRED', 'MONITOR_OPERATOR_REQUIRED' => ['decision' => 'DENIED', 'required_permission' => $permission, 'reason_codes' => [$reason]],
            default => ['decision' => 'UNAVAILABLE', 'reason_codes' => [$reason]],
        };
    }
}
