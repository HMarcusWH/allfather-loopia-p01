<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Db\Database;

/**
 * The one read the support page is allowed to make.
 *
 * A support surface wants to show the reader which workspace they are acting in, which means a
 * query — and the moment a support controller holds a `Database` it can make any query at all.
 * Putting the read here keeps it to exactly one statement, scoped by the SESSION's own tenant and
 * workspace, in a file whose entire content is that statement.
 *
 * IT GRANTS NOTHING AND PROVES NOTHING. It is not an M02 gate and must never be used as one: the
 * workspace label is a label. Membership is re-decided by the chooser and by every product surface,
 * and a session whose membership was revoked a moment ago still has an `active_workspace_id` — so
 * this returning a name says the session selected that workspace, never that it may still act in it.
 *
 * THE SCOPE IS NOT NEGOTIABLE. Both identifiers come from the resolved session, never from the
 * request, so there is no input that could widen it. A workspace in another tenant returns null for
 * the same reason it does everywhere else in this codebase: the tenant is part of the predicate.
 */
final class SupportContextAdapter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $session
     * @return array{display_name: string, environment_class: string}|null
     */
    public function activeWorkspace(?array $session): ?array
    {
        $workspaceId = $session['active_workspace_id'] ?? null;
        if ($session === null || $workspaceId === null) {
            return null;
        }

        $row = $this->db->one(
            "SELECT display_name, environment_class FROM workspaces
             WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'",
            [(string) $session['tenant_id'], (string) $workspaceId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'display_name' => (string) $row['display_name'],
            'environment_class' => (string) $row['environment_class'],
        ];
    }
}
