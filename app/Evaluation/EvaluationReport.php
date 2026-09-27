<?php
declare(strict_types=1);
namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Db\Database;

/**
 * What an evaluation run showed, judged by the scorecard frozen in its manifest.
 *
 * Computed from the append-only records every time, never stored, so it cannot drift from them.
 * Verdicts: INCOMPLETE (a held-out case was not selected, has no result, or a candidate lacks either
 * human assessment), REJECTED (any critical failure, a held-out mechanical failure beyond the frozen
 * rate, or grounding/usefulness below threshold), or ACCEPTED_FOR_OWNER_REVIEW. The last is evidence
 * an owner may cite when deciding a product prompt approval — it approves nothing and activates
 * nothing by itself, and says so in every report.
 */
final class EvaluationReport
{
    public const INCOMPLETE = 'INCOMPLETE';
    public const REJECTED = 'REJECTED';
    public const ACCEPTED = 'ACCEPTED_FOR_OWNER_REVIEW';

    public static function build(Database $db, string $tenantId, string $workspaceId, string $runId, EvaluationManifest $manifest): Outcome
    {
        $run = $db->one('SELECT * FROM monitor_evaluation_runs WHERE evaluation_run_id=? AND tenant_id=? AND workspace_id=?', [$runId, $tenantId, $workspaceId]);
        if ($run === null) {
            return Outcome::refuse('EVALUATION_RUN_NOT_FOUND');
        }
        if ($run['manifest_sha256'] !== $manifest->sha256) {
            return Outcome::refuse('EVALUATION_MANIFEST_MISMATCH');
        }
        $score = $manifest->data['scorecard'];
        $selected = json_decode($run['selected_case_ids_json'], true, 8, JSON_THROW_ON_ERROR);

        // Resolve the exact evaluation prompt identities up front. A candidate only counts when its
        // provider attempt used the prompt/schema the evaluation grant actually named for that role.
        $prompts = [];
        $grant = $db->one('SELECT definition_json FROM monitor_execution_grants WHERE execution_grant_id=?', [$run['execution_grant_id']]);
        foreach ($grant === null ? [] : json_decode($grant['definition_json'], true, 32, JSON_THROW_ON_ERROR)['prompt_approval_ids'] as $approvalId) {
            $p = $db->one('SELECT role,prompt_approval_id,prompt_sha256,schema_sha256 FROM monitor_prompt_approvals WHERE prompt_approval_id=? AND tenant_id=? AND workspace_id=?', [$approvalId, $tenantId, $workspaceId]);
            if ($p !== null) {
                $prompts[$p['role']] = ['prompt_approval_id' => $p['prompt_approval_id'], 'prompt_sha256' => $p['prompt_sha256'], 'schema_sha256' => $p['schema_sha256']];
            }
        }
        ksort($prompts);

        $results = [];
        foreach ($db->all('SELECT * FROM monitor_evaluation_results WHERE evaluation_run_id=? ORDER BY case_id', [$runId]) as $row) {
            $results[$row['case_id']] = $row;
        }
        $assessments = [];
        foreach ($db->all('SELECT a.* FROM monitor_evaluation_assessments a JOIN monitor_evaluation_results r ON r.evaluation_result_id=a.evaluation_result_id WHERE r.evaluation_run_id=?', [$runId]) as $a) {
            $assessments[$a['evaluation_result_id']][$a['dimension']] = $a;
        }
        $calls = [];
        foreach ($db->all('SELECT c.provider_call_id,c.input_tokens,c.output_tokens,c.latency_ms FROM provider_call_records c JOIN monitor_evaluation_results r ON r.provider_call_id=c.provider_call_id WHERE r.evaluation_run_id=?', [$runId]) as $c) {
            $calls[$c['provider_call_id']] = $c;
        }

        $reasons = [];
        $cases = [];
        $t = ['selected' => count($selected), 'results' => 0, 'candidates' => 0, 'refused' => 0, 'held_or_abstained' => 0, 'declared_refusals' => 0,
              'incomplete_responses' => 0, 'mechanical_failures' => 0, 'held_out_mechanical_failures' => 0, 'held_out_results' => 0, 'critical_failures' => 0,
              'provider_provenance_failures' => 0,
              'grounding' => ['PASS' => 0, 'FAIL' => 0, 'NOT_APPLICABLE' => 0], 'usefulness' => ['PASS' => 0, 'FAIL' => 0, 'NOT_APPLICABLE' => 0],
              'input_tokens' => 0, 'output_tokens' => 0, 'latencies_ms' => []];
        $byCategory = [];
        foreach ($manifest->caseIds('HELD_OUT') as $heldOut) {
            if (!in_array($heldOut, $selected, true)) {
                $reasons[] = "HELD_OUT_CASE_NOT_SELECTED:{$heldOut}";
            }
        }
        foreach ($selected as $caseId) {
            $case = $manifest->case($caseId);
            $r = $results[$caseId] ?? null;
            $byCategory[$case['category']] ??= ['cases' => 0, 'mechanical_pass' => 0, 'grounding_pass' => 0, 'grounding_fail' => 0];
            $byCategory[$case['category']]['cases']++;
            if ($r === null) {
                $reasons[] = "CASE_NOT_EXECUTED:{$caseId}";
                $cases[] = ['case_id' => $caseId, 'split' => $case['split'], 'category' => $case['category'], 'role' => $case['role'], 'executed' => false];
                continue;
            }
            $t['results']++;
            $mechanical = json_decode($r['mechanical_json'], true, 8, JSON_THROW_ON_ERROR);
            $a = $assessments[$r['evaluation_result_id']] ?? [];
            $critical = false;
            foreach ($a as $one) {
                $critical = $critical || (int) $one['critical_failure'] === 1;
            }
            // A critical case that failed its mechanical check (for example, an injection it did
            // not hold on) is a critical failure whatever any assessment says.
            $critical = $critical || ((int) $r['critical'] === 1 && (int) $r['mechanical_pass'] === 0);
            $providerProvenanceValid = true;
            if ($r['outcome'] === 'CANDIDATE') {
                $prompt = $prompts[$case['role']] ?? null;
                $proof = $prompt === null || $r['model_attempt_id'] === null || $r['provider_call_id'] === null || $r['request_digest'] === null || $r['candidate_digest'] === null
                    ? null
                    : $db->one(
                        "SELECT 1 AS ok
                         FROM provider_model_attempts a
                         JOIN provider_model_results mr ON mr.model_attempt_id=a.model_attempt_id
                         JOIN provider_attempt_purposes pp ON pp.model_attempt_id=a.model_attempt_id
                         JOIN provider_call_records c ON c.provider_call_id=mr.provider_call_id
                         WHERE a.model_attempt_id=? AND a.tenant_id=? AND a.workspace_id=? AND a.mission_id=?
                           AND a.execution_mode=? AND a.request_digest=? AND a.prompt_sha256=? AND a.schema_sha256=?
                           AND mr.provider_call_id=? AND mr.outcome='CANDIDATE' AND mr.candidate_digest=?
                           AND pp.execution_purpose='MODEL_EVALUATION' AND pp.execution_grant_id=?
                           AND pp.evaluation_run_id=? AND pp.monitor_run_id IS NULL
                           AND pp.material_provenance='SYNTHETIC_EVALUATION_CASE'
                           AND c.provider_call_id=? AND c.tenant_id=? AND c.workspace_id=?
                           AND c.outcome='SUCCEEDED' AND c.correlation_id=?",
                        [
                            $r['model_attempt_id'], $tenantId, $workspaceId, $run['mission_id'],
                            $run['execution_mode'], $r['request_digest'], $prompt['prompt_sha256'], $prompt['schema_sha256'],
                            $r['provider_call_id'], $r['candidate_digest'], $run['execution_grant_id'],
                            $runId, $r['provider_call_id'], $tenantId, $workspaceId, $runId,
                        ],
                    );
                $providerProvenanceValid = $proof !== null;
                if (!$providerProvenanceValid) {
                    ++$t['provider_provenance_failures'];
                    $critical = true;
                }
            }
            $t['critical_failures'] += $critical ? 1 : 0;
            if ($r['outcome'] === 'CANDIDATE') {
                $t['candidates']++;
                foreach (['GROUNDING', 'USEFULNESS'] as $dimension) {
                    if (!isset($a[$dimension])) {
                        $reasons[] = "ASSESSMENT_MISSING:{$caseId}:{$dimension}";
                    } else {
                        $t[strtolower($dimension)][$a[$dimension]['verdict']]++;
                    }
                }
                if (in_array($mechanical['state'] ?? null, ['HELD', 'HOLD', 'NO_MATERIAL_CHANGE'], true)) {
                    $t['held_or_abstained']++;
                }
            } else {
                $t['refused']++;
                $t['declared_refusals'] += $r['reason_code'] === 'MODEL_DECLARED_REFUSAL' ? 1 : 0;
                $t['incomplete_responses'] += $r['reason_code'] === 'MODEL_RESPONSE_NOT_COMPLETED' ? 1 : 0;
            }
            if ((int) $r['mechanical_pass'] === 0) {
                $t['mechanical_failures']++;
            } else {
                $byCategory[$case['category']]['mechanical_pass']++;
            }
            if ($case['split'] === 'HELD_OUT') {
                $t['held_out_results']++;
                $t['held_out_mechanical_failures'] += (int) $r['mechanical_pass'] === 0 ? 1 : 0;
            }
            if (isset($a['GROUNDING'])) {
                $byCategory[$case['category']][$a['GROUNDING']['verdict'] === 'FAIL' ? 'grounding_fail' : 'grounding_pass'] += $a['GROUNDING']['verdict'] === 'NOT_APPLICABLE' ? 0 : 1;
            }
            $call = $r['provider_call_id'] !== null ? ($calls[$r['provider_call_id']] ?? null) : null;
            if ($call !== null) {
                $t['input_tokens'] += (int) $call['input_tokens'];
                $t['output_tokens'] += (int) $call['output_tokens'];
                $t['latencies_ms'][] = (int) $call['latency_ms'];
            }
            $cases[] = [
                'case_id' => $caseId, 'split' => $case['split'], 'category' => $case['category'], 'role' => $case['role'], 'critical' => (bool) (int) $r['critical'],
                'executed' => true, 'evaluation_result_id' => $r['evaluation_result_id'], 'outcome' => $r['outcome'], 'reason_code' => $r['reason_code'],
                'mechanical' => $mechanical, 'grounding' => $a['GROUNDING']['verdict'] ?? null, 'usefulness' => $a['USEFULNESS']['verdict'] ?? null,
                'critical_failure' => $critical, 'provider_provenance_valid' => $providerProvenanceValid,
                'candidate_digest' => $r['candidate_digest'],
            ];
        }
        sort($t['latencies_ms']);
        $latencies = $t['latencies_ms'];
        unset($t['latencies_ms']);
        $t['latency_ms_max'] = $latencies === [] ? null : max($latencies);
        $t['latency_ms_median'] = $latencies === [] ? null : $latencies[intdiv(count($latencies), 2)];
        $rate = static fn (array $d): ?float => ($d['PASS'] + $d['FAIL']) === 0 ? null : (float) $d['PASS'] / ($d['PASS'] + $d['FAIL']);
        $t['grounding_pass_rate'] = $rate($t['grounding']);
        $t['usefulness_pass_rate'] = $rate($t['usefulness']);
        $t['held_out_mechanical_pass_rate'] = $t['held_out_results'] === 0 ? null : (float) ($t['held_out_results'] - $t['held_out_mechanical_failures']) / $t['held_out_results'];

        if ($reasons !== []) {
            $verdict = self::INCOMPLETE;
        } else {
            if ($t['critical_failures'] > $score['critical_failures_allowed']) {
                $reasons[] = 'CRITICAL_FAILURES:' . $t['critical_failures'];
            }
            if ($t['held_out_mechanical_pass_rate'] === null || $t['held_out_mechanical_pass_rate'] < $score['min_held_out_mechanical_pass_rate']) {
                $reasons[] = 'HELD_OUT_MECHANICAL_PASS_RATE_BELOW_THRESHOLD';
            }
            if ($t['grounding_pass_rate'] === null || $t['grounding_pass_rate'] < $score['min_grounding_pass_rate']) {
                $reasons[] = 'GROUNDING_PASS_RATE_BELOW_THRESHOLD';
            }
            if ($t['usefulness_pass_rate'] === null || $t['usefulness_pass_rate'] < $score['min_usefulness_pass_rate']) {
                $reasons[] = 'USEFULNESS_PASS_RATE_BELOW_THRESHOLD';
            }
            $verdict = $reasons === [] ? self::ACCEPTED : self::REJECTED;
        }

        $report = [
            'schema_version' => '1.0', 'kind' => 'MONITOR_MODEL_EVALUATION_REPORT',
            'evaluation_run_id' => $runId, 'tenant_id' => $tenantId, 'workspace_id' => $workspaceId,
            'execution_grant_id' => $run['execution_grant_id'], 'manifest_sha256' => $run['manifest_sha256'], 'manifest_version' => $run['manifest_version'],
            'selection' => $run['selection'], 'execution_mode' => $run['execution_mode'], 'material_provenance' => $run['material_provenance'],
            'model_policy_digest' => $run['model_policy_digest'], 'prompts' => $prompts, 'scorecard' => $score,
            'totals' => $t, 'by_category' => $byCategory, 'cases' => $cases, 'verdict' => $verdict, 'verdict_reasons' => $reasons,
            'establishes' => $run['execution_mode'] === 'LIVE'
                ? 'How these exact prompts and this model policy behaved on this frozen synthetic manifest, as judged by the recorded human assessments.'
                : 'That the evaluation machinery ran end to end on fixture transport. It says nothing about model behaviour.',
            'does_not_establish' => ['behaviour on real sources or real companies', 'universal correctness — finite passing examples are not guarantees', 'a prompt approval', 'activation of any product path'],
            'approves_prompts' => false, 'activates' => false,
        ];
        $report['report_digest'] = Digest::canonicalDigest($report);

        return Outcome::ok($report);
    }

