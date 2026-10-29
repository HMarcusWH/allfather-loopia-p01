<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Workflow\ScheduleHistoryCoverage;

/**
 * OB-03 CoverageReceipt.v1 binds a *current native owner* schedule inspection
 * when available. Without an admitted ScheduleHistoryCoverage owner the only
 * available receipt is explicitly SYNTHETIC/UNKNOWN; a planned slot must
 * never become an observed NO_CHANGE merely because it is missing.
 *
 * None of these receipts grants historical source reuse (U03), a new
 * scheduled task, a model call or customer output.
 */
final class ObservationCoverageReceipt
{
    public const VERSION = 'ob03.coverage-receipt.v1';
    private const HEX = '/^[a-f0-9]{64}$/D';

    /**
     * Truly current owner schedule path: re-checks schedule version,
     * service identity, occurrence ledger, RunJournal and private M05 capture
     * inside ScheduleHistoryCoverage::inspect(). Not an untrusted receipt
     * supplied directly by a caller.
     *
     * @param array<string,mixed> $actor Exact M02 principal scope
     * @param array<string,string> $period Half-open UTC window
     */
    public static function fromCurrentOwner(
        ScheduleHistoryCoverage $owner,
        array $actor,
        string $competitorId,
        array $period,
        string $knowledgeCutoffUtc,
        string $scheduleVersionId,
    ): Outcome {
        $checked = $owner->inspect(
            $actor, $competitorId, $period, $knowledgeCutoffUtc, $scheduleVersionId,
        );
        if (!$checked->isOk()) {
            return $checked;
        }
        $native = $checked->value();
        if (($native['version'] ?? null) !== ScheduleHistoryCoverage::VERSION
            || ($native['scope']['tenant_id'] ?? null) !== ($actor['tenant_id'] ?? null)
            || ($native['scope']['workspace_id'] ?? null) !== ($actor['workspace_id'] ?? null)
            || ($native['scope']['competitor_id'] ?? null) !== $competitorId
            || ($native['schedule_version_id'] ?? null) !== $scheduleVersionId
            || ($native['period'] ?? null) !== $period
            || ($native['knowledge_cutoff_utc'] ?? null) !== $knowledgeCutoffUtc
            || ($native['owner_schedule_current_verified'] ?? null) !== true
            || ($native['U03_historical_use_admitted'] ?? null) !== false
            || ($native['model_call_authorized'] ?? null) !== false
            || ($native['scheduler_action_authorized'] ?? null) !== false
            || ($native['customer_output_authorized'] ?? null) !== false
            || !is_string($native['receipt_digest'] ?? null)
            || preg_match(self::HEX, $native['receipt_digest']) !== 1) {
            return Outcome::refuse('OB03_OWNER_COVERAGE_CONTRACT_INVALID');
        }
        $copy = $native;
        unset($copy['receipt_digest']);
        if (!hash_equals(Digest::canonicalDigest($copy), $native['receipt_digest'])) {
            return Outcome::refuse('OB03_OWNER_COVERAGE_DIGEST_INVALID');
        }
        if (!is_array($native['summary'] ?? null)
            || ($native['summary']['plan_version_id'] ?? null) !== $scheduleVersionId
            || ($native['summary']['planned_slots'] ?? null) !== $native['planned_slots']) {
            return Outcome::refuse('OB03_OWNER_COVERAGE_MISMATCH');
        }
        return self::compose(
            $native['summary'],
            'CURRENT_OWNER_SCHEDULE_OCCURRENCE_RUNJOURNAL_VERIFIED',
            [
                'tenant_id'=>$actor['tenant_id'],
                'workspace_id'=>$actor['workspace_id'],
                'competitor_id'=>$competitorId,
                'monitored_source_id'=>$native['source_id'],
                'schedule_version_id'=>$scheduleVersionId,
                'schedule_digest'=>$native['schedule_digest'],
                'period'=>$period,
                'knowledge_cutoff_utc'=>$knowledgeCutoffUtc,
                'native_receipt_digest'=>$native['receipt_digest'],
            ],
            ($native['status'] ?? null) === 'COMPLETE_OWNER_SLOT_COVERAGE',
        );
    }

