<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/**
 * P01 registration — landing the competition-monitoring capability on the certified substrate.
 *
 * It COMPOSES existing registry primitives and creates NO new authority plane:
 *
 *   M03  a monitoring Valkyrie, its capability contract, and its tool right
 *   M06  the governed read tool and its READ operation
 *   M08  the competition-relevance policy set
 *   M16  the tenant's output visibility profiles
 *   P01  the subscription tiers this capability is sold under
 *
 * THE VISIBILITY PROFILES BELONG HERE, not in the release path. A release that provisioned its own
 * visibility profiles would be granting itself the authority it then checks — the check would pass
 * because the release created the row that permits it, which is no check at all. Provisioning is a
 * tenant-setup act with its own moment, and the release path only ever READS what setup decided.
 *
 * The profiles seeded are the same ones M16 defines: internal audiences permitted, every wider
 * audience seeded EXPLICITLY as not permitted. Registration cannot widen them — the storage CHECK
 * makes a permitting wider profile unwritable — so this call is provisioning, never a grant.
 *
 * The contract cannot grant an excluded capability: M03 refuses to issue one carrying an
 * external-effect action class, before any row exists.
 */
final class Registration
{
    /**
     * Forbidden-action clauses recorded on the contract.
     *
     * Each MIRRORS an excluded capability as a DESCRIPTIVE prohibition. The literal excluded
     * permission keys are deliberately not used as clause categories — naming one would be
     * requesting it, and M02 treats a request for an excluded permission as the event itself.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CLAUSES = [
        'EXTERNAL_MESSAGING_OR_CRM_MUTATION',
        'PUBLIC_OR_CUSTOMER_PUBLISH',
        'TRADE_PROPOSAL_OR_EXECUTION',
        'COMPANY_TWIN_OR_ECONOMY_KERNEL_MUTATION',
        'SENSITIVE_INFERENCE_OR_SURVEILLANCE',
        'GENERIC_EXTERNAL_ACTION_OR_AUTONOMOUS_POLICY_CHANGE',
    ];

    /** The read permissions the P01 capability is sold with. */
    private const TIER_PERMISSIONS = [
        'competition_monitoring.read', 'company_signal.review', 'briefing.read', 'battlecard.read',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M03Registry $m03,
        private readonly Eligibility $eligibility,
        private readonly ToolRegistry $tools,
        private readonly PolicySets $policySets,
        private readonly ReleaseOutput $m16,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, operator_id: string,
     *     runtime_binding_id?: ?string, runtime_host_tool_ref?: ?string
     * } $input
     */
    public function registerCompetitionMonitor(array $input): Outcome
    {
        return $this->db->transaction(function (Database $tx) use ($input): Outcome {
            // --- M03: the agent, its contract, its tool right -----------------------------------
            $valkyrieId = $this->m03->registerValkyrie(
                $input['tenant_id'],
                $input['workspace_id'],
                'Competition Monitor',
                'COMPETITION_MONITOR_VALKYRIE',
                'Monitors approved public competitor sources and briefs material changes.',
                $input['operator_id'],
                'ACTIVE',
            );

            // The ceiling stops at RELEASE_INTELLIGENCE. M03 refuses anything above it, so the
            // no-external-effect boundary is enforced at issuance rather than described in a comment.
            $contract = $this->m03->issueContract(
                $valkyrieId,
                $input['tenant_id'],
                $input['workspace_id'],
                'p01.competition_monitoring',
                'COMPETITION_MONITOR_VALKYRIE',
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
            $toolId = $this->tools->registerTool([
                'tool_key' => 'p01.source.read',
                'display_name' => 'Competitor source read',
                'purpose_summary' => 'Read an approved public competitor source page.',
                'tool_class' => 'READ_ONLY_SOURCE',
                'risk_level' => 'T1',
                // The tool's own ceiling is OBSERVE: this tool reads, and nothing more. The
                // contract's higher ceiling covers the agent's other acts, not this tool's.
                'maximum_action_class' => 'OBSERVE',
                'runtime_mapping_ref_id' => $input['runtime_binding_id'] ?? null,
                'evidence_capture_required_by_default' => true,
            ]);
            $operationId = $this->tools->registerOperation([
                'governed_tool_id' => $toolId,
                'operation_key' => 'source.read',
                'operation_summary' => 'Fetch one approved source URL.',
                'operation_class' => 'READ',
                'classified_action_class' => 'OBSERVE',
                'requires_evidence_capture' => true,
            ]);
            $toolRightId = $this->eligibility->grantToolRight(
                $contractId, $toolId, ['source.read'], 'READ_ONLY_SOURCE', 'OBSERVE'
            );

            // --- M08: the relevance policy set -------------------------------------------------------
            $policySet = $this->policySets->seed(
                PolicySets::P01_COMPETITION_RELEVANCE, $input['tenant_id'], $input['workspace_id']
            );

            // --- M16: the tenant's visibility profiles -------------------------------------------------
            // Provisioned HERE and read by the release path. Internal audiences are permitted;
            // every wider one is seeded explicitly as not permitted, and the storage CHECK makes a
            // permitting wider profile unwritable — so this is provisioning, never a grant.
            $this->m16->seedVisibilityProfiles($input['tenant_id']);

            // --- the commercial tier ---------------------------------------------------------------------
            $tier = $this->ensureTier();
            if ($tier->isRefusal()) {
                return $tier;
            }

            return Outcome::ok([
                'valkyrie_id' => $valkyrieId,
                'capability_contract_id' => $contractId,
                'tool_right_id' => $toolRightId,
                'governed_tool_id' => $toolId,
                'governed_tool_operation_id' => $operationId,
                'arbiter_policy_set_id' => $policySet->value()['arbiter_policy_set_id'],
                'subscription_tier_id' => $tier->value()['subscription_tier_id'],
            ]);
        });
    }

    /**
     * The P01 catalogue tier, created once per deployment.
     *
     * A tier is a PLATFORM row, so a second tenant registering reuses it rather than creating a
     * rival definition of the same product — two tiers with one key would make "what does this
     * product include?" a question with two answers.
     */
    private function ensureTier(): Outcome
    {
        $existing = $this->db->scalar(
            "SELECT subscription_tier_id FROM subscription_tiers WHERE tier_key = 'p01_monitor'"
        );
        if ($existing !== null) {
            return Outcome::ok(['subscription_tier_id' => (string) $existing]);
        }

        // The permissions must already exist in the platform vocabulary. `defineTier` refuses any
        // that do not, so the commercial plane cannot mint an authority M02 never heard of.
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
            'p01_monitor', 'P01 Competition Monitor', 249_900, self::TIER_PERMISSIONS
        );
    }
}
