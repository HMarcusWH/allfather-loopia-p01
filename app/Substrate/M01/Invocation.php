<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M06\AuthoritySnapshot;
use Allfather\Loopia\Substrate\M06\DecisionIntegrity;

/**
 * M01 invocation — the ONLY way a governed tool call reaches a runtime host, and M06 is its only
 * legitimate caller (INV-006).
 *
 * A request passes only when ALL of these hold:
 *   1. the caller presents a nominal, single-use M06 dispatch admission bound to this exact
 *      database handle and to one persisted `tool_dispatch_records` row;
 *   2. the pre-dispatch validation passes (binding, host, adapter, scope, safety class);
 *   3. a matching `tool_authorization_decisions` row exists with `decision = 'ALLOW'` whose stored
 *      evidence survives its own integrity law; and
 *   4. the binding's safety classification is dispatchable.
 *
 * WHAT THIS PROJECTION DOES NOT PORT. The canonical module also carries the Harness native adapter
 * path and the Class-E demo-action path. Both are excluded here:
 *
 *   - The Harness path is the host adapter / agent runtime, which is outside this projection's
 *     boundary.
 *   - Class E (`EXECUTE_CONSEQUENTIAL_ACTION`) requires an M09 single-use approval and a frozen
 *     artifact. There is no M09 plane here, so rather than porting a branch that could never be
 *     satisfied, the class is refused outright — and separately made unreachable by M02 (the
 *     permission is EXCLUDED), by M03 (a contract granting it cannot be issued) and by the M06 V1
 *     scope filter. `HARMLESS_DEMO_ACTION` and `DEMO_ADAPTER_SURFACE` are therefore never
 *     dispatchable on any path here.
 *
 * The remaining dispatchable surface is read-only: a deterministic synthetic fixture, and the live
 * egress-guarded source capture P01 needs. NO external effect is reachable through either.
 */
final class Invocation
{
    /** Egress/transport reasons that indicate an SSRF or trust-boundary attempt — escalated to S4. */
    private const LIVE_CAPTURE_SECURITY_REASONS = [
        'REFUSED_BLOCKED_IP',
        'REFUSED_REDIRECT_CROSSED_BOUNDARY',
        'REFUSED_HOST_NOT_ALLOWLISTED',
    ];

    /** The gate types a stored ALLOW must be able to show for a standard gateway dispatch. */
    private const REQUIRED_GATE_TYPES = [
        'M02_ACCESS_SCOPE',
        'M03_CAPABILITY_CONTRACT',
        'M04_MISSION_SCOPE',
        'M06_TOOL_DESCRIPTOR',
        'V1_SCOPE_PROHIBITION',
    ];

    private const ACTIVE_OPERATION_STATUS = 'ACTIVE';
    private const ACTIVE_TOOL_STATUS = 'ACTIVE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Synthetic safe-read dispatch.
     *
     * There is no live host tool on this path, so the adapter produces a deterministic fixture
     * result. The digest is computed from a canonical projection of the request, so identical
     * inputs yield identical results — which is what makes replay and idempotency checkable. The
     * safe reference is a synthetic, content-free pointer: no secrets, no raw payload.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function invokeAuthorizedHostTool(array $command, mixed $admission = null): array
    {
        $validated = $this->validate($command, true);
        if ($validated['ok'] === false) {
            return $validated['outcome'];
        }

        // The synthetic fixture IS this path's dispatch, so M06 authority is proven and consumed
        // before the fixture is produced — not after.
        $admitted = $this->admit($command, $validated, $admission);
        if ($admitted['ok'] === false) {
            return $admitted['outcome'];
        }
        $binding = $admitted['binding'];
        $dispatchId = (string) $binding['tool_dispatch_id'];

        $resultDigest = Digest::canonicalDigest([
            'toolRequestId' => $command['tool_request_id'],
            'governedToolId' => $command['governed_tool_id'],
            'actionClass' => $command['action_class'],
            'tenantId' => $command['tenant_id'],
            'workspaceId' => $command['workspace_id'] ?? null,
        ]);
        $classification = match ($validated['safety_classification']) {
            'SAFE_INTERNAL_TRANSFORM' => 'TRANSFORM_OUTPUT',
            default => 'SOURCE_CANDIDATE',
        };
        $hostExecutionRef = sprintf('host-exec:%s:%s', $command['governed_tool_id'], $command['tool_request_id']);
        $resultReferenceSafe = sprintf('fixture://%s/%s', $command['governed_tool_id'], substr($resultDigest, 0, 16));

        $startedAt = $this->clock->now();
        $finalized = $this->finalize($command, $validated, $binding, $startedAt, true, $resultDigest, fn (): string
            => $this->appendSucceeded($command, $validated['binding_id'], $dispatchId, [
                'runtime_host_execution_ref' => $hostExecutionRef,
                'result_reference_safe' => $resultReferenceSafe,
                'result_digest' => $resultDigest,
                'output_classification' => $classification,
            ], $startedAt));
        if ($finalized['ok'] === false) {
            return $finalized['outcome'];
        }

        return [
            'outcome' => 'SUCCEEDED',
            'execution_ref_id' => $finalized['execution_ref_id'],
            'runtime_host_execution_ref' => $hostExecutionRef,
            'result_reference_safe' => $resultReferenceSafe,
            'result_digest' => $resultDigest,
            'output_classification' => $classification,
        ];
    }

