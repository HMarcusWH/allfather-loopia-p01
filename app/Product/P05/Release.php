<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P05;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P05 internal release and surfacing.
 *
 * RELEASE HERE MEANS RELEASED_INTERNAL, and the status vocabulary has no member above it. What a
 * governed decision records is that the tenant's own people may read this draft in their console.
 * Nothing in this module dispatches, sends, publishes or writes CRM state, and there is no column
 * or code path that could.
 *
 * TWO RELEASE-BOUNDARY RE-CHECKS, both over the window between the verdict and the release:
 *
 *   The cited source must STILL be relied upon. Marketing built on a retracted claim would repeat
 *   the retraction to a new audience.
 *
 *   THE DIGEST MUST STILL RECONCILE. The asset digest is recomputed from the draft's own stored
 *   fields and compared to the one frozen at composition. This is the check that catches copy
 *   edited after it was cleared — the arbiter judged specific words, and different words have not
 *   been judged. It is stronger than a content scan because it needs no opinion about what the new
 *   words say: they are simply not the ones that were admitted.
 *
 * SURFACING IS A TWO-GATE DEFAULT-DENY: M02 must grant the read permission, and the tenant's live
 * tier must include it. There is no third gate here as there is in P04, because a marketing asset
 * has no audience narrower than the workspace — it is not addressed to a role.
 */
