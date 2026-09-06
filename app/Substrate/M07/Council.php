<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M07;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M07 — the HMWH Council, the structured deliberation plane.
 *
 * Ported from `packages/allfather-control-substrate/src/m07/council.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * M07 evaluates, challenges, dissents and flags risk. It **NEVER** releases, approves, dispatches
 * or executes. That separation is pinned in storage
 * (`hmwh_results.not_approval_or_execution_authority = 1`) and in code: there is no path from an
 * HMWH result to a tool dispatch or a verdict. M08 consumes the frozen result as ADVICE.
 *
 * The load-bearing rule encoded here: **a producing Valkyrie cannot be assigned as an INDEPENDENT
 * reviewer of its own proposal.** It may be assigned only as an explicitly non-independent
 * self-critique; an attempt to claim independence is DENIED and the eligibility check records
 * `SELF_REVIEW_ONLY` as the proof. Without that rule, "independent review" becomes a producer
 * agreeing with itself.
 */
final class Council
{
    public const REVIEW_MODE = ['MULTI_REVIEWER_DEMO', 'SINGLE_REVIEWER_FIXTURE'];

    /**
     * Review purposes. Each product module adds its own; no M07 behavior branches on the value —
     * the purpose is recorded so an auditor can tell WHAT was reviewed, not so the council behaves
     * differently.
     */
    public const REVIEW_PURPOSE = [
        'INTERNAL_BRIEFING_REVIEW',
        'CONTROLLED_DEMO_ACTION_REVIEW',
        'REPLAY_TEST_REVIEW',
        'COMPETITION_RELEVANCE_REVIEW',          // P01
        'FIT_HYPOTHESIS_UNCERTAINTY_REVIEW',     // P03
        'DISTRIBUTION_SENSITIVITY_REVIEW',       // P04
        'OUTREACH_COMPLIANCE_REVIEW',            // P07
        'TWIN_STATE_REVIEW',                     // P02
        'RELATIONSHIP_STATE_REVIEW',             // P02
        'MARKETING_ASSET_REVIEW',                // P05
        'SOURCE_CONFIG_REVIEW',                  // M15
    ];

    public const REVIEW_ROLE = [
        'EVIDENCE_CRITIC', 'SCOPE_AND_POLICY_CRITIC', 'RISK_CRITIC',
        'GENERAL_REVIEWER', 'FIXTURE_REVIEWER',
    ];

    public const INDEPENDENCE = [
        'INDEPENDENT_DECLARED_REVIEWER',
        'NON_INDEPENDENT_SELF_CRITIQUE',
        'TEST_FIXTURE_ONLY',
    ];

    public const ASSESSMENT_RECOMMENDATION = [
        'SUPPORT_PROCEED_TO_ARBITER', 'SUPPORT_WITH_CONDITIONS', 'REQUIRE_MORE_EVIDENCE',
        'HOLD', 'BLOCK', 'QUARANTINE_OR_INCIDENT_REVIEW',
    ];

    public const RESULT_RECOMMENDATION = [
        'PROCEED_TO_ARBITER', 'PROCEED_WITH_CONDITIONS', 'REQUIRE_MORE_EVIDENCE',
        'HOLD', 'BLOCK', 'QUARANTINE_OR_INCIDENT_EVALUATION',
    ];

    public const RESULT_VALIDITY = [
        'VALID_FOR_ARBITER_INPUT', 'INCOMPLETE', 'INVALIDATED', 'TEST_FIXTURE_ONLY',
    ];

    public const EVIDENCE_SUFFICIENCY_VIEW = [
        'SUFFICIENT_FOR_REVIEW', 'INCOMPLETE', 'CONFLICTED', 'STALE', 'QUARANTINED', 'UNKNOWN',
    ];

    public const CONFIDENCE_BAND = ['LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED'];

    public const DISSENT_TYPE = [
        'MINORITY_RECOMMENDATION', 'MATERIAL_EVIDENCE_DISAGREEMENT', 'RISK_VETO_DISAGREEMENT',
        'SCOPE_OR_POLICY_DISAGREEMENT', 'CONFIDENCE_DISAGREEMENT', 'OTHER',
    ];

    public const VETO_TYPE = [
        'EVIDENCE_INTEGRITY_CONCERN', 'SCOPE_VIOLATION_CONCERN', 'FORBIDDEN_ACTION_CONCERN',
        'PRIVACY_OR_PROVENANCE_CONCERN', 'ARTIFACT_OR_EXECUTION_RISK_CONCERN', 'POLICY_GAP_CONCERN', 'OTHER',
    ];

    /** A veto's routing recommendation. Note every value routes AWAY from release. */
    public const RECOMMENDED_ROUTING = [
        'ARBITER_HOLD', 'ARBITER_BLOCK', 'ARBITER_QUARANTINE', 'INCIDENT_EVALUATION', 'REQUIRE_MORE_EVIDENCE',
    ];

    // Refusal vocabulary — conformance surface, preserved verbatim.
    public const HMWH_PROPOSAL_NOT_FOUND = 'HMWH_PROPOSAL_NOT_FOUND';
    public const HMWH_PROPOSAL_NOT_SUBMITTED_OR_MUTABLE = 'HMWH_PROPOSAL_NOT_SUBMITTED_OR_MUTABLE';
    public const HMWH_SESSION_NOT_FOUND = 'HMWH_SESSION_NOT_FOUND';
    public const HMWH_SESSION_NOT_READY_FOR_ASSESSMENT = 'HMWH_SESSION_NOT_READY_FOR_ASSESSMENT';
    public const HMWH_SESSION_NOT_READY_FOR_AGGREGATION = 'HMWH_SESSION_NOT_READY_FOR_AGGREGATION';
    public const HMWH_RESULT_ALREADY_FROZEN = 'HMWH_RESULT_ALREADY_FROZEN';
    public const HMWH_NO_SUBMITTED_ASSESSMENTS = 'HMWH_NO_SUBMITTED_ASSESSMENTS';
    public const HMWH_PARTICIPANT_NOT_ELIGIBLE = 'HMWH_PARTICIPANT_NOT_ELIGIBLE';
    public const HMWH_SELF_REVIEW_MISREPRESENTED_AS_INDEPENDENT = 'HMWH_SELF_REVIEW_MISREPRESENTED_AS_INDEPENDENT';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** Open a review session over a submitted proposal. */
    public function openSession(
        string $tenantId,
        string $workspaceId,
        string $missionId,
        string $proposalId,
        string $reviewMode,
        string $reviewPurpose,
        string $initiatedByActorType,
        string $initiatedByActorId,
        ?string $evidenceBundleId = null,
    ): Outcome {
        $proposal = $this->db->one(
            'SELECT status, submission_digest FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return Outcome::refuse(self::HMWH_PROPOSAL_NOT_FOUND, [], 'REVIEW');
        }
        // Review runs over an IMMUTABLE proposal. Reviewing something still mutable would mean the
        // council's advice could describe a proposal that no longer exists.
        if (!in_array((string) $proposal['status'], ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            return Outcome::refuse(self::HMWH_PROPOSAL_NOT_SUBMITTED_OR_MUTABLE, [
                'status' => (string) $proposal['status'],
            ], 'REVIEW');
        }

        $sessionId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('hmwh_review_sessions', [
            'review_session_id' => $sessionId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'mission_id' => $missionId,
            'proposal_id' => $proposalId,
            'evidence_bundle_id' => $evidenceBundleId,
            'review_mode' => $reviewMode,
            'review_purpose' => $reviewPurpose,
            'status' => 'AWAITING_ASSESSMENTS',
            'initiated_by_actor_type' => $initiatedByActorType,
            'initiated_by_actor_id' => $initiatedByActorId,
            // The session BINDS the proposal's submission digest. Review is of an exact frozen
            // proposal, so advice cannot later be attached to a different version of it.
            'proposal_submission_digest' => (string) ($proposal['submission_digest'] ?? ''),
            'initiated_at' => $now,
        ]);

        return Outcome::ok(['review_session_id' => $sessionId]);
    }

    /**
     * Assign a participant, applying the producer-self-review rule.
     *
     * The eligibility check is recorded whatever the outcome, so a denial leaves proof rather than
     * an absence.
     */
    public function assignParticipant(
        string $reviewSessionId,
        string $valkyrieId,
        string $reviewRole,
        string $independenceClassification,
    ): Outcome {
        $session = $this->db->one(
            'SELECT review_session_id, status, proposal_id FROM hmwh_review_sessions WHERE review_session_id = ?',
            [$reviewSessionId]
        );
        if ($session === null) {
            return Outcome::refuse(self::HMWH_SESSION_NOT_FOUND, [], 'REVIEW');
        }

        $proposal = $this->db->one(
            'SELECT producing_valkyrie_id FROM proposal_envelopes WHERE proposal_id = ?',
            [$session['proposal_id']]
        );
        $producingValkyrieId = $proposal === null ? null : ($proposal['producing_valkyrie_id'] ?? null);
        $isSelf = $producingValkyrieId !== null && (string) $producingValkyrieId === $valkyrieId;

        $valkyrie = $this->db->one('SELECT status FROM valkyries WHERE valkyrie_id = ?', [$valkyrieId]);
        $valkyrieActive = $valkyrie !== null && (string) $valkyrie['status'] === 'ACTIVE';

        // Decide the eligibility outcome BEFORE recording the append-only audit row, so the row
        // records the decision rather than being edited to match it.
        $denial = null;
        if (!$valkyrieActive) {
            $m03Result = 'INELIGIBLE';
            $m03Rejection = 'VALKYRIE_NOT_ACTIVE';
            $conflict = 'CLEARED';
            $denial = self::HMWH_PARTICIPANT_NOT_ELIGIBLE;
        } elseif ($isSelf && $independenceClassification === 'INDEPENDENT_DECLARED_REVIEWER') {
            // THE rule: the producer cannot be an independent reviewer of its own proposal.
            $m03Result = 'INELIGIBLE';
            $m03Rejection = 'PRODUCER_SELF_REVIEW';
            $conflict = 'SELF_REVIEW_ONLY';
            $denial = self::HMWH_SELF_REVIEW_MISREPRESENTED_AS_INDEPENDENT;
        } elseif ($isSelf) {
            // Self-critique IS permitted — but only when explicitly labelled non-independent, so
            // nothing downstream can mistake it for independent review.
            $m03Result = 'CONDITIONAL';
            $m03Rejection = null;
            $conflict = 'SELF_REVIEW_ONLY';
        } else {
            $m03Result = 'ELIGIBLE';
            $m03Rejection = null;
            $conflict = 'CLEARED';
        }

        $now = $this->clock->now();
        $eligibilityCheckId = Ids::new();

        return $this->db->transaction(function (Database $tx) use (
            $eligibilityCheckId, $reviewSessionId, $valkyrieId, $reviewRole,
            $independenceClassification, $m03Result, $m03Rejection, $conflict, $denial, $now
        ): Outcome {
            $tx->insert('hmwh_eligibility_checks', [
                'eligibility_check_id' => $eligibilityCheckId,
                'schema_version' => '1',
                'review_session_id' => $reviewSessionId,
                'valkyrie_id' => $valkyrieId,
                'proposed_review_role' => $reviewRole,
                'm03_eligibility_result' => $m03Result,
                'm03_rejection_reason_code' => $m03Rejection,
                'conflict_or_separation_check' => $conflict,
                'assessed_at' => $now,
            ]);

            if ($denial !== null) {
                return Outcome::refuse($denial, [
                    'eligibility_check_id' => $eligibilityCheckId,
                    'conflict_or_separation_check' => $conflict,
                    'm03_rejection_reason_code' => $m03Rejection,
                ], 'REVIEW');
            }

            $participantId = Ids::new();
            $tx->insert('hmwh_participant_assignments', [
                'participant_assignment_id' => $participantId,
                'schema_version' => '1',
                'review_session_id' => $reviewSessionId,
                'valkyrie_id' => $valkyrieId,
                'review_role' => $reviewRole,
                'independence_classification' => $independenceClassification,
                'eligibility_check_id' => $eligibilityCheckId,
                'status' => 'ACTIVE',
                'assigned_at' => $now,
            ]);

            return Outcome::ok([
                'participant_assignment_id' => $participantId,
                'eligibility_check_id' => $eligibilityCheckId,
                'conflict_or_separation_check' => $conflict,
            ]);
        });
    }

    /** Record a participant's assessment. */
    public function recordAssessment(
        string $reviewSessionId,
        string $participantAssignmentId,
        string $valkyrieId,
        string $recommendation,
        string $assessmentSummary,
        array $evidenceRefsCited = [],
        array $identifiedRisks = [],
        string $confidenceBand = 'MEDIUM',
        string $evidenceSufficiencyView = 'SUFFICIENT_FOR_REVIEW',
    ): Outcome {
        $session = $this->db->one(
            'SELECT status FROM hmwh_review_sessions WHERE review_session_id = ?',
            [$reviewSessionId]
        );
        if ($session === null) {
            return Outcome::refuse(self::HMWH_SESSION_NOT_FOUND, [], 'REVIEW');
        }
        if (!in_array((string) $session['status'], ['AWAITING_ASSESSMENTS', 'READY_FOR_AGGREGATION'], true)) {
            return Outcome::refuse(self::HMWH_SESSION_NOT_READY_FOR_ASSESSMENT, [
                'status' => (string) $session['status'],
            ], 'REVIEW');
        }

        $assessmentId = Ids::new();
        $submissionDigest = Digest::canonicalDigest([
            'reviewSessionId' => $reviewSessionId,
            'participantAssignmentId' => $participantAssignmentId,
            'recommendation' => $recommendation,
            'assessmentSummary' => $assessmentSummary,
            'evidenceRefsCited' => array_values($evidenceRefsCited),
            'identifiedRisks' => array_values($identifiedRisks),
        ]);

        $this->db->insert('hmwh_assessments', [
            'assessment_id' => $assessmentId,
            'schema_version' => '1',
            'review_session_id' => $reviewSessionId,
            'participant_assignment_id' => $participantAssignmentId,
            'valkyrie_id' => $valkyrieId,
            'recommendation' => $recommendation,
            'assessment_summary' => $assessmentSummary,
            'evidence_refs_cited' => json_encode(array_values($evidenceRefsCited), JSON_THROW_ON_ERROR),
            'identified_risks' => json_encode(array_values($identifiedRisks), JSON_THROW_ON_ERROR),
            'confidence_band' => $confidenceBand,
            'evidence_sufficiency_view' => $evidenceSufficiencyView,
            'status' => 'SUBMITTED',
            'submission_digest' => $submissionDigest,
            'submitted_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['assessment_id' => $assessmentId, 'submission_digest' => $submissionDigest]);
    }

    /** Record a dissent. Dissent is preserved, never averaged away. */
    public function recordDissent(
        string $reviewSessionId,
        array $dissentingAssessmentIds,
        string $dissentType,
        string $dissentSummary,
        bool $materialToArbiterEvaluation = true,
    ): string {
        $dissentId = Ids::new();
        $this->db->insert('hmwh_dissent_records', [
            'dissent_record_id' => $dissentId,
            'schema_version' => '1',
            'review_session_id' => $reviewSessionId,
            'dissenting_assessment_ids' => json_encode(array_values($dissentingAssessmentIds), JSON_THROW_ON_ERROR),
            'dissent_type' => $dissentType,
            'dissent_summary' => $dissentSummary,
            'material_to_arbiter_evaluation' => $materialToArbiterEvaluation ? 1 : 0,
            // CHECK-pinned: dissent is PRESERVED for routing, never averaged away into a consensus
            // that never existed.
            'preserved_for_routing' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        return $dissentId;
    }

    /** Record a risk veto. */
    public function recordRiskVeto(
        string $reviewSessionId,
        ?string $assessmentId,
        string $vetoType,
        string $riskSummary,
        string $recommendedRouting,
    ): string {
        $vetoId = Ids::new();
        $this->db->insert('hmwh_risk_veto_records', [
            'risk_veto_record_id' => $vetoId,
            'schema_version' => '1',
            'review_session_id' => $reviewSessionId,
            'assessment_id' => $assessmentId,
            'veto_type' => $vetoType,
            'risk_summary' => $riskSummary,
            'recommended_routing' => $recommendedRouting,
            // CHECK-pinned: a risk veto is ALWAYS visible to the Arbiter. It cannot be filed away
            // where the decision plane never sees it.
            'mandatory_visibility_to_arbiter' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        return $vetoId;
    }

    /**
     * Freeze the council's result.
     *
     * The digest commits to the session, the proposal, the recommendation, the exact set of
     * submitted assessments, every dissent, every risk veto, and the validity status. So a result
     * cannot later be re-attributed to a different set of assessments, and a dissent cannot be
     * dropped from the record without changing the result's identity.
     *
     * `not_approval_or_execution_authority` is written as 1 and CHECK-pinned: the frozen result is
     * ADVICE to M08, never an approval and never an execution authority.
     */
    public function freezeResult(
        string $reviewSessionId,
        string $resultRecommendation,
        string $resultSummary,
        ?string $confidenceSummary = null,
        array $knownLimitations = [],
        string $validityStatus = 'VALID_FOR_ARBITER_INPUT',
    ): Outcome {
        $session = $this->db->one(
            'SELECT status, tenant_id, workspace_id, mission_id, proposal_id, evidence_bundle_id, review_mode
             FROM hmwh_review_sessions WHERE review_session_id = ?',
            [$reviewSessionId]
        );
        if ($session === null) {
            return Outcome::refuse(self::HMWH_SESSION_NOT_FOUND, [], 'REVIEW');
        }
        if (!in_array((string) $session['status'], ['READY_FOR_AGGREGATION', 'AWAITING_ASSESSMENTS'], true)) {
            return Outcome::refuse(self::HMWH_SESSION_NOT_READY_FOR_AGGREGATION, [
                'status' => (string) $session['status'],
            ], 'REVIEW');
        }

        $existing = $this->db->one(
            'SELECT hmwh_result_id FROM hmwh_results WHERE review_session_id = ?',
            [$reviewSessionId]
        );
        if ($existing !== null) {
            // A second freeze would produce a competing "frozen" result for one session.
            return Outcome::refuse(self::HMWH_RESULT_ALREADY_FROZEN, [
                'hmwh_result_id' => (string) $existing['hmwh_result_id'],
            ], 'REVIEW');
        }

        // Ordered by id so the digest is stable regardless of the order the database returns rows.
        $assessmentIds = array_map(
            static fn (array $r): string => (string) $r['assessment_id'],
            $this->db->all(
                'SELECT assessment_id FROM hmwh_assessments
                 WHERE review_session_id = ? AND status = ? ORDER BY assessment_id',
                [$reviewSessionId, 'SUBMITTED']
            )
        );
        if ($assessmentIds === []) {
            // A council result with no assessment behind it is an opinion with no deliberation.
            return Outcome::refuse(self::HMWH_NO_SUBMITTED_ASSESSMENTS, [], 'REVIEW');
        }

        $dissentIds = array_map(
            static fn (array $r): string => (string) $r['dissent_record_id'],
            $this->db->all(
                'SELECT dissent_record_id FROM hmwh_dissent_records WHERE review_session_id = ? ORDER BY dissent_record_id',
                [$reviewSessionId]
            )
        );
        $vetoIds = array_map(
            static fn (array $r): string => (string) $r['risk_veto_record_id'],
            $this->db->all(
                'SELECT risk_veto_record_id FROM hmwh_risk_veto_records WHERE review_session_id = ? ORDER BY risk_veto_record_id',
                [$reviewSessionId]
            )
        );

        $resultDigest = Digest::canonicalDigest([
            'reviewSessionId' => $reviewSessionId,
            'proposalId' => (string) $session['proposal_id'],
            'resultRecommendation' => $resultRecommendation,
            'submittedAssessmentIds' => $assessmentIds,
            'dissentRecordIds' => $dissentIds,
            'riskVetoRecordIds' => $vetoIds,
            'validityStatus' => $validityStatus,
        ]);

        $resultId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $resultId, $reviewSessionId, $session, $assessmentIds, $resultRecommendation,
            $resultSummary, $confidenceSummary, $dissentIds, $vetoIds, $knownLimitations,
            $validityStatus, $resultDigest, $now
        ): Outcome {
            $tx->insert('hmwh_results', [
                'hmwh_result_id' => $resultId,
                'schema_version' => '1',
                'review_session_id' => $reviewSessionId,
                'tenant_id' => (string) $session['tenant_id'],
                'workspace_id' => (string) $session['workspace_id'],
                'mission_id' => (string) $session['mission_id'],
                'proposal_id' => (string) $session['proposal_id'],
                'evidence_bundle_id' => $session['evidence_bundle_id'],
                'review_mode' => (string) $session['review_mode'],
                'submitted_assessment_ids' => json_encode($assessmentIds, JSON_THROW_ON_ERROR),
                'result_recommendation' => $resultRecommendation,
                'result_summary' => $resultSummary,
                'confidence_summary' => $confidenceSummary ?? '',
                'dissent_record_ids' => json_encode($dissentIds, JSON_THROW_ON_ERROR),
                'risk_veto_record_ids' => json_encode($vetoIds, JSON_THROW_ON_ERROR),
                'known_limitations' => json_encode(array_values($knownLimitations), JSON_THROW_ON_ERROR),
                'validity_status' => $validityStatus,
                // CHECK-pinned to 1. M07 is advice; it is never approval or execution authority.
                'not_approval_or_execution_authority' => 1,
                'result_digest' => $resultDigest,
                'hash_algorithm' => 'sha256',
                'created_at' => $now,
            ]);

            $tx->run(
                'UPDATE hmwh_review_sessions SET status = ?, closed_at = ? WHERE review_session_id = ?',
                ['AGGREGATED', $now, $reviewSessionId]
            );

            return Outcome::ok([
                'hmwh_result_id' => $resultId,
                'result_digest' => $resultDigest,
                'submitted_assessment_ids' => $assessmentIds,
                'dissent_record_ids' => $dissentIds,
                'risk_veto_record_ids' => $vetoIds,
            ]);
        });
    }

    /** The frozen result for a proposal, if any — what M08 consumes as advice. */
    public function resultForProposal(string $proposalId): ?array
    {
        return $this->db->one(
            'SELECT * FROM hmwh_results WHERE proposal_id = ? ORDER BY created_at DESC, hmwh_result_id DESC LIMIT 1',
            [$proposalId]
        );
    }
}
