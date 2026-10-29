<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * HS-01: immutable point-in-time metadata candidate, composed over the
 * existing LI-05 selector. An input hash is NOT M05 authentication or U03
 * source-use permission. No captured source bytes enter this contract.
 *
 * Keep LI-05 v1 unchanged: old frozen hashes remain replayable.
 */
final class PointInTimeHistoricalBasis
{
    public const VERSION = 'hs01.point-in-time-historical-basis.v1';
    public const CALENDAR = 'UTC_PROLEPTIC_GREGORIAN_SECONDS_V1';
    public const SELECTION_POLICY = 'CONTRARY_FIRST_BOUNDED_V1';
    private const HEX = '/^[a-f0-9]{64}$/D';
    private const REQUEST_KEYS = ['calendar','legacy_input','previous_basis_digest','review_at_utc'];
    private const COUNTS = [
        'BEFORE_WINDOW'=>0, 'AFTER_WINDOW'=>0, 'NOT_YET_KNOWN'=>0,
        'REPORTED_NOT_USABLE'=>0, 'SELECTION_CAP'=>0,
    ];
    private const OUTPUT_KEYS = [
        'schema_version', 'candidate_kind', 'status', 'scope', 'period',
        'baseline_period', 'baseline_null_reason', 'calendar', 'window_clock',
        'event_clock_authoritative', 'knowledge_cutoff_utc', 'review_at_utc',
        'selection_policy', 'source_input_digest', 'previous_basis_digest',
        'input_count', 'selected_count', 'selected_references',
        'excluded_references', 'excluded_counts_by_reason',
        'eligible_contrary_count', 'selected_contrary_count',
        'excluded_contrary_count', 'data_disposition', 'selection_integrity',
        'coverage', 'coverage_owner_verified', 'rights_disposition',
        'historical_reuse_admitted', 'model_context_authorized',
        'provider_disclosure_authorized', 'customer_release_authorized',
        'native_persistence_authorized', 'origin_independence_verified',
        'basis_digest',
    ];
    private const REFERENCE_KEYS = [
        'ref_id','source_ref_id','evidence_id','snapshot_id','assessment_id',
        'payload_sha256','event_at','observed_at','recorded_at','known_at',
        'analyzed_at','analysis_as_of_cutoff','semantic_polarity',
        'origin_group','provenance','rights_disposition',
    ];
    private const EXCLUDED_KEYS = [
        'ref_id','reference_input_digest','reason','semantic_polarity',
        'known_at_utc',
    ];

