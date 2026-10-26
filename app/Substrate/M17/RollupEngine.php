<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-07A deterministic, referential *candidate* rollups. Not a memory writer.
 * Uses only previously frozen LI-06A metadata objects; no provider, DB or source reads.
 */
final class RollupEngine
{
    public const VERSION = 'li07a.offline-rollup.v1';

    /** @param array<string,mixed> $input */
    public static function derive(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, [
            'input_set', 'window', 'profile_candidate', 'importance_weights',
        ]) || !is_array($input['input_set']) || !is_array($input['window'])
            || !is_array($input['profile_candidate'])
            || !InputSet::verify($input['input_set'])->isOk()
            || !WindowEngine::verifyWindow($input['window'])->isOk()
            || !ProfileResolver::verify($input['profile_candidate'])->isOk()) {
            return Outcome::refuse('M17_ROLLUP_INPUT_INVALID');
        }
        $set = $input['input_set'];
        $profile = $input['profile_candidate'];
        $window = $input['window'];
        if ($set['scope'] !== ($profile['scope'] ?? null)
            || $set['data_class'] !== ($profile['data_class'] ?? null)
            || ($profile['status'] ?? null) !== 'OFFLINE_NOT_ADMITTED'
            || ($profile['event_level_hold'] ?? null) !== false
            || ($profile['resolved']['minimum_lineage'] ?? null) !== 'EVERY_INPUT_REF'
            || ($profile['resolved']['conflict_behavior'] ?? null) !== 'ITEMIZE_ALWAYS'
            || ($profile['resolved']['summary_shape'] ?? null) !== 'STATS_ONLY'
            || ($profile['resolved']['release_eligible'] ?? null) !== false
            || ($profile['resolved']['customer_output_eligible'] ?? null) !== false) {
            return Outcome::refuse('M17_ROLLUP_PROFILE_OR_SCOPE_NOT_ADMISSIBLE');
        }
        // Even a rehashed caller-supplied profile may not hide an event-level hold,
        // legal/audit no-loss floor or an explicit forbid effect. It is not an
        // authenticated law register; it is only a conservative local refusal.
        foreach ($profile['applied_floor_candidates'] ?? [] as $floor) {
            if (!is_array($floor) || !is_string($floor['effect'] ?? null)
                || in_array($floor['effect'], ['FORCE_EVENT_LEVEL', 'FORBID'], true)) {
                return Outcome::refuse('M17_ROLLUP_HELD_OR_FORBIDDEN_PROFILE');
            }
        }
        $lineageResult = Lineage::derive(['input_set' => $set, 'window' => $window]);
        if (!$lineageResult->isOk()) {
            return $lineageResult;
        }
        $measureResult = DynamicMeasures::measure([
            'input_set' => $set, 'window' => $window,
            'importance_weights' => $input['importance_weights'],
        ]);
        if (!$measureResult->isOk()) {
            return $measureResult;
        }
        $lineage = $lineageResult->value();
        $measure = $measureResult->value();
        if ($lineage['selected_count'] !== $measure['reference_count']
            || array_column($lineage['selected_refs'], 'ref_id') !== $measure['reference_ids']
            || $lineage['contrary_or_conflict_ref_ids'] !== $measure['contrary_or_conflict_refs']
            || $lineage['high_diagnosticity_ref_ids'] !== $measure['high_diagnosticity_refs']) {
            return Outcome::refuse('M17_ROLLUP_LINEAGE_MEASURE_DIVERGENCE');
        }
        $refs = $lineage['selected_refs'];
        $oldest = $refs !== [] ? $refs[0]['observed_at_utc'] : null;
        $newest = $refs !== [] ? $refs[count($refs) - 1]['observed_at_utc'] : null;
        $status = $refs !== [] ? 'OFFLINE_NOT_ADMITTED' : 'INSUFFICIENT_INPUT';
        $bounds = [
            'kind' => $window['kind'],
            'start_inclusive_utc' => $window['start_inclusive_utc'],
            'end_exclusive_utc' => $window['end_exclusive_utc'],
            'calendar_timezone' => $window['calendar_timezone'],
            'calendar_origin' => $window['calendar_origin'],
            'tzdb_version' => $window['tzdb_version'],
            'start_offset_seconds' => $window['start_offset_seconds'],
            'end_offset_seconds' => $window['end_offset_seconds'],
            'duration_seconds' => $window['duration_seconds'],
        ];
        $manifest = [
            'version' => self::VERSION,
            'kind' => 'ROLLUP_CANDIDATE',
            'status' => $status,
            'generation_method' => 'DETERMINISTIC_STATISTIC',
            'engine_version' => self::VERSION,
            'scope' => $set['scope'],
            'data_class' => $set['data_class'],
            'window_bounds' => $bounds,
            'window_digest' => $window['window_digest'],
            'input_digest' => $set['input_digest'],
            'profile_resolution_digest' => $profile['resolution_digest'],
            'lineage' => $lineage,
            'measures' => $measure,
            'caveats' => [
                'uncertainty_code' => 'CALLER_SUPPLIED_METADATA_NOT_AUTHENTICATED',
                'oldest_observed_at_utc' => $oldest,
                'newest_observed_at_utc' => $newest,
                'contrary_or_conflict_refs' => $lineage['contrary_or_conflict_ref_ids'],
                'high_diagnosticity_refs' => $lineage['high_diagnosticity_ref_ids'],
                'outside_period_ref_ids' => $lineage['excluded_outside_window_ref_ids'],
                'source_rights_disposition' => 'NOT_ASSESSED',
                'coverage_disposition' => 'NOT_ASSESSED',
                'event_independence_disposition' => 'UNKNOWN',
                'commercial_materiality' => 'NOT_ASSESSED',
            ],
            'narrative_template_id' => 'LI07A_COUNTS_ONLY_V1',
            'narrative_safe' => sprintf(
                '%d caller-supplied reference(s), %d contrary/conflict, %d diagnostic; evidence and rights not verified.',
                count($refs), count($lineage['contrary_or_conflict_ref_ids']),
                count($lineage['high_diagnosticity_ref_ids']),
            ),
            'replayable_metadata_only' => true,
            'storage_write_performed' => false,
            'source_rights_verified' => false,
            'profile_authority_adopted' => false,
            'customer_output_eligible' => false,
            'release_eligible' => false,
            'historical_reuse_admitted' => false,
        ];
        return Outcome::ok([...$manifest, 'rollup_digest' => Digest::canonicalDigest($manifest)]);
    }

    /** Validates the self-contained digest/shape, NOT upstream source permissions or derivation. */
    public static function verify(array $rollup): Outcome
    {
        $digest = $rollup['rollup_digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            return Outcome::refuse('M17_ROLLUP_DIGEST_INVALID');
        }
        unset($rollup['rollup_digest']);
        if (!Vocabulary::exactKeys($rollup, [
            'version', 'kind', 'status', 'generation_method', 'engine_version', 'scope',
            'data_class', 'window_bounds', 'window_digest', 'input_digest',
            'profile_resolution_digest', 'lineage', 'measures', 'caveats',
            'narrative_template_id', 'narrative_safe', 'replayable_metadata_only',
            'storage_write_performed', 'source_rights_verified',
            'profile_authority_adopted', 'customer_output_eligible', 'release_eligible',
            'historical_reuse_admitted',
        ]) || ($rollup['version'] ?? null) !== self::VERSION
            || $rollup['engine_version'] !== self::VERSION
            || $rollup['kind'] !== 'ROLLUP_CANDIDATE'
            || !in_array($rollup['status'], ['OFFLINE_NOT_ADMITTED', 'INSUFFICIENT_INPUT'], true)
            || $rollup['generation_method'] !== 'DETERMINISTIC_STATISTIC'
            || !Vocabulary::scope($rollup['scope'])
            || !in_array($rollup['data_class'], Vocabulary::SUPPORTED_CLASSES, true)
            || !is_array($rollup['window_bounds'])
            || !Vocabulary::exactKeys($rollup['window_bounds'], [
                'kind', 'start_inclusive_utc', 'end_exclusive_utc', 'calendar_timezone',
                'calendar_origin', 'tzdb_version', 'start_offset_seconds',
                'end_offset_seconds', 'duration_seconds',
            ]) || !is_array($rollup['lineage'])
            || !Lineage::verify($rollup['lineage'])->isOk()
            || !is_array($rollup['measures'])
            || !Vocabulary::exactKeys($rollup['measures'], [
                'version', 'scope', 'data_class', 'input_digest', 'window_digest',
                'reference_count', 'reference_ids', 'polarity_counts',
                'contrary_or_conflict_refs', 'high_diagnosticity_refs',
                'distinct_payload_hashes', 'independent_event_count',
                'unknown_magnitude_count', 'known_magnitude_count',
                'magnitude_sum_milli', 'magnitude_mean_numerator',
                'magnitude_mean_denominator', 'population_variance_numerator',
                'population_variance_denominator', 'density_numerator',
                'density_denominator_seconds', 'business_importance_basis_points',
                'business_importance_basis', 'commercial_materiality',
                'source_authority_verified', 'historical_reuse_admitted', 'measure_digest',
            ])
            || ($rollup['measures']['source_authority_verified'] ?? null) !== false
            || ($rollup['measures']['historical_reuse_admitted'] ?? null) !== false
            || !array_key_exists('independent_event_count', $rollup['measures'])
            || $rollup['measures']['independent_event_count'] !== null
            || !is_array($rollup['caveats'])
            || !Vocabulary::exactKeys($rollup['caveats'], [
                'uncertainty_code', 'oldest_observed_at_utc', 'newest_observed_at_utc',
                'contrary_or_conflict_refs', 'high_diagnosticity_refs',
                'outside_period_ref_ids', 'source_rights_disposition',
                'coverage_disposition', 'event_independence_disposition',
                'commercial_materiality',
            ]) || !is_string($rollup['narrative_safe'])
            || strlen($rollup['narrative_safe']) > 256
            || $rollup['narrative_template_id'] !== 'LI07A_COUNTS_ONLY_V1'
            || $rollup['replayable_metadata_only'] !== true
            || $rollup['storage_write_performed'] !== false
            || $rollup['source_rights_verified'] !== false
            || $rollup['profile_authority_adopted'] !== false
            || $rollup['customer_output_eligible'] !== false
            || $rollup['release_eligible'] !== false
            || $rollup['historical_reuse_admitted'] !== false
            || $rollup['lineage']['scope'] !== $rollup['scope']
            || $rollup['lineage']['data_class'] !== $rollup['data_class']
            || $rollup['lineage']['input_digest'] !== $rollup['input_digest']
            || $rollup['lineage']['window_digest'] !== $rollup['window_digest']
            || ($rollup['measures']['input_digest'] ?? null) !== $rollup['input_digest']
            || ($rollup['measures']['window_digest'] ?? null) !== $rollup['window_digest']
            || ($rollup['measures']['scope'] ?? null) !== $rollup['scope']
            || ($rollup['measures']['data_class'] ?? null) !== $rollup['data_class']
            || ($rollup['caveats']['uncertainty_code'] ?? null) !== 'CALLER_SUPPLIED_METADATA_NOT_AUTHENTICATED'
            || ($rollup['caveats']['oldest_observed_at_utc'] ?? null)
                !== ($rollup['lineage']['selected_refs'][0]['observed_at_utc'] ?? null)
            || ($rollup['caveats']['newest_observed_at_utc'] ?? null)
                !== ($rollup['lineage']['selected_refs'][count($rollup['lineage']['selected_refs']) - 1]['observed_at_utc'] ?? null)
            || ($rollup['caveats']['source_rights_disposition'] ?? null) !== 'NOT_ASSESSED'
            || ($rollup['caveats']['commercial_materiality'] ?? null) !== 'NOT_ASSESSED'
            || ($rollup['caveats']['coverage_disposition'] ?? null) !== 'NOT_ASSESSED'
            || ($rollup['caveats']['event_independence_disposition'] ?? null) !== 'UNKNOWN'
            || $rollup['lineage']['selected_count'] !== $rollup['measures']['reference_count']
            || array_column($rollup['lineage']['selected_refs'], 'ref_id')
                !== ($rollup['measures']['reference_ids'] ?? null)
            || $rollup['lineage']['contrary_or_conflict_ref_ids']
                !== ($rollup['measures']['contrary_or_conflict_refs'] ?? null)
            || $rollup['lineage']['high_diagnosticity_ref_ids']
                !== ($rollup['measures']['high_diagnosticity_refs'] ?? null)
            || $rollup['caveats']['contrary_or_conflict_refs']
                !== $rollup['lineage']['contrary_or_conflict_ref_ids']
            || $rollup['caveats']['high_diagnosticity_refs']
                !== $rollup['lineage']['high_diagnosticity_ref_ids']
            || $rollup['caveats']['outside_period_ref_ids']
                !== $rollup['lineage']['excluded_outside_window_ref_ids']
            || $rollup['status'] !== ($rollup['lineage']['selected_count'] === 0
                ? 'INSUFFICIENT_INPUT' : 'OFFLINE_NOT_ADMITTED')
            || $rollup['narrative_safe'] !== sprintf(
                '%d caller-supplied reference(s), %d contrary/conflict, %d diagnostic; evidence and rights not verified.',
                $rollup['lineage']['selected_count'],
                count($rollup['lineage']['contrary_or_conflict_ref_ids']),
                count($rollup['lineage']['high_diagnosticity_ref_ids']),
            )) {
            return Outcome::refuse('M17_ROLLUP_SHAPE_INVALID');
        }
        try {
            $measureDigest = $rollup['measures']['measure_digest'];
            $measureParts = $rollup['measures'];
            unset($measureParts['measure_digest']);
            if (!is_string($measureDigest) || !preg_match('/^[0-9a-f]{64}$/D', $measureDigest)
                || !hash_equals($measureDigest, Digest::canonicalDigest($measureParts))) {
                return Outcome::refuse('M17_ROLLUP_SHAPE_INVALID');
            }
            if (!hash_equals($digest, Digest::canonicalDigest($rollup))) {
                return Outcome::refuse('M17_ROLLUP_DIGEST_MISMATCH');
            }
        } catch (\Throwable) {
            return Outcome::refuse('M17_ROLLUP_SHAPE_INVALID');
        }
        return Outcome::ok(['digest_verified' => true, 'derivation_verified' => false]);
    }
}
