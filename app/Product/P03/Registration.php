<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;

/**
 * P03 registration — the single governed act that lands this capability on the substrate.
 *
 * Lands the lead-qualification capability. Note the data scopes upstream grants it do NOT include
 * public resources: P03 never touches the live-fetch seam, and the tool class says so.
 *
 * It COMPOSES existing primitives and introduces no authority plane of its own: M03 for the agent
 * identity and its contract, M06 for the governed tool and its operation, M08 for the admission
 * policy set, and the entitlement plane for the commercial tier. Every one of those already
 * existed; what was missing was the one call that establishes them together, which is why a
 * deployment could not be provisioned without hand-written SQL.
 *
 * THE CONTRACT CANNOT GRANT AN EXCLUDED CAPABILITY. M03 refuses a contract carrying an
 * external-effect action class before any row exists, so the ceiling is enforced at issuance rather
 * than described in a comment. The forbidden clauses below are DESCRIPTIVE prohibitions recorded on
 * the contract; the literal excluded permission keys are deliberately not used as clause categories,
 * because naming one would be requesting it and M02 treats a request for an excluded permission as
 * the event itself.
 *
 * IDEMPOTENT WHERE CANONICAL IS. The tier is a PLATFORM row and is created once per deployment: two
 * tiers with one key would make "what does this product include?" a question with two answers.
 */
final class Registration
{
    /**
     * Forbidden-action clauses recorded on the contract, mirroring canonical P03 registration.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CLAUSES = [
        'EXTERNAL_MESSAGING_OR_CRM_MUTATION',
        'PUBLIC_OR_CUSTOMER_PUBLISH',
        'TRADE_PROPOSAL_OR_EXECUTION',
        'COMPANY_TWIN_OR_ECONOMY_KERNEL_MUTATION',
        'SENSITIVE_INFERENCE_OR_SURVEILLANCE',
        'COVERT_EMPLOYEE_OR_STAKEHOLDER_SCORING',
        'GENERIC_EXTERNAL_ACTION_OR_AUTONOMOUS_POLICY_CHANGE',
    ];

    /** The permissions this capability is sold with. */
    private const TIER_PERMISSIONS = [
        'prospect.read',
        'priority_outreach_queue.read',
        'prospect_battlecard.read',
        'priority_outreach_queue.review',
        'customer_catalogue.read',
        'customer_catalogue.upload',
    ];

    /** The commercial tiers, in canonical order. `[tier_key, display_name, monthly_fee_minor_units]`. */
    private const TIERS = [
        ['p03_intelligence', 'P03 Intelligence', 29900],
        ['p03_command', 'P03 Command', 99900],
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
    public function registerLeadQualification(array $input): Outcome
    {
        return $this->db->transaction(function (Database $tx) use ($input): Outcome {
            // --- M03: the agent, its contract, its tool right -----------------------------------
            $valkyrieId = $this->m03->registerValkyrie(
                $input['tenant_id'],
                $input['workspace_id'],
                'Lead Qualifier',
                'LEAD_QUALIFIER_VALKYRIE',
                'Pairs released signals with the tenant catalogue to derive product-fit hypotheses.',
                $input['operator_id'],
                'ACTIVE',
            );
            $contract = $this->m03->issueContract(
                $valkyrieId,
                $input['tenant_id'],
                $input['workspace_id'],
                'p03.lead_qualification',
                'LEAD_QUALIFIER_VALKYRIE',
                ['OBSERVE', 'ANALYZE'],
                self::FORBIDDEN_CLAUSES,
            );
            if ($contract->isRefusal()) {
                return Outcome::refuse('CONTRACT_ISSUANCE_REFUSED', [
                    'detail' => $contract->reasonCode(),
                ], 'CONTRACT');
            }
            $contractId = $contract->value()['capability_contract_id'];

            // --- M06: the governed tool -----------------------------------------------------------
            // A governed tool is a PLATFORM row, like a tier: it describes what the tool IS, not
            // who may use it. A second tenant registering this module converges on the existing
            // definition rather than minting a rival one — two tools with one key would make "what
            // does this tool do?" a question with two answers, and the tool RIGHT below is where
            // per-tenant authority actually lives.
            $existingTool = $this->db->one(
                'SELECT gt.governed_tool_id, gto.governed_tool_operation_id
                 FROM governed_tools gt
                 LEFT JOIN governed_tool_operations gto ON gto.governed_tool_id = gt.governed_tool_id
                 WHERE gt.tool_key = ?',
                ['p03.catalogue.transform']
            );
            if ($existingTool !== null) {
                $toolId = (string) $existingTool['governed_tool_id'];
                $operationId = (string) $existingTool['governed_tool_operation_id'];
            } else {
            $toolId = $this->tools->registerTool([
                'tool_key' => 'p03.catalogue.transform',
                'display_name' => 'P03 Customer Catalogue Internal Transform',
                'purpose_summary' => 'Normalize a tenant catalogue and pair its products with released signals.',
                'tool_class' => 'INTERNAL_TRANSFORM',
                'risk_level' => 'T1',
                // The TOOL's ceiling, which is lower than the contract's: this tool transforms tenant records, and
                // nothing more. The contract's higher ceiling covers the agent's other acts.
                'maximum_action_class' => 'ANALYZE',
                'runtime_mapping_ref_id' => $input['runtime_binding_id'] ?? null,
                'evidence_capture_required_by_default' => false,
            ]);
            $operationId = $this->tools->registerOperation([
                'governed_tool_id' => $toolId,
                'operation_key' => 'normalize',
                'operation_summary' => 'Normalize catalogue products for fit matching.',
                'operation_class' => 'TRANSFORM',
                'classified_action_class' => 'ANALYZE',
                'requires_evidence_capture' => false,
            ]);
            }
            // The tool RIGHT is per-contract and therefore per-tenant, always minted fresh.
            $toolRightId = $this->eligibility->grantToolRight(
                $contractId, $toolId, ['normalize'], 'INTERNAL_TRANSFORM', 'ANALYZE'
            );

            // --- M08: the admission policy set ------------------------------------------------------
            $policySet = $this->policySets->seed(
                PolicySets::P03_PROSPECT_ADMISSION, $input['tenant_id'], $input['workspace_id']
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
