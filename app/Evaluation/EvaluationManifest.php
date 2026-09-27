<?php
declare(strict_types=1);
namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\PromptOps\ProductPromptPack;

/**
 * A frozen, reviewed set of synthetic evaluation cases and the scorecard they are judged by.
 *
 * Its identity is the SHA-256 of its exact bytes. An evaluation grant binds one manifest; changing a
 * case, the development/held-out split or a threshold is a new manifest and needs a new grant. The
 * split and the scorecard are fixed BEFORE any native run, so tuning cannot quietly move the target.
 * Material is synthetic and non-sensitive by contract: no real company, person or customer text.
 */
final class EvaluationManifest
{
    public const DEFAULT_PATH = 'tests/fixtures/monitor_model_eval_cases.json';
    public const SPLITS = ['DEVELOPMENT', 'HELD_OUT'];
    private const MAX_BYTES = 262144;

    private function __construct(public readonly array $data, public readonly string $sha256, public readonly string $path) {}

    public static function forRoot(string $root): Outcome
    {
        return self::load(rtrim($root, '/') . '/' . self::DEFAULT_PATH);
    }

    public static function load(string $path): Outcome
    {
        $bytes = is_file($path) ? file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) {
            return Outcome::refuse('EVALUATION_MANIFEST_NOT_AVAILABLE');
        }
        try {
            $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Outcome::refuse('EVALUATION_MANIFEST_INVALID');
        }
        $problem = self::problem($data);

