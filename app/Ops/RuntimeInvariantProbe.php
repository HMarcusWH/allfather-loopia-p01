<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * Deterministic proof that the migrated RUNTIME database enforces two load-bearing invariants.
 *
 * This is intentionally not the full test suite: the full suite belongs on the disposable
 * qualification database. This probe acts only on one explicitly named internal TEST scope and
 * leaves one append-only replay-test finding as durable proof of what was exercised.
 */
final class RuntimeInvariantProbe
{
    public const SUMMARY = 'LOOPIA_RUNTIME_INVARIANT_PROBE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $tenantKey, string $workspaceKey): array
    {
        $scope = $this->db->one(
            'SELECT t.tenant_id, t.tenant_type, t.status AS tenant_status,
                    w.workspace_id, w.workspace_type, w.environment_class,
                    w.status AS workspace_status, w.external_side_effects_allowed
             FROM tenants t
             JOIN workspaces w ON w.tenant_id = t.tenant_id
             WHERE t.tenant_key = ? AND w.workspace_key = ?',
            [$tenantKey, $workspaceKey],
        );
        if ($scope === null) {
            return self::failed('RUNTIME_PROBE_SCOPE_NOT_FOUND');
        }
        if (
            (string) $scope['tenant_status'] !== 'ACTIVE'
            || (string) $scope['workspace_status'] !== 'ACTIVE'
            || (string) $scope['tenant_type'] !== 'TEST'
            || (string) $scope['workspace_type'] !== 'TEST'
            || (string) $scope['environment_class'] !== 'TEST'
        ) {
            return self::failed('RUNTIME_PROBE_SCOPE_NOT_INTERNAL_TEST');
        }
        if ((int) $scope['external_side_effects_allowed'] !== 0) {
            return self::failed('RUNTIME_PROBE_SCOPE_ALREADY_UNSAFE');
        }

        // Prove the real migrated CHECK, against the exact named workspace. Zero rows affected is
        // not accepted: this row exists and the attempted unsafe value must be rejected by MariaDB.
        try {
            $this->db->run(
                'UPDATE workspaces SET external_side_effects_allowed = 1
                 WHERE tenant_id = ? AND workspace_id = ?',
                [(string) $scope['tenant_id'], (string) $scope['workspace_id']],
            );
            return self::failed('WORKSPACE_EXTERNAL_EFFECT_CHECK_NOT_ENFORCED');
        } catch (\PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if (!in_array($driverCode, [3819, 4025], true)) {
                return self::failed('WORKSPACE_EXTERNAL_EFFECT_CHECK_NOT_PROVEN');
            }
        }
        if ((int) $this->db->scalar(
            'SELECT external_side_effects_allowed FROM workspaces WHERE workspace_id = ?',
            [(string) $scope['workspace_id']],
        ) !== 0) {
            return self::failed('WORKSPACE_EXTERNAL_EFFECT_VALUE_CHANGED');
        }

        $ledger = new DecisionLedger($this->db, $this->clock);
        try {
            $streamId = $ledger->ensureActiveStream(
                (string) $scope['tenant_id'],
                (string) $scope['workspace_id'],
                'TEST',
            );
        } catch (\Throwable) {
            return self::failed('RUNTIME_PROBE_LEDGER_UNAVAILABLE');
        }

        $existing = $this->db->one(
            'SELECT decision_record_id FROM decision_records
             WHERE ledger_stream_id = ? AND record_type = ? AND safe_decision_summary = ?
             ORDER BY record_sequence_number ASC LIMIT 1',
            [$streamId, 'REPLAY_TEST_FINDING_RECORDED', self::SUMMARY],
        );
        $reused = $existing !== null;
        if ($existing === null) {
            $appended = $ledger->append(
                $streamId,
                'REPLAY_TEST_FINDING_RECORDED',
                'REPLAY_TEST_MATERIAL',
                'REPLAY_RECORDED',
                self::SUMMARY,
                [[
                    'owner_module_id' => 'M02',
                    'input_object_type' => 'workspace',
                    'input_object_id' => (string) $scope['workspace_id'],
                    'reference_role' => 'OTHER',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ]],
                DecisionLedger::SERVICE_IDENTITY_M10,
                ['reason_codes' => ['LOOPIA_RUNTIME_INVARIANT_PROBE']],
            );
            if (!$appended->isOk()) {
                return self::failed('RUNTIME_PROBE_LEDGER_APPEND_REFUSED');
            }
            $recordId = (string) $appended->value()['decision_record_id'];
        } else {
            $recordId = (string) $existing['decision_record_id'];
            if (($ledger->verifyRecordPayload($recordId)['ok'] ?? false) !== true) {
                return self::failed('RUNTIME_PROBE_EXISTING_RECORD_INVALID');
            }
        }

        $deleteRejected = false;
        try {
            $this->db->run('DELETE FROM decision_records WHERE decision_record_id = ?', [$recordId]);
        } catch (\PDOException $error) {
            $deleteRejected = (string) $error->getCode() === '45000'
                && str_contains(
                    (string) ($error->errorInfo[2] ?? ''),
                    'decision_records cannot be deleted (INV-013)',
                );
        }
        if (!$deleteRejected) {
            return self::failed('DECISION_APPEND_ONLY_TRIGGER_NOT_PROVEN');
        }
        if ((int) $this->db->scalar(
            'SELECT COUNT(*) FROM decision_records WHERE decision_record_id = ?',
            [$recordId],
        ) !== 1) {
            return self::failed('DECISION_PROBE_RECORD_MISSING_AFTER_DELETE_ATTEMPT');
        }

        $integrity = $ledger->verifyStream($streamId);
        if (($integrity['status'] ?? '') !== 'PASS') {
            return self::failed('RUNTIME_PROBE_LEDGER_INTEGRITY_FAILED');
        }

        return [
            'status' => 'RUNTIME_INVARIANTS_VERIFIED',
            'workspace_external_effect_guard' => 'PASS',
            'decision_record_append_only' => 'PASS',
            'ledger_integrity' => 'PASS',
            'probe_record_reused' => $reused,
            'writes_external_systems' => false,
        ];
    }

    /** @return array{status: string, reason_codes: list<string>, writes_external_systems: false} */
    private static function failed(string $reason): array
    {
        return [
            'status' => 'RUNTIME_INVARIANTS_FAILED',
            'reason_codes' => [$reason],
            'writes_external_systems' => false,
        ];
    }
}
