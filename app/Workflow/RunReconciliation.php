<?php
declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Provider\ModelArtifactStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Read interrupted model execution as durable facts, then explicitly adopt only an exact known
 * result. It never invokes a provider, creates authority, reopens terminal history or releases.
 */
final class RunReconciliation
{
    private const STAGES = [
        'DIFFED' => ['name' => 'classifier', 'role' => 'P01_SIGNAL_CLASSIFIER', 'next' => 'ANALYZED'],
        'ANALYZED' => ['name' => 'draft', 'role' => 'BATTLECARD_GENERATOR', 'next' => 'DRAFTED'],
        'DRAFTED' => ['name' => 'critic', 'role' => 'MONITOR_REVIEW_CRITIC', 'next' => 'REVIEW_REQUIRED'],
        'ROUTING_PREPARED' => ['name' => 'routing_critic', 'role' => 'MONITOR_ROUTING_CRITIC', 'next' => 'ROUTING_REVIEW'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly RunJournal $journal,
        private readonly RunArtifacts $runArtifacts,
        private readonly ModelArtifactStore $modelArtifacts,
        private readonly MissionConfiguration $configuration,
        private readonly ModelContextAuthority $context,
        private readonly ?ExecutionAdmission $admission = null,
    ) {}

    public function inspect(array $scope, string $runId): Outcome
    {
        $access = $this->authorize($scope, ['mission.read', 'evidence.read']);
        if (!$access->isOk()) {
            return $access;
        }
        $replay = $this->journal->replay($scope, $runId);
        if (!$replay->isOk()) {
            return $replay;
        }
        $resolved = $this->resolve($scope, $replay->value()['run']);
        if (!$resolved->isOk()) {
            return $resolved;
        }

        return Outcome::ok(self::publicView($resolved->value()));
    }

    public function adopt(array $scope, string $runId, int $expectedRevision): Outcome
    {
        $access = $this->authorize($scope, ['mission.transition', 'evidence.read', 'tool.invoke']);
        if (!$access->isOk()) {
            return $access;
        }

        return $this->journal->exclusiveAtRevision($scope, $runId, $expectedRevision, function (array $run) use ($scope): Outcome {
            $resolved = $this->resolve($scope, $run);
            if (!$resolved->isOk()) {
                return $resolved;
            }
            $state = $resolved->value();
            if (!in_array($state['status'], ['RECOVERABLE_CANDIDATE', 'RECOVERABLE_REFUSAL'], true)) {
                return Outcome::refuse('MONITOR_RECONCILIATION_NOT_RECOVERABLE', [
                    'status' => $state['status'],
                    'reason_code' => $state['reason_code'] ?? null,
                ]);
            }

            if ($state['status'] === 'RECOVERABLE_CANDIDATE') {
                $valid = $this->configuration->validate($scope, $run['definition'], $run['execution_mode']);
                if (!$valid->isOk()) {
                    return $valid;
                }
                $requestProvenance = $state['_request']['provenance'] ?? null;
                if (!is_array($requestProvenance) || !is_array($requestProvenance['context'] ?? null)) {
                    return Outcome::refuse('MONITOR_RECONCILIATION_REQUEST_INVALID');
                }
                $currentContext = $this->context->revalidate($requestProvenance['context']);
                if (!$currentContext->isOk()) {
                    return $currentContext;
                }
                if ($state['role'] === 'MONITOR_ROUTING_CRITIC') {
                    $inputs = $requestProvenance['stage_inputs'] ?? null;
                    if (!is_array($inputs) || !is_array($inputs['routing_package'] ?? null)
                        || !is_array($inputs['prior_candidate']['candidate'] ?? null)) {
                        return Outcome::refuse('MONITOR_RECONCILIATION_REQUEST_INVALID');
                    }
                    $routing = $this->context->validateRoutingPackage(
                        $requestProvenance['context'],
                        $inputs['routing_package'],
                        $inputs['prior_candidate']['candidate'],
                    );
                    if (!$routing->isOk()) {
                        return $routing;
                    }
                }
                if ($run['execution_mode'] === 'NATIVE') {
                    if ($this->admission === null || !is_string($state['execution_grant_id'] ?? null)) {
                        return Outcome::refuse('MONITOR_RECONCILIATION_ADMISSION_UNAVAILABLE');
                    }
                    $p = $state['_request']['provenance'] ?? [];
                    $fresh = $this->admission->check('MODEL_AFTER_RETURN', [
                        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
                        'operator_id' => $scope['operator_id'], 'execution_grant_id' => $state['execution_grant_id'],
                        'purpose' => 'MANUAL_MONITOR', 'mission_id' => $run['mission_id'],
                        'definition_digest' => $run['definition_digest'],
                        'credential_id' => $run['definition']['credential_id'] ?? null,
                        'prompt_approval_id' => $p['prompt_approval_id'] ?? null,
                        'prompt_sha256' => $p['prompt_sha256'] ?? null,
                        'schema_sha256' => $p['schema_sha256'] ?? null,
                        'request_digest' => $state['request_digest'], 'monitor_run_id' => $run['monitor_run_id'],
                    ]);
                    if (!$fresh->isOk()) {
                        return $fresh;
                    }
                }

                $payload = [
                    'role' => $state['role'],
                    'context_digest' => $state['context_digest'],
                    'request_digest' => $state['request_digest'],
                    'candidate_digest' => $state['candidate_digest'],
                    'candidate' => $state['_candidate'],
                    'provider_call_id' => $state['provider_call_id'],
                    'model_attempt_id' => $state['model_attempt_id'],
                    'model_result_id' => $state['model_result_id'],
                    'candidate_artifact_digest' => $state['candidate_artifact_digest'],
                    'execution_mode' => $state['execution_mode'],
                ];
                $saved = $this->runArtifacts->saveCandidate($run, $state['model_stage'], $payload);
                if (!$saved->isOk()) {
                    return $saved;
                }

                $next = $state['next_stage'];
                $cursor = $run['cursor'] + [
                    $state['model_stage'] . '_request_artifact' => $state['request_artifact_digest'],
                    $state['model_stage'] . '_artifact' => $saved->value()['digest'],
                ];
                if ($state['model_stage'] === 'classifier' && ($state['_candidate']['status'] ?? null) === 'NO_MATERIAL_CHANGE') {
                    $next = 'NO_CHANGE';
                } elseif (!str_contains($state['model_stage'], 'critic') && ($state['_candidate']['status'] ?? null) === 'HELD') {
                    $next = 'BLOCKED';
                    $cursor['reason_code'] = 'MONITOR_MODEL_HELD';
                }

                return $this->db->transaction(function (Database $tx) use ($scope, $run, $state, $next, $cursor): Outcome {
                    $reconciliationId = $this->appendRecord($tx, $scope, $run, $state, 'ADOPTED_CANDIDATE', 'MODEL_CANDIDATE_DURABLE', null);
                    $advanced = $this->journal->advance($scope, $run, $next, $cursor, 'MODEL_CANDIDATE_RECONCILED');
                    if (!$advanced->isOk()) {
                        return $advanced;
                    }

                    return Outcome::ok([
                        'reconciliation_id' => $reconciliationId,
                        'disposition' => 'ADOPTED_CANDIDATE',
                        'run' => $advanced->value(),
                        'will_call_provider' => false,
                        'will_release' => false,
                    ]);
                });
            }

            $reason = (string) $state['reason_code'];
            return $this->db->transaction(function (Database $tx) use ($scope, $run, $state, $reason): Outcome {
                $reconciliationId = $this->appendRecord($tx, $scope, $run, $state, 'ADOPTED_REFUSAL', $reason, null);
                $advanced = $this->journal->advance(
                    $scope,
                    $run,
                    'BLOCKED',
                    $run['cursor'] + [
                        $state['model_stage'] . '_request_artifact' => $state['request_artifact_digest'],
                        'reason_code' => $reason,
                    ],
                    'MODEL_REFUSAL_RECONCILED',
                );
                if (!$advanced->isOk()) {
                    return $advanced;
                }

                return Outcome::ok([
                    'reconciliation_id' => $reconciliationId,
                    'disposition' => 'ADOPTED_REFUSAL',
                    'run' => $advanced->value(),
                    'will_call_provider' => false,
                    'will_release' => false,
                ]);
            });
        });
    }

    public function markUnresolved(array $scope, string $runId, int $expectedRevision, string $operatorReason): Outcome
    {
        $access = $this->authorize($scope, ['mission.transition', 'evidence.read']);
        if (!$access->isOk()) {
            return $access;
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $operatorReason) !== 1) {
            return Outcome::refuse('MONITOR_RECONCILIATION_REASON_INVALID');
        }

        return $this->journal->exclusiveAtRevision($scope, $runId, $expectedRevision, function (array $run) use ($scope, $operatorReason): Outcome {
            $resolved = $this->resolve($scope, $run);
            if (!$resolved->isOk()) {
                return $resolved;
            }
            $state = $resolved->value();
            if (in_array($state['status'], ['RECOVERABLE_CANDIDATE', 'RECOVERABLE_REFUSAL'], true)) {
                return Outcome::refuse('MONITOR_RECONCILIATION_RESULT_IS_RECOVERABLE');
            }
            if (($state['status'] ?? null) !== 'UNRESOLVED') {
                return Outcome::refuse('MONITOR_RECONCILIATION_NOT_UNRESOLVED', [
                    'status' => $state['status'] ?? 'UNKNOWN',
                ]);
            }

            return $this->db->transaction(function (Database $tx) use ($scope, $run, $state, $operatorReason): Outcome {
                $existing = $tx->one(
                    "SELECT reconciliation_id FROM monitor_run_reconciliations
                     WHERE monitor_run_id=? AND run_revision=? AND disposition='UNRESOLVED'",
                    [$run['monitor_run_id'], $run['revision']],
                );
                if ($existing !== null) {
                    return Outcome::ok([
                        'reconciliation_id' => $existing['reconciliation_id'],
                        'disposition' => 'UNRESOLVED',
                        'run_revision' => (int) $run['revision'],
                        'will_call_provider' => false,
                    ]);
                }

                $id = $this->appendRecord(
                    $tx,
                    $scope,
                    $run,
                    $state,
                    'UNRESOLVED',
                    (string) ($state['reason_code'] ?? 'MONITOR_RECONCILIATION_UNRESOLVED'),
                    $operatorReason,
                );

                return Outcome::ok([
                    'reconciliation_id' => $id,
                    'disposition' => 'UNRESOLVED',
                    'run_revision' => (int) $run['revision'],
                    'will_call_provider' => false,
                ]);
            });
        });
    }

