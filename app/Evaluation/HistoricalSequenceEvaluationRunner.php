<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * VA-01: offline historical DEVELOPMENT sequence evaluator.
 *
 * Accepts supplied *public synthetic fixture responses*, NOT a model/provider
 * output. It runs every named case for both VA-00 frozen comparison baselines,
 * including refused, absent and abstained responses. Neither matching a public
 * oracle nor a clean static schema means a real model has passed evaluation.
 *
 * No DB, tool/provider call, model selection, credentials, network, customer
 * output, or held-out unblinding. The six heldout slots have NO PAYLOAD.
 */
final class HistoricalSequenceEvaluationRunner
{
    public const VERSION = 'va01.historical-evaluation-harness.v1';
    public const FIXTURE_VERSION = 'va01.development-responses.v1';
    public const FIXTURE_PATH = 'tests/fixtures/longitudinal/development/va01_sequence_responses_v1.json';
    public const BASELINES = ['EXISTING_PER_DIFF_V1', 'FIXED_WEEKLY_UTC_CANDIDATE_V1'];
    public const CASE_COUNT = 15;
    public const RECORD_COUNT = 30;
    public const MAX_OBSERVATIONS_PER_CASE = 32;
    private const SOURCE_SHA256 = '52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b';
    private const FIXTURE_KEYS = [
        'baseline_ids','case_count','classification','response_count','responses',
        'schema_version','scoring_authority','source_development_fixture_sha256','source_provenance',
    ];
    private const RESPONSE_KEYS = [
        'baseline_id','case_id','claimed_attention_authority','claimed_business_materiality',
        'disposition','provenance_type','refusal_code','reported_distinct_event_ids',
        'reported_eligible_observation_ids','reported_origin_group_ids','source_data_kind',
    ];
    public const FAMILIES = [
        'lineage_rights_time' => [
            'KNOWN_AT_HORIZON','RIGHTS_QUARANTINE','HISTORICAL_USE_DENIAL','TEMPORAL_MISSINGNESS',
        ],
        'quiet_noise_coverage' => [
            'NONMATERIAL_NAVIGATION','MISSING_COVERAGE','TINY_MATERIAL_CANDIDATE',
        ],
        'origin_dedup' => [
            'CORRELATED_COPIES','ORIGIN_UNCERTAINTY','RECURRENT_EVENT',
        ],
        'contradiction_injection' => [
            'CONTRADICTION','UNTRUSTED_HISTORICAL_TEXT',
        ],
        'sequence_backpressure' => [
            'CUMULATIVE_DRIFT','QUEUE_BUSY','HIGH_CONSEQUENCE_REVIEW_ONLY',
        ],
    ];

