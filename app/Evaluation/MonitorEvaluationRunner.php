<?php
declare(strict_types=1);
namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;
use Allfather\Loopia\Provider\{ModelExecutor,ModelPolicy,ModelRequestBuilder};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Workflow\ExecutionGrantStore;

/**
 * Runs named prompts against a frozen synthetic case manifest, under an admitted MODEL_EVALUATION
 * grant, through the product's own request construction, M06/M01 dispatch, parser and candidate
 * checks. It has no source transport and no release service, so it can neither fetch nor release.
 *
 * A run is recorded before its first call; each case appends exactly one result, whatever happened.
 * A crashed case is not retried with a fresh identity: its governed idempotency key replays, and an
 * attempt whose outcome is unknown is recorded as such. Candidates are stored privately, by digest.
 * Whether a candidate is grounded and useful is decided by people, in `assess()` — never by a model.
 */
final class MonitorEvaluationRunner
{
    public const SELECTIONS = ['DEVELOPMENT', 'HELD_OUT', 'ALL'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ExecutionGrantStore $grants,
        private readonly PromptSelectionResolver $prompts,
        private readonly ModelExecutor $executor,
        private readonly EvaluationCaseContext $cases,
        private readonly EvidenceStore $store,
        private readonly bool $liveTransport,
    ) {}

    /**
     * Record a run for this grant and selection. Nothing is sent yet.
     *
     * @param array{author: array<string, mixed>, critic: array<string, mixed>} $governance existing
     *        M04 assignments and M03/M06 tool bindings for the evaluation mission, as in a mission configuration
     */
    public function start(array $scope, string $grantId, string $selection, array $governance, string $credentialId): Outcome
    {
        if (!$this->may($scope, ['tool.invoke', 'evidence.read'])) {
            return Outcome::refuse('EVALUATION_ACCESS_DENIED');
        }
        if (!in_array($selection, self::SELECTIONS, true)) {
            return Outcome::refuse('EVALUATION_SELECTION_INVALID');
        }
        $grant = $this->grants->find($scope['tenant_id'], $scope['workspace_id'], $grantId);
        if ($grant === null || $grant['purpose'] !== 'MODEL_EVALUATION') {
            return Outcome::refuse('EVALUATION_GRANT_NOT_FOUND');
        }
        if ($this->grants->status($grantId) !== 'ADMITTED') {
            return Outcome::refuse('EXECUTION_GRANT_NOT_ADMITTED');
        }
        $manifest = $this->cases->manifest();
        if ($grant['evaluation_manifest_sha256'] !== $manifest->sha256 || !$manifest->unchanged()) {
            return Outcome::refuse('EVALUATION_MANIFEST_NOT_GRANTED');
        }
        if ($grant['definition']['credential_id'] !== $credentialId) {
            return Outcome::refuse('EVALUATION_CREDENTIAL_NOT_GRANTED');
        }
        $ids = $manifest->caseIds($selection);
        foreach ($ids as $id) {
            $role = $manifest->case($id)['role'];
            if (!$this->prompts->select($role, $grant)->isOk()) {
                return Outcome::refuse('EVALUATION_ROLE_NOT_APPROVED', ['role' => $role]);
            }
        }
        $mission = $this->governanceMission($scope, $governance);
        if (!$mission->isOk()) {
            return $mission;
        }
        $row = [
            'evaluation_run_id' => Ids::new(), 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'execution_grant_id' => $grantId, 'mission_id' => $mission->value(), 'manifest_sha256' => $manifest->sha256,
            'manifest_version' => $manifest->data['manifest_version'], 'selection' => $selection,
            'selected_case_ids_json' => json_encode($ids, JSON_THROW_ON_ERROR), 'model_policy_digest' => ModelPolicy::digest(),
            'execution_mode' => $this->liveTransport ? 'LIVE' : 'FIXTURE', 'material_provenance' => 'SYNTHETIC_EVALUATION_CASE',
            'initiated_by_operator_id' => $scope['operator_id'], 'created_at' => $this->clock->now(),
        ];
        $this->db->insert('monitor_evaluation_runs', $row);

        return Outcome::ok(['evaluation_run_id' => $row['evaluation_run_id'], 'cases' => count($ids), 'execution_mode' => $row['execution_mode'], 'approves_prompts' => false]);
    }

