<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/** A resumable composition journal, never a substitute for M10 release or M05 evidence. */
final class RunJournal
{
    public const NEXT = [
        'NEW' => ['CAPTURED', 'BASELINE'],
        'CAPTURED' => ['DIFFED', 'NO_CHANGE'],
        'DIFFED' => ['ANALYZED', 'NO_CHANGE'],
        'ANALYZED' => ['DRAFTED'],
        'DRAFTED' => ['REVIEW_REQUIRED'],
        'REVIEW_REQUIRED' => ['P01_RELEASED'],
        'P01_RELEASED' => ['ROUTING_PREPARED'],
        'ROUTING_PREPARED' => ['ROUTING_REVIEW'],
        'ROUTING_REVIEW' => ['COMPLETE'],
    ];

    public function __construct(private readonly Database $db, private readonly Clock $clock) {}

    public function create(array $scope, array $definition, string $key, string $mode): Outcome
    {
        if ($key === '' || strlen($key) > 191 || !in_array($mode, ['FIXTURE', 'NATIVE'], true)) {
            return Outcome::refuse('MONITOR_DEFINITION_INVALID');
        }
        $scopeRow = $this->db->one('SELECT w.environment_class FROM missions m JOIN workspaces w ON w.workspace_id = m.workspace_id AND w.tenant_id = m.tenant_id JOIN operators o ON o.tenant_id = m.tenant_id WHERE m.mission_id = ? AND m.tenant_id = ? AND m.workspace_id = ? AND m.status = ? AND w.status = ? AND o.operator_id = ? AND o.status = ?', [
            $definition['mission_id'] ?? '', $scope['tenant_id'], $scope['workspace_id'], 'ACTIVE', 'ACTIVE', $scope['operator_id'], 'ACTIVE',
        ]);
        if ($scopeRow === null) {
            return Outcome::refuse('MONITOR_MISSION_SCOPE_INVALID');
        }
        if ($mode === 'FIXTURE' && $scopeRow['environment_class'] !== 'TEST') {
            return Outcome::refuse('MONITOR_FIXTURE_REQUIRES_TEST_SCOPE');
        }
        $digest = Digest::canonicalDigest(['definition' => $definition, 'mode' => $mode]);
        $existing = $this->db->one('SELECT * FROM monitor_runs WHERE tenant_id = ? AND workspace_id = ? AND idempotency_key = ?', [$scope['tenant_id'], $scope['workspace_id'], $key]);
        if ($existing !== null) {
            return hash_equals((string) $existing['definition_digest'], $digest)
                ? Outcome::ok($this->decode($existing)) : Outcome::refuse('MONITOR_IDEMPOTENCY_CONFLICT');
        }
        try { return $this->db->transaction(function () use ($scope, $definition, $key, $mode, $digest): Outcome {
            $id = Ids::new();
            $now = $this->clock->now();
            $this->db->insert('monitor_runs', [
                'monitor_run_id' => $id, 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
                'mission_id' => $definition['mission_id'], 'initiated_by_operator_id' => $scope['operator_id'],
                'idempotency_key' => $key, 'definition_json' => json_encode($definition, JSON_THROW_ON_ERROR),
                'definition_digest' => $digest, 'execution_mode' => $mode, 'stage' => 'NEW', 'cursor_json' => '{}',
                'revision' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return Outcome::ok($this->find($scope, $id));
        }); } catch (\PDOException $error) {
            if (!str_contains($error->getMessage(),'uq_monitor_run_scope_key')) { throw $error; }
            $winner=$this->db->one('SELECT * FROM monitor_runs WHERE tenant_id=? AND workspace_id=? AND idempotency_key=?',[$scope['tenant_id'],$scope['workspace_id'],$key]);
            return $winner!==null && hash_equals($winner['definition_digest'],$digest)
                ? Outcome::ok($this->decode($winner)) : Outcome::refuse('MONITOR_IDEMPOTENCY_CONFLICT');
        }
    }

    public function find(array $scope, string $id): ?array
    {
        $row = $this->db->one('SELECT * FROM monitor_runs WHERE monitor_run_id = ? AND tenant_id = ? AND workspace_id = ?', [$id, $scope['tenant_id'], $scope['workspace_id']]);
        return $row === null ? null : $this->decode($row);
    }

    /** Session advisory lock avoids holding a database transaction across a bounded network call. */
    public function exclusive(array $scope, string $id, callable $work): Outcome
    {
        if ($this->find($scope, $id) === null) {
            return Outcome::refuse('MONITOR_RUN_NOT_FOUND');
        }
        $lock = 'monitor:' . substr(hash('sha256', $id), 0, 48);
        if ((int) $this->db->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return Outcome::refuse('MONITOR_RUN_BUSY');
        }
        try {
            return $work($this->find($scope, $id));
        } finally {
            $this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Same session lock, plus the exact revision the operator inspected. */
    public function exclusiveAtRevision(array $scope, string $id, int $expectedRevision, callable $work): Outcome
    {
        return $this->exclusive($scope, $id, static function (?array $run) use ($expectedRevision, $work): Outcome {
            if ($run === null) {
                return Outcome::refuse('MONITOR_RUN_NOT_FOUND');
            }
            if ((int) $run['revision'] !== $expectedRevision) {
                return Outcome::refuse('MONITOR_RUN_REVISION_CHANGED_RELOAD_REQUIRED');
            }

            return $work($run);
        });
    }

    /** Cursor is operational state. Event chain records the exact transition, not authority. */
    public function advance(array $scope, array $run, string $stage, array $cursor, string $kind): Outcome
    {
        $terminal = !isset(self::NEXT[$run['stage']]);
        if ($terminal || (!in_array($stage, self::NEXT[$run['stage']], true) && !in_array($stage, ['BLOCKED', 'FAILED', 'STOPPED'], true))) {
            return Outcome::refuse('MONITOR_TRANSITION_INVALID');
        }
        return $this->db->transaction(function () use ($scope, $run, $stage, $cursor, $kind): Outcome {
            $sequence = (int) $run['revision'] + 1;
            $at = $this->clock->now();
            $payload = ['from_stage' => $run['stage'], 'to_stage' => $stage, 'cursor' => $cursor];
            $digest = Digest::canonicalDigest([
                'run_id' => $run['monitor_run_id'], 'sequence' => $sequence, 'kind' => $kind,
                'actor' => $scope['operator_id'], 'previous_digest' => $run['event_digest'], 'payload' => $payload, 'at' => $at,
            ]);
            $changed = $this->db->run('UPDATE monitor_runs SET stage = ?, cursor_json = ?, revision = ?, event_digest = ?, updated_at = ? WHERE monitor_run_id = ? AND tenant_id = ? AND workspace_id = ? AND revision = ?', [
                $stage, json_encode($cursor, JSON_THROW_ON_ERROR), $sequence, $digest, $at,
                $run['monitor_run_id'], $scope['tenant_id'], $scope['workspace_id'], $run['revision'],
            ]);
            if ($changed !== 1) {
                return Outcome::refuse('MONITOR_RUN_REVISION_CONFLICT');
            }
            $this->db->insert('monitor_run_events', [
                'monitor_event_id' => Ids::new(), 'monitor_run_id' => $run['monitor_run_id'], 'sequence_no' => $sequence,
                'event_kind' => $kind, 'actor_operator_id' => $scope['operator_id'], 'previous_digest' => $run['event_digest'],
                'event_digest' => $digest, 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'recorded_at' => $at,
            ]);
            return Outcome::ok($this->find($scope, $run['monitor_run_id']));
        });
    }

    /** Pure readback: no transport, model, review, admission or release invocation is reachable. */
    public function replay(array $scope, string $id): Outcome
    {
        $run = $this->find($scope, $id);
        if ($run === null) {
            return Outcome::refuse('MONITOR_RUN_NOT_FOUND');
        }
        if (!hash_equals($run['definition_digest'], Digest::canonicalDigest(['definition' => $run['definition'], 'mode' => $run['execution_mode']]))) {
            return Outcome::refuse('MONITOR_DEFINITION_INTEGRITY_FAILED');
        }
        $previous = null;
        $sequence = 0;
        $events = $this->db->all('SELECT * FROM monitor_run_events WHERE monitor_run_id = ? ORDER BY sequence_no', [$id]);
        foreach ($events as &$event) {
            $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $digest = Digest::canonicalDigest(['run_id' => $id, 'sequence' => ++$sequence, 'kind' => $event['event_kind'], 'actor' => $event['actor_operator_id'], 'previous_digest' => $previous, 'payload' => $payload, 'at' => $event['recorded_at']]);
            if ((int) $event['sequence_no'] !== $sequence || $event['previous_digest'] !== $previous || !hash_equals($digest, $event['event_digest'])) {
                return Outcome::refuse('MONITOR_EVENT_INTEGRITY_FAILED');
            }
            $previous = $digest;
            $event['payload'] = $payload;
            unset($event['payload_json']);
        }
        unset($event);
        if ($sequence !== (int) $run['revision'] || $previous !== $run['event_digest']) {
            return Outcome::refuse('MONITOR_CURSOR_INTEGRITY_FAILED');
        }
        if ($events !== [] && ($run['stage'] !== $events[array_key_last($events)]['payload']['to_stage'] || $run['cursor'] !== $events[array_key_last($events)]['payload']['cursor'])) {
            return Outcome::refuse('MONITOR_CURSOR_INTEGRITY_FAILED');
        }
        if ($events === [] && ($run['stage'] !== 'NEW' || $run['cursor'] !== [])) {
            return Outcome::refuse('MONITOR_CURSOR_INTEGRITY_FAILED');
        }
        return Outcome::ok(['run' => $run, 'events' => $events, 'integrity' => 'PASS', 'reexecutes_model' => false, 'grants_release' => false]);
    }

    private function decode(array $row): array
    {
        $row['definition'] = json_decode($row['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['cursor'] = json_decode($row['cursor_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['definition_json'], $row['cursor_json']);
        return $row;
    }
}
