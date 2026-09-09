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

        $roles = $projection === null ? [] : json_decode((string) $projection['visible_role_summaries'], true, 512, JSON_THROW_ON_ERROR);
        $permissions = $projection === null ? [] : json_decode((string) $projection['visible_permission_summaries'], true, 512, JSON_THROW_ON_ERROR);
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
            'as_of' => $projection === null ? null : (string) $projection['generated_at'],
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
