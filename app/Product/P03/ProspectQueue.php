<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M08\Arbiter;

/**
 * The two lead-qualification admissions, and the difference between them.
 *
 *   THE WATCH LIST      "There is a reason to look at this."
 *   THE PRIORITY QUEUE  "There is a timely reason to act on this."
 *
 * The second is a stronger claim, and it is the only one the buyer-intent invariant constrains. A
 * Watch-List entry asserts a match; a queue entry asserts a MOMENT — that something happened
 * recently enough that acting now makes sense. Asserting a moment requires a buying TRIGGER signal
 * that was directly OBSERVED, and the arbiter blocks the admission as an overclaim otherwise.
 *
 * A QUEUE ITEM IS NOT AN OUTREACH ACT. It is a signal to a human that one MIGHT be warranted. No
 * message leaves this module, no CRM is written, and no external effect is reachable from here —
 * outreach is a separate, separately-gated surface.
 *
 * The storage says the same thing independently: `triggering_company_signal_id` is a required
 * column, so a queue item that cited no trigger could not be written even if this code tried.
 */
final class ProspectQueue
{
    /**
     * Verdicts that TERMINATE the hypothesis.
     *
     * A buyer-intent overclaim BLOCKs, and a block here is not an invitation to revise: the claim
     * was never available to make. A hold or a request for more evidence leaves the hypothesis
     * submitted, because those verdicts are asking for something.
     *
     * @var list<string>
     */
    private const TERMINATING_VERDICTS = ['BLOCK', 'QUARANTINE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Proposals $proposals,
        private readonly EvidenceSpine $evidence,
        private readonly Readiness $readiness,
        private readonly Arbiter $arbiter,
    ) {
    }

