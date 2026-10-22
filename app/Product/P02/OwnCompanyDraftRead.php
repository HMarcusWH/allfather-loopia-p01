<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Internal OWN_COMPANY version read; NOT the P02 released snapshot read.
 *
 * Session-owned tenant/workspace scope, M02 permission and active tier are required.
 * Neither the existence of an own-company twin nor its evidence ID is revealed on
 * access denial. This never promotes a draft into a reviewed/released artefact.
 */
final class OwnCompanyDraftRead
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope
     * @return array{decision:string,required_permission:string,reason_codes:list<string>,rows:list<array<string,mixed>>}
     */
    public function listInternalDrafts(array $scope): array
    {
        // A released-twin read alone must never expand into access to unreleased
        // first-party customer drafts. Require the same operator to hold the
        // tenant-administrator authority as an additional M02 gate.
        $admin = $this->access->evaluate(
            $scope['actor_type'], $scope['actor_id'], $scope['tenant_id'],
            $scope['workspace_id'], 'tenant.administer', 'entity_twin',
        );
        if ($admin['decision'] !== 'ALLOW') {
            return [
                'decision' => 'DENIED', 'required_permission' => 'tenant.administer',
                'reason_codes' => $admin['reason_codes'], 'rows' => [],
            ];
        }
        $permission = 'entity_twin.read';
        $access = $this->access->evaluate(
            $scope['actor_type'], $scope['actor_id'], $scope['tenant_id'],
            $scope['workspace_id'], $permission, 'entity_twin',
        );
        if ($access['decision'] !== 'ALLOW') {
            return [
                'decision' => 'DENIED', 'required_permission' => $permission,
                'reason_codes' => $access['reason_codes'], 'rows' => [],
            ];
        }
        if (!$this->entitlements->tenantTierGrants($scope['tenant_id'], $permission)) {
            return [
                'decision' => 'DENIED', 'required_permission' => $permission,
                'reason_codes' => ['TIER_DOES_NOT_GRANT_PERMISSION'], 'rows' => [],
            ];
        }

        // Latest version only. A later reviewed version is NOT backfilled by an old draft.
        $rows = $this->db->all(
            "SELECT t.entity_twin_id,t.display_name,t.legal_name,t.domain,t.country,t.industry,
                    t.segment,v.entity_twin_version_id,v.version_sequence,v.claim_ceiling,
                    u.summary_safe AS uncertainty_summary_safe,u.unknown_field_count,
                    u.has_conflict,u.conflict_summary_safe
             FROM entity_twins t
             JOIN entity_twin_versions v
               ON v.entity_twin_id=t.entity_twin_id
                 AND v.tenant_id=t.tenant_id AND v.workspace_id=t.workspace_id
             JOIN entity_twin_uncertainty_summaries u
               ON u.entity_twin_version_id=v.entity_twin_version_id AND u.tenant_id=t.tenant_id
             WHERE t.tenant_id=? AND t.workspace_id=? AND t.entity_kind='OWN_COMPANY'
               AND t.status='ACTIVE' AND t.is_first_party=1
               AND v.claim_ceiling='INTERNAL_DRAFT'
               AND v.version_sequence=(
                 SELECT MAX(v2.version_sequence) FROM entity_twin_versions v2
                 WHERE v2.entity_twin_id=t.entity_twin_id AND v2.tenant_id=t.tenant_id
                   AND v2.workspace_id=t.workspace_id
               )
             ORDER BY t.display_name,t.entity_twin_id",
            [$scope['tenant_id'], $scope['workspace_id']],
        );
        foreach ($rows as &$row) {
            $versionId = (string) $row['entity_twin_version_id'];
            $row['fields'] = $this->db->all(
                'SELECT field_key,field_value_safe,value_status FROM entity_twin_state_summaries
                 WHERE tenant_id=? AND entity_twin_version_id=? ORDER BY field_key',
                [$scope['tenant_id'], $versionId],
            );
            // Only the classification is shown, never raw M05 evidence bytes or file paths.
            $row['lineage'] = $this->db->all(
                'SELECT source_basis,allowed_use,freshness_observed_at FROM entity_twin_source_links
                 WHERE tenant_id=? AND entity_twin_version_id=? ORDER BY source_basis,allowed_use',
                [$scope['tenant_id'], $versionId],
            );
            $row['unknown_field_count'] = (int) $row['unknown_field_count'];
            $row['has_conflict'] = (int) $row['has_conflict'] === 1;
        }
        unset($row);

        return [
            'decision' => 'VISIBLE', 'required_permission' => $permission,
            'reason_codes' => ['GRANTED'], 'rows' => $rows,
        ];
    }
}
