<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Auth\PasswordPolicy;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * Minimal authority-chain seeding for tests.
 *
 * Deliberately NOT a fixture factory that hides governance: every seed here creates only rows a
 * real operator act would create, so a test cannot accidentally prove behavior that depends on a
 * state the product could never reach.
 */
final class Seed
{
    /** @return array{tenant_id: string, workspace_id: string, operator_id: string} */
    public static function tenantWorkspace(Database $db, Clock $clock, string $key = 'acme'): array
    {
        $now = $clock->now();
        $tenantId = Ids::new();
        $workspaceId = Ids::new();
        $operatorId = Ids::new();

        $db->insert('tenants', [
            'tenant_id' => $tenantId,
            'tenant_key' => $key . '-' . substr($tenantId, 0, 8),
            'schema_version' => '1',
            'display_name' => ucfirst($key),
            'tenant_type' => 'TEST',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => 'Operator ' . $key,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('workspaces', [
            'workspace_id' => $workspaceId,
            'tenant_id' => $tenantId,
            'workspace_key' => 'ws-' . substr($workspaceId, 0, 8),
            'schema_version' => '1',
            'display_name' => 'Workspace',
            'workspace_type' => 'TEST',
            'environment_class' => 'TEST',
            'external_side_effects_allowed' => 0,
            'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        return ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $operatorId];
    }

    /** An additional operator inside an existing tenant. */
    public static function operator(Database $db, Clock $clock, string $tenantId, string $displayName): string
    {
        $operatorId = Ids::new();
        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => $displayName,
            'status' => 'ACTIVE',
            'created_at' => $clock->now(),
        ]);

        return $operatorId;
    }

    /** @return array{role_id: string} */
    public static function role(Database $db, Clock $clock, string $roleKey, array $permissions = []): array
    {
        $now = $clock->now();
        $roleId = Ids::new();
        $db->insert('roles', [
            'role_id' => $roleId,
            'role_key' => $roleKey,
            'role_version' => 1,
            'schema_version' => '1',
            'display_name' => $roleKey,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);
        foreach ($permissions as $permissionKey) {
            $exists = $db->one('SELECT permission_key FROM permissions WHERE permission_key = ?', [$permissionKey]);
            if ($exists === null) {
                $db->insert('permissions', [
                    'permission_key' => $permissionKey,
                    'schema_version' => '1',
                    'display_name' => $permissionKey,
                    'created_at' => $now,
                ]);
            }
            $db->insert('role_permissions', ['role_id' => $roleId, 'permission_key' => $permissionKey]);
        }

        return ['role_id' => $roleId];
    }

    public static function membership(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $workspaceId,
        string $operatorId,
        string $roleId,
    ): string {
        $now = $clock->now();
        $membershipId = Ids::new();
        $db->insert('workspace_memberships', [
            'membership_id' => $membershipId,
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'principal_type' => 'OPERATOR',
            'principal_id' => $operatorId,
            'role_id' => $roleId,
            'role_version' => 1,
            'status' => 'ACTIVE',
            'valid_from' => $now,
            'created_at' => $now,
        ]);

        return $membershipId;
    }

    /** @return array{platform_user_id: string, email: string, password: string} */
    public static function platformUser(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $operatorId,
        string $email = 'operator@example.test',
        string $password = 'correct horse battery staple',
    ): array {
        $now = $clock->now();
        $hashed = PasswordPolicy::hash($password);
        $platformUserId = Ids::new();
        $db->insert('platform_users', [
            'platform_user_id' => $platformUserId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'email' => $email,
            'display_name' => 'Test Operator',
            'password_hash' => $hashed['hash'],
            'password_algo' => $hashed['algo'],
            'status' => 'ACTIVE',
            'password_changed_at' => $now,
            'created_at' => $now,
        ]);

        return ['platform_user_id' => $platformUserId, 'email' => $email, 'password' => $password];
    }
}