    /**
     * Submit a fit hypothesis for admission, on ONE of the two paths.
     *
     * The path is chosen HERE, by the proposal type, and it is what the buyer-intent rule reads.
     * A caller asking for the queue is making the stronger claim and will be held to it.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, fit_hypothesis_id: string,
     *     admission_path: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submitForAdmission(array $input): Outcome
    {
        $path = $input['admission_path'];
        if (!in_array($path, ['FIT_HYPOTHESIS', 'PRIORITY_OUTREACH_QUEUE_ADMISSION'], true)) {
            return Outcome::refuse('ADMISSION_PATH_NOT_RECOGNIZED', ['admission_path' => $path], 'POLICY');
        }

        $hypothesis = $this->db->one(
            'SELECT fh.tenant_id, fh.workspace_id, fh.status, fh.proposal_id, fh.fit_band,
                    fh.basis_evidence_id, fh.company_signal_id,
                    cs.signal_class, cs.interpretation_kind, cs.summary_safe
             FROM fit_hypotheses fh
             JOIN company_signals cs ON cs.company_signal_id = fh.company_signal_id
             WHERE fh.fit_hypothesis_id = ?',
            [$input['fit_hypothesis_id']]
        );
        if ($hypothesis === null) {
            return Outcome::refuse('FIT_HYPOTHESIS_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $hypothesis['tenant_id'] !== $input['tenant_id']
            || (string) $hypothesis['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('FIT_HYPOTHESIS_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $hypothesis['status'] !== 'DRAFT' || $hypothesis['proposal_id'] !== null) {
            return Outcome::refuse('FIT_HYPOTHESIS_NOT_DRAFT', [
                'status' => (string) $hypothesis['status'],
            ], 'POLICY');
        }

        $governance = $input['governance'];

        return $this->db->transaction(function (Database $tx) use ($input, $hypothesis, $path, $governance): Outcome {
            $bundleId = $this->evidence->createBundleDraft(
                $input['tenant_id'], $input['workspace_id'], $governance['mission_id'], null,
                'fit hypothesis admission evidence',
            );
            $added = $this->evidence->addBundleItem($bundleId, (string) $hypothesis['basis_evidence_id']);
            if ($added->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', ['detail' => $added->reasonCode()], 'EVIDENCE');
            }
            $submittedBundle = $this->evidence->submitBundle($bundleId);
            if ($submittedBundle->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $submittedBundle->reasonCode(),
                ], 'EVIDENCE');
            }

            $draft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => $path,
                'requested_action_class' => 'ANALYZE',
                'requested_next_stage' => 'ARBITER_EVALUATION',
                'requested_review_path' => 'ARBITER_ONLY',
                'title' => $path === 'PRIORITY_OUTREACH_QUEUE_ADMISSION'
                    ? 'Priority outreach queue admission'
                    : 'Watch list admission',
                'safe_purpose_summary' => $path === 'PRIORITY_OUTREACH_QUEUE_ADMISSION'
                    ? 'Admit a fit hypothesis to the priority outreach queue, citing a buying-trigger signal.'
                    : 'Admit a fit hypothesis to the watch list as a reason to investigate.',
                'proposed_content_or_action_summary' => (string) $hypothesis['summary_safe'],
                // The uncertainty statement says what the admission does NOT claim. On the queue
                // path especially: a timely reason to act is still not evidence that anyone wants
                // to buy, and the brief has to say so where a reader will see it.
                'uncertainty_summary' => $path === 'PRIORITY_OUTREACH_QUEUE_ADMISSION'
                    ? 'A cited buying trigger establishes timeliness, NOT buyer intent. Whether the prospect wants to buy is unobserved.'
                    : 'A reason to investigate, not a reason to act. No timeliness and no buyer intent are claimed.',
                'declared_limitations' => [
                    'Fit is a term-overlap measurement between the customer\'s catalogue and an observed competitor change.',
                    'No claim is made about any individual\'s intentions.',
                ],
                'evidence_refs' => [(string) $hypothesis['basis_evidence_id']],
                'evidence_bundle_id' => $bundleId,
            ]);
            if ($draft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', ['detail' => $draft->reasonCode()], 'POLICY');
            }
            $proposalId = $draft->value()['proposal_id'];

            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                ], 'EVIDENCE');
            }

            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }
            // The hypothesis is bound to its proposal BEFORE the verdict, because the arbiter's
            // context is loaded through that binding: an unbound hypothesis has no fit context, and
            // the P03 rules would stand aside rather than judge.
            $tx->run(
                "UPDATE fit_hypotheses SET proposal_id = ?, status = 'SUBMITTED' WHERE fit_hypothesis_id = ?",
                [$proposalId, $input['fit_hypothesis_id']]
            );

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'proposal_id' => $proposalId,
                'admission_path' => $path,
                'evidence_bundle_id' => $bundleId,
            ]);
        });
    }

    /**
     * Take the verdict, and — only on an ALLOW down the queue path — create the queue item.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, fit_hypothesis_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string
     * } $input
     */
    public function admit(array $input): Outcome
    {
        $hypothesis = $this->db->one(
            'SELECT fh.tenant_id, fh.workspace_id, fh.status, fh.proposal_id, fh.company_signal_id,
                    pe.proposal_type
             FROM fit_hypotheses fh
             JOIN proposal_envelopes pe ON pe.proposal_id = fh.proposal_id
             WHERE fh.fit_hypothesis_id = ?',
            [$input['fit_hypothesis_id']]
        );
        if ($hypothesis === null) {
            return Outcome::refuse('FIT_HYPOTHESIS_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $hypothesis['tenant_id'] !== $input['tenant_id']
            || (string) $hypothesis['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('FIT_HYPOTHESIS_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $hypothesis['status'] !== 'SUBMITTED') {
            return Outcome::refuse('FIT_HYPOTHESIS_NOT_SUBMITTED', [
                'status' => (string) $hypothesis['status'],
            ], 'POLICY');
        }

        $existing = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM priority_outreach_queue_items WHERE fit_hypothesis_id = ?',
            [$input['fit_hypothesis_id']]
        );
        if ($existing > 0) {
            return Outcome::refuse('QUEUE_ITEM_ALREADY_EXISTS', [], 'POLICY');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $hypothesis): Outcome {
            $decision = $this->arbiter->evaluateProposal(
                (string) $hypothesis['proposal_id'],
                $input['arbiter_policy_set_id'],
                $input['correlation_id'] ?? null,
            );
            if ($decision->isRefusal()) {
                return Outcome::refuse('EVALUATION_REJECTED', ['detail' => $decision->reasonCode()], 'POLICY');
            }

            $verdict = (string) $decision->value()['verdict'];
            $verdictId = (string) $decision->value()['arbiter_verdict_id'];
            $isQueuePath = (string) $hypothesis['proposal_type'] === 'PRIORITY_OUTREACH_QUEUE_ADMISSION';

            if ($verdict === 'ALLOW_INFORMATIONAL_RELEASE') {
                $tx->run(
                    "UPDATE fit_hypotheses SET arbiter_verdict_id = ?, status = 'ADMITTED'
                     WHERE fit_hypothesis_id = ?",
                    [$verdictId, $input['fit_hypothesis_id']]
                );

                // A WATCH-LIST admission creates no queue item. The two paths produce different
                // things, and collapsing them would make every admitted hypothesis a call-now lead.
                if (!$isQueuePath) {
                    return Outcome::ok([
                        'outcome' => 'ADMITTED_TO_WATCH_LIST',
                        'verdict' => $verdict,
                        'arbiter_verdict_id' => $verdictId,
                    ]);
                }

                $queueItemId = Ids::new();
                $tx->insert('priority_outreach_queue_items', [
                    'priority_outreach_queue_item_id' => $queueItemId,
                    'schema_version' => '1',
                    'tenant_id' => $input['tenant_id'],
                    'workspace_id' => $input['workspace_id'],
                    'fit_hypothesis_id' => $input['fit_hypothesis_id'],
                    // REQUIRED. A queue item that cited no trigger could not be written even if this
                    // code tried — the storage says the same thing the arbiter rule does.
                    'triggering_company_signal_id' => (string) $hypothesis['company_signal_id'],
                    'arbiter_verdict_id' => $verdictId,
                    // OPEN means "a human should look", not "outreach has begun". Nothing here
                    // contacts anyone.
                    'queue_status' => 'OPEN',
                    'created_at' => $this->clock->now(),
                ]);
                $tx->insert('priority_outreach_queue_item_status_transitions', [
                    'transition_id' => Ids::new(),
                    'schema_version' => '1',
                    'priority_outreach_queue_item_id' => $queueItemId,
                    'from_status' => null,
                    'to_status' => 'OPEN',
                    'reason_code' => 'ADMITTED',
                    'transitioned_at' => $this->clock->now(),
                ]);

                return Outcome::ok([
                    'outcome' => 'ADMITTED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => $verdictId,
                    'priority_outreach_queue_item_id' => $queueItemId,
                    'triggering_company_signal_id' => (string) $hypothesis['company_signal_id'],
                ]);
            }

            // Any non-allowing verdict produces NO queue item. A BLOCK — which is what a
            // buyer-intent overclaim earns — terminates the hypothesis: the claim was never
            // available to make, so there is nothing to revise into.
            $terminates = in_array($verdict, self::TERMINATING_VERDICTS, true);
            if ($terminates) {
                $tx->run(
                    "UPDATE fit_hypotheses SET arbiter_verdict_id = ?, status = 'REJECTED'
                     WHERE fit_hypothesis_id = ?",
                    [$verdictId, $input['fit_hypothesis_id']]
                );
            }

            return Outcome::ok([
                'outcome' => 'REFUSED',
                'verdict' => $verdict,
                'arbiter_verdict_id' => $verdictId,
                'reason_codes' => $decision->value()['reason_codes'] ?? [],
                'hypothesis_terminated' => $terminates,
            ]);
        });
    }
}
