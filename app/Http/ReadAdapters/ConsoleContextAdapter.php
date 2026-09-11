<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;

/** M12 browser/SSR context adapter. Presentation only; it grants no permission. */
final class ConsoleContextAdapter
{
    public function __construct(
        private readonly Database $db,
        private readonly Console $console,
        private readonly Assurance $assurance,
    ) {
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function read(?array $session): array
    {
        $workspaceId = $session['active_workspace_id'] ?? null;
        if ($session === null || $workspaceId === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }

        $tenantId = (string) $session['tenant_id'];
        $operatorId = (string) $session['operator_id'];

        // Session resolution revalidates the account, but tenant lifecycle is a separate M02 gate.
        // A session issued while the tenant was ACTIVE must not keep presentation context visible
        // after the tenant is suspended or retired. Check the current tenant state before reading
        // workspace metadata, rebuilding M12 projections, or exposing assurance information.
        $tenant = $this->db->one('SELECT status FROM tenants WHERE tenant_id = ?', [$tenantId]);
        if ($tenant === null || (string) $tenant['status'] !== 'ACTIVE') {
            return [
                'decision' => 'DENIED',
                'required_permission' => null,
                'reason_codes' => ['TENANT_NOT_ACTIVE'],
                'data' => null,
            ];
        }

        $workspace = $this->db->one(
            "SELECT display_name, environment_class, active_v1_capability_ceiling, external_side_effects_allowed
             FROM workspaces WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'",
            [$tenantId, (string) $workspaceId]
        );
        if ($workspace === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }

        $this->console->buildWorkspaceContext($tenantId, (string) $workspaceId, $operatorId);
        $projection = $this->db->one(
            'SELECT visible_role_summaries, visible_permission_summaries, generated_at
             FROM console_workspace_context_projections
             WHERE tenant_id = ? AND workspace_id = ? AND operator_id = ?',
            [$tenantId, (string) $workspaceId, $operatorId]
        );
        if ($projection === null) {
            return ['decision' => 'UNAVAILABLE', 'reason_codes' => ['CONTEXT_PROJECTION_UNAVAILABLE']];
        }

        $projectedRoles = json_decode((string) $projection['visible_role_summaries'], true, 512, JSON_THROW_ON_ERROR);
        $projectedPermissions = json_decode((string) $projection['visible_permission_summaries'], true, 512, JSON_THROW_ON_ERROR);
        $at = (string) $projection['generated_at'];
        $scopeArgs = [$operatorId, (string) $workspaceId, $tenantId, $at, $at];

        // M12 is a regenerable presentation projection, not authority. Role lifecycle can change
        // independently of an ACTIVE membership, so the browser adapter revalidates the role
        // status before allowing that projection to expose workspace context or permissions. The
        // shape mirrors M02's active-membership/active-operator/ACTIVE-role contract and then
        // intersects with the just-built projection: presentation can only become narrower here.
        $activeRoles = array_column($this->db->all(
            "SELECT DISTINCT r.role_key FROM workspace_memberships m
             JOIN roles r ON r.role_id = m.role_id
             JOIN operators o ON o.operator_id = m.principal_id AND o.tenant_id = m.tenant_id
             WHERE m.principal_id = ? AND m.workspace_id = ? AND m.tenant_id = ?
               AND m.principal_type = 'OPERATOR' AND m.status = 'ACTIVE'
               AND o.status = 'ACTIVE' AND r.status = 'ACTIVE'
               AND m.valid_from <= ? AND (m.valid_until IS NULL OR m.valid_until > ?)
             ORDER BY r.role_key",
            $scopeArgs
        ), 'role_key');
        $activePermissions = array_column($this->db->all(
            "SELECT DISTINCT rp.permission_key FROM workspace_memberships m
             JOIN role_permissions rp ON rp.role_id = m.role_id
             JOIN roles r ON r.role_id = m.role_id
             JOIN operators o ON o.operator_id = m.principal_id AND o.tenant_id = m.tenant_id
             WHERE m.principal_id = ? AND m.workspace_id = ? AND m.tenant_id = ?
               AND m.principal_type = 'OPERATOR' AND m.status = 'ACTIVE'
               AND o.status = 'ACTIVE' AND r.status = 'ACTIVE'
               AND m.valid_from <= ? AND (m.valid_until IS NULL OR m.valid_until > ?)
             ORDER BY rp.permission_key",
            $scopeArgs
        ), 'permission_key');

        $roles = array_values(array_unique(array_intersect($projectedRoles, $activeRoles)));
        $permissions = array_values(array_unique(array_intersect($projectedPermissions, $activePermissions)));
        if ($roles === []) {
            return [
                'decision' => 'DENIED',
                'required_permission' => null,
                'reason_codes' => ['WORKSPACE_MEMBERSHIP_REQUIRED'],
                'data' => null,
            ];
        }

        $gaps = array_map(
            static fn (array $gap): array => [
                'gap_key' => (string) $gap['gap_type'],
                'severity' => (string) $gap['effect_on_v1'],
                'safe_summary' => 'Status: ' . (string) $gap['status'],
            ],
            $this->assurance->listBlockingGaps($tenantId, (string) $workspaceId)
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => null,
            'reason_codes' => ['GRANTED'],
            'as_of' => (string) $projection['generated_at'],
            'data' => [
                'workspace' => [
                    'display_name' => (string) $workspace['display_name'],
                    'environment_class' => (string) $workspace['environment_class'],
                    'active_v1_capability_ceiling' => (string) $workspace['active_v1_capability_ceiling'],
                    'external_side_effects_allowed' => (bool) $workspace['external_side_effects_allowed'],
                ],
                'roles' => $roles,
                'permissions' => $permissions,
                'gaps' => $gaps,
            ],
        ];
    }
}
