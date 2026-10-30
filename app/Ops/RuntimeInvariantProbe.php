<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * Deterministic proof that the migrated RUNTIME database enforces two load-bearing storage guards.
 *
 * This deliberately does not call M10. M10's runtime callers and input-reference vocabulary are
 * canonical conformance surfaces, so an Ops-only qualification probe must not widen either one.
 * Instead it exercises a real migrated append-only governance table (`access_evaluation_records`)
 * plus the workspace external-effect CHECK on one explicitly named internal TEST scope.
 */
final class RuntimeInvariantProbe
{
    public const RESOURCE_TYPE = 'RUNTIME_INVARIANT_PROBE';
    public const REASON_CODE = 'LOOPIA_RUNTIME_INVARIANT_PROBE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * RT-01: no-DB candidate packaging posture, separate from the existing
     * runtime invariant probe which exercises migrated native controls.
     */
    public static function candidateSchemaStatus(string $root): \Allfather\Loopia\Core\Outcome
    {
        $plan=HistorySchemaPlan::inspect($root);
        if(!$plan->isOk())return $plan;
        return \Allfather\Loopia\Core\Outcome::ok([
            'version'=>'rt01.runtime-candidate-posture.v1',
            'status'=>'CANDIDATE_SCHEMA_PRESENT_RUNTIME_HARD_DISABLED',
            'plan_digest'=>$plan->value()['plan_digest'],
            'expected_physical_tables'=>$plan->value()['table_count'],
            'expected_append_only_triggers'=>$plan->value()['trigger_count'],
            'current_native_database_verified'=>false,
            'candidate_tables_installed_on_runtime'=>false,
            'u01_through_u10_owner_adopted'=>false,
            'provider_source_scheduler_enabled'=>false,
            'customer_visibility_authorized'=>false,
        ]);
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

        // Prove the real migrated CHECK, against the exact named workspace. The row exists, so a
        // successful statement is a failed qualification rather than an ambiguous zero-row update.
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

        // A durable HOLD_FOR_REVIEW access-evaluation row is a safe qualification marker: it grants
        // nothing, dispatches nothing, and its table is one of the real append-only governance
        // tables installed by migration 001. Re-running reuses the same marker.
        $reasonJson = json_encode([self::REASON_CODE], JSON_THROW_ON_ERROR);
        $existing = $this->db->one(
            'SELECT access_evaluation_id FROM access_evaluation_records
             WHERE tenant_id = ? AND workspace_id = ? AND actor_type = ? AND actor_id = ?
               AND requested_permission = ? AND resource_type = ? AND decision = ? AND reason_codes = ?
             ORDER BY evaluated_at ASC LIMIT 1',
            [
                (string) $scope['tenant_id'],
                (string) $scope['workspace_id'],
                'SYSTEM',
                (string) $scope['workspace_id'],
                'battlecard.read',
                self::RESOURCE_TYPE,
                'HOLD_FOR_REVIEW',
                $reasonJson,
            ],
        );
        $reused = $existing !== null;
        $recordId = $existing === null ? Ids::new() : (string) $existing['access_evaluation_id'];
        if ($existing === null) {
            $this->db->insert('access_evaluation_records', [
                'access_evaluation_id' => $recordId,
                'schema_version' => '1',
                'tenant_id' => (string) $scope['tenant_id'],
                'workspace_id' => (string) $scope['workspace_id'],
                'actor_type' => 'SYSTEM',
                'actor_id' => (string) $scope['workspace_id'],
                'requested_permission' => 'battlecard.read',
                'resource_type' => self::RESOURCE_TYPE,
                'resource_id' => (string) $scope['workspace_id'],
                'applicable_policy_version' => null,
                'matched_role_ids' => '[]',
                'decision' => 'HOLD_FOR_REVIEW',
                'reason_codes' => $reasonJson,
                'severity' => null,
                'incident_required' => 0,
                'evaluated_at' => $this->clock->now(),
                'correlation_id' => null,
            ]);
        }

        $deleteRejected = false;
        try {
            $this->db->run(
                'DELETE FROM access_evaluation_records WHERE access_evaluation_id = ?',
                [$recordId],
            );
        } catch (\PDOException $error) {
            $deleteRejected = (string) $error->getCode() === '45000'
                && str_contains(
                    (string) ($error->errorInfo[2] ?? ''),
                    'access_evaluation_records is append-only',
                );
        }
        if (!$deleteRejected) {
            return self::failed('APPEND_ONLY_TRIGGER_NOT_PROVEN');
        }
        if ((int) $this->db->scalar(
            'SELECT COUNT(*) FROM access_evaluation_records WHERE access_evaluation_id = ?',
            [$recordId],
        ) !== 1) {
            return self::failed('RUNTIME_PROBE_RECORD_MISSING_AFTER_DELETE_ATTEMPT');
        }

        return [
            'status' => 'RUNTIME_INVARIANTS_VERIFIED',
            'workspace_external_effect_guard' => 'PASS',
            'access_evaluation_append_only' => 'PASS',
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
