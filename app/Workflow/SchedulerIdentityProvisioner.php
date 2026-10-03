<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\{AccessGuard,Permissions};

/**
 * Governed provisioning for the tenant-bound SYSTEM_SCHEDULER service principal used by P07.
 *
 * The service identity is NOT an execution grant and a workspace membership is NOT a schedule
 * admission. This owner only creates the narrow M02 principal that a separately admitted
 * SCHEDULED_OBSERVATION schedule may delegate capture/diff work to.
 *
 * Canonical authority: Allfatherbusinessintelligence PR369
 * (ROLE_SYSTEM_SCHEDULER_OBSERVER; exact four-permission seat).
 */
final class SchedulerIdentityProvisioner
{
    public const ROLE_KEY = MonitorPrincipal::SERVICE_ROLE_KEY;
    public const SERVICE_CLASS = 'SYSTEM_SCHEDULER';
    public const SERVICE_PURPOSE = 'P07 recurring observation: governed capture and diff only';

    /** Human authority needed to create the service identity + membership. */
    private const PROVISION_PERMISSIONS = ['tenant.administer', 'identity.register', 'role.assign'];
    /** Human authority needed to withdraw the identity + membership. */
    private const STAND_DOWN_PERMISSIONS = ['tenant.administer', 'identity.suspend_revoke', 'role.revoke'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Dry-run the exact service identity and membership this owner would create.
     *
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     */
    public function plan(array $scope): Outcome
    {
        if (!$this->humanMay($scope, self::PROVISION_PERMISSIONS)) {
            return Outcome::refuse('SCHEDULER_IDENTITY_PROVISION_DENIED');
        }

        $validated = $this->validateScopeAndRole($scope);
        if (!$validated->isOk()) {
            return $validated;
        }
        $v = $validated->value();

        $serviceKey = self::serviceKey($scope['tenant_id'], $scope['workspace_id']);
        $existing = $this->db->one(
            'SELECT service_identity_id, tenant_id, service_class, service_purpose, status
             FROM service_identities WHERE service_key = ?',
            [$serviceKey]
        );

        $serviceAction = 'CREATE';
        $serviceId = null;
        if ($existing !== null) {
            if (
                (string) ($existing['tenant_id'] ?? '') !== $scope['tenant_id']
                || (string) $existing['service_class'] !== self::SERVICE_CLASS
                || (string) $existing['service_purpose'] !== self::SERVICE_PURPOSE
            ) {
                return Outcome::refuse('SCHEDULER_IDENTITY_CONFLICT');
            }
            if ((string) $existing['status'] !== 'ACTIVE') {
                return Outcome::refuse('SCHEDULER_IDENTITY_NOT_ACTIVE', [
                    'status' => (string) $existing['status'],
                ]);
            }
            $serviceAction = 'REUSE';
            $serviceId = (string) $existing['service_identity_id'];
        }

        $membershipAction = 'CREATE';
        $membershipId = null;
        if ($serviceId !== null) {
            $memberships = $this->db->all(
                "SELECT m.membership_id, m.workspace_id, m.role_id, m.status, m.valid_from, m.valid_until,
                        r.role_key, r.role_version
                 FROM workspace_memberships m
                 JOIN roles r ON r.role_id = m.role_id
                 WHERE m.tenant_id = ? AND m.principal_type = 'SERVICE' AND m.principal_id = ?
                 ORDER BY m.workspace_id, m.created_at, m.membership_id",
                [$scope['tenant_id'], $serviceId]
            );
            $now = $this->clock->now();
            foreach ($memberships as $membership) {
                $status = (string) $membership['status'];
                $roleKey = (string) $membership['role_key'];
                $validUntil = $membership['valid_until'] === null ? null : (string) $membership['valid_until'];
                $current = $status === 'ACTIVE'
                    && (string) $membership['valid_from'] <= $now
                    && ($validUntil === null || $validUntil > $now);

                // A service key is deterministically scoped to one workspace. Any other CURRENT
                // membership — even a seemingly harmless read role — makes this no longer the
                // single-purpose service principal the canonical decision authorizes.
                if ($current && (
                    (string) $membership['workspace_id'] !== $scope['workspace_id']
                    || $roleKey !== self::ROLE_KEY
                )) {
                    return Outcome::refuse('SCHEDULER_IDENTITY_BROADER_MEMBERSHIP_PRESENT', [
                        'workspace_id' => (string) $membership['workspace_id'],
                        'role_key' => $roleKey,
                    ]);
                }
                if ((string) $membership['workspace_id'] === $scope['workspace_id'] && $roleKey === self::ROLE_KEY) {
                    if ($status !== 'ACTIVE') {
                        return Outcome::refuse('SCHEDULER_MEMBERSHIP_NOT_ACTIVE', [
                            'status' => $status,
                        ]);
                    }
                    if (!$current) {
                        return Outcome::refuse('SCHEDULER_MEMBERSHIP_NOT_CURRENT');
                    }
                    $membershipAction = 'REUSE';
                    $membershipId = (string) $membership['membership_id'];
                }
            }
        }

        $definition = [
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'service_key' => $serviceKey,
            'service_class' => self::SERVICE_CLASS,
            'service_purpose' => self::SERVICE_PURPOSE,
            'role_key' => self::ROLE_KEY,
            'role_id' => $v['role_id'],
            'role_version' => $v['role_version'],
            'permissions' => $v['permissions'],
        ];

        return Outcome::ok([
            'definition' => $definition,
            'plan_digest' => Digest::canonicalDigest(['scheduler_identity' => $definition]),
            'service_action' => $serviceAction,
            'membership_action' => $membershipAction,
            'service_identity_id' => $serviceId,
            'membership_id' => $membershipId,
            'grants_execution' => false,
            'admits_schedule' => false,
        ]);
    }

    /**
     * Apply exactly the plan a human reviewed.
     *
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     */
    public function provision(array $scope, string $reviewedPlanDigest): Outcome
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $reviewedPlanDigest) !== 1) {
            return Outcome::refuse('SCHEDULER_IDENTITY_PLAN_DIGEST_REQUIRED');
        }

        return $this->db->transaction(function (Database $tx) use ($scope, $reviewedPlanDigest): Outcome {
            // Serialize all provisioning for this tenant; the plan is re-read under this lock.
            $tenant = $tx->one(
                'SELECT tenant_id FROM tenants WHERE tenant_id = ? FOR UPDATE',
                [$scope['tenant_id']]
            );
            if ($tenant === null) {
                return Outcome::refuse('TENANT_NOT_FOUND');
            }

            $plan = $this->plan($scope);
            if (!$plan->isOk()) {
                return $plan;
            }
            $p = $plan->value();
            if (!hash_equals((string) $p['plan_digest'], $reviewedPlanDigest)) {
                return Outcome::refuse('SCHEDULER_IDENTITY_PLAN_CHANGED');
            }

            $serviceId = $p['service_identity_id'];
            $serviceCreated = false;
            if ($p['service_action'] === 'CREATE') {
                $serviceId = Ids::new();
                try {
                    $tx->insert('service_identities', [
                        'service_identity_id' => $serviceId,
                        'tenant_id' => $scope['tenant_id'],
                        'schema_version' => '1',
                        'display_name' => 'Monitor scheduler observer',
                        'service_key' => $p['definition']['service_key'],
                        'service_purpose' => self::SERVICE_PURPOSE,
                        'service_class' => self::SERVICE_CLASS,
                        'status' => 'ACTIVE',
                        'created_at' => $this->clock->now(),
                    ]);
                    $serviceCreated = true;
                } catch (\PDOException $error) {
                    return Database::classify($error, 'SCHEDULER_IDENTITY_ALREADY_EXISTS');
                }
            }

            $membershipId = $p['membership_id'];
            $membershipCreated = false;
            if ($p['membership_action'] === 'CREATE') {
                $membershipId = Ids::new();
                try {
                    $tx->insert('workspace_memberships', [
                        'membership_id' => $membershipId,
                        'tenant_id' => $scope['tenant_id'],
                        'workspace_id' => $scope['workspace_id'],
                        'principal_type' => 'SERVICE',
                        'principal_id' => $serviceId,
                        'role_id' => $p['definition']['role_id'],
                        'role_version' => $p['definition']['role_version'],
                        'status' => 'ACTIVE',
                        'valid_from' => $this->clock->now(),
                        'created_at' => $this->clock->now(),
                    ]);
                    $membershipCreated = true;
                } catch (\PDOException $error) {
                    return Database::classify($error, 'SCHEDULER_MEMBERSHIP_ALREADY_EXISTS');
                }
            }

            return Outcome::ok([
                'service_identity_id' => $serviceId,
                'membership_id' => $membershipId,
                'service_created' => $serviceCreated,
                'membership_created' => $membershipCreated,
                'role_key' => self::ROLE_KEY,
                'plan_digest' => $p['plan_digest'],
                'grants_execution' => false,
                'admits_schedule' => false,
            ]);
        });
    }

    /**
     * Deliberately withdraw the service seat. Provisioning never silently revives this state.
     *
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     */
    public function standDown(array $scope, string $mode): Outcome
    {
        if (!in_array($mode, ['suspend', 'revoke'], true)) {
            return Outcome::refuse('SCHEDULER_IDENTITY_STAND_DOWN_MODE_INVALID');
        }
        if (!$this->humanMay($scope, self::STAND_DOWN_PERMISSIONS)) {
            return Outcome::refuse('SCHEDULER_IDENTITY_STAND_DOWN_DENIED');
        }

        $serviceKey = self::serviceKey($scope['tenant_id'], $scope['workspace_id']);
        $service = $this->db->one(
            'SELECT service_identity_id, tenant_id, service_class, status
             FROM service_identities WHERE service_key = ?',
            [$serviceKey]
        );
        if ($service === null
            || (string) ($service['tenant_id'] ?? '') !== $scope['tenant_id']
            || (string) $service['service_class'] !== self::SERVICE_CLASS) {
            return Outcome::refuse('SCHEDULER_IDENTITY_NOT_FOUND');
        }

        $target = $mode === 'suspend' ? 'SUSPENDED' : 'REVOKED';
        $serviceId = (string) $service['service_identity_id'];

        return $this->db->transaction(function (Database $tx) use ($scope, $serviceId, $target): Outcome {
            $tx->all(
                'SELECT membership_id FROM workspace_memberships
                 WHERE tenant_id = ? AND workspace_id = ?
                   AND principal_type = ? AND principal_id = ? FOR UPDATE',
                [$scope['tenant_id'], $scope['workspace_id'], 'SERVICE', $serviceId]
            );
            $tx->run(
                "UPDATE workspace_memberships m
                 JOIN roles r ON r.role_id = m.role_id
                 SET m.status = ?
                 WHERE m.tenant_id = ? AND m.workspace_id = ?
                   AND m.principal_type = 'SERVICE' AND m.principal_id = ?
                   AND r.role_key = ? AND m.status <> ?",
                [$target, $scope['tenant_id'], $scope['workspace_id'], $serviceId, self::ROLE_KEY, $target]
            );
            $tx->run(
                'UPDATE service_identities SET status = ?
                 WHERE service_identity_id = ? AND tenant_id = ? AND service_class = ? AND status <> ?',
                [$target, $serviceId, $scope['tenant_id'], self::SERVICE_CLASS, $target]
            );

            return Outcome::ok([
                'service_identity_id' => $serviceId,
                'service_status' => $target,
                'membership_status' => $target,
                'grants_execution' => false,
            ]);
        });
    }

    /**
     * Read the exact service seat in this scope.
     *
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     */
    public function status(array $scope): Outcome
    {
        if (!$this->humanMay($scope, ['identity.read'])) {
            return Outcome::refuse('SCHEDULER_IDENTITY_READ_DENIED');
        }

        $service = $this->db->one(
            "SELECT s.service_identity_id, s.tenant_id, s.service_key, s.service_class, s.status,
                    m.membership_id, m.status AS membership_status, r.role_key, r.role_version
             FROM service_identities s
             LEFT JOIN workspace_memberships m
               ON m.tenant_id = s.tenant_id AND m.workspace_id = ?
              AND m.principal_type = 'SERVICE' AND m.principal_id = s.service_identity_id
             LEFT JOIN roles r ON r.role_id = m.role_id
             WHERE s.service_key = ?",
            [$scope['workspace_id'], self::serviceKey($scope['tenant_id'], $scope['workspace_id'])]
        );
        if ($service === null || (string) ($service['tenant_id'] ?? '') !== $scope['tenant_id']) {
            return Outcome::refuse('SCHEDULER_IDENTITY_NOT_FOUND');
        }

        return Outcome::ok([
            'service_identity_id' => (string) $service['service_identity_id'],
            'service_key' => (string) $service['service_key'],
            'service_class' => (string) $service['service_class'],
            'service_status' => (string) $service['status'],
            'membership_id' => $service['membership_id'] === null ? null : (string) $service['membership_id'],
            'membership_status' => $service['membership_status'] === null ? null : (string) $service['membership_status'],
            'role_key' => $service['role_key'] === null ? null : (string) $service['role_key'],
            'role_version' => $service['role_version'] === null ? null : (int) $service['role_version'],
            'grants_execution' => false,
        ]);
    }

    public static function serviceKey(string $tenantId, string $workspaceId): string
    {
        return 'monitor-scheduler-' . substr(hash('sha256', $tenantId . "\0" . $workspaceId), 0, 32);
    }

    /**
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     * @param list<string> $permissions
     */
    private function humanMay(array $scope, array $permissions): bool
    {
        $guard = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($guard->evaluate(
                'OPERATOR',
                $scope['operator_id'],
                $scope['tenant_id'],
                $scope['workspace_id'],
                $permission,
                'SYSTEM_SCHEDULER'
            )['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope
     */
    private function validateScopeAndRole(array $scope): Outcome
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!isset($scope[$key]) || !Ids::isValid((string) $scope[$key])) {
                return Outcome::refuse('SCHEDULER_IDENTITY_SCOPE_INVALID');
            }
        }

        $workspace = $this->db->one(
            "SELECT w.status AS workspace_status, t.status AS tenant_status
             FROM workspaces w JOIN tenants t ON t.tenant_id = w.tenant_id
             WHERE w.workspace_id = ? AND w.tenant_id = ?",
            [$scope['workspace_id'], $scope['tenant_id']]
        );
        if ($workspace === null
            || (string) $workspace['workspace_status'] !== 'ACTIVE'
            || (string) $workspace['tenant_status'] !== 'ACTIVE') {
            return Outcome::refuse('SCHEDULER_IDENTITY_SCOPE_NOT_ACTIVE');
        }

        $role = $this->db->one(
            "SELECT role_id, role_version FROM roles
             WHERE role_key = ? AND status = 'ACTIVE'
             ORDER BY role_version DESC LIMIT 1",
            [self::ROLE_KEY]
        );
        if ($role === null) {
            return Outcome::refuse('SCHEDULER_ROLE_NOT_DEFINED');
        }

        $permissions = array_map(
            static fn (array $row): string => (string) $row['permission_key'],
            $this->db->all(
                'SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key',
                [(string) $role['role_id']]
            )
        );
        $expected = MonitorPrincipal::SERVICE_ROLE_PERMISSIONS;
        sort($expected, SORT_STRING);
        $actual = $permissions;
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            return Outcome::refuse('SCHEDULER_ROLE_AUTHORITY_DRIFT', [
                'role_key' => self::ROLE_KEY,
            ], 'INTEGRITY', 'S4');
        }

        return Outcome::ok([
            'role_id' => (string) $role['role_id'],
            'role_version' => (int) $role['role_version'],
            'permissions' => $actual,
        ]);
    }
}
