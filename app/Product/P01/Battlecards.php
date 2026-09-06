<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M08\Arbiter;

/**
 * P01 battlecards — the verdict half of the signal path.
 *
 * A relevance-reviewed signal goes to the arbiter, and ONLY an
 * `ALLOW_INFORMATIONAL_RELEASE` verdict materializes a battlecard. Every other verdict produces no
 * artifact at all.
 *
 * That asymmetry is the point. An artifact is the thing a salesperson reads and repeats, so it may
 * exist only where a governed decision admitted it. A held or blocked signal that still produced a
 * battlecard would put an unadmitted claim in front of a customer through the side door.
 *
 * This module ISSUES no release. The arbiter owns the verdict; M10 owns release. A battlecard here
 * reaches `VERDICT_ISSUED` and stops.
 */
final class Battlecards
{
    /**
     * Verdicts that TERMINATE a signal.
     *
     * A BLOCK or QUARANTINE ends it: the signal is rejected and cannot be revised into a release.
     * Everything else — a hold, a request for more evidence — leaves the signal under review, because
     * those verdicts are asking for something, not refusing outright.
     *
     * @var list<string>
     */
    private const TERMINATING_VERDICTS = ['BLOCK', 'QUARANTINE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Arbiter $arbiter,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, company_signal_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string,
     *     content: array{
     *         why_it_matters_safe: string, customer_impact_hypothesis_safe: string,
     *         talk_track_safe: string, recommended_response_safe: string, rejected_claims_safe: string
     *     }
     * } $input
     */
    public function issueVerdict(array $input): Outcome
    {
        $content = $input['content'];

        // The authored intelligence must be COMPLETE before any verdict is issued. A battlecard with
        // an empty talk track or no rejected-claims section is not a governed artifact — and
        // `rejected_claims_safe` is as required as the rest: what the evidence does NOT support is
        // part of the artifact, not an optional footnote.
        foreach ([
            'why_it_matters_safe', 'customer_impact_hypothesis_safe', 'talk_track_safe',
            'recommended_response_safe', 'rejected_claims_safe',
        ] as $field) {
            if (trim($content[$field] ?? '') === '') {
                return Outcome::refuse('BATTLECARD_CONTENT_INCOMPLETE', ['field' => $field], 'POLICY');
            }
        }

        $signal = $this->db->one(
            'SELECT tenant_id, workspace_id, competitor_id, source_diff_id, signal_class,
                    interpretation_kind, confidence_band, summary_safe, proposal_id, status
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
        // The verdict is issued on a RELEVANCE-REVIEWED signal. One that never went to review has
        // not had the question asked that the verdict answers.
        if ((string) $signal['status'] !== 'UNDER_REVIEW' || $signal['proposal_id'] === null) {
            return Outcome::refuse('SIGNAL_NOT_UNDER_REVIEW', ['status' => (string) $signal['status']], 'POLICY');
        }
        // At most ONE battlecard per signal. A verdict is never re-materialized into a second
        // artifact, because two artifacts for one decision would let a reader find whichever suits.
        $existing = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM battlecard_briefs WHERE company_signal_id = ?',
            [$input['company_signal_id']]
        );
        if ($existing > 0) {
            return Outcome::refuse('BATTLECARD_ALREADY_EXISTS', [], 'POLICY');
        }

        // The verdict and its CONSEQUENCE are one transaction: an admitting verdict commits WITH its
        // battlecard or not at all. There is never an admitting verdict with no artifact, and never
        // an artifact with no verdict.
        return $this->db->transaction(function (Database $tx) use ($input, $signal, $content): Outcome {
            $decision = $this->arbiter->evaluateProposal(
                (string) $signal['proposal_id'],
                $input['arbiter_policy_set_id'],
                $input['correlation_id'] ?? null,
            );
            if ($decision->isRefusal()) {
                return Outcome::refuse('EVALUATION_REJECTED', [
                    'detail' => $decision->reasonCode(),
                ], 'POLICY');
            }

            $verdict = (string) $decision->value()['verdict'];
            if ($verdict === 'ALLOW_INFORMATIONAL_RELEASE') {
                $battlecardId = $this->materialize(
                    $tx,
                    $signal,
                    $input['company_signal_id'],
                    (string) $decision->value()['arbiter_verdict_id'],
                    $content,
                );

                return Outcome::ok([
                    'outcome' => 'ADMITTED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => (string) $decision->value()['arbiter_verdict_id'],
                    'battlecard_brief_id' => $battlecardId,
                ]);
            }

            // A non-allowing verdict produces NO artifact. M10 is never reached.
            $terminates = in_array($verdict, self::TERMINATING_VERDICTS, true);
            if ($terminates) {
                $tx->run(
                    "UPDATE company_signals SET status = 'REJECTED' WHERE company_signal_id = ?",
                    [$input['company_signal_id']]
                );
            }

            return Outcome::ok([
                'outcome' => 'REFUSED',
                'verdict' => $verdict,
                'arbiter_verdict_id' => (string) $decision->value()['arbiter_verdict_id'],
                'reason_codes' => $decision->value()['reason_codes'] ?? [],
                'signal_terminated' => $terminates,
            ]);
        });
    }

