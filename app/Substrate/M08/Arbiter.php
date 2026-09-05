<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M05\Readiness;

/**
 * M08 — the Arbiter, the decision plane.
 *
 * Ported from `packages/allfather-control-substrate/src/m08/arbiter.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * The evaluation ORDER is the law, and it is preserved exactly:
 *
 *   0. **Preconditions.** The proposal must exist and be SUBMITTED (immutable). The policy set must
 *      exist, be ACTIVE, be in scope, be the EFFECTIVE binding for the proposal's scope, and admit
 *      the proposal's type.
 *   A. **Non-overridable seeds**, always first, in fixed order: V1 scope prohibition, M05 evidence
 *      readiness, M07 council recommendation. No policy rule — and no ABSENCE of one — can outrank
 *      them.
 *   B. **Ordinary policy rules**, priority descending. The FIRST fail or hold decides. This is
 *      fail-closed: a permissive rule can never reach a later allow, because evaluation stops at
 *      the first refusal.
 *   C. **Terminal verdict** by proposal type, reached only when every check passed.
 *
 * TF7-01 — **precedence is not optional**. Scope compatibility proves only that the NAMED policy is
 * ALLOWED to see this proposal; it does not prove it is the one that GOVERNS it. Without the
 * effective-binding check, a caller could name the global or tenant-wide set while an
 * exact-workspace binding exists, and pick its own authority — the exact ambiguity the scoped
 * identity law exists to remove.
 *
 * A verdict is NOT an approval and NOT an execution authority
 * (`not_human_approval_or_execution_authority` is storage-pinned to 1), and its `basis_hash` binds
 * it to the exact inputs it was decided on.
 */
