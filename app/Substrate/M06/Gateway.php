<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M06;

use Allfather\Loopia\Core\ActionClass;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M01\DispatchAdmission;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;

/**
 * The M06 tool gateway — the SINGLE path through which a governed tool call may reach a host.
 *
 * The gate sequence is fixed: M02 access → M03 contract → M04 mission scope → M06 descriptor → V1
 * scope/safety filter → idempotency. Each sub-check writes an append-only `tool_gate_checks` row;
 * the canonical answer is one append-only `tool_authorization_decisions` row per request.
 *
 * ALLOW dispatches via M01. DENY / HOLD / REQUIRE_APPROVAL writes a denial escalation record with
 * `no_dispatch_confirmed = 1` — the positive proof that nothing was dispatched, which a missing
 * dispatch row alone could not give.
 *
 * PROJECTION CEILINGS. Every action class above ANALYZE routes to REQUIRE_APPROVAL, and this
 * projection has no M09 approval plane, so REQUIRE_APPROVAL is terminal here rather than a step
 * toward eventual dispatch. Tool classes outside READ_ONLY_SOURCE and INTERNAL_TRANSFORM are
 * refused at M06 before M01 is ever called.
 */
final class Gateway
{
    /** @var list<string> */
    private const V1_SAFE_TOOL_CLASSES = ['READ_ONLY_SOURCE', 'INTERNAL_TRANSFORM'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
        private readonly Eligibility $eligibility,
        private readonly Invocation $m01,
    ) {
    }

    /**
     * Synthetic dispatch: no live host tool on this path, so M01 returns a deterministic fixture.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function requestToolDispatch(array $command): array
    {
        $prepared = $this->prepare($command);
        if ($prepared['kind'] === 'EARLY') {
            return $prepared['decision'];
        }

        return $this->finish(
            $prepared['prepared'],
            $this->m01->invokeAuthorizedHostTool($prepared['prepared']['m01_command'], $prepared['prepared']['admission'])
        );
    }

    /**
     * Live read-only source capture dispatch.
     *
     * The SAME governed gate chain as `requestToolDispatch` — it shares `prepare()`, the single
     * non-bypassable chain — but the M01 handoff performs a real, egress-guarded network read
     * through the injected callable instead of returning a fixture. INV-006 holds: M01 stays the
     * only dispatch path and still requires the M06-issued ALLOW. The network is reached ONLY
     * through the injected transport, so tests and replay never touch it.
     *
     * @param array<string, mixed> $command
     * @param callable(): array{kind: string, content_hash?: string, reason_code?: string} $performLiveFetch
     * @return array<string, mixed>
     */
    public function requestLiveSourceDispatch(array $command, callable $performLiveFetch): array
    {
        $prepared = $this->prepare($command);
        if ($prepared['kind'] === 'EARLY') {
            return $prepared['decision'];
        }

        return $this->finish(
            $prepared['prepared'],
            $this->m01->invokeAuthorizedLiveSourceCapture(
                $prepared['prepared']['m01_command'],
                $performLiveFetch,
                $prepared['prepared']['admission']
            )
        );
    }

    /** Bounded model transform; same gate chain, exact immutable input, no general host executor. */
    public function requestModelDispatch(array $command, \Allfather\Loopia\Provider\PreparedModelRequest $request, callable $performModel): array
    {
        $identity=$request->provenance();
        foreach (['tenant_id','workspace_id','mission_id'] as $key) {
            if (($command[$key]??null)!==$identity[$key]) return ['kind'=>'DENIED','reason_codes'=>['MODEL_REQUEST_SCOPE_MISMATCH']];
        }
        if (($command['requested_action_class']??'')!=='ANALYZE') return ['kind'=>'DENIED','reason_codes'=>['MODEL_REQUIRES_ANALYZE']];
        $command['normalized_input_ref_safe']='model-request://'.$request->digest();
        $prepared=$this->prepare($command);
        if ($prepared['kind']==='EARLY') return $prepared['decision'];
        $m01=$prepared['prepared']['m01_command'];
        $m01['model_request_digest']=$request->digest();
        return $this->finish($prepared['prepared'],$this->m01->invokeAuthorizedModelTransform($m01,$performModel,$prepared['prepared']['admission']));
    }

    // ---------------------------------------------------------------- phases 1-4