    /** @return Outcome<array<string,mixed>> */
    private function resolve(array $scope, array $run): Outcome
    {
        $spec = self::STAGES[$run['stage']] ?? null;
        if ($spec === null) {
            return Outcome::refuse('MONITOR_RECONCILIATION_NOT_MODEL_STAGE');
        }

        $base = [
            'monitor_run_id' => $run['monitor_run_id'], 'run_revision' => (int) $run['revision'],
            'stage' => $run['stage'], 'model_stage' => $spec['name'], 'role' => $spec['role'],
            'next_stage' => $spec['next'], 'will_call_provider' => false, 'will_release' => false,
        ];
        $idempotencyKey = 'monitor.model.' . $run['monitor_run_id'] . '.' . $spec['name'];
        $tool = $this->db->one(
            'SELECT tool_request_id,status,normalized_input_ref_safe
             FROM tool_requests WHERE tenant_id=? AND workspace_id=? AND idempotency_key=?',
            [$scope['tenant_id'], $scope['workspace_id'], $idempotencyKey],
        );
        if ($tool === null) {
            return Outcome::ok($base + [
                'status' => 'NO_DISPATCH_RECORDED',
                'reason_code' => 'MONITOR_RECONCILIATION_DISPATCH_NOT_RECORDED',
                'retry_safe' => true,
            ]);
        }
        $base['tool_request_id'] = $tool['tool_request_id'];
        if (preg_match('/^model-request:\/\/([a-f0-9]{64})$/D', (string) $tool['normalized_input_ref_safe'], $m) !== 1) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_REQUEST_REFERENCE_INVALID',
            ]);
        }
        $requestDigest = $m[1];
        $base['request_digest'] = $requestDigest;

        $attempt = $this->db->one(
            'SELECT * FROM provider_model_attempts
             WHERE tool_request_id=? AND tenant_id=? AND workspace_id=? AND mission_id=?',
            [$tool['tool_request_id'], $scope['tenant_id'], $scope['workspace_id'], $run['mission_id']],
        );
        if ($attempt === null) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_ATTEMPT_NOT_RECORDED',
            ]);
        }
        $base += [
            'model_attempt_id' => $attempt['model_attempt_id'],
            'execution_mode' => $attempt['execution_mode'],
            'context_digest' => $attempt['context_digest'],
        ];
        $expectedAttemptMode = $run['execution_mode'] === 'NATIVE' ? 'LIVE' : 'FIXTURE';
        if ($attempt['execution_mode'] !== $expectedAttemptMode) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_EXECUTION_MODE_MISMATCH',
            ]);
        }
        if (!hash_equals((string) $attempt['request_digest'], $requestDigest)) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_ATTEMPT_MISMATCH',
            ]);
        }

        $binding = $this->db->one(
            'SELECT monitor_run_id,run_revision,model_stage,request_artifact_digest,request_digest
             FROM monitor_model_attempt_bindings WHERE model_attempt_id=?',
            [$attempt['model_attempt_id']],
        );
        $requestKey = $spec['name'] . '_request_artifact';
        $requestArtifactDigest = $binding['request_artifact_digest'] ?? ($run['cursor'][$requestKey] ?? null);
        if ($binding !== null
            && ($binding['monitor_run_id'] !== $run['monitor_run_id']
                || (int) $binding['run_revision'] !== (int) $run['revision']
                || $binding['model_stage'] !== $spec['name']
                || !hash_equals((string) $binding['request_digest'], $requestDigest))) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_RUN_BINDING_MISMATCH',
            ]);
        }
        if (isset($run['cursor'][$requestKey]) && $binding !== null
            && !hash_equals((string) $run['cursor'][$requestKey], (string) $binding['request_artifact_digest'])) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_REQUEST_ARTIFACT_MISMATCH',
            ]);
        }
        if (!is_string($requestArtifactDigest) || preg_match('/^[a-f0-9]{64}$/D', $requestArtifactDigest) !== 1) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_REQUEST_NOT_RECORDED',
            ]);
        }
        $base['request_artifact_digest'] = $requestArtifactDigest;
        $request = $this->runArtifacts->read($run, $requestArtifactDigest, $spec['name'] . '_request');
        if (!$request->isOk()) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED',
                'reason_code' => 'MONITOR_RECONCILIATION_REQUEST_ARTIFACT_UNAVAILABLE',
            ]);
        }
        $inspected = $request->value();
        if (($inspected['request_digest'] ?? null) !== $requestDigest
            || !is_array($inspected['provenance'] ?? null)
            || ($inspected['provenance']['role'] ?? null) !== $spec['role']
            || ($inspected['provenance']['context_digest'] ?? null) !== $attempt['context_digest']) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_REQUEST_MISMATCH',
            ]);
        }
        $base['_request'] = $inspected;

        $purpose = $this->db->one('SELECT * FROM provider_attempt_purposes WHERE model_attempt_id=?', [$attempt['model_attempt_id']]);
        if ($run['execution_mode'] === 'NATIVE') {
            if ($purpose === null || $purpose['execution_purpose'] !== 'MANUAL_MONITOR'
                || $purpose['monitor_run_id'] !== $run['monitor_run_id']) {
                return Outcome::ok($base + [
                    'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_PURPOSE_MISMATCH',
                ]);
            }
            $base['execution_grant_id'] = $purpose['execution_grant_id'];
            $base['execution_purpose'] = $purpose['execution_purpose'];
        }

        $result = $this->db->one('SELECT * FROM provider_model_results WHERE model_attempt_id=?', [$attempt['model_attempt_id']]);
        if ($result === null) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_RESULT_NOT_RECORDED',
            ]);
        }
        if ($result['tool_request_id'] !== $tool['tool_request_id']) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_RESULT_REQUEST_MISMATCH',
            ]);
        }
        $base += [
            'model_result_id' => $result['model_result_id'],
            'provider_call_id' => $result['provider_call_id'],
            'candidate_digest' => $result['candidate_digest'],
        ];

        $toolOutcome = $this->db->one(
            'SELECT tool_execution_outcome_id,outcome_status,result_digest,error_code
             FROM tool_execution_outcomes WHERE tool_request_id=? ORDER BY completed_at DESC LIMIT 1',
            [$tool['tool_request_id']],
        );
        if ($toolOutcome === null) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_TOOL_OUTCOME_NOT_RECORDED',
            ]);
        }
        $base['tool_execution_outcome_id'] = $toolOutcome['tool_execution_outcome_id'];
        $base['tool_outcome_status'] = $toolOutcome['outcome_status'];
        $base['tool_error_code'] = $toolOutcome['error_code'];

        $call = $result['provider_call_id'] === null ? null : $this->db->one(
            'SELECT provider_call_id,outcome,failure_code,correlation_id
             FROM provider_call_records WHERE provider_call_id=? AND tenant_id=? AND workspace_id=?',
            [$result['provider_call_id'], $scope['tenant_id'], $scope['workspace_id']],
        );
        if ($call === null || $call['correlation_id'] !== $run['monitor_run_id']) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_PROVIDER_CALL_MISMATCH',
            ]);
        }

        if ($result['outcome'] === 'REFUSED') {
            $reason = $result['failure_code'];
            if (!is_string($reason) || preg_match('/^[A-Z][A-Z0-9_]{0,95}$/D', $reason) !== 1) {
                return Outcome::ok($base + [
                    'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_REFUSAL_INVALID',
                ]);
            }
            $failedTransform = $toolOutcome['outcome_status'] === 'FAILED'
                && (string) ($toolOutcome['error_code'] ?? '') === $reason
                && ($call['outcome'] === 'SUCCEEDED' || (string) ($call['failure_code'] ?? '') === $reason);
            $postTransformRefusal = $toolOutcome['outcome_status'] === 'SUCCEEDED'
                && $call['outcome'] === 'SUCCEEDED';
            if (!$failedTransform && !$postTransformRefusal) {
                return Outcome::ok($base + [
                    'status' => 'UNRESOLVED',
                    'reason_code' => 'MONITOR_RECONCILIATION_REFUSAL_PROVENANCE_INVALID',
                ]);
            }
            if (in_array($reason, ['MODEL_NETWORK_OUTCOME_UNKNOWN','MODEL_TIMEOUT','MODEL_ADAPTER_EXCEPTION'], true)) {
                return Outcome::ok($base + [
                    'status' => 'UNRESOLVED',
                    'reason_code' => $reason,
                    'provider_call_outcome' => $call['outcome'],
                ]);
            }

            return Outcome::ok($base + [
                'status' => 'RECOVERABLE_REFUSAL',
                'reason_code' => $reason,
                'provider_call_outcome' => $call['outcome'],
            ]);
        }

        if ($result['outcome'] !== 'CANDIDATE'
            || $toolOutcome['outcome_status'] !== 'SUCCEEDED'
            || !is_string($result['candidate_digest'])
            || !hash_equals((string) $toolOutcome['result_digest'], (string) $result['candidate_digest'])
            || $call['outcome'] !== 'SUCCEEDED') {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_CANDIDATE_PROVENANCE_INVALID',
            ]);
        }

        $artifact = $this->modelArtifacts->read(
            $scope['tenant_id'],
            $scope['workspace_id'],
            $run['mission_id'],
            $attempt['model_attempt_id'],
            $requestDigest,
        );
        if (!$artifact->isOk()) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => $artifact->reasonCode(),
            ]);
        }
        $a = $artifact->value();
        if (!hash_equals((string) $result['candidate_digest'], (string) $a['candidate_digest'])) {
            return Outcome::ok($base + [
                'status' => 'UNRESOLVED', 'reason_code' => 'MONITOR_RECONCILIATION_CANDIDATE_DIGEST_MISMATCH',
            ]);
        }

        return Outcome::ok($base + [
            'status' => 'RECOVERABLE_CANDIDATE',
            'reason_code' => 'MODEL_CANDIDATE_DURABLE',
            'candidate_artifact_digest' => $a['artifact_digest'],
            '_candidate' => $a['candidate'],
        ]);
    }

    private function appendRecord(
        Database $tx,
        array $scope,
        array $run,
        array $state,
        string $disposition,
        string $observedReason,
        ?string $operatorReason,
    ): string {
        $existing = $tx->one(
            'SELECT reconciliation_id FROM monitor_run_reconciliations
             WHERE monitor_run_id=? AND run_revision=? AND disposition=?',
            [$run['monitor_run_id'], $run['revision'], $disposition],
        );
        if ($existing !== null) {
            return (string) $existing['reconciliation_id'];
        }

        $id = Ids::new();
        $tx->insert('monitor_run_reconciliations', [
            'reconciliation_id' => $id,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'monitor_run_id' => $run['monitor_run_id'],
            'run_revision' => $run['revision'],
            'model_stage' => $state['model_stage'],
            'disposition' => $disposition,
            'tool_request_id' => $state['tool_request_id'] ?? null,
            'model_attempt_id' => $state['model_attempt_id'] ?? null,
            'model_result_id' => $state['model_result_id'] ?? null,
            'request_digest' => $state['request_digest'] ?? null,
            'candidate_digest' => $state['candidate_digest'] ?? null,
            'artifact_digest' => $state['candidate_artifact_digest'] ?? null,
            'observed_reason_code' => $observedReason,
            'operator_reason_code' => $operatorReason,
            'recorded_by_operator_id' => $scope['operator_id'],
            'recorded_at' => $this->clock->now(),
        ]);

        return $id;
    }

    private function authorize(array $scope, array $permissions): Outcome
    {
        foreach (['tenant_id','workspace_id','operator_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return Outcome::refuse('MONITOR_RECONCILIATION_SCOPE_REQUIRED');
            }
        }
        $access = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($access->evaluate(
                'OPERATOR',
                $scope['operator_id'],
                $scope['tenant_id'],
                $scope['workspace_id'],
                $permission,
                'MONITOR_RECONCILIATION',
            )['decision'] !== 'ALLOW') {
                return Outcome::refuse('MONITOR_RECONCILIATION_ACCESS_DENIED');
            }
        }

        return Outcome::ok();
    }

    /** Remove private candidate/request material from the operator-facing inspection result. */
    private static function publicView(array $state): array
    {
        foreach (array_keys($state) as $key) {
            if (str_starts_with((string) $key, '_')) {
                unset($state[$key]);
            }
        }

        return $state;
    }
}
