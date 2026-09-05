<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;

/**
 * P03 fit hypotheses — pairing one thing the customer sells with one thing a competitor did.
 *
 * THE ONE CLAIM THIS MODULE MAKES IS "THERE IS A REASON TO INVESTIGATE".
 *
 * `claim_kind` is PINNED to `REASON_TO_INVESTIGATE` and `uncertainty_explicit` is PINNED to true —
 * neither is a parameter, because a caller able to choose either could author a stronger claim than
 * the evidence supports. A fit hypothesis says a customer's product and a competitor's observed
 * change share vocabulary. It does not say the prospect wants to buy, is in market, or has any
 * state of mind at all.
 *
 * That is the buyer-intent invariant, and it is held in FOUR independent places:
 *
 *   1. HERE, by the pinned claim kind — this module cannot express buyer intent.
 *   2. In the arbiter, where a queue admission asserting a timely buying signal must cite a
 *      TRIGGER-class signal that was directly OBSERVED, or it blocks as an overclaim.
 *   3. In the storage vocabulary, whose `claim_kind` CHECK admits no member meaning "intends to
 *      purchase".
 *   4. In the M11 buyer-intent audits, which look for the overclaim after the fact.
 *
 * A hypothesis rests on a RELEASED signal, never a draft one. Deriving from an unreviewed
 * observation would let a lead be built on something the review plane had not yet admitted.
 */
