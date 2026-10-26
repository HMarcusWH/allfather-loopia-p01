<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-05A pure metadata-only temporal selector. No M02, M05, SQL, payload read or model context.
 * Every input field is caller-supplied, NOT a claim that rights or provenance were verified.
 */
final class HistoricalEvidenceSelector
{
    public const VERSION = 'li05.offline-selector.v1';
    public const MAX_INPUT_ROWS = 256;
    public const MAX_REFERENCES = 32;
    public const MAX_SERIALIZED_INPUT_BYTES = 524288;
    public const FIELDS = [
        'ref_id', 'tenant_id', 'workspace_id', 'competitor_id', 'question_id',
        'source_ref_id', 'evidence_id', 'snapshot_id', 'assessment_id', 'payload_sha256',
        'event_at', 'observed_at', 'recorded_at', 'known_at', 'analyzed_at',
        'semantic_polarity', 'origin_group', 'reported_disposition', 'provenance',
    ];
    private const INPUT_FIELDS = [
        'scope', 'period', 'baseline_period', 'baseline_null_reason',
        'knowledge_cutoff_utc', 'rows', 'max_references', 'coverage',
    ];
    private const OMISSIONS = [
        'BEFORE_WINDOW' => 0,
        'AFTER_WINDOW' => 0,
        'NOT_YET_KNOWN' => 0,
        'REPORTED_NOT_USABLE' => 0,
        'SELECTION_CAP' => 0,
    ];

