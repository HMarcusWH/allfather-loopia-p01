<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;

/**
 * OB-02 candidate: immutable metadata-only link over already-retained P01/M05
 * snapshots and structural diff refs. NOT an inferred historical assessment.
 *
 * Source rights remain with ObservationHistory/M02/M05. No rejected model text,
 * source bytes, inferred legacy question ID, known-at backdating, provider call,
 * scheduler act, notification, or customer release is generated here.
 */
final class ObservationAssessment
{
    public const VERSION = 'ob02.legacy-metadata-link.v1';
    public const MAX_DIFF_REFERENCES = 8;
    public const MAX_PAGE_SIZE = 20;
    public const STATUS_NOT_ASSESSED = 'NOT_ASSESSED';
    public const VALID_COVERAGE = [
        'BASELINE','STRUCTURALLY_UNCHANGED','STRUCTURAL_CHANGE_RECORDED',
        'COMPARISON_UNAVAILABLE','MULTIPLE_COMPARISONS',
    ];
    private const HEX = '/^[a-f0-9]{64}$/D';

    /**
     * Turn one currently readable NATIVE row into a strictly synthetic-safe,
     * non-admitted candidate lineage link. A future U01 human/model assessment
     * must be a new, separate record, never a rewrite of this metadata.
     *
     * @param array<string,mixed> $bound CompetitorScope::bind result.
     * @param array<string,mixed> $historyRow Existing ObservationHistory row.
     */
    public static function fromLegacy(array $bound, array $historyRow): Outcome
    {
        if (($bound['schema_version'] ?? null) !== CompetitorScope::VERSION
            || (!array_key_exists('question_id', $bound) || $bound['question_id'] !== null)
            || ($bound['question_identity'] ?? null) !== CompetitorScope::QUESTION_UNKNOWN
            || ($bound['source_rights_readback'] ?? null) !== 'CURRENT_OWNER_FILTERED_READ_ONLY'
            || ($bound['source_bytes_read_here'] ?? null) !== false
            || ($bound['u01_u02_adopted'] ?? null) !== false
            || ($bound['customer_release_authorized'] ?? null) !== false) {
            return Outcome::refuse('OB02_NATIVE_SCOPE_NOT_PROVED');
        }
        foreach (['tenant_id','workspace_id','competitor_id','monitored_source_id',
            'source_ref_id','source_license_card_id','source_snapshot_id','evidence_id'] as $key) {
            if (!is_string($bound[$key] ?? null) || !Ids::isValid($bound[$key])) {
                return Outcome::refuse('OB02_ASSESSMENT_LINEAGE_IDENTITY_INVALID');
            }
        }
        if (($bound['source_snapshot_id'] ?? null) !== ($historyRow['source_snapshot_id'] ?? null)
            || ($bound['monitored_source_id'] ?? null) !== ($historyRow['monitored_source_id'] ?? null)
            || ($bound['snapshot_sequence'] ?? null) !== ($historyRow['snapshot_sequence'] ?? null)
            || ($bound['captured_at'] ?? null) !== ($historyRow['captured_at'] ?? null)) {
            return Outcome::refuse('OB02_ASSESSMENT_HISTORY_SCOPE_MISMATCH');
        }
        if (!is_string($bound['captured_at']) || !Clock::isCanonical($bound['captured_at'])
            || !is_string($bound['p01_canonical_content_hash'] ?? null)
            || preg_match(self::HEX, $bound['p01_canonical_content_hash']) !== 1) {
            return Outcome::refuse('OB02_CAPTURE_CLOCK_OR_HASH_INVALID');
        }
        $coverage = $historyRow['coverage_state'] ?? null;
        $diffs = $historyRow['comparisons'] ?? null;
        if (!in_array($coverage, self::VALID_COVERAGE, true)
            || !is_array($diffs) || !array_is_list($diffs)
            || count($diffs) > self::MAX_DIFF_REFERENCES) {
            return Outcome::refuse('OB02_COMPARISON_COVERAGE_INVALID');
        }
        if (($coverage === 'BASELINE' || $coverage === 'COMPARISON_UNAVAILABLE') && $diffs !== []
            || $coverage === 'STRUCTURALLY_UNCHANGED' && count($diffs) !== 1
            || $coverage === 'STRUCTURAL_CHANGE_RECORDED' && count($diffs) !== 1
            || $coverage === 'MULTIPLE_COMPARISONS' && count($diffs) < 2) {
            return Outcome::refuse('OB02_COVERAGE_COMPARISON_INCONSISTENT');
        }

        $refs = [[
            'ref_kind' => 'M05_EVIDENCE',
            'ref_id' => $bound['evidence_id'],
            'source_snapshot_id' => $bound['source_snapshot_id'],
            'structural_significance' => null,
            'recorded_at' => $bound['captured_at'],
        ]];
        $seen = [];
        foreach ($diffs as $diff) {
            if (!is_array($diff)
                || !is_string($diff['source_diff_id'] ?? null) || !Ids::isValid($diff['source_diff_id'])
                || isset($seen[$diff['source_diff_id']])
                || !in_array($diff['structural_significance'] ?? null, ['NONE','MINOR','MATERIAL','UNKNOWN'], true)
                || !is_string($diff['detected_at'] ?? null)
                || !Clock::isCanonical($diff['detected_at'])
                || $diff['detected_at'] < $bound['captured_at']) {
                return Outcome::refuse('OB02_COMPARISON_REFERENCE_INVALID');
            }
            $seen[$diff['source_diff_id']] = true;
            $refs[] = [
                'ref_kind' => 'P01_STRUCTURAL_DIFF',
                'ref_id' => $diff['source_diff_id'],
                'source_snapshot_id' => $bound['source_snapshot_id'],
                'structural_significance' => $diff['structural_significance'],
                'recorded_at' => $diff['detected_at'],
            ];
        }
        if ($coverage === 'STRUCTURALLY_UNCHANGED'
            && $diffs[0]['structural_significance'] !== 'NONE'
            || $coverage === 'STRUCTURAL_CHANGE_RECORDED'
            && $diffs[0]['structural_significance'] === 'NONE') {
            return Outcome::refuse('OB02_COVERAGE_SIGNIFICANCE_INCONSISTENT');
        }

        $category = match ($coverage) {
            'BASELINE' => 'BASELINE_NO_COMPARISON',
            'STRUCTURALLY_UNCHANGED' => 'UNCHANGED_STRUCTURE_NOT_MATERIALITY',
            'STRUCTURAL_CHANGE_RECORDED' => 'CHANGED_STRUCTURE_NOT_MATERIALITY',
            'MULTIPLE_COMPARISONS' => 'HELD_CONTRARY_OR_MULTIPLE_DIFF_REFERENCES',
            default => 'INSUFFICIENT_COMPARISON_EVIDENCE',
        };
        $record = [
            'schema_version' => self::VERSION,
            'record_kind' => 'LEGACY_METADATA_ONLY',
            'tenant_id' => $bound['tenant_id'],
            'workspace_id' => $bound['workspace_id'],
            'competitor_id' => $bound['competitor_id'],
            'question_id' => null,
            'question_identity' => CompetitorScope::QUESTION_UNKNOWN,
            'monitored_source_id' => $bound['monitored_source_id'],
            'source_ref_id' => $bound['source_ref_id'],
            'source_license_card_id' => $bound['source_license_card_id'],
            'source_snapshot_id' => $bound['source_snapshot_id'],
            'evidence_id' => $bound['evidence_id'],
            'snapshot_sequence' => $bound['snapshot_sequence'],
            'source_captured_at' => $bound['captured_at'],
            'p01_canonical_content_hash' => $bound['p01_canonical_content_hash'],
            'reference_count' => count($refs),
            'references' => $refs,
            'coverage_class' => $category,
            'comparison_coverage' => $coverage,
            'bytes_availability' => 'NOT_INSPECTED',
            'assessment_status' => self::STATUS_NOT_ASSESSED,
            'business_significance' => 'NOT_ASSESSED',
            'human_annotation_id' => null,
            'model_prompt_version' => null,
            'run_id' => null,
            'known_at' => null,
            'recorded_at' => null,
            'knowledge_clock' => 'ACTUAL_APPEND_TIME_ONLY',
            'source_use_readback' => 'CURRENT_FILTERED_READ_ONLY',
            'source_rights_for_new_use_authorized' => false,
            'model_quality_verified' => false,
            'attention_or_notification_authorized' => false,
            'customer_release_authorized' => false,
            'historical_assessment_reconstructed' => false,
            'candidate_only' => true,
            'source_bytes_stored' => false,
        ];
        $record['lineage_digest'] = Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    /** Verify integrity of an inert metadata-only record, never source rights. */
    public static function verify(array $candidate): Outcome
    {
        if (($candidate['schema_version'] ?? null) !== self::VERSION
            || ($candidate['record_kind'] ?? null) !== 'LEGACY_METADATA_ONLY'
            || (!array_key_exists('question_id', $candidate) || $candidate['question_id'] !== null)
            || ($candidate['question_identity'] ?? null) !== CompetitorScope::QUESTION_UNKNOWN
            || ($candidate['assessment_status'] ?? null) !== 'NOT_ASSESSED'
            || ($candidate['business_significance'] ?? null) !== 'NOT_ASSESSED'
            || ($candidate['bytes_availability'] ?? null) !== 'NOT_INSPECTED'
            || (!array_key_exists('human_annotation_id', $candidate) || $candidate['human_annotation_id'] !== null)
            || (!array_key_exists('known_at', $candidate) || $candidate['known_at'] !== null)
            || (!array_key_exists('recorded_at', $candidate) || $candidate['recorded_at'] !== null)
            || (!array_key_exists('run_id', $candidate) || $candidate['run_id'] !== null)
            || (!array_key_exists('model_prompt_version', $candidate) || $candidate['model_prompt_version'] !== null)
            || ($candidate['source_rights_for_new_use_authorized'] ?? null) !== false
            || ($candidate['model_quality_verified'] ?? null) !== false
            || ($candidate['attention_or_notification_authorized'] ?? null) !== false
            || ($candidate['customer_release_authorized'] ?? null) !== false
            || ($candidate['historical_assessment_reconstructed'] ?? null) !== false
            || ($candidate['source_bytes_stored'] ?? null) !== false
            || ($candidate['candidate_only'] ?? null) !== true
            || !is_string($candidate['lineage_digest'] ?? null)
            || preg_match(self::HEX, $candidate['lineage_digest']) !== 1) {
            return Outcome::refuse('OB02_METADATA_RECORD_NOT_ADMITTED');
        }
        $copy = $candidate;
        unset($copy['lineage_digest']);
        if (!hash_equals(Digest::canonicalDigest($copy), $candidate['lineage_digest'])) {
            return Outcome::refuse('OB02_METADATA_LINEAGE_DIGEST_MISMATCH');
        }
        $refs = $candidate['references'] ?? null;
        if (!is_array($refs) || !array_is_list($refs)
            || count($refs) < 1 || count($refs) > self::MAX_DIFF_REFERENCES + 1
            || !is_int($candidate['reference_count'] ?? null)
            || count($refs) !== $candidate['reference_count']) {
            return Outcome::refuse('OB02_METADATA_REFERENCE_INVALID');
        }
        return Outcome::ok($candidate);
    }

    /** Distinct safe gap classifications, with NO automatic backfilled row. */
    public static function gap(string $reason): Outcome
    {
        $codes = [
            'REVOKED_SOURCE_RIGHTS' => 'HELD_CURRENT_SOURCE_RIGHTS',
            'MISSING_SOURCE_BYTES' => 'HELD_BYTES_MISSING',
            'REJECTED_CANDIDATE' => 'NO_RECONSTRUCTION_REJECTED',
            'TERMINAL_NO_CHANGE' => 'PRESERVE_TERMINAL_NO_CHANGE',
            'AMBIGUOUS_SOURCE' => 'HELD_UNVERIFIED_SOURCE_REF',
            'INSUFFICIENT_EVIDENCE' => 'HELD_INSUFFICIENT_BASIS',
        ];
        if (!isset($codes[$reason])) {
            return Outcome::refuse('OB02_UNKNOWN_GAP_REASON');
        }
        return Outcome::ok([
            'category' => $codes[$reason],
            'backfill_record_created' => false,
            'model_run_permitted' => false,
            'source_use_admitted' => false,
        ]);
    }
}
