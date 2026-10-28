<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * OB-02 metadata-only preview via the EXISTING P01 ObservationHistory owner.
 * No new competitor registry, no raw body access, no inference on rejected
 * text, no migration, no write, no provider and no terminal journal rewrite.
 *
 * The native read checks M02 permissions + subscription + CURRENT source-card,
 * M05 evidence, source and workspace every call, including cursor pages.
 * This projection additionally validates exact source/evidence/diff joins.
 */
final class ObservationBackfill
{
    public const VERSION = 'ob02.cursor-ledger-preview.v1';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ObservationHistory $history,
    ) {}

    /**
     * @param array<string,mixed> $actor Same existing P01 M02 scope.
     * @return Outcome<array<string,mixed>>
     */
    public function preview(array $actor, string $competitorId, int $limit = 10, ?string $beforeSnapshotId = null): Outcome
    {
        if (!Ids::isValid($competitorId) || $limit < 1
            || $limit > ObservationAssessment::MAX_PAGE_SIZE
            || ($beforeSnapshotId !== null && !Ids::isValid($beforeSnapshotId))) {
            return Outcome::refuse('OB02_BACKFILL_SELECTOR_INVALID');
        }
        // MUST be the real owner reader, not a second status or subscription
        // gate. The reader excludes revoked, quarantined and expired sources.
        $visible = $this->history->listSnapshots($actor, $competitorId, $limit, $beforeSnapshotId);
        if (($visible['decision'] ?? null) !== 'VISIBLE'
            || ($visible['data']['read_only'] ?? null) !== true
            || ($visible['data']['source_rights'] ?? null) !== 'CURRENT_ELIGIBILITY_CHECKED'
            || ($visible['data']['competitor']['competitor_id'] ?? null) !== $competitorId) {
            return Outcome::refuse('OB02_OWNER_HISTORY_NOT_AVAILABLE', [], 'SCOPE', 'S4');
        }
        $rows = $visible['data']['rows'];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $limit) {
            return Outcome::refuse('OB02_OWNER_HISTORY_SHAPE_INVALID');
        }
        $records = [];
        $sourceIds = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !Ids::isValid((string) ($row['source_snapshot_id'] ?? ''))) {
                return Outcome::refuse('OB02_OWNER_HISTORY_REFERENCE_INVALID');
            }
            $native = $this->db->one(
                "SELECT s.source_snapshot_id,s.tenant_id,s.workspace_id,s.monitored_source_id,
                        s.evidence_id,s.snapshot_sequence,s.content_hash,s.captured_at,
                        m.competitor_id,m.source_ref_id,m.source_license_card_id,
                        ev.source_ref_id AS evidence_source_ref_id,ev.evidence_status
                 FROM source_snapshots s
                 JOIN monitored_sources m ON m.monitored_source_id=s.monitored_source_id
                    AND m.tenant_id=s.tenant_id AND m.workspace_id=s.workspace_id
                 JOIN evidence_objects ev ON ev.evidence_id=s.evidence_id
                    AND ev.tenant_id=s.tenant_id AND ev.workspace_id=s.workspace_id
                 WHERE s.source_snapshot_id=? AND s.tenant_id=? AND s.workspace_id=?
                    AND m.competitor_id=?",
                [$row['source_snapshot_id'], $actor['tenant_id'], $actor['workspace_id'], $competitorId],
            );
            if ($native === null) {
                return Outcome::refuse('OB02_OWNER_SOURCE_REFERENCE_UNAVAILABLE', [], 'PROVENANCE', 'S4');
            }
            $bound = CompetitorScope::bind($actor, $competitorId, $native, $row);
            if (!$bound->isOk()) {
                return $bound;
            }
            $binding = $bound->value();
            foreach ($row['comparisons'] as $comparison) {
                // Every comparison must belong to this exact AFTER snapshot,
                // tenant, workspace and monitored source; a copied diff ID
                // from another source must not be silently recorded as fact.
                $d = $this->db->one(
                    "SELECT d.source_diff_id
                     FROM source_diffs d
                     WHERE d.source_diff_id=? AND d.tenant_id=? AND d.workspace_id=?
                       AND d.monitored_source_id=? AND d.after_snapshot_id=?
                       AND d.change_significance=? AND d.detected_at=?",
                    [$comparison['source_diff_id'], $actor['tenant_id'], $actor['workspace_id'],
                        $binding['monitored_source_id'], $binding['source_snapshot_id'],
                        $comparison['structural_significance'], $comparison['detected_at']],
                );
                if ($d === null) {
                    return Outcome::refuse('OB02_DIFF_SCOPE_OR_LINEAGE_UNVERIFIABLE', [], 'PROVENANCE', 'S4');
                }
            }
            $candidate = ObservationAssessment::fromLegacy($binding, $row);
            if (!$candidate->isOk()) {
                return $candidate;
            }
            $records[] = $candidate->value();
            $sourceIds[] = $binding['monitored_source_id'];
        }
        $cursor = $visible['data']['next_cursor'] ?? null;
        if ($cursor !== null && !Ids::isValid((string) $cursor)) {
            return Outcome::refuse('OB02_BACKFILL_CURSOR_INVALID');
        }
        $ledger = [
            'schema_version' => self::VERSION,
            'tenant_id' => $actor['tenant_id'],
            'workspace_id' => $actor['workspace_id'],
            'competitor_id' => $competitorId,
            'cursor_before' => $beforeSnapshotId,
            'cursor_after' => $cursor,
            'limit' => $limit,
            'record_lineage_digests' => array_column($records, 'lineage_digest'),
            'snapshot_ids' => array_column($records, 'source_snapshot_id'),
        ];
        $ledger['page_digest'] = Digest::canonicalDigest($ledger);
        return Outcome::ok([
            'version' => self::VERSION,
            'source_owner' => 'EXISTING_P01_OBSERVATION_HISTORY',
            'current_eligibility_rechecked_at' => $this->clock->now(),
            'page' => $ledger,
            'records' => $records,
            'record_count' => count($records),
            'cursor_before' => $beforeSnapshotId,
            'next_cursor' => $cursor,
            'question_identity' => CompetitorScope::QUESTION_UNKNOWN,
            'runtime_family_260_installed' => false,
            'preview_only' => true,
            'native_persistent_apply_authorized' => false,
            'source_bytes_retrieved' => false,
            'historical_assessments_reconstructed' => false,
            'terminal_no_change_journals_unchanged' => true,
            'customer_output_authorized' => false,
        ]);
    }
}
