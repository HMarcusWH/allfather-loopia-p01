<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * VA-01: machine-inspectable, denominator-explicit DEVELOPMENT-only report.
 *
 * No model quality, independent human PASS, held-out execution, source right,
 * alert, or customer release is asserted. A publicly copied oracle can pass a
 * mechanical comparison; it does NOT establish a model's ability to reason.
 */
final class HistoricalSequenceReport
{
    public const VERSION = 'va01.historical-evaluation-report.v1';
    public const EVALUATION_STATUS = 'INCOMPLETE_UNFROZEN_HELDOUT';
    private const MEASURES = [
        'source_coverage','unique_events','origin_independence',
        'due_review','alert_quality','grounding','usefulness',
    ];

    /**
     * @param list<array<string,mixed>> $records
     * @param array<string,mixed> $freeze from VA-00 loadAndVerify().
     * @return Outcome<array<string,mixed>>
     */
    public static function build(array $records, array $freeze, string $responseDigest): Outcome
    {
        $m = $freeze['manifest'] ?? null;
        $dev = $freeze['development'] ?? null;
        $held = $freeze['heldout'] ?? null;
        if (!is_array($m) || !is_array($dev) || !is_array($held)
            || !is_string($responseDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $responseDigest) !== 1
            || count($records) !== HistoricalSequenceEvaluationRunner::RECORD_COUNT
            || ($m['longitudinal']['heldout_status'] ?? null) !== 'RESERVED_NOT_FROZEN_NOT_RUN'
            || ($held['status'] ?? null) !== 'RESERVED_NOT_FROZEN_NOT_RUN') {
            return Outcome::refuse('VA01_REPORT_INPUT_OR_HELDOUT_INVALID');
        }
        $groups = [];
        $denominators = [];
        $critical = [];
        $failures = [];
        $perBaseline = [];
        $byKey = [];
        foreach ($records as $record) {
            $baseline = $record['baseline_id'] ?? null;
            $caseId = $record['case_id'] ?? null;
            if (!in_array($baseline, HistoricalSequenceEvaluationRunner::BASELINES, true)
                || !is_string($caseId)
                || !in_array($record['family'] ?? null,
                    array_keys(HistoricalSequenceEvaluationRunner::FAMILIES), true)
                || ($record['split'] ?? null) !== 'DEVELOPMENT'
                || !is_bool($record['mechanical_match'] ?? null)
                || !is_array($record['failure_codes'] ?? null)
                || !is_array($record['critical_failure_codes'] ?? null)
                || ($record['customer_release_authorized'] ?? null) !== false
                || ($record['external_effects_performed'] ?? null) !== 0
                || ($record['provider_usage_status'] ?? null) !== 'NOT_CALLED') {
                return Outcome::refuse('VA01_REPORT_RECORD_CONTRACT_INVALID');
            }
            $key = $baseline . "\0" . $caseId;
            if (isset($byKey[$key])) {
                return Outcome::refuse('VA01_REPORT_DUPLICATE_RECORD');
            }
            $byKey[$key] = true;
            if (!isset($perBaseline[$baseline])) {
                $perBaseline[$baseline] = self::emptyCounters();
                $groups[$baseline] = [];
            }
            $count = &$perBaseline[$baseline];
            self::add($count, $record);
            unset($count);
            $family = $record['family'];
            if (!isset($groups[$baseline][$family])) {
                $groups[$baseline][$family] = self::emptyCounters();
                $groups[$baseline][$family]['case_ids'] = [];
            }
            self::add($groups[$baseline][$family], $record);
            $groups[$baseline][$family]['case_ids'][] = $caseId;
            foreach ($record['critical_failure_codes'] as $reason) {
                $critical[$reason] = ($critical[$reason] ?? 0) + 1;
            }
            foreach ($record['failure_codes'] as $reason) {
                $failures[$reason] = ($failures[$reason] ?? 0) + 1;
            }
        }
        foreach (HistoricalSequenceEvaluationRunner::BASELINES as $baseline) {
            if (($perBaseline[$baseline]['all_cases'] ?? 0)
                !== HistoricalSequenceEvaluationRunner::CASE_COUNT) {
                return Outcome::refuse('VA01_REPORT_BASELINE_COVERAGE_INCOMPLETE');
            }
            ksort($groups[$baseline]);
            foreach ($groups[$baseline] as &$g) {
                sort($g['case_ids'], SORT_STRING);
            }
            unset($g);
        }
        ksort($critical);
        ksort($failures);
        $frozenDenominators = array_column($m['longitudinal']['denominator_rules'], null, 'id');
        if (array_keys($frozenDenominators) !== self::MEASURES) {
            return Outcome::refuse('VA01_DENOMINATOR_POLICY_DRIFT');
        }
        foreach (HistoricalSequenceEvaluationRunner::BASELINES as $baseline) {
            $x = $perBaseline[$baseline];
            $denominators[$baseline] = [];
            foreach (self::MEASURES as $measure) {
                $rule = $frozenDenominators[$measure];
                // Never write computed rates into frozen VA-00 source manifest.
                $observed = match ($measure) {
                    'source_coverage' => [
                        'numerator' => $x['eligibility_match_cases'],
                        'denominator' => $x['all_cases'],
                        'interpretation' => 'PUBLIC_DEV_FIXTURE_CASE_EXACTNESS_NOT_REAL_SOURCE_COVERAGE',
                    ],
                    'unique_events' => [
                        'numerator' => $x['event_match_cases'],
                        'denominator' => $x['all_cases'],
                        'interpretation' => 'PUBLIC_DEV_FIXTURE_EVENT_LABEL_MATCH_NOT_INDEPENDENT_FACT',
                    ],
                    'origin_independence' => [
                        'numerator' => null, 'denominator' => null,
                        'interpretation' => 'NO_OWNER_AUTHENTICATED_INDEPENDENCE_ASSERTIONS',
                    ],
                    'due_review' => [
                        'numerator' => null, 'denominator' => null,
                        'interpretation' => 'NO_NATIVE_DUE_SCHEDULE_OR_GOVERNED_MODEL_ATTEMPT',
                    ],
                    'alert_quality' => [
                        'numerator' => null, 'denominator' => null,
                        'interpretation' => 'NO_NOTIFICATIONS_OR_VERIFIED_ATTENTION_LABELS',
                    ],
                    default => [
                        'numerator' => null, 'denominator' => null,
                        'interpretation' => 'INDEPENDENT_HUMAN_ASSESSOR_UNASSIGNED',
                    ],
                };
                $denominator = $observed['denominator'];
                $rate = $denominator === null || $denominator === 0
                    ? null : $observed['numerator'] / $denominator;
                $denominators[$baseline][$measure] = [
                    'id' => $measure,
                    'frozen_denominator_rule' => $rule['denominator'],
                    'frozen_numerator_rule' => $rule['numerator'],
                    'local_fixture_numerator' => $observed['numerator'],
                    'local_fixture_denominator' => $denominator,
                    'local_fixture_rate' => $rate,
                    'authoritative_numerator' => null,
                    'authoritative_denominator' => null,
                    'authoritative_rate' => null,
                    'interpretation' => $observed['interpretation'],
                ];
            }
        }
        $heldoutSummary = [
            'source_status' => 'RESERVED_NOT_FROZEN_NOT_RUN',
            'slot_count' => count($held['slots']),
            'frozen_payload_count' => 0,
            'frozen_oracle_count' => 0,
            'executed_cases' => 0,
            'independently_grounding_scored' => 0,
            'independently_usefulness_scored' => 0,
            'custodian' => null,
            'overall_mechanical_rate' => null,
            'overall_grounding_rate' => null,
            'overall_usefulness_rate' => null,
            'qualification' => self::EVALUATION_STATUS,
        ];
        $report = [
            'schema_version' => self::VERSION,
            'harness_version' => HistoricalSequenceEvaluationRunner::VERSION,
            'scope' => 'OFFLINE_PUBLIC_SYNTHETIC_DEVELOPMENT_ONLY',
            'result_kind' => 'MECHANICAL_FIXTURE_DIAGNOSTIC_NOT_MODEL_EVALUATION',
            'manifest_identity' => [
                'va00_canonical_digest' => Digest::canonicalDigest($m),
                'source_fixture_sha256' =>
                    '52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b',
                'heldout_registry_digest' => Digest::canonicalDigest($held),
                'historical_dev7_evidence_retained' => true,
                'response_fixture_digest' => $responseDigest,
                'frozen_thresholds' => $m['longitudinal']['thresholds'],
                'comparison_baselines' => array_column($m['longitudinal']['baselines'], 'status', 'id'),
            ],
            'total_development_cases' => HistoricalSequenceEvaluationRunner::CASE_COUNT,
            'baseline_count' => count(HistoricalSequenceEvaluationRunner::BASELINES),
            'record_count' => count($records),
            'cases' => $records,
            'baseline_summaries' => $perBaseline,
            'grouped_failure_analysis' => $groups,
            'failure_code_totals' => $failures,
            'critical_failure_code_totals' => $critical,
            'denominator_scorecards' => $denominators,
            'heldout' => $heldoutSummary,
            'independent_human_scores' => [
                'assessor_identity' => null,
                'grounding' => ['pass'=>0,'total'=>0,'rate'=>null,'state'=>'NOT_ASSESSED'],
                'usefulness' => ['pass'=>0,'total'=>0,'rate'=>null,'state'=>'NOT_ASSESSED'],
            ],
            'provider_usage' => [
                'model_attempts' => 0,
                'network_calls' => 0,
                'input_tokens' => null,
                'output_tokens' => null,
                'token_measurement' => 'UNKNOWN_NO_PROVIDER_DISPATCH',
                'estimated_cost' => null,
            ],
            'development_acceptance' => 'NOT_VALIDATED_MODEL_BEHAVIOR',
            'model_quality_status' => self::EVALUATION_STATUS,
            'heldout_owner_custody_accepted' => false,
            'actual_per_diff_runtime_baseline_executed' => false,
            'weekly_native_runtime_baseline_executed' => false,
            'comparison_is_fixture_only' => true,
            'funded_model_execution_authorized' => false,
            'live_source_use_authorized' => false,
            'customer_release_authorized' => false,
            'notification_authorized' => false,
            'schema_migration_performed' => false,
        ];
        $report['report_digest'] = Digest::canonicalDigest($report);
        return Outcome::ok($report);
    }

