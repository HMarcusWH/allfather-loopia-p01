<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-06A deterministic statistics over ALREADY SUPPLIED reference metadata only.
 * Integer and rational numbers avoid float/locale ambiguity. Magnitude is structural,
 * not business materiality; weights and change labels are unauthenticated candidates.
 */
final class DynamicMeasures
{
    public const VERSION = 'li06a.offline-dynamic-measures.v1';

    /** @param array<string,mixed> $input */
    public static function measure(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, ['input_set','window','importance_weights'])
            || !is_array($input['input_set']) || !is_array($input['window'])
            || !InputSet::verify($input['input_set'])->isOk()
            || !WindowEngine::verifyWindow($input['window'])->isOk()) {
            return Outcome::refuse('M17_MEASURES_INPUT_INVALID');
        }
        $weights = $input['importance_weights'];
        if ($weights !== null) {
            if (!is_array($weights) || !Vocabulary::exactKeys($weights, [
                'profile_weight_basis_points','entity_role_weight_basis_points',
            ])) {
                return Outcome::refuse('M17_IMPORTANCE_WEIGHT_INVALID');
            }
            foreach ($weights as $value) {
                if (!is_int($value) || $value < 0 || $value > 10000) {
                    return Outcome::refuse('M17_IMPORTANCE_WEIGHT_INVALID');
                }
            }
        }
        $window = $input['window'];
        $refs = [];
        $sumsq = 0;
        $sum = 0;
        $magnitudeKnown = 0;
        $polarityCounts = array_fill_keys(Vocabulary::POLARITY, 0);
        $contradictions = [];
        $diagnostics = [];
        $digests = [];
        foreach ($input['input_set']['refs'] as $row) {
            if ($row['observed_at_utc'] < $window['start_inclusive_utc']
                || $row['observed_at_utc'] >= $window['end_exclusive_utc']) {
                continue;
            }
            $refs[] = $row['ref_id'];
            $digests[$row['payload_sha256']] = true;
            $polarityCounts[$row['polarity']]++;
            if ($row['polarity'] === 'CONTRARY' || $row['conflict_flag']) {
                $contradictions[] = $row['ref_id'];
            }
            if ($row['diagnostic_flag']) {
                $diagnostics[] = $row['ref_id'];
            }
            if ($row['magnitude_milli'] !== null) {
                $magnitudeKnown++;
                $sum += $row['magnitude_milli'];
                $sumsq += $row['magnitude_milli'] * $row['magnitude_milli'];
            }
        }
        $varianceN = $magnitudeKnown >= 2
            ? $magnitudeKnown * $sumsq - $sum * $sum : null;
        $varianceD = $magnitudeKnown >= 2
            ? $magnitudeKnown * $magnitudeKnown : null;
        $importance = $weights === null ? null : intdiv(
            $weights['profile_weight_basis_points'] * $weights['entity_role_weight_basis_points'],
            10000,
        );
        $result = [
            'version'=>self::VERSION,
            'scope'=>$input['input_set']['scope'],
            'data_class'=>$input['input_set']['data_class'],
            'input_digest'=>$input['input_set']['input_digest'],
            'window_digest'=>$window['window_digest'],
            'reference_count'=>count($refs),
            'reference_ids'=>$refs,
            'polarity_counts'=>$polarityCounts,
            'contrary_or_conflict_refs'=>$contradictions,
            'high_diagnosticity_refs'=>$diagnostics,
            'distinct_payload_hashes'=>count($digests),
            'independent_event_count'=>null,
            'unknown_magnitude_count'=>count($refs)-$magnitudeKnown,
            'known_magnitude_count'=>$magnitudeKnown,
            'magnitude_sum_milli'=>$sum,
            'magnitude_mean_numerator'=>$magnitudeKnown ? $sum : null,
            'magnitude_mean_denominator'=>$magnitudeKnown ?: null,
            'population_variance_numerator'=>$varianceN,
            'population_variance_denominator'=>$varianceD,
            'density_numerator'=>count($refs),
            'density_denominator_seconds'=>$window['duration_seconds'],
            'business_importance_basis_points'=>$importance,
            'business_importance_basis'=>$weights === null
                ? 'UNKNOWN' : 'CALLER_SUPPLIED_WEIGHTS_UNVERIFIED',
            'commercial_materiality'=>'NOT_ASSESSED',
            'source_authority_verified'=>false,
            'historical_reuse_admitted'=>false,
        ];
        return Outcome::ok([...$result, 'measure_digest'=>Digest::canonicalDigest($result)]);
    }
}