    /**
     * Live read-only source capture.
     *
     * The SAME dispatch contract as the synthetic path — it shares `validate()`, so an absent
     * admission or any pre-dispatch failure refuses identically — but instead of a fixture it
     * executes a real, egress-guarded network read through the INJECTED fetch callable. The guard,
     * its transport and its resolver are owned by the caller; M01 imports none of them, so unit
     * tests and replay never touch the network.
     *
     * Only SAFE_READ_ONLY bindings and OBSERVE/ANALYZE action classes may take this path. A guarded
     * refusal or failure is fail-closed: the host execution ref is FAILED, never SUCCEEDED, so it
     * cannot seed evidence — and it never auto-retries.
     *
     * @param array<string, mixed> $command
     * @param callable(): array{kind: string, content_hash?: string, reason_code?: string} $performLiveFetch
     * @return array<string, mixed>
     */
    public function invokeAuthorizedLiveSourceCapture(
        array $command,
        callable $performLiveFetch,
        mixed $admission = null,
    ): array {
        $validated = $this->validate($command, true);
        if ($validated['ok'] === false) {
            return $validated['outcome'];
        }
        if ($validated['safety_classification'] !== 'SAFE_READ_ONLY') {
            return $this->refusePreDispatch($command, 'LIVE_CAPTURE_REQUIRES_SAFE_READ_ONLY', 'S5');
        }
        if (!in_array((string) $command['action_class'], ['OBSERVE', 'ANALYZE'], true)) {
            return $this->refusePreDispatch($command, 'LIVE_CAPTURE_ACTION_CLASS_NOT_READ', 'S5');
        }

        // ── LIVE DISPATCH BOUNDARY ─────────────────────────────────────────────────────────────
        $admitted = $this->admit($command, $validated, $admission);
        if ($admitted['ok'] === false) {
            return $admitted['outcome'];
        }
        $binding = $admitted['binding'];
        $dispatchId = (string) $binding['tool_dispatch_id'];
        $startedAt = $this->clock->now();

        // Everything past the callable has crossed the adapter boundary, so no exit below may be
        // recorded as a PRE-dispatch refusal: the fetch demonstrably ran.
        try {
            $fetched = $performLiveFetch();
        } catch (\Throwable) {
            // The thrown value itself never reaches the database — no message, no stack, no
            // payload. Only this canonical safe code does.
            $finalized = $this->finalize($command, $validated, $binding, $startedAt, false, null, fn (): string
                => $this->appendPostDispatchTerminal(
                    $command, $validated['binding_id'], $dispatchId, 'FAILED',
                    'LIVE_CAPTURE_FETCH_EXCEPTION', null, $startedAt
                ));
            if ($finalized['ok'] === false) {
                return $finalized['outcome'];
            }

            return [
                'outcome' => 'REFUSED',
                'reason_code' => 'LIVE_CAPTURE_FETCH_FAILED',
                'severity' => 'S3',
                'execution_ref_id' => $finalized['execution_ref_id'],
            ];
        }

        if (($fetched['kind'] ?? '') === 'FETCHED') {
            $contentHash = (string) $fetched['content_hash'];
            $hostExecutionRef = sprintf('host-exec:%s:%s', $command['governed_tool_id'], $command['tool_request_id']);
            // The provenance digest IS the captured content hash from the guard: a live capture is
            // bound to exactly the bytes that were read. The safe ref carries no URL and no payload.
            $resultReferenceSafe = sprintf('capture://%s/%s', $command['governed_tool_id'], substr($contentHash, 0, 16));
            $finalized = $this->finalize($command, $validated, $binding, $startedAt, true, $contentHash, fn (): string
                => $this->appendSucceeded($command, $validated['binding_id'], $dispatchId, [
                    'runtime_host_execution_ref' => $hostExecutionRef,
                    'result_reference_safe' => $resultReferenceSafe,
                    'result_digest' => $contentHash,
                    'output_classification' => 'SOURCE_CANDIDATE',
                ], $startedAt));
            if ($finalized['ok'] === false) {
                return $finalized['outcome'];
            }

            return [
                'outcome' => 'SUCCEEDED',
                'execution_ref_id' => $finalized['execution_ref_id'],
                'runtime_host_execution_ref' => $hostExecutionRef,
                'result_reference_safe' => $resultReferenceSafe,
                'result_digest' => $contentHash,
                'output_classification' => 'SOURCE_CANDIDATE',
            ];
        }

        // A guarded REFUSE (egress/SSRF policy) or FAILED (timeout/transport): record a FAILED
        // execution ref carrying the SPECIFIC upstream reason, and return a refusal. The gateway
        // then records a non-SUCCEEDED outcome that cannot seed evidence. No auto-retry.
        $guardReason = (string) ($fetched['reason_code'] ?? 'LIVE_CAPTURE_RESULT_INSPECTION_FAILED');
        $reasonCode = ($fetched['kind'] ?? '') === 'REFUSED'
            ? 'LIVE_CAPTURE_FETCH_REFUSED'
            : 'LIVE_CAPTURE_FETCH_FAILED';
        $severity = (($fetched['kind'] ?? '') === 'REFUSED' && in_array($guardReason, self::LIVE_CAPTURE_SECURITY_REASONS, true))
            ? 'S4'
            : 'S3';
        $finalized = $this->finalize($command, $validated, $binding, $startedAt, false, null, fn (): string
            => $this->appendPostDispatchTerminal(
                $command, $validated['binding_id'], $dispatchId, 'FAILED', $guardReason, null, $startedAt
            ));
        if ($finalized['ok'] === false) {
            return $finalized['outcome'];
        }

        return [
            'outcome' => 'REFUSED',
            'reason_code' => $reasonCode,
            'severity' => $severity,
            'execution_ref_id' => $finalized['execution_ref_id'],
            'guard_reason_code' => $guardReason,
        ];
    }