final class FitHypotheses
{
    /**
     * The ONLY claim kind this module writes. Not a parameter, and deliberately so: an authoring
     * path that could choose `FIT_HYPOTHESIS` versus something stronger would be an authoring path
     * that could overclaim.
     */
    private const PINNED_CLAIM_KIND = 'REASON_TO_INVESTIGATE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceSpine $evidence,
        private readonly Icp $icp,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, customer_product_id: string,
     *     company_signal_id: string, mission_id?: ?string
     * } $input
     */
    public function pair(array $input): Outcome
    {
        $product = $this->db->one(
            'SELECT p.tenant_id, p.product_status, p.attributes_json,
                    cat.catalogue_status, cat.source_ref_id, cat.workspace_id AS catalogue_workspace_id,
                    sr.lawful_or_permitted_use_scope
             FROM customer_products p
             JOIN customer_product_catalogues cat
               ON cat.customer_product_catalogue_id = p.customer_product_catalogue_id
             JOIN source_references sr ON sr.source_ref_id = cat.source_ref_id
             WHERE p.customer_product_id = ?',
            [$input['customer_product_id']]
        );
        if ($product === null) {
            return Outcome::refuse('CUSTOMER_PRODUCT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $product['tenant_id'] !== $input['tenant_id']) {
            return Outcome::refuse('CUSTOMER_PRODUCT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // A catalogue is workspace-scoped OR tenant-wide. A workspace-scoped one belongs to its own
        // workspace only; a tenant-wide one (null) is readable across the tenant's workspaces.
        if ($product['catalogue_workspace_id'] !== null
            && (string) $product['catalogue_workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('CUSTOMER_PRODUCT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Catalogue status is read HERE, at pairing time. Suspending a catalogue therefore stops
        // new hypotheses immediately — which is what makes it a control rather than a label.
        if ((string) $product['catalogue_status'] !== 'ACTIVE') {
            return Outcome::refuse('CATALOGUE_NOT_ACTIVE', [
                'catalogue_status' => (string) $product['catalogue_status'],
            ], 'POLICY');
        }
        if ((string) $product['product_status'] !== 'ACTIVE') {
            return Outcome::refuse('PRODUCT_OFFER_NOT_ACTIVE', [
                'product_status' => (string) $product['product_status'],
            ], 'POLICY');
        }

        $signal = $this->db->one(
            'SELECT tenant_id, workspace_id, signal_class, summary_safe, status
             FROM company_signals WHERE company_signal_id = ?',
            [$input['company_signal_id']]
        );
        if ($signal === null) {
            return Outcome::refuse('COMPANY_SIGNAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $signal['tenant_id'] !== $input['tenant_id']
            || (string) $signal['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('COMPANY_SIGNAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Only a RELEASED signal. Deriving a lead from a draft or under-review observation would
        // build it on something the review plane had not yet admitted.
        if ((string) $signal['status'] !== 'RELEASED') {
            return Outcome::refuse('SIGNAL_NOT_RELEASED', [
                'status' => (string) $signal['status'],
            ], 'POLICY');
        }

        $attributes = json_decode((string) $product['attributes_json'], true, 512, JSON_THROW_ON_ERROR);
        $score = FitScoring::scoreFit(
            FitScoring::fitTerms(...$attributes),
            FitScoring::fitTerms((string) $signal['summary_safe'], (string) $signal['signal_class']),
        );
        if ($score['kind'] === 'INSUFFICIENT') {
            // Nothing to compare is not a weak match. Reporting it as UNFIT would assert a negative
            // finding nobody established.
            return Outcome::refuse('INSUFFICIENT_FIT_EVIDENCE', [], 'EVIDENCE');
        }
        if ($score['band'] === 'UNFIT') {
            return Outcome::refuse('OUTSIDE_CUSTOMER_INTEREST', [], 'POLICY');
        }

        // The ICP component is SEPARATE from the fit band and pinned to a profile VERSION. Folding
        // them into one number would make "why was this surfaced?" unanswerable — a single score
        // cannot say whether the match came from the product, the profile, or an exclusion.
        $profileVersion = $this->icp->activeProfileVersion($input['tenant_id'], $input['workspace_id']);
        $icpFit = $profileVersion === null ? null : FitScoring::scoreIcpFit($profileVersion, [
            'terms' => FitScoring::icpTerms(
                ...$attributes, ...[(string) $signal['summary_safe'], (string) $signal['signal_class']]
            ),
        ]);

        // The digest is the stable identity of THIS assessment. It binds the pair, the band, and
        // the pinned claim shape — so a second derivation of the same assessment recognizes itself
        // rather than accumulating near-duplicate leads.
        $fitAssessmentDigest = Digest::canonicalDigest([
            'customerProductId' => $input['customer_product_id'],
            'companySignalId' => $input['company_signal_id'],
            'fitBand' => $score['band'],
            'claimKind' => self::PINNED_CLAIM_KIND,
            'uncertaintyExplicit' => true,
            ...($icpFit === null ? [] : ['icp' => [
                'icpProfileVersionId' => $icpFit['icp_profile_version_id'],
                'scorerVersion' => $icpFit['scorer_version'],
                'score' => $icpFit['score'],
                'matchedDimensions' => $icpFit['matched_dimensions'],
            ]]),
        ]);

        $existing = $this->db->one(
            "SELECT fit_hypothesis_id, fit_band FROM fit_hypotheses
             WHERE fit_assessment_digest = ? AND status NOT IN ('REJECTED', 'SUPERSEDED')",
            [$fitAssessmentDigest]
        );
        if ($existing !== null) {
            return Outcome::ok([
                'outcome' => 'ALREADY_DERIVED',
                'fit_hypothesis_id' => (string) $existing['fit_hypothesis_id'],
                'fit_band' => (string) $existing['fit_band'],
            ]);
        }

        return $this->db->transaction(function (Database $tx) use (
            $input, $product, $score, $icpFit, $fitAssessmentDigest
        ): Outcome {
            // The derivation itself becomes M05 evidence. A lead whose basis was not recorded as
            // evidence would be a conclusion with no citable derivation behind it.
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $input['mission_id'] ?? null,
                'source_ref_id' => (string) $product['source_ref_id'],
                'evidence_type' => 'FIT_HYPOTHESIS_BASIS_REFERENCE',
                'captured_content_reference_safe' => sprintf(
                    'fit-basis://%s/%s/%s',
                    $input['customer_product_id'],
                    $input['company_signal_id'],
                    substr($fitAssessmentDigest, 0, 16),
                ),
                'content_hash' => $fitAssessmentDigest,
                'capture_method' => 'FIT_MATCH_DERIVATION',
                'permitted_use_scope' => $product['lawful_or_permitted_use_scope']
                    ?? 'customer_owned_tenant_scoped/internal_fit_matching',
                'sensitivity_class' => 'INTERNAL_DEMO',
            ]);
            if ($captured->isRefusal()) {
                return Outcome::refuse('FIT_BASIS_EVIDENCE_REJECTED', [
                    'detail' => $captured->reasonCode(),
                ], 'EVIDENCE');
            }

            $fitHypothesisId = Ids::new();
            $tx->insert('fit_hypotheses', [
                'fit_hypothesis_id' => $fitHypothesisId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'customer_product_id' => $input['customer_product_id'],
                'company_signal_id' => $input['company_signal_id'],
                'fit_band' => $score['band'],
                // PINNED. The strongest thing this row can say is that somebody should look.
                'claim_kind' => self::PINNED_CLAIM_KIND,
                'uncertainty_explicit' => 1,
                'fit_assessment_digest' => $fitAssessmentDigest,
                'icp_profile_version_id' => $icpFit['icp_profile_version_id'] ?? null,
                'icp_fit_scorer_version' => $icpFit['scorer_version'] ?? null,
                'icp_fit_score' => $icpFit['score'] ?? null,
                'icp_fit_reasons_json' => $icpFit === null
                    ? null
                    : json_encode($icpFit['matched_dimensions'], JSON_THROW_ON_ERROR),
                'basis_evidence_id' => $captured->value()['evidence_id'],
                'proposal_id' => null,
                'arbiter_verdict_id' => null,
                'status' => 'DRAFT',
                'created_at' => $this->clock->now(),
            ]);

            return Outcome::ok([
                'outcome' => 'DERIVED',
                'fit_hypothesis_id' => $fitHypothesisId,
                'basis_evidence_id' => $captured->value()['evidence_id'],
                'fit_band' => $score['band'],
                'claim_kind' => self::PINNED_CLAIM_KIND,
                'fit_assessment_digest' => $fitAssessmentDigest,
                'icp_fit' => $icpFit,
            ]);
        });
    }
}
