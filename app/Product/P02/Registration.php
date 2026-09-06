<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;

/**
 * P02 registration — the single governed act that lands the entity-twin capability.
 *
 * It COMPOSES existing primitives and introduces no authority plane of its own: M03 for the curator
 * identity and its contract, M06 for the governed read tool, M08 for the twin-state admission policy
 * set, and the entitlement plane for the commercial tier.
 *
 * THE TOOL IS READ-ONLY AND HITS NO NETWORK. Twin curation reads the M10-recorded released chain and
 * tenant-provided records. There is no live-fetch seam in this capability, and the tool class says
 * so rather than leaving it to be inferred from the absence of a call.
 *
 * THE CONTRACT REACHES RELEASE_INTELLIGENCE, which is higher than P03's, because a twin state change
 * IS an internal release — and it stops there. M03 refuses a contract carrying an external-effect
 * class before any row exists, so the ceiling is enforced at issuance rather than described.
 *
 * THE TIER GRANTS ONE PERMISSION, and that is deliberate. Upstream's P02 tiers grant the full read
 * set — offering twins, account twins, supplier risk, market context, stakeholder role profiles —
 * for surfaces this port does not have. Selling a read for a surface that does not exist would put a
 * permission in a customer's tier that nothing in the running system ever checks. The permission
 * VOCABULARY still carries them (M02 mirrors upstream); what this tier sells is what this port can
 * actually show.
 */
final class Registration
{
    /**
     * Forbidden-action clauses recorded on the contract, mirroring canonical P02 registration.
     *
     * `DIRECT_MUTATION_OF_RELEASED_SNAPSHOT` is the P02-specific one: released snapshots are
     * append-only in storage, and the contract forbids the act as well as the schema refusing it.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CLAUSES = [
        'COMPANY_TWIN_OR_ECONOMY_KERNEL_MUTATION',
        'EMPLOYEE_OR_PERSON_SCORING_OR_PROFILING',
        'SENSITIVE_INFERENCE_OR_SURVEILLANCE',
        'EXTERNAL_MESSAGE_DELIVERY',
        'LIVE_CRM_WRITEBACK',
        'PUBLIC_OR_CUSTOMER_PUBLISH',
        'TRADE_PROPOSAL_OR_EXECUTION',
        'DIRECT_MUTATION_OF_RELEASED_SNAPSHOT',
        'GENERIC_EXTERNAL_ACTION_OR_AUTONOMOUS_POLICY_CHANGE',
    ];

    /**
     * The permission this capability is sold with — the one a ported surface actually requires.
     *
     * @var list<string>
     */
    private const TIER_PERMISSIONS = ['entity_twin.read'];