    /** New bounded analysis adapter. Caller-supplied text never becomes source evidence or authority. */
    public function invokeAuthorizedModelTransform(array $command, callable $performModel, mixed $admission = null): array
    {
        $validated=$this->validate($command,true);
        if (!$validated['ok']) return $validated['outcome'];
        $modelDescriptor=(new \Allfather\Loopia\Provider\ModelRegistration($this->db,$this->clock))->validateDispatchIds((string)$command['governed_tool_id'],$validated['operation']['governed_tool_operation_id']);
        if(!$modelDescriptor->isOk())return $this->refusePreDispatch($command,'MODEL_DESCRIPTOR_CONFLICT','S4');
        if ($validated['safety_classification']!=='SAFE_INTERNAL_TRANSFORM' || ($command['action_class']??'')!=='ANALYZE'
            || $validated['operation']['owning_tool_class']!=='INTERNAL_TRANSFORM'
            || $validated['operation']['operation_key']!==\Allfather\Loopia\Provider\ModelRegistration::OPERATION_KEY) {
            return $this->refusePreDispatch($command,'MODEL_TRANSFORM_BINDING_REQUIRED','S4');
        }
        $digest=$command['model_request_digest']??'';
        $ref=$this->db->scalar('SELECT normalized_input_ref_safe FROM tool_requests WHERE tool_request_id=?',[$command['tool_request_id']]);
        if (!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1||$ref!=='model-request://'.$digest) return $this->refusePreDispatch($command,'MODEL_REQUEST_DIGEST_NOT_BOUND','S4');
        $admitted=$this->admit($command,$validated,$admission);
        if (!$admitted['ok']) return $admitted['outcome'];
        $binding=$admitted['binding'];$dispatchId=(string)$binding['tool_dispatch_id'];$startedAt=$this->clock->now();
        try { $result=$performModel((string)$command['tool_request_id']); }
        catch (\Throwable) { $result=['kind'=>'FAILED','reason_code'=>'MODEL_ADAPTER_EXCEPTION']; }
        $success=($result['kind']??'')==='GENERATED' && is_string($result['result_digest']??null) && preg_match('/^[a-f0-9]{64}$/D',$result['result_digest'])===1;
        $resultDigest=$success?$result['result_digest']:null;
        $reason=($result['reason_code']??'MODEL_RESULT_INVALID');
        if (!is_string($reason)||preg_match('/^[A-Z][A-Z0-9_]{0,95}$/D',$reason)!==1) $reason='MODEL_ADAPTER_FAILED';
        $hostRef='host-exec:'.$command['governed_tool_id'].':'.$command['tool_request_id'];
        $safeRef='model-result://'.($resultDigest??$digest);
        $final=$this->finalize($command,$validated,$binding,$startedAt,$success,$resultDigest,
            fn():string=>$success?$this->appendSucceeded($command,$validated['binding_id'],$dispatchId,[
                'runtime_host_execution_ref'=>$hostRef,'result_reference_safe'=>$safeRef,'result_digest'=>$resultDigest,'output_classification'=>'TRANSFORM_OUTPUT',
            ],$startedAt):$this->appendPostDispatchTerminal($command,$validated['binding_id'],$dispatchId,'FAILED',$reason,null,$startedAt));
        if (!$final['ok']) return $final['outcome'];
        if (!$success) return ['outcome'=>'REFUSED','reason_code'=>$reason,'execution_ref_id'=>$final['execution_ref_id'],'severity'=>'S3'];
        return ['outcome'=>'SUCCEEDED','execution_ref_id'=>$final['execution_ref_id'],'runtime_host_execution_ref'=>$hostRef,'result_reference_safe'=>$safeRef,'result_digest'=>$resultDigest,'output_classification'=>'TRANSFORM_OUTPUT'];
    }

    // ---------------------------------------------------------------- pre-dispatch validation

