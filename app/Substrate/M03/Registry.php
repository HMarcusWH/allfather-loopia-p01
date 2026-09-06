<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M03 — capability identity: Valkyries, contracts, tool rights, revocation.
 *
 * Ported from `packages/allfather-control-substrate/src/m03/`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d, bounded to what the selected product graph reaches.
 *
 * A Valkyrie is a bounded capability identity, not an agent with general authority: what it may do
 * is its ACTIVE capability contract plus the tool rights on that contract, and both are revocable.
 * Revocation is the stand-down lever the rollback runbook pulls — a REVOKED Valkyrie is refused
 * everywhere, and cannot be re-activated (revocation is terminal, not a pause).
 */
final class Registry
{
    public const VALKYRIE_STATUS = ['DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'RETIRED'];

    /** The closed role-class vocabulary. One class per product module, CHECK-pinned in storage. */
    public const ROLE_CLASS = [
        'RESEARCH_SOURCE_VALKYRIE',
        'REVIEW_CRITIC_VALKYRIE',
        'DEMO_ACTION_PREPARER_VALKYRIE',
        'REPLAY_TEST_VALKYRIE',
        'COMPETITION_MONITOR_VALKYRIE',      // P01
        'LEAD_QUALIFIER_VALKYRIE',           // P03
        'INTELLIGENCE_DISTRIBUTOR_VALKYRIE', // P04
        'CRM_EXECUTION_VALKYRIE',            // P07-A
        'ENTITY_TWIN_CURATOR_VALKYRIE',      // P02
        'MARKETING_CUSTOMIZER_VALKYRIE',     // P05
    ];

    /** Forward-only lifecycle. REVOKED and RETIRED are TERMINAL — no path back to ACTIVE. */
    public const ALLOWED_TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'REVOKED', 'RETIRED'],
        'ACTIVE' => ['SUSPENDED', 'REVOKED', 'RETIRED'],
        'SUSPENDED' => ['ACTIVE', 'REVOKED', 'RETIRED'],
        'REVOKED' => [],
        'RETIRED' => [],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function registerValkyrie(
        string $tenantId,
        ?string $workspaceId,
        string $displayName,
        string $canonicalRoleClass,
        string $purposeSummary,
        string $createdByOperatorId,
        string $status = 'DRAFT',
    ): string {
        $valkyrieId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('valkyries', [
            'valkyrie_id' => $valkyrieId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'display_name' => $displayName,
            'canonical_role_class' => $canonicalRoleClass,
            'purpose_summary' => $purposeSummary,
            'status' => $status,
            'created_by_operator_id' => $createdByOperatorId,
            'created_at' => $now,
            'activated_at' => $status === 'ACTIVE' ? $now : null,
        ]);

        return $valkyrieId;
    }

    /**
     * Change a Valkyrie's status.
     *
     * Revocation being TERMINAL is the point: a stand-down that could be silently undone by
     * re-activating the same identity would not be a stand-down.
     */
    public function changeStatus(string $valkyrieId, string $toStatus, string $reasonCode): Outcome
    {
        $valkyrie = $this->db->one('SELECT status FROM valkyries WHERE valkyrie_id = ?', [$valkyrieId]);
        if ($valkyrie === null) {
            return Outcome::refuse('VALKYRIE_NOT_FOUND', [], 'AUTHORIZATION');
        }
        $from = (string) $valkyrie['status'];
        if (!in_array($toStatus, self::ALLOWED_TRANSITIONS[$from] ?? [], true)) {
            return Outcome::refuse('VALKYRIE_ILLEGAL_TRANSITION', [
                'from_status' => $from,
                'to_status' => $toStatus,
            ], 'AUTHORIZATION');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($valkyrieId, $toStatus, $from, $reasonCode, $now): Outcome {
            $tx->run(
                'UPDATE valkyries SET status = ?, activated_at = COALESCE(activated_at, ?) WHERE valkyrie_id = ?',
                [$toStatus, $toStatus === 'ACTIVE' ? $now : null, $valkyrieId]
            );

            if ($toStatus === 'REVOKED' || $toStatus === 'SUSPENDED') {
                $tx->insert('valkyrie_revocations', [
                    'revocation_id' => Ids::new(),
                    'schema_version' => '1',
                    'valkyrie_id' => $valkyrieId,
                    'action' => $toStatus === 'REVOKED' ? 'REVOKE' : 'SUSPEND',
                    'reason_code' => $reasonCode,
                    'reason_summary' => $reasonCode,
                    'effective_at' => $now,
                    'affected_mission_handling' => 'MISSIONS_BLOCKED_PENDING_REVIEW',
                    'recorded_at' => $now,
                ]);
            }

            return Outcome::ok(['valkyrie_id' => $valkyrieId, 'from_status' => $from, 'to_status' => $toStatus]);
        });
    }

    public function isActive(string $valkyrieId): bool
    {
        $row = $this->db->one('SELECT status FROM valkyries WHERE valkyrie_id = ?', [$valkyrieId]);

        return $row !== null && (string) $row['status'] === 'ACTIVE';
    }

