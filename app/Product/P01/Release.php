<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P01 release — a verdict-admitted battlecard becomes an internally released briefing.
 *
 * M10 owns RELEASE. This module does not invent one: it re-validates what P01 knows, hands the
 * release to the decision ledger, and then projects the result onto the P01 domain rows.
 *
 * THE LICENCE IS RE-READ AT THE RELEASE BOUNDARY. The arbiter already gated on it at verdict time,
 * and this checks it again — because the gap between a verdict and a release is real time, in which
 * a card can be blocked, expire, or have its customer-output permission withdrawn. Releasing on a
 * verdict that was correct when issued but is no longer supported would be releasing on stale
 * authority.
 *
 * A signal with no source-card lineage is not silently exempted: the card check simply has nothing
 * to read, and the verdict-time gate stands alone — which the code says explicitly rather than
 * leaving to inference.
 */
final class Release
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly Proposals $proposals,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, battlecard_brief_id: string,
     *     correlation_id?: ?string
     * } $input
     */
    public function releaseBriefing(array $input): Outcome
    {
        $battlecard = $this->db->one(
            'SELECT tenant_id, workspace_id, company_signal_id, proposal_id, arbiter_verdict_id, status
             FROM battlecard_briefs WHERE battlecard_brief_id = ?',
            [$input['battlecard_brief_id']]
        );
        if ($battlecard === null) {
            return Outcome::refuse('BATTLECARD_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $battlecard['tenant_id'] !== $input['tenant_id']
            || (string) $battlecard['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BATTLECARD_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Only a VERDICT_ISSUED battlecard may be released. This is the fail-closed guard against
        // both double release and releasing a BLOCKED one.
        if ((string) $battlecard['status'] !== 'VERDICT_ISSUED') {
            return Outcome::refuse('BATTLECARD_NOT_RELEASABLE', [
                'status' => (string) $battlecard['status'],
            ], 'POLICY');
        }
        if ($battlecard['arbiter_verdict_id'] === null || $battlecard['proposal_id'] === null) {
            return Outcome::refuse('BATTLECARD_MISSING_VERDICT', [], 'PROVENANCE', 'S4');
        }
        // Defence in depth: a release reference must not already exist for this battlecard.
        $alreadyReleased = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM released_briefings WHERE battlecard_brief_id = ?',
            [$input['battlecard_brief_id']]
        );
        if ($alreadyReleased > 0) {
            return Outcome::refuse('ALREADY_RELEASED', [], 'POLICY');
        }

        // --- the release-boundary licence re-check ------------------------------------------------
        $card = $this->db->one(
            'SELECT slc.review_status, slc.customer_output_allowed, slc.license_class, slc.review_expires_at
             FROM company_signals cs
             LEFT JOIN source_diffs sd ON sd.source_diff_id = cs.source_diff_id
             LEFT JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
             LEFT JOIN source_license_cards slc ON slc.source_license_card_id = ms.source_license_card_id
             WHERE cs.company_signal_id = ?',
            [(string) $battlecard['company_signal_id']]
        );
        if ($card !== null && $card['review_status'] !== null) {
            $refusal = $this->cardRefusalAtRelease($card);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        // --- M10 owns the release ------------------------------------------------------------------
        $verdict = $this->db->one(
            'SELECT av.tenant_id, av.workspace_id, av.arbiter_verdict_id, av.verdict, av.basis_hash,
                    pe.evidence_bundle_id
             FROM arbiter_verdicts av
             JOIN proposal_envelopes pe ON pe.proposal_id = av.proposal_id
             WHERE av.arbiter_verdict_id = ?',
            [(string) $battlecard['arbiter_verdict_id']]
        );
        if ($verdict === null || (string) $verdict['verdict'] !== 'ALLOW_INFORMATIONAL_RELEASE') {
            return Outcome::refuse('VERDICT_DOES_NOT_PERMIT_RELEASE', [
                'verdict' => $verdict === null ? null : (string) $verdict['verdict'],
            ], 'POLICY', 'S4');
        }

        $streamId = $this->ledger->ensureActiveStream($input['tenant_id'], $input['workspace_id']);
        $released = $this->ledger->appendInternalRelease(
            $streamId,
            'Released competition battlecard briefing.',
            [
                [
                    'owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT',
                    'input_object_id' => (string) $battlecard['arbiter_verdict_id'],
                    'input_object_version_or_digest' => (string) $verdict['basis_hash'],
                    'reference_role' => 'VERDICT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL',
                    'input_object_id' => (string) $battlecard['proposal_id'],
                    'reference_role' => 'PROPOSAL_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M05', 'input_object_type' => 'EVIDENCE_BUNDLE',
                    'input_object_id' => (string) $verdict['evidence_bundle_id'],
                    'reference_role' => 'EVIDENCE_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
            ],
            ['proposal_id' => (string) $battlecard['proposal_id']],
        );
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        // --- the P01 projection ----------------------------------------------------------------------
        // One transaction, so the release reference and both status advances commit together. A
        // released briefing whose battlecard still read VERDICT_ISSUED would let the same artifact
        // be released a second time.
        return $this->db->transaction(function (Database $tx) use (
            $input, $battlecard, $decisionRecordId
        ): Outcome {
            $releasedBriefingId = Ids::new();
            $tx->insert('released_briefings', [
                'released_briefing_id' => $releasedBriefingId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'briefing_kind' => 'BATTLECARD_BRIEF',
                'company_signal_id' => (string) $battlecard['company_signal_id'],
                'battlecard_brief_id' => $input['battlecard_brief_id'],
                'proposal_id' => (string) $battlecard['proposal_id'],
                // The canonical release IS the decision record. This row REFERENCES it; it does not
                // duplicate the decision, so there is one place a release can be revoked from.
                'decision_record_id' => $decisionRecordId,
                'released_at' => $this->clock->now(),
            ]);
            $tx->run(
                "UPDATE battlecard_briefs SET status = 'RELEASED' WHERE battlecard_brief_id = ?",
                [$input['battlecard_brief_id']]
            );
            $tx->run(
                "UPDATE company_signals SET status = 'RELEASED' WHERE company_signal_id = ?",
                [(string) $battlecard['company_signal_id']]
            );

            // The proposal envelope advances through M04, against the M10 record just written —
            // which is what keeps the envelope status from becoming a second, unbacked release claim.
            $marked = $this->proposals->markReleased((string) $battlecard['proposal_id'], $decisionRecordId);
            if ($marked->isRefusal()) {
                return Outcome::refuse('PROPOSAL_RELEASE_ADVANCE_FAILED', [
                    'detail' => $marked->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_briefing_id' => $releasedBriefingId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => (string) $battlecard['arbiter_verdict_id'],
            ]);
        });
    }

    /**
     * The four release-boundary card checks, each named specifically.
     *
     * `..._AT_RELEASE` in every code, because these are the SAME conditions the verdict gate
     * evaluated — and an operator reading the audit trail needs to know the refusal happened at the
     * later boundary, on state that changed after the verdict.
     *
     * @param array<string, mixed> $card
     */
    private function cardRefusalAtRelease(array $card): ?Outcome
    {
        if ((string) $card['review_status'] !== 'approved') {
            return Outcome::refuse('SOURCE_CARD_NOT_APPROVED_AT_RELEASE', [
                'review_status' => (string) $card['review_status'],
            ], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }
        if ($card['review_expires_at'] !== null
            && $this->clock->now() > (string) $card['review_expires_at']
        ) {
            return Outcome::refuse('SOURCE_CARD_REVIEW_EXPIRED_AT_RELEASE', [], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }
        if ((string) $card['license_class'] === 'PROHIBITED') {
            return Outcome::refuse('SOURCE_LICENSE_PROHIBITED_AT_RELEASE', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        // A release is an internal release, and it still requires the source to permit customer
        // output — because a released briefing is the artifact a salesperson repeats to a customer.
        if ((int) $card['customer_output_allowed'] !== 1) {
            return Outcome::refuse('SOURCE_CARD_FORBIDS_CUSTOMER_OUTPUT_AT_RELEASE', [], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }

        return null;
    }
}
