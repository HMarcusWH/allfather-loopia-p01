<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * OB-02 disposable MariaDB candidate writer, NOT a runtime migration/adaptor.
 * No production factory wires this class. U01/U02 cannot be self-ratified by
 * passing a JSON flag; only a test-only, manually-installed candidate schema
 * opens this CLI fixture path, and only workspaces marked TEST may be written.
 *
 * Source rights are re-read through existing ObservationHistory every APPLY.
 * Writes are append-only metadata LINKS, not historical model assessments,
 * and never alter source_snapshots, source_diffs, provider or monitor journals.
 */
final class ObservationAssessmentStore
{
    public const VERSION = 'ob02.disposable-append-only-assessments.v1';

    private function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ObservationHistory $history,
    ) {}

    public static function forDisposableTest(Database $db, Clock $clock, ObservationHistory $history): Outcome
    {
        if (PHP_SAPI !== 'cli'
            || !defined('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED') !== true) {
            return Outcome::refuse('OB02_NATIVE_PERSISTENCE_U01_U02_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db, $clock, $history));
    }

    /**
     * @param array<string,mixed> $actor Real native M02 history actor from test harness.
     * @return Outcome<array<string,mixed>>
     */
    public function applyFixturePage(
        array $actor,
        string $competitorId,
        int $limit = 10,
        ?string $cursor = null,
    ): Outcome
    {
        $candidate = $this->history->previewAssessmentBackfill($actor, $competitorId, $limit, $cursor);
        if (!$candidate->isOk()) {
            return $candidate;
        }
        $preview = $candidate->value();
        if (($preview['preview_only'] ?? null) !== true
            || ($preview['native_persistent_apply_authorized'] ?? null) !== false
            || ($preview['historical_assessments_reconstructed'] ?? null) !== false
            || ($preview['page']['schema_version'] ?? null) !== ObservationBackfill::VERSION) {
            return Outcome::refuse('OB02_PREVIEW_CANNOT_AUTHORIZE_NATIVE_WRITE');
        }
        $workspace = $this->db->one(
            'SELECT environment_class,external_side_effects_allowed FROM workspaces
             WHERE workspace_id=? AND tenant_id=?',
            [$actor['workspace_id'], $actor['tenant_id']],
        );
        if ($workspace === null || $workspace['environment_class'] !== 'TEST'
            || (int) $workspace['external_side_effects_allowed'] !== 0) {
            return Outcome::refuse('OB02_DISPOSABLE_WORKSPACE_REQUIRED', [], 'AUTHORITY');
        }
        $page = $preview['page'];
        $pageDigest = Digest::canonicalDigest(array_diff_key($page, ['page_digest'=>true]));
        if ($pageDigest !== $page['page_digest']
            || $page['limit'] !== $limit || $page['cursor_before'] !== $cursor
            || $page['competitor_id'] !== $competitorId
            || $page['tenant_id'] !== $actor['tenant_id']
            || $page['workspace_id'] !== $actor['workspace_id']
            || count($preview['records']) !== $page['record_lineage_digests']
                && !is_array($page['record_lineage_digests'])) {
            return Outcome::refuse('OB02_CURSOR_LEDGER_UNTRUSTED');
        }
        $key = Digest::canonicalDigest([
            'version' => self::VERSION,
            'tenant_id' => $actor['tenant_id'],
            'workspace_id' => $actor['workspace_id'],
            'competitor_id' => $competitorId,
            'cursor_before' => $cursor,
            'limit' => $limit,
        ]);
        $now = $this->clock->now();
        try {
            return $this->db->transaction(function (Database $tx) use (
                $preview, $page, $key, $pageDigest, $now, $actor, $competitorId, $limit, $cursor
            ): Outcome {
                $prior = $tx->one(
                    'SELECT page_digest,p01_observation_backfill_ledger_id,record_count
                     FROM p01_observation_backfill_ledger
                     WHERE tenant_id=? AND workspace_id=? AND competitor_id=? AND cursor_key=? FOR UPDATE',
                    [$actor['tenant_id'],$actor['workspace_id'],$competitorId,$key],
                );
                if ($prior !== null) {
                    if (!hash_equals($prior['page_digest'], $pageDigest)
                        || (int)$prior['record_count'] !== count($preview['records'])) {
                        return Outcome::refuse('OB02_LEDGER_CURSOR_DRIFT_REFUSED');
                    }
                    return Outcome::ok([
                        'action' => 'EXACT_PAGE_REPLAY_NO_WRITE',
                        'page_digest' => $pageDigest,
                        'ledger_id' => $prior['p01_observation_backfill_ledger_id'],
                        'next_cursor' => $page['cursor_after'],
                        'new_assessments' => 0,
                        'new_refs' => 0,
                        'native_source_use_authorized' => false,
                        'historical_assessments_created' => false,
                    ]);
                }
                $added = 0;
                $refs = 0;
                foreach ($preview['records'] as $record) {
                    if (!ObservationAssessment::verify($record)->isOk()
                        || $record['tenant_id'] !== $actor['tenant_id']
                        || $record['workspace_id'] !== $actor['workspace_id']
                        || $record['competitor_id'] !== $competitorId
                        || $now < $record['source_captured_at']) {
                        return Outcome::refuse('OB02_APPLY_RECORD_NOT_PROVEN');
                    }
                    $stored = $tx->one(
                        'SELECT p01_observation_assessment_id,lineage_digest,record_json
                         FROM p01_observation_assessments
                         WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                           AND source_snapshot_id=? AND record_kind=? FOR UPDATE',
                        [$actor['tenant_id'],$actor['workspace_id'],$competitorId,
                            $record['source_snapshot_id'],$record['record_kind']],
                    );
                    $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    if ($stored !== null) {
                        if (!hash_equals($stored['lineage_digest'], $record['lineage_digest'])
                            || $stored['record_json'] !== $json) {
                            return Outcome::refuse('OB02_EXISTING_METADATA_LINEAGE_CONFLICT');
                        }
                        continue;
                    }
                    $id = Ids::new();
                    $tx->insert('p01_observation_assessments', [
                        'p01_observation_assessment_id'=>$id,
                        'tenant_id'=>$record['tenant_id'], 'workspace_id'=>$record['workspace_id'],
                        'competitor_id'=>$record['competitor_id'],
                        'monitored_source_id'=>$record['monitored_source_id'],
                        'source_ref_id'=>$record['source_ref_id'],
                        'source_license_card_id'=>$record['source_license_card_id'],
                        'source_snapshot_id'=>$record['source_snapshot_id'],
                        'evidence_id'=>$record['evidence_id'],
                        'question_id'=>null, 'question_identity'=>$record['question_identity'],
                        'run_id'=>null, 'record_kind'=>$record['record_kind'],
                        'coverage_class'=>$record['coverage_class'],
                        'assessment_status'=>'NOT_ASSESSED',
                        'business_significance'=>'NOT_ASSESSED',
                        'lineage_digest'=>$record['lineage_digest'], 'record_json'=>$json,
                        'source_captured_at'=>$record['source_captured_at'],
                        'known_at'=>$now, 'assessed_at'=>$now,
                        'human_annotation_id'=>null, 'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    ]);
                    foreach ($record['references'] as $ordinal=>$ref) {
                        $tx->insert('p01_observation_assessment_refs', [
                            'p01_observation_assessment_ref_id'=>Ids::new(),
                            'p01_observation_assessment_id'=>$id,
                            'tenant_id'=>$record['tenant_id'],
                            'workspace_id'=>$record['workspace_id'],
                            'ordinal_no'=>$ordinal, 'ref_kind'=>$ref['ref_kind'],
                            'source_snapshot_id'=>$ref['source_snapshot_id'],
                            'evidence_id'=>$ref['ref_kind']==='M05_EVIDENCE' ? $ref['ref_id'] : null,
                            'source_diff_id'=>$ref['ref_kind']==='P01_STRUCTURAL_DIFF' ? $ref['ref_id'] : null,
                            'ref_recorded_at'=>$ref['recorded_at'], 'created_at'=>$now,
                        ]);
                        $refs++;
                    }
                    $added++;
                }
                $id = Ids::new();
                $tx->insert('p01_observation_backfill_ledger',[
                    'p01_observation_backfill_ledger_id'=>$id,
                    'tenant_id'=>$actor['tenant_id'],'workspace_id'=>$actor['workspace_id'],
                    'competitor_id'=>$competitorId,'cursor_key'=>$key,
                    'cursor_before'=>$cursor,'cursor_after'=>$page['cursor_after'],
                    'page_limit'=>$limit,'page_digest'=>$pageDigest,
                    'record_count'=>count($preview['records']),
                    'newly_inserted_count'=>$added,'recorded_at'=>$now,
                    'state'=>'TEST_ONLY_NOT_ADMITTED',
                ]);
                return Outcome::ok([
                    'action' => 'DISPOSABLE_METADATA_LINKS_RECORDED',
                    'ledger_id' => $id,
                    'page_digest' => $pageDigest,
                    'next_cursor' => $page['cursor_after'],
                    'new_assessments' => $added,
                    'new_refs' => $refs,
                    'native_source_use_authorized' => false,
                    'historical_assessments_created' => false,
                ]);
            });
        } catch (\PDOException $e) {
            return Database::classify($e, 'OB02_DISPOSABLE_INSERT_CONFLICT');
        } catch (\Throwable) {
            return Outcome::failure('OB02_DISPOSABLE_PERSISTENCE_FAILED');
        }
    }

    /** Read one opaque, metadata-only candidate under a fresh owner history read. */
    public function replayMetadata(array $actor, string $competitorId, string $assessmentId): Outcome
    {
        if (!Ids::isValid($assessmentId) || !Ids::isValid($competitorId)) {
            return Outcome::refuse('OB02_ASSESSMENT_SELECTOR_INVALID');
        }
        $visible = $this->history->listSnapshots($actor, $competitorId, 1);
        if (($visible['decision'] ?? null) !== 'VISIBLE') {
            return Outcome::refuse('OB02_HISTORY_NOT_AVAILABLE', [], 'SCOPE', 'S4');
        }
        $row = $this->db->one(
            'SELECT record_json,lineage_digest,known_at,source_captured_at
             FROM p01_observation_assessments
             WHERE p01_observation_assessment_id=? AND tenant_id=? AND workspace_id=?
               AND competitor_id=? AND candidate_state=?',
            [$assessmentId,$actor['tenant_id'],$actor['workspace_id'],$competitorId,'TEST_ONLY_NOT_ADMITTED'],
        );
        if ($row === null) {
            return Outcome::refuse('OB02_ASSESSMENT_NOT_FOUND');
        }
        try {
            $record = json_decode($row['record_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return Outcome::refuse('OB02_STORED_METADATA_INVALID');
        }
        if (!is_array($record)
            || !ObservationAssessment::verify($record)->isOk()
            || !hash_equals($row['lineage_digest'], $record['lineage_digest'])
            || !Clock::isCanonical($row['known_at'])
            || $row['known_at'] < $row['source_captured_at']) {
            return Outcome::refuse('OB02_STORED_METADATA_INVALID');
        }
        $refs = $this->db->all(
            'SELECT ordinal_no,ref_kind,source_snapshot_id,evidence_id,source_diff_id,ref_recorded_at
             FROM p01_observation_assessment_refs
             WHERE p01_observation_assessment_id=? AND tenant_id=? AND workspace_id=?
             ORDER BY ordinal_no',
            [$assessmentId,$actor['tenant_id'],$actor['workspace_id']],
        );
        if (count($refs)!==$record['reference_count']) {
            return Outcome::refuse('OB02_STORED_REF_COUNT_MISMATCH');
        }
        foreach ($refs as $i=>$ref) {
            $expected = $record['references'][$i];
            if ((int)$ref['ordinal_no']!==$i
                || $ref['ref_kind']!==$expected['ref_kind']
                || $ref['source_snapshot_id']!==$expected['source_snapshot_id']
                || ($ref['evidence_id'] ?? $ref['source_diff_id'])!==$expected['ref_id']
                || $ref['ref_recorded_at']!==$expected['recorded_at']) {
                return Outcome::refuse('OB02_STORED_REF_LINEAGE_MISMATCH');
            }
        }
        return Outcome::ok([
            'integrity'=>'MATCH_METADATA_ONLY',
            'assessment_id'=>$assessmentId,
            'lineage_digest'=>$record['lineage_digest'],
            'recorded_now_not_historical'=>$row['known_at'],
            'assessment_status'=>'NOT_ASSESSED',
            'source_rights_for_new_model_use'=>false,
            'customer_output_authorized'=>false,
        ]);
    }
}