    /**
     * The pre-dispatch validation, shared by both dispatch paths so neither can bypass it.
     *
     * `$persistRefusal` is false on the post-callback re-run: that pass is a comparison, and
     * writing a second refusal row for it would double-count one refusal.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function validate(array $command, bool $persistRefusal): array
    {
        $decide = function (string $reasonCode, string $severity) use ($command, $persistRefusal): array {
            return [
                'ok' => false,
                'outcome' => $persistRefusal
                    ? $this->refusePreDispatch($command, $reasonCode, $severity)
                    : ['outcome' => 'REFUSED', 'reason_code' => $reasonCode, 'severity' => $severity, 'execution_ref_id' => ''],
            ];
        };

        $toolRef = $this->db->one(
            'SELECT t.tool_ref_id, t.binding_id, t.declared_operation_class, t.mapping_status,
                    b.status AS binding_status, b.binding_type, b.safety_classification,
                    b.runtime_adapter_instance_id, b.permitted_operation_classes,
                    b.tenant_id, b.workspace_id,
                    a.status AS adapter_status, a.runtime_host_instance_id,
                    a.adapter_family, a.adapter_name, a.adapter_version,
                    a.descriptor_identity, a.capability_manifest_identity,
                    a.conformance_profile_identity, a.identity_digest,
                    h.status AS host_status
             FROM runtime_host_tool_refs t
             JOIN runtime_host_bindings b ON b.binding_id = t.binding_id
             JOIN runtime_adapter_instances a ON a.runtime_adapter_instance_id = b.runtime_adapter_instance_id
             JOIN runtime_host_instances h ON h.runtime_host_instance_id = b.runtime_host_instance_id
             WHERE t.runtime_host_tool_ref = ? AND t.governed_tool_id = ?',
            [$command['runtime_host_tool_ref'], $command['governed_tool_id']]
        );
        if ($toolRef === null) {
            return $decide('TOOL_REF_NOT_FOUND', 'S2');
        }
        if ((string) $toolRef['mapping_status'] !== 'ACTIVE') {
            return $decide('TOOL_REF_NOT_ACTIVE', 'S2');
        }
        // An ACTIVE binding is dispatchable only while its host remains REGISTERED and the exact
        // stored adapter remains READY and conformant. Lifecycle changes therefore fail closed at
        // EVERY invocation, not only at registration.
        $conformant = AdapterConformance::verify([
            'runtime_host_instance_id' => (string) $toolRef['runtime_host_instance_id'],
            'adapter_family' => (string) $toolRef['adapter_family'],
            'adapter_name' => (string) $toolRef['adapter_name'],
            'adapter_version' => (string) $toolRef['adapter_version'],
            'descriptor_identity' => (string) $toolRef['descriptor_identity'],
            'capability_manifest_identity' => (string) $toolRef['capability_manifest_identity'],
            'conformance_profile_identity' => (string) $toolRef['conformance_profile_identity'],
            'identity_digest' => (string) $toolRef['identity_digest'],
        ]);
        if ((string) $toolRef['binding_status'] !== 'ACTIVE'
            || (string) $toolRef['host_status'] !== 'REGISTERED'
            || (string) $toolRef['adapter_status'] !== 'READY'
            || !$conformant
        ) {
            return $decide('BINDING_NOT_ACTIVE', 'S2');
        }

        $permitted = json_decode((string) $toolRef['permitted_operation_classes'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array((string) $command['action_class'], $permitted, true)) {
            return $decide('ACTION_CLASS_NOT_PERMITTED_ON_BINDING', 'S3');
        }
        // Class E has no satisfiable path in this projection: there is no M09 plane to issue the
        // single-use authorization it requires. It is refused HERE rather than left to the demo
        // branch, so no code exists that could dispatch it if the branch were ever reached.
        if ((string) $command['action_class'] === 'EXECUTE_CONSEQUENTIAL_ACTION') {
            return $decide('CLASS_E_NOT_AVAILABLE_IN_PROJECTION', 'S5');
        }
        if ($toolRef['tenant_id'] !== null && (string) $toolRef['tenant_id'] !== (string) $command['tenant_id']) {
            return $decide('TENANT_WORKSPACE_SCOPE_MISMATCH', 'S4');
        }
        // A workspace-scoped binding is never dispatchable without the exact workspace. Omitting
        // the workspace is a MISMATCH, not an unscoped wildcard.
        if ($toolRef['workspace_id'] !== null && (string) $toolRef['workspace_id'] !== (string) ($command['workspace_id'] ?? '')) {
            return $decide('TENANT_WORKSPACE_SCOPE_MISMATCH', 'S4');
        }

        // The M06-issued ALLOW. M01 joins the canonical request and RE-BINDS the decision to
        // tenant, workspace, governed tool and action class, rather than trusting an ALLOW row or
        // the caller's ids in isolation.
        $auth = $this->db->one(
            'SELECT d.tool_authorization_decision_id, d.tool_request_id, d.decision,
                    d.validated_action_class,
                    r.tenant_id AS request_tenant_id, r.workspace_id AS request_workspace_id,
                    r.governed_tool_id AS request_governed_tool_id,
                    r.governed_tool_operation_id AS request_operation_id,
                    r.requested_action_class AS request_action_class,
                    o.governed_tool_operation_id AS operation_id, o.operation_key,
                    o.status AS operation_status, o.governed_tool_id AS operation_governed_tool_id,
                    o.operation_class, o.classified_action_class,
                    g.governed_tool_id AS owning_tool_id, g.status AS owning_tool_status,
                    g.tool_class AS owning_tool_class, g.descriptor_version AS owning_tool_descriptor_version
             FROM tool_authorization_decisions d
             JOIN tool_requests r ON r.tool_request_id = d.tool_request_id
             -- LEFT JOIN so an ABSENT operation row is a distinguishable governed refusal rather
             -- than collapsing into AUTHORIZATION_DECISION_NOT_FOUND. The owning tool is joined
             -- through the operation so its lifecycle is checked exactly as the M06 gate does.
             LEFT JOIN governed_tool_operations o ON o.governed_tool_operation_id = r.governed_tool_operation_id
             LEFT JOIN governed_tools g ON g.governed_tool_id = o.governed_tool_id
             WHERE d.tool_authorization_decision_id = ?',
            [$command['tool_authorization_decision_id']]
        );
        if ($auth === null) {
            return $decide('AUTHORIZATION_DECISION_NOT_FOUND', 'S2');
        }
        if ((string) $auth['tool_request_id'] !== (string) $command['tool_request_id']) {
            return $decide('AUTHORIZATION_DECISION_REQUEST_MISMATCH', 'S4');
        }
        if ((string) $auth['decision'] !== 'ALLOW') {
            return $decide('AUTHORIZATION_DECISION_NOT_ALLOW', 'S2');
        }
        if ((string) $auth['request_tenant_id'] !== (string) $command['tenant_id']
            || (string) $auth['request_workspace_id'] !== (string) ($command['workspace_id'] ?? '')
            || (string) $auth['request_governed_tool_id'] !== (string) $command['governed_tool_id']
            || (string) $auth['request_action_class'] !== (string) $command['action_class']
            || (string) $auth['validated_action_class'] !== (string) $command['action_class']
        ) {
            return $decide('AUTHORIZATION_DECISION_REQUEST_MISMATCH', 'S4');
        }

        // Governed-operation lifecycle law. Validating only the operation KEY would let a
        // SUSPENDED, REVOKED or FORBIDDEN operation — or one that now belongs to a different
        // governed tool — dispatch through M01 while the M06 descriptor gate would refuse it.
        if ($auth['operation_id'] === null || $auth['operation_status'] === null
            || $auth['operation_governed_tool_id'] === null || $auth['classified_action_class'] === null
        ) {
            return $decide('GOVERNED_OPERATION_NOT_FOUND', 'S2');
        }
        if ((string) $auth['operation_id'] !== (string) $auth['request_operation_id']) {
            return $decide('GOVERNED_OPERATION_IDENTITY_MISMATCH', 'S4');
        }
        if ((string) $auth['operation_governed_tool_id'] !== (string) $command['governed_tool_id']) {
            return $decide('GOVERNED_OPERATION_OWNER_MISMATCH', 'S4');
        }
        if ((string) $auth['operation_status'] !== self::ACTIVE_OPERATION_STATUS) {
            return $decide('GOVERNED_OPERATION_NOT_ACTIVE', 'S2');
        }
        // M06 descriptor parity: the operation's classified action class IS the class it may be
        // dispatched under. Without this, an operation reclassified after the ALLOW still runs.
        if ((string) $auth['classified_action_class'] !== (string) $command['action_class']
            || (string) $auth['classified_action_class'] !== (string) $auth['request_action_class']
        ) {
            return $decide('GOVERNED_OPERATION_ACTION_CLASS_MISMATCH', 'S4');
        }
        if ($auth['owning_tool_id'] === null || $auth['owning_tool_status'] === null) {
            return $decide('GOVERNED_TOOL_NOT_FOUND', 'S2');
        }
        if ((string) $auth['owning_tool_status'] !== self::ACTIVE_TOOL_STATUS) {
            return $decide('GOVERNED_TOOL_NOT_ACTIVE', 'S2');
        }

        // The dispatch filter. Only the two READ-SAFE classifications remain dispatchable in this
        // projection; HARMLESS_DEMO_ACTION would require the Class-E path that is refused above,
        // and UNSAFE_BLOCKED is never dispatchable anywhere.
        if (!in_array((string) $toolRef['safety_classification'], ['SAFE_READ_ONLY', 'SAFE_INTERNAL_TRANSFORM'], true)) {
            return $decide('BINDING_SAFETY_CLASSIFICATION_NOT_DISPATCHABLE_IN_V1', 'S5');
        }

        return [
            'ok' => true,
            'binding_id' => (string) $toolRef['binding_id'],
            'safety_classification' => (string) $toolRef['safety_classification'],
            'adapter_identity' => [
                'descriptor_identity' => (string) $toolRef['descriptor_identity'],
                'capability_manifest_identity' => (string) $toolRef['capability_manifest_identity'],
                'conformance_profile_identity' => (string) $toolRef['conformance_profile_identity'],
                'identity_digest' => (string) $toolRef['identity_digest'],
            ],
            'operation' => [
                'governed_tool_operation_id' => (string) $auth['operation_id'],
                'operation_key' => (string) $auth['operation_key'],
                'operation_status' => (string) $auth['operation_status'],
                'owning_governed_tool_id' => (string) $auth['operation_governed_tool_id'],
                'operation_class' => (string) $auth['operation_class'],
                'classified_action_class' => (string) $auth['classified_action_class'],
                'owning_tool_status' => (string) $auth['owning_tool_status'],
                'owning_tool_class' => (string) ($auth['owning_tool_class'] ?? ''),
                'owning_tool_descriptor_version' => (string) ($auth['owning_tool_descriptor_version'] ?? ''),
            ],
            'runtime_host_instance_id' => (string) $toolRef['runtime_host_instance_id'],
            'runtime_adapter_instance_id' => (string) $toolRef['runtime_adapter_instance_id'],
            'binding_tenant_id' => $toolRef['tenant_id'] === null ? null : (string) $toolRef['tenant_id'],
            'binding_workspace_id' => $toolRef['workspace_id'] === null ? null : (string) $toolRef['workspace_id'],
            'runtime_host_tool_ref' => (string) $command['runtime_host_tool_ref'],
        ];
    }

    // ---------------------------------------------------------------- the M06 authority gate

    /**
     * The single M06 authority gate. Runs BEFORE any adapter or fetch, consuming the admission so a
     * retained one cannot drive a second execution.
     *
     * ORDER IS LAW HERE. Everything that can still refuse runs FIRST, and only then is the
     * irreversible execution claim taken. Claiming before the command and authority comparisons
     * would permanently burn a dispatch act whose command never matched — costing the caller the
     * ability to ever dispatch that authorization, and leaving an audit trail saying a dispatch was
     * claimed when nothing was admitted.
     *
     * @param array<string, mixed> $command
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function admit(array $command, array $validated, mixed $admission): array
    {
        $verified = DispatchAdmission::verify($this->db, $admission);
        if ($verified['ok'] === false) {
            return ['ok' => false, 'outcome' => $this->refuseAdmission($command, $verified['reason'])];
        }
        $binding = $verified['binding'];

        // The admission must describe THIS command. A valid admission for another request,
        // decision, tool, mapping, scope or action class authorizes nothing here.
        if ($binding['tool_request_id'] !== (string) $command['tool_request_id']
            || $binding['tool_authorization_decision_id'] !== (string) $command['tool_authorization_decision_id']
            || $binding['governed_tool_id'] !== (string) $command['governed_tool_id']
            || $binding['runtime_host_tool_ref'] !== (string) $command['runtime_host_tool_ref']
            || $binding['action_class'] !== (string) $command['action_class']
            || $binding['tenant_id'] !== (string) $command['tenant_id']
            || $binding['workspace_id'] !== (string) ($command['workspace_id'] ?? '')
            || $binding['correlation_id'] !== (string) ($command['correlation_id'] ?? '')
        ) {
            return ['ok' => false, 'outcome' => $this->refuseAdmission($command, 'M06_DISPATCH_ADMISSION_COMMAND_MISMATCH')];
        }

        // The descriptor surface that produced the ALLOW is RE-READ from live rows and compared to
        // the digest the admission carries. A tool class, ceiling, runtime mapping, descriptor
        // version, approval requirement or operation field edited between authorization and
        // dispatch changes the digest, so the dispatch fails closed.
        if (AuthoritySnapshot::digestFor($this->db, $binding['governed_tool_id'], $binding['governed_tool_operation_id'])
            !== $binding['authority_snapshot_digest']
        ) {
            return ['ok' => false, 'outcome' => $this->refuseAdmission($command, 'M06_AUTHORITY_SNAPSHOT_DRIFTED')];
        }

        // The stored ALLOW evidence must survive its own integrity law before it authorizes
        // anything: the decision digest recomputes from persisted rows, and every gate check it
        // cites exists, belongs to this request, and actually passed. A flat `decision = 'ALLOW'`
        // read is not evidence.
        $evidence = DecisionIntegrity::verify(
            $this->db,
            (string) $command['tool_request_id'],
            (string) $command['tool_authorization_decision_id'],
            (string) $command['action_class'],
            self::REQUIRED_GATE_TYPES,
        );
        if ($evidence['ok'] === false) {
            return ['ok' => false, 'outcome' => $this->refuseAdmission($command, 'AUTHORIZATION_EVIDENCE_INTEGRITY_FAILED')];
        }

        // Nothing above this line changed durable state; nothing below it can refuse. The claim is
        // the last pre-callback authority act.
        $claimed = DispatchAdmission::claimExecution(
            $this->db, $admission, (string) $binding['tool_dispatch_id'], $this->clock->now()
        );
        if ($claimed['ok'] === false) {
            return ['ok' => false, 'outcome' => $this->refuseAdmission($command, $claimed['reason'])];
        }

        return ['ok' => true, 'binding' => $binding];
    }

    // ---------------------------------------------------------------- terminal finalization

    /**
     * Write the terminal row, re-proving authority under the write lock.
     *
     * The re-proof is NOT performed before this transaction: a separate check outside it would only
     * reintroduce the window it is meant to close. It happens in the SAME transaction that would
     * otherwise carry the success row, so no concurrent authority change can slip between the check
     * and the row.
     *
     * @param array<string, mixed> $command
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $binding
     * @param callable(): string $write
     * @return array{ok: true, execution_ref_id: string}|array{ok: false, outcome: array<string, mixed>}
     */
    private function finalize(
        array $command,
        array $validated,
        array $binding,
        string $startedAt,
        bool $claimsSuccess,
        ?string $resultDigest,
        callable $write,
    ): array {
        $dispatchId = (string) $binding['tool_dispatch_id'];

        try {
            return $this->db->transaction(function (Database $tx) use (
                $command, $validated, $binding, $dispatchId, $startedAt, $claimsSuccess, $resultDigest, $write
            ): array {
                $placeholders = implode(', ', array_fill(0, count(DispatchAdmission::TERMINAL_EXECUTION_STATUSES), '?'));
                $existing = $tx->one(
                    "SELECT execution_ref_id, status FROM runtime_host_execution_refs
                     WHERE tool_dispatch_id = ? AND status IN ({$placeholders})",
                    [$dispatchId, ...DispatchAdmission::TERMINAL_EXECUTION_STATUSES]
                );
                if ($existing !== null) {
                    // A terminal row already exists and someone else wrote it. The caller's
                    // intended outcome is NOT reported against it: the reference resolves to that
                    // row, and the reason says the dispatch was already closed rather than
                    // implying this call produced it.
                    return ['ok' => false, 'outcome' => [
                        'outcome' => 'REFUSED',
                        'reason_code' => 'DISPATCH_ALREADY_TERMINAL',
                        'severity' => 'S4',
                        'execution_ref_id' => (string) $existing['execution_ref_id'],
                        'existing_status' => (string) $existing['status'],
                    ]];
                }

                if ($claimsSuccess && $this->finalStateInvalid($command, $validated, $binding)) {
                    $refusalId = $this->appendPostDispatchTerminal(
                        $command, $validated['binding_id'], $dispatchId, 'REFUSED_POST_DISPATCH',
                        'POST_CALLBACK_AUTHORITY_DRIFTED', $resultDigest, $startedAt
                    );

                    return ['ok' => false, 'outcome' => [
                        'outcome' => 'REFUSED',
                        'reason_code' => 'POST_CALLBACK_AUTHORITY_DRIFTED',
                        'severity' => 'S4',
                        'execution_ref_id' => $refusalId,
                    ]];
                }

                return ['ok' => true, 'execution_ref_id' => $write()];
            });
        } catch (\Throwable) {
            // The intended row could not be written. The minimal TRUTHFUL terminal row asserts only
            // what is certain — the dispatch act happened and its result could not be recorded —
            // and flags the side effect as uncertain so no downstream reader treats it as known.
            try {
                $unknownId = $this->appendUnknown($command, $validated['binding_id'], $dispatchId, $startedAt);

                return ['ok' => false, 'outcome' => [
                    'outcome' => 'REFUSED',
                    'reason_code' => 'DISPATCH_FINALIZATION_FAILED',
                    'severity' => 'S5',
                    'execution_ref_id' => $unknownId,
                ]];
            } catch (\Throwable) {
                // Even the degraded row is unwritable. The host-neutral log is the last resort; if
                // that also fails, the empty id says so honestly rather than naming a row that was
                // never written.
                $auditId = '';
                try {
                    $auditId = $this->appendAdmissionEvent('DISPATCH_FINALIZATION_FAILED', 'DISPATCH_FINALIZATION_FAILED');
                } catch (\Throwable) {
                    // Nothing further this boundary can do; the caller still gets a governed outcome.
                }

                return ['ok' => false, 'outcome' => [
                    'outcome' => 'REFUSED',
                    'reason_code' => 'DISPATCH_FINALIZATION_FAILED',
                    'severity' => 'S5',
                    'execution_ref_id' => '',
                    'governed_admission_event_id' => $auditId,
                ]];
            }
        }
    }