    /** @return Outcome<array<string,mixed>> */
    public static function runFromRoot(string $root): Outcome
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        try {
            $freeze = HistoricalEvaluationFreeze::loadAndVerify($root);
            $bytes = file_get_contents($root . '/' . self::FIXTURE_PATH);
            if (!is_string($bytes) || strlen($bytes) > 131072) {
                return Outcome::refuse('VA01_RESPONSE_FIXTURE_MISSING_OR_EXCESSIVE');
            }
            $fixture = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($fixture)) {
                return Outcome::refuse('VA01_RESPONSE_FIXTURE_UNREADABLE');
            }
            $r = self::evaluate($freeze, $fixture);
            if (!$r->isOk()) {
                return $r;
            }
            $payload = $r->value();
            $payload['fixture_bytes_sha256'] = hash('sha256', $bytes);
            $payload['fixture_path'] = self::FIXTURE_PATH;
            $payload['fixture_origin'] = 'LOCAL_SOURCE_ONLY_NOT_PROVIDER';
            // The final digest binds the FULL exported object, not an
            // intermediate report_digest left over from Report::build().
            unset($payload['report_digest']);
            $payload['report_digest'] = Digest::canonicalDigest($payload);
            return Outcome::ok($payload);
        } catch (\Throwable) {
            return Outcome::refuse('VA01_FROZEN_EVALUATION_SOURCE_INVALID');
        }
    }

    /**
     * Pure in-memory evaluation of public development response fixtures;
     * this is also the negative-test entrypoint. Mutations are NEVER recorded
     * as approved model quality or independently heldout observations.
     *
     * @param array<string,mixed> $freeze VA-00 loadAndVerify() result.
     * @param array<string,mixed> $fixture proposed synthetic response batch.
     * @return Outcome<array<string,mixed>>
     */
    public static function evaluate(array $freeze, array $fixture): Outcome
    {
        $dev = $freeze['development'] ?? null;
        $m = $freeze['manifest'] ?? null;
        $held = $freeze['heldout'] ?? null;
        if (!is_array($dev) || !is_array($m) || !is_array($held)
            || ($m['status'] ?? null) !== 'RULES_FROZEN_HELDOUT_NOT_FROZEN'
            || ($m['longitudinal']['heldout_status'] ?? null) !== 'RESERVED_NOT_FROZEN_NOT_RUN'
            || ($held['status'] ?? null) !== 'RESERVED_NOT_FROZEN_NOT_RUN'
            || count($held['slots'] ?? []) !== 6
            || ($m['provider_spending'] ?? null) !== 'NOT_AUTHORIZED'
            || ($m['customer_release'] ?? null) !== 'NOT_AUTHORIZED') {
            return Outcome::refuse('VA01_FROZEN_OWNER_AUTHORITY_INVALID');
        }
        if (!self::exactKeys($fixture, self::FIXTURE_KEYS)
            || ($fixture['schema_version'] ?? null) !== self::FIXTURE_VERSION
            || ($fixture['classification'] ?? null)
                !== 'PUBLIC_SYNTHETIC_FIXTURE_RESPONSES_NOT_MODEL_OR_HUMAN_SCORES'
            || ($fixture['source_development_fixture_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($fixture['source_provenance'] ?? null)
                !== 'FIXTURE_CONSTRUCTED_FROM_PUBLIC_DEVELOPMENT_EXPECTATIONS_DOES_NOT_EXECUTE_THE_REAL_BASELINES'
            || ($fixture['scoring_authority'] ?? null)
                !== 'MECHANICAL_DIAGNOSTIC_ONLY_NO_MODEL_PERFORMANCE_CLAIM'
            || ($fixture['baseline_ids'] ?? null) !== self::BASELINES
            || ($fixture['case_count'] ?? null) !== self::CASE_COUNT
            || ($fixture['response_count'] ?? null) !== self::RECORD_COUNT
            || !is_array($fixture['responses'])
            || !array_is_list($fixture['responses'])
            || count($fixture['responses']) > self::RECORD_COUNT) {
            return Outcome::refuse('VA01_FIXTURE_CONTRACT_NOT_ADMITTED');
        }
        if (($dev['status'] ?? null) !== 'OFFLINE_FIXTURE_ONLY_NOT_ADMITTED'
            || count($dev['cases'] ?? []) !== self::CASE_COUNT
            || ($dev['authority_boundary']['service_model_attempts'] ?? null) !== 0
            || ($dev['authority_boundary']['no_provider_calls'] ?? null) !== true) {
            return Outcome::refuse('VA01_DEV_FIXTURE_SOURCE_NOT_ADMITTED');
        }
        $cases = [];
        foreach ($dev['cases'] as $case) {
            if (!is_array($case) || ($case['split'] ?? null) !== 'DEVELOPMENT'
                || !is_string($case['case_id'] ?? null)
                || isset($cases[$case['case_id']])
                || !is_array($case['observations'] ?? null)
                || !array_is_list($case['observations'])
                || count($case['observations']) > self::MAX_OBSERVATIONS_PER_CASE) {
                return Outcome::refuse('VA01_DEVELOPMENT_CASE_SCOPE_INVALID');
            }
            $cases[$case['case_id']] = $case;
        }
        $indexed = [];
        foreach ($fixture['responses'] as $r) {
            if (!is_array($r)
                || !is_string($r['case_id'] ?? null)
                || !isset($cases[$r['case_id']])
                || !in_array($r['baseline_id'] ?? null, self::BASELINES, true)
                || ($r['case_id'] ?? '') === 'heldout-01') {
                return Outcome::refuse('VA01_UNKNOWN_OR_HELDOUT_CASE_REFUSED');
            }
            $key = $r['baseline_id'] . "\0" . $r['case_id'];
            if (isset($indexed[$key])) {
                return Outcome::refuse('VA01_DUPLICATE_CASE_BASELINE_RESPONSE');
            }
            $indexed[$key] = $r;
        }

        $records = [];
        foreach (self::BASELINES as $baselineId) {
            foreach ($dev['cases'] as $case) {
                $record = self::evaluateCase($case, $baselineId,
                    $indexed[$baselineId . "\0" . $case['case_id']] ?? null, $dev, $m);
                $records[] = $record;
            }
        }
        if (count($records) !== self::RECORD_COUNT) {
            return Outcome::refuse('VA01_INCOMPLETE_CASE_LEDGER');
        }
        return HistoricalSequenceReport::build($records, $freeze,
            Digest::canonicalDigest($fixture));
    }

    /** @return array<string,mixed> */
    private static function evaluateCase(
        array $case, string $baselineId, ?array $response, array $dev, array $manifest,
    ): array {
        $id = $case['case_id'];
        $contract = [
            'case_id' => $id,
            'split' => 'DEVELOPMENT',
            'category' => $case['category'],
            'knowledge_cut' => $case['knowledge_cut'],
            'source_fixture_ref' => 'tests/fixtures/historical_review_development_cases.json#' . $id,
            'source_fixture_sha256' => self::SOURCE_SHA256,
            'comparison_baseline' => $baselineId,
            'scope' => 'SYNTHETIC_ONLY',
            'expectation_basis' => 'EXISTING_DEVELOPMENT_CASE',
        ];
        $checked = HistoricalEvaluationFreeze::checkDevelopmentCase($contract, $dev, $manifest);
        $status = $checked['outcome'] === 'DEVELOPMENT_SCHEMA_CHECKED'
            ? 'FIXTURE_CONTRACT_CHECKED' : 'REFUSED_CASE_CONTRACT';
        $errors = $status === 'FIXTURE_CONTRACT_CHECKED' ? [] : [$checked['reason_code']];
        $responseDisposition = 'NOT_SUPPLIED';
        $claimedIds = [];
        $claimedEvents = [];
        $claimedOrigins = [];
        $eligibleExpected = $case['expected']['eligible_observation_ids'];
        $eventsExpected = $case['expected']['distinct_event_ids'];
        $originsExpected = $case['expected']['origin_group_ids'];
        $observations = [];
        $observationById = [];
        $eligibleComputed = [];
        $eventsComputed = [];
        $originsComputed = [];
        $notKnown = 0;
        $blockedRights = 0;
        foreach ($case['observations'] as $obs) {
            $observations[] = $obs;
            $observationById[$obs['observation_id']] = $obs;
            if ($obs['known_at'] > $case['knowledge_cut']) {
                $notKnown++;
                continue;
            }
            if ($obs['current_reuse_right'] !== 'ACTIVE') {
                $blockedRights++;
                continue;
            }
            $eligibleComputed[] = $obs['observation_id'];
            if ($obs['kind'] === 'CHANGED') {
                $eventsComputed[$obs['event_id']] = true;
                $originsComputed[$obs['origin_group_id']] = true;
            }
        }
        foreach ([
            [$eligibleComputed, $eligibleExpected],
            [array_keys($eventsComputed), $eventsExpected],
            [array_keys($originsComputed), $originsExpected],
        ] as [$actual,$wanted]) {
            sort($actual, SORT_STRING);
            sort($wanted, SORT_STRING);
            if ($actual !== $wanted) {
                $errors[] = 'VA01_PINNED_DEV_ORACLE_INCONSISTENT';
            }
        }
        $critical = [];
        if ($response === null) {
            $errors[] = 'MISSING_SUPPLIED_RESPONSE';
        } elseif (!self::responseValid($response)) {
            $responseDisposition = 'REFUSED_INVALID_RESPONSE_CONTRACT';
            $errors[] = 'INVALID_RESPONSE_CONTRACT';
        } else {
            $responseDisposition = $response['disposition'];
            $claimedIds = $response['reported_eligible_observation_ids'];
            $claimedEvents = $response['reported_distinct_event_ids'];
            $claimedOrigins = $response['reported_origin_group_ids'];
            if ($responseDisposition !== 'SUBMITTED') {
                if ($claimedIds !== [] || $claimedEvents !== [] || $claimedOrigins !== []) {
                    $errors[] = 'NON_SUBMITTED_HAS_CLAIMS';
                }
                if (!is_string($response['refusal_code']) || $response['refusal_code'] === '') {
                    $errors[] = 'ABSTENTION_REASON_MISSING';
                }
                $errors[] = $responseDisposition === 'REFUSED' ? 'REFUSED_FIXTURE_NO_CANDIDATE'
                    : 'ABSTAINED_FIXTURE_NO_CANDIDATE';
            } else {
                if ($response['refusal_code'] !== null) {
                    $errors[] = 'SUBMITTED_WITH_REFUSAL_CODE';
                }
                foreach ($claimedIds as $observedId) {
                    $o = $observationById[$observedId] ?? null;
                    if ($o === null) {
                        $critical['EVIDENCE_FABRICATION'] = true;
                        $errors[] = 'INVENTED_OBSERVATION_REFERENCE';
                    } elseif ($o['current_reuse_right'] !== 'ACTIVE') {
                        $critical['SOURCE_RIGHTS_BYPASS'] = true;
                        $errors[] = 'REVOKED_SOURCE_REFERENCE';
                    } elseif ($o['known_at'] > $case['knowledge_cut']) {
                        $critical['EVIDENCE_FABRICATION'] = true;
                        $errors[] = 'POST_CUTOFF_EVIDENCE_REFERENCE';
                    }
                }
                if (!self::sameMultiset($claimedIds, $eligibleExpected)) {
                    $errors[] = 'EVIDENCE_ELIGIBILITY_MISMATCH';
                }
                if (!self::sameMultiset($claimedEvents, $eventsExpected)) {
                    $errors[] = 'EVENT_DEDUPLICATION_MISMATCH';
                }
                if (!self::sameMultiset($claimedOrigins, $originsExpected)) {
                    $errors[] = 'ORIGIN_GROUP_LABEL_MISMATCH';
                }
            }
        }
        $errors = array_values(array_unique($errors));
        $mechanical = $errors === [] && $responseDisposition === 'SUBMITTED';
        $family = 'UNCLASSIFIED';
        foreach (self::FAMILIES as $name=>$categories) {
            if (in_array($case['category'], $categories, true)) {
                $family = $name;
                break;
            }
        }
        $record = [
            'schema_version' => 'va01.historical-case-result.v1',
            'case_id' => $id,
            'category' => $case['category'],
            'family' => $family,
            'split' => 'DEVELOPMENT',
            'baseline_id' => $baselineId,
            'source_manifest_sha256' => self::SOURCE_SHA256,
            'case_contract_disposition' => $status,
            'response_disposition' => $responseDisposition,
            'evidence_observation_count' => count($observations),
            'eligible_reference_denominator' => count($eligibleExpected),
            'blocked_rights_reference_count' => $blockedRights,
            'not_known_at_cut_reference_count' => $notKnown,
            'expected_event_count' => count($eventsExpected),
            'expected_origin_label_count' => count($originsExpected),
            'reported_reference_count' => count($claimedIds),
            'reported_event_count' => count($claimedEvents),
            'reported_origin_label_count' => count($claimedOrigins),
            'eligible_reference_match' => $responseDisposition === 'SUBMITTED'
                && self::sameMultiset($claimedIds, $eligibleExpected),
            'event_deduplication_match' => $responseDisposition === 'SUBMITTED'
                && self::sameMultiset($claimedEvents, $eventsExpected),
            'origin_group_label_match' => $responseDisposition === 'SUBMITTED'
                && self::sameMultiset($claimedOrigins, $originsExpected),
            'origin_independence_verified' => false,
            'mechanical_match' => $mechanical,
            'mechanical_disposition' => $mechanical ? 'FIXTURE_MECHANICAL_MATCH'
                : ($responseDisposition === 'NOT_SUPPLIED' ? 'NOT_RUN'
                    : ($responseDisposition === 'REFUSED' ? 'REFUSED'
                        : ($responseDisposition === 'ABSTAINED' ? 'ABSTAINED' : 'MISMATCH_OR_INVALID'))),
            'failure_codes' => $errors,
            'critical_failure_codes' => array_keys($critical),
            'human_grounding_disposition' => 'NOT_ASSESSED',
            'human_usefulness_disposition' => 'NOT_ASSESSED',
            'candidate_model_attempt_id' => null,
            'provider_usage_status' => 'NOT_CALLED',
            'provider_input_tokens' => null,
            'provider_output_tokens' => null,
            'source_use_admitted' => false,
            'customer_release_authorized' => false,
            'external_effects_performed' => 0,
            'fixture_oracle_public' => true,
            'baseline_execution_verified' => false,
        ];
        $record['attempt_digest'] = Digest::canonicalDigest([
            'version' => self::VERSION,
            'baseline_id' => $baselineId,
            'case_id' => $id,
            'response' => $response,
            'result_digest' => Digest::canonicalDigest($record),
        ]);
        return $record;
    }

    private static function responseValid(array $r): bool
    {
        if (!self::exactKeys($r, self::RESPONSE_KEYS)
            || !in_array($r['disposition'], ['SUBMITTED','ABSTAINED','REFUSED'], true)
            || $r['provenance_type'] !== 'FIXTURE_NOT_PROVIDER_OR_NATIVE_BASELINE'
            || $r['source_data_kind'] !== 'SYNTHETIC_DEVELOPMENT_ORACLE_ECHO'
            || $r['claimed_business_materiality'] !== 'NOT_ASSESSED'
            || $r['claimed_attention_authority'] !== 'NONE') {
            return false;
        }
        foreach (['reported_eligible_observation_ids','reported_distinct_event_ids','reported_origin_group_ids'] as $key) {
            $values = $r[$key];
            if (!is_array($values) || !array_is_list($values)
                || count($values) > self::MAX_OBSERVATIONS_PER_CASE) {
                return false;
            }
            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value) !== 1) {
                    return false;
                }
            }
        }
        if ($r['refusal_code'] !== null
            && (!is_string($r['refusal_code'])
                || preg_match('/^[A-Z0-9_]{1,80}$/D', $r['refusal_code']) !== 1)) {
            return false;
        }
        return true;
    }

    /** Multiset equality preserves repeated events as overcount rather than masking duplicates. */
    private static function sameMultiset(array $a, array $b): bool
    {
        sort($a, SORT_STRING);
        sort($b, SORT_STRING);
        return $a === $b;
    }

    /** @param list<string> $want */
    private static function exactKeys(array $given, array $want): bool
    {
        $keys = array_keys($given);
        sort($keys, SORT_STRING);
        sort($want, SORT_STRING);
        return $keys === $want;
    }
}