    /**
     * Fixture-only slot plan, explicitly NOT an owner schedule/capture proof.
     * Useful to prove accounting/refusal behavior without inventing occurrence
     * receipts, M02 actor, real source rights or native schedule identity.
     *
     * @param list<array<string,mixed>> $occurrences
     */
    public static function synthetic(
        string $planVersionId, int $plannedSlots, array $occurrences,
    ): Outcome {
        $summary = ObservationCoverage::summarize($planVersionId, $plannedSlots, $occurrences);
        if (!$summary->isOk()) {
            return $summary;
        }
        return self::compose($summary->value(), 'SYNTHETIC_PLAN_NOT_OWNER_ADMITTED',
            [
                'synthetic_plan_version_id'=>$planVersionId,
                'synthetic_occurrence_digest'=>Digest::canonicalDigest($occurrences),
            ], false);
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $lineage */
    private static function compose(array $summary, string $authority, array $lineage, bool $ownerComplete): Outcome
    {
        foreach ([
            'planned_slots','accounted_occurrences','captured_slots',
            'baseline_captures','structurally_unchanged_captures','structurally_changed_captures',
            'refused_slots','failed_slots','pending_slots','explicit_skipped_slots',
            'unreported_slots','not_successfully_observed_slots',
        ] as $key) {
            if (!is_int($summary[$key] ?? null) || $summary[$key] < 0) {
                return Outcome::refuse('OB03_COVERAGE_COUNT_INVALID');
            }
        }
        if ($summary['captured_slots'] !== $summary['baseline_captures']
                + $summary['structurally_unchanged_captures']
                + $summary['structurally_changed_captures']
            || $summary['planned_slots'] !== $summary['captured_slots']
                + $summary['refused_slots'] + $summary['failed_slots']
                + $summary['pending_slots'] + $summary['explicit_skipped_slots']
                + $summary['unreported_slots']
            || $summary['not_successfully_observed_slots'] !==
                $summary['planned_slots'] - $summary['captured_slots']) {
            return Outcome::refuse('OB03_COVERAGE_DENOMINATOR_MISMATCH');
        }
        $gaps = [
            'EXPECTED_NOT_FETCHED_UNKNOWN'=>$summary['unreported_slots'],
            'EXPLICITLY_SKIPPED'=>$summary['explicit_skipped_slots'],
            'BLOCKED_OR_REFUSED'=>$summary['refused_slots'],
            'FAILED_CAPTURE'=>$summary['failed_slots'],
            'PENDING_NOT_OBSERVED'=>$summary['pending_slots'],
        ];
        $hasGap = array_sum($gaps) > 0;
        if ($ownerComplete && ($hasGap || $summary['planned_slots'] < 1)) {
            return Outcome::refuse('OB03_OWNER_FALSE_COMPLETENESS');
        }
        $receipt = [
            'schema_version'=>self::VERSION,
            'kind'=>'SCHEDULED_OBSERVATION_COVERAGE_DERIVED',
            'authority_basis'=>$authority,
            'lineage'=>$lineage,
            'planned_slots'=>$summary['planned_slots'],
            'accounted_occurrences'=>$summary['accounted_occurrences'],
            'captured_slots'=>$summary['captured_slots'],
            'baseline_captures'=>$summary['baseline_captures'],
            'captured_unchanged'=>$summary['structurally_unchanged_captures'],
            'captured_changed'=>$summary['structurally_changed_captures'],
            'gap_counts'=>$gaps,
            'missing_or_unknown_slots'=>array_sum($gaps),
            'coverage_state'=>$authority === 'SYNTHETIC_PLAN_NOT_OWNER_ADMITTED'
                ? 'UNKNOWN_NOT_OWNER_VERIFIED'
                : ($ownerComplete ? 'COMPLETE_OWNER_CAPTURE_COVERAGE' : 'HOLD_GAPPED_OR_INCOMPLETE'),
            'successful_capture_rate'=>$summary['successful_capture_rate'] ?? null,
            'original_capture_status_preserved'=>true,
            'missed_capture_never_no_change'=>true,
            'unique_events_verified'=>false,
            'distinct_real_world_event_count'=>null,
            'independent_corroboration_count'=>null,
            'independent_origin_count'=>null,
            'commercial_materiality'=>'NOT_ASSESSED',
            'u02_origin_adopted'=>false,
            'u03_historical_reuse_admitted'=>false,
            'native_scheduling_or_capture_authorized'=>false,
            'model_dispatch_authorized'=>false,
            'customer_release_authorized'=>false,
            'source_bytes_returned'=>false,
        ];
        $receipt['coverage_digest'] = Digest::canonicalDigest($receipt);
        return Outcome::ok($receipt);
    }

    /** Verify frozen candidate bytes only, NOT original schedule admission. */
    public static function verify(array $candidate): bool
    {
        if (($candidate['schema_version'] ?? null) !== self::VERSION
            || ($candidate['missed_capture_never_no_change'] ?? null) !== true
            || ($candidate['u03_historical_reuse_admitted'] ?? null) !== false
            || ($candidate['customer_release_authorized'] ?? null) !== false
            || !array_key_exists('independent_origin_count', $candidate)
            || $candidate['independent_origin_count'] !== null
            || !is_string($candidate['coverage_digest'] ?? null)
            || preg_match(self::HEX, $candidate['coverage_digest']) !== 1) {
            return false;
        }
        $copy = $candidate;
        unset($copy['coverage_digest']);
        return hash_equals($candidate['coverage_digest'], Digest::canonicalDigest($copy));
    }
}
