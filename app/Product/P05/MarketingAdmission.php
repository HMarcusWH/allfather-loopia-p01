<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P05;

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
 * P05 marketing-asset admission — the governed route from a DRAFT to a verdict.
 *
 * The chain is the one every other proposal takes: M04 envelope, M05 frozen bundle, M07 review,
 * M08 verdict. What is being asked for is `RELEASE_INTELLIGENCE` — that the tenant's own people
 * may read this draft in the console — and the M04 ceiling refuses the two external classes
 * outright, so this path cannot request a publication even in principle.
 *
 * SUBMITTING CLOSES THE DRAFT FOR EDITS. It moves to IN_REVIEW, and both the claim-citation and
 * variant paths refuse anything but DRAFT. That is what makes the review honest: the reviewer and
 * the arbiter are looking at the same words, and nobody can add a comparative claim underneath a
 * verdict that cleared copy without one.
 *
 * FOUR OF THE FIVE NON-ALLOW VERDICTS CLOSE THE DRAFT — including HOLD, unlike P04 where HOLD
 * leaves the briefing pending. The asymmetry is deliberate: a marketing draft that did not clear is
 * revised into a NEW draft rather than resubmitted, because its content is the thing that was
 * judged and changing it makes it a different asset.
 *
 * `REQUIRE_MORE_EVIDENCE` is the exception, and the reason is precise: it is the one verdict that
 * asks for something OTHER than different copy. The words the reviewer read are still the words,
 * so the draft stays in review while the missing basis is supplied.
 */
