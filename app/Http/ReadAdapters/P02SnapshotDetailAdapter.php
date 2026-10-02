<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P02\TwinRelease;

/**
 * GET /console/api/v1/p02/entity-detail?snapshot=<id> (P09).
 *
 * The query value is a SELECTOR only. Scope comes from the authenticated session, and the owner
 * (`TwinRelease::visibleSnapshotDetail`) decides authority and checks the object's tenant and
 * workspace. A malformed selector is answered like an unknown one, without asking the owner.
 */
final class P02SnapshotDetailAdapter
{
    public function __construct(private readonly TwinRelease $twins)
    {
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function read(?array $session, string $selector): array
    {
        if ($session === null || ($session['active_workspace_id'] ?? null) === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }
        if (!Ids::isValid($selector)) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => 'entity_twin.read', 'reason_codes' => ['SELECTOR_INVALID']];
        }
        $owner = $this->twins->visibleSnapshotDetail([
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
            'released_entity_twin_snapshot_id' => $selector,
        ]);

        return [
            'decision' => $owner['decision'],
            'required_permission' => $owner['required_permission'],
            'reason_codes' => $owner['reason_codes'],
            'data' => $owner['detail'],
        ];
    }
}
