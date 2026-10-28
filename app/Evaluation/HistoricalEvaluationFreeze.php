<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

/**
 * VA-00 read-only contract lock for historical evaluation preparation.
 *
 * This class has no DB, transport, credential, action or M10 writer. It freezes
 * what can be evaluated later, not an evaluation or an execution permission.
 * Real held-out custody and human score authentication belong to independent
 * owners and VA-01/V05, not to synthetic fixture metadata.
 */
final class HistoricalEvaluationFreeze
{
    private const PINNED_SHA256 = [
        'tests/fixtures/monitor_model_eval_cases.json' => 'cd21a4224b4b1c54f4378657b1950d5d399a0a743738739799f2341eeddf7c70',
        'tests/fixtures/historical_review_development_cases.json' => '52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b',
        'tests/fixtures/historical_review_heldout_manifest.json' => 'dc70e10438f6ffdf3acba6f1eba77e2aaa49f2e8e1682890b09aa44f0f1995f8',
        'tests/fixtures/monitor_observation_v2_eval_cases.json' => '8210f429bccd998fb518064eec85c28b8351959626e38bfe3e2223c33cadefd5',
        'app/PromptOps/bundles/2026-09-27.monitor.v1.json' => 'ed3c377ea2a91e906f8df2875d337af524b7e70db6f111803cc90c21a22bbcde',
        'app/PromptOps/bundles/monitor-observation-v2.json' => '718d0ac64e56d633504be295510879bebbb1b751decfa2038b1bbd1385731cdf',
        'app/Provider/ModelPolicy.php' => 'd2371c09d61eb9a1e6eb1de65efd7a9d4677e875cef5f26b266e073b90165e84',
        'docs/MONITOR_MODEL_EVALUATION_PROTOCOL.md' => 'c8613f93bbb7f65e53df6a69c4a2b20b160fe49c28c01e224a86a680d772ff2d',
        'docs/decisions/plus46_luna_dev7_model_evaluation_grant_2026_10_24.json' => 'f614960a99cff5f99568a17b8a6a43dcb208f647ccfc998affff3b9ecdbbd309',
        'docs/decisions/plus46_luna_model_evaluation_prompts_2026_10_24.json' => 'a4b0b69fecfc564223714ab16847816bb1d473a0b268a6042dc4fd08dcc9b3ca',
    ];

    private const HELDOUT_CATEGORIES = [
        'NOISE_VS_FACT', 'TIME_CUT_DELAY', 'SOURCE_RIGHTS',
        'FALSE_CORROBORATION', 'CONTRADICTION', 'ALERT_DEDUPLICATION',
    ];

    /** @return array{manifest: array, development: array, heldout: array, legacy: array} */
    public static function loadAndVerify(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $manifest = self::readJson($root . '/docs/longitudinal/build-first/EVALUATION_FREEZE_MANIFEST.json');
        $development = self::readJson($root . '/tests/fixtures/historical_review_development_cases.json');
        $heldout = self::readJson($root . '/tests/fixtures/historical_review_heldout_manifest.json');
        $legacy = self::readJson($root . '/tests/fixtures/monitor_model_eval_cases.json');
        self::validate($manifest, $development, $heldout, $legacy);

        $files = $manifest['pinned_source_files'];
        $seen = [];
        foreach ($files as $record) {
            self::require(is_array($record), 'SOURCE_RECORD');
            $path = $record['path'] ?? null;
            self::require(is_string($path) && isset(self::PINNED_SHA256[$path]) && !isset($seen[$path]), 'SOURCE_PATH');
            $seen[$path] = true;
            $bytes = @file_get_contents($root . '/' . $path);
            self::require(is_string($bytes), 'SOURCE_MISSING');
            $sha = hash('sha256', $bytes);
            self::require(hash_equals(self::PINNED_SHA256[$path], $sha), 'SOURCE_BYTE_DRIFT');
            self::require(($record['sha256_bytes'] ?? null) === $sha, 'SOURCE_MANIFEST_HASH_DRIFT');
            $gitSha1 = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
            self::require(($record['git_blob_sha1'] ?? null) === $gitSha1, 'SOURCE_GIT_BLOB_DRIFT');
        }
        self::require(count($seen) === count(self::PINNED_SHA256), 'SOURCE_FILE_COVERAGE');
        return compact('manifest', 'development', 'heldout', 'legacy');
    }