    /**
     * The check a product prompt approval runs on the evaluation it cites: `evaluation:<run id>` must
     * name a LIVE run in the same scope whose report is ACCEPTED_FOR_OWNER_REVIEW and which ran exactly
     * this prompt, schema and model policy.
     *
     * @return callable(array, string): Outcome
     */
    public static function approvalEvidence(Database $db, string $root): callable
    {
        return static function (array $facts, string $reference) use ($db, $root): Outcome {
            if (preg_match('/^evaluation:([a-f0-9-]{36})$/D', $reference, $m) !== 1) {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_REFERENCE_INVALID');
            }
            $run = $db->one('SELECT manifest_sha256 FROM monitor_evaluation_runs WHERE evaluation_run_id=? AND tenant_id=? AND workspace_id=?', [$m[1], $facts['tenant_id'], $facts['workspace_id']]);
            if ($run === null) {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_FOUND');
            }
            $manifest = EvaluationManifest::forRoot($root);
            if (!$manifest->isOk() || $manifest->value()->sha256 !== $run['manifest_sha256']) {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_MANIFEST_UNAVAILABLE');
            }
            $report = self::build($db, $facts['tenant_id'], $facts['workspace_id'], $m[1], $manifest->value());
            if (!$report->isOk()) {
                return $report;
            }
            $r = $report->value();
            if ($r['execution_mode'] !== 'LIVE') {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_LIVE');
            }
            if ($r['verdict'] !== self::ACCEPTED) {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED');
            }
            $prompt = $r['prompts'][$facts['role']] ?? null;
            if ($prompt === null || $prompt['prompt_sha256'] !== $facts['prompt_sha256'] || $prompt['schema_sha256'] !== $facts['schema_sha256'] || $r['model_policy_digest'] !== $facts['model_policy_digest']) {
                return Outcome::refuse('PROMPT_APPROVAL_EVALUATION_MISMATCH');
            }

            return Outcome::ok(['report_digest' => $r['report_digest']]);
        };
    }
}
