<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P04 distribution admission — the governed route from a DRAFT briefing to a verdict.
 *
 * A composed briefing is a proposal about an AUDIENCE, and it takes the same road every other
 * proposal takes: M04 envelope, M05 frozen bundle, M07 sensitivity review, M08 verdict. Nothing
 * here shortcuts a plane, and the briefing advances only on the verdict the arbiter actually gave.
 *
 * WHAT IS BEING ASKED FOR is `RELEASE_INTELLIGENCE` and nothing above it: show an existing,
 * already-released claim to one more internal role. The M04 ceiling refuses the two external
 * classes outright, so this path cannot even request one.
 *
 * THE GOVERNANCE FACTS ARE FROZEN, NOT PASSED. The sensitivity class and legal basis are written
 * into the submitted proposal's release scope, which is digest-bound. The arbiter reads them from
 * there — so a routing cannot claim ROUTINE at the verdict having been composed as SENSITIVE, and
 * cannot be handed a legal basis it never committed to.
 *
 * ONLY `ALLOW_INFORMATIONAL_RELEASE` ADVANCES the briefing. BLOCK and QUARANTINE terminate it;
 * HOLD and the REQUIRE_* verdicts leave it exactly where it was, awaiting the repair they named.
 * There is no verdict in this vocabulary that authorizes a send.
 */
final class DistributionAdmission
{
    /**
     * The verdicts that END a briefing.
     *
     * BLOCK and QUARANTINE are findings about the routing itself, not requests for more work, so
     * the briefing is closed rather than left waiting for a revision nobody was asked to make.
     *
     * @var list<string>
     */
    public const TERMINATING_VERDICTS = ['BLOCK', 'QUARANTINE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceSpine $evidence,
        private readonly Readiness $readiness,
        private readonly Proposals $proposals,
        private readonly Council $council,
        private readonly Arbiter $arbiter,
        private readonly DecisionLedger $ledger,
    ) {
    }

