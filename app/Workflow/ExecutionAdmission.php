<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\ModelPolicy;

/**
 * Is this deployment permitted to use the existing machinery for this exact operation, right now?
 *
 * A VALIDATOR, NOT A NEW AUTHORITY PLANE. M02 permissions, M03 tool rights, M04 missions and
 * assignments, M06/M01 dispatch and M07/M08/M10 release all still run and still refuse on their own.
 * This class answers the question none of them asks: whether an owner admitted this deployment to
 * perform this purpose with these prompts, this model policy, this credential, this source and
 * target, inside this window and budget, on this application release.
 *
 * It is asked at every point where going further would reach the world or rely on a result:
 * before a capture, before reserving a model attempt, immediately before dispatch, after the
 * provider returns and before the candidate is accepted, and before each human-approved release.
 * Revocation during an in-flight call cannot recall bytes already sent; it stops the response from
 * becoming an accepted candidate, and the attempt's receipt stays truthful.
 *
 * Every answer is recorded, allow or refuse, in `monitor_admission_decisions`.
 */
final class ExecutionAdmission
{
    /** checkpoint => [purposes it serves, budget kind it must still have room for] */
    public const CHECKPOINTS = [
        'SOURCE_CAPTURE' => [['MANUAL_MONITOR', 'SCHEDULED_OBSERVATION'], 'SOURCE_CAPTURE'],
        // Re-check after the budget reservation and M06 gates, immediately before DNS/HTTP can
        // begin. It has no budget test of its own because SOURCE_CAPTURE already spent the unit.
        'SOURCE_BEFORE_DISPATCH' => [['MANUAL_MONITOR', 'SCHEDULED_OBSERVATION'], null],
        'MODEL_BEFORE_RESERVE' => [['MANUAL_MONITOR', 'MODEL_EVALUATION'], 'MODEL_ATTEMPT'],
        'MODEL_BEFORE_DISPATCH' => [['MANUAL_MONITOR', 'MODEL_EVALUATION'], null],
        'MODEL_AFTER_RETURN' => [['MANUAL_MONITOR', 'MODEL_EVALUATION'], null],
        'RELEASE_P01' => [['MANUAL_MONITOR'], null],
        'RELEASE_ROUTING' => [['MANUAL_MONITOR'], null],
    ];

    private const MODEL_CHECKPOINTS = ['MODEL_BEFORE_RESERVE', 'MODEL_BEFORE_DISPATCH', 'MODEL_AFTER_RETURN'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ExecutionGrantStore $grants,
        private readonly PromptApprovalStore $approvals,
        private readonly OwnerDecisionRegistry $decisions,
        private readonly string|\Closure $applicationFingerprint,
    ) {}

    /** The running release's source fingerprint — computed only when a check needs it, then kept. */
    private function fingerprint(): string
    {
        if ($this->applicationFingerprint instanceof \Closure) {
            $this->resolvedFingerprint ??= (string) ($this->applicationFingerprint)();

            return $this->resolvedFingerprint;
        }

        return $this->applicationFingerprint;
    }

    private ?string $resolvedFingerprint = null;

    public function grants(): ExecutionGrantStore
    {
        return $this->grants;
    }

    /**
     * @param array<string, mixed> $facts tenant_id, workspace_id, operator_id, execution_grant_id and
     *        whatever the checkpoint binds: mission_id, definition_digest, monitored_source_id,
     *        capture_url, target_org_role, credential_id, prompt_approval_id, prompt_sha256,
     *        schema_sha256, evaluation_manifest_sha256, monitor_run_id, request_digest.
     */
    public function check(string $checkpoint, array $facts): Outcome
    {
        $grantId = is_string($facts['execution_grant_id'] ?? null) ? $facts['execution_grant_id'] : null;
        $reason = $this->evaluate($checkpoint, $facts, $grantId);
        // An allow that could not be recorded is not an allow: the trail is part of the contract.
        if (!$this->record($checkpoint, $facts, $grantId, $reason) && $reason === null) {
            $reason = 'EXECUTION_ADMISSION_RECORD_FAILED';
        }

        return $reason === null
            ? Outcome::ok(['execution_grant_id' => $grantId, 'checkpoint' => $checkpoint])
            : Outcome::refuse($reason, ['checkpoint' => $checkpoint]);
    }

