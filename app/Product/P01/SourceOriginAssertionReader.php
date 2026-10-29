<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * OB-03 current-owner source-reference assertion *candidates*.
 *
 * This is a readback of registered M05/P01 references through the EXISTING
 * ObservationHistory M02 + entitlement + CURRENT card/right filter. It proves
 * scope and canonical snapshot/evidence linkage, NOT original publication,
 * independence, common-origin identity or a permitted new historical use.
 *
 * No alternate RBAC law, crawler, raw body, source URL, new table or release.
 */
final class SourceOriginAssertionReader
{
    public const VERSION = 'ob03.current-owner-source-reference.v1';
    public const MAX_REFERENCES = 20;

    public function __construct(
        private readonly Database $db,
        private readonly ObservationHistory $history,
    ) {}

    /**
     * @param array<string,mixed> $actor Existing M02 actor.
     * @param list<string> $snapshotIds Existing P01 captured snapshot identities.
     * @return Outcome<array<string,mixed>>
     */
    public function inspect(array $actor, string $competitorId, array $snapshotIds): Outcome
    {
        if (array_diff(array_keys($actor), ['tenant_id','workspace_id','actor_type','actor_id']) !== []
            || array_diff(['tenant_id','workspace_id','actor_type','actor_id'], array_keys($actor)) !== []
            || !Ids::isValid((string)($actor['tenant_id'] ?? ''))
            || !Ids::isValid((string)($actor['workspace_id'] ?? ''))
            || !in_array($actor['actor_type'] ?? null, ['OPERATOR','SERVICE'], true)
            || !Ids::isValid((string)($actor['actor_id'] ?? ''))
            || !Ids::isValid($competitorId)
            || !array_is_list($snapshotIds)
            || count($snapshotIds) < 1 || count($snapshotIds) > self::MAX_REFERENCES
            || count($snapshotIds) !== count(array_unique($snapshotIds))) {
            return Outcome::refuse('OB03_ORIGIN_OWNER_SELECTOR_INVALID');
        }
        foreach ($snapshotIds as $id) {
            if (!is_string($id) || !Ids::isValid($id)) {
                return Outcome::refuse('OB03_ORIGIN_SNAPSHOT_ID_INVALID');
            }
        }
        // The original owner reader supplies CURRENT M02, paid tier and
        // P01/M05 card/source/evidence eligibility. A snapshot outside the
        // first bounded, authorized page cannot be promoted by a separate SQL
        // lookup; request a future scoped owner window instead.
        $visible = $this->history->listSnapshots(
            $actor, $competitorId, ObservationHistory::MAX_PAGE_SIZE
        );
        if (($visible['decision'] ?? null) !== 'VISIBLE'
            || ($visible['data']['source_rights'] ?? null) !== 'CURRENT_ELIGIBILITY_CHECKED'
            || ($visible['data']['read_only'] ?? null) !== true
            || ($visible['data']['competitor']['competitor_id'] ?? null) !== $competitorId) {
            return Outcome::refuse('OB03_ORIGIN_OWNER_HISTORY_DENIED', [], 'SCOPE', 'S4');
        }
        $bySnapshot = [];
        foreach ($visible['data']['rows'] ?? [] as $row) {
            if (is_array($row) && is_string($row['source_snapshot_id'] ?? null)) {
                $bySnapshot[$row['source_snapshot_id']] = $row;
            }
        }
        $records = [];
        $sources = [];
        sort($snapshotIds, SORT_STRING);
        foreach ($snapshotIds as $snapshotId) {
            $historical = $bySnapshot[$snapshotId] ?? null;
            if ($historical === null) {
                return Outcome::refuse('OB03_ORIGIN_NOT_IN_CURRENT_OWNER_WINDOW', [], 'SCOPE', 'S4');
            }
            $native = $this->db->one(
                'SELECT s.source_snapshot_id,s.tenant_id,s.workspace_id,
                        s.monitored_source_id,s.evidence_id,s.snapshot_sequence,
                        s.content_hash,s.captured_at,
                        m.competitor_id,m.source_ref_id,m.source_license_card_id,
                        e.source_ref_id AS evidence_source_ref_id,e.evidence_status,
                        e.content_hash AS evidence_content_hash,e.evidence_type
                 FROM source_snapshots s
                 JOIN monitored_sources m ON m.monitored_source_id=s.monitored_source_id
                   AND m.tenant_id=s.tenant_id AND m.workspace_id=s.workspace_id
                 JOIN evidence_objects e ON e.evidence_id=s.evidence_id
                   AND e.tenant_id=s.tenant_id AND e.workspace_id=s.workspace_id
                 WHERE s.source_snapshot_id=? AND s.tenant_id=? AND s.workspace_id=?
                   AND m.competitor_id=?',
                [$snapshotId, $actor['tenant_id'],$actor['workspace_id'],$competitorId],
            );
            if ($native === null) {
                return Outcome::refuse('OB03_ORIGIN_M05_SNAPSHOT_REFERENCE_UNAVAILABLE', [], 'PROVENANCE', 'S4');
            }
            $binding = CompetitorScope::bind($actor, $competitorId, $native, $historical);
            if (!$binding->isOk()) {
                return $binding;
            }
            $b = $binding->value();
            $sources[$b['source_ref_id']] = true;
            $records[] = [
                'tenant_id'=>$b['tenant_id'],
                'workspace_id'=>$b['workspace_id'],
                'competitor_id'=>$b['competitor_id'],
                'monitored_source_id'=>$b['monitored_source_id'],
                'source_ref_id'=>$b['source_ref_id'],
                'source_license_card_id'=>$b['source_license_card_id'],
                'source_snapshot_id'=>$b['source_snapshot_id'],
                'evidence_id'=>$b['evidence_id'],
                'snapshot_sequence'=>$b['snapshot_sequence'],
                'captured_at'=>$b['captured_at'],
                'p01_canonical_content_hash'=>$b['p01_canonical_content_hash'],
                'proof'=>'CURRENT_M02_P01_M05_REFERENCE_COHERENCE_ONLY',
                'original_publisher_identity'=>'UNKNOWN',
                'common_origin_identity'=>'UNKNOWN',
                'source_independence'=>'NOT_ASSESSED_U02',
                'historical_reuse_admitted'=>false,
            ];
        }
        $report = [
            'schema_version'=>self::VERSION,
            'scope'=>[
                'tenant_id'=>$actor['tenant_id'],
                'workspace_id'=>$actor['workspace_id'],
                'competitor_id'=>$competitorId,
            ],
            'source_reference_count'=>count($sources),
            'observed_captures'=>count($records),
            'references'=>$records,
            'current_owner_read_verified'=>true,
            'owner_read_window_limited_to'=>ObservationHistory::MAX_PAGE_SIZE,
            'exact_scope_and_m05_hash_consistency_checked'=>true,
            'independent_origin_count'=>null,
            'corroborating_event_count'=>null,
            'origin_independence_certified'=>false,
            'source_use_for_new_analysis_admitted'=>false,
            'u02_u03_owner_adopted'=>false,
            'customer_release_authorized'=>false,
            'no_raw_source_bytes_returned'=>true,
        ];
        $report['assertion_digest']=Digest::canonicalDigest($report);
        return Outcome::ok($report);
    }
}
