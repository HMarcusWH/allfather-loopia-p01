<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M03;

use Allfather\Loopia\Core\ActionClass;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * M03 server-side authority check — the analog of M02's access evaluation, but for governed-agent
 * (Valkyrie) authority under a capability contract.
 *
 * Called by M04 (mission assignment), M06 (per-tool dispatch) and M07/M08 (producer and
 * participant validation). DEFAULT-DENY: the absence of an ACTIVE valkyrie, an ACTIVE contract, or
 * a matching grant is "not permitted" — never "not checked".
 *
 * Every call appends a row to `valkyrie_authority_evaluations`, including the denials. An
 * evaluation that refuses and leaves no trace makes "we never asked" and "we asked and were
 * refused" indistinguishable, which is the distinction an audit exists to make.
 */
final class Eligibility
{
    /** @var list<string> */
    public const PURPOSES = [
        'MISSION_ASSIGNMENT',
        'TOOL_RIGHT',
        'HMWH_PARTICIPANT',
        'PRODUCER_VALIDATION',
        'AUTHORITY_AT_TIME',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string,
     *     workspace_id: string,
     *     mission_id?: ?string,
     *     valkyrie_id: string,
     *     governed_tool_id: string,
     *     requested_operation?: ?string,
     *     requested_action_class: string,
     *     requested_output_class?: ?string,
     *     evaluation_instant?: ?string,
     *     correlation_id?: ?string,
     *     purpose?: string
     * } $request
     * @return array{
     *     authority_evaluation_id: string,
     *     valkyrie_id: string,
     *     capability_contract_id: ?string,
     *     contract_version: ?int,
     *     tool_right_id: ?string,
     *     decision: string,
     *     maximum_action_class: ?string,
     *     approval_required: bool,
     *     frozen_artifact_required: bool,
     *     evidence_capture_required: bool,
     *     reason_codes: list<string>,
     *     evaluated_at: string
     * }
     */
    public function evaluateToolRight(array $request): array
    {
        $at = $request['evaluation_instant'] ?? $this->clock->now();
        $purpose = $request['purpose'] ?? 'TOOL_RIGHT';

        $finalize = function (
            string $decision,
            ?string $contractId,
            ?int $contractVersion,
            ?array $toolRight,
            array $reasonCodes,
        ) use ($request, $purpose, $at): array {
            $evaluationId = Ids::new();
            $this->db->insert('valkyrie_authority_evaluations', [
                'authority_evaluation_id' => $evaluationId,
                'schema_version' => '1',
                'valkyrie_id' => $request['valkyrie_id'],
                'capability_contract_id' => $contractId,
                'contract_version' => $contractVersion,
                'evaluation_purpose' => $purpose,
                'request_snapshot_json' => json_encode($request, JSON_THROW_ON_ERROR),
                'decision' => $decision,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'correlation_id' => $request['correlation_id'] ?? null,
                'evaluated_at' => $at,
            ]);

            return [
                'authority_evaluation_id' => $evaluationId,
                'valkyrie_id' => $request['valkyrie_id'],
                'capability_contract_id' => $contractId,
                'contract_version' => $contractVersion,
                'tool_right_id' => $toolRight === null ? null : (string) $toolRight['tool_right_id'],
                'decision' => $decision,
                'maximum_action_class' => $toolRight === null ? null : (string) $toolRight['maximum_action_class'],
                'approval_required' => $toolRight !== null && (int) $toolRight['approval_required'] === 1,
                'frozen_artifact_required' => $toolRight !== null && (int) $toolRight['frozen_artifact_required'] === 1,
                'evidence_capture_required' => $toolRight !== null && (int) $toolRight['evidence_capture_required'] === 1,
                'reason_codes' => $reasonCodes,
                'evaluated_at' => $at,
            ];
        };

        $valkyrie = $this->db->one(
            'SELECT valkyrie_id, tenant_id, status FROM valkyries WHERE valkyrie_id = ?',
            [$request['valkyrie_id']]
        );
        if ($valkyrie === null) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', null, null, null, ['VALKYRIE_NOT_REGISTERED']);
        }
        if ((string) $valkyrie['tenant_id'] !== $request['tenant_id']) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', null, null, null, ['TENANT_SCOPE_MISMATCH']);
        }
        $status = (string) $valkyrie['status'];
        if ($status === 'SUSPENDED') {
            return $finalize('CONTRACT_INACTIVE', null, null, null, ['VALKYRIE_SUSPENDED']);
        }
        if ($status === 'REVOKED' || $status === 'RETIRED') {
            return $finalize('CONTRACT_INACTIVE', null, null, null, ['VALKYRIE_REVOKED']);
        }
        if ($status !== 'ACTIVE') {
            return $finalize('CONTRACT_INACTIVE', null, null, null, ['VALKYRIE_NOT_ACTIVE']);
        }

        $contract = $this->db->one(
            "SELECT capability_contract_id, contract_version, allowed_action_classes, status,
                    valid_from, valid_until, workspace_id
             FROM capability_contracts WHERE valkyrie_id = ? AND status = 'ACTIVE'",
            [$request['valkyrie_id']]
        );
        if ($contract === null) {
            return $finalize('CONTRACT_INACTIVE', null, null, null, ['CONTRACT_NOT_FOUND']);
        }
        $contractId = (string) $contract['capability_contract_id'];
        $contractVersion = (int) $contract['contract_version'];

        // Canonical ISO-8601 UTC with fixed millisecond precision, so lexical comparison IS
        // chronological comparison. The storage type (CHAR(24)) is what makes that safe.
        if ((string) $contract['valid_from'] > $at) {
            return $finalize('CONTRACT_INACTIVE', $contractId, $contractVersion, null, ['CONTRACT_OUTSIDE_VALIDITY']);
        }
        if ($contract['valid_until'] !== null && (string) $contract['valid_until'] <= $at) {
            return $finalize('CONTRACT_INACTIVE', $contractId, $contractVersion, null, ['CONTRACT_OUTSIDE_VALIDITY']);
        }
        if ($contract['workspace_id'] !== null && (string) $contract['workspace_id'] !== $request['workspace_id']) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, null, ['WORKSPACE_SCOPE_MISMATCH']);
        }

        $allowed = json_decode((string) $contract['allowed_action_classes'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($request['requested_action_class'], $allowed, true)) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, null, [
                'ACTION_CLASS_EXCEEDS_CONTRACT_MAXIMUM',
            ]);
        }

        $toolRight = $this->db->one(
            "SELECT tool_right_id, governed_tool_id, allowed_operations, tool_class,
                    maximum_action_class, approval_required, frozen_artifact_required,
                    evidence_capture_required, status
             FROM tool_rights
             WHERE capability_contract_id = ? AND governed_tool_id = ? AND status = 'ACTIVE'",
            [$contractId, $request['governed_tool_id']]
        );
        if ($toolRight === null) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, null, [
                'CONTRACT_TOOL_RIGHT_VIOLATION',
            ]);
        }

        // The OPERATION must be in the grant, not merely the tool. Checking only the tool would
        // make every contract grant far looser than it reads: a right to one read operation would
        // become a right to every operation the tool exposes.
        $requestedOperation = $request['requested_operation'] ?? null;
        if ($requestedOperation !== null) {
            $allowedOps = json_decode((string) $toolRight['allowed_operations'], true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($requestedOperation, $allowedOps, true)) {
                return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, $toolRight, [
                    'CONTRACT_TOOL_RIGHT_VIOLATION',
                ]);
            }
        }

        // Canonical ordering only: an unknown or FORBIDDEN ceiling is not permissive.
        if (!ActionClass::withinCeiling($request['requested_action_class'], (string) $toolRight['maximum_action_class'])) {
            return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, $toolRight, [
                'ACTION_CLASS_EXCEEDS_TOOL_RIGHT_MAXIMUM',
            ]);
        }

        $outputClass = $request['requested_output_class'] ?? null;
        if ($outputClass !== null && $outputClass !== '') {
            $binding = $this->db->one(
                "SELECT output_class_binding_id FROM output_class_bindings
                 WHERE capability_contract_id = ? AND output_class = ? AND status = 'ACTIVE'",
                [$contractId, $outputClass]
            );
            if ($binding === null) {
                return $finalize('NOT_PERMITTED_BY_CONTRACT', $contractId, $contractVersion, $toolRight, [
                    'CONTRACT_OUTPUT_CLASS_VIOLATION',
                ]);
            }
        }

        // A grant that needs approval or a frozen artifact is not a refusal — it is a grant whose
        // remaining conditions belong to gates downstream of M03. Reporting it as PERMITTED would
        // hide those conditions from the gate that has to enforce them.
        $decision = ((int) $toolRight['approval_required'] === 1 || (int) $toolRight['frozen_artifact_required'] === 1)
            ? 'REQUIRES_DOWNSTREAM_GATES'
            : 'PERMITTED_BY_CONTRACT';

        return $finalize($decision, $contractId, $contractVersion, $toolRight, ['GRANTED']);
    }

    /**
     * Grant a governed tool right on a capability contract.
     *
     * `tool_class` is CHECK-restricted to the three non-forbidden classes, and the ceiling is
     * validated against the canonical ordering here so an unknown class cannot be stored as a
     * ceiling that later comparisons would treat as unrankable.
     */
    public function grantToolRight(
        string $capabilityContractId,
        string $governedToolId,
        array $allowedOperations,
        string $toolClass,
        string $maximumActionClass,
        bool $evidenceCaptureRequired = true,
    ): string {
        if (!ActionClass::isCanonical($maximumActionClass)) {
            throw new \InvalidArgumentException("tool right ceiling is not a canonical action class: {$maximumActionClass}");
        }
        $toolRightId = Ids::new();
        $this->db->insert('tool_rights', [
            'tool_right_id' => $toolRightId,
            'schema_version' => '1',
            'capability_contract_id' => $capabilityContractId,
            'governed_tool_id' => $governedToolId,
            'allowed_operations' => json_encode(array_values($allowedOperations), JSON_THROW_ON_ERROR),
            'tool_class' => $toolClass,
            'maximum_action_class' => $maximumActionClass,
            // Both stay 0 in this projection: an approval-requiring right has no M09 plane to
            // satisfy it, so granting one would create an authority nothing can exercise.
            'approval_required' => 0,
            'frozen_artifact_required' => 0,
            'evidence_capture_required' => $evidenceCaptureRequired ? 1 : 0,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        return $toolRightId;
    }
}