    /**
     * Spend one unit of a grant's budget, inside the caller's transaction and under a lock on the
     * grant row, so two concurrent reservations cannot both take the last unit. Spent stays spent.
     */
    public function reserve(Database $tx, string $grantId, string $kind, string $referenceId, ?string $runId): Outcome
    {
        $grant = $tx->one('SELECT definition_json FROM monitor_execution_grants WHERE execution_grant_id=? FOR UPDATE', [$grantId]);
        if ($grant === null) {
            return Outcome::refuse('EXECUTION_GRANT_NOT_FOUND');
        }
        $limits = json_decode($grant['definition_json'], true, 32, JSON_THROW_ON_ERROR)['limits'];
        $limit = $kind === 'MODEL_ATTEMPT' ? $limits['max_model_attempts'] : ($kind === 'SOURCE_CAPTURE' ? $limits['max_source_captures'] : -1);
        if ($limit < 0) {
            return Outcome::refuse('EXECUTION_GRANT_BUDGET_KIND_INVALID');
        }
        $used = (int) $tx->scalar('SELECT COUNT(*) FROM monitor_grant_consumptions WHERE execution_grant_id=? AND consumption_kind=?', [$grantId, $kind]);
        if ($used >= $limit) {
            return Outcome::refuse('EXECUTION_GRANT_BUDGET_EXHAUSTED');
        }
        $tx->insert('monitor_grant_consumptions', [
            'grant_consumption_id' => Ids::new(), 'execution_grant_id' => $grantId, 'consumption_kind' => $kind,
            'reference_id' => $referenceId, 'monitor_run_id' => $runId, 'recorded_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['consumed' => $used + 1, 'limit' => $limit]);
    }

    private function evaluate(string $checkpoint, array $facts, ?string $grantId): ?string
    {
        if (!isset(self::CHECKPOINTS[$checkpoint])) {
            return 'EXECUTION_CHECKPOINT_UNKNOWN';
        }
        [$purposes, $budget] = self::CHECKPOINTS[$checkpoint];
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!is_string($facts[$key] ?? null) || $facts[$key] === '') {
                return 'EXECUTION_SCOPE_REQUIRED';
            }
        }
        if ($grantId === null) {
            return 'EXECUTION_GRANT_REQUIRED';
        }
        $grant = $this->grants->find($facts['tenant_id'], $facts['workspace_id'], $grantId);
        if ($grant === null) {
            return 'EXECUTION_GRANT_NOT_FOUND';
        }
        $d = $grant['definition'];
        if (!in_array($grant['purpose'], $purposes, true) || (isset($facts['purpose']) && $facts['purpose'] !== $grant['purpose'])) {
            return 'EXECUTION_GRANT_PURPOSE_MISMATCH';
        }
        $status = $this->grants->status($grantId);
        if ($status !== 'ADMITTED') {
            return 'EXECUTION_GRANT_' . $status;
        }
        // The owner decision is re-read every time: editing or removing it withdraws admission.
        $admission = $this->grants->admission($grantId);
        $decision = $admission === null ? null : $this->decisions->resolve((string) $admission['owner_decision_ref'], (string) $admission['owner_decision_sha256']);
        if ($decision === null || !$decision->isOk()) {
            return $decision === null ? 'EXECUTION_GRANT_NOT_ADMITTED' : $decision->reasonCode();
        }
        if (!OwnerDecisionRegistry::authorizesGrant($decision->value(), $grant['tenant_id'], $grant['workspace_id'], $grant['purpose'], $grant['grant_digest'])) {
            return 'OWNER_DECISION_DOES_NOT_AUTHORIZE_GRANT';
        }
        if ($facts['operator_id'] !== $grant['principal_operator_id']) {
            return 'EXECUTION_GRANT_PRINCIPAL_MISMATCH';
        }
        if ($this->db->one("SELECT 1 FROM operators WHERE operator_id=? AND tenant_id=? AND status='ACTIVE'", [$facts['operator_id'], $facts['tenant_id']]) === null) {
            return 'EXECUTION_GRANT_PRINCIPAL_NOT_ACTIVE';
        }
        if (!hash_equals($d['application_fingerprint'], $this->fingerprint())) {
            return 'EXECUTION_GRANT_RELEASE_MISMATCH';
        }
        if ($d['model_policy_id'] !== ModelPolicy::ID || $d['model_policy_digest'] !== ModelPolicy::digest()) {
            return 'EXECUTION_GRANT_MODEL_POLICY_CHANGED';
        }

        if ($grant['purpose'] === 'MODEL_EVALUATION') {
            if (($facts['evaluation_manifest_sha256'] ?? null) !== $grant['evaluation_manifest_sha256']) {
                return 'EXECUTION_GRANT_EVALUATION_MISMATCH';
            }
        } else {
            if (($facts['mission_id'] ?? null) !== $grant['mission_id']) {
                return 'EXECUTION_GRANT_MISSION_MISMATCH';
            }
            if (($facts['definition_digest'] ?? null) !== $grant['definition_digest']) {
                return 'EXECUTION_GRANT_CONFIGURATION_CHANGED';
            }
            if (in_array($checkpoint, ['SOURCE_CAPTURE', 'SOURCE_BEFORE_DISPATCH'], true)
                && (($facts['monitored_source_id'] ?? null) !== $d['source_scope']['monitored_source_id']
                    || ($facts['capture_url'] ?? null) !== $d['source_scope']['capture_url'])) {
                return 'EXECUTION_GRANT_SOURCE_MISMATCH';
            }
            if (in_array($checkpoint, ['RELEASE_P01', 'RELEASE_ROUTING'], true) && ($facts['target_org_role'] ?? null) !== $d['target_org_role']) {
                return 'EXECUTION_GRANT_TARGET_MISMATCH';
            }
        }

        // A human release is a fresh reliance on every product prompt/evaluation the grant
        // named. A prompt withdrawn after generation, or an evaluation that no longer qualifies,
        // cannot be bypassed by releasing a candidate produced earlier.
        if ($grant['purpose'] === 'MANUAL_MONITOR' && in_array($checkpoint, ['RELEASE_P01', 'RELEASE_ROUTING'], true)) {
            foreach ($d['prompt_approval_ids'] as $approvalId) {
                $current = $this->approvals->current($grant['tenant_id'], $grant['workspace_id'], $approvalId);
                if (!$current->isOk()) {
                    return $current->reasonCode();
                }
            }
        }

        if (in_array($checkpoint, self::MODEL_CHECKPOINTS, true)) {
            $credential = is_string($facts['credential_id'] ?? null) && $facts['credential_id'] === $d['credential_id']
                ? $this->db->one("SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'", [$d['credential_id'], $grant['tenant_id']])
                : null;
            if ($credential === null || !hash_equals((string) $d['credential_digest'], (string) $credential['credential_digest'])) {
                return 'EXECUTION_GRANT_CREDENTIAL_CHANGED';
            }
            $approvalId = $facts['prompt_approval_id'] ?? null;
            if (!is_string($approvalId) || !in_array($approvalId, $d['prompt_approval_ids'], true)) {
                return 'EXECUTION_GRANT_PROMPT_NOT_ADMITTED';
            }
            $approval = $this->approvals->current($grant['tenant_id'], $grant['workspace_id'], $approvalId);
            if (!$approval->isOk()) {
                return $approval->reasonCode();
            }
            if ($approval->value()['prompt_sha256'] !== ($facts['prompt_sha256'] ?? null) || $approval->value()['schema_sha256'] !== ($facts['schema_sha256'] ?? null)) {
                return 'EXECUTION_GRANT_PROMPT_NOT_ADMITTED';
            }
        }

        if ($budget !== null) {
            $limit = $budget === 'MODEL_ATTEMPT' ? $d['limits']['max_model_attempts'] : $d['limits']['max_source_captures'];
            if ($this->grants->consumed($grantId, $budget) >= $limit) {
                return 'EXECUTION_GRANT_BUDGET_EXHAUSTED';
            }
        }

        return null;
    }

