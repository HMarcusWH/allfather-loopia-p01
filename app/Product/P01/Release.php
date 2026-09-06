<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/**
 * P01 release — a verdict-admitted battlecard becomes an internally released briefing.
 *
 * M10 owns RELEASE. This module does not invent one: it re-validates what P01 knows, hands the
 * decision ledger a VERDICT IDENTITY, and then projects the result onto the P01 domain rows. It
 * does not decide whether that verdict is releasable, supply a basis reference, or advance the
 * proposal envelope — M10 does all three, in one transaction of its own.
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
        private readonly ReleaseOutput $m16,
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
        // The verdict IDENTITY is the whole handover. M10 loads the verdict, the proposal and the
        // evidence bundle itself, checks the verdict value AND its routed stage, the invalidations,
        // the outstanding conditions, the scope and the submission digest, mints the release record
        // on the basis IT read, and performs the SUBMITTED -> RELEASED flip in its own transaction.
        // Nothing here decides whether a verdict is releasable, supplies a basis reference, or
        // touches proposal status: duplicating that policy is how the two copies come to disagree.
        //
        // releaseOrResume, not the bare release path: if an earlier attempt committed the M10
        // decision and then failed in the projection below, this adopts THAT record rather than
        // minting a second release for one decision.
        $released = $this->ledger->releaseOrResume((string) $battlecard['arbiter_verdict_id']);
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

            // The proposal envelope is ALREADY RELEASED: M10 flipped it, in the same transaction
            // that wrote the decision record. There is nothing for this module to advance.

            // The M16 artifact freeze rides THIS transaction, so the released reference and its
            // frozen output commit together or not at all. A briefing with no frozen artifact would
            // be released but unrenderable — the surface reads the artifact, not the domain rows.
            $composed = $this->composeArtifact(
                $input['tenant_id'],
                $input['workspace_id'],
                $input['battlecard_brief_id'],
                $releasedBriefingId,
                $decisionRecordId,
                (string) $battlecard['arbiter_verdict_id'],
                (string) $battlecard['company_signal_id'],
            );
            if ($composed->isRefusal()) {
                return Outcome::refuse('RELEASE_ARTIFACT_ASSEMBLY_REFUSED', [
                    'detail' => $composed->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_briefing_id' => $releasedBriefingId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => (string) $battlecard['arbiter_verdict_id'],
                'release_artifact_id' => $composed->value()['release_artifact_id'],
                'customer_output_projection_id' => $composed->value()['customer_output_projection_id'],
            ]);
        });
    }

    /**
     * Freeze the released briefing into an M16 artifact and activate it for internal visibility.
     *
     * The order is M16's own: compile the source-use profile, assemble the artifact against it,
     * release it internally, project it for the internal audience, and only then activate. Each
     * step gates the next, so an artifact whose sources do not permit the audience never reaches an
     * activation — and the surface, which reads the projection, has nothing to render.
     *
     * The battlecard's authored content becomes the artifact's content elements. It travels as a
     * CLAIM plus the artifact's own uncertainty statement, because that is what a released claim IS
     * to a reader: an assertion and the limits on it, together.
     */
    private function composeArtifact(
        string $tenantId,
        string $workspaceId,
        string $battlecardBriefId,
        string $releasedBriefingId,
        string $decisionRecordId,
        string $arbiterVerdictId,
        string $companySignalId,
    ): Outcome {
        $card = $this->db->one(
            'SELECT bc.signal_summary_safe, bc.why_it_matters_safe, bc.confidence_band,
                    bc.uncertainty_summary_safe, ms.source_ref_id
             FROM battlecard_briefs bc
             JOIN company_signals cs ON cs.company_signal_id = bc.company_signal_id
             LEFT JOIN source_diffs sd ON sd.source_diff_id = cs.source_diff_id
             LEFT JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
             WHERE bc.battlecard_brief_id = ?',
            [$battlecardBriefId]
        );
        if ($card === null || $card['source_ref_id'] === null) {
            // No contributing source means no provenance to state. M16 would refuse the profile
            // anyway; refusing here names the P01-side cause rather than the M16-side symptom.
            return Outcome::refuse('ARTIFACT_SOURCE_LINEAGE_MISSING', [], 'PROVENANCE');
        }

        $profile = $this->m16->compileSourceUseProfile($tenantId, $workspaceId, [(string) $card['source_ref_id']]);
        if ($profile->isRefusal()) {
            return Outcome::refuse('SOURCE_USE_PROFILE_REFUSED', [
                'detail' => $profile->reasonCode(),
            ], 'POLICY');
        }
        if ($profile->value()['status'] !== 'COMPILED_ALLOW') {
            // A HELD profile is a compiled answer, not a failure — and the answer is no. The hold
            // reasons travel with the refusal so the operator sees which source held it.
            return Outcome::refuse('SOURCE_USE_PROFILE_HELD', [
                'hold_reasons' => $profile->value()['hold_reasons'],
            ], 'POLICY');
        }

        $artifact = $this->m16->assembleArtifact([
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'artifact_kind' => 'BATTLECARD_BRIEFING',
            'released_briefing_id' => $releasedBriefingId,
            'decision_record_id' => $decisionRecordId,
            'arbiter_verdict_id' => $arbiterVerdictId,
            'source_use_profile_id' => $profile->value()['release_source_use_profile_id'],
            'content_elements' => [
                [
                    'elementClass' => 'CLAIM',
                    'textSafe' => (string) $card['signal_summary_safe'],
                    'confidence' => (string) $card['confidence_band'],
                ],
                [
                    'elementClass' => 'CONTEXT',
                    'textSafe' => (string) $card['why_it_matters_safe'],
                ],
            ],
            // The battlecard's own uncertainty statement, carried onto the artifact. A released
            // claim whose limits were left behind in the source row is a claim without its limits.
            'uncertainty_summary_safe' => (string) $card['uncertainty_summary_safe'],
        ]);
        if ($artifact->isRefusal()) {
            return Outcome::refuse('ARTIFACT_ASSEMBLY_REFUSED', [
                'detail' => $artifact->reasonCode(),
            ], 'POLICY');
        }
        $artifactId = $artifact->value()['release_artifact_id'];

        $this->m16->releaseInternal($artifactId);
        $projection = $this->m16->projectForAudience($artifactId, 'TENANT_INTERNAL');
        if ($projection->isRefusal() || $projection->value()['status'] !== 'PROJECTED') {
            return Outcome::refuse('ARTIFACT_PROJECTION_REFUSED', [], 'POLICY');
        }
        $activation = $this->m16->activateInternalVisibility($artifactId, $tenantId, $workspaceId);
        if ($activation->isRefusal()) {
            return Outcome::refuse('ARTIFACT_ACTIVATION_REFUSED', [
                'detail' => $activation->reasonCode(),
            ], 'POLICY');
        }

        return Outcome::ok([
            'release_artifact_id' => $artifactId,
            'customer_output_projection_id' => $projection->value()['customer_output_projection_id'],
        ]);
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