    /** The commercial tiers, in canonical order. `[tier_key, display_name, monthly_fee_minor_units]`. */
    private const TIERS = [
        ['P02_INTELLIGENCE', 'Intelligence', 149900],
        ['P02_GOVERNED_OPS', 'Governed Ops', 349900],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M03Registry $m03,
        private readonly Eligibility $eligibility,
        private readonly ToolRegistry $tools,
        private readonly PolicySets $policySets,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, operator_id: string,
     *     runtime_binding_id?: ?string
     * } $input
     */
    public function registerEntityTwinCurator(array $input): Outcome
    {
        return $this->db->transaction(function (Database $tx) use ($input): Outcome {
            // --- M03: the curator, its contract -------------------------------------------------
            $valkyrieId = $this->m03->registerValkyrie(
                $input['tenant_id'],
                $input['workspace_id'],
                'Entity Twin Curator',
                'ENTITY_TWIN_CURATOR_VALKYRIE',
                'Maintains a bounded, evidence-backed, uncertainty-aware twin of the tenant\'s commercial'
                    . ' environment — internal snapshots only, never an external send, never person scoring.',
                $input['operator_id'],
                'ACTIVE',
            );
            $contract = $this->m03->issueContract(
                $valkyrieId,
                $input['tenant_id'],
                $input['workspace_id'],
                'p02.entity_twin_curation',
                'ENTITY_TWIN_CURATOR_VALKYRIE',
                // RELEASE_INTELLIGENCE is reached because a twin state change IS an internal
                // release. The two classes above it stay out of the contract entirely.
                ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'],
                self::FORBIDDEN_CLAUSES,
            );
            if ($contract->isRefusal()) {
                return Outcome::refuse('CONTRACT_ISSUANCE_REFUSED', [
                    'detail' => $contract->reasonCode(),
                ], 'CONTRACT');
            }
            $contractId = $contract->value()['capability_contract_id'];

            // --- M06: the governed read tool ------------------------------------------------------
            // A PLATFORM row: a second tenant registering this module converges on the existing
            // definition rather than minting a rival one. Per-tenant authority lives in the RIGHT.
            $existingTool = $this->db->one(
                'SELECT gt.governed_tool_id, gto.governed_tool_operation_id
                 FROM governed_tools gt
                 LEFT JOIN governed_tool_operations gto ON gto.governed_tool_id = gt.governed_tool_id
                 WHERE gt.tool_key = ?',
                ['p02.entity_twin.read']
            );
            if ($existingTool !== null) {
                $toolId = (string) $existingTool['governed_tool_id'];
                $operationId = (string) $existingTool['governed_tool_operation_id'];
            } else {
                $toolId = $this->tools->registerTool([
                    'tool_key' => 'p02.entity_twin.read',
                    'display_name' => 'P02 Entity-Twin Read',
                    'purpose_summary' => 'Read the M10-recorded released chain and tenant-provided records'
                        . ' to curate evidence-backed twin state. No network.',
                    'tool_class' => 'READ_ONLY_SOURCE',
                    'risk_level' => 'T1',
                    // The TOOL's ceiling is OBSERVE — reading records is all it does. The contract's
                    // higher ceiling covers the curator's other acts, not this tool's.
                    'maximum_action_class' => 'OBSERVE',
                    'runtime_mapping_ref_id' => $input['runtime_binding_id'] ?? null,
                    'evidence_capture_required_by_default' => true,
                ]);
                $operationId = $this->tools->registerOperation([
                    'governed_tool_id' => $toolId,
                    'operation_key' => 'read',
                    'operation_summary' => 'Read a released intelligence record or a tenant-provided record.',
                    'operation_class' => 'READ',
                    'classified_action_class' => 'OBSERVE',
                    'requires_evidence_capture' => true,
                ]);
            }
            $toolRightId = $this->eligibility->grantToolRight(
                $contractId, $toolId, ['read'], 'READ_ONLY_SOURCE', 'OBSERVE'
            );

            // --- M08: the twin-state admission policy set --------------------------------------------
            $policySet = $this->policySets->seed(
                PolicySets::P02_TWIN_STATE_ADMISSION, $input['tenant_id'], $input['workspace_id']
            );
            if ($policySet->isRefusal()) {
                return Outcome::refuse('POLICY_SET_SEED_REFUSED', [
                    'detail' => $policySet->reasonCode(),
                ], 'POLICY');
            }

            // --- the commercial tiers ------------------------------------------------------------------
            $tierIds = [];
            foreach (self::TIERS as [$tierKey, $displayName, $fee]) {
                $tier = $this->ensureTier($tierKey, $displayName, $fee);
                if ($tier->isRefusal()) {
                    return $tier;
                }
                $tierIds[$tierKey] = $tier->value()['subscription_tier_id'];
            }

            return Outcome::ok([
                'valkyrie_id' => $valkyrieId,
                'capability_contract_id' => $contractId,
                'tool_right_id' => $toolRightId,
                'governed_tool_id' => $toolId,
                'governed_tool_operation_id' => $operationId,
                'arbiter_policy_set_id' => $policySet->value()['arbiter_policy_set_id'],
                'subscription_tier_ids' => $tierIds,
            ]);
        });
    }

    /**
     * One tier, created once per deployment.
     *
     * The permissions must exist in the platform vocabulary first: `defineTier` refuses any that do
     * not, so the commercial plane cannot mint an authority the access plane never heard of.
     */
    private function ensureTier(string $tierKey, string $displayName, int $monthlyFeeMinorUnits): Outcome
    {
        $existing = $this->db->scalar(
            'SELECT subscription_tier_id FROM subscription_tiers WHERE tier_key = ?',
            [$tierKey]
        );
        if ($existing !== null) {
            return Outcome::ok(['subscription_tier_id' => (string) $existing]);
        }

        $now = $this->clock->now();
        foreach (self::TIER_PERMISSIONS as $permissionKey) {
            $defined = $this->db->one(
                'SELECT permission_key FROM permissions WHERE permission_key = ?',
                [$permissionKey]
            );
            if ($defined === null) {
                $this->db->insert('permissions', [
                    'permission_key' => $permissionKey,
                    'schema_version' => '1',
                    'display_name' => $permissionKey,
                    'created_at' => $now,
                ]);
            }
        }

        return $this->entitlements->defineTier(
            $tierKey, $displayName, $monthlyFeeMinorUnits, self::TIER_PERMISSIONS
        );
    }
}