    /**
     * Idempotency → request row → the gate chain → the canonical ALLOW → runtime mapping and
     * dispatch record.
     *
     * THE WHOLE GATE CHAIN IS ONE ATOMIC ACT. Wrapping only the ALLOW persistence would leave the
     * idempotency lookup, the request insert and every gate-check row outside a transaction: a
     * failure part way through would strand an EVALUATING request with a partial gate sequence and
     * no decision, and two concurrent requests for one idempotency key could both pass the lookup
     * before either inserted. The loser now loses on the unique index INSIDE the transaction, and
     * that loss is resolved as a governed replay or conflict rather than escaping as an exception.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function prepare(array $command): array
    {
        try {
            return $this->db->transaction(fn (Database $tx): array => $this->prepareWithin($tx, $command));
        } catch (\PDOException $e) {
            // ONLY the idempotency-key collision is a governed race. Any other constraint failure
            // inside the gate chain is a real fault, and reporting it as a replay would hand the
            // caller a governed answer to a question nobody asked.
            if (!str_contains($e->getMessage(), 'ux_tool_requests_idempotency')) {
                throw $e;
            }

            return ['kind' => 'EARLY', 'decision' => $this->resolveIdempotencyRace($command)];
        }
    }

    /**
     * Another writer committed the same (tenant, idempotency key) while this one was evaluating.
     *
     * The governed answer is the one the pre-check would have given a moment later: a replay when
     * the normalized input matches, a conflict when it does not. Never an exception.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function resolveIdempotencyRace(array $command): array
    {
        $inputDigest = self::inputDigestFor($command);
        $conflict = [
            'kind' => 'CONFLICT',
            'reason_code' => 'TOOL_IDEMPOTENCY_CONFLICT',
            'incident_evaluation_required' => true,
        ];

        $prior = $this->priorIdempotencyRecord($command);
        if ($prior !== null) {
            return (string) $prior['normalized_input_digest'] === $inputDigest
                ? [
                    'kind' => 'IDEMPOTENT_REPLAY',
                    'prior_tool_request_id' => (string) $prior['prior_tool_request_id'],
                    'prior_tool_authorization_decision_id' => (string) ($prior['prior_tool_authorization_decision_id'] ?? ''),
                    'prior_tool_execution_outcome_id' => $prior['prior_tool_execution_outcome_id'],
                ]
                : $conflict;
        }

        // The winner committed its request row but not yet its idempotency record — still in
        // flight. The request row carries the same normalized digest, so the same distinction holds.
        $request = $this->db->one(
            'SELECT r.tool_request_id, r.normalized_input_digest, d.tool_authorization_decision_id
             FROM tool_requests r
             LEFT JOIN tool_authorization_decisions d ON d.tool_request_id = r.tool_request_id
             WHERE r.tenant_id = ? AND r.idempotency_key = ?',
            [$command['tenant_id'], $command['idempotency_key']]
        );
        if ($request === null || (string) $request['normalized_input_digest'] !== $inputDigest) {
            return $conflict;
        }

        return [
            'kind' => 'IDEMPOTENT_REPLAY',
            'prior_tool_request_id' => (string) $request['tool_request_id'],
            'prior_tool_authorization_decision_id' => (string) ($request['tool_authorization_decision_id'] ?? ''),
            'prior_tool_execution_outcome_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function prepareWithin(Database $tx, array $command): array
    {
        $inputDigest = self::inputDigestFor($command);

        // Idempotency: same (tenant, key) AND same digest → replay; a digest mismatch → conflict,
        // never a second dispatch under a key the caller believed was already spent.
        $prior = $this->priorIdempotencyRecord($command);
        if ($prior !== null) {
            if ((string) $prior['normalized_input_digest'] !== $inputDigest) {
                return ['kind' => 'EARLY', 'decision' => [
                    'kind' => 'CONFLICT',
                    'reason_code' => 'TOOL_IDEMPOTENCY_CONFLICT',
                    'incident_evaluation_required' => true,
                ]];
            }

            return ['kind' => 'EARLY', 'decision' => [
                'kind' => 'IDEMPOTENT_REPLAY',
                'prior_tool_request_id' => (string) $prior['prior_tool_request_id'],
                'prior_tool_authorization_decision_id' => (string) ($prior['prior_tool_authorization_decision_id'] ?? ''),
                'prior_tool_execution_outcome_id' => $prior['prior_tool_execution_outcome_id'],
            ]];
        }

        $toolRequestId = Ids::new();
        $now = $this->clock->now();
        // The request row records what RESOLVED, not what was claimed. A claimed mission,
        // assignment, agent or contract that does not exist is stored as NULL rather than as a
        // dangling id: the row would otherwise assert a relationship the database can prove false,
        // and the foreign keys would reject the write outright — turning a governed refusal into
        // an exception the caller cannot act on.
        //
        // The CLAIMED ids still reach every gate, because the gates read the command, not this
        // row. So an unresolvable assignment is refused by the M04 gate with ASSIGNMENT_NOT_FOUND,
        // with the claimed id recorded on the gate-check row — which is where a claim that turned
        // out to be false belongs.
        $tx->insert('tool_requests', [
            'tool_request_id' => $toolRequestId,
            'schema_version' => '1',
            'tenant_id' => $command['tenant_id'],
            'workspace_id' => $command['workspace_id'],
            'mission_id' => $this->resolved('missions', 'mission_id', $command['mission_id'] ?? null),
            'assignment_id' => $this->resolved('mission_assignments', 'assignment_id', $command['assignment_id'] ?? null),
            'valkyrie_id' => $this->resolved('valkyries', 'valkyrie_id', $command['valkyrie_id'] ?? null),
            'capability_contract_id' => $this->resolved(
                'capability_contracts', 'capability_contract_id', $command['capability_contract_id'] ?? null
            ),
            'governed_tool_id' => $command['governed_tool_id'],
            'governed_tool_operation_id' => $command['governed_tool_operation_id'],
            'requested_action_class' => $command['requested_action_class'],
            'normalized_input_ref_safe' => $command['normalized_input_ref_safe'] ?? null,
            'normalized_input_digest' => $inputDigest,
            'justification_summary' => $command['justification_summary'] ?? null,
            'idempotency_key' => $command['idempotency_key'],
            'correlation_id' => $command['correlation_id'],
            'status' => 'EVALUATING',
            'requested_at' => $now,
        ]);

        $passed = [];
        $failed = [];
        $reasonCodes = [];

        $recordCheck = function (string $gateType, string $decision, array $codes) use (
            $tx, $toolRequestId, &$passed, &$failed, &$reasonCodes
        ): string {
            $checkId = Ids::new();
            $tx->insert('tool_gate_checks', [
                'tool_gate_check_id' => $checkId,
                'schema_version' => '1',
                'tool_request_id' => $toolRequestId,
                'gate_type' => $gateType,
                'dependency_object_refs_json' => '{}',
                'decision' => $decision,
                'reason_codes' => json_encode(array_values($codes), JSON_THROW_ON_ERROR),
                'assessed_at' => $this->clock->now(),
            ]);
            if ($decision === 'PASS') {
                $passed[] = $checkId;
            } else {
                $failed[] = $checkId;
                foreach ($codes as $code) {
                    $reasonCodes[] = $code;
                }
            }

            return $decision;
        };

        // ── (1) M02 access scope ────────────────────────────────────────────────────────────────
        // "Are you allowed to attempt a tool call in this workspace at all?" Tool-class-specific
        // gates live downstream; this one is the workspace-level authority.
        $m02 = $this->access->evaluate(
            (string) $command['calling_actor_type'],
            (string) $command['calling_actor_id'],
            (string) $command['tenant_id'],
            (string) $command['workspace_id'],
            'tool.invoke',
            'tool_request',
            $toolRequestId,
            (string) $command['correlation_id'],
        );
        $m02Decision = $m02['decision'] === 'ALLOW' ? 'PASS' : 'FAIL';
        $m02Codes = $m02['decision'] === 'ALLOW'
            ? []
            : array_map(static fn (string $r): string => "TOOL_REQUEST_{$r}", $m02['reason_codes']);
        $m02Severity = $m02['severity'] ?? 'S2';
        $m02Category = 'TOOL_REQUEST_SCOPE_MISMATCH';

        // The subscription-tier entitlement is composed INTO the M02 gate, not run beside it.
        // After M02 grants the role-level authority to invoke a tool at all, the tenant's ACTIVE
        // tier must ALSO grant the declared permission. This adds NO parallel authority plane: it
        // narrows an already-authorized caller by the tenant's commercial entitlement, on the SAME
        // gate row. Default-deny — a tier that does not grant it cannot dispatch.
        $entitlement = $command['required_entitlement_permission'] ?? null;
        if ($m02Decision === 'PASS' && $entitlement !== null
            && !$this->tenantActiveTierGrants((string) $command['tenant_id'], (string) $entitlement)
        ) {
            $m02Decision = 'FAIL';
            $m02Codes = ['TOOL_REQUEST_TIER_DOES_NOT_GRANT_PERMISSION'];
            $m02Severity = 'S2';
            $m02Category = 'TOOL_REQUEST_TIER_NOT_ENTITLED';
        }
        if ($recordCheck('M02_ACCESS_SCOPE', $m02Decision, $m02Codes) === 'FAIL') {
            return ['kind' => 'EARLY', 'decision' => $this->finalizeDeny(
                $tx, $toolRequestId, $passed, $failed, $reasonCodes, $m02Severity, 'DENY', $m02Category
            )];
        }

        // ── (2) M03 capability contract ─────────────────────────────────────────────────────────
        // The resolved OPERATION key is passed, so the eligibility check verifies the operation is
        // in the contract's allowed operations — not merely that the tool is permitted, which would
        // make every contract grant far looser than it reads.
        $operationKey = $this->db->scalar(
            'SELECT operation_key FROM governed_tool_operations WHERE governed_tool_operation_id = ?',
            [$command['governed_tool_operation_id']]
        );
        $m03 = $this->eligibility->evaluateToolRight([
            'tenant_id' => (string) $command['tenant_id'],
            'workspace_id' => (string) $command['workspace_id'],
            'mission_id' => (string) $command['mission_id'],
            'valkyrie_id' => (string) $command['valkyrie_id'],
            'governed_tool_id' => (string) $command['governed_tool_id'],
            'requested_operation' => $operationKey === null ? null : (string) $operationKey,
            'requested_action_class' => (string) $command['requested_action_class'],
            'purpose' => 'TOOL_RIGHT',
            'correlation_id' => (string) $command['correlation_id'],
        ]);
        $m03Decision = match ($m03['decision']) {
            'PERMITTED_BY_CONTRACT', 'REQUIRES_DOWNSTREAM_GATES' => 'PASS',
            'HOLD' => 'HOLD',
            default => 'FAIL',
        };
        $m03Codes = array_map(static fn (string $r): string => "TOOL_VALKYRIE_{$r}", $m03['reason_codes']);
        if ($recordCheck('M03_CAPABILITY_CONTRACT', $m03Decision, $m03Codes) !== 'PASS') {
            // A cross-tenant reach is incident territory; every other contract failure is ordinary.
            $severity = in_array('TENANT_SCOPE_MISMATCH', $m03['reason_codes'], true) ? 'S4' : 'S2';

            return ['kind' => 'EARLY', 'decision' => $this->finalizeDeny(
                $tx, $toolRequestId, $passed, $failed, $reasonCodes, $severity,
                $m03Decision === 'HOLD' ? 'HOLD_FOR_REVIEW' : 'DENY',
                'TOOL_VALKYRIE_AUTHORITY_INVALID'
            )];
        }

        // ── (3) M04 mission scope ───────────────────────────────────────────────────────────────
        $assignment = $this->db->one(
            'SELECT a.assignment_id, a.status AS assignment_status, a.assigned_tool_ids,
                    a.assigned_action_classes, m.status AS mission_status
             FROM mission_assignments a JOIN missions m ON m.mission_id = a.mission_id
             WHERE a.assignment_id = ? AND a.mission_id = ? AND a.valkyrie_id = ?
               AND a.tenant_id = ? AND a.workspace_id = ?',
            [
                $command['assignment_id'], $command['mission_id'], $command['valkyrie_id'],
                $command['tenant_id'], $command['workspace_id'],
            ]
        );
        $missionReasons = [];
        if ($assignment === null) {
            $missionReasons[] = 'TOOL_MISSION_SCOPE_INVALID';
            $missionReasons[] = 'ASSIGNMENT_NOT_FOUND';
            $missionReasons[] = 'CLAIMED_ASSIGNMENT_ID:' . (string) ($command['assignment_id'] ?? '');
        } else {
            if ((string) $assignment['mission_status'] !== 'ACTIVE') {
                $missionReasons[] = 'MISSION_NOT_ACTIVE';
            }
            if ((string) $assignment['assignment_status'] !== 'ACTIVE') {
                $missionReasons[] = 'ASSIGNMENT_NOT_ACTIVE';
            }
            $tools = json_decode((string) $assignment['assigned_tool_ids'], true, 512, JSON_THROW_ON_ERROR);
            $actions = json_decode((string) $assignment['assigned_action_classes'], true, 512, JSON_THROW_ON_ERROR);
            if (!in_array((string) $command['governed_tool_id'], $tools, true)) {
                $missionReasons[] = 'TOOL_NOT_IN_ASSIGNMENT_SCOPE';
            }
            if (!in_array((string) $command['requested_action_class'], $actions, true)) {
                $missionReasons[] = 'ACTION_CLASS_NOT_IN_ASSIGNMENT_SCOPE';
            }
        }
        if ($recordCheck('M04_MISSION_SCOPE', $missionReasons === [] ? 'PASS' : 'FAIL', $missionReasons) === 'FAIL') {
            return ['kind' => 'EARLY', 'decision' => $this->finalizeDeny(
                $tx, $toolRequestId, $passed, $failed, $reasonCodes, 'S2', 'DENY', 'TOOL_MISSION_SCOPE_INVALID'
            )];
        }

        // ── (4) M06 tool descriptor ─────────────────────────────────────────────────────────────
        $tool = $this->db->one(
            'SELECT t.governed_tool_id, t.status AS tool_status, t.tool_class, t.maximum_action_class,
                    t.runtime_mapping_ref_id, t.approval_required, t.frozen_artifact_required,
                    t.evidence_capture_required_by_default,
                    o.governed_tool_operation_id, o.status AS op_status, o.operation_class,
                    o.classified_action_class, o.requires_evidence_capture, o.requires_approval,
                    o.requires_frozen_artifact
             FROM governed_tools t
             JOIN governed_tool_operations o ON o.governed_tool_id = t.governed_tool_id
             WHERE t.governed_tool_id = ? AND o.governed_tool_operation_id = ?',
            [$command['governed_tool_id'], $command['governed_tool_operation_id']]
        );
        $descReasons = [];
        if ($tool === null) {
            $descReasons[] = 'TOOL_DESCRIPTOR_NOT_FOUND';
        } else {
            if ((string) $tool['tool_status'] !== 'ACTIVE') {
                $descReasons[] = 'TOOL_NOT_ACTIVE';
            }
            if ((string) $tool['op_status'] !== 'ACTIVE') {
                $descReasons[] = 'TOOL_OPERATION_NOT_ACTIVE';
            }
            if ((string) $tool['classified_action_class'] !== (string) $command['requested_action_class']) {
                $descReasons[] = 'TOOL_OPERATION_CLASS_MISMATCH';
            }
            // The ceiling this gate already read must actually BOUND the request. An unknown or
            // FORBIDDEN ceiling never compares as permissive, so both fail closed.
            if (!ActionClass::withinCeiling($command['requested_action_class'], (string) $tool['maximum_action_class'])) {
                $descReasons[] = 'TOOL_ACTION_CLASS_EXCEEDS_TOOL_MAXIMUM';
            }
            if ((string) $tool['tool_class'] === 'FORBIDDEN_IN_V1') {
                $descReasons[] = 'TOOL_CLASS_FORBIDDEN_IN_V1';
            }
            // `operation_class` and `classified_action_class` are DIFFERENT descriptor fields.
            // Naming one while testing the other would let an operation declared
            // `operation_class = FORBIDDEN` pass as long as its action class looked benign.
            if ((string) $tool['operation_class'] === 'FORBIDDEN') {
                $descReasons[] = 'TOOL_OPERATION_CLASS_FORBIDDEN';
            }
            if ((string) $tool['classified_action_class'] === 'FORBIDDEN') {
                $descReasons[] = 'TOOL_OPERATION_ACTION_CLASS_FORBIDDEN';
            }
        }
        if ($recordCheck('M06_TOOL_DESCRIPTOR', $descReasons === [] ? 'PASS' : 'FAIL', $descReasons) === 'FAIL') {
            return ['kind' => 'EARLY', 'decision' => $this->finalizeDeny(
                $tx, $toolRequestId, $passed, $failed, $reasonCodes, 'S2', 'DENY',
                $descReasons[0] ?? 'TOOL_DESCRIPTOR_NOT_FOUND'
            )];
        }

        // ── (5) V1 scope / safety filter ────────────────────────────────────────────────────────
        // Operation-level `requires_frozen_artifact` is as binding as the tool-level flag; reading
        // only the tool-level one would let an operation that demands a frozen artifact dispatch
        // without approval.
        $requiresApproval = (int) $tool['approval_required'] === 1
            || (int) $tool['requires_approval'] === 1
            || (int) $tool['frozen_artifact_required'] === 1
            || (int) $tool['requires_frozen_artifact'] === 1;
        $scopeReasons = [];
        if (!in_array((string) $tool['tool_class'], self::V1_SAFE_TOOL_CLASSES, true)) {
            $scopeReasons[] = 'TOOL_FORBIDDEN_IN_V1';
        }
        if (!in_array((string) $command['requested_action_class'], ActionClass::DISPATCHABLE_IN_PROJECTION, true)) {
            $scopeReasons[] = 'TOOL_REQUIRES_APPROVAL';
        }
        if ($requiresApproval) {
            $scopeReasons[] = 'TOOL_REQUIRES_APPROVAL';
        }
        if ($recordCheck('V1_SCOPE_PROHIBITION', $scopeReasons === [] ? 'PASS' : 'FAIL', $scopeReasons) === 'FAIL') {
            $requireApproval = in_array('TOOL_REQUIRES_APPROVAL', $scopeReasons, true);

            return ['kind' => 'EARLY', 'decision' => $this->finalizeDeny(
                $tx, $toolRequestId, $passed, $failed, $reasonCodes,
                $requireApproval ? 'S3' : 'S5',
                $requireApproval ? 'REQUIRE_APPROVAL' : 'DENY',
                $scopeReasons[0]
            )];
        }

        // ── ALLOW ───────────────────────────────────────────────────────────────────────────────
        // Persisted inside the gate-chain transaction, so the decision, the request status advance
        // and the dispatch record commit with — or roll back with — every gate row that justified
        // them.
        $decisionId = Ids::new();
        // The SHARED canonical projection, so M01 can recompute this digest from persisted rows.
        $decisionDigest = DecisionIntegrity::decisionDigest(
            $toolRequestId,
            (string) $command['governed_tool_operation_id'],
            (string) $command['requested_action_class'],
            $inputDigest,
            $passed,
            'ALLOW',
        );
        $tx->insert('tool_authorization_decisions', [
            'tool_authorization_decision_id' => $decisionId,
            'schema_version' => '1',
            'tool_request_id' => $toolRequestId,
            'decision' => 'ALLOW',
            'validated_action_class' => $command['requested_action_class'],
            'passed_gate_check_ids' => json_encode($passed, JSON_THROW_ON_ERROR),
            'failed_or_holding_gate_check_ids' => json_encode($failed, JSON_THROW_ON_ERROR),
            'reason_codes' => json_encode(['GRANTED'], JSON_THROW_ON_ERROR),
            'decision_digest' => $decisionDigest,
            'hash_algorithm' => 'sha256',
            'decided_at' => $this->clock->now(),
        ]);
        $tx->run("UPDATE tool_requests SET status = 'ALLOWED' WHERE tool_request_id = ?", [$toolRequestId]);

        $runtimeBindingId = $tool['runtime_mapping_ref_id'] === null ? null : (string) $tool['runtime_mapping_ref_id'];
        $bindingTool = $runtimeBindingId === null ? null : $this->db->one(
            'SELECT runtime_host_tool_ref FROM runtime_host_tool_refs
             WHERE binding_id = ? AND governed_tool_id = ?',
            [$runtimeBindingId, $command['governed_tool_id']]
        );

        // No runtime mapping configured: the request was AUTHORIZED but is not dispatchable. That
        // is a failure-only outcome recorded honestly, not a silent success and not an exception —
        // and M01 is never called, so no admission is minted.
        if ($runtimeBindingId === null || $bindingTool === null) {
            $dispatchId = Ids::new();
            $tx->insert('tool_dispatch_records', [
                'tool_dispatch_id' => $dispatchId,
                'schema_version' => '1',
                'tool_request_id' => $toolRequestId,
                'tool_authorization_decision_id' => $decisionId,
                'runtime_host_binding_id' => null,
                'action_class' => $command['requested_action_class'],
                'exact_dispatched_input_digest' => $inputDigest,
                'dispatched_at' => $this->clock->now(),
                'status' => 'FAILED_TO_DISPATCH',
            ]);
            $outcomeId = $this->recordOutcome(
                $tx, $toolRequestId, $dispatchId, $decisionId, 'FAILED', 'FAILURE_ONLY',
                null, null, 'RUNTIME_MAPPING_UNAVAILABLE', false, null
            );
            $tx->run("UPDATE tool_requests SET status = 'FAILED' WHERE tool_request_id = ?", [$toolRequestId]);
            $this->writeIdempotencyRecord($tx, $command, $toolRequestId, $decisionId, $outcomeId, $inputDigest);

            return ['kind' => 'EARLY', 'decision' => [
                'kind' => 'ALLOWED_AND_DISPATCHED',
                'tool_request_id' => $toolRequestId,
                'tool_authorization_decision_id' => $decisionId,
                'tool_dispatch_id' => $dispatchId,
                'tool_execution_outcome_id' => $outcomeId,
                'outcome_status' => 'FAILED',
                'outcome_classification' => 'FAILURE_ONLY',
                'evidence_capture_candidate' => false,
            ]];
        }

        $dispatchId = Ids::new();
        $tx->insert('tool_dispatch_records', [
            'tool_dispatch_id' => $dispatchId,
            'schema_version' => '1',
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'runtime_host_binding_id' => $runtimeBindingId,
            'action_class' => $command['requested_action_class'],
            'exact_dispatched_input_digest' => $inputDigest,
            'dispatched_at' => $this->clock->now(),
            'status' => 'DISPATCHED',
        ]);

        $m01Command = [
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'governed_tool_id' => (string) $command['governed_tool_id'],
            'runtime_host_tool_ref' => (string) $bindingTool['runtime_host_tool_ref'],
            'action_class' => (string) $command['requested_action_class'],
            'tenant_id' => (string) $command['tenant_id'],
            'workspace_id' => (string) $command['workspace_id'],
            'correlation_id' => (string) $command['correlation_id'],
        ];

        // Minted ONLY here, only after the dispatch row exists, and only for this exact command.
        $admission = DispatchAdmission::mint($this->db, [
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'tool_dispatch_id' => $dispatchId,
            'governed_tool_id' => (string) $command['governed_tool_id'],
            'governed_tool_operation_id' => (string) $command['governed_tool_operation_id'],
            'runtime_host_binding_id' => $runtimeBindingId,
            'runtime_host_tool_ref' => (string) $bindingTool['runtime_host_tool_ref'],
            'action_class' => (string) $command['requested_action_class'],
            'dispatched_input_digest' => $inputDigest,
            'tenant_id' => (string) $command['tenant_id'],
            'workspace_id' => (string) $command['workspace_id'],
            'correlation_id' => (string) $command['correlation_id'],
            // The LIVE descriptor surface, not the decision digest: M01 re-reads and compares it.
            'authority_snapshot_digest' => AuthoritySnapshot::digestFor(
                $this->db,
                (string) $command['governed_tool_id'],
                (string) $command['governed_tool_operation_id']
            ),
        ]);

        return ['kind' => 'READY', 'prepared' => [
            'command' => $command,
            'tool_request_id' => $toolRequestId,
            'decision_id' => $decisionId,
            'dispatch_id' => $dispatchId,
            'evidence_capture_required' => (int) $tool['requires_evidence_capture'] === 1
                || (int) $tool['evidence_capture_required_by_default'] === 1,
            'm01_command' => $m01Command,
            'input_digest' => $inputDigest,
            'admission' => $admission,
        ]];
    }

    // ---------------------------------------------------------------- phase 5

    /**
     * Record the execution outcome from the M01 result, advance the request status and write the
     * idempotency record.
     *
     * COMPLETION IS ONE ATOMIC ACT, for the same reason preparation is: the outcome row, the
     * request status and the idempotency record describe one finished dispatch. A partial
     * completion would leave a recorded outcome with no replay key — and the next identical request
     * would then be authorized and dispatched a SECOND time.
     *
     * A non-SUCCEEDED M01 outcome — including a fail-closed live-capture refusal — records a FAILED
     * outcome that cannot seed evidence. The M01 refusal is itself in `runtime_host_execution_refs`,
     * with the specific upstream reason in `error_code`.
     *
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $m01Outcome
     * @return array<string, mixed>
     */
    private function finish(array $prepared, array $m01Outcome): array
    {
        $command = $prepared['command'];
        $toolRequestId = $prepared['tool_request_id'];
        $decisionId = $prepared['decision_id'];
        $dispatchId = $prepared['dispatch_id'];
        $inputDigest = $prepared['input_digest'];

        if (($m01Outcome['outcome'] ?? '') === 'SUCCEEDED') {
            $evidenceCandidate = $m01Outcome['output_classification'] === 'SOURCE_CANDIDATE'
                && $prepared['evidence_capture_required'];
            $outcomeId = $this->db->transaction(function (Database $tx) use (
                $command, $toolRequestId, $dispatchId, $decisionId, $m01Outcome, $evidenceCandidate, $inputDigest
            ): string {
                $id = $this->recordOutcome(
                    $tx, $toolRequestId, $dispatchId, $decisionId, 'SUCCEEDED',
                    (string) $m01Outcome['output_classification'],
                    (string) $m01Outcome['result_reference_safe'],
                    (string) $m01Outcome['result_digest'],
                    null, $evidenceCandidate, (string) $m01Outcome['runtime_host_execution_ref']
                );
                $tx->run("UPDATE tool_requests SET status = 'SUCCEEDED' WHERE tool_request_id = ?", [$toolRequestId]);
                $this->writeIdempotencyRecord($tx, $command, $toolRequestId, $decisionId, $id, $inputDigest);

                return $id;
            });

            return [
                'kind' => 'ALLOWED_AND_DISPATCHED',
                'tool_request_id' => $toolRequestId,
                'tool_authorization_decision_id' => $decisionId,
                'tool_dispatch_id' => $dispatchId,
                'tool_execution_outcome_id' => $outcomeId,
                'outcome_status' => 'SUCCEEDED',
                'outcome_classification' => $m01Outcome['output_classification'],
                'evidence_capture_candidate' => $evidenceCandidate,
                'runtime_host_execution_ref' => $m01Outcome['runtime_host_execution_ref'],
                'result_reference_safe' => $m01Outcome['result_reference_safe'],
                'result_digest' => $m01Outcome['result_digest'],
            ];
        }

        // The SPECIFIC upstream reason is preserved. Replacing it with a generic failure code here
        // would erase exactly the distinction an operator needs: an SSRF refusal and a timeout are
        // not the same event.
        $errorCode = (string) ($m01Outcome['guard_reason_code'] ?? $m01Outcome['reason_code'] ?? 'M01_REFUSED');
        $outcomeId = $this->db->transaction(function (Database $tx) use (
            $command, $toolRequestId, $dispatchId, $decisionId, $errorCode, $inputDigest
        ): string {
            $id = $this->recordOutcome(
                $tx, $toolRequestId, $dispatchId, $decisionId, 'FAILED', 'FAILURE_ONLY',
                null, null, $errorCode, false, null
            );
            $tx->run("UPDATE tool_requests SET status = 'FAILED' WHERE tool_request_id = ?", [$toolRequestId]);
            $this->writeIdempotencyRecord($tx, $command, $toolRequestId, $decisionId, $id, $inputDigest);

            return $id;
        });

        return [
            'kind' => 'ALLOWED_AND_DISPATCHED',
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'tool_dispatch_id' => $dispatchId,
            'tool_execution_outcome_id' => $outcomeId,
            'outcome_status' => 'FAILED',
            'outcome_classification' => 'FAILURE_ONLY',
            'evidence_capture_candidate' => false,
            'm01_reason_code' => $m01Outcome['reason_code'] ?? null,
        ];
    }