    /**
     * @param array<string,mixed> $request
     * Only caller-provided metadata can be selected. No owner authentication,
     * rights, approval, or actual review is implied.
     */
    public static function freeze(array $request): Outcome
    {
        if (!self::keys($request, self::REQUEST_KEYS)
            || $request['calendar'] !== self::CALENDAR
            || !HistoricalEvidenceSelector::utc($request['review_at_utc'])
            || !is_array($request['legacy_input'])
            || ($request['previous_basis_digest'] !== null
                && !self::hex($request['previous_basis_digest']))) {
            return Outcome::refuse('HS01_REQUEST_CONTRACT_INVALID');
        }
        $input = $request['legacy_input'];
        $validated = HistoricalEvidenceSelector::select($input);
        if (!$validated->isOk()) {
            return $validated;
        }
        if ($request['review_at_utc'] < $input['knowledge_cutoff_utc']) {
            return Outcome::refuse('HS01_REVIEW_PRECEDES_KNOWLEDGE_CUT');
        }
        // Re-validate with the historical owner and retain all its v1 rules:
        // same-scope rows, no injected fields, bounded size, four source clocks,
        // unique evidence/snapshot IDs, honest caller-provided coverage.
        $v1 = $validated->value();
        $rows = $input['rows'];
        usort($rows, static fn(array $a, array $b): int =>
            [$a['observed_at'],$a['ref_id']] <=> [$b['observed_at'],$b['ref_id']]);

        $cut = $input['knowledge_cutoff_utc'];
        $window = $input['period'];
        $eligible = [];
        $excludedById = [];
        foreach ($rows as $row) {
            $reason = null;
            if ($row['observed_at'] < $window['start_inclusive_utc']) {
                $reason = 'BEFORE_WINDOW';
            } elseif ($row['observed_at'] >= $window['end_exclusive_utc']) {
                $reason = 'AFTER_WINDOW';
            } elseif ($row['known_at'] > $cut) {
                $reason = 'NOT_YET_KNOWN';
            } elseif ($row['reported_disposition'] !== 'UNASSESSED') {
                $reason = 'REPORTED_NOT_USABLE';
            }
            if ($reason !== null) {
                $excludedById[$row['ref_id']] = $reason;
            } else {
                $eligible[] = $row;
            }
        }
        // Keep contrary observations at the head of the limited candidate pool.
        // When the cap is too small to retain everything, say so rather than
        // presenting the selected subset as a complete review basis.
        usort($eligible, static fn(array $a, array $b): int =>
            [($a['semantic_polarity'] === 'CONTRARY') ? 0 : 1,
             $a['observed_at'], $a['ref_id']]
            <=>
            [($b['semantic_polarity'] === 'CONTRARY') ? 0 : 1,
             $b['observed_at'], $b['ref_id']]);
        $kept = array_slice($eligible, 0, $input['max_references']);
        foreach (array_slice($eligible, $input['max_references']) as $row) {
            $excludedById[$row['ref_id']] = 'SELECTION_CAP';
        }
        usort($kept, static fn(array $a, array $b): int =>
            [$a['observed_at'],$a['ref_id']] <=> [$b['observed_at'],$b['ref_id']]);

        $selected = [];
        $selectedContrary = 0;
        foreach ($kept as $row) {
            $selected[] = self::project($row, $cut);
            if ($row['semantic_polarity'] === 'CONTRARY') {
                $selectedContrary++;
            }
        }
        $excluded = [];
        $counts = self::COUNTS;
        $excludedContrary = 0;
        foreach ($rows as $row) {
            $reason = $excludedById[$row['ref_id']] ?? null;
            if ($reason === null) {
                continue;
            }
            $counts[$reason]++;
            if ($row['semantic_polarity'] === 'CONTRARY') {
                $excludedContrary++;
            }
            // Do not expose future-known IDs, claims, polarity, dates or
            // source references in a historical as-of candidate. Only a
            // diagnostic digest + reason remain in the sealed audit manifest.
            $future = $reason === 'NOT_YET_KNOWN';
            $excluded[] = [
                'ref_id'=>$future ? null : $row['ref_id'],
                'reference_input_digest'=>Digest::canonicalDigest($row),
                'reason'=>$reason,
                'semantic_polarity'=>$future ? 'UNKNOWN_AS_OF_CUTOFF' : $row['semantic_polarity'],
                'known_at_utc'=>$future ? null : $row['known_at'],
            ];
        }
        $coverage = $v1['coverage'];
        $coverageHasGaps = $coverage['state'] !== 'KNOWN'
            || ($coverage['failed'] ?? 0) > 0 || ($coverage['skipped'] ?? 0) > 0
            || ($coverage['unreported'] ?? 0) > 0;
        $incomplete = count($selected) === 0 || $coverageHasGaps
            || $counts['REPORTED_NOT_USABLE'] > 0 || $counts['SELECTION_CAP'] > 0;
        $integrity = $incomplete ? 'INSUFFICIENT_BASIS' : 'COMPLETE_WITHIN_INPUT';
        $lost = false;
        foreach ($rows as $row) {
            if ($row['reported_disposition'] === 'MISSING_BYTES'
                && $row['known_at'] <= $cut) {
                $lost = true;
                break;
            }
        }
        $data = $rows === [] ? 'NO_INPUT_DATA'
            : ($lost ? 'MISSING_BYTES_REPORTED'
            : ($counts['NOT_YET_KNOWN'] > 0 ? 'LATE_KNOWN_INPUT_EXCLUDED'
            : ($incomplete ? 'INCOMPLETE_SUPPLIED_INPUT' : 'BOUNDED_METADATA_AVAILABLE')));
        $fingerprint = [
            'scope'=>$input['scope'], 'period'=>$input['period'],
            'baseline_period'=>$input['baseline_period'],
            'baseline_null_reason'=>$input['baseline_null_reason'],
            'knowledge_cutoff_utc'=>$cut, 'rows'=>$rows,
            'max_references'=>$input['max_references'], 'coverage'=>$coverage,
        ];
        $manifest = [
            'schema_version'=>self::VERSION,
            'candidate_kind'=>'HISTORICAL_BASIS_METADATA_ONLY',
            'status'=>$incomplete ? 'INSUFFICIENT_BASIS' : 'OFFLINE_NOT_ADMITTED',
            'scope'=>$input['scope'],
            'period'=>$window,
            'baseline_period'=>$input['baseline_period'],
            'baseline_null_reason'=>$input['baseline_null_reason'],
            'calendar'=>self::CALENDAR,
            'window_clock'=>'OBSERVED_AT',
            'event_clock_authoritative'=>false,
            'knowledge_cutoff_utc'=>$cut,
            'review_at_utc'=>$request['review_at_utc'],
            'selection_policy'=>self::SELECTION_POLICY,
            'source_input_digest'=>Digest::canonicalDigest($fingerprint),
            'previous_basis_digest'=>$request['previous_basis_digest'],
            'input_count'=>count($rows),
            'selected_count'=>count($selected),
            'selected_references'=>$selected,
            'excluded_references'=>$excluded,
            'excluded_counts_by_reason'=>$counts,
            'eligible_contrary_count'=>count(array_filter(
                $eligible, static fn(array $r): bool => $r['semantic_polarity']==='CONTRARY')),
            'selected_contrary_count'=>$selectedContrary,
            'excluded_contrary_count'=>$excludedContrary,
            'data_disposition'=>$data,
            'selection_integrity'=>$integrity,
            'coverage'=>$coverage,
            'coverage_owner_verified'=>false,
            'rights_disposition'=>'NOT_ASSESSED',
            'historical_reuse_admitted'=>false,
            'model_context_authorized'=>false,
            'provider_disclosure_authorized'=>false,
            'customer_release_authorized'=>false,
            'native_persistence_authorized'=>false,
            'origin_independence_verified'=>false,
        ];
        $manifest['basis_digest']=Digest::canonicalDigest($manifest);
        return Outcome::ok($manifest);
    }

