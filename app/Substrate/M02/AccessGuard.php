<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * The M02 access-evaluation guard.
 *
 * Ported from `packages/allfather-control-substrate/src/m02/access.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * This is the UI-independent, SERVER-SIDE authority check. It allows a caller to REQUEST a
 * governed operation; it does not make that operation lawful by itself — M03..M10 gates still
 * apply. **Default-deny: absence of a matching active permission and scope is never permission**
 * (M02-DEC-008).
 *
 * The evaluation order is load-bearing and is preserved exactly, because each step's refusal code
 * and severity differ:
 *   1. excluded permission        -> S5 hard deny + incident, BEFORE any role or scope lookup
 *   2. tenant active
 *   3. principal mapped, in-tenant, active   (cross-tenant principal = S4 + incident)
 *   4. workspace required, in-tenant, active (cross-tenant workspace = S4 + incident)
 *   5. active membership exists
 *   6. a held role actually grants the permission
 *
 * Every decision is appended to `access_evaluation_records`. Denials are ALWAYS retained; an ALLOW
 * is retained only when the caller asks, so the audit ledger does not drown in routine reads while
 * every refusal stays reconstructable.
 */
final class AccessGuard
{
    public const GRANTED = 'GRANTED';
    public const EXCLUDED_PERMISSION_REQUESTED = 'EXCLUDED_PERMISSION_REQUESTED';
    public const TENANT_NOT_ACTIVE = 'TENANT_NOT_ACTIVE';
    public const IDENTITY_NOT_MAPPED = 'IDENTITY_NOT_MAPPED';
    public const IDENTITY_NOT_ACTIVE = 'IDENTITY_NOT_ACTIVE';
    public const SERVICE_IDENTITY_NOT_AUTHORIZED = 'SERVICE_IDENTITY_NOT_AUTHORIZED';
    public const TENANT_SCOPE_MISMATCH = 'TENANT_SCOPE_MISMATCH';
    public const WORKSPACE_REQUIRED = 'WORKSPACE_REQUIRED';
    public const WORKSPACE_SCOPE_MISMATCH = 'WORKSPACE_SCOPE_MISMATCH';
    public const WORKSPACE_NOT_ACTIVE = 'WORKSPACE_NOT_ACTIVE';
    public const ROLE_ASSIGNMENT_NOT_ACTIVE = 'ROLE_ASSIGNMENT_NOT_ACTIVE';
    public const PERMISSION_NOT_GRANTED = 'PERMISSION_NOT_GRANTED';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     access_evaluation_id: string,
     *     decision: 'ALLOW'|'DENY',
     *     matched_role_ids: list<string>,
     *     reason_codes: list<string>,
     *     severity: ?string,
     *     incident_required: bool,
     *     evaluated_at: string
     * }
     */
    public function evaluate(
        string $actorType,
        string $actorId,
        string $tenantId,
        ?string $workspaceId,
        string $requestedPermission,
        string $resourceType,
        ?string $resourceId = null,
        ?string $correlationId = null,
        bool $retainAllowDecision = false,
    ): array {
        $at = $this->clock->now();

        $deny = fn (string $reason, ?string $severity, bool $incident, array $matched = []): array
            => $this->record(
                $actorType, $actorId, $tenantId, $workspaceId, $requestedPermission, $resourceType,
                $resourceId, $correlationId, 'DENY', $matched, [$reason], $severity, $incident, $at, true,
            );

        // 1. Prohibited permission — hard deny before any role or scope evaluation. This is
        //    release-blocking: an excluded permission being *requested* at all is the event.
        if (Permissions::isExcluded($requestedPermission)) {
            return $deny(self::EXCLUDED_PERMISSION_REQUESTED, 'S5', true);
        }

        // 2. Tenant must exist and be active.
        $tenant = $this->db->one('SELECT status FROM tenants WHERE tenant_id = ?', [$tenantId]);
        if ($tenant === null || (string) $tenant['status'] !== 'ACTIVE') {
            return $deny(self::TENANT_NOT_ACTIVE, 'S2', false);
        }

        // 3. Principal identity must be mapped, in-tenant and active. A cross-tenant principal is
        //    a scope breach (S4, incident) — never a silent miss.
        $principal = $this->resolvePrincipal($actorType, $actorId, $tenantId);
        if ($principal['kind'] === 'DENY') {
            return $deny($principal['reason'], $principal['severity'], $principal['incident']);
        }
        $principalType = $principal['principal_type'];

        // 4. Workspace scope. V1's permission model is workspace-scoped, so a missing workspace is
        //    a refusal rather than a wildcard.
        if ($workspaceId === null || $workspaceId === '') {
            return $deny(self::WORKSPACE_REQUIRED, 'S1', false);
        }
        $workspace = $this->db->one(
            'SELECT tenant_id, status FROM workspaces WHERE workspace_id = ?',
            [$workspaceId]
        );
        if ($workspace === null || (string) $workspace['tenant_id'] !== $tenantId) {
            // Missing and cross-tenant are the SAME refusal: a distinct "exists but forbidden"
            // would let a caller enumerate another tenant's workspaces.
            return $deny(self::WORKSPACE_SCOPE_MISMATCH, 'S4', true);
        }
        if ((string) $workspace['status'] !== 'ACTIVE') {
            return $deny(self::WORKSPACE_NOT_ACTIVE, 'S2', false);
        }

        // 5. Active membership(s) for this principal in this workspace, valid at THIS instant.
        $roleIds = $this->activeMembershipRoleIds($workspaceId, $principalType, $actorId, $at);
        if ($roleIds === []) {
            return $deny(self::ROLE_ASSIGNMENT_NOT_ACTIVE, 'S2', false);
        }

        // 6. Does any held role actually grant the requested permission?
        $matched = $this->rolesGrantingPermission($roleIds, $requestedPermission);
        if ($matched === []) {
            return $deny(self::PERMISSION_NOT_GRANTED, 'S1', false);
        }

        return $this->record(
            $actorType, $actorId, $tenantId, $workspaceId, $requestedPermission, $resourceType,
            $resourceId, $correlationId, 'ALLOW', $matched, [self::GRANTED], null, false, $at,
            $retainAllowDecision,
        );
    }

    /** Convenience predicate for call sites that only need the boolean. */
    public function allows(
        string $actorType,
        string $actorId,
        string $tenantId,
        ?string $workspaceId,
        string $permission,
        string $resourceType,
        ?string $resourceId = null,
    ): bool {
        return $this->evaluate(
            $actorType, $actorId, $tenantId, $workspaceId, $permission, $resourceType, $resourceId
        )['decision'] === 'ALLOW';
    }

    /** @return array{kind: 'OK', principal_type: string}|array{kind: 'DENY', reason: string, severity: string, incident: bool} */
    private function resolvePrincipal(string $actorType, string $actorId, string $tenantId): array
    {
        if ($actorType === 'OPERATOR') {
            $operator = $this->db->one('SELECT tenant_id, status FROM operators WHERE operator_id = ?', [$actorId]);
            if ($operator === null) {
                return ['kind' => 'DENY', 'reason' => self::IDENTITY_NOT_MAPPED, 'severity' => 'S2', 'incident' => false];
            }
            if ((string) $operator['tenant_id'] !== $tenantId) {
                return ['kind' => 'DENY', 'reason' => self::TENANT_SCOPE_MISMATCH, 'severity' => 'S4', 'incident' => true];
            }
            if ((string) $operator['status'] !== 'ACTIVE') {
                return ['kind' => 'DENY', 'reason' => self::IDENTITY_NOT_ACTIVE, 'severity' => 'S2', 'incident' => false];
            }

            return ['kind' => 'OK', 'principal_type' => 'OPERATOR'];
        }

        if ($actorType === 'SERVICE') {
            $service = $this->db->one(
                'SELECT tenant_id, status FROM service_identities WHERE service_identity_id = ?',
                [$actorId]
            );
            if ($service === null) {
                return ['kind' => 'DENY', 'reason' => self::SERVICE_IDENTITY_NOT_AUTHORIZED, 'severity' => 'S2', 'incident' => false];
            }
            // A service identity may be tenant-wide (NULL tenant); a service bound to a DIFFERENT
            // tenant is a scope breach.
            if ($service['tenant_id'] !== null && (string) $service['tenant_id'] !== $tenantId) {
                return ['kind' => 'DENY', 'reason' => self::TENANT_SCOPE_MISMATCH, 'severity' => 'S4', 'incident' => true];
            }
            if ((string) $service['status'] !== 'ACTIVE') {
                return ['kind' => 'DENY', 'reason' => self::IDENTITY_NOT_ACTIVE, 'severity' => 'S2', 'incident' => false];
            }

            return ['kind' => 'OK', 'principal_type' => 'SERVICE'];
        }

        // VALKYRIE and SYSTEM actors do not hold M02 memberships; they act through a governed
        // path that carries its own authority. Requesting an M02 permission as one is not mapped.
        return ['kind' => 'DENY', 'reason' => self::IDENTITY_NOT_MAPPED, 'severity' => 'S2', 'incident' => false];
    }

    /** @return list<string> */
    private function activeMembershipRoleIds(
        string $workspaceId,
        string $principalType,
        string $principalId,
        string $at,
    ): array {
        $rows = $this->db->all(
            'SELECT role_id FROM workspace_memberships
             WHERE workspace_id = ?
               AND principal_type = ?
               AND principal_id = ?
               AND status = ?
               AND valid_from <= ?
               AND (valid_until IS NULL OR valid_until > ?)',
            [$workspaceId, $principalType, $principalId, 'ACTIVE', $at, $at]
        );

        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['role_id'], $rows)));
    }

    /** @param list<string> $roleIds @return list<string> */
    private function rolesGrantingPermission(array $roleIds, string $permission): array
    {
        if ($roleIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $rows = $this->db->all(
            "SELECT rp.role_id FROM role_permissions rp
             JOIN roles r ON r.role_id = rp.role_id
             WHERE rp.role_id IN ({$placeholders}) AND rp.permission_key = ? AND r.status = ?",
            [...$roleIds, $permission, 'ACTIVE']
        );

        return array_values(array_map(static fn (array $r): string => (string) $r['role_id'], $rows));
    }

    /**
     * @param list<string> $matched
     * @param list<string> $reasonCodes
     * @return array{access_evaluation_id: string, decision: string, matched_role_ids: list<string>, reason_codes: list<string>, severity: ?string, incident_required: bool, evaluated_at: string}
     */
    private function record(
        string $actorType,
        string $actorId,
        string $tenantId,
        ?string $workspaceId,
        string $permission,
        string $resourceType,
        ?string $resourceId,
        ?string $correlationId,
        string $decision,
        array $matched,
        array $reasonCodes,
        ?string $severity,
        bool $incidentRequired,
        string $at,
        bool $retain,
    ): array {
        $id = Ids::new();

        if ($retain) {
            $this->db->insert('access_evaluation_records', [
                'access_evaluation_id' => $id,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'requested_permission' => $permission,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'decision' => $decision,
                'matched_role_ids' => json_encode($matched, JSON_THROW_ON_ERROR),
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'severity' => $severity,
                'incident_required' => $incidentRequired ? 1 : 0,
                'correlation_id' => $correlationId,
                'evaluated_at' => $at,
            ]);
        }

        return [
            'access_evaluation_id' => $id,
            'decision' => $decision,
            'matched_role_ids' => $matched,
            'reason_codes' => $reasonCodes,
            'severity' => $severity,
            'incident_required' => $incidentRequired,
            'evaluated_at' => $at,
        ];
    }
}
