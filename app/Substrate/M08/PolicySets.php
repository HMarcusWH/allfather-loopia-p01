<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M08 policy-set definitions and seeding.
 *
 * Ported from the `seed*PolicySet` functions in
 * `packages/allfather-control-substrate/src/m08/arbiter.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Two things about these definitions are easy to misread:
 *
 *  1. **The three seed rules are declared for the RECORD, not for the engine.** `v1_scope_prohibition`,
 *     `m05_evidence_readiness` and `m07_hmwh_required` appear in each set so an auditor reading the
 *     policy can see them — but the engine enforces them as non-overridable seeds regardless of
 *     whether they are present, and evaluates them BEFORE any ordinary rule. Deleting them from a
 *     policy set does not disable them.
 *  2. **`default_fail_closed_verdict` is the answer when a rule fails without a usable effect.** It
 *     is never ALLOW.
 *
 * Deactivating a policy set is a stand-down lever: with it inactive, nothing routed through it can
 * be admitted at all.
 */
final class PolicySets
{
    /**
     * The three non-overridable guarantees, declared in every set for the record.
     *
     * @return list<array<string, mixed>>
     */
    private const SEED_RULES = [
        [
            'rule_key' => 'v1_scope_prohibition',
            'rule_category' => 'V1_SCOPE',
            'rule_summary' => 'Prohibited V1 action classes (external/consequential) are never admissible.',
            'check_type' => 'PROHIBITION_CHECK',
            'failure_effect' => 'BLOCK',
            'priority' => 100,
        ],
        [
            'rule_key' => 'm05_evidence_readiness',
            'rule_category' => 'M05_EVIDENCE',
            'rule_summary' => 'Evidence readiness must be SATISFIED_FOR_EVALUATION for a release route.',
            'check_type' => 'ENUM_STATUS',
            'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
            'priority' => 80,
        ],
        [
            'rule_key' => 'm07_hmwh_required',
            'rule_category' => 'M07_HMWH',
            'rule_summary' => 'A valid HMWH result must exist when the review path requires it.',
            'check_type' => 'REQUIRED_REFERENCE',
            'failure_effect' => 'HOLD',
            'priority' => 60,
        ],
    ];

    /** The P01 competition-relevance policy set. */
    public const P01_COMPETITION_RELEVANCE = [
        'policy_key' => 'P01_COMPETITION_RELEVANCE',
        'policy_version' => 'v1',
        'display_name' => 'P01 competition relevance policy',
        'purpose_summary' => 'P01 competition-monitoring relevance/admissibility for COMPANY_SIGNAL_BRIEF / BATTLECARD_BRIEF.',
        'applicable_proposal_types' => ['COMPANY_SIGNAL_BRIEF', 'BATTLECARD_BRIEF'],
        'applicable_requested_action_classes' => ['ANALYZE', 'RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p01_relevance_customer_interest',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The signal must concern a competitor within the customer\'s competitive interest.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p01_relevance_source_authority',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The backing source card must permit customer-visible intelligence output.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p01_relevance_insufficient_evidence',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'A binding release needs a classified structural change (not UNKNOWN).',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 30,
            ],
            [
                'rule_key' => 'p01_relevance_stale_input',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The source-card review must be current (not expired).',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 20,
            ],
        ],
    ];

    /**
     * The P03 prospect-admission policy set.
     *
     * It governs BOTH lead-qualification paths, and the difference between them is the whole point:
     *
     *   FIT_HYPOTHESIS               the Watch List. "There is a reason to look at this."
     *   PRIORITY_OUTREACH_QUEUE_ADMISSION  the Call Now list. "There is a timely reason to act."
     *
     * The buyer-intent rule fires on the SECOND only. A Watch-List entry never asserts buyer
     * intent, so a rule that fired on it would be refusing a claim nobody made. A queue admission
     * DOES assert timeliness, and must cite a buying TRIGGER signal that was directly OBSERVED —
     * absent that, it BLOCKS as an overclaim and opens an incident.
     *
     * That rule is non-overridable by design: it is the highest-priority ordinary rule in the set,
     * so no more permissive rule below it can reach an allow once it has fired.
     */
    public const P03_PROSPECT_ADMISSION = [
        'policy_key' => 'P03_PROSPECT_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P03 prospect admission policy',
        'purpose_summary' => 'Lead-qualification admissibility for FIT_HYPOTHESIS and PRIORITY_OUTREACH_QUEUE_ADMISSION.',
        'applicable_proposal_types' => ['FIT_HYPOTHESIS', 'PRIORITY_OUTREACH_QUEUE_ADMISSION'],
        'applicable_requested_action_classes' => ['ANALYZE', 'RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p03_buyer_intent_trigger',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'A queue admission must cite a buying-trigger signal that was directly observed; otherwise it is a buyer-intent overclaim.',
                'check_type' => 'PASS_FAIL',
                // BLOCK, not HOLD. An overclaim about a person\'s intent is not something to revise
                // — the claim itself was never available to make.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                // The HIGHEST priority in the set, so no more permissive rule below can reach an
                // allow once this one has fired.
                'priority' => 90,
            ],
            [
                'rule_key' => 'p03_icp_excluded_segment',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'A prospect matching a tenant-declared excluded segment is not admissible.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'BLOCK',
                'priority' => 80,
            ],
            [
                'rule_key' => 'p03_admission_fit_present',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'An UNFIT pairing is never a reason to investigate.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p03_admission_catalogue_active',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'The catalogue behind the cited product must still be ACTIVE.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p03_admission_signal_released',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'The cited company signal must still be RELEASED.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 30,
            ],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create and ACTIVATE a policy set for a scope.
     *
     * @param array<string, mixed> $definition one of the constants above
     */
    public function seed(array $definition, string $tenantId, string $workspaceId): Outcome
    {
        $existing = $this->db->one(
            'SELECT arbiter_policy_set_id FROM arbiter_policy_sets
             WHERE policy_key = ? AND tenant_id = ? AND workspace_id = ? AND status = ?',
            [$definition['policy_key'], $tenantId, $workspaceId, 'ACTIVE']
        );
        if ($existing !== null) {
            return Outcome::ok([
                'arbiter_policy_set_id' => (string) $existing['arbiter_policy_set_id'],
                'created' => false,
            ]);
        }

        $policySetId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($policySetId, $definition, $tenantId, $workspaceId, $now): Outcome {
            $tx->insert('arbiter_policy_sets', [
                'arbiter_policy_set_id' => $policySetId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'policy_key' => $definition['policy_key'],
                'policy_version' => $definition['policy_version'],
                'display_name' => $definition['display_name'],
                'purpose_summary' => $definition['purpose_summary'],
                'applicable_proposal_types' => json_encode($definition['applicable_proposal_types'], JSON_THROW_ON_ERROR),
                'applicable_requested_action_classes' => json_encode($definition['applicable_requested_action_classes'], JSON_THROW_ON_ERROR),
                'available_verdicts' => json_encode($definition['available_verdicts'], JSON_THROW_ON_ERROR),
                'default_fail_closed_verdict' => $definition['default_fail_closed_verdict'],
                // Both CHECK-pinned to 1. A material outcome ALWAYS requires a decision-ledger
                // record, and a policy set NEVER approves or executes — those are M09's, behind a
                // separate seat. Neither can be configured away by a policy author.
                'requires_decision_ledger_for_material_outcome' => 1,
                'does_not_approve_or_execute' => 1,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'activated_at' => $now,
            ]);

            foreach ([...self::SEED_RULES, ...$definition['rules']] as $rule) {
                $tx->insert('arbiter_policy_rules', [
                    'arbiter_policy_rule_id' => Ids::new(),
                    'schema_version' => '1',
                    'arbiter_policy_set_id' => $policySetId,
                    'policy_version' => $definition['policy_version'],
                    'rule_key' => $rule['rule_key'],
                    'rule_category' => $rule['rule_category'],
                    'rule_summary' => $rule['rule_summary'],
                    'check_type' => $rule['check_type'],
                    'failure_effect' => $rule['failure_effect'],
                    'incident_evaluation_if_failed' => (int) ($rule['incident_evaluation_if_failed'] ?? 0),
                    'priority' => $rule['priority'],
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                ]);
            }

            return Outcome::ok(['arbiter_policy_set_id' => $policySetId, 'created' => true]);
        });
    }

    /**
     * Deactivate a policy set — the stand-down lever.
     *
     * Nothing routed through a deactivated set can be admitted, and the set stays queryable as
     * history rather than being deleted.
     */
    public function deactivate(string $policySetId, string $reasonCode): Outcome
    {
        $affected = $this->db->run(
            'UPDATE arbiter_policy_sets SET status = ? WHERE arbiter_policy_set_id = ? AND status = ?',
            ['SUPERSEDED', $policySetId, 'ACTIVE']
        );

        return $affected === 1
            ? Outcome::ok(['arbiter_policy_set_id' => $policySetId, 'status' => 'SUPERSEDED', 'reason_code' => $reasonCode])
            : Outcome::refuse('ARBITER_POLICY_NOT_ACTIVE', ['arbiter_policy_set_id' => $policySetId], 'POLICY');
    }
}