        return $problem === null ? Outcome::ok(new self($data, hash('sha256', $bytes), $path)) : Outcome::refuse('EVALUATION_MANIFEST_INVALID', ['problem' => $problem]);
    }

    /** Whether the file on disk is still exactly the bytes this manifest was loaded from. */
    public function unchanged(): bool
    {
        return is_file($this->path) && hash_equals($this->sha256, (string) hash_file('sha256', $this->path));
    }

    public function case(string $caseId): ?array
    {
        foreach ($this->data['cases'] as $case) {
            if ($case['case_id'] === $caseId) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function caseIds(string $selection): array
    {
        $ids = [];
        foreach ($this->data['cases'] as $case) {
            if ($selection === 'ALL' || $case['split'] === $selection) {
                $ids[] = $case['case_id'];
            }
        }

        return $ids;
    }

    /**
     * The mechanical checks for one candidate against its case's frozen expectations. These are
     * necessary conditions only — whether a candidate is grounded and useful is a human's call.
     *
     * @return array{pass: bool, failures: list<string>, state: ?string}
     */
    public static function mechanical(array $case, string $outcome, ?array $candidate, ?string $reason): array
    {
        $failures = [];
        if ($outcome !== 'CANDIDATE' || $candidate === null) {
            return ['pass' => false, 'failures' => ['NO_CANDIDATE:' . ($reason ?? 'UNKNOWN')], 'state' => null];
        }
        $e = $case['expectations'];
        $critic = in_array($case['role'], ['MONITOR_REVIEW_CRITIC', 'MONITOR_ROUTING_CRITIC'], true);
        $state = $critic ? ($candidate['verdict'] ?? null) : ($candidate['status'] ?? null);
        if (($e['expect_status'] ?? null) !== null && $state !== $e['expect_status']) {
            $failures[] = 'STATUS_NOT_' . $e['expect_status'];
        }
        if (($e['expect_hold'] ?? false) === true && !in_array($state, ['HELD', 'HOLD'], true)) {
            $failures[] = 'EXPECTED_HOLD';
        }
        if (in_array($state, $e['forbidden_verdicts'] ?? [], true)) {
            $failures[] = 'FORBIDDEN_VERDICT_' . $state;
        }
        // Only the candidate's text values are searched — never its field names.
        $values = [];
        array_walk_recursive($candidate, static function (mixed $v) use (&$values): void { if (is_string($v)) { $values[] = $v; } });
        $text = mb_strtolower(implode("\n", $values));
        foreach ($e['forbidden_substrings'] ?? [] as $forbidden) {
            if (str_contains($text, mb_strtolower($forbidden))) {
                $failures[] = 'FORBIDDEN_TEXT';
                break;
            }
        }

        return ['pass' => $failures === [], 'failures' => $failures, 'state' => is_string($state) ? $state : null];
    }

    private static function problem(mixed $d): ?string
    {
        if (!is_array($d) || ($d['schema_version'] ?? null) !== '1.0' || ($d['kind'] ?? null) !== 'MONITOR_MODEL_EVALUATION_MANIFEST') {
            return 'header';
        }
        if (($d['material_provenance'] ?? null) !== 'SYNTHETIC_NON_SENSITIVE') {
            return 'material_provenance';
        }
        foreach (['manifest_version', 'purpose', 'audience'] as $key) {
            if (!is_string($d[$key] ?? null) || trim($d[$key]) === '' || strlen($d[$key]) > 4000) {
                return $key;
            }
        }
        $score = $d['scorecard'] ?? null;
        if (!is_array($score) || !is_int($score['critical_failures_allowed'] ?? null) || $score['critical_failures_allowed'] < 0 || ($score['require_all_held_out'] ?? null) !== true) {
            return 'scorecard';
        }
        foreach (['min_held_out_mechanical_pass_rate', 'min_grounding_pass_rate', 'min_usefulness_pass_rate'] as $rate) {
            if ((!is_int($score[$rate] ?? null) && !is_float($score[$rate] ?? null)) || $score[$rate] < 0 || $score[$rate] > 1) {
                return 'scorecard.' . $rate;
            }
        }
        if (!is_array($d['cases'] ?? null) || $d['cases'] === []) {
            return 'cases';
        }
        $seen = [];
        $heldOut = 0;
        foreach ($d['cases'] as $i => $c) {
            if (!is_array($c) || !is_string($c['case_id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/D', $c['case_id']) !== 1 || isset($seen[$c['case_id']])) {
                return "cases[{$i}].case_id";
            }
            $seen[$c['case_id']] = true;
            if (!in_array($c['split'] ?? null, self::SPLITS, true) || !in_array($c['role'] ?? null, ProductPromptPack::ROLES, true) || !is_bool($c['critical'] ?? null)) {
                return "cases[{$i}]";
            }
            $heldOut += $c['split'] === 'HELD_OUT' ? 1 : 0;
            if (!is_string($c['category'] ?? null) || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $c['category']) !== 1) {
                return "cases[{$i}].category";
            }
            $ev = $c['evidence'] ?? null;
            if (!is_array($ev) || array_keys($ev) !== ['before', 'after', 'profile']) {
                return "cases[{$i}].evidence";
            }
            foreach ($ev as $text) {
                if (!is_string($text) || trim($text) === '' || strlen($text) > 8000 || !mb_check_encoding($text, 'UTF-8')) {
                    return "cases[{$i}].evidence";
                }
            }
            $needsPrior = $c['role'] !== 'P01_SIGNAL_CLASSIFIER';
            if ($needsPrior !== is_array($c['prior_candidate'] ?? null)) {
                return "cases[{$i}].prior_candidate";
            }
            if (($c['role'] === 'MONITOR_ROUTING_CRITIC') !== is_array($c['routing'] ?? null)) {
                return "cases[{$i}].routing";
            }
            $x = $c['expectations'] ?? null;
            if (!is_array($x) || array_diff(array_keys($x), ['expect_status', 'expect_hold', 'forbidden_substrings', 'forbidden_verdicts']) !== [] || !is_bool($x['expect_hold'] ?? null) || !is_array($x['forbidden_substrings'] ?? null)) {
                return "cases[{$i}].expectations";
            }
            if (!is_string($c['assessment_guidance'] ?? null) || trim($c['assessment_guidance']) === '') {
                return "cases[{$i}].assessment_guidance";
            }
        }

        return $heldOut === 0 ? 'no held-out cases' : null;
    }
}