    /**
     * The final-state authority law: is the world still what authorized this dispatch?
     *
     * @param array<string, mixed> $command
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $binding
     */
    private function finalStateInvalid(array $command, array $validated, array $binding): bool
    {
        $claims = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM governed_execution_claims WHERE tool_dispatch_id = ?',
            [$binding['tool_dispatch_id']]
        );
        if ($claims !== 1) {
            return true;
        }

        $dispatch = $this->db->one(
            'SELECT tool_request_id, tool_authorization_decision_id, runtime_host_binding_id,
                    action_class, exact_dispatched_input_digest, status
             FROM tool_dispatch_records WHERE tool_dispatch_id = ?',
            [$binding['tool_dispatch_id']]
        );
        if ($dispatch === null
            || (string) $dispatch['tool_request_id'] !== $binding['tool_request_id']
            || (string) $dispatch['tool_authorization_decision_id'] !== $binding['tool_authorization_decision_id']
            || (string) ($dispatch['runtime_host_binding_id'] ?? '') !== $binding['runtime_host_binding_id']
            || (string) $dispatch['action_class'] !== $binding['action_class']
            || (string) $dispatch['exact_dispatched_input_digest'] !== $binding['dispatched_input_digest']
        ) {
            return true;
        }

        if (DecisionIntegrity::verify(
            $this->db,
            (string) $command['tool_request_id'],
            (string) $command['tool_authorization_decision_id'],
            (string) $command['action_class'],
            self::REQUIRED_GATE_TYPES,
        )['ok'] === false) {
            return true;
        }