    /**
     * Materialize the artifact, carrying its LINEAGE with it.
     *
     * The battlecard records the source licence flags it was built under, so a later reader can see
     * what the artifact was permitted to become without re-joining through four tables — and so a
     * licence changed afterwards cannot silently rewrite what the artifact claimed at the time.
     *
     * @param array<string, mixed> $signal
     * @param array<string, string> $content
     */
    private function materialize(
        Database $db,
        array $signal,
        string $companySignalId,
        string $arbiterVerdictId,
        array $content,
    ): string {
        $lineage = $db->one(
            'SELECT pe.evidence_refs, sd.diff_summary_safe,
                    slc.customer_output_allowed, slc.license_class, slc.allowed_use_class
             FROM company_signals cs
             JOIN proposal_envelopes pe ON pe.proposal_id = cs.proposal_id
             LEFT JOIN source_diffs sd ON sd.source_diff_id = cs.source_diff_id
             LEFT JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
             LEFT JOIN source_license_cards slc ON slc.source_license_card_id = ms.source_license_card_id
             WHERE cs.company_signal_id = ?',
            [$companySignalId]
        );

        // The uncertainty statement is DERIVED from the signal's own interpretation kind, so it
        // cannot be authored into something more confident than the signal was. Note that even a
        // FACT_OBSERVED signal yields a battlecard whose CUSTOMER IMPACT remains a hypothesis: the
        // structural change is observed; what it means for a buyer is not.
        $uncertainty = (string) $signal['interpretation_kind'] === 'HYPOTHESIS'
            ? 'Interpretation recorded as HYPOTHESIS — released as an unverified competitive hypothesis, not an observed fact.'
            : 'Interpretation recorded as FACT_OBSERVED — the structural change is directly observed; downstream customer impact remains a hypothesis.';

        $battlecardId = Ids::new();
        $db->insert('battlecard_briefs', [
            'battlecard_brief_id' => $battlecardId,
            'schema_version' => '1',
            'tenant_id' => (string) $signal['tenant_id'],
            'workspace_id' => (string) $signal['workspace_id'],
            'competitor_id' => (string) $signal['competitor_id'],
            'company_signal_id' => $companySignalId,
            'proposal_id' => (string) $signal['proposal_id'],
            'arbiter_verdict_id' => $arbiterVerdictId,
            'signal_summary_safe' => (string) $signal['summary_safe'],
            'source_evidence_ids' => (string) ($lineage['evidence_refs'] ?? '[]'),
            'before_after_delta_safe' => $lineage['diff_summary_safe']
                ?? sprintf('Structural change detected for %s.', (string) $signal['signal_class']),
            'why_it_matters_safe' => $content['why_it_matters_safe'],
            'customer_impact_hypothesis_safe' => $content['customer_impact_hypothesis_safe'],
            'talk_track_safe' => $content['talk_track_safe'],
            'recommended_response_safe' => $content['recommended_response_safe'],
            'confidence_band' => (string) $signal['confidence_band'],
            'uncertainty_summary_safe' => $uncertainty,
            // What the evidence does NOT support, recorded ON the artifact. A battlecard that only
            // said what was supported would invite a reader to fill the silence themselves.
            'rejected_claims_safe' => $content['rejected_claims_safe'],
            'source_license_visibility_flags' => json_encode([
                'customer_output_allowed' => ((int) ($lineage['customer_output_allowed'] ?? 0)) === 1,
                'license_class' => $lineage['license_class'] ?? null,
                'allowed_use_class' => $lineage['allowed_use_class'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'status' => 'VERDICT_ISSUED',
            'created_at' => $this->clock->now(),
        ]);

        return $battlecardId;
    }
}