    /**
     * Issue a capability contract and bind it as the Valkyrie's active contract.
     *
     * @param list<string> $permittedActionClasses
     * @param list<string> $forbiddenActionClauses
     */
    /**
     * Bind an OUTPUT CLASS to a capability contract.
     *
     * The contract says which ACTION classes an agent may perform; this says which KINDS OF OUTPUT
     * it may produce. They are different questions, and P07 is where the difference bites: an agent
     * with ANALYZE authority may analyse a great many things, and producing a CRM outreach proposal
     * is a specific one of them that has to be granted on its own.
     *
     * `requested_action_class_maximum` is the CEILING this output class may be produced under. A
     * binding that named a class above the projection's own ceiling would be an authority the
     * contract itself could not have granted, so it is refused here as it is there.
     */
    public function bindOutputClass(
        string $capabilityContractId,
        string $outputClass,
        string $requestedActionClassMaximum,
        string $status = 'ACTIVE',
    ): Outcome {
        if (in_array($requestedActionClassMaximum, ['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'], true)) {
            return Outcome::refuse('OUTPUT_CLASS_ACTION_CLASS_ABOVE_CEILING', [
                'requested_action_class_maximum' => $requestedActionClassMaximum,
            ], 'CONTRACT', 'S5', 'BLOCK_RELEASE');
        }

        $contract = $this->db->one(
            'SELECT status FROM capability_contracts WHERE capability_contract_id = ?',
            [$capabilityContractId]
        );
        if ($contract === null) {
            return Outcome::refuse('CONTRACT_NOT_FOUND', [], 'CONTRACT');
        }

        $bindingId = Ids::new();
        $this->db->insert('output_class_bindings', [
            'output_class_binding_id' => $bindingId,
            'schema_version' => '1',
            'capability_contract_id' => $capabilityContractId,
            'output_class' => $outputClass,
            'requested_action_class_maximum' => $requestedActionClassMaximum,
            'required_downstream_gates' => json_encode(['M07_HMWH', 'M08_ARBITER'], JSON_THROW_ON_ERROR),
            'permitted_visibility_scope' => 'TENANT_INTERNAL',
            'status' => $status,
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['output_class_binding_id' => $bindingId]);
    }

    public function issueContract(
        string $valkyrieId,
        string $tenantId,
        ?string $workspaceId,
        string $contractKey,
        string $roleClass,
        array $permittedActionClasses,
        array $forbiddenActionClauses = [],
        string $status = 'ACTIVE',
    ): Outcome {
        // A contract may never permit an action class above the projection's ceiling. This is the
        // second half of the no-external-action boundary: M02 forbids the permission, and M03
        // refuses to issue a contract that would grant the class.
        foreach ($permittedActionClasses as $class) {
            if (in_array($class, ['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'], true)) {
                return Outcome::refuse('CONTRACT_ACTION_CLASS_ABOVE_CEILING', [
                    'requested_action_class' => $class,
                ], 'CONTRACT', 'S5', 'BLOCK_RELEASE');
            }
        }

        $contractId = Ids::new();
        $now = $this->clock->now();
        // The NEXT version for this Valkyrie. Contracts are append-only — a change of authority
        // issues a new contract rather than editing one — so a Valkyrie legitimately accumulates
        // versions, and a hardcoded 1 would make the second issuance impossible.
        $version = 1 + (int) $this->db->scalar(
            'SELECT COUNT(*) FROM capability_contracts WHERE valkyrie_id = ?',
            [$valkyrieId]
        );

        return $this->db->transaction(function (Database $tx) use (
            $contractId, $valkyrieId, $tenantId, $workspaceId, $contractKey, $roleClass,
            $permittedActionClasses, $forbiddenActionClauses, $status, $now, $version
        ): Outcome {
            $tx->insert('capability_contracts', [
                'capability_contract_id' => $contractId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'valkyrie_id' => $valkyrieId,
                'contract_version' => $version,
                'role_class' => $roleClass,
                'purpose_summary' => $contractKey,
                'allowed_action_classes' => json_encode(array_values($permittedActionClasses), JSON_THROW_ON_ERROR),
                'maximum_autonomy_statement' => 'Bounded to the allowed action classes; no external effect.',
                'evidence_obligation_summary' => 'Every released claim cites admitted M05 evidence.',
                'escalation_obligation_summary' => 'Unknown authority is refusal; escalate rather than proceed.',
                // CHECK-pinned to 0. A capability contract in this projection can NEVER carry
                // human-approval or execution authority — the approval seat is a separate M02 role
                // and the execution ceiling is A/B/C.
                'human_approval_or_execution_authority' => 0,
                'status' => $status,
                'valid_from' => $now,
                'created_at' => $now,
            ]);

            foreach ($forbiddenActionClauses as $clause) {
                $tx->insert('forbidden_action_clauses', [
                    'forbidden_action_clause_id' => Ids::new(),
                    'schema_version' => '1',
                    'capability_contract_id' => $contractId,
                    'forbidden_category' => $clause,
                    'description' => $clause,
                    'severity_if_attempted' => 'S5',
                    'incident_evaluation_required' => 1,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                ]);
            }

            if ($status === 'ACTIVE') {
                $tx->run(
                    'UPDATE valkyries SET active_capability_contract_id = ? WHERE valkyrie_id = ?',
                    [$contractId, $valkyrieId]
                );
            }

            return Outcome::ok(['capability_contract_id' => $contractId]);
        });
    }
}