    /** Data-only checks; no attempt to prove actual custodial identity or native grants. */
    public static function validate(array $m, array $dev, array $heldout, array $legacy): void
    {
        self::require(($m['schema_version'] ?? null) === 'allfather.va00.evaluation_freeze.v1', 'SCHEMA');
        self::require(($m['status'] ?? null) === 'RULES_FROZEN_HELDOUT_NOT_FROZEN', 'FALSE_HOLDOUT_FREEZE');
        foreach (['model_quality' => 'NOT_ACCEPTED', 'owner_approval' => 'PENDING',
            'external_source_access' => 'NOT_AUTHORIZED', 'provider_spending' => 'NOT_AUTHORIZED',
            'customer_release' => 'NOT_AUTHORIZED', 'schema_migrations' => 'NONE'] as $key => $expected) {
            self::require(($m[$key] ?? null) === $expected, 'ILLICIT_AUTHORITY_' . $key);
        }
        self::require(($m['source_baseline']['adopted_global_authority_pin'] ?? null)
            === 'c6d9cf9a7a1001b001ca6118999e9cb75c831c4d', 'GLOBAL_PIN');
        self::require(($m['source_baseline']['commit'] ?? null)
            === 'a625f97551ae937648ca57c8d113d600d171cb22', 'BASE_SHA');
        self::require(($m['plan_sha256'] ?? null)
            === 'e7c2f7d2366e81a2ab623fa998314ee264d9ef8818c843cb1171da70750d17b2', 'PLAN_IDENTITY');

        self::require(($legacy['kind'] ?? null) === 'MONITOR_MODEL_EVALUATION_MANIFEST'
            && ($legacy['manifest_version'] ?? null) === '2026-09-27.v1', 'LEGACY_MANIFEST');
        self::require(count($legacy['cases'] ?? []) === 18, 'LEGACY_CASE_COUNT');
        self::require(count(array_filter($legacy['cases'], static fn (array $c): bool => $c['split'] === 'DEVELOPMENT')) === 7, 'LEGACY_DEV_SPLIT');
        self::require(count(array_filter($legacy['cases'], static fn (array $c): bool => $c['split'] === 'HELD_OUT')) === 11, 'LEGACY_HELDOUT_SPLIT');

        $long = $m['longitudinal'] ?? null;
        self::require(is_array($long) && ($long['version'] ?? null) === 'historical-evaluation-candidate-2026-10-28.v1', 'LONG_VERSION');
        self::require(($dev['status'] ?? null) === 'OFFLINE_FIXTURE_ONLY_NOT_ADMITTED'
            && count($dev['cases'] ?? []) === 15 && ($long['development_case_count'] ?? null) === 15, 'DEV_POPULATION');
        $devIds = array_column($dev['cases'], 'case_id');
        self::require(count(array_unique($devIds)) === 15 && ($long['development_split'] ?? null) === 'DEVELOPMENT', 'DEV_IDS');
        self::require(($heldout['status'] ?? null) === 'RESERVED_NOT_FROZEN_NOT_RUN'
            && count($heldout['slots'] ?? []) === 6 && ($long['heldout_slot_count'] ?? null) === 6, 'HELDOUT_NOT_FROZEN');
        foreach ($heldout['slots'] as $i => $original) {
            $new = $long['heldout_slots'][$i] ?? null;
            self::require(is_array($new) && ($new['case_id'] ?? null) === ($original['case_id'] ?? null), 'HELDOUT_CASE_ID');
            self::require(($new['category'] ?? null) === self::HELDOUT_CATEGORIES[$i], 'HELDOUT_CATEGORY');
            self::require(($new['payload'] ?? null) === 'NOT_SUPPLIED' && ($new['oracle'] ?? null) === 'NOT_SUPPLIED'
                && array_key_exists('sha256_commitment', $new) && $new['sha256_commitment'] === null && array_key_exists('oracle_sha256_commitment', $new) && $new['oracle_sha256_commitment'] === null, 'HELDOUT_ORACLE_EXPOSED');
            self::require(($new['case_status'] ?? null) === 'NOT_FROZEN'
                && ($new['execution_status'] ?? null) === 'NOT_RUN'
                && ($new['human_assessment'] ?? null) === 'NOT_ASSESSED', 'HELDOUT_FALSE_RESULT');
        }
        $custody = $long['custody'] ?? [];
        self::require(array_key_exists('custodian_identity', $custody) && $custody['custodian_identity'] === null && ($custody['custodian_status'] ?? null) === 'UNASSIGNED', 'FABRICATED_CUSTODIAN');
        self::require(array_key_exists('private_payload_location', $custody) && $custody['private_payload_location'] === null
            && array_key_exists('private_oracle_location', $custody) && $custody['private_oracle_location'] === null
            && ($custody['payload_commitments'] ?? null) === []
            && ($custody['oracle_commitments'] ?? null) === []
            && ($custody['blindness_verified'] ?? null) === false, 'FABRICATED_HOLDOUT_CUSTODY');
        self::require(($custody['grounding_assessors'] ?? null) === 'UNASSIGNED'
            && ($custody['usefulness_assessors'] ?? null) === 'UNASSIGNED', 'FABRICATED_HUMAN_SCORING');
        self::require(($long['current_observations']['heldout_attempts'] ?? -1) === 0
            && ($long['current_observations']['independent_human_scores'] ?? -1) === 0, 'FABRICATED_RESULTS');
        self::require(($long['current_observations']['candidate_acceptance'] ?? null) === 'INCOMPLETE_UNFROZEN_HELDOUT', 'FALSE_QUALITY_ACCEPTANCE');

        $thresholds = $long['thresholds'] ?? [];
        $old = $legacy['scorecard'] ?? [];
        self::require(($thresholds['critical_failures_allowed'] ?? -1) === ($old['critical_failures_allowed'] ?? null)
            && ($thresholds['heldout_mechanical_pass_rate_min'] ?? -1) === ($old['min_held_out_mechanical_pass_rate'] ?? null)
            && ($thresholds['human_grounding_pass_rate_min'] ?? -1) === ($old['min_grounding_pass_rate'] ?? null)
            && ($thresholds['human_usefulness_pass_rate_min'] ?? -1) === ($old['min_usefulness_pass_rate'] ?? null), 'THRESHOLD_DRIFT');
        self::require(($thresholds['heldout_cases_required'] ?? null) === 6
            && ($thresholds['all_heldout_must_be_independently_assessed'] ?? null) === true
            && ($thresholds['owner_review_status'] ?? null) === 'PENDING', 'UNADOPTED_SCORECARD');

        $comparison = array_column($long['baselines'] ?? [], 'id');
        self::require($comparison === ['EXISTING_PER_DIFF_V1', 'FIXED_WEEKLY_UTC_CANDIDATE_V1'], 'BASELINES_CHANGED');
        self::require(($long['baselines'][1]['period_days'] ?? null) === 7
            && ($long['baselines'][1]['status'] ?? null) === 'CANDIDATE_SPEC_ONLY_NOT_CANONICAL_POLICY', 'CALENDAR_TUNED');
        self::require(count($long['denominator_rules'] ?? []) === 7, 'DENOMINATORS');
        foreach ($long['denominator_rules'] as $metric) {
            self::require(array_key_exists('observed_numerator', $metric) && $metric['observed_numerator'] === null
                && array_key_exists('observed_denominator', $metric) && $metric['observed_denominator'] === null
                && array_key_exists('rate', $metric) && $metric['rate'] === null, 'INVENTED_METRIC');
        }
        self::require(count($long['critical_failure_codes'] ?? []) === 7
            && count(array_unique($long['critical_failure_codes'])) === 7, 'CRITICAL_TAXONOMY');
        self::require(($long['case_contract']['name'] ?? null) === 'HistoricalEvaluationCase.v1'
            && ($long['human_score_contract']['name'] ?? null) === 'HumanScorecard.v1', 'INTERFACE_NAMES');
        self::require(($long['human_score_contract']['assessment_receipts_present'] ?? null) === false, 'HUMAN_RECEIPT_FALSE_CLAIM');
        self::require(($m['historical_monitors']['DEV7']['independent_human_quality_status'] ?? null) === 'NOT_ACCEPTED'
            && array_key_exists('private_artifact_sha256', $m['historical_monitors']['DEV7']) && $m['historical_monitors']['DEV7']['private_artifact_sha256'] === null, 'DEV7_REWRITTEN');
    }