        // Re-run the whole pre-dispatch validation and compare it field by field. `$persistRefusal`
        // is false: this pass is a comparison, not a second refusal.
        $revalidated = $this->validate($command, false);
        if ($revalidated['ok'] === false) {
            return true;
        }
        foreach ([
            'binding_id', 'safety_classification', 'runtime_host_instance_id',
            'runtime_adapter_instance_id', 'binding_tenant_id', 'binding_workspace_id',
            'runtime_host_tool_ref',
        ] as $field) {
            if ($revalidated[$field] !== $validated[$field]) {
                return true;
            }
        }
        if ($revalidated['adapter_identity'] !== $validated['adapter_identity']
            || $revalidated['operation'] !== $validated['operation']
        ) {
            return true;
        }

        return AuthoritySnapshot::digestFor($this->db, $binding['governed_tool_id'], $binding['governed_tool_operation_id'])
            !== $binding['authority_snapshot_digest'];
    }

    // ---------------------------------------------------------------- append-only audit writers

    /**
     * A pre-dispatch refusal.
     *
     * The execution-ref insert needs a binding to reference. When the tool ref resolves, the
     * refusal IS a real execution reference. When it does not, the refusal goes to the host-neutral
     * admission log — never against an arbitrary host, and never as a fabricated execution ref.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function refusePreDispatch(array $command, string $reasonCode, string $severity): array
    {
        $bindingRow = $this->db->one(
            'SELECT t.binding_id FROM runtime_host_tool_refs t
             JOIN runtime_host_bindings b ON b.binding_id = t.binding_id
             WHERE t.runtime_host_tool_ref = ? AND t.governed_tool_id = ?',
            [$command['runtime_host_tool_ref'], $command['governed_tool_id']]
        );
        if ($bindingRow === null) {
            return [
                'outcome' => 'REFUSED',
                'reason_code' => $reasonCode,
                'severity' => $severity,
                'execution_ref_id' => '',
                'governed_admission_event_id' => $this->appendAdmissionEvent('DISPATCH_ADMISSION_REFUSED', $reasonCode),
            ];
        }

        $executionRefId = Ids::new();
        $this->db->insert('runtime_host_execution_refs', [
            'execution_ref_id' => $executionRefId,
            'schema_version' => '1',
            'binding_id' => (string) $bindingRow['binding_id'],
            'tool_request_id' => $command['tool_request_id'],
            'tool_authorization_decision_id' => $command['tool_authorization_decision_id'],
            'tool_dispatch_id' => null,
            'execution_authorization_id' => null,
            'artifact_id' => null,
            'status' => 'REFUSED_PRE_DISPATCH',
            'error_code' => $reasonCode,
            'side_effect_uncertainty' => 0,
            'tenant_id' => $command['tenant_id'],
            'workspace_id' => $command['workspace_id'] ?? null,
            'started_at' => $this->clock->now(),
            'correlation_id' => $command['correlation_id'] ?? null,
        ]);

        return [
            'outcome' => 'REFUSED',
            'reason_code' => $reasonCode,
            'severity' => $severity,
            'execution_ref_id' => $executionRefId,
        ];
    }

    /**
     * An admission refusal.
     *
     * Reachable only from `admit()`, where every path that reaches it has left durable state
     * untouched: verification is read-only, the command and authority comparisons are read-only,
     * and a failed claim wrote nothing. So no execution was claimed and nothing dispatched — this
     * really is a pre-dispatch refusal.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function refuseAdmission(array $command, string $reasonCode): array
    {
        return $this->refusePreDispatch($command, $reasonCode, 'S4');
    }

    /** @param array<string, mixed> $command @param array<string, string> $result */
    private function appendSucceeded(
        array $command,
        string $bindingId,
        string $dispatchId,
        array $result,
        string $startedAt,
    ): string {
        $executionRefId = Ids::new();
        $this->db->insert('runtime_host_execution_refs', [
            'execution_ref_id' => $executionRefId,
            'schema_version' => '1',
            'binding_id' => $bindingId,
            'tool_request_id' => $command['tool_request_id'],
            'tool_authorization_decision_id' => $command['tool_authorization_decision_id'],
            'tool_dispatch_id' => $dispatchId,
            'execution_authorization_id' => null,
            'artifact_id' => null,
            'runtime_host_execution_ref' => $result['runtime_host_execution_ref'],
            'status' => 'SUCCEEDED',
            'result_reference_safe' => $result['result_reference_safe'],
            'result_digest' => $result['result_digest'],
            'output_classification' => $result['output_classification'],
            'error_code' => null,
            'side_effect_uncertainty' => 0,
            'tenant_id' => $command['tenant_id'],
            'workspace_id' => $command['workspace_id'] ?? null,
            'started_at' => $startedAt,
            'completed_at' => $this->clock->now(),
            'correlation_id' => $command['correlation_id'] ?? null,
        ]);

        return $executionRefId;
    }

    /**
     * A terminal row for a dispatch that DID cross the adapter boundary.
     *
     * `REFUSED_POST_DISPATCH` and `FAILED` are the only statuses this writes: the callback
     * demonstrably ran, so the audit row must never claim the request was stopped before dispatch.
     *
     * @param array<string, mixed> $command
     */
    private function appendPostDispatchTerminal(
        array $command,
        string $bindingId,
        string $dispatchId,
        string $status,
        string $errorCode,
        ?string $resultDigest,
        string $startedAt,
    ): string {
        $executionRefId = Ids::new();
        $this->db->insert('runtime_host_execution_refs', [
            'execution_ref_id' => $executionRefId,
            'schema_version' => '1',
            'binding_id' => $bindingId,
            'tool_request_id' => $command['tool_request_id'],
            'tool_authorization_decision_id' => $command['tool_authorization_decision_id'],
            'tool_dispatch_id' => $dispatchId,
            'execution_authorization_id' => null,
            'artifact_id' => null,
            'status' => $status,
            'result_digest' => $resultDigest,
            'output_classification' => 'FAILURE_ONLY',
            'error_code' => $errorCode,
            'side_effect_uncertainty' => 0,
            'tenant_id' => $command['tenant_id'],
            'workspace_id' => $command['workspace_id'] ?? null,
            'started_at' => $startedAt,
            'completed_at' => $this->clock->now(),
            'correlation_id' => $command['correlation_id'] ?? null,
        ]);

        return $executionRefId;
    }

    /**
     * The minimal truthful terminal row when the intended one could not be written.
     *
     * It depends on NO value produced by the adapter, so it can still be written when the intended
     * row cannot — and `side_effect_uncertainty = 1` says plainly that whether anything happened is
     * not known, rather than guessing either way.
     *
     * @param array<string, mixed> $command
     */
    private function appendUnknown(array $command, string $bindingId, string $dispatchId, string $startedAt): string
    {
        $executionRefId = Ids::new();
        $this->db->insert('runtime_host_execution_refs', [
            'execution_ref_id' => $executionRefId,
            'schema_version' => '1',
            'binding_id' => $bindingId,
            'tool_request_id' => $command['tool_request_id'],
            'tool_authorization_decision_id' => $command['tool_authorization_decision_id'],
            'tool_dispatch_id' => $dispatchId,
            'status' => 'UNKNOWN',
            'error_code' => 'DISPATCH_FINALIZATION_FAILED',
            'side_effect_uncertainty' => 1,
            'tenant_id' => $command['tenant_id'],
            'workspace_id' => $command['workspace_id'] ?? null,
            'started_at' => $startedAt,
            'completed_at' => $this->clock->now(),
            'correlation_id' => $command['correlation_id'] ?? null,
        ]);

        return $executionRefId;
    }

    private function appendAdmissionEvent(string $eventType, string $reasonCode): string
    {
        $eventId = Ids::new();
        $this->db->insert('governed_admission_events', [
            'governed_admission_event_id' => $eventId,
            'schema_version' => '1',
            'event_type' => $eventType,
            'reason_code' => $reasonCode,
            'materiality' => 'SECURITY',
            'observed_at' => $this->clock->now(),
        ]);

        return $eventId;
    }
}