final class Release
{
    private const REQUIRED_PERMISSION = 'marketing_asset.read';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly Proposals $proposals,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * Release an admitted draft internally.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, marketing_asset_draft_id: string,
     *     correlation_id?: ?string
     * } $input
     */
    public function releaseMarketingAsset(array $input): Outcome
    {
        $draft = $this->db->one(
            'SELECT tenant_id, workspace_id, asset_kind, released_briefing_id,
                    released_prospect_output_id, title_safe, draft_content_safe, asset_status,
                    asset_digest, arbiter_verdict_id, proposal_id
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
        // Checked BEFORE the status guard, so a second release attempt names the reason it is a
        // second one rather than reporting a status that only looks wrong.
        $alreadyReleased = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM released_marketing_assets WHERE marketing_asset_draft_id = ?',
            [$input['marketing_asset_draft_id']]
        );
        if ($alreadyReleased > 0) {
            return Outcome::refuse('ALREADY_RELEASED', [], 'POLICY');
        }
        if ((string) $draft['asset_status'] !== 'VERDICT_ISSUED' || $draft['arbiter_verdict_id'] === null) {
            return Outcome::refuse('DRAFT_NOT_VERDICT_ISSUED', [
                'asset_status' => (string) $draft['asset_status'],
            ], 'POLICY');
        }

        // --- the release-boundary re-checks ---------------------------------------------------------
        $sourceRecordId = $this->draftSourceDecisionRecord($draft);
        if ($sourceRecordId === null || !$this->ledger->isCurrentlyRelied($sourceRecordId)) {
            return Outcome::refuse('SOURCE_RELEASE_INVALIDATED_AT_RELEASE', [
                'current_reliance' => $sourceRecordId === null
                    ? null
                    : $this->ledger->currentReliance($sourceRecordId),
            ], 'PROVENANCE', 'S4');
        }
        if (self::recomputeAssetDigest($draft) !== (string) $draft['asset_digest']) {
            // The words that were admitted are not the words about to be released. No opinion about
            // the new words is needed, or offered: they have not been judged.
            return Outcome::refuse('ASSET_DIGEST_CHANGED_AT_RELEASE', [], 'INTEGRITY', 'S4', 'BLOCK_RELEASE');
        }

        // --- M10 owns the release ---------------------------------------------------------------------
        $verdictId = (string) $draft['arbiter_verdict_id'];
        $verdict = $this->db->one(
            'SELECT av.verdict, av.basis_hash, pe.evidence_bundle_id
             FROM arbiter_verdicts av
             JOIN proposal_envelopes pe ON pe.proposal_id = av.proposal_id
             WHERE av.arbiter_verdict_id = ?',
            [$verdictId]
        );
        if ($verdict === null || (string) $verdict['verdict'] !== 'ALLOW_INFORMATIONAL_RELEASE') {
            return Outcome::refuse('VERDICT_DOES_NOT_PERMIT_RELEASE', [
                'verdict' => $verdict === null ? null : (string) $verdict['verdict'],
            ], 'POLICY', 'S4');
        }

        $proposalId = (string) $draft['proposal_id'];
        $streamId = $this->ledger->ensureActiveStream($input['tenant_id'], $input['workspace_id']);
        $released = $this->ledger->appendInternalRelease(
            $streamId,
            'Released internal marketing asset draft (' . (string) $draft['asset_kind'] . ').',
            [
                [
                    'owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT',
                    'input_object_id' => $verdictId,
                    'input_object_version_or_digest' => (string) $verdict['basis_hash'],
                    'reference_role' => 'VERDICT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL',
                    'input_object_id' => $proposalId,
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
            ['proposal_id' => $proposalId],
        );
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        return $this->db->transaction(function (Database $tx) use (
            $input, $decisionRecordId, $verdictId, $proposalId
        ): Outcome {
            $now = $this->clock->now();
            $releasedId = Ids::new();
            $tx->insert('released_marketing_assets', [
                'released_marketing_asset_id' => $releasedId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'marketing_asset_draft_id' => $input['marketing_asset_draft_id'],
                // The canonical release IS the decision record; this row references it.
                'decision_record_id' => $decisionRecordId,
                'released_at' => $now,
            ]);

            // Guarded in the UPDATE itself. If anything moved the draft out of VERDICT_ISSUED
            // between the read and here, zero rows change and the whole unit rolls back rather
            // than leaving a release reference to a draft in some other state.
            $changed = $tx->run(
                "UPDATE marketing_asset_drafts SET asset_status = 'RELEASED_INTERNAL'
                 WHERE marketing_asset_draft_id = ? AND asset_status = 'VERDICT_ISSUED'",
                [$input['marketing_asset_draft_id']]
            );
            if ($changed !== 1) {
                return Outcome::refuse('DRAFT_NOT_VERDICT_ISSUED', [], 'POLICY');
            }
            $tx->insert('marketing_asset_draft_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'marketing_asset_draft_id' => $input['marketing_asset_draft_id'],
                'from_status' => 'VERDICT_ISSUED',
                'to_status' => 'RELEASED_INTERNAL',
                'reason_code' => 'M10_DECISION_RECORD_APPENDED',
                'transitioned_at' => $now,
            ]);

            $marked = $this->proposals->markReleased($proposalId, $decisionRecordId);
            if ($marked->isRefusal()) {
                return Outcome::refuse('PROPOSAL_RELEASE_ADVANCE_FAILED', [
                    'detail' => $marked->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_marketing_asset_id' => $releasedId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => $verdictId,
            ]);
        });
    }

    /**
     * The two-gate surfacing over RELEASED_INTERNAL assets.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, actor_type: string, actor_id: string,
     *     asset_kind?: ?string, correlation_id?: ?string
     * } $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, outputs: list<array<string, mixed>>}
     */
    public function listVisibleMarketingAssets(array $input): array
    {
        $access = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            self::REQUIRED_PERMISSION,
            'released_marketing_asset',
            null,
            $input['correlation_id'] ?? null,
        );
        if ($access['decision'] !== 'ALLOW') {
            return $this->denied($access['reason_codes']);
        }

        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], self::REQUIRED_PERMISSION)) {
            return $this->denied(['TIER_DOES_NOT_GRANT_PERMISSION']);
        }

        $assetKind = $input['asset_kind'] ?? null;
        if ($assetKind !== null && !in_array($assetKind, MarketingAssets::ASSET_KINDS, true)) {
            // An unrecognized kind narrows to nothing rather than being ignored. Silently dropping
            // an unreadable filter would return MORE than the caller asked for.
            return $this->denied(['UNKNOWN_ASSET_KIND']);
        }

        $sql = 'SELECT rma.released_marketing_asset_id, rma.marketing_asset_draft_id,
                       d.asset_kind, d.title_safe, d.draft_content_safe,
                       d.released_briefing_id, d.released_prospect_output_id,
                       rma.decision_record_id, rma.released_at
                FROM released_marketing_assets rma
                JOIN marketing_asset_drafts d
                  ON d.marketing_asset_draft_id = rma.marketing_asset_draft_id
                WHERE rma.tenant_id = ? AND rma.workspace_id = ?';
        $params = [$input['tenant_id'], $input['workspace_id']];
        if ($assetKind !== null) {
            $sql .= ' AND d.asset_kind = ?';
            $params[] = $assetKind;
        }
        $sql .= ' ORDER BY rma.released_at, rma.released_marketing_asset_id';

        return [
            'decision' => 'VISIBLE',
            'required_permission' => self::REQUIRED_PERMISSION,
            'reason_codes' => ['GRANTED'],
            'outputs' => $this->db->all($sql, $params),
        ];
    }

    /**
     * Recompute the asset digest from the draft's OWN stored fields.
     *
     * The projection is the composer's, exactly. Two projections that drifted apart would make
     * every release look tampered with — or, worse, make a tampered one look clean.
     *
     * @param array<string, mixed> $draft
     */
    private static function recomputeAssetDigest(array $draft): string
    {
        return Digest::canonicalDigest([
            'assetKind' => (string) $draft['asset_kind'],
            'sourceKind' => $draft['released_briefing_id'] !== null ? 'P01_BRIEFING' : 'P03_PROSPECT_OUTPUT',
            'sourceId' => $draft['released_briefing_id'] !== null
                ? (string) $draft['released_briefing_id']
                : (string) $draft['released_prospect_output_id'],
            'title' => (string) $draft['title_safe'],
            'content' => (string) $draft['draft_content_safe'],
        ]);
    }

    /**
     * @param list<string> $reasonCodes
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, outputs: list<array<string, mixed>>}
     */
    private function denied(array $reasonCodes): array
    {
        return [
            'decision' => 'DENIED',
            'required_permission' => self::REQUIRED_PERMISSION,
            'reason_codes' => $reasonCodes,
            'outputs' => [],
        ];
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
}