    /**
     * Submit a DRAFT briefing as a governed INTELLIGENCE_DISTRIBUTION_ROUTING proposal.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, role_distribution_briefing_id: string,
     *     sensitivity_class?: string, legal_basis_ref?: ?string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submitRoutingProposal(array $input): Outcome
    {
        $briefing = $this->db->one(
            'SELECT tenant_id, workspace_id, briefing_status, target_org_role,
                    released_briefing_id, released_prospect_output_id, proposal_id
             FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($briefing === null) {
            return Outcome::refuse('BRIEFING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $briefing['tenant_id'] !== $input['tenant_id']
            || (string) $briefing['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BRIEFING_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Both clauses. A briefing already carrying a proposal is not DRAFT whatever its status
        // column says, and either check alone leaves a double-submit path open.
        if ((string) $briefing['briefing_status'] !== 'DRAFT' || $briefing['proposal_id'] !== null) {
            return Outcome::refuse('BRIEFING_NOT_DRAFT', [
                'briefing_status' => (string) $briefing['briefing_status'],
            ], 'POLICY');
        }

        // Re-checked at SUBMIT, having already been checked at composition. The two moments are
        // different: a release can be withdrawn in between, and M08 re-checks it a third time at
        // the verdict. Each check governs the moment it runs at, and none of them substitutes.
        $decisionRecordId = $this->briefingDecisionRecord($briefing);
        if ($decisionRecordId === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied($decisionRecordId)) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance($decisionRecordId),
            ], 'PROVENANCE', 'S4');
        }

        $governance = $input['governance'];
        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM missions WHERE mission_id = ?',
            [$governance['mission_id']]
        );
        if ($mission === null) {
            return Outcome::refuse('MISSION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $mission['tenant_id'] !== $input['tenant_id']
            || (string) $mission['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('MISSION_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $mission['status'] !== 'ACTIVE') {
            return Outcome::refuse('MISSION_NOT_ACTIVE', ['status' => (string) $mission['status']], 'POLICY');
        }

        $sensitivityClass = (string) ($input['sensitivity_class'] ?? 'ROUTINE');
        $targetRole = (string) $briefing['target_org_role'];

        return $this->db->transaction(function (Database $tx) use (
            $input, $governance, $decisionRecordId, $sensitivityClass, $targetRole
        ): Outcome {
            // 1. The distribution BASIS: a derived reference that cites the source's M10 record.
            //    Never the raw content — the basis proves which release is being routed, and the
            //    content stays where it was released.
            $basisSourceRefId = $this->evidence->registerSource(
                $input['tenant_id'],
                $input['workspace_id'],
                'PROJECT_CANONICAL_OBJECT',
                'distribution-basis:' . $input['role_distribution_briefing_id'],
                'internal_role_distribution',
                'INTERNAL_DEMO',
                'ACTIVE_FOR_CAPTURE',
            );
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'source_ref_id' => $basisSourceRefId,
                'evidence_type' => 'DERIVED_ANNOTATION_REFERENCE',
                'captured_content_reference_safe' => sprintf(
                    'Distribution basis for role %s: cites M10 release %s.',
                    $targetRole,
                    $decisionRecordId,
                ),
                'content_hash' => Digest::canonicalDigest([
                    'roleDistributionBriefingId' => $input['role_distribution_briefing_id'],
                    'decisionRecordId' => $decisionRecordId,
                    'targetOrgRole' => $targetRole,
                ]),
                'capture_method' => 'DISTRIBUTION_BASIS_REFERENCE',
                'permitted_use_scope' => 'internal_role_distribution',
                'sensitivity_class' => 'INTERNAL_DEMO',
            ]);
            if ($captured->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $captured->reasonCode(),
                ], 'EVIDENCE');
            }
            $evidenceId = $captured->value()['evidence_id'];

            // 2. Freeze the bundle.
            $bundleId = $this->evidence->createBundleDraft(
                $input['tenant_id'],
                $input['workspace_id'],
                $governance['mission_id'],
                null,
                'distribution routing basis evidence',
            );
            $added = $this->evidence->addBundleItem($bundleId, $evidenceId);
            if ($added->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $added->reasonCode(),
                ], 'EVIDENCE');
            }
            $submittedBundle = $this->evidence->submitBundle($bundleId);
            if ($submittedBundle->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $submittedBundle->reasonCode(),
                ], 'EVIDENCE');
            }

            // 3. The proposal DRAFT. The routing governance facts go into the release scope, where
            //    the submission digest will freeze them.
            $draft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => 'INTELLIGENCE_DISTRIBUTION_ROUTING',
                'requested_action_class' => 'RELEASE_INTELLIGENCE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'Intelligence distribution routing — ' . $targetRole,
                'safe_purpose_summary' =>
                    'HMWH sensitivity review of an intelligence-distribution routing (role-scoped briefing).',
                'proposed_content_or_action_summary' =>
                    'Route an M10-recorded P01/P03 briefing to an authorized org-role; internal in-console release only.',
                'uncertainty_summary' =>
                    'Routing references the canonical released source; it derives no new fact and copies no raw content.',
                'declared_limitations' => [
                    // Both stated on every routing, because both are true of every routing.
                    'Internal in-console release and presentation only; no external delivery.',
                    'Role-class only; no individual identifier and no covert employee or stakeholder telemetry.',
                ],
                'evidence_refs' => [$evidenceId],
                'evidence_bundle_id' => $bundleId,
                'requested_release_or_action_scope' => json_encode([
                    'targetOrgRole' => $targetRole,
                    'sensitivityClass' => $sensitivityClass,
                    'legalBasisRef' => $input['legal_basis_ref'] ?? null,
                ], JSON_THROW_ON_ERROR),
            ]);
            if ($draft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $draft->reasonCode(),
                ], 'POLICY');
            }
            $proposalId = $draft->value()['proposal_id'];

            // 4. Admissibility BEFORE submission: a non-satisfied bundle never becomes a submitted
            //    proposal, so no reviewer is ever asked about a basis that was not ready.
            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                    'reason_codes' => $readiness['reason_codes'],
                ], 'EVIDENCE');
            }

            // 5. Freeze the proposal and advance the briefing.
            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }
            $tx->run(
                "UPDATE role_distribution_briefings
                 SET proposal_id = ?, briefing_status = 'PENDING_VERDICT'
                 WHERE role_distribution_briefing_id = ?",
                [$proposalId, $input['role_distribution_briefing_id']]
            );
            $this->appendTransition(
                $tx,
                $input['role_distribution_briefing_id'],
                'DRAFT',
                'PENDING_VERDICT',
                'ROUTING_PROPOSAL_SUBMITTED',
            );

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'proposal_id' => $proposalId,
                'evidence_bundle_id' => $bundleId,
                'submission_digest' => $submitted->value()['submission_digest'],
                'readiness_status' => $readiness['requirement_status'],
            ]);
        });
    }

    /**
     * Open the M07 sensitivity review over a submitted routing.
     *
     * The question the council is asked is narrow and stated: is THIS briefing cleared for THIS
     * role, and is there a basis for showing it there.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, role_distribution_briefing_id: string,
     *     review_mode?: string, initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openSensitivityReview(array $input): Outcome
    {
        $briefing = $this->db->one(
            'SELECT tenant_id, workspace_id, briefing_status, proposal_id
             FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($briefing === null) {
            return Outcome::refuse('BRIEFING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $briefing['tenant_id'] !== $input['tenant_id']
            || (string) $briefing['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BRIEFING_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $briefing['briefing_status'] !== 'PENDING_VERDICT' || $briefing['proposal_id'] === null) {
            return Outcome::refuse('BRIEFING_NOT_PENDING_VERDICT', [
                'briefing_status' => (string) $briefing['briefing_status'],
            ], 'POLICY');
        }

        $proposalId = (string) $briefing['proposal_id'];
        $proposal = $this->db->one(
            'SELECT mission_id, evidence_bundle_id FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }

        $opened = $this->council->openSession(
            $input['tenant_id'],
            $input['workspace_id'],
            (string) $proposal['mission_id'],
            $proposalId,
            (string) ($input['review_mode'] ?? 'MULTI_REVIEWER_DEMO'),
            'DISTRIBUTION_SENSITIVITY_REVIEW',
            $input['initiated_by_actor_type'],
            $input['initiated_by_actor_id'],
            $proposal['evidence_bundle_id'] === null ? null : (string) $proposal['evidence_bundle_id'],
        );
        if ($opened->isRefusal()) {
            return Outcome::refuse('REVIEW_NOT_OPENED', [
                'detail' => $opened->reasonCode(),
            ], 'REVIEW');
        }

        return Outcome::ok([
            'review_session_id' => $opened->value()['review_session_id'],
            'proposal_id' => $proposalId,
            'evidence_bundle_id' => $proposal['evidence_bundle_id'],
        ]);
    }

    /**
     * Evaluate the routing and act on the verdict.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, role_distribution_briefing_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string
     * } $input
     */
    public function admitRouting(array $input): Outcome
    {
        $briefing = $this->db->one(
            'SELECT b.tenant_id, b.workspace_id, b.briefing_status, b.proposal_id,
                    pe.proposal_type
             FROM role_distribution_briefings b
             LEFT JOIN proposal_envelopes pe ON pe.proposal_id = b.proposal_id
             WHERE b.role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($briefing === null) {
            return Outcome::refuse('BRIEFING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $briefing['tenant_id'] !== $input['tenant_id']
            || (string) $briefing['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BRIEFING_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $briefing['briefing_status'] !== 'PENDING_VERDICT' || $briefing['proposal_id'] === null) {
            return Outcome::refuse('BRIEFING_NOT_PENDING_VERDICT', [
                'briefing_status' => (string) $briefing['briefing_status'],
            ], 'POLICY');
        }
        // The bound proposal must be the RIGHT KIND. A briefing pointing at some other proposal
        // type would be borrowing a verdict issued about a different question entirely.
        if ((string) $briefing['proposal_type'] !== 'INTELLIGENCE_DISTRIBUTION_ROUTING') {
            return Outcome::refuse('NOT_A_DISTRIBUTION_ROUTING_PROPOSAL', [
                'proposal_type' => $briefing['proposal_type'],
            ], 'POLICY');
        }

        $proposalId = (string) $briefing['proposal_id'];

        return $this->db->transaction(function (Database $tx) use ($input, $proposalId): Outcome {
            $decision = $this->arbiter->evaluateProposal(
                $proposalId,
                $input['arbiter_policy_set_id'],
                $input['correlation_id'] ?? null,
            );
            if ($decision->isRefusal()) {
                return Outcome::refuse('EVALUATION_REJECTED', [
                    'detail' => $decision->reasonCode(),
                ], 'POLICY');
            }

            $result = $decision->value();
            $verdict = (string) $result['verdict'];
            $briefingId = $input['role_distribution_briefing_id'];

            if ($verdict === 'ALLOW_INFORMATIONAL_RELEASE') {
                $tx->run(
                    "UPDATE role_distribution_briefings
                     SET briefing_status = 'VERDICT_ISSUED', arbiter_verdict_id = ?
                     WHERE role_distribution_briefing_id = ?",
                    [$result['arbiter_verdict_id'], $briefingId]
                );
                $this->appendTransition($tx, $briefingId, 'PENDING_VERDICT', 'VERDICT_ISSUED', 'ADMITTED_BY_VERDICT');

                return Outcome::ok([
                    'outcome' => 'ADMITTED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                    'permitted_next_stage' => $result['permitted_next_stage'],
                ]);
            }

            // A non-allow verdict never advances the briefing. BLOCK and QUARANTINE close it; the
            // rest leave it PENDING_VERDICT, which is exactly where a request for more evidence or
            // a revision should leave it.
            $terminates = in_array($verdict, self::TERMINATING_VERDICTS, true);
            if ($terminates) {
                $tx->run(
                    "UPDATE role_distribution_briefings SET briefing_status = 'BLOCKED'
                     WHERE role_distribution_briefing_id = ?",
                    [$briefingId]
                );
                // The reason code IS the verdict: the record says which finding closed it.
                $this->appendTransition($tx, $briefingId, 'PENDING_VERDICT', 'BLOCKED', $verdict);
            }

            return Outcome::ok([
                'outcome' => 'REFUSED',
                'verdict' => $verdict,
                'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                'reason_codes' => $result['reason_codes'],
                'briefing_blocked' => $terminates,
            ]);
        });
    }

    /** The M10 record behind a briefing's cited source (exactly one source FK is non-null). */
    private function briefingDecisionRecord(array $briefing): ?string
    {
        $row = $briefing['released_briefing_id'] !== null
            ? $this->db->one(
                'SELECT decision_record_id FROM released_briefings WHERE released_briefing_id = ?',
                [(string) $briefing['released_briefing_id']]
            )
            : ($briefing['released_prospect_output_id'] !== null
                ? $this->db->one(
                    'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
                    [(string) $briefing['released_prospect_output_id']]
                )
                : null);

        return $row === null ? null : (string) $row['decision_record_id'];
    }

    private function appendTransition(
        Database $tx,
        string $briefingId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
    ): void {
        $tx->insert('role_distribution_briefing_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'role_distribution_briefing_id' => $briefingId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'transitioned_at' => $this->clock->now(),
        ]);
    }
}
