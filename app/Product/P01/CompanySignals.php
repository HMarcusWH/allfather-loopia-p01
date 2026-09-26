<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;

/**
 * P01 company signals — turning a detected change into a governed, evidence-cited proposal that has
 * been relevance-reviewed, and STOPPING just before the arbiter verdict.
 *
 * The path bypasses no plane:
 *
 *     source diff  ->  company signal (a class + an interpretation kind)
 *                  ->  M04 proposal envelope
 *                  ->  M05 evidence bundle citing the two snapshots and the change reference
 *                  ->  M07 relevance review — "does this matter to the customer?"
 *
 * M04, M05 and M07 are REUSED, never re-implemented. This module contributes the P01-specific
 * question and lets the certified planes answer everything they already answer.
 *
 * THE LOAD-BEARING LINE IS FACT VERSUS HYPOTHESIS.
 *
 * A signal records what was OBSERVED and, separately, how it is INTERPRETED. An unclassified change
 * — one where the hash moved but no text was available to measure how much — is inherently
 * uncertain, and recording it as an observed FACT would be speculation wearing a fact's label. So
 * `FACT_OBSERVED` is refused for it, and the signal must be a `HYPOTHESIS`.
 *
 * This is the same line that keeps a product-fit hypothesis from becoming buyer intent. A signal
 * says a competitor's page changed. It does not say a customer cares, and nothing in this module
 * can make it say so — that judgement belongs to the review and the verdict, with a human in it.
 */
