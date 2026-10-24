<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Display only: registered competitor candidates and monitored-source readiness.
 * A DRAFT competitor is NOT a monitored competitor or released battlecard.
 * Never returns a source URL, raw evidence, credentials or a capture capability.
 */
final class WatchlistRead
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope
     * @return array<string,mixed>
     */
    public function listCandidates(array $scope): array
    {
        $permission = 'competition_monitoring.read';
        $gate = $this->access->evaluate(
            $scope['actor_type'], $scope['actor_id'], $scope['tenant_id'],
            $scope['workspace_id'], $permission, 'competitor',
        );
        if ($gate['decision'] !== 'ALLOW') {
            return ['decision'=>'DENIED', 'required_permission'=>$permission,
                'reason_codes'=>$gate['reason_codes'], 'rows'=>[]];
        }
        if (!$this->entitlements->tenantTierGrants($scope['tenant_id'], $permission)) {
            return ['decision'=>'DENIED', 'required_permission'=>$permission,
                'reason_codes'=>['TIER_DOES_NOT_GRANT_PERMISSION'], 'rows'=>[]];
        }

        $rows = $this->db->all(
            "SELECT competitor_id,display_name,competitor_class,status,created_at
             FROM competitors WHERE tenant_id=? AND workspace_id=?
             AND status IN ('DRAFT','ACTIVE','PAUSED')
             ORDER BY display_name,competitor_id",
            [$scope['tenant_id'],$scope['workspace_id']],
        );
        $now = $this->clock->now();
        foreach ($rows as &$row) {
            // A source is ready for *consideration* only when every current
            // source-rights gate agrees; the browser never starts capture.
            $sources = $this->db->all(
                "SELECT m.status AS monitoring_status,c.review_status,c.review_expires_at,
                        c.allowed_use_class,r.status AS reference_status
                 FROM monitored_sources m
                 JOIN source_license_cards c ON c.source_license_card_id=m.source_license_card_id
                    AND c.tenant_id=m.tenant_id AND c.source_ref_id=m.source_ref_id
                 JOIN source_references r ON r.source_ref_id=m.source_ref_id
                    AND r.tenant_id=m.tenant_id
                 WHERE m.tenant_id=? AND m.workspace_id=? AND m.competitor_id=?",
                [$scope['tenant_id'],$scope['workspace_id'],$row['competitor_id']],
            );
            $ready = 0;
            foreach ($sources as $source) {
                if ($source['monitoring_status'] === 'ACTIVE'
                    && $source['review_status'] === 'approved'
                    && ($source['review_expires_at'] === null || $source['review_expires_at'] > $now)
                    && $source['reference_status'] === 'ACTIVE_FOR_CAPTURE'
                    && $source['allowed_use_class'] !== 'prohibited') {
                    $ready++;
                }
            }
            $row['monitored_sources_count'] = count($sources);
            $row['currently_approved_source_count'] = $ready;
            $row['readiness'] = $row['status'] === 'DRAFT'
                ? 'DRAFT_CANDIDATE_ONLY'
                : ($ready ? 'SOURCE_RIGHTS_PRESENT_REVIEW_REQUIRED' : 'NO_CURRENT_APPROVED_SOURCE');
        }
        unset($row);

        return ['decision'=>'VISIBLE','required_permission'=>$permission,
            'reason_codes'=>['GRANTED'],'rows'=>$rows];
    }
}