    /** @return array{outcome:string,reason_code:string} */
    public static function checkDevelopmentCase(array $fixture, array $dev, array $manifest): array
    {
        $deny = static fn (string $why): array => ['outcome' => 'REFUSED', 'reason_code' => $why];
        if (($fixture['split'] ?? null) !== 'DEVELOPMENT') {
            return $deny('VA00_HELDOUT_UNFROZEN');
        }
        $allowed = $manifest['longitudinal']['case_contract']['required'] ?? [];
        if (!is_array($fixture) || array_diff(array_keys($fixture), $allowed) !== []
            || array_diff($allowed, array_keys($fixture)) !== []) {
            return $deny('VA00_CASE_EXTRA_FIELD');
        }
        $source = null;
        foreach ($dev['cases'] ?? [] as $row) {
            if (($row['case_id'] ?? null) === ($fixture['case_id'] ?? null)) {
                $source = $row;
                break;
            }
        }
        if ($source === null) {
            return $deny('VA00_CASE_NOT_IN_PINNED_DEVELOPMENT');
        }
        if (($fixture['source_fixture_sha256'] ?? null)
            !== '52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b'
            || ($fixture['source_fixture_ref'] ?? null)
            !== 'tests/fixtures/historical_review_development_cases.json#' . $fixture['case_id']) {
            return $deny('VA00_SOURCE_MANIFEST_IDENTITY');
        }
        if (($fixture['category'] ?? null) !== $source['category']
            || ($fixture['knowledge_cut'] ?? null) !== $source['knowledge_cut']
            || ($fixture['scope'] ?? null) !== 'SYNTHETIC_ONLY'
            || ($fixture['expectation_basis'] ?? null) !== 'EXISTING_DEVELOPMENT_CASE') {
            return $deny('VA00_DEVELOPMENT_CONTEXT_MISMATCH');
        }
        if (!in_array($fixture['comparison_baseline'] ?? null,
            ['EXISTING_PER_DIFF_V1', 'FIXED_WEEKLY_UTC_CANDIDATE_V1'], true)) {
            return $deny('VA00_BASELINE_NOT_FROZEN');
        }
        return ['outcome' => 'DEVELOPMENT_SCHEMA_CHECKED', 'reason_code' => 'VA00_CANDIDATE_ONLY_NOT_MODEL_RESULT'];
    }