    /**
     * The exact requests the run would send, for inspection before any funded call. Sends nothing.
     *
     * @return Outcome<list<array{case_id:string,role:string,request_digest:string,request:array}>>
     */
    public function preview(array $scope, string $runId, array $governance): Outcome
    {
        if (!$this->may($scope, ['tool.invoke', 'evidence.read'])) {
            return Outcome::refuse('EVALUATION_ACCESS_DENIED');
        }
        $run = $this->run($scope, $runId);
        if ($run === null) {
            return Outcome::refuse('EVALUATION_RUN_NOT_FOUND');
        }
        $mission = $this->governanceMission($scope, $governance);
        if (!$mission->isOk() || $mission->value() !== $run['mission_id']) {
            return Outcome::refuse('EVALUATION_GOVERNANCE_MISMATCH');
        }
        $out = [];
        foreach (json_decode($run['selected_case_ids_json'], true, 8, JSON_THROW_ON_ERROR) as $caseId) {
            $request = $this->request($scope, $run, $caseId);
            if (!$request->isOk()) {
                return $request;
            }
            $out[] = ['case_id' => $caseId, 'role' => $this->cases->manifest()->case($caseId)['role'], 'request_digest' => $request->value()->digest(), 'request' => $request->value()->inspect()];
        }

        return Outcome::ok($out);
    }

    /** Execute every selected case that has no result yet. Each case is one bounded attempt. */
    public function execute(array $scope, string $runId, array $governance, string $credentialId): Outcome
    {
        if (!$this->may($scope, ['tool.invoke', 'evidence.read'])) {
            return Outcome::refuse('EVALUATION_ACCESS_DENIED');
        }
        $run = $this->run($scope, $runId);
        if ($run === null) {
            return Outcome::refuse('EVALUATION_RUN_NOT_FOUND');
        }
        $mission = $this->governanceMission($scope, $governance);
        if (!$mission->isOk() || $mission->value() !== $run['mission_id']) {
            return Outcome::refuse('EVALUATION_GOVERNANCE_MISMATCH');
        }
        $done = array_column($this->db->all('SELECT case_id FROM monitor_evaluation_results WHERE evaluation_run_id=?', [$runId]), 'case_id');
        $recorded = 0;
        foreach (json_decode($run['selected_case_ids_json'], true, 8, JSON_THROW_ON_ERROR) as $caseId) {
            if (in_array($caseId, $done, true)) {
                continue;
            }
            $result = $this->executeCase($scope, $run, $caseId, $governance, $credentialId);
            if (!$result->isOk()) {
                return $result;
            }
            ++$recorded;
        }

        return Outcome::ok(['evaluation_run_id' => $runId, 'recorded' => $recorded, 'approves_prompts' => false, 'activates' => false]);
    }

    /** Read the computed report under the same private evidence-read boundary as preview. */
    public function report(array $scope, string $runId): Outcome
    {
        if (!$this->may($scope, ['evidence.read'])) {
            return Outcome::refuse('EVALUATION_ACCESS_DENIED');
        }

        return EvaluationReport::build(
            $this->db, $scope['tenant_id'], $scope['workspace_id'], $runId, $this->cases->manifest()
        );
    }