    /** @return array<string,int> */
    private static function emptyCounters(): array
    {
        return [
            'all_cases'=>0,
            'mechanical_matches'=>0,
            'mechanical_mismatches'=>0,
            'refusals'=>0,
            'abstentions'=>0,
            'missing_responses'=>0,
            'eligible_reference_denominator'=>0,
            'observed_reference_denominator'=>0,
            'never_known_at_cut'=>0,
            'blocked_for_rights'=>0,
            'expected_distinct_events'=>0,
            'expected_origin_group_labels'=>0,
            'eligibility_match_cases'=>0,
            'event_match_cases'=>0,
            'origin_label_match_cases'=>0,
            'critical_failure_instances'=>0,
        ];
    }

    /** @param array<string,int> $c @param array<string,mixed> $r */
    private static function add(array &$c, array $r): void
    {
        $c['all_cases']++;
        if ($r['mechanical_match']) {
            $c['mechanical_matches']++;
        } else {
            $c['mechanical_mismatches']++;
        }
        $c['refusals'] += $r['mechanical_disposition'] === 'REFUSED' ? 1 : 0;
        $c['abstentions'] += $r['mechanical_disposition'] === 'ABSTAINED' ? 1 : 0;
        $c['missing_responses'] += $r['mechanical_disposition'] === 'NOT_RUN' ? 1 : 0;
        $c['eligible_reference_denominator'] += $r['eligible_reference_denominator'];
        $c['observed_reference_denominator'] += $r['evidence_observation_count'];
        $c['never_known_at_cut'] += $r['not_known_at_cut_reference_count'];
        $c['blocked_for_rights'] += $r['blocked_rights_reference_count'];
        $c['expected_distinct_events'] += $r['expected_event_count'];
        $c['expected_origin_group_labels'] += $r['expected_origin_label_count'];
        $c['eligibility_match_cases'] += $r['eligible_reference_match'] ? 1 : 0;
        $c['event_match_cases'] += $r['event_deduplication_match'] ? 1 : 0;
        $c['origin_label_match_cases'] += $r['origin_group_label_match'] ? 1 : 0;
        $c['critical_failure_instances'] += count($r['critical_failure_codes']);
    }
}
