<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\ModelPolicy;

/**
 * Two operator questions about native execution, answered from the database and the deployed tree.
 *
 * `readiness()` reports a posture — INERT_STAGING, MANUAL_PILOT_READY or BLOCKED with exact reasons.
 * A posture is a DESCRIPTION, never permission: the admitted grant and its reviewed decision are the
 * permission, and every step re-checks them. `standDown()` suspends or revokes admitted grants and can
 * stop runs in flight. It never deletes an artifact, alters a review, refunds budget or retracts a
 * release — retraction remains the explicit M10 correction/withdrawal operation.
 */
final class NativeOperations
{
    public const INERT = 'INERT_STAGING';
    public const READY = 'MANUAL_PILOT_READY';
    public const BLOCKED = 'BLOCKED';

    /** @var null|callable(array,string):Outcome */
    private $evaluationEvidence;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ExecutionGrantStore $grants,
        private readonly PromptApprovalStore $approvals,
        private readonly OwnerDecisionRegistry $decisions,
        private readonly string|\Closure $applicationFingerprint,
        private readonly string $decisionDirectory,
        ?callable $evaluationEvidence = null,
    ) {
        $this->evaluationEvidence = $evaluationEvidence;
    }

    /** @return array<string, mixed> */
    public function readiness(string $tenantId, string $workspaceId, ?string $missionId = null): array
    {
        $fingerprint = $this->applicationFingerprint instanceof \Closure ? (string) ($this->applicationFingerprint)() : $this->applicationFingerprint;
        $decisionFiles = array_map('basename', glob(rtrim($this->decisionDirectory, '/') . '/*.json') ?: []);
        $admitted = [];
        foreach (['MANUAL_MONITOR', 'MODEL_EVALUATION', 'SCHEDULED_OBSERVATION'] as $purpose) {
            foreach ($this->grants->admittedIn($tenantId, $workspaceId, $purpose) as $id) {
                $admitted[] = $this->grants->find($tenantId, $workspaceId, $id);
            }
        }
        $base = [
            'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'application_fingerprint' => $fingerprint,
            'owner_decisions_shipped' => $decisionFiles,
            'admitted_grants' => array_map(fn (array $g): array => [
                'execution_grant_id' => $g['execution_grant_id'], 'purpose' => $g['purpose'], 'mission_id' => $g['mission_id'],
                'valid_until' => $g['valid_until'], 'release_matches' => hash_equals($g['definition']['application_fingerprint'], $fingerprint),
                'model_attempts' => ['used' => $this->grants->consumed($g['execution_grant_id'], 'MODEL_ATTEMPT'), 'limit' => $g['definition']['limits']['max_model_attempts']],
                'source_captures' => ['used' => $this->grants->consumed($g['execution_grant_id'], 'SOURCE_CAPTURE'), 'limit' => $g['definition']['limits']['max_source_captures']],
            ], $admitted),
            'posture_is_permission' => false,
        ];
        if ($admitted === []) {
            return $base + ['posture' => self::INERT, 'reasons' => $decisionFiles === [] ? [] : ['OWNER_DECISIONS_SHIPPED_BUT_NOTHING_ADMITTED']];
        }

        $reasons = [];
        $manual = array_values(array_filter($admitted, static fn (array $g): bool => $g['purpose'] === 'MANUAL_MONITOR' && ($missionId === null || $g['mission_id'] === $missionId)));
        if (count($manual) !== 1) {
            $reasons[] = $manual === [] ? 'NO_ADMITTED_MANUAL_MONITOR_GRANT' : 'MORE_THAN_ONE_ADMITTED_MANUAL_MONITOR_GRANT';
        } else {
            $g = $manual[0];
            $d = $g['definition'];
            $admission = $this->grants->admission($g['execution_grant_id']);
            $decision = $admission === null ? null : $this->decisions->resolve((string) $admission['owner_decision_ref'], (string) $admission['owner_decision_sha256']);
            if ($decision === null || !$decision->isOk() || !OwnerDecisionRegistry::authorizesGrant($decision->value(), $tenantId, $workspaceId, 'MANUAL_MONITOR', $g['grant_digest'])) {
                $reasons[] = 'OWNER_DECISION_NO_LONGER_AUTHORIZES_GRANT';
            }
            if (!hash_equals($d['application_fingerprint'], $fingerprint)) {
                $reasons[] = 'GRANT_BOUND_TO_ANOTHER_RELEASE';
            }
            if ($d['model_policy_digest'] !== ModelPolicy::digest()) {
                $reasons[] = 'MODEL_POLICY_CHANGED';
            }
            $config = $this->db->one('SELECT definition_digest,execution_mode FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?', [$g['mission_id'], $tenantId, $workspaceId]);
            if ($config === null || $config['execution_mode'] !== 'NATIVE' || $config['definition_digest'] !== $g['definition_digest']) {
                $reasons[] = 'MISSION_CONFIGURATION_CHANGED';
            }
            $credential = $this->db->one("SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'", [$d['credential_id'], $tenantId]);
            if ($credential === null || $credential['credential_digest'] !== $d['credential_digest']) {
                $reasons[] = 'CREDENTIAL_NOT_CURRENT';
            }
            foreach ($d['prompt_approval_ids'] as $approvalId) {
                $approval = $this->approvals->current($tenantId, $workspaceId, $approvalId);
                if (!$approval->isOk()) {
                    $reasons[] = 'PROMPT_APPROVAL_NOT_CURRENT:' . $approvalId;
                    continue;
                }
                if ($this->evaluationEvidence !== null) {
                    $a = $approval->value();
                    $evidence = ($this->evaluationEvidence)(['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'role' => $a['role'], 'prompt_sha256' => $a['prompt_sha256'], 'schema_sha256' => $a['schema_sha256'], 'model_policy_digest' => $a['model_policy_digest']], (string) $a['evaluation_reference']);
                    if (!$evidence->isOk()) {
                        $reasons[] = 'EVALUATION_EVIDENCE_NOT_ACCEPTED:' . $a['role'];
                    }
                }
            }
            // One baseline and one changed capture, and all four model stages, must still fit.
            if ($d['limits']['max_source_captures'] - $this->grants->consumed($g['execution_grant_id'], 'SOURCE_CAPTURE') < 2) {
                $reasons[] = 'SOURCE_CAPTURE_BUDGET_INSUFFICIENT';
            }
            if ($d['limits']['max_model_attempts'] - $this->grants->consumed($g['execution_grant_id'], 'MODEL_ATTEMPT') < 4) {
                $reasons[] = 'MODEL_ATTEMPT_BUDGET_INSUFFICIENT';
            }
            if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE')) {
                $reasons[] = 'NATIVE_TRANSPORT_UNAVAILABLE_ON_THIS_PHP';
            }
        }

        return $base + ['posture' => $reasons === [] ? self::READY : self::BLOCKED, 'reasons' => $reasons];
    }

    /**
     * Suspend (or revoke) every admitted grant in the workspace, or one named grant, and optionally
     * stop native runs that have not finished. Idempotent: standing down twice changes nothing more.
     *
     * @return Outcome<array{grants: list<array{execution_grant_id:string,status:string}>, stopped_runs: list<string>}>
     */
    public function standDown(array $scope, string $mode, string $reasonCode, ?string $grantId, ?MonitorOperations $stopWith): Outcome
    {
        if (!in_array($mode, ['suspend', 'revoke'], true)) {
            return Outcome::refuse('STAND_DOWN_MODE_INVALID');
        }
        $ids = $grantId !== null ? [$grantId] : [];
        if ($grantId === null) {
            foreach (['MANUAL_MONITOR', 'MODEL_EVALUATION', 'SCHEDULED_OBSERVATION'] as $purpose) {
                $ids = [...$ids, ...$this->grants->admittedIn($scope['tenant_id'], $scope['workspace_id'], $purpose)];
                if ($mode === 'revoke') {
                    foreach ($this->db->all('SELECT execution_grant_id FROM monitor_execution_grants WHERE tenant_id=? AND workspace_id=? AND purpose=?', [$scope['tenant_id'], $scope['workspace_id'], $purpose]) as $row) {
                        if ($this->grants->status($row['execution_grant_id']) === 'SUSPENDED') {
                            $ids[] = $row['execution_grant_id'];
                        }
                    }
                }
            }
        }
        $done = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $status = $this->grants->status($id);
            $wanted = $mode === 'suspend' ? 'SUSPENDED' : 'REVOKED';
            if ($status !== $wanted) {
                $out = $mode === 'suspend' ? $this->grants->suspend($scope, $id, $reasonCode) : $this->grants->revoke($scope, $id, $reasonCode);
                if (!$out->isOk()) {
                    return $out;
                }
            }
            $done[] = ['execution_grant_id' => $id, 'status' => $this->grants->status($id)];
        }
        $stopped = [];
        if ($stopWith !== null) {
            $open = $this->db->all("SELECT monitor_run_id FROM monitor_runs WHERE tenant_id=? AND workspace_id=? AND execution_mode='NATIVE' AND stage NOT IN ('COMPLETE','BASELINE','NO_CHANGE','BLOCKED','FAILED','STOPPED') ORDER BY created_at", [$scope['tenant_id'], $scope['workspace_id']]);
            foreach ($open as $row) {
                $out = $stopWith->stop($scope, $row['monitor_run_id']);
                if ($out->isOk()) {
                    $stopped[] = $row['monitor_run_id'];
                }
            }
        }

        return Outcome::ok(['grants' => $done, 'stopped_runs' => $stopped, 'refunds_budget' => false, 'retracts_releases' => false]);
    }
}