    // ---------------------------------------------------------------- refusal

    /**
     * The refusal is ONE ATOMIC ACT too: the canonical decision, the non-dispatch proof and the
     * request status are a single governed event. A decision without its escalation record would
     * look like a refusal that was never escalated.
     *
     * @param list<string> $passed
     * @param list<string> $failed
     * @param list<string> $reasonCodes
     * @return array<string, mixed>
     */
    private function finalizeDeny(
        Database $tx,
        string $toolRequestId,
        array $passed,
        array $failed,
        array $reasonCodes,
        string $severity,
        string $decisionKind,
        string $refusalCategory,
    ): array {
        $decisionId = Ids::new();
        $escalationId = Ids::new();
        $now = $this->clock->now();

        $tx->insert('tool_authorization_decisions', [
            'tool_authorization_decision_id' => $decisionId,
            'schema_version' => '1',
            'tool_request_id' => $toolRequestId,
            'decision' => $decisionKind,
            'validated_action_class' => null,
            'passed_gate_check_ids' => json_encode($passed, JSON_THROW_ON_ERROR),
            'failed_or_holding_gate_check_ids' => json_encode($failed, JSON_THROW_ON_ERROR),
            'reason_codes' => json_encode(array_values($reasonCodes), JSON_THROW_ON_ERROR),
            'decision_digest' => Digest::canonicalDigest([
                'toolRequestId' => $toolRequestId,
                'decision' => $decisionKind,
                'reasonCodes' => array_values($reasonCodes),
            ]),
            'hash_algorithm' => 'sha256',
            'decided_at' => $now,
        ]);
        $tx->insert('tool_denial_escalation_records', [
            'tool_denial_escalation_id' => $escalationId,
            'schema_version' => '1',
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'refusal_category' => $refusalCategory,
            'severity' => $severity,
            'failed_gate_check_ids' => json_encode($failed, JSON_THROW_ON_ERROR),
            // CHECK-pinned to 1. The positive proof of non-dispatch, which the ABSENCE of a
            // dispatch row could never give: absence is also what an unrecorded dispatch looks like.
            'no_dispatch_confirmed' => 1,
            'incident_evaluation_required' => in_array($severity, ['S4', 'S5'], true) ? 1 : 0,
            'safe_summary' => implode(',', $reasonCodes),
            'created_at' => $now,
        ]);
        $tx->run('UPDATE tool_requests SET status = ? WHERE tool_request_id = ?', [
            match ($decisionKind) {
                'DENY' => 'DENIED',
                'HOLD_FOR_REVIEW' => 'HELD',
                'REQUIRE_APPROVAL' => 'REQUIRE_APPROVAL',
                default => 'QUARANTINED',
            },
            $toolRequestId,
        ]);

        return [
            'kind' => match ($decisionKind) {
                'DENY' => 'DENIED',
                'HOLD_FOR_REVIEW' => 'HELD',
                'REQUIRE_APPROVAL' => 'REQUIRE_APPROVAL',
                default => 'QUARANTINED',
            },
            'tool_request_id' => $toolRequestId,
            'tool_authorization_decision_id' => $decisionId,
            'tool_denial_escalation_id' => $escalationId,
            'reason_codes' => array_values($reasonCodes),
            'severity' => $severity,
            'no_dispatch_confirmed' => true,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function recordOutcome(
        Database $tx,
        string $toolRequestId,
        string $dispatchId,
        string $decisionId,
        string $outcomeStatus,
        string $classification,
        ?string $resultReferenceSafe,
        ?string $resultDigest,
        ?string $errorCode,
        bool $evidenceCandidate,
        ?string $runtimeExecutionRef,
    ): string {
        $outcomeId = Ids::new();
        $tx->insert('tool_execution_outcomes', [
            'tool_execution_outcome_id' => $outcomeId,
            'schema_version' => '1',
            'tool_request_id' => $toolRequestId,
            'tool_dispatch_id' => $dispatchId,
            'tool_authorization_decision_id' => $decisionId,
            'runtime_host_execution_ref' => $runtimeExecutionRef,
            'outcome_status' => $outcomeStatus,
            'outcome_classification' => $classification,
            'result_reference_safe' => $resultReferenceSafe,
            'result_digest' => $resultDigest,
            'evidence_capture_candidate' => $evidenceCandidate ? 1 : 0,
            'error_code' => $errorCode,
            'completed_at' => $this->clock->now(),
        ]);

        return $outcomeId;
    }

    /** @param array<string, mixed> $command */
    private function writeIdempotencyRecord(
        Database $tx,
        array $command,
        string $toolRequestId,
        string $decisionId,
        string $outcomeId,
        string $inputDigest,
    ): void {
        $tx->insert('tool_idempotency_records', [
            'idempotency_record_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $command['tenant_id'],
            'idempotency_key' => $command['idempotency_key'],
            'governed_tool_id' => $command['governed_tool_id'],
            'normalized_input_digest' => $inputDigest,
            'prior_tool_request_id' => $toolRequestId,
            'prior_tool_authorization_decision_id' => $decisionId,
            'prior_tool_execution_outcome_id' => $outcomeId,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * The id if the referenced row exists, otherwise null.
     *
     * Used only for the request row's optional foreign keys. It is NOT an authority check — the
     * gates decide authority — it is the difference between recording a relationship that holds
     * and asserting one that does not.
     */
    private function resolved(string $table, string $column, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $exists = $this->db->one(
            sprintf('SELECT %s FROM %s WHERE %s = ?', $column, $table, $column),
            [$id]
        );

        return $exists === null ? null : $id;
    }

    /** @param array<string, mixed> $command */
    private function priorIdempotencyRecord(array $command): ?array
    {
        return $this->db->one(
            'SELECT prior_tool_request_id, prior_tool_authorization_decision_id,
                    prior_tool_execution_outcome_id, normalized_input_digest
             FROM tool_idempotency_records WHERE tenant_id = ? AND idempotency_key = ?',
            [$command['tenant_id'], $command['idempotency_key']]
        );
    }

    /**
     * Dispatch-time entitlement resolution: does the tenant's live subscription to an ACTIVE tier
     * grant this permission?
     *
     * INLINED here rather than imported from the P01 commercial layer, to preserve the dependency
     * direction — the certified dispatch gate never imports a product module. It mirrors the same
     * entitlement plane the P01 read model uses; the two must be kept in sync.
     */
    private function tenantActiveTierGrants(string $tenantId, string $permissionKey): bool
    {
        return $this->db->one(
            "SELECT 1 AS hit
             FROM tenant_subscriptions ts
             JOIN subscription_tiers st ON st.subscription_tier_id = ts.subscription_tier_id
                  AND st.status = 'ACTIVE'
             JOIN subscription_tier_permissions stp ON stp.subscription_tier_id = st.subscription_tier_id
             WHERE ts.tenant_id = ? AND ts.status IN ('TRIAL', 'ACTIVE') AND stp.permission_key = ?
             LIMIT 1",
            [$tenantId, $permissionKey]
        ) !== null;
    }

    /** @param array<string, mixed> $command */
    private static function inputDigestFor(array $command): string
    {
        return Registry::inputDigest([
            'governed_tool_id' => (string) $command['governed_tool_id'],
            'governed_tool_operation_id' => (string) $command['governed_tool_operation_id'],
            'mission_id' => $command['mission_id'] ?? null,
            'valkyrie_id' => $command['valkyrie_id'] ?? null,
            'normalized_input_ref_safe' => $command['normalized_input_ref_safe'] ?? null,
        ]);
    }
}
