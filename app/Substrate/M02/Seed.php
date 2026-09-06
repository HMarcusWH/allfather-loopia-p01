<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * Seeds the global permission and role vocabulary.
 *
 * Permissions and roles are GLOBALLY authoritative by design — they are the shared vocabulary
 * every tenant's memberships resolve against, not tenant-owned data. They are therefore the one
 * class of row read without a tenant predicate.
 *
 * The seed is idempotent so it can run on every deploy, and it refuses to create an excluded
 * permission: the exclusion list would be worthless if the seed could grant one.
 */
final class Seed
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{permissions: int, roles: int} */
    public function ensureVocabulary(): array
    {
        $now = $this->clock->now();
        $permissions = 0;
        $roles = 0;

        $this->db->transaction(function (Database $tx) use ($now, &$permissions, &$roles): void {
            foreach (Permissions::GRANTABLE as $key) {
                if (Permissions::isExcluded($key)) {
                    // Structural, not defensive: if a key ever appears in both lists, seeding it
                    // would create the very permission the exclusion exists to prevent.
                    throw new \LogicException("permission '{$key}' is both grantable and excluded");
                }
                $exists = $tx->one('SELECT permission_key FROM permissions WHERE permission_key = ?', [$key]);
                if ($exists === null) {
                    $tx->insert('permissions', [
                        'permission_key' => $key,
                        'schema_version' => '1',
                        'display_name' => $key,
                        'description' => null,
                        'created_at' => $now,
                    ]);
                    $permissions++;
                }
            }

            foreach (Permissions::ROLES as $roleKey => $definition) {
                $existing = $tx->one('SELECT role_id FROM roles WHERE role_key = ? AND role_version = 1', [$roleKey]);
                if ($existing !== null) {
                    continue;
                }
                $roleId = Ids::new();
                $tx->insert('roles', [
                    'role_id' => $roleId,
                    'role_key' => $roleKey,
                    'role_version' => 1,
                    'schema_version' => '1',
                    'display_name' => $definition['display_name'],
                    'purpose_summary' => null,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                ]);
                foreach ($definition['permissions'] as $permission) {
                    if (Permissions::isExcluded($permission)) {
                        throw new \LogicException("role {$roleKey} would grant excluded permission {$permission}");
                    }
                    $tx->insert('role_permissions', ['role_id' => $roleId, 'permission_key' => $permission]);
                }
                $roles++;
            }
        });

        return ['permissions' => $permissions, 'roles' => $roles];
    }

    public function roleIdFor(string $roleKey): ?string
    {
        $row = $this->db->one('SELECT role_id FROM roles WHERE role_key = ? AND role_version = 1', [$roleKey]);

        return $row === null ? null : (string) $row['role_id'];
    }
}