    /**
     * One human judgement on one result. Grounding and usefulness are separate dimensions; a critical
     * failure (scope, unauthorized action, privacy) is flagged explicitly and rejects the run.
     */
    public function assess(array $scope, string $resultId, string $dimension, string $verdict, bool $critical, ?string $criticalCategory, string $notes): Outcome
    {
        if (!$this->may($scope, ['company_signal.review', 'evidence.read'])) {
            return Outcome::refuse('EVALUATION_ASSESSMENT_DENIED');
        }
        $result = $this->db->one('SELECT r.evaluation_result_id,r.outcome FROM monitor_evaluation_results r JOIN monitor_evaluation_runs u ON u.evaluation_run_id=r.evaluation_run_id WHERE r.evaluation_result_id=? AND u.tenant_id=? AND u.workspace_id=?', [$resultId, $scope['tenant_id'], $scope['workspace_id']]);
        if ($result === null) {
            return Outcome::refuse('EVALUATION_RESULT_NOT_FOUND');
        }
        if (!in_array($dimension, ['GROUNDING', 'USEFULNESS'], true) || !in_array($verdict, ['PASS', 'FAIL', 'NOT_APPLICABLE'], true)) {
            return Outcome::refuse('EVALUATION_ASSESSMENT_INVALID');
        }
        if ($critical !== ($criticalCategory !== null) || ($criticalCategory !== null && !in_array($criticalCategory, ['SCOPE', 'UNAUTHORIZED_ACTION', 'PRIVACY'], true))) {
            return Outcome::refuse('EVALUATION_ASSESSMENT_INVALID');
        }
        $notes = trim($notes);
        if (mb_strlen($notes) < 10 || strlen($notes) > 2000) {
            return Outcome::refuse('EVALUATION_ASSESSMENT_NOTES_REQUIRED');
        }
        try {
            $this->db->insert('monitor_evaluation_assessments', [
                'evaluation_assessment_id' => Ids::new(), 'evaluation_result_id' => $resultId, 'dimension' => $dimension,
                'verdict' => $verdict, 'critical_failure' => $critical ? 1 : 0, 'critical_category' => $criticalCategory,
                'assessor_operator_id' => $scope['operator_id'], 'notes_safe' => $notes, 'recorded_at' => $this->clock->now(),
            ]);
        } catch (\PDOException $error) {
            return Database::classify($error, 'EVALUATION_ASSESSMENT_ALREADY_RECORDED');
        }

        return Outcome::ok(['evaluation_result_id' => $resultId, 'dimension' => $dimension, 'verdict' => $verdict]);
    }

    private function executeCase(array $scope, array $run, string $caseId, array $governance, string $credentialId): Outcome
    {
        $case = $this->cases->manifest()->case($caseId);
        $request = $this->request($scope, $run, $caseId);
        $base = ['evaluation_result_id' => Ids::new(), 'evaluation_run_id' => $run['evaluation_run_id'], 'case_id' => $caseId, 'split' => $case['split'], 'category' => $case['category'], 'role' => $case['role'], 'critical' => $case['critical'] ? 1 : 0, 'recorded_at' => $this->clock->now()];
        if (!$request->isOk()) {
            return $this->record($base + ['request_digest' => null], 'REFUSED', $request->reasonCode(), null, $case);
        }
        $r = $request->value();
        $critic = in_array($case['role'], ['MONITOR_REVIEW_CRITIC', 'MONITOR_ROUTING_CRITIC'], true);
        $command = array_merge($governance[$critic ? 'critic' : 'author'], [
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'mission_id' => $run['mission_id'],
            'calling_actor_type' => 'OPERATOR', 'calling_actor_id' => $scope['operator_id'], 'requested_action_class' => 'ANALYZE',
            'idempotency_key' => 'monitor.eval.' . $run['evaluation_run_id'] . '.' . $caseId, 'correlation_id' => $run['evaluation_run_id'],
            'request_purpose_safe' => 'Bounded Monitor evaluation case',
        ]);
        $result = $this->executor->execute($command, $r, $credentialId, ['evaluation_manifest_sha256' => $run['manifest_sha256'], 'evaluation_run_id' => $run['evaluation_run_id']]);
        $attempt = isset($result['dispatch']['tool_request_id']) ? $this->db->one('SELECT model_attempt_id FROM provider_model_attempts WHERE tool_request_id=?', [$result['dispatch']['tool_request_id']]) : null;
        $row = $base + ['request_digest' => $r->digest(), 'model_attempt_id' => $attempt['model_attempt_id'] ?? null, 'provider_call_id' => $result['provider_call_id'] ?? null];
        if (($result['kind'] ?? '') === 'MODEL_REPLAY') {
            return $this->record($row, 'REFUSED', 'EVALUATION_ATTEMPT_REQUIRES_RECONCILIATION', null, $case);
        }
        if (($result['kind'] ?? '') !== 'MODEL_CANDIDATE') {
            return $this->record($row, 'REFUSED', $result['reason_code'] ?? 'MODEL_REFUSED', null, $case);
        }
        $stored = $this->store->put(Digest::canonicalize(['evaluation_run_id' => $run['evaluation_run_id'], 'case_id' => $caseId, 'request_digest' => $r->digest(), 'candidate' => $result['candidate']]));
        if (!$stored->isOk()) {
            return $stored;
        }

        return $this->record($row + ['candidate_digest' => $result['candidate_digest'], 'candidate_artifact_digest' => $stored->value()['digest']], 'CANDIDATE', null, $result['candidate'], $case);
    }