    private function record(string $checkpoint, array $facts, ?string $grantId, ?string $reason): bool
    {
        if (!is_string($facts['tenant_id'] ?? null) || !is_string($facts['workspace_id'] ?? null)) {
            return false;
        }
        $scopeExists = $this->db->one('SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?', [$facts['workspace_id'], $facts['tenant_id']]) !== null;
        if (!$scopeExists) {
            return false;
        }
        $known = $grantId !== null && $this->db->one(
            'SELECT 1 FROM monitor_execution_grants WHERE execution_grant_id=? AND tenant_id=? AND workspace_id=?',
            [$grantId, $facts['tenant_id'], $facts['workspace_id']]
        ) !== null;
        $digest = is_string($facts['request_digest'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $facts['request_digest']) === 1 ? $facts['request_digest'] : null;
        $run = is_string($facts['monitor_run_id'] ?? null) && preg_match('/^[a-f0-9-]{36}$/D', $facts['monitor_run_id']) === 1
            && $this->db->one('SELECT 1 FROM monitor_runs WHERE monitor_run_id=? AND tenant_id=? AND workspace_id=?', [$facts['monitor_run_id'], $facts['tenant_id'], $facts['workspace_id']]) !== null
            ? $facts['monitor_run_id'] : null;
        try {
            $this->db->insert('monitor_admission_decisions', [
                'admission_decision_id' => Ids::new(), 'tenant_id' => $facts['tenant_id'], 'workspace_id' => $facts['workspace_id'],
                'execution_grant_id' => $known ? $grantId : null, 'checkpoint' => substr($checkpoint, 0, 48),
                'outcome' => $reason === null ? 'ALLOW' : 'REFUSE', 'reason_code' => substr($reason ?? 'EXECUTION_ADMITTED', 0, 96),
                'monitor_run_id' => $run, 'request_digest' => $digest, 'recorded_at' => $this->clock->now(),
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
