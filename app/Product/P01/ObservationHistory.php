<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * LI-03 read-only projection of EXISTING P01/M05 objects.
 *
 * This is not an observation assessment or an M17 memory. Structural change
 * significance is never business materiality. No source bytes, prompts,
 * source URLs, model candidates or customer release fields leave this reader.
 * No migrations, capture, model, schedule or action authority is introduced.
 */
final class ObservationHistory
{
    public const MAX_PAGE_SIZE = 50;
    public const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope
     * @return array<string,mixed>
     */
    public function listSnapshots(array $scope, string $competitorId, int $limit = self::DEFAULT_PAGE_SIZE, ?string $beforeSnapshotId = null): array
    {
        $required = ['tenant_id', 'workspace_id', 'actor_type', 'actor_id'];
        foreach ($required as $key) {
            if (!isset($scope[$key]) || !is_string($scope[$key]) || $scope[$key] === '') {
                return $this->denied('HISTORY_SCOPE_REQUIRED');
            }
        }

        // The existing P01 and M05 permissions are both required; no new role
        // or implication from subscription is minted by this read.
        foreach (['competition_monitoring.read', 'evidence.read'] as $permission) {
            $gate = $this->access->evaluate(
                $scope['actor_type'], $scope['actor_id'], $scope['tenant_id'],
                $scope['workspace_id'], $permission, 'competitor_history', $competitorId,
            );
            if ($gate['decision'] !== 'ALLOW') {
                return $this->denied((string) ($gate['reason_codes'][0] ?? 'PERMISSION_NOT_GRANTED'), $permission);
            }
        }
        if (!$this->entitlements->tenantTierGrants($scope['tenant_id'], 'competition_monitoring.read')) {
            return $this->denied('TIER_DOES_NOT_GRANT_PERMISSION', 'competition_monitoring.read');
        }

        // A foreign/missing selector has the same outcome; do not enumerate
        // other tenants' competitors, including archived ones.
        if ($competitorId === '' || $limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return ['decision'=>'NOT_FOUND', 'reason_codes'=>['HISTORY_SELECTOR_INVALID']];
        }
        $competitor = $this->db->one(
            "SELECT competitor_id,display_name,status FROM competitors
             WHERE competitor_id=? AND tenant_id=? AND workspace_id=? AND status IN ('ACTIVE','PAUSED')",
            [$competitorId,$scope['tenant_id'],$scope['workspace_id']],
        );
        if ($competitor === null) {
            return ['decision'=>'NOT_FOUND', 'reason_codes'=>['HISTORY_NOT_FOUND']];
        }

        // Current permission/source use is rechecked on EVERY page. Requiring
        // an active approved source and permitted derived internal use is
        // deliberately conservative until U03 finalizes historical-read law.
        // We do not even reveal a historical row for revoked/quarantined cards.
        $join = <<<'SQL'
            FROM source_snapshots s
            JOIN monitored_sources m ON m.monitored_source_id=s.monitored_source_id
                AND m.tenant_id=s.tenant_id AND m.workspace_id=s.workspace_id
            JOIN source_license_cards card ON card.source_license_card_id=m.source_license_card_id
                AND card.tenant_id=m.tenant_id AND card.source_ref_id=m.source_ref_id
            JOIN source_references ref ON ref.source_ref_id=m.source_ref_id
                AND ref.tenant_id=m.tenant_id
                AND (ref.workspace_id IS NULL OR ref.workspace_id=m.workspace_id)
            JOIN evidence_objects ev ON ev.evidence_id=s.evidence_id
                AND ev.tenant_id=s.tenant_id AND ev.workspace_id=s.workspace_id
            WHERE s.tenant_id=? AND s.workspace_id=? AND m.competitor_id=?
                AND m.status='ACTIVE'
                AND ref.status='ACTIVE_FOR_CAPTURE'
                AND ref.content_sensitivity_class='PUBLIC_OR_NON_SENSITIVE'
                AND card.review_status='approved'
                AND (card.review_expires_at IS NULL OR card.review_expires_at>?)
                AND card.allowed_use_class IN ('derived_summary_allowed','customer_visible_allowed','customer_owned_tenant_scoped')
                AND card.derived_summary_allowed=1
                AND card.retention_class='standard'
                AND card.caching_policy IN ('standard_cache','explicit_contract')
                AND ev.evidence_status IN ('CAPTURED','ASSESSED')
SQL;
        $args = [$scope['tenant_id'],$scope['workspace_id'],$competitorId,$this->clock->now()];
        $cursor = '';
        if ($beforeSnapshotId !== null) {
            if ($beforeSnapshotId === '') {
                return ['decision'=>'NOT_FOUND', 'reason_codes'=>['HISTORY_CURSOR_INVALID']];
            }
            $last = $this->db->one(
                'SELECT s.captured_at,s.source_snapshot_id '.$join.' AND s.source_snapshot_id=?',
                [...$args,$beforeSnapshotId],
            );
            if ($last === null) {
                return ['decision'=>'NOT_FOUND', 'reason_codes'=>['HISTORY_CURSOR_NOT_AVAILABLE']];
            }
            $cursor = ' AND (s.captured_at<? OR (s.captured_at=? AND s.source_snapshot_id<?))';
            $args[] = $last['captured_at'];
            $args[] = $last['captured_at'];
            $args[] = $last['source_snapshot_id'];
        }
        $take = $limit + 1; // sanitized integer, bounded before interpolation
        $captures = $this->db->all(
            'SELECT s.source_snapshot_id,s.monitored_source_id,s.snapshot_sequence,s.captured_at,m.source_kind'
            .$join.$cursor
            ." ORDER BY s.captured_at DESC,s.source_snapshot_id DESC LIMIT {$take}",
            $args,
        );
        $hasMore = count($captures) > $limit;
        $page = array_slice($captures, 0, $limit);

        // Return *all* eligible comparisons for a capture rather than picking an
        // arbitrary latest diff and silently hiding contrary references.
        $comparisons = [];
        if ($page !== []) {
            $ids = array_column($page, 'source_snapshot_id');
            $holders = implode(',', array_fill(0,count($ids),'?'));
            $diffs = $this->db->all(
                "SELECT d.after_snapshot_id,d.source_diff_id,d.change_significance,d.detected_at
                 FROM source_diffs d
                 JOIN source_snapshots before_s ON before_s.source_snapshot_id=d.before_snapshot_id
                    AND before_s.monitored_source_id=d.monitored_source_id
                    AND before_s.tenant_id=d.tenant_id AND before_s.workspace_id=d.workspace_id
                 JOIN evidence_objects before_ev ON before_ev.evidence_id=before_s.evidence_id
                    AND before_ev.tenant_id=before_s.tenant_id AND before_ev.workspace_id=before_s.workspace_id
                 WHERE d.tenant_id=? AND d.workspace_id=?
                    AND before_ev.evidence_status IN ('CAPTURED','ASSESSED')
                    AND d.after_snapshot_id IN ({$holders})
                 ORDER BY d.detected_at,d.source_diff_id",
                [$scope['tenant_id'],$scope['workspace_id'],...$ids],
            );
            foreach ($diffs as $diff) {
                $comparisons[$diff['after_snapshot_id']][] = [
                    'source_diff_id'=>(string)$diff['source_diff_id'],
                    'structural_significance'=>(string)$diff['change_significance'],
                    'detected_at'=>(string)$diff['detected_at'],
                ];
            }
        }

        $rows = [];
        foreach ($page as $capture) {
            $diffs = $comparisons[$capture['source_snapshot_id']] ?? [];
            $coverage = ((int)$capture['snapshot_sequence'] === 1)
                ? 'BASELINE'
                : ($diffs === [] ? 'COMPARISON_UNAVAILABLE' :
                    (count($diffs) > 1 ? 'MULTIPLE_COMPARISONS' :
                        ($diffs[0]['structural_significance']==='NONE' ? 'STRUCTURALLY_UNCHANGED' : 'STRUCTURAL_CHANGE_RECORDED')));
            $rows[] = [
                'source_snapshot_id'=>(string)$capture['source_snapshot_id'],
                'monitored_source_id'=>(string)$capture['monitored_source_id'],
                'source_kind'=>(string)$capture['source_kind'],
                'snapshot_sequence'=>(int)$capture['snapshot_sequence'],
                'captured_at'=>(string)$capture['captured_at'],
                'coverage_state'=>$coverage,
                'comparisons'=>$diffs,
                'business_materiality'=>'NOT_ASSESSED',
                'observation_assessment'=>'NOT_IMPLEMENTED',
                'release_status'=>'NOT_INFERRED',
            ];
        }
        return [
            'decision'=>'VISIBLE', 'required_permission'=>'evidence.read',
            'reason_codes'=>['GRANTED'], 'data'=>[
                'competitor'=>[
                    'competitor_id'=>(string)$competitor['competitor_id'],
                    'display_name'=>(string)$competitor['display_name'],
                    'status'=>(string)$competitor['status'],
                ],
                'rows'=>$rows,
                'next_cursor'=>$hasMore && $rows!==[] ? $rows[count($rows)-1]['source_snapshot_id'] : null,
                'page_size'=>$limit,
                'source_rights'=>'CURRENT_ELIGIBILITY_CHECKED',
                'business_materiality_available'=>false,
                'read_only'=>true,
            ],
        ];
    }

    /**
     * OB-02 preview-only history projection, deliberately routed through this
     * existing owner reader's CURRENT M02/M05/card eligibility checks.
     *
     * No new runtime schema or source-use grant. This cannot apply a backfill,
     * read raw bodies, create v2 findings, or release anything to customers.
     *
     * @param array<string,mixed> $actor
     * @return \Allfather\Loopia\Core\Outcome
     */
    public function previewAssessmentBackfill(
        array $actor,
        string $competitorId,
        int $limit = 10,
        ?string $beforeSnapshotId = null,
    ): \Allfather\Loopia\Core\Outcome {
        return (new ObservationBackfill($this->db, $this->clock, $this))
            ->preview($actor, $competitorId, $limit, $beforeSnapshotId);
    }

    /** @return array<string,mixed> */
    private function denied(string $reason, ?string $permission = null): array
    {
        return [
            'decision'=>'DENIED','required_permission'=>$permission ?? 'evidence.read',
            'reason_codes'=>[$reason],
        ];
    }
}