final class MarketingAdmission
{
    /**
     * The verdicts that CLOSE a draft.
     *
     * `REQUIRE_MORE_EVIDENCE` is deliberately absent: it asks for a missing basis, not for
     * different copy, and the draft it was issued about is still the draft under review.
     *
     * @var list<string>
     */
    public const BLOCKING_VERDICTS = ['BLOCK', 'QUARANTINE', 'REQUIRE_REVISION', 'HOLD'];

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
     * Submit a DRAFT as a governed MARKETING_ASSET_DRAFT proposal.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submitDraftProposal(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_status, asset_kind, title_safe,
                    released_briefing_id, released_prospect_output_id, proposal_id
             FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $draft['tenant_id'] !== $input['tenant_id']
            || (string) $draft['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Both clauses. A draft already carrying a proposal is not DRAFT whatever its status says.
        if ((string) $draft['asset_status'] !== 'DRAFT' || $draft['proposal_id'] !== null) {
            return Outcome::refuse('DRAFT_NOT_DRAFT', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
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

        // Re-checked at SUBMIT, having been checked at composition and about to be checked again at
        // the verdict. Each governs its own moment; none substitutes.
        $decisionRecordId = $this->draftSourceDecisionRecord($draft);
        if ($decisionRecordId === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied($decisionRecordId)) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance($decisionRecordId),
            ], 'PROVENANCE', 'S4');
        }

        return $this->db->transaction(function (Database $tx) use (
            $input, $draft, $governance, $decisionRecordId
        ): Outcome {
            // 1. The draft BASIS: a derived reference citing the source's M10 record. Not the raw
            //    content — the basis proves which release this copy was built from.
            $basisSourceRefId = $this->evidence->registerSource(
                $input['tenant_id'],
                $input['workspace_id'],
                'PROJECT_CANONICAL_OBJECT',
                'marketing-asset-draft-basis:' . $input['marketing_asset_draft_id'],
                'marketing_asset_draft_admission',
                'INTERNAL_DEMO',
                'ACTIVE_FOR_CAPTURE',
            );
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'source_ref_id' => $basisSourceRefId,
                'evidence_type' => 'MARKETING_ASSET_DRAFT_BASIS_REFERENCE',
                'captured_content_reference_safe' => sprintf(
                    'Marketing asset draft basis: %s; cites M10 release %s.',
                    (string) $draft['asset_kind'],
                    $decisionRecordId,
                ),
                'content_hash' => Digest::canonicalDigest([
                    'marketingAssetDraftId' => $input['marketing_asset_draft_id'],
                    'decisionRecordId' => $decisionRecordId,
                    'assetKind' => (string) $draft['asset_kind'],
                    'titleSafe' => (string) $draft['title_safe'],
                ]),
                'capture_method' => 'MARKETING_ASSET_DRAFT_BASIS_REFERENCE',
                'permitted_use_scope' => 'marketing_asset_draft_admission',
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
                'marketing asset draft admission basis evidence',
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

            // 3. The proposal DRAFT.
            $proposalDraft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => 'MARKETING_ASSET_DRAFT',
                'requested_action_class' => 'RELEASE_INTELLIGENCE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'Marketing asset draft — ' . (string) $draft['title_safe'],
                'safe_purpose_summary' =>
                    'HMWH and arbiter admission of an internal marketing asset draft; no publication or send.',
                'proposed_content_or_action_summary' =>
                    'Evaluate a marketing asset draft for INTERNAL release eligibility. Publication is not in this plane.',
                'uncertainty_summary' =>
                    'The draft derives from an M10-recorded released source and cites governed evidence for comparative claims.',
                'declared_limitations' => [
                    // Stated on every draft because both are true of every draft.
                    'Internal draft and recommendation only; no external publication, dispatch, send or CRM writeback.',
                    'Comparative claims must cite governed evidence objects.',
                ],
                'evidence_refs' => [$evidenceId],
                'evidence_bundle_id' => $bundleId,
                'requested_release_or_action_scope' => json_encode([
                    'assetKind' => (string) $draft['asset_kind'],
                    'sourceDecisionRecordId' => $decisionRecordId,
                ], JSON_THROW_ON_ERROR),
            ]);
            if ($proposalDraft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $proposalDraft->reasonCode(),
                ], 'POLICY');
            }
            $proposalId = $proposalDraft->value()['proposal_id'];

            // 4. Admissibility BEFORE submission.
            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                    'reason_codes' => $readiness['reason_codes'],
                ], 'EVIDENCE');
            }

            // 5. Freeze the proposal and CLOSE the draft for edits.
            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }
            $tx->run(
                "UPDATE marketing_asset_drafts
                 SET proposal_id = ?, basis_evidence_id = ?, asset_status = 'IN_REVIEW'
                 WHERE marketing_asset_draft_id = ?",
                [$proposalId, $evidenceId, $input['marketing_asset_draft_id']]
            );
            $this->appendTransition(
                $tx,
                $input['marketing_asset_draft_id'],
                'DRAFT',
                'IN_REVIEW',
                'MARKETING_ASSET_DRAFT_PROPOSAL_SUBMITTED',
            );

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'proposal_id' => $proposalId,
                'evidence_bundle_id' => $bundleId,
                'basis_evidence_id' => $evidenceId,
                'submission_digest' => $submitted->value()['submission_digest'],
                'readiness_status' => $readiness['requirement_status'],
            ]);
        });
    }

    /**
     * Open the M07 marketing-asset review over a submitted draft.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     review_mode?: string, initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openAssetReview(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_status, proposal_id
             FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $draft['tenant_id'] !== $input['tenant_id']
            || (string) $draft['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $draft['asset_status'] !== 'IN_REVIEW' || $draft['proposal_id'] === null) {
            return Outcome::refuse('DRAFT_NOT_IN_REVIEW', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }

        $proposalId = (string) $draft['proposal_id'];
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
            'MARKETING_ASSET_REVIEW',
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
     * Evaluate the draft and act on the verdict.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string
     * } $input
     */
    public function issueVerdict(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT d.tenant_id, d.workspace_id, d.asset_status, d.proposal_id, pe.proposal_type
             FROM marketing_asset_drafts d
             LEFT JOIN proposal_envelopes pe ON pe.proposal_id = d.proposal_id
             WHERE d.marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $draft['tenant_id'] !== $input['tenant_id']
            || (string) $draft['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $draft['asset_status'] !== 'IN_REVIEW' || $draft['proposal_id'] === null) {
            return Outcome::refuse('DRAFT_NOT_IN_REVIEW', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }
        // The bound proposal must be the right kind, or the draft would be borrowing a verdict
        // issued about a different question.
        if ((string) $draft['proposal_type'] !== 'MARKETING_ASSET_DRAFT') {
            return Outcome::refuse('NOT_A_MARKETING_ASSET_DRAFT_PROPOSAL', [
                'proposal_type' => $draft['proposal_type'],
            ], 'POLICY');
        }

        $proposalId = (string) $draft['proposal_id'];

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
            $draftId = $input['marketing_asset_draft_id'];

            if ($verdict === 'ALLOW_INFORMATIONAL_RELEASE') {
                $tx->run(
                    "UPDATE marketing_asset_drafts
                     SET asset_status = 'VERDICT_ISSUED', arbiter_verdict_id = ?
                     WHERE marketing_asset_draft_id = ?",
                    [$result['arbiter_verdict_id'], $draftId]
                );
                $this->appendTransition($tx, $draftId, 'IN_REVIEW', 'VERDICT_ISSUED', 'ADMITTED_BY_VERDICT');

                return Outcome::ok([
                    'outcome' => 'ADMITTED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                    'permitted_next_stage' => $result['permitted_next_stage'],
                ]);
            }

            $blocks = in_array($verdict, self::BLOCKING_VERDICTS, true);
            if ($blocks) {
                $tx->run(
                    "UPDATE marketing_asset_drafts SET asset_status = 'BLOCKED', arbiter_verdict_id = ?
                     WHERE marketing_asset_draft_id = ?",
                    [$result['arbiter_verdict_id'], $draftId]
                );
                // The reason code IS the verdict: the record says which finding closed it.
                $this->appendTransition($tx, $draftId, 'IN_REVIEW', 'BLOCKED', $verdict);
            }

            return Outcome::ok([
                'outcome' => 'REFUSED',
                'verdict' => $verdict,
                'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                'reason_codes' => $result['reason_codes'],
                'draft_blocked' => $blocks,
            ]);
        });
    }

    /** @param array<string, mixed> $draft */
    private function draftSourceDecisionRecord(array $draft): ?string
    {
        $row = $draft['released_briefing_id'] !== null
            ? $this->db->one(
                'SELECT decision_record_id FROM released_briefings WHERE released_briefing_id = ?',
                [(string) $draft['released_briefing_id']]
            )
            : ($draft['released_prospect_output_id'] !== null
                ? $this->db->one(
                    'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
                    [(string) $draft['released_prospect_output_id']]
                )
                : null);

        return $row === null ? null : (string) $row['decision_record_id'];
    }

    private function appendTransition(
        Database $tx,
        string $draftId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
    ): void {
        $tx->insert('marketing_asset_draft_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'marketing_asset_draft_id' => $draftId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'transitioned_at' => $this->clock->now(),
        ]);
    }
}
