<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * OB-03: bounded CR-02 section deltas + OB-02 legacy metadata + LI-04 features.
 *
 * Every input is a candidate readback, NOT an authenticated source-use
 * entitlement. Do not confuse the CR-01 original-HTML byte SHA with the P01
 * canonical URL/body hash. This interpreter never reads or returns source text
 * and cannot create a model/evidence/release or assert independent origins.
 */
final class ObservationFeatureSet
{
    public const VERSION = 'ob03.observation-feature-set.v1';
    public const MAX_DELTAS = 128;
    private const HEX = '/^[a-f0-9]{64}$/D';
    private const ALIGNMENTS = ['EXACT_DERIVED_SELECTOR','WEAK_UNIQUE_TYPE_AND_BODY','UNPAIRED'];
    private const LI04_CROSSWALK = [
        'NAV_ORDER_CHANGED' => ['ORDER_ONLY'],
        'PRICE_TOKEN_CHANGED' => ['PRICE_CHANGED','MULTIPLE_SENSITIVE_TOKENS_CHANGED'],
        'NEGATION_TOKEN_CHANGED' => ['NEGATION_CHANGED','MULTIPLE_SENSITIVE_TOKENS_CHANGED'],
        'DATE_LITERAL_CHANGED' => ['DATE_CHANGED','MULTIPLE_SENSITIVE_TOKENS_CHANGED'],
    ];