    /** This layer cannot certify a human identity or count a synthetic PASS. */
    public static function checkHumanScore(array $score, array $manifest): array
    {
        if (($manifest['longitudinal']['custody']['custodian_status'] ?? null) !== 'UNASSIGNED'
            || ($manifest['longitudinal']['human_score_contract']['assessment_receipts_present'] ?? null) !== false) {
            throw new \DomainException('VA00_MISSTATED_CUSTODY');
        }
        if ($score === $manifest['longitudinal']['human_score_contract']['no_assessment_placeholder']) {
            return ['outcome' => 'NOT_ASSESSED', 'reason_code' => 'VA00_HUMAN_UNASSIGNED'];
        }
        return ['outcome' => 'REFUSED', 'reason_code' => 'VA00_HUMAN_NOT_AUTHENTICATED'];
    }

    /**
     * A zero denominator is null/INCOMPLETE, never a perfect score.
     * Counts are purely descriptive; no accepted verdict can be minted here.
     * @return array{status:string,mechanical_rate:?float,grounding_rate:?float,usefulness_rate:?float}
     */
    public static function preliminaryRates(array $numbers, array $manifest): array
    {
        foreach (['mechanical_pass','mechanical_total','grounding_pass','grounding_total',
            'usefulness_pass','usefulness_total','critical_failures'] as $key) {
            if (!isset($numbers[$key]) || !is_int($numbers[$key]) || $numbers[$key] < 0) {
                throw new \DomainException('VA00_COUNT_INVALID');
            }
        }
        foreach (['mechanical','grounding','usefulness'] as $kind) {
            if ($numbers[$kind . '_pass'] > $numbers[$kind . '_total']) {
                throw new \DomainException('VA00_IMPOSSIBLE_DENOMINATOR');
            }
        }
        $ratio = static fn (string $kind): ?float
            => $numbers[$kind . '_total'] === 0 ? null : $numbers[$kind . '_pass'] / $numbers[$kind . '_total'];
        self::require(($manifest['longitudinal']['heldout_status'] ?? null) === 'RESERVED_NOT_FROZEN_NOT_RUN', 'HOLDOUT_STATUS_CHANGED');
        return [
            'status' => 'INCOMPLETE_UNFROZEN_HELDOUT',
            'mechanical_rate' => $ratio('mechanical'),
            'grounding_rate' => $ratio('grounding'),
            'usefulness_rate' => $ratio('usefulness'),
        ];
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \DomainException('VA00_SOURCE_UNAVAILABLE');
        }
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('VA00_INVALID_JSON', 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \DomainException('VA00_NOT_OBJECT');
        }
        return $data;
    }

    private static function require(bool $ok, string $reason): void
    {
        if (!$ok) {
            throw new \DomainException('VA00_' . $reason);
        }
    }
}