    /** @param array<string,mixed> $input */
    public static function select(array $input): Outcome
    {
        if (!self::exactKeys($input, self::INPUT_FIELDS)
            || !is_array($input['scope']) || !self::exactKeys($input['scope'], [
                'tenant_id', 'workspace_id', 'competitor_id', 'question_id',
            ])) {
            return Outcome::refuse('LI05_SELECTOR_CONTRACT_INVALID');
        }
        foreach ($input['scope'] as $id) {
            if (!self::id($id)) {
                return Outcome::refuse('LI05_SELECTOR_SCOPE_INVALID');
            }
        }
        $scope = $input['scope'];
        if (!self::period($input['period']) || !self::utc($input['knowledge_cutoff_utc'])) {
            return Outcome::refuse('LI05_SELECTOR_TIME_INVALID');
        }
        $period = $input['period'];
        $baseline = $input['baseline_period'];
        $nullReason = $input['baseline_null_reason'];
        if ($baseline === null) {
            if (!in_array($nullReason, ['NO_BASELINE_AVAILABLE', 'NOT_REQUESTED'], true)) {
                return Outcome::refuse('LI05_BASELINE_CONTRACT_INVALID');
            }
        } elseif (!self::period($baseline) || $nullReason !== null
            || strcmp($baseline['end_exclusive_utc'], $period['start_inclusive_utc']) > 0) {
            return Outcome::refuse('LI05_BASELINE_CONTRACT_INVALID');
        }
        if (!is_int($input['max_references']) || $input['max_references'] < 1
            || $input['max_references'] > self::MAX_REFERENCES
            || !is_array($input['rows']) || !array_is_list($input['rows'])
            || count($input['rows']) > self::MAX_INPUT_ROWS) {
            return Outcome::refuse('LI05_SELECTOR_BOUNDS_INVALID');
        }
        $coverage = self::coverage($input['coverage']);
        if ($coverage === null) {
            return Outcome::refuse('LI05_COVERAGE_CONTRACT_INVALID');
        }
        try {
            $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Outcome::refuse('LI05_SELECTOR_ENCODING_INVALID');
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_INPUT_BYTES) {
            return Outcome::refuse('LI05_SELECTOR_BOUNDS_INVALID');
        }
        $rows = [];
        $ids = [];
        $evidenceIds = [];
        $snapshotIds = [];
        foreach ($input['rows'] as $row) {
            if (!is_array($row) || !self::exactKeys($row, self::FIELDS)) {
                return Outcome::refuse('LI05_ROW_CONTRACT_INVALID');
            }
            foreach (['tenant_id', 'workspace_id', 'competitor_id', 'question_id'] as $key) {
                if (!self::id($row[$key]) || $row[$key] !== $scope[$key]) {
                    // No foreign-scope detail or exclusion counts are emitted on refusal.
                    return Outcome::refuse('LI05_ROW_SCOPE_MISMATCH');
                }
            }
            foreach (['ref_id', 'source_ref_id', 'evidence_id', 'snapshot_id'] as $key) {
                if (!self::id($row[$key])) {
                    return Outcome::refuse('LI05_ROW_IDENTITY_INVALID');
                }
            }
            if (($row['assessment_id'] !== null && !self::id($row['assessment_id']))
                || (($row['assessment_id'] === null) !== ($row['analyzed_at'] === null))) {
                return Outcome::refuse('LI05_ROW_IDENTITY_INVALID');
            }
            if (isset($ids[$row['ref_id']]) || isset($evidenceIds[$row['evidence_id']])
                || isset($snapshotIds[$row['snapshot_id']])) {
                return Outcome::refuse('LI05_ROW_DUPLICATE_IDENTITY');
            }
            $ids[$row['ref_id']] = true;
            $evidenceIds[$row['evidence_id']] = true;
            $snapshotIds[$row['snapshot_id']] = true;
            if (!is_string($row['payload_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $row['payload_sha256']) !== 1
                || !self::utc($row['observed_at']) || !self::utc($row['recorded_at'])
                || !self::utc($row['known_at'])
                || ($row['event_at'] !== null && !self::utc($row['event_at']))
                || ($row['analyzed_at'] !== null && !self::utc($row['analyzed_at']))) {
                return Outcome::refuse('LI05_ROW_CLOCK_OR_DIGEST_INVALID');
            }
            if ($row['observed_at'] > $row['recorded_at']
                || $row['recorded_at'] > $row['known_at']
                || ($row['analyzed_at'] !== null && $row['analyzed_at'] < $row['known_at'])) {
                return Outcome::refuse('LI05_ROW_CLOCK_ORDER_INVALID');
            }
            if (!in_array($row['semantic_polarity'], ['SUPPORT', 'CONTRARY', 'UNKNOWN'], true)
                || !in_array($row['origin_group'], ['EXACT_BYTE_EQUIVALENCE_ONLY', 'UNKNOWN'], true)
                || !in_array($row['provenance'], ['M05_CAPTURE_METADATA', 'OWNER_SUPPLIED_METADATA'], true)) {
                return Outcome::refuse('LI05_ROW_VOCABULARY_INVALID');
            }
            $reported = HistoricalEvidenceEligibility::describe([
                'reported_disposition' => $row['reported_disposition'],
            ]);
            if (!$reported->isOk()) {
                return $reported;
            }
            $rows[] = $row;
        }
        // A caller's row order cannot alter an as-of history digest or capped membership.
        usort($rows, static fn (array $a, array $b): int =>
            [$a['observed_at'], $a['ref_id']] <=> [$b['observed_at'], $b['ref_id']]);
        $omitted = self::OMISSIONS;
        $pool = [];
        $cutoff = $input['knowledge_cutoff_utc'];
        $missedContrary = false;
        foreach ($rows as $row) {
            if ($row['observed_at'] < $period['start_inclusive_utc']) {
                $omitted['BEFORE_WINDOW']++;
                continue;
            }
            if ($row['observed_at'] >= $period['end_exclusive_utc']) {
                $omitted['AFTER_WINDOW']++;
                continue;
            }
            if ($row['known_at'] > $cutoff) {
                $omitted['NOT_YET_KNOWN']++;
                continue;
            }
            if ($row['reported_disposition'] !== 'UNASSESSED') {
                $omitted['REPORTED_NOT_USABLE']++;
                $missedContrary = $missedContrary || $row['semantic_polarity'] === 'CONTRARY';
                continue;
            }
            $pool[] = $row;
        }
        $selected = [];
        foreach ($pool as $index => $row) {
            if ($index >= $input['max_references']) {
                $omitted['SELECTION_CAP']++;
                $missedContrary = $missedContrary || $row['semantic_polarity'] === 'CONTRARY';
                continue;
            }
            $analysisKnown = $row['analyzed_at'] !== null && $row['analyzed_at'] <= $cutoff;
            $selected[] = [
                'ref_id' => $row['ref_id'],
                'source_ref_id' => $row['source_ref_id'],
                'evidence_id' => $row['evidence_id'],
                'snapshot_id' => $row['snapshot_id'],
                'assessment_id' => $analysisKnown ? $row['assessment_id'] : null,
                'payload_sha256' => $row['payload_sha256'],
                'event_at' => $row['event_at'],
                'observed_at' => $row['observed_at'],
                'recorded_at' => $row['recorded_at'],
                'known_at' => $row['known_at'],
                'analyzed_at' => $analysisKnown ? $row['analyzed_at'] : null,
                'analysis_as_of_cutoff' => $analysisKnown ? 'AVAILABLE' : 'NOT_KNOWN',
                'semantic_polarity' => $row['semantic_polarity'],
                'origin_group' => $row['origin_group'],
                'provenance' => $row['provenance'],
                'rights_disposition' => 'NOT_ASSESSED',
            ];
        }
        $insufficient = $selected === [] || $coverage['state'] !== 'KNOWN'
            || $missedContrary || $omitted['REPORTED_NOT_USABLE'] > 0
            || $omitted['SELECTION_CAP'] > 0;
        $integrity = $insufficient ? 'INSUFFICIENT_BASIS' : 'COMPLETE_WITHIN_INPUT';
        return Outcome::ok([
            'schema_version' => HistoryReviewBasis::SCHEMA_VERSION,
            'status' => $insufficient ? 'INSUFFICIENT_BASIS' : 'OFFLINE_NOT_ADMITTED',
            'scope' => $scope,
            'period' => $period,
            'baseline_period' => $baseline,
            'baseline_null_reason' => $nullReason,
            'knowledge_cutoff_utc' => $cutoff,
            'selector_version' => self::VERSION,
            'eligibility_version' => HistoricalEvidenceEligibility::VERSION,
            'references' => $selected,
            'input_count' => count($rows),
            'selected_count' => count($selected),
            'excluded_counts_by_reason' => $omitted,
            'coverage' => $coverage,
            'contra_evidence_not_included' => $missedContrary,
            'selection_integrity' => $integrity,
            'rights_disposition' => 'NOT_ASSESSED',
            'historical_reuse_admitted' => false,
        ]);
    }

    /** @param array<array-key,mixed> $map @param list<string> $required */
    private static function exactKeys(array $map, array $required): bool
    {
        $keys = array_keys($map);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);
        return $keys === $required;
    }

    private static function id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $id) === 1;
    }

    public static function utc(mixed $timestamp): bool
    {
        if (!is_string($timestamp)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/D', $timestamp, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59;
    }

    private static function period(mixed $period): bool
    {
        return is_array($period) && self::exactKeys($period, ['start_inclusive_utc', 'end_exclusive_utc'])
            && self::utc($period['start_inclusive_utc'])
            && self::utc($period['end_exclusive_utc'])
            && $period['start_inclusive_utc'] < $period['end_exclusive_utc'];
    }

    /** @return ?array<string,mixed> */
    private static function coverage(mixed $coverage): ?array
    {
        if (!is_array($coverage) || !is_string($coverage['state'] ?? null)) {
            return null;
        }
        if ($coverage['state'] === 'UNKNOWN') {
            return self::exactKeys($coverage, ['state'])
                ? ['state' => 'UNKNOWN', 'plan_version_id' => null, 'planned_slots' => null,
                   'captured' => null, 'unchanged' => null, 'failed' => null,
                   'skipped' => null, 'unreported' => null,
                   'basis' => 'CALLER_SUPPLIED_UNVERIFIED', 'independent_event_count' => null]
                : null;
        }
        if ($coverage['state'] !== 'KNOWN' || !self::exactKeys($coverage, [
            'state', 'plan_version_id', 'planned_slots', 'captured', 'unchanged',
            'failed', 'skipped', 'unreported',
        ]) || !self::id($coverage['plan_version_id'])) {
            return null;
        }
        foreach (['planned_slots', 'captured', 'unchanged', 'failed', 'skipped', 'unreported'] as $key) {
            if (!is_int($coverage[$key]) || $coverage[$key] < 0 || $coverage[$key] > 10000) {
                return null;
            }
        }
        if ($coverage['unchanged'] > $coverage['captured']
            || $coverage['captured'] + $coverage['failed'] + $coverage['skipped']
            + $coverage['unreported'] !== $coverage['planned_slots']) {
            return null;
        }
        return [...$coverage, 'basis' => 'CALLER_SUPPLIED_UNVERIFIED', 'independent_event_count' => null];
    }
}