    /**
     * @param array<string,mixed> $assessment OB-02 unadmitted metadata record
     * @param array<string,mixed> $delta CR-02 SemanticDelta.v1 result
     * @param list<array<string,mixed>> $captures LI-04 exact capture/hash records
     * @param ?array<string,mixed> $boundedInput Optional supplied *raw bytes* for
     *       LI-04 lightweight cross-check, discarded immediately after use.
     */
    public static function compose(
        array $assessment, array $delta, array $captures, ?array $boundedInput = null,
    ): Outcome {
        if (!ObservationAssessment::verify($assessment)->isOk()
            || ($assessment['schema_version'] ?? null) !== ObservationAssessment::VERSION
            || ($assessment['record_kind'] ?? null) !== 'LEGACY_METADATA_ONLY'
            || ($assessment['assessment_status'] ?? null) !== 'NOT_ASSESSED'
            || ($assessment['source_rights_for_new_use_authorized'] ?? null) !== false
            || ($assessment['source_bytes_stored'] ?? null) !== false) {
            return Outcome::refuse('OB03_OB02_METADATA_NOT_ADMITTED');
        }
        if (($delta['schema_version'] ?? null) !== SemanticDelta::VERSION
            || ($delta['algorithm_version'] ?? null) !== SemanticDelta::ALGORITHM_VERSION
            || ($delta['fingerprint_version'] ?? null) !== SectionFingerprint::VERSION
            || ($delta['derived_only'] ?? null) !== true
            || ($delta['commercial_significance'] ?? null) !== 'NOT_ASSESSED'
            || ($delta['source_use_rights_rechecked'] ?? null) !== false
            || ($delta['customer_release_authorized'] ?? null) !== false
            || ($delta['provider_or_model_authorized'] ?? null) !== false
            || ($delta['production_authorized'] ?? null) !== false
            || !is_string($delta['delta_digest'] ?? null)
            || preg_match(self::HEX, $delta['delta_digest']) !== 1) {
            return Outcome::refuse('OB03_CR02_DELTA_CONTRACT_INVALID');
        }
        $copy = $delta;
        unset($copy['delta_digest']);
        if (!hash_equals(Digest::canonicalDigest($copy), $delta['delta_digest'])) {
            return Outcome::refuse('OB03_CR02_DELTA_DIGEST_MISMATCH');
        }
        $scope = $delta['source_scope'] ?? null;
        $before = $delta['before'] ?? null;
        $after = $delta['after'] ?? null;
        if (!is_array($scope) || !is_array($before) || !is_array($after)
            || ($scope['tenant_id'] ?? null) !== $assessment['tenant_id']
            || ($scope['workspace_id'] ?? null) !== $assessment['workspace_id']
            || ($scope['monitored_source_id'] ?? null) !== $assessment['monitored_source_id']
            || ($scope['source_ref_id'] ?? null) !== $assessment['source_ref_id']
            || ($scope['source_card_id'] ?? null) !== $assessment['source_license_card_id']
            || ($after['capture_ref_id'] ?? null) !== $assessment['source_snapshot_id']
            || ($after['evidence_ref_id'] ?? null) !== $assessment['evidence_id']
            || ($before['capture_ref_id'] ?? null) === ($after['capture_ref_id'] ?? null)
            || ($before['evidence_ref_id'] ?? null) === ($after['evidence_ref_id'] ?? null)
            || !is_string($after['raw_sha256'] ?? null)
            || !is_string($before['raw_sha256'] ?? null)
            || preg_match(self::HEX, $after['raw_sha256']) !== 1
            || preg_match(self::HEX, $before['raw_sha256']) !== 1) {
            return Outcome::refuse('OB03_CR02_OB02_SOURCE_BINDING_MISMATCH', [], 'SCOPE', 'S4');
        }
        $grouping = SourceOriginGroups::inspect([
            'tenant_id'=>$assessment['tenant_id'],
            'workspace_id'=>$assessment['workspace_id'],
        ], $captures);
        if (!$grouping->isOk()) {
            return $grouping;
        }
        $group = $grouping->value();
        $afterPresent = 0;
        foreach ($captures as $capture) {
            if ($capture['capture_id'] === $assessment['source_snapshot_id']) {
                $afterPresent++;
                if ($capture['source_ref_id'] !== $assessment['source_ref_id']
                    || $capture['payload_sha256'] !== $after['raw_sha256']) {
                    return Outcome::refuse('OB03_ORIGIN_CAPTURE_BYTE_LINEAGE_MISMATCH');
                }
            }
        }
        if ($afterPresent !== 1) {
            return Outcome::refuse('OB03_ORIGIN_AFTER_CAPTURE_REQUIRED');
        }
        $bounded = null;
        $kinds = [];
        if ($boundedInput !== null) {
            if (($boundedInput['before_snapshot_id'] ?? null) !== $before['capture_ref_id']
                || ($boundedInput['after_snapshot_id'] ?? null) !== $after['capture_ref_id']
                || ($boundedInput['before_evidence_id'] ?? null) !== $before['evidence_ref_id']
                || ($boundedInput['after_evidence_id'] ?? null) !== $after['evidence_ref_id']
                || ($boundedInput['before_body_sha256'] ?? null) !== $before['raw_sha256']
                || ($boundedInput['after_body_sha256'] ?? null) !== $after['raw_sha256']) {
                return Outcome::refuse('OB03_LI04_RAW_BYTE_PROJECTION_MISMATCH');
            }
            $extracted = ObservationFeatures::extract($boundedInput);
            if (!$extracted->isOk()) {
                return $extracted;
            }
            $bounded = $extracted->value();
            foreach ($bounded['features'] as $feature) {
                if (!isset(self::LI04_CROSSWALK[$feature['kind']])) {
                    return Outcome::refuse('OB03_UNKNOWN_LI04_FEATURE_KIND');
                }
                $kinds[$feature['kind']] = true;
            }
        }
        $sections = $delta['deltas'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)
            || count($sections) !== ($delta['delta_count'] ?? null)
            || count($sections) > self::MAX_DELTAS) {
            return Outcome::refuse('OB03_SECTION_DELTA_LIMIT_OR_SHAPE');
        }
        $featureRows = [];
        $deduplicated = [];
        $semanticKinds = [];
        $materialityUnassessed = true;
        foreach ($sections as $d) {
            if (!is_array($d)
                || !is_string($d['change_kind'] ?? null)
                || !is_array($d['sensitive_token_kinds'] ?? null)
                || !array_is_list($d['sensitive_token_kinds'])
                || !is_array($d['uncertainty_codes'] ?? null)
                || !array_is_list($d['uncertainty_codes'])
                || !in_array($d['alignment'] ?? null, self::ALIGNMENTS, true)
                || ($d['original_byte_offsets_verified'] ?? null) !== false
                || ($d['source_excerpt_not_exported'] ?? null) !== true
                || ($d['before_source_sha256'] ?? $before['raw_sha256']) !== $before['raw_sha256']
                || ($d['after_source_sha256'] ?? $after['raw_sha256']) !== $after['raw_sha256']) {
                return Outcome::refuse('OB03_SECTION_DELTA_PROVENANCE_INVALID');
            }
            $semanticKinds[$d['change_kind']] = true;
            $signature = Digest::canonicalDigest([
                'version'=>self::VERSION,
                'source_ref_id'=>$assessment['source_ref_id'],
                'before_raw_sha256'=>$before['raw_sha256'],
                'after_raw_sha256'=>$after['raw_sha256'],
                'change_kind'=>$d['change_kind'],
                'before_text_sha256'=>$d['before_text_sha256'] ?? null,
                'after_text_sha256'=>$d['after_text_sha256'] ?? null,
                'before_order_fingerprint'=>$d['before_order_fingerprint'] ?? null,
                'after_order_fingerprint'=>$d['after_order_fingerprint'] ?? null,
            ]);
            $deduplicated[$signature] = true;
            $featureRows[] = [
                'change_kind'=>$d['change_kind'],
                'alignment'=>$d['alignment'],
                'before_section_origin_id'=>$d['before_origin_id'] ?? null,
                'after_section_origin_id'=>$d['after_origin_id'] ?? null,
                'before_content_sha256'=>$d['before_text_sha256'] ?? null,
                'after_content_sha256'=>$d['after_text_sha256'] ?? null,
                'sensitive_token_kinds'=>$d['sensitive_token_kinds'],
                'uncertainty_codes'=>$d['uncertainty_codes'],
                'exact_change_signature'=>$signature,
                'raw_html_offsets_verified'=>false,
                'source_content_exported'=>false,
            ];
        }
        $compatible = 0;
        $conflicted = [];
        if ($bounded !== null) {
            foreach (array_keys($kinds) as $kind) {
                $matches = array_intersect(self::LI04_CROSSWALK[$kind], array_keys($semanticKinds));
                if ($matches === []) {
                    $conflicted[] = $kind;
                } else {
                    $compatible++;
                }
            }
        }
        $crosswalk = $bounded === null ? 'NOT_PROVIDED'
            : ($conflicted !== [] ? 'DISAGREEMENT_HELD'
                : ($kinds === [] ? 'NO_BOUNDED_FEATURE_TO_CONFIRM'
                    : 'CONCORDANT_BOUNDED_TOKEN_CLASSES'));
        $record = [
            'schema_version'=>self::VERSION,
            'type'=>'OBSERVATION_FEATURE_CANDIDATE_ONLY',
            'scope'=>[
                'tenant_id'=>$assessment['tenant_id'],
                'workspace_id'=>$assessment['workspace_id'],
                'competitor_id'=>$assessment['competitor_id'],
                'monitored_source_id'=>$assessment['monitored_source_id'],
                'source_ref_id'=>$assessment['source_ref_id'],
                'source_card_id'=>$assessment['source_license_card_id'],
            ],
            'source_lineage'=>[
                'assessment_metadata_digest'=>$assessment['lineage_digest'],
                'semantic_delta_digest'=>$delta['delta_digest'],
                'after_snapshot_id'=>$assessment['source_snapshot_id'],
                'after_evidence_id'=>$assessment['evidence_id'],
                'before_snapshot_id'=>$before['capture_ref_id'],
                'before_evidence_id'=>$before['evidence_ref_id'],
                'p01_canonical_hash_not_raw_html'=>$assessment['p01_canonical_content_hash'],
                'before_raw_html_sha256'=>$before['raw_sha256'],
                'after_raw_html_sha256'=>$after['raw_sha256'],
            ],
            'feature_rows'=>$featureRows,
            'section_delta_count'=>count($featureRows),
            'unique_exact_change_signatures'=>count($deduplicated),
            'bounded_feature_fingerprint'=>$bounded['input_fingerprint'] ?? null,
            'bounded_feature_count'=>$bounded === null ? null : count($bounded['features']),
            'bounded_feature_crosswalk'=>$crosswalk,
            'crosswalk_matched_feature_kinds'=>$compatible,
            'crosswalk_disagreements'=>$conflicted,
            'bounded_feature_notes'=>$bounded === null ? [] : $bounded['extraction_notes'],
            'byte_class_summary'=>[
                'capture_count'=>$group['capture_count'],
                'distinct_exact_payload_count'=>$group['distinct_exact_payload_count'],
                'exact_byte_repeat_count'=>$group['exact_byte_repeat_count'],
                'distinct_source_ref_count'=>$group['distinct_source_ref_count'],
                'grouping_input_fingerprint'=>$group['input_fingerprint'],
            ],
            'event_identity'=>'UNKNOWN',
            'distinct_real_world_event_count'=>null,
            'independently_supported_event_count'=>null,
            'independent_corroboration_count'=>null,
            'independent_origin_established'=>false,
            'business_materiality'=>'NOT_ASSESSED',
            'current_source_use_admitted'=>false,
            'u02_u03_owner_adopted'=>false,
            'owner_authenticated_origin_assertions'=>false,
            'runtime_persistence_authorized'=>false,
            'customer_release_authorized'=>false,
            'model_or_external_action_authorized'=>false,
            'source_excerpt_exported'=>false,
            'derived_not_primary_evidence'=>true,
        ];
        $record['feature_digest'] = Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    /** Verify candidate shape/digest, not owner authenticity or source rights. */
    public static function verify(array $candidate): bool
    {
        if (($candidate['schema_version'] ?? null) !== self::VERSION
            || ($candidate['type'] ?? null) !== 'OBSERVATION_FEATURE_CANDIDATE_ONLY'
            || ($candidate['business_materiality'] ?? null) !== 'NOT_ASSESSED'
            || ($candidate['u02_u03_owner_adopted'] ?? null) !== false
            || ($candidate['runtime_persistence_authorized'] ?? null) !== false
            || ($candidate['independent_corroboration_count'] ?? 'BAD') !== null
            || ($candidate['customer_release_authorized'] ?? null) !== false
            || !is_string($candidate['feature_digest'] ?? null)
            || preg_match(self::HEX, $candidate['feature_digest']) !== 1) {
            return false;
        }
        $copy = $candidate;
        unset($copy['feature_digest']);
        return hash_equals($candidate['feature_digest'], Digest::canonicalDigest($copy));
    }
}