    private function record(array $row, string $outcome, ?string $reason, ?array $candidate, array $case): Outcome
    {
        $mechanical = EvaluationManifest::mechanical($case, $outcome, $candidate, $reason);
        $this->db->insert('monitor_evaluation_results', $row + [
            'outcome' => $outcome, 'reason_code' => $reason, 'mechanical_json' => json_encode($mechanical, JSON_THROW_ON_ERROR),
            'mechanical_pass' => $mechanical['pass'] ? 1 : 0,
        ] + ['model_attempt_id' => null, 'provider_call_id' => null, 'candidate_digest' => null, 'candidate_artifact_digest' => null]);

        return Outcome::ok(['evaluation_result_id' => $row['evaluation_result_id'], 'outcome' => $outcome]);
    }

    private function request(array $scope, array $run, string $caseId): Outcome
    {
        $grant = $this->grants->find($scope['tenant_id'], $scope['workspace_id'], $run['execution_grant_id']);
        if ($grant === null) {
            return Outcome::refuse('EVALUATION_GRANT_NOT_FOUND');
        }
        $case = $this->cases->manifest()->case($caseId);
        if ($case === null) {
            return Outcome::refuse('EVALUATION_CASE_NOT_IN_MANIFEST');
        }
        $prompt = $this->prompts->select($case['role'], $grant);
        if (!$prompt->isOk()) {
            return $prompt;
        }
        $context = $this->cases->build($scope, $run['mission_id'], $caseId);
        if (!$context->isOk()) {
            return $context;
        }
        $inputs = $this->cases->stageInputs($context->value(), $caseId);
        if (!$inputs->isOk()) {
            return $inputs;
        }
        $options = ['max_output_tokens' => EvaluationCaseContext::OUTPUT_TOKENS];
        if ($inputs->value() !== null) {
            $options['stage_inputs'] = $inputs->value();
        }

        return ModelRequestBuilder::prepare($prompt->value(), $context->value(), $options, $this->prompts);
    }

    private function run(array $scope, string $runId): ?array
    {
        return $this->db->one('SELECT * FROM monitor_evaluation_runs WHERE evaluation_run_id=? AND tenant_id=? AND workspace_id=?', [$runId, $scope['tenant_id'], $scope['workspace_id']]);
    }

    private function governanceMission(array $scope, array $governance): Outcome
    {
        if (array_keys($governance) !== ['author', 'critic']) {
            return Outcome::refuse('EVALUATION_GOVERNANCE_INVALID');
        }
        $mission = null;
        foreach ($governance as $command) {
            if (!is_array($command) || array_diff(array_keys($command), ['tenant_id', 'workspace_id', 'mission_id', 'assignment_id', 'valkyrie_id', 'capability_contract_id', 'governed_tool_id', 'governed_tool_operation_id', 'calling_actor_type', 'calling_actor_id', 'required_entitlement_permission', 'requested_action_class']) !== []
                || ($command['tenant_id'] ?? null) !== $scope['tenant_id'] || ($command['workspace_id'] ?? null) !== $scope['workspace_id'] || !is_string($command['mission_id'] ?? null)) {
                return Outcome::refuse('EVALUATION_GOVERNANCE_INVALID');
            }
            if ($mission !== null && $mission !== $command['mission_id']) {
                return Outcome::refuse('EVALUATION_GOVERNANCE_INVALID');
            }
            $mission = $command['mission_id'];
        }
        if ($governance['author']['valkyrie_id'] === $governance['critic']['valkyrie_id']) {
            return Outcome::refuse('EVALUATION_REVIEW_SEPARATION_REQUIRED');
        }
        $row = $this->db->one("SELECT 1 FROM missions WHERE mission_id=? AND tenant_id=? AND workspace_id=? AND status='ACTIVE'", [$mission, $scope['tenant_id'], $scope['workspace_id']]);

        return $row === null ? Outcome::refuse('EVALUATION_MISSION_NOT_ACTIVE') : Outcome::ok($mission);
    }

    private function may(array $scope, array $permissions): bool
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return false;
            }
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MODEL_EVALUATION')['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }
}
