<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Workflow\OwnerDecisionRegistry;

/**
 * Permission to EXECUTE an exact prompt, kept apart from the prompt's content.
 *
 * The prompt bundle is immutable content: approving, withdrawing or replacing a prompt never edits
 * the bytes an earlier run used. An approval names the exact prompt and schema hashes, the purpose
 * (evaluation or manual Monitor), the model policy digest, and the reviewed owner decision that
 * authorizes exactly that combination. Withdrawal is an append-only event. A changed prompt is a
 * new identity and needs a new approval — there is nothing here to "update".
 *
 * A manual-Monitor approval must also cite the evaluation it relies on. An evaluation approval does
 * not: evaluating a draft prompt is how the evidence for a product approval is produced, and it
 * never lets that prompt run against a real mission.
 */
final class PromptApprovalStore
{
    public const PURPOSES = ['MODEL_EVALUATION', 'MANUAL_MONITOR'];

    /** @var null|callable(array,string):Outcome */
    private $evaluationEvidence;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly OwnerDecisionRegistry $decisions,
        ?callable $evaluationEvidence = null,
    ) {
        $this->evaluationEvidence = $evaluationEvidence;
    }

    public function approve(array $scope, string $role, string $purpose, string $decisionRef, string $decisionSha256, ?string $evaluationReference = null): Outcome
    {
        if (!$this->mayAdminister($scope)) {
            return Outcome::refuse('PROMPT_APPROVAL_DENIED');
        }
        if (!in_array($purpose, self::PURPOSES, true)) {
            return Outcome::refuse('PROMPT_APPROVAL_PURPOSE_INVALID');
        }
        $content = ProductPromptPack::resolve($role);
        if (!$content->isOk()) {
            return $content;
        }
        $prompt = $content->value();
        if ($purpose === 'MANUAL_MONITOR') {
            if ($evaluationReference === null || preg_match('/^[A-Za-z0-9:._-]{1,191}$/D', $evaluationReference) !== 1) {
                return Outcome::refuse('PROMPT_APPROVAL_REQUIRES_EVALUATION');
            }
            if ($this->evaluationEvidence !== null) {
                $evidence = ($this->evaluationEvidence)($scope + ['role' => $role, 'prompt_sha256' => $prompt['prompt_sha256'], 'schema_sha256' => $prompt['schema_sha256'], 'model_policy_digest' => ModelPolicy::digest()], $evaluationReference);
                if (!$evidence->isOk()) {
                    return $evidence;
                }
            }
        } elseif ($evaluationReference !== null) {
            return Outcome::refuse('PROMPT_APPROVAL_PURPOSE_INVALID');
        }
        $identity = [
            'purpose' => $purpose, 'role' => $role, 'prompt_sha256' => $prompt['prompt_sha256'],
            'schema_sha256' => $prompt['schema_sha256'], 'model_policy_digest' => ModelPolicy::digest(),
        ];
        $decision = $this->decisions->resolve($decisionRef, $decisionSha256);
        if (!$decision->isOk()) {
            return $decision;
        }
        if (!OwnerDecisionRegistry::authorizesPrompt($decision->value(), $scope['tenant_id'], $scope['workspace_id'], $identity)) {
            return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_PROMPT');
        }

        // The prompt CONTENT identity and the AUTHORIZATION instance are different things.
        // A later reviewed owner decision, or a later accepted product evaluation, must be able to
        // authorize the same immutable prompt bytes without mutating/resurrecting the old approval.
        // The exact same authorization remains idempotent; a withdrawn authorization stays terminal.
        $approvalDigest = Digest::canonicalDigest($identity + [
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'owner_decision_ref' => $decisionRef, 'owner_decision_sha256' => $decisionSha256,
            'evaluation_reference' => $evaluationReference,
        ]);
        $existing = $this->db->one('SELECT * FROM monitor_prompt_approvals WHERE approval_digest=?', [$approvalDigest]);
        if ($existing !== null) {
            return $this->withdrawn($existing['prompt_approval_id'])
                ? Outcome::refuse('PROMPT_APPROVAL_WITHDRAWN')
                : Outcome::ok($this->public($existing));
        }

        $row = [
            'prompt_approval_id' => Ids::new(), 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'purpose' => $purpose, 'role' => $role, 'pack_version' => $prompt['pack_version'],
            'prompt_version_id' => $prompt['prompt_version_id'], 'binding_id' => $prompt['binding_id'],
            'prompt_sha256' => $prompt['prompt_sha256'], 'schema_sha256' => $prompt['schema_sha256'],
            'model_policy_id' => ModelPolicy::ID, 'model_policy_digest' => ModelPolicy::digest(),
            'evaluation_reference' => $evaluationReference, 'owner_decision_ref' => $decisionRef,
            'owner_decision_sha256' => $decisionSha256, 'approval_digest' => $approvalDigest,
            'approved_by_operator_id' => $scope['operator_id'], 'created_at' => $this->clock->now(),
        ];
        try {
            $this->db->insert('monitor_prompt_approvals', $row);
        } catch (\PDOException $error) {
            return Database::classify($error, 'PROMPT_APPROVAL_ALREADY_RECORDED');
        }

        return Outcome::ok($this->public($row));
    }

    public function withdraw(array $scope, string $approvalId, string $reasonCode): Outcome
    {
        if (!$this->mayAdminister($scope)) {
            return Outcome::refuse('PROMPT_APPROVAL_DENIED');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $reasonCode) !== 1) {
            return Outcome::refuse('PROMPT_APPROVAL_REASON_REQUIRED');
        }
        $row = $this->find($scope['tenant_id'], $scope['workspace_id'], $approvalId);
        if ($row === null) {
            return Outcome::refuse('PROMPT_APPROVAL_NOT_FOUND');
        }
        if ($this->withdrawn($approvalId)) {
            return Outcome::ok(['prompt_approval_id' => $approvalId, 'status' => 'WITHDRAWN']);
        }
        try {
            $this->db->insert('monitor_prompt_approval_events', [
                'prompt_approval_event_id' => Ids::new(), 'prompt_approval_id' => $approvalId, 'event_kind' => 'WITHDRAWN',
                'reason_code' => $reasonCode, 'recorded_by_operator_id' => $scope['operator_id'], 'recorded_at' => $this->clock->now(),
            ]);
        } catch (\PDOException $error) {
            return Database::classify($error, 'PROMPT_APPROVAL_ALREADY_WITHDRAWN');
        }

        return Outcome::ok(['prompt_approval_id' => $approvalId, 'status' => 'WITHDRAWN']);
    }

    public function find(string $tenantId, string $workspaceId, string $approvalId): ?array
    {
        return $this->db->one('SELECT * FROM monitor_prompt_approvals WHERE prompt_approval_id=? AND tenant_id=? AND workspace_id=?', [$approvalId, $tenantId, $workspaceId]);
    }

    /**
     * Whether an approval may be relied on NOW: not withdrawn, its owner decision still present and
     * still authorizing it, its prompt still the bundle's bytes, and its model policy still current.
     */
    public function current(string $tenantId, string $workspaceId, string $approvalId): Outcome
    {
        $row = $this->find($tenantId, $workspaceId, $approvalId);
        if ($row === null) {
            return Outcome::refuse('PROMPT_APPROVAL_NOT_FOUND');
        }
        if ($this->withdrawn($approvalId)) {
            return Outcome::refuse('PROMPT_APPROVAL_WITHDRAWN');
        }
        if ($row['model_policy_id'] !== ModelPolicy::ID || $row['model_policy_digest'] !== ModelPolicy::digest()) {
            return Outcome::refuse('PROMPT_APPROVAL_MODEL_POLICY_CHANGED');
        }
        $content = ProductPromptPack::resolve($row['role']);
        if (!$content->isOk() || $content->value()['prompt_sha256'] !== $row['prompt_sha256'] || $content->value()['schema_sha256'] !== $row['schema_sha256']) {
            return Outcome::refuse('PROMPT_APPROVAL_CONTENT_CHANGED');
        }
        $decision = $this->decisions->resolve($row['owner_decision_ref'], $row['owner_decision_sha256']);
        if (!$decision->isOk()) {
            return $decision;
        }
        $identity = ['purpose' => $row['purpose'], 'role' => $row['role'], 'prompt_sha256' => $row['prompt_sha256'], 'schema_sha256' => $row['schema_sha256'], 'model_policy_digest' => $row['model_policy_digest']];
        if (!OwnerDecisionRegistry::authorizesPrompt($decision->value(), $tenantId, $workspaceId, $identity)) {
            return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_PROMPT');
        }
        // A MANUAL_MONITOR approval's evaluation remains current authority, not creation-time
        // metadata. Deployed execution supplies this resolver and therefore re-checks the cited
        // LIVE accepted evaluation every time the approval is selected.
        if ($row['purpose'] === 'MANUAL_MONITOR' && $this->evaluationEvidence !== null) {
            $evidence = ($this->evaluationEvidence)([
                'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'role' => $row['role'],
                'prompt_sha256' => $row['prompt_sha256'], 'schema_sha256' => $row['schema_sha256'],
                'model_policy_digest' => $row['model_policy_digest'],
            ], (string) $row['evaluation_reference']);
            if (!$evidence->isOk()) {
                return $evidence;
            }
        }

        return Outcome::ok($this->public($row));
    }

    private function withdrawn(string $approvalId): bool
    {
        return $this->db->one("SELECT 1 FROM monitor_prompt_approval_events WHERE prompt_approval_id=? AND event_kind='WITHDRAWN'", [$approvalId]) !== null;
    }

    /** @return array<string, mixed> */
    private function public(array $row): array
    {
        return array_intersect_key($row, array_flip(['prompt_approval_id', 'purpose', 'role', 'prompt_version_id', 'binding_id', 'prompt_sha256', 'schema_sha256', 'model_policy_id', 'model_policy_digest', 'evaluation_reference', 'owner_decision_ref', 'approval_digest']))
            + ['status' => 'APPROVED', 'grants_execution' => false];
    }

    private function mayAdminister(array $scope): bool
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return false;
            }
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach (['tenant.administer', 'mission.activate'] as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'PROMPT_APPROVAL', null, null, true)['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }
}
