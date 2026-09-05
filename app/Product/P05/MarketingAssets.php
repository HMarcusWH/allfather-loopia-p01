<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P05;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\Scope;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P05 marketing-asset composition — internal DRAFTS, and nothing else.
 *
 * P05 PERSONALIZES; IT DOES NOT PUBLISH. It turns intelligence M10 already released into internal
 * drafts a marketer can work from: landing-page variants, offer positioning, competitor-weakness
 * maps, campaign angles, copy variants. Every one is a draft.
 *
 * THERE IS NO PUBLISH PATH, AND NOT BECAUSE ONE IS GUARDED. The `asset_status` CHECK has no
 * PUBLISHED member; there is no channel column, no destination column, no scheduled-send column.
 * The strongest state a marketing asset can reach in this projection is RELEASED_INTERNAL, which
 * means a governed decision recorded that the tenant's own people may read it in the console.
 * Anything beyond that is a different product plane and is not implemented here.
 *
 * THE EVIDENCE-CITED-CLAIM INVARIANT. Copy may say what it likes about the customer's own product.
 * The moment it says something about a COMPETITOR, that claim must cite a real governed evidence
 * object in the draft's own scope — and `cited_evidence_id` is NOT NULL in storage, so an uncited
 * comparative claim cannot be written even by code that tried. The rule exists because a
 * comparative claim is the one kind of marketing copy that can defame somebody, and "the model
 * wrote it" is not a source.
 *
 * A DRAFT CITES EXACTLY ONE RELEASED SOURCE, still relied upon at composition time. Composing from
 * a withdrawn release would build marketing on a claim the organization has already retracted.
 */
final class MarketingAssets
{
    /** @var list<string> */
    public const ASSET_KINDS = [
        'LANDING_PAGE_VARIANT',
        'OFFER_POSITIONING',
        'COMPETITOR_WEAKNESS_MAP',
        'CAMPAIGN_ANGLE',
        'COPY_VARIANT',
    ];

    /** @var list<string> */
    public const SOURCE_KINDS = ['P01_BRIEFING', 'P03_PROSPECT_OUTPUT'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
    ) {
    }