final class CompanySignals
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Proposals $proposals,
        private readonly EvidenceSpine $evidence,
        private readonly Readiness $readiness,
        private readonly Council $council,
    ) {
    }

    /**
     * Create a DRAFT signal from a detected change.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, monitored_source_id: string,
     *     source_diff_id: string, signal_class: string, interpretation_kind: string,
     *     confidence_band: string, summary_safe: string
     * } $input
     */
    public function createFromDiff(array $input): Outcome
    {
        $row = $this->db->one(
            'SELECT sd.tenant_id, sd.workspace_id, sd.monitored_source_id, sd.change_significance,
                    ms.status AS ms_status, ms.competitor_id, c.status AS competitor_status
             FROM source_diffs sd
             JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
             LEFT JOIN competitors c ON c.competitor_id = ms.competitor_id
             WHERE sd.source_diff_id = ?',
            [$input['source_diff_id']]
        );
        if ($row === null) {
            return Outcome::refuse('SOURCE_DIFF_NOT_FOUND', [], 'PROVENANCE');
        }
        // The diff must be in the caller's scope AND bound to the NAMED monitored source. Without
        // the second clause, a diff from one source could be signalled as another's.
        if ((string) $row['tenant_id'] !== $input['tenant_id']
            || (string) $row['workspace_id'] !== $input['workspace_id']
            || (string) $row['monitored_source_id'] !== $input['monitored_source_id']
        ) {
            return Outcome::refuse('SOURCE_DIFF_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $row['ms_status'] !== 'ACTIVE') {
            return Outcome::refuse('MONITORED_SOURCE_NOT_ACTIVE', [], 'POLICY');
        }
        if ($row['competitor_id'] === null) {
            return Outcome::refuse('COMPETITOR_NOT_FOUND', [], 'PROVENANCE');
        }
        // Defence in depth: registration and capture both checked this, and so does this. A
        // competitor archived after the capture must not acquire new signals.
        if (!in_array((string) $row['competitor_status'], ['ACTIVE', 'PAUSED'], true)) {
            return Outcome::refuse('COMPETITOR_NOT_LIVE', [], 'POLICY');
        }

        $significance = (string) $row['change_significance'];
        // A NONE diff is no change at all. There is nothing to signal, and a signal recorded over
        // one would assert an event that did not occur.
        if ($significance === 'NONE') {
            return Outcome::refuse('SIGNAL_REQUIRES_DETECTED_CHANGE', [], 'POLICY');
        }
        // THE FACT-VERSUS-HYPOTHESIS GUARD. An UNKNOWN change is one whose magnitude was never
        // measured; calling it an observed fact would be speculation. It must be a hypothesis.
        if ($input['interpretation_kind'] === 'FACT_OBSERVED' && $significance === 'UNKNOWN') {
            return Outcome::refuse('FACT_REQUIRES_CLASSIFIED_CHANGE', [
                'change_significance' => $significance,
            ], 'POLICY', 'S3', 'BLOCK_RELEASE');
        }

        $signalId = Ids::new();
        $this->db->insert('company_signals', [
            'company_signal_id' => $signalId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'competitor_id' => (string) $row['competitor_id'],
            'source_diff_id' => $input['source_diff_id'],
            'signal_class' => $input['signal_class'],
            'interpretation_kind' => $input['interpretation_kind'],
            'confidence_band' => $input['confidence_band'],
            // A DERIVED summary — counts and claims only. Never the raw captured page content,
            // which lives in the snapshots where the licence checks reach it.
            'summary_safe' => $input['summary_safe'],
            'proposal_id' => null,
            'status' => 'DRAFT',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'company_signal_id' => $signalId,
            'competitor_id' => (string) $row['competitor_id'],
        ]);
    }

    /**
     * Submit the signal as a governed brief: freeze its evidence, create and freeze its proposal.
     *
     * The ORDER inside the transaction is the design. The bundle is frozen first, then the proposal
     * cites it, then READINESS is evaluated — and only then is the proposal submitted. Evaluating
     * readiness after submission would mean a non-admissible bundle had already become a submitted
     * brief, and a submitted proposal is frozen.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, company_signal_id: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string,
     *         submitted_by_actor_type: string, submitted_by_actor_id: string
     *     }
     * } $input
     */
    public function submitBrief(array $input): Outcome
    {
        $signal = $this->db->one(
            'SELECT tenant_id, workspace_id, status, source_diff_id, signal_class,
                    interpretation_kind, summary_safe, proposal_id
             FROM company_signals WHERE company_signal_id = ?',
            [$input['company_signal_id']]
        );
        if ($signal === null) {
            return Outcome::refuse('SIGNAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $signal['tenant_id'] !== $input['tenant_id']
            || (string) $signal['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('SIGNAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Only a DRAFT signal may be submitted, and one already carrying a proposal is not draft
        // whatever its status column says. Both clauses, because either alone leaves a double-submit
        // path open.
        if ((string) $signal['status'] !== 'DRAFT' || $signal['proposal_id'] !== null) {
            return Outcome::refuse('SIGNAL_NOT_DRAFT', ['status' => (string) $signal['status']], 'POLICY');
        }

        if ($signal['source_diff_id'] === null && array_key_exists('context_evidence_ids',$input)) {
            return Outcome::refuse('CONTEXT_EVIDENCE_NOT_ADMISSIBLE',[],'EVIDENCE');
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

        $citedEvidenceIds = $this->supportingEvidence($signal['source_diff_id']);
        $contextIds = $input['context_evidence_ids'] ?? [];
        if (!is_array($contextIds) || !array_is_list($contextIds) || count($contextIds) > 16) {
            return Outcome::refuse('CONTEXT_EVIDENCE_NOT_ADMISSIBLE',[],'EVIDENCE');
        }
        foreach ($contextIds as $id) {
            if (!is_string($id) || CompanyContext::read($this->db,$id,$input['tenant_id'],$input['workspace_id']) === null) {
                return Outcome::refuse('CONTEXT_EVIDENCE_NOT_ADMISSIBLE',[],'EVIDENCE');
            }
        }
        if (isset($input['uncertainty_summary_safe']) && (!is_string($input['uncertainty_summary_safe']) || strlen($input['uncertainty_summary_safe']) > 8000)) {
            return Outcome::refuse('UNCERTAINTY_SUMMARY_INVALID',[],'POLICY');
        }
        $citedEvidenceIds = array_values(array_unique([...$citedEvidenceIds,...$contextIds]));


        return $this->db->transaction(function (Database $tx) use (
            $input, $signal, $governance, $citedEvidenceIds
        ): Outcome {
            // 1. Freeze the evidence bundle.
            $bundleId = $this->evidence->createBundleDraft(
                $input['tenant_id'],
                $input['workspace_id'],
                $governance['mission_id'],
                null,
                'company signal brief evidence',
            );
            foreach ($citedEvidenceIds as $evidenceId) {
                $added = $this->evidence->addBundleItem($bundleId, $evidenceId);
                if ($added->isRefusal()) {
                    return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                        'detail' => $added->reasonCode(),
                    ], 'EVIDENCE');
                }
            }
            $submittedBundle = $this->evidence->submitBundle($bundleId);
            if ($submittedBundle->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $submittedBundle->reasonCode(),
                ], 'EVIDENCE');
            }

            // 2. Create the proposal DRAFT citing the frozen bundle.
            $draft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => 'COMPANY_SIGNAL_BRIEF',
                'requested_action_class' => 'ANALYZE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'Competition signal brief — ' . (string) $signal['signal_class'],
                'safe_purpose_summary' => sprintf(
                    'HMWH relevance review of a detected competitor change (%s)',
                    (string) $signal['signal_class'],
                ),
                'proposed_content_or_action_summary' => (string) $signal['summary_safe'],
                // The uncertainty statement CARRIES the interpretation kind forward, so a reader of
                // the proposal cannot lose the distinction the signal made.
                'uncertainty_summary' => implode("\n", array_filter([
                    (string) $signal['interpretation_kind'] === 'HYPOTHESIS'
                        ? 'Interpretation recorded as HYPOTHESIS — not an observed fact.'
                        : 'Interpretation recorded as FACT_OBSERVED — the structural change is directly observed.',
                    trim($input['uncertainty_summary_safe'] ?? ''),
                ], static fn (string $v): bool => $v !== '')),
                'declared_limitations' => [
                    // Stated on every brief, because it is true of every brief: this path measures
                    // structure, and relevance is somebody else's judgement.
                    'Structural change detection only; customer relevance is the HMWH review and Arbiter verdict\'s judgement.',
                ],
                'evidence_refs' => $citedEvidenceIds,
                'evidence_bundle_id' => $bundleId,
            ]);
            if ($draft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $draft->reasonCode(),
                ], 'POLICY');
            }
            $proposalId = $draft->value()['proposal_id'];

            // 3. Gate ADMISSIBILITY before submission. A bundle that is missing, empty, stale,
            //    conflicted or quarantined never becomes a submitted brief.
            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                    'reason_codes' => $readiness['reason_codes'],
                ], 'EVIDENCE');
            }

            // 4. Freeze the proposal and advance the signal.
            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }
            $tx->run(
                "UPDATE company_signals SET proposal_id = ?, status = 'PROPOSED' WHERE company_signal_id = ?",
                [$proposalId, $input['company_signal_id']]
            );

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'proposal_id' => $proposalId,
                'evidence_bundle_id' => $bundleId,
                'submission_digest' => $submitted->value()['submission_digest'],
                'cited_evidence_ids' => $citedEvidenceIds,
                'readiness_status' => $readiness['requirement_status'],
            ]);
        });
    }

    /**
     * Open the relevance review — "does this matter to the customer?" — for a PROPOSED signal.
     *
     * It REUSES the Council's session machinery with the competition-relevance purpose. No review
     * behaviour branches on that purpose, so the existing semantics are unchanged — including the
     * one that matters most here: the producer cannot independently review its own brief.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, company_signal_id: string,
     *     review_mode?: string, initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openRelevanceReview(array $input): Outcome
    {
        $signal = $this->db->one(
            'SELECT tenant_id, workspace_id, status, proposal_id FROM company_signals
             WHERE company_signal_id = ?',
            [$input['company_signal_id']]
        );
        if ($signal === null) {
            return Outcome::refuse('SIGNAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $signal['tenant_id'] !== $input['tenant_id']
            || (string) $signal['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('SIGNAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $signal['status'] !== 'PROPOSED' || $signal['proposal_id'] === null) {
            return Outcome::refuse('SIGNAL_NOT_PROPOSED', ['status' => (string) $signal['status']], 'POLICY');
        }

        $proposal = $this->db->one(
            'SELECT mission_id, evidence_bundle_id FROM proposal_envelopes WHERE proposal_id = ?',
            [(string) $signal['proposal_id']]
        );
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $signal, $proposal): Outcome {
            $opened = $this->council->openSession(
                $input['tenant_id'],
                $input['workspace_id'],
                (string) $proposal['mission_id'],
                (string) $signal['proposal_id'],
                $input['review_mode'] ?? 'MULTI_REVIEWER_DEMO',
                'COMPETITION_RELEVANCE_REVIEW',
                $input['initiated_by_actor_type'],
                $input['initiated_by_actor_id'],
                $proposal['evidence_bundle_id'] === null ? null : (string) $proposal['evidence_bundle_id'],
            );
            if ($opened->isRefusal()) {
                return Outcome::refuse('REVIEW_NOT_OPENED', ['detail' => $opened->reasonCode()], 'POLICY');
            }
            $tx->run(
                "UPDATE company_signals SET status = 'UNDER_REVIEW' WHERE company_signal_id = ?",
                [$input['company_signal_id']]
            );

            return Outcome::ok([
                'outcome' => 'OPENED',
                'review_session_id' => $opened->value()['review_session_id'],
                'proposal_id' => (string) $signal['proposal_id'],
                'evidence_bundle_id' => $proposal['evidence_bundle_id'],
            ]);
        });
    }

    /**
     * The evidence a diff-backed signal cites: both snapshots' evidence AND the change reference.
     *
     * All three, because they answer different questions. The snapshots are what was seen, before
     * and after; the change reference is the derivation that says they differ. A bundle citing only
     * the change reference would assert a difference with nothing to compare.
     *
     * @return list<string>
     */
    private function supportingEvidence(?string $sourceDiffId): array
    {
        if ($sourceDiffId === null) {
            return [];
        }
        $diff = $this->db->one(
            'SELECT before_snapshot_id, after_snapshot_id, change_evidence_id FROM source_diffs
             WHERE source_diff_id = ?',
            [$sourceDiffId]
        );
        if ($diff === null) {
            return [];
        }

        $ids = [];
        foreach (['before_snapshot_id', 'after_snapshot_id'] as $field) {
            $evidenceId = $this->db->scalar(
                'SELECT evidence_id FROM source_snapshots WHERE source_snapshot_id = ?',
                [(string) $diff[$field]]
            );
            if ($evidenceId !== null) {
                $ids[] = (string) $evidenceId;
            }
        }
        if ($diff['change_evidence_id'] !== null) {
            $ids[] = (string) $diff['change_evidence_id'];
        }

        return array_values(array_unique($ids));
    }
}