final class Arbiter
{
    // Refusal vocabulary — conformance surface, preserved verbatim.
    public const ARBITER_PROPOSAL_NOT_FOUND = 'ARBITER_PROPOSAL_NOT_FOUND';
    public const ARBITER_PROPOSAL_NOT_SUBMITTED = 'ARBITER_PROPOSAL_NOT_SUBMITTED';
    public const ARBITER_POLICY_NOT_FOUND = 'ARBITER_POLICY_NOT_FOUND';
    public const ARBITER_POLICY_NOT_ACTIVE = 'ARBITER_POLICY_NOT_ACTIVE';
    public const ARBITER_POLICY_OUT_OF_SCOPE = 'ARBITER_POLICY_OUT_OF_SCOPE';
    public const ARBITER_POLICY_SCOPE_AMBIGUOUS = 'ARBITER_POLICY_SCOPE_AMBIGUOUS';
    public const ARBITER_POLICY_NO_EFFECTIVE_BINDING = 'ARBITER_POLICY_NO_EFFECTIVE_BINDING';
    public const ARBITER_POLICY_NOT_EFFECTIVE_FOR_SCOPE = 'ARBITER_POLICY_NOT_EFFECTIVE_FOR_SCOPE';
    public const ARBITER_POLICY_INCOMPATIBLE_ROUTE = 'ARBITER_POLICY_INCOMPATIBLE_ROUTE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Readiness $readiness,
    ) {
    }

    /**
     * Resolve the EFFECTIVE policy set for a scope: the most specific ACTIVE binding of a policy
     * family, exact-workspace beating tenant-wide beating global.
     *
     * Two ACTIVE bindings at the SAME specificity are AMBIGUOUS, not "pick one" — a coin flip
     * between two authorities is not an authority.
     *
     * @return array{outcome: 'FOUND', arbiter_policy_set_id: string}|array{outcome: 'NOT_FOUND'}|array{outcome: 'AMBIGUOUS'}
     */
    public function resolveEffectivePolicySet(string $tenantId, string $workspaceId, string $policyKey): array
    {
        foreach ([
            ['tenant_id = ? AND workspace_id = ?', [$tenantId, $workspaceId]],
            ['tenant_id = ? AND workspace_id IS NULL', [$tenantId]],
            ['tenant_id IS NULL AND workspace_id IS NULL', []],
        ] as [$predicate, $params]) {
            $rows = $this->db->all(
                "SELECT arbiter_policy_set_id FROM arbiter_policy_sets
                 WHERE policy_key = ? AND status = 'ACTIVE' AND {$predicate}",
                [$policyKey, ...$params]
            );
            if (count($rows) > 1) {
                return ['outcome' => 'AMBIGUOUS'];
            }
            if (count($rows) === 1) {
                return ['outcome' => 'FOUND', 'arbiter_policy_set_id' => (string) $rows[0]['arbiter_policy_set_id']];
            }
        }

        return ['outcome' => 'NOT_FOUND'];
    }

    /**
     * Evaluate a proposal and issue a verdict.
     *
     * @return Outcome ok(array{arbiter_verdict_id, verdict, permitted_next_stage, reason_codes, ...})
     */
    public function evaluateProposal(
        string $proposalId,
        string $arbiterPolicySetId,
        ?string $correlationId = null,
    ): Outcome {
        // --- 0. preconditions ---------------------------------------------------------------

        $proposal = $this->db->one(
            'SELECT proposal_id, status, tenant_id, workspace_id, mission_id, submission_digest,
                    proposal_type, requested_action_class, requested_review_path, evidence_bundle_id
             FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return Outcome::refuse(self::ARBITER_PROPOSAL_NOT_FOUND, [], 'POLICY');
        }
        if ((string) $proposal['status'] !== 'SUBMITTED' || empty($proposal['submission_digest'])) {
            // A verdict must be about a FROZEN artifact. Evaluating a mutable proposal would let
            // the thing decided about change after the decision.
            return Outcome::refuse(self::ARBITER_PROPOSAL_NOT_SUBMITTED, [
                'status' => (string) $proposal['status'],
            ], 'POLICY');
        }

        $policy = $this->db->one(
            'SELECT arbiter_policy_set_id, tenant_id, workspace_id, status, policy_key, policy_version,
                    applicable_proposal_types, default_fail_closed_verdict
             FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?',
            [$arbiterPolicySetId]
        );
        if ($policy === null) {
            return Outcome::refuse(self::ARBITER_POLICY_NOT_FOUND, [], 'POLICY');
        }
        if ((string) $policy['status'] !== 'ACTIVE') {
            // A deactivated policy set is the stand-down lever: with it inactive, nothing under it
            // can be admitted at all.
            return Outcome::refuse(self::ARBITER_POLICY_NOT_ACTIVE, [
                'status' => (string) $policy['status'],
            ], 'POLICY');
        }

        // A SCOPED policy set may only evaluate a proposal in that same scope. A NULL-scoped set is
        // global (the non-overridable seeds), so it is left unrestricted. Without this, one
        // tenant's proposal could be evaluated under another tenant's ACTIVE policy set.
        if (($policy['tenant_id'] !== null && (string) $policy['tenant_id'] !== (string) $proposal['tenant_id'])
            || ($policy['workspace_id'] !== null && (string) $policy['workspace_id'] !== (string) $proposal['workspace_id'])) {
            return Outcome::refuse(self::ARBITER_POLICY_OUT_OF_SCOPE, [], 'POLICY');
        }

        // TF7-01 — the named policy must ALSO be the effective binding for this scope.
        $effective = $this->resolveEffectivePolicySet(
            (string) $proposal['tenant_id'],
            (string) $proposal['workspace_id'],
            (string) $policy['policy_key'],
        );
        if ($effective['outcome'] === 'AMBIGUOUS') {
            return Outcome::refuse(self::ARBITER_POLICY_SCOPE_AMBIGUOUS, [], 'POLICY');
        }
        if ($effective['outcome'] === 'NOT_FOUND') {
            return Outcome::refuse(self::ARBITER_POLICY_NO_EFFECTIVE_BINDING, [], 'POLICY');
        }
        if ($effective['arbiter_policy_set_id'] !== $arbiterPolicySetId) {
            return Outcome::refuse(self::ARBITER_POLICY_NOT_EFFECTIVE_FOR_SCOPE, [
                'effective_arbiter_policy_set_id' => $effective['arbiter_policy_set_id'],
            ], 'POLICY');
        }

        $applicableTypes = json_decode((string) $policy['applicable_proposal_types'], true, 512, JSON_THROW_ON_ERROR);
        if (is_array($applicableTypes) && $applicableTypes !== []
            && !in_array((string) $proposal['proposal_type'], $applicableTypes, true)) {
            return Outcome::refuse(self::ARBITER_POLICY_INCOMPATIBLE_ROUTE, [
                'proposal_type' => (string) $proposal['proposal_type'],
            ], 'POLICY');
        }

        // --- bind the exact inputs ----------------------------------------------------------

        $readiness = $this->readiness->forProposal($proposalId);
        $hmwhRequired = $this->isHmwhRequired($proposal);
        $hmwhResult = $this->db->one(
            "SELECT hmwh_result_id, result_recommendation FROM hmwh_results
             WHERE proposal_id = ? AND validity_status = 'VALID_FOR_ARBITER_INPUT'
             ORDER BY created_at DESC, hmwh_result_id DESC LIMIT 1",
            [$proposalId]
        );

        $runId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('arbiter_evaluation_runs', [
            'arbiter_evaluation_run_id' => $runId,
            'schema_version' => '1',
            'tenant_id' => (string) $proposal['tenant_id'],
            'workspace_id' => (string) $proposal['workspace_id'],
            'mission_id' => (string) $proposal['mission_id'],
            'proposal_id' => $proposalId,
            'proposal_submission_digest' => (string) $proposal['submission_digest'],
            'requested_proposal_type' => (string) $proposal['proposal_type'],
            'requested_action_class' => (string) $proposal['requested_action_class'],
            'evidence_bundle_id' => $readiness['evidence_bundle_id'] ?? $proposal['evidence_bundle_id'],
            'evidence_bundle_hash' => $readiness['bundle_hash'],
            'hmwh_result_id' => $hmwhResult['hmwh_result_id'] ?? null,
            'arbiter_policy_set_id' => $arbiterPolicySetId,
            'policy_version' => (string) $policy['policy_version'],
            'correlation_id' => $correlationId,
            'status' => 'EVALUATING',
            'requested_at' => $now,
        ]);

        $passed = [];
        $failedOrHolding = [];

        $recordCheck = function (
            string $category,
            string $checkType,
            string $decision,
            array $reasonCodes,
            ?string $severity,
            bool $incident,
        ) use ($runId, &$passed, &$failedOrHolding): void {
            $checkId = Ids::new();
            $this->db->insert('arbiter_check_records', [
                'arbiter_check_id' => $checkId,
                'schema_version' => '1',
                'arbiter_evaluation_run_id' => $runId,
                'rule_category' => $category,
                'check_type' => $checkType,
                'decision' => $decision,
                'reason_codes' => json_encode(array_values($reasonCodes), JSON_THROW_ON_ERROR),
                'safe_assessment_summary' => null,
                'severity_if_failed' => $severity,
                'incident_evaluation_required' => $incident ? 1 : 0,
                'evaluated_at' => $this->clock->now(),
            ]);
            if ($decision === 'PASS' || $decision === 'NOT_APPLICABLE') {
                $passed[] = $checkId;
            } else {
                $failedOrHolding[] = $checkId;
            }
        };

        // --- A. non-overridable seeds, fixed order --------------------------------------------

        $scopeResult = Verdicts::scopeProhibition((string) $proposal['requested_action_class']);
        $recordCheck(
            'V1_SCOPE', 'PROHIBITION_CHECK',
            $scopeResult === null ? 'PASS' : 'FAIL',
            $scopeResult['reason_codes'] ?? [],
            $scopeResult['severity'] ?? null,
            $scopeResult['incident_evaluation_required'] ?? false,
        );
        if ($scopeResult !== null) {
            return $this->issueVerdict($runId, $proposal, $arbiterPolicySetId, $policy, $readiness, $hmwhResult, $passed, $failedOrHolding, $scopeResult);
        }

        $readinessResult = Verdicts::readinessToVerdict((string) $readiness['requirement_status']);
        $recordCheck(
            'M05_EVIDENCE', 'ENUM_STATUS',
            $readinessResult === null ? 'PASS' : 'FAIL',
            $readinessResult['reason_codes'] ?? [],
            $readinessResult['severity'] ?? null,
            $readinessResult['incident_evaluation_required'] ?? false,
        );
        if ($readinessResult !== null) {
            return $this->issueVerdict($runId, $proposal, $arbiterPolicySetId, $policy, $readiness, $hmwhResult, $passed, $failedOrHolding, $readinessResult);
        }

        $hmwhCheckResult = null;
        if (!$hmwhRequired) {
            $recordCheck('M07_HMWH', 'REQUIRED_REFERENCE', 'NOT_APPLICABLE', [], null, false);
        } elseif ($hmwhResult === null) {
            $hmwhCheckResult = Verdicts::hmwhMissing();
            $recordCheck('M07_HMWH', 'REQUIRED_REFERENCE', 'HOLD', $hmwhCheckResult['reason_codes'], 'S2', false);
        } else {
            $hmwhCheckResult = Verdicts::hmwhRecommendationToVerdict((string) $hmwhResult['result_recommendation']);
            $recordCheck(
                'M07_HMWH',
                $hmwhCheckResult === null ? 'REQUIRED_REFERENCE' : 'ENUM_STATUS',
                $hmwhCheckResult === null ? 'PASS' : 'FAIL',
                $hmwhCheckResult['reason_codes'] ?? [],
                $hmwhCheckResult['severity'] ?? null,
                $hmwhCheckResult['incident_evaluation_required'] ?? false,
            );
        }
        if ($hmwhCheckResult !== null) {
            return $this->issueVerdict($runId, $proposal, $arbiterPolicySetId, $policy, $readiness, $hmwhResult, $passed, $failedOrHolding, $hmwhCheckResult);
        }

        // --- B. ordinary policy rules, priority descending, first refusal decides -------------

        $failClosed = Verdicts::coerceVerdict((string) $policy['default_fail_closed_verdict'], 'HOLD');
        $context = $this->loadRuleContext($proposalId, (string) $proposal['proposal_type']);

        foreach ($this->ordinaryRules($arbiterPolicySetId) as $rule) {
            $check = RuleEngine::evaluate($rule, $context, (string) $proposal['proposal_type'], $failClosed);
            $recordCheck(
                (string) $rule['rule_category'],
                (string) $rule['check_type'],
                $check['decision'],
                $check['reason_codes'],
                $check['severity'],
                $check['incident'],
            );
            if ($check['result'] !== null) {
                return $this->issueVerdict($runId, $proposal, $arbiterPolicySetId, $policy, $readiness, $hmwhResult, $passed, $failedOrHolding, $check['result']);
            }
        }

        // --- C. terminal admissible verdict ----------------------------------------------------

        return $this->issueVerdict(
            $runId, $proposal, $arbiterPolicySetId, $policy, $readiness, $hmwhResult,
            $passed, $failedOrHolding,
            Verdicts::terminalVerdictForType((string) $proposal['proposal_type']),
        );
    }

    /**
     * Ordinary (non-seed) rules for a policy set, priority DESCENDING.
     *
     * The secondary sort on `rule_key` makes the order total: two rules at the same priority would
     * otherwise be evaluated in whatever order the database returned, and since the FIRST refusal
     * decides, a nondeterministic order would make the reason code nondeterministic too.
     *
     * @return list<array<string, mixed>>
     */
    private function ordinaryRules(string $policySetId): array
    {
        $seeds = Verdicts::SEED_CATEGORIES;
        $placeholders = implode(', ', array_fill(0, count($seeds), '?'));

        return $this->db->all(
            "SELECT * FROM arbiter_policy_rules
             WHERE arbiter_policy_set_id = ? AND status = 'ACTIVE' AND rule_category NOT IN ({$placeholders})
             ORDER BY priority DESC, rule_key ASC",
            [$policySetId, ...$seeds]
        );
    }

    /** Whether the review path requires a council result. */
    private function isHmwhRequired(array $proposal): bool
    {
        $path = $proposal['requested_review_path'] ?? null;
        if ($path !== null) {
            return (string) $path === 'HMWH_THEN_ARBITER';
        }

        $mission = $this->db->one('SELECT review_path_id FROM missions WHERE mission_id = ?', [$proposal['mission_id']]);
        // Absent an explicit path, a council review is REQUIRED. Defaulting to "not required" would
        // let a proposal skip review by omitting a field.
        return $mission === null || $mission['review_path_id'] === null
            ? true
            : (string) $mission['review_path_id'] === 'HMWH_THEN_ARBITER';
    }

    /** @return array<string, mixed> */
    private function loadRuleContext(string $proposalId, string $proposalType): array
    {
        return RuleEngine::loadContext($this->db, $proposalId, $proposalType);
    }

    /**
     * Record the verdict.
     *
     * `basis_hash` binds the verdict to its exact evaluation inputs, so a verdict can be proven to
     * be about this proposal version, this evidence bundle, this council result and this policy
     * version — and cannot be re-attributed to different inputs later.
     */
    private function issueVerdict(
        string $runId,
        array $proposal,
        string $policySetId,
        array $policy,
        array $readiness,
        ?array $hmwhResult,
        array $passed,
        array $failedOrHolding,
        array $check,
    ): Outcome {
        $basisHash = Digest::canonicalDigest([
            'proposalId' => (string) $proposal['proposal_id'],
            'submissionDigest' => (string) $proposal['submission_digest'],
            'bundleHash' => $readiness['bundle_hash'],
            'hmwhResultId' => $hmwhResult['hmwh_result_id'] ?? null,
            'policyVersion' => (string) $policy['policy_version'],
        ]);

        $verdictId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $verdictId, $runId, $proposal, $policySetId, $policy, $readiness, $hmwhResult,
            $passed, $failedOrHolding, $check, $basisHash, $now
        ): Outcome {
            $tx->insert('arbiter_verdicts', [
                'arbiter_verdict_id' => $verdictId,
                'schema_version' => '1',
                'arbiter_evaluation_run_id' => $runId,
                'tenant_id' => (string) $proposal['tenant_id'],
                'workspace_id' => (string) $proposal['workspace_id'],
                'mission_id' => (string) $proposal['mission_id'],
                'proposal_id' => (string) $proposal['proposal_id'],
                'proposal_submission_digest' => (string) $proposal['submission_digest'],
                'evidence_bundle_id' => $readiness['evidence_bundle_id'],
                'evidence_bundle_hash' => $readiness['bundle_hash'],
                'hmwh_result_id' => $hmwhResult['hmwh_result_id'] ?? null,
                'arbiter_policy_set_id' => $policySetId,
                'policy_version' => (string) $policy['policy_version'],
                'verdict' => $check['verdict'],
                'permitted_next_stage' => $check['permitted_next_stage'],
                'admissibility_scope' => null,
                'passed_check_ids' => json_encode(array_values($passed), JSON_THROW_ON_ERROR),
                'failed_or_holding_check_ids' => json_encode(array_values($failedOrHolding), JSON_THROW_ON_ERROR),
                'condition_ids' => json_encode([], JSON_THROW_ON_ERROR),
                'reason_codes' => json_encode(array_values($check['reason_codes']), JSON_THROW_ON_ERROR),
                'safe_rationale_summary' => implode('; ', $check['reason_codes']),
                'uncertainty_and_limitation_summary' => null,
                'severity' => $check['severity'],
                'incident_evaluation_required' => $check['incident_evaluation_required'] ? 1 : 0,
                // CHECK-pinned to 1. A verdict is a DECISION, never an approval and never an
                // execution authority — those are M09's, behind a separate seat.
                'not_human_approval_or_execution_authority' => 1,
                'basis_hash' => $basisHash,
                'hash_algorithm' => 'sha256',
                'issued_at' => $now,
            ]);

            // The run's terminal state is carried by `status` alone — the table has no
            // `completed_at`, and the verdict's own `issued_at` is the authoritative timestamp.
            $tx->run(
                'UPDATE arbiter_evaluation_runs SET status = ? WHERE arbiter_evaluation_run_id = ?',
                [self::runStatusFor($check['verdict']), $runId]
            );

            return Outcome::ok([
                'arbiter_verdict_id' => $verdictId,
                'arbiter_evaluation_run_id' => $runId,
                'verdict' => $check['verdict'],
                'permitted_next_stage' => $check['permitted_next_stage'],
                'reason_codes' => array_values($check['reason_codes']),
                'severity' => $check['severity'],
                'incident_evaluation_required' => $check['incident_evaluation_required'],
                'basis_hash' => $basisHash,
                'passed_check_ids' => $passed,
                'failed_or_holding_check_ids' => $failedOrHolding,
            ]);
        });
    }

    /**
     * The run status implied by a verdict.
     *
     * The status vocabulary is narrower than the verdict vocabulary, so this is a deliberate
     * mapping rather than a pass-through: HELD and BLOCKED are distinguished because an operator
     * reading run history needs to see which refusals were terminal.
     */
    private static function runStatusFor(string $verdict): string
    {
        return match ($verdict) {
            'HOLD', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION' => 'HELD',
            'BLOCK' => 'BLOCKED',
            default => 'VERDICT_ISSUED',
        };
    }

    /** The latest verdict for a proposal. */
    public function latestVerdict(string $proposalId): ?array
    {
        return $this->db->one(
            'SELECT * FROM arbiter_verdicts WHERE proposal_id = ? ORDER BY issued_at DESC, arbiter_verdict_id DESC LIMIT 1',
            [$proposalId]
        );
    }

    /** True only for the one verdict that permits an informational release. */
    public function permitsRelease(string $proposalId): bool
    {
        $verdict = $this->latestVerdict($proposalId);

        return $verdict !== null && (string) $verdict['verdict'] === 'ALLOW_INFORMATIONAL_RELEASE';
    }
}