    /**
     * Compose a DRAFT marketing asset over an M10-recorded release.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, asset_kind: string,
     *     source_kind: string, source_id: string, title_safe: string, draft_content_safe: string
     * } $input
     */
    public function compose(array $input): Outcome
    {
        if (!in_array($input['asset_kind'], self::ASSET_KINDS, true)) {
            return Outcome::refuse('UNKNOWN_ASSET_KIND', [
                'asset_kind' => $input['asset_kind'],
            ], 'POLICY');
        }
        // Validated explicitly rather than left to the else-branch. An unrecognized source kind
        // that fell through to the P03 lookup would trip the exactly-one-FK CHECK as a thrown
        // error instead of a named refusal — the right outcome reached the wrong way.
        if (!in_array($input['source_kind'], self::SOURCE_KINDS, true)) {
            return Outcome::refuse('UNKNOWN_SOURCE_KIND', [
                'source_kind' => $input['source_kind'],
            ], 'PROVENANCE');
        }

        $decisionRecordId = $this->sourceDecisionRecord(
            $input['source_kind'],
            $input['source_id'],
            $input['tenant_id'],
            $input['workspace_id'],
        );
        if ($decisionRecordId === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied($decisionRecordId)) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance($decisionRecordId),
            ], 'PROVENANCE', 'S4');
        }

        // The digest covers what the draft SAYS and what it says it about. A later edit changes it,
        // which is what makes the release-time comparison meaningful.
        $assetDigest = Digest::canonicalDigest([
            'assetKind' => $input['asset_kind'],
            'sourceKind' => $input['source_kind'],
            'sourceId' => $input['source_id'],
            'title' => $input['title_safe'],
            'content' => $input['draft_content_safe'],
        ]);

        $draftId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($input, $draftId, $assetDigest, $now): Outcome {
            $tx->insert('marketing_asset_drafts', [
                'marketing_asset_draft_id' => $draftId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'asset_kind' => $input['asset_kind'],
                // Exactly one, and the storage CHECK says so independently of this code.
                'released_briefing_id' => $input['source_kind'] === 'P01_BRIEFING' ? $input['source_id'] : null,
                'released_prospect_output_id' => $input['source_kind'] === 'P03_PROSPECT_OUTPUT' ? $input['source_id'] : null,
                'title_safe' => $input['title_safe'],
                'draft_content_safe' => $input['draft_content_safe'],
                'asset_status' => 'DRAFT',
                'asset_digest' => $assetDigest,
                'created_at' => $now,
            ]);
            $tx->insert('marketing_asset_draft_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'marketing_asset_draft_id' => $draftId,
                'from_status' => null,
                'to_status' => 'DRAFT',
                'reason_code' => 'COMPOSED_FROM_RELEASED_SOURCE',
                'transitioned_at' => $now,
            ]);

            return Outcome::ok([
                'marketing_asset_draft_id' => $draftId,
                'asset_digest' => $assetDigest,
            ]);
        });
    }

    /**
     * Attach a claim citation to a DRAFT.
     *
     * THE CITED EVIDENCE MUST BE IN THE DRAFT'S OWN SCOPE — same tenant AND same workspace. A
     * cross-workspace citation, even inside one tenant, is refused as not-found rather than as a
     * scope error, because from this draft's position that evidence does not exist.
     *
     * Only a DRAFT accepts claims. Once an asset is in review its content and its citations are
     * what the reviewer is looking at, and adding to them underneath would make the review about
     * something else.
     *
     * @param array{
     *     tenant_id: string, marketing_asset_draft_id: string,
     *     claim_text_safe: string, cited_evidence_id: string
     * } $input
     */
    public function addClaimCitation(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_status FROM marketing_asset_drafts
             WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $draft['tenant_id'] !== $input['tenant_id']) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $draft['asset_status'] !== 'DRAFT') {
            return Outcome::refuse('DRAFT_NOT_EDITABLE', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }

        $evidence = $this->db->one(
            'SELECT tenant_id, workspace_id FROM evidence_objects WHERE evidence_id = ?',
            [$input['cited_evidence_id']]
        );
        if ($evidence === null
            || (string) $evidence['tenant_id'] !== $input['tenant_id']
            || (string) $evidence['workspace_id'] !== (string) $draft['workspace_id']
        ) {
            return Outcome::refuse('CITED_EVIDENCE_NOT_FOUND', [], 'EVIDENCE', 'S4');
        }

        $citationId = Ids::new();
        $this->db->insert('marketing_claim_citations', [
            'marketing_claim_citation_id' => $citationId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'marketing_asset_draft_id' => $input['marketing_asset_draft_id'],
            'claim_text_safe' => $input['claim_text_safe'],
            'cited_evidence_id' => $input['cited_evidence_id'],
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['marketing_claim_citation_id' => $citationId]);
    }

    /**
     * Mint a CLAIM BASIS: governed evidence a comparative claim can properly cite.
     *
     * WHY THIS EXISTS. The citation gate accepts any real in-scope evidence, and the M11 citation
     * audit is stricter — it asks whether the cited evidence has LINEAGE to this draft, and counts
     * only the draft's own admission basis or a purpose-built claim basis. Without a way to mint
     * the latter, a marketer citing a P01 signal would pass the gate and then fail the audit
     * forever, with nothing they could do about it. The two would be describing different rules
     * and only the audit would be right.
     *
     * So the basis is minted through the certified M05 path, under the source-location and
     * use-scope convention the audit recognizes. It adds no authority: it is an INTERNAL_DEMO
     * derived reference that records WHICH released source a claim rests on, and the claim still
     * has to be cited, and the audit still has to accept it.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     claim_tag: string, basis_summary_safe: string, mission_id?: ?string
     * } $input
     */
    public function captureClaimBasis(array $input, EvidenceSpine $evidence): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_status, released_briefing_id,
                    released_prospect_output_id
             FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!Scope::isCompatible(
            (string) $draft['tenant_id'],
            (string) $draft['workspace_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            tenantWideObjectPermitted: false,
        )) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $draft['asset_status'] !== 'DRAFT') {
            return Outcome::refuse('DRAFT_NOT_EDITABLE', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }

        // The basis records which RELEASED source the claim rests on. A basis over a withdrawn
        // release would be evidence for a claim the organization has already retracted.
        $decisionRecordId = $draft['released_briefing_id'] !== null
            ? $this->db->scalar(
                'SELECT decision_record_id FROM released_briefings WHERE released_briefing_id = ?',
                [(string) $draft['released_briefing_id']]
            )
            : $this->db->scalar(
                'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
                [(string) $draft['released_prospect_output_id']]
            );
        if ($decisionRecordId === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied((string) $decisionRecordId)) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance((string) $decisionRecordId),
            ], 'PROVENANCE', 'S4');
        }

        $sourceRefId = $evidence->registerSource(
            $input['tenant_id'],
            $input['workspace_id'],
            'PROJECT_CANONICAL_OBJECT',
            // The convention the audit reads. Both halves matter: the draft id ties the basis to
            // THIS draft, and the tag distinguishes one claim's basis from another's.
            'marketing-claim-basis:' . $input['marketing_asset_draft_id'] . ':' . $input['claim_tag'],
            'marketing_claim_basis',
            'INTERNAL_DEMO',
            'ACTIVE_FOR_CAPTURE',
        );

        $captured = $evidence->capture([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'mission_id' => $input['mission_id'] ?? null,
            'source_ref_id' => $sourceRefId,
            'evidence_type' => 'DERIVED_ANNOTATION_REFERENCE',
            'captured_content_reference_safe' => $input['basis_summary_safe'],
            'content_hash' => Digest::canonicalDigest([
                'marketingAssetDraftId' => $input['marketing_asset_draft_id'],
                'claimTag' => $input['claim_tag'],
                'decisionRecordId' => (string) $decisionRecordId,
            ]),
            'capture_method' => 'MARKETING_CLAIM_BASIS_DERIVATION',
            'permitted_use_scope' => 'marketing_claim_basis',
            'sensitivity_class' => 'INTERNAL_DEMO',
        ]);
        if ($captured->isRefusal()) {
            return Outcome::refuse('CLAIM_BASIS_REJECTED', [
                'detail' => $captured->reasonCode(),
            ], 'EVIDENCE');
        }

        return Outcome::ok([
            'evidence_id' => $captured->value()['evidence_id'],
            'source_ref_id' => $sourceRefId,
            'decision_record_id' => (string) $decisionRecordId,
        ]);
    }

    /**
     * Add a copy variant to a DRAFT.
     *
     * A variant is an alternative wording of a draft, and it is a draft too — it carries no
     * channel column, no destination, and no state a publish could read. The workspace check is
     * the same isolation the citation path enforces: a variant added from outside the draft's
     * workspace would be content that workspace never wrote.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     variant_key: string, variant_content_safe: string
     * } $input
     */
    public function addVariant(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_status FROM marketing_asset_drafts
             WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($draft === null) {
            return Outcome::refuse('DRAFT_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!Scope::isCompatible(
            (string) $draft['tenant_id'],
            (string) $draft['workspace_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            tenantWideObjectPermitted: false,
        )) {
            return Outcome::refuse('DRAFT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $draft['asset_status'] !== 'DRAFT') {
            return Outcome::refuse('DRAFT_NOT_EDITABLE', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }

        $variantId = Ids::new();
        $this->db->insert('marketing_asset_variants', [
            'marketing_asset_variant_id' => $variantId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'marketing_asset_draft_id' => $input['marketing_asset_draft_id'],
            'variant_key' => $input['variant_key'],
            'variant_content_safe' => $input['variant_content_safe'],
            'variant_status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['marketing_asset_variant_id' => $variantId]);
    }

    /** The M10 record behind a cited source, resolved IN SCOPE. */
    private function sourceDecisionRecord(
        string $sourceKind,
        string $sourceId,
        string $tenantId,
        string $workspaceId,
    ): ?string {
        $row = $sourceKind === 'P01_BRIEFING'
            ? $this->db->one(
                'SELECT decision_record_id FROM released_briefings
                 WHERE released_briefing_id = ? AND tenant_id = ? AND workspace_id = ?',
                [$sourceId, $tenantId, $workspaceId]
            )
            : $this->db->one(
                'SELECT decision_record_id FROM released_prospect_outputs
                 WHERE released_prospect_output_id = ? AND tenant_id = ? AND workspace_id = ?',
                [$sourceId, $tenantId, $workspaceId]
            );

        return $row === null ? null : (string) $row['decision_record_id'];
    }
}