    /** Integrity/shape only; a caller can still forge self-hashed metadata. */
    public static function verify(array $candidate): Outcome
    {
        if (!self::keys($candidate, self::OUTPUT_KEYS)
            || $candidate['schema_version'] !== self::VERSION
            || $candidate['candidate_kind'] !== 'HISTORICAL_BASIS_METADATA_ONLY'
            || $candidate['calendar'] !== self::CALENDAR
            || $candidate['window_clock'] !== 'OBSERVED_AT'
            || $candidate['event_clock_authoritative'] !== false
            || $candidate['selection_policy'] !== self::SELECTION_POLICY
            || $candidate['coverage_owner_verified'] !== false
            || $candidate['rights_disposition'] !== 'NOT_ASSESSED'
            || $candidate['historical_reuse_admitted'] !== false
            || $candidate['model_context_authorized'] !== false
            || $candidate['provider_disclosure_authorized'] !== false
            || $candidate['customer_release_authorized'] !== false
            || $candidate['native_persistence_authorized'] !== false
            || $candidate['origin_independence_verified'] !== false
            || !self::hex($candidate['basis_digest'])
            || !self::hex($candidate['source_input_digest'])
            || ($candidate['previous_basis_digest'] !== null
                && !self::hex($candidate['previous_basis_digest']))
            || !HistoricalEvidenceSelector::utc($candidate['knowledge_cutoff_utc'])
            || !HistoricalEvidenceSelector::utc($candidate['review_at_utc'])
            || $candidate['review_at_utc'] < $candidate['knowledge_cutoff_utc']
            || !self::keys($candidate['scope'], ['tenant_id','workspace_id','competitor_id','question_id'])
            || !self::period($candidate['period'])
            || !is_array($candidate['selected_references'])
            || !array_is_list($candidate['selected_references'])
            || !is_array($candidate['excluded_references'])
            || !array_is_list($candidate['excluded_references'])
            || !self::keys($candidate['excluded_counts_by_reason'], array_keys(self::COUNTS))
            || !is_int($candidate['selected_count'])
            || $candidate['selected_count'] !== count($candidate['selected_references'])
            || $candidate['selected_count'] > HistoricalEvidenceSelector::MAX_REFERENCES
            || !is_int($candidate['input_count'])
            || $candidate['input_count'] !== $candidate['selected_count'] + count($candidate['excluded_references'])
            || !in_array($candidate['selection_integrity'], ['INSUFFICIENT_BASIS','COMPLETE_WITHIN_INPUT'], true)
            || $candidate['status'] !== ($candidate['selection_integrity'] === 'INSUFFICIENT_BASIS'
                ? 'INSUFFICIENT_BASIS' : 'OFFLINE_NOT_ADMITTED')) {
            return Outcome::refuse('HS01_BASIS_CONTRACT_INVALID');
        }
        foreach ($candidate['scope'] as $id) {
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $id)!==1) {
                return Outcome::refuse('HS01_BASIS_SCOPE_INVALID');
            }
        }
        $seen = [];
        $contrary = 0;
        foreach ($candidate['selected_references'] as $row) {
            if (!is_array($row) || !self::keys($row, self::REFERENCE_KEYS)
                || !is_string($row['ref_id']) || isset($seen[$row['ref_id']])
                || !self::hex($row['payload_sha256'])
                || !HistoricalEvidenceSelector::utc($row['observed_at'])
                || !HistoricalEvidenceSelector::utc($row['known_at'])
                || $row['observed_at'] < $candidate['period']['start_inclusive_utc']
                || $row['observed_at'] >= $candidate['period']['end_exclusive_utc']
                || $row['known_at'] > $candidate['knowledge_cutoff_utc']
                || $row['rights_disposition'] !== 'NOT_ASSESSED') {
                return Outcome::refuse('HS01_BASIS_REFERENCE_INVALID');
            }
            $seen[$row['ref_id']] = true;
            if ($row['semantic_polarity'] === 'CONTRARY') { $contrary++; }
        }
        $counts = self::COUNTS;
        foreach ($candidate['excluded_references'] as $row) {
            if (!is_array($row) || !self::keys($row, self::EXCLUDED_KEYS)
                || !isset($counts[$row['reason']]) || !self::hex($row['reference_input_digest'])
                || ($row['reason'] === 'NOT_YET_KNOWN'
                    ? ($row['ref_id'] !== null || $row['known_at_utc'] !== null
                        || $row['semantic_polarity'] !== 'UNKNOWN_AS_OF_CUTOFF')
                    : (!is_string($row['ref_id']) || isset($seen[$row['ref_id']])
                        || !HistoricalEvidenceSelector::utc($row['known_at_utc'])))) {
                return Outcome::refuse('HS01_BASIS_EXCLUSION_INVALID');
            }
            if ($row['ref_id'] !== null) { $seen[$row['ref_id']] = true; }
            $counts[$row['reason']]++;
        }
        if ($counts !== $candidate['excluded_counts_by_reason']
            || $contrary !== $candidate['selected_contrary_count']
            || !is_int($candidate['excluded_contrary_count'])
            || !is_int($candidate['eligible_contrary_count'])
            || $candidate['selected_contrary_count'] > $candidate['eligible_contrary_count']
            || $candidate['excluded_contrary_count'] < 0) {
            return Outcome::refuse('HS01_BASIS_COUNT_INVALID');
        }
        try {
            $digest=$candidate['basis_digest']; unset($candidate['basis_digest']);
            if (!hash_equals($digest, Digest::canonicalDigest($candidate))) {
                return Outcome::refuse('HS01_BASIS_DIGEST_MISMATCH');
            }
        } catch (\Throwable) {
            return Outcome::refuse('HS01_BASIS_ENCODING_INVALID');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_DIGEST_ONLY','historical_reuse_admitted'=>false]);
    }

    /** Metadata-only operator summary: no source IDs, claims, future evidence or raw bodies. */
    public static function preview(array $manifest): Outcome
    {
        if (!self::verify($manifest)->isOk()) {
            return Outcome::refuse('HS01_PREVIEW_MANIFEST_INVALID');
        }
        return Outcome::ok([
            'version'=>self::VERSION, 'basis_digest'=>$manifest['basis_digest'],
            'period'=>$manifest['period'], 'knowledge_cutoff_utc'=>$manifest['knowledge_cutoff_utc'],
            'review_at_utc'=>$manifest['review_at_utc'],
            'input_count'=>$manifest['input_count'],
            'selected_count'=>$manifest['selected_count'],
            'selected_contrary_count'=>$manifest['selected_contrary_count'],
            'excluded_counts_by_reason'=>$manifest['excluded_counts_by_reason'],
            'coverage_owner_verified'=>false,
            'selection_integrity'=>$manifest['selection_integrity'],
            'data_disposition'=>$manifest['data_disposition'],
            'authority'=>'OFFLINE_METADATA_NOT_U03_ADMITTED',
            'source_bytes_or_model_dispatch_available'=>false,
            'customer_output_authorized'=>false,
        ]);
    }

    private static function project(array $row, string $cut): array
    {
        $analysisKnown = $row['analyzed_at'] !== null && $row['analyzed_at'] <= $cut;
        return [
            'ref_id'=>$row['ref_id'], 'source_ref_id'=>$row['source_ref_id'],
            'evidence_id'=>$row['evidence_id'], 'snapshot_id'=>$row['snapshot_id'],
            'assessment_id'=>$analysisKnown ? $row['assessment_id'] : null,
            'payload_sha256'=>$row['payload_sha256'], 'event_at'=>$row['event_at'],
            'observed_at'=>$row['observed_at'], 'recorded_at'=>$row['recorded_at'],
            'known_at'=>$row['known_at'],
            'analyzed_at'=>$analysisKnown ? $row['analyzed_at'] : null,
            'analysis_as_of_cutoff'=>$analysisKnown ? 'AVAILABLE' : 'NOT_KNOWN',
            'semantic_polarity'=>$row['semantic_polarity'],
            'origin_group'=>$row['origin_group'], 'provenance'=>$row['provenance'],
            'rights_disposition'=>'NOT_ASSESSED',
        ];
    }

    private static function keys(mixed $map, array $expected): bool
    {
        if (!is_array($map)) return false;
        $keys=array_keys($map); sort($keys,SORT_STRING); sort($expected,SORT_STRING);
        return $keys===$expected;
    }

    private static function hex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX,$value)===1;
    }

    private static function period(mixed $period): bool
    {
        return self::keys($period,['start_inclusive_utc','end_exclusive_utc'])
            && HistoricalEvidenceSelector::utc($period['start_inclusive_utc'])
            && HistoricalEvidenceSelector::utc($period['end_exclusive_utc'])
            && $period['start_inclusive_utc'] < $period['end_exclusive_utc'];
    }
}
