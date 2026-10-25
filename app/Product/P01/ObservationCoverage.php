<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-04 pure scheduler-slot coverage arithmetic.
 * No absent capture can be classified as NO_CHANGE. The plan count MUST be
 * supplied from a separately governed schedule; this method authorizes none.
 */
final class ObservationCoverage
{
    public const VERSION = 'li04.observation-coverage.pure.v1';
    public const MAX_PLANNED_SLOTS = 10000;
    private const OUTCOMES = [
        'CAPTURED_BASELINE', 'CAPTURED_UNCHANGED', 'CAPTURED_CHANGED',
        'REFUSED', 'FAILED', 'PENDING',
    ];

    /**
     * @param list<array<string,mixed>> $occurrences
     */
    public static function summarize(string $planVersionId, int $plannedSlots, array $occurrences): Outcome
    {
        if ($planVersionId === '' || strlen($planVersionId) > 128
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $planVersionId) !== 1
            || $plannedSlots < 0 || $plannedSlots > self::MAX_PLANNED_SLOTS) {
            return Outcome::refuse('LI04_COVERAGE_PLAN_INVALID');
        }
        if (!array_is_list($occurrences) || count($occurrences) > $plannedSlots) {
            return Outcome::refuse('LI04_COVERAGE_OCCURRENCES_INVALID');
        }
        $slots = [];
        foreach ($occurrences as $row) {
            if (!is_array($row)
                || array_diff(['slot_index','outcome','skipped_slots'], array_keys($row)) !== []
                || array_diff(array_keys($row), ['slot_index','outcome','skipped_slots']) !== []
                || !is_int($row['slot_index'])
                || $row['slot_index'] < 0 || $row['slot_index'] >= $plannedSlots
                || !is_int($row['skipped_slots']) || $row['skipped_slots'] < 0
                || !in_array($row['outcome'], self::OUTCOMES, true)) {
                return Outcome::refuse('LI04_COVERAGE_SLOT_INVALID');
            }
            if (isset($slots[$row['slot_index']])) {
                return Outcome::refuse('LI04_COVERAGE_DUPLICATE_SLOT');
            }
            $slots[$row['slot_index']] = $row;
        }
        ksort($slots, SORT_NUMERIC);

        $counts = array_fill_keys(self::OUTCOMES, 0);
        $skipped = 0;
        $unknownBetween = 0;
        $last = -1;
        foreach ($slots as $index => $row) {
            $gap = $index - $last - 1;
            if ($row['skipped_slots'] > $gap) {
                return Outcome::refuse('LI04_COVERAGE_SKIPPED_SLOTS_OVERLAP');
            }
            $skipped += $row['skipped_slots'];
            $unknownBetween += $gap - $row['skipped_slots'];
            $counts[$row['outcome']]++;
            $last = $index;
        }
        $unreported = $unknownBetween + ($plannedSlots - 1 - $last);
        $captured = $counts['CAPTURED_BASELINE'] + $counts['CAPTURED_UNCHANGED'] + $counts['CAPTURED_CHANGED'];
        $missed = $skipped + $unreported + $counts['REFUSED'] + $counts['FAILED'] + $counts['PENDING'];
        $rate = static fn (int $numerator, int $denominator): ?array =>
            $denominator > 0 ? ['numerator'=>$numerator,'denominator'=>$denominator] : null;
        return Outcome::ok([
            'schema_version' => self::VERSION,
            'plan_version_id' => $planVersionId,
            'planned_slots' => $plannedSlots,
            'accounted_occurrences' => count($occurrences),
            'captured_slots' => $captured,
            'baseline_captures' => $counts['CAPTURED_BASELINE'],
            'structurally_unchanged_captures' => $counts['CAPTURED_UNCHANGED'],
            'structurally_changed_captures' => $counts['CAPTURED_CHANGED'],
            'refused_slots' => $counts['REFUSED'],
            'failed_slots' => $counts['FAILED'],
            'pending_slots' => $counts['PENDING'],
            'explicit_skipped_slots' => $skipped,
            'unreported_slots' => $unreported,
            'not_successfully_observed_slots' => $missed,
            'successful_capture_rate' => $rate($captured, $plannedSlots),
            'structural_change_rate_within_captures' => $rate($counts['CAPTURED_CHANGED'], $captured),
            'distinct_real_world_event_count' => null,
            'corroboration_count' => null,
            'business_materiality' => 'NOT_ASSESSED',
            'source_rights' => 'NOT_CHECKED_BY_PURE_COVERAGE',
            'read_only' => true,
            'capture_or_analysis_authorized' => false,
        ]);
    }
}
