<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P03\Release as P03Release;

/**
 * GET /console/api/v1/p03/prospect-detail?output=<id> (P09).
 *
 * The query value is a SELECTOR only. The owner (`P03\Release::visibleProspectOutputDetail`) applies
 * the same two gates as the P03 index reads, checks the object's tenant and workspace, and returns a
 * fit hypothesis framed as a reason to investigate — never buyer intent.
 */
final class P03ProspectDetailAdapter
{
    public function __construct(private readonly P03Release $release)
    {
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function read(?array $session, string $selector): array
    {
        if ($session === null || ($session['active_workspace_id'] ?? null) === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }
        if (!Ids::isValid($selector)) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => 'priority_outreach_queue.read', 'reason_codes' => ['SELECTOR_INVALID']];
        }
        $owner = $this->release->visibleProspectOutputDetail([
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
            'released_prospect_output_id' => $selector,
        ]);

        return [
            'decision' => $owner['decision'],
            'required_permission' => $owner['required_permission'],
            'reason_codes' => $owner['reason_codes'],
            'data' => $owner['detail'],
        ];
    }
}
