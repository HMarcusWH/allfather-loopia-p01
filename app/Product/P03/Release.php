<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P03 release and surfacing — where a qualified prospect becomes released internal intelligence.
 *
 * This is the end of the P03 vertical the product concept actually promises: catalogue → ICP → fit
 * hypothesis → qualification → priority queue → **released prospect output**. Without it the port
 * stopped at an admitted queue item that nothing could ever show anyone.
 *
 * P03 DOES NOT ROUTE THROUGH M16, and that is canonical rather than an omission. Upstream's M16
 * adoption covers the P01 briefing kinds and the P02 snapshot views; a released prospect output is
 * a REFERENCE to the M10 decision record, projected into `released_prospect_outputs` and read back
 * through this module's own two-gate read model. Forcing it through M16 to make one release service
 * out of three would be re-deciding an adoption boundary the owner gate set, and it would change
 * what the artifact IS — so the port carries the canonical shape.
 *
 * M10 STILL OWNS RELEASE. Nothing here mints a release, decides whether a verdict is releasable,
 * or advances the proposal envelope; `DecisionLedger::appendInternalReleaseDecisionRecord` is the
 * one path that can, and this module references what it wrote.
 *
 * THE SOURCE CARD IS RE-READ AT THE RELEASE BOUNDARY, resolved along the prospect → fit hypothesis
 * → customer product → catalogue → card lineage. The arbiter gated on it at verdict time; the gap
 * between a verdict and a release is real time, in which a card can be unapproved, expire, or stop
 * being tenant-owned. A hypothesis with no card lineage is not silently exempted — there is simply
 * nothing to re-read, and the verdict-time gate stands alone.
 *
 * A FIT IS NOT AN INTENT, here as everywhere in P03. This module releases what the queue admitted
 * and adds no claim of its own; the `claim_kind` it surfaces is the hypothesis's, unmodified.
 */
final class Release
{
    /**
     * The two output kinds this module releases.
     *
     * `PROSPECT_BATTLECARD_BRIEF` is a third member of the storage vocabulary and is NOT released
     * here: upstream's release path carries the column as NULL and mints no brief, so the port
     * mints none either. A kind the code cannot produce is not a kind this module offers.
     *
     * @var list<string>
     */
    public const OUTPUT_KINDS = ['MONITORED_PROSPECT', 'PRIORITY_OUTREACH_QUEUE_ITEM'];

    /**
     * The read permission each surfaced kind requires — in BOTH M02 and the tenant's tier.
     *
     * @var array<string, string>
     */
    private const REQUIRED_PERMISSION = [
        'MONITORED_PROSPECT' => 'prospect.read',
        'PRIORITY_OUTREACH_QUEUE_ITEM' => 'priority_outreach_queue.read',
    ];

    /** Which table and identity column each kind lives in. */
    private const SOURCE_TABLE = [
        'MONITORED_PROSPECT' => ['monitored_prospects', 'monitored_prospect_id'],
        'PRIORITY_OUTREACH_QUEUE_ITEM' => ['priority_outreach_queue_items', 'priority_outreach_queue_item_id'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * Release one admitted prospect output into the M10 ledger and project the reference.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, output_kind: string, output_id: string,
     *     correlation_id?: ?string
     * } $input
     */
    public function releaseProspectOutput(array $input): Outcome
    {
        $kind = $input['output_kind'];
        if (!in_array($kind, self::OUTPUT_KINDS, true)) {
            // A kind outside the closed set is refused by name rather than reaching a CHECK as a
            // driver error — and `PROSPECT_BATTLECARD_BRIEF` lands here deliberately.
            return Outcome::refuse('OUTPUT_KIND_NOT_RELEASABLE', ['output_kind' => $kind], 'POLICY');
        }
        [$table, $idColumn] = self::SOURCE_TABLE[$kind];

        $row = $this->db->one(
            "SELECT tenant_id, workspace_id, arbiter_verdict_id, fit_hypothesis_id
             FROM {$table} WHERE {$idColumn} = ?",
            [$input['output_id']]
        );
        if ($row === null) {
            return Outcome::refuse('OUTPUT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $row['tenant_id'] !== $input['tenant_id']
            || (string) $row['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('OUTPUT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ($row['arbiter_verdict_id'] === null) {
            return Outcome::refuse('OUTPUT_MISSING_VERDICT', [], 'PROVENANCE', 'S4');
        }

        // Defence in depth: a release reference must not already exist for this output.
        $alreadyReleased = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM released_prospect_outputs WHERE {$idColumn} = ?",
            [$input['output_id']]
        );
        if ($alreadyReleased > 0) {
            return Outcome::refuse('ALREADY_RELEASED', [], 'POLICY');
        }

        // --- the release-boundary source-card re-check --------------------------------------------
        $card = $this->db->one(
            'SELECT slc.review_status, slc.review_expires_at, slc.data_ownership
             FROM fit_hypotheses fh
             JOIN customer_products cp ON cp.customer_product_id = fh.customer_product_id
             JOIN customer_product_catalogues cpc
               ON cpc.customer_product_catalogue_id = cp.customer_product_catalogue_id
             JOIN source_license_cards slc ON slc.source_license_card_id = cpc.source_license_card_id
             WHERE fh.fit_hypothesis_id = ?',
            [(string) $row['fit_hypothesis_id']]
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
        $released = $this->ledger->releaseOrResume((string) $row['arbiter_verdict_id']);
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        // --- the P03 projection --------------------------------------------------------------------
        // One transaction, so the reference and the proposal advance commit together.
        return $this->db->transaction(function (Database $tx) use (
            $input, $kind, $idColumn, $row, $decisionRecordId
        ): Outcome {
            $releasedId = Ids::new();
            $tx->insert('released_prospect_outputs', [
                'released_prospect_output_id' => $releasedId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'output_kind' => $kind,
                // Exactly ONE of the three reference columns is set — the storage CHECK enforces it,
                // and the shape is what makes a released row unambiguous about what it released.
                'monitored_prospect_id' => $idColumn === 'monitored_prospect_id' ? $input['output_id'] : null,
                'priority_outreach_queue_item_id' => $idColumn === 'priority_outreach_queue_item_id'
                    ? $input['output_id']
                    : null,
                // Upstream's release path carries this NULL and mints no brief. So does this one.
                'prospect_battlecard_brief_id' => null,
                'decision_record_id' => $decisionRecordId,
                'released_at' => $this->clock->now(),
            ]);

            // The proposal envelope is ALREADY RELEASED: M10 flipped it, in the same transaction
            // that wrote the decision record. There is nothing for this module to advance.

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_prospect_output_id' => $releasedId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => (string) $row['arbiter_verdict_id'],
            ]);
        });
    }

    /**
     * Surface RELEASED prospect outputs under a TWO-gate default-deny.
     *
     * Visible only when BOTH M02 grants the read permission (role → permission) AND the tenant's
     * ACTIVE subscription tier includes it. Either gate denying makes the whole list invisible —
     * never a partial leak, because a partial answer to "may I see this" is a yes.
     *
     * A role grant is not a purchase, and the two gates are deliberately separate authorities.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, actor_type: string, actor_id: string,
     *     output_kind?: string, correlation_id?: ?string
     * } $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, rows: list<array<string, mixed>>}
     */
    public function listVisibleProspectOutputs(array $input): array
    {
        $kind = $input['output_kind'] ?? 'PRIORITY_OUTREACH_QUEUE_ITEM';
        if (!in_array($kind, self::OUTPUT_KINDS, true)) {
            return [
                'decision' => 'DENIED',
                'required_permission' => '',
                'reason_codes' => ['OUTPUT_KIND_NOT_SURFACEABLE'],
                'rows' => [],
            ];
        }
        $requiredPermission = self::REQUIRED_PERMISSION[$kind];

        // Gate 1 — M02 server-side authority.
        $decision = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            $requiredPermission,
            'released_prospect_output',
        );
        if ($decision['decision'] !== 'ALLOW') {
            return [
                'decision' => 'DENIED',
                'required_permission' => $requiredPermission,
                'reason_codes' => $decision['reason_codes'],
                'rows' => [],
            ];
        }

        // Gate 2 — the tenant's ACTIVE tier must grant it too. Default-deny.
        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], $requiredPermission)) {
            return [
                'decision' => 'DENIED',
                'required_permission' => $requiredPermission,
                'reason_codes' => ['TIER_DOES_NOT_GRANT_PERMISSION'],
                'rows' => [],
            ];
        }

        // The fit band and claim kind come from the prospect's own hypothesis — a safe derived
        // label, never raw catalogue content, and never anything pre-release.
        $join = $kind === 'PRIORITY_OUTREACH_QUEUE_ITEM'
            ? 'LEFT JOIN priority_outreach_queue_items src
                 ON src.priority_outreach_queue_item_id = rpo.priority_outreach_queue_item_id'
            : 'LEFT JOIN monitored_prospects src
                 ON src.monitored_prospect_id = rpo.monitored_prospect_id';

        $rows = $this->db->all(
            "SELECT rpo.released_prospect_output_id, rpo.output_kind, rpo.monitored_prospect_id,
                    rpo.priority_outreach_queue_item_id, rpo.decision_record_id, rpo.released_at,
                    fh.fit_band, fh.claim_kind
             FROM released_prospect_outputs rpo
             {$join}
             LEFT JOIN fit_hypotheses fh ON fh.fit_hypothesis_id = src.fit_hypothesis_id
             WHERE rpo.tenant_id = ? AND rpo.workspace_id = ? AND rpo.output_kind = ?
             ORDER BY rpo.released_at, rpo.released_prospect_output_id",
            [$input['tenant_id'], $input['workspace_id'], $kind]
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'rows' => $rows,
        ];
    }

    /**
     * One released prospect output, in detail (P09): the hypothesis behind it (a fit band and a
     * REASON TO INVESTIGATE, never buyer intent), the triggering signal while it is still a released
     * signal, the product it pairs, whether the release is CURRENTLY relied on, and the latest P03
     * post-outcome audit findings about it. Same two gates as the list, for the output's own kind;
     * the object must be in the caller's tenant AND workspace, and a foreign or unknown id is
     * NOT_FOUND. A battlecard brief is shown only if one was RELEASED — the canonical release path
     * mints none, so none is shown, rather than surfacing pre-release draft text.
     *
     * @param array{tenant_id: string, workspace_id: string, actor_type: string, actor_id: string, released_prospect_output_id: string} $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, detail: ?array<string, mixed>}
     */
    public function visibleProspectOutputDetail(array $input): array
    {
        $kind = $this->db->scalar(
            'SELECT output_kind FROM released_prospect_outputs WHERE released_prospect_output_id = ? AND tenant_id = ? AND workspace_id = ?',
            [$input['released_prospect_output_id'], $input['tenant_id'], $input['workspace_id']]
        );
        // The permission depends on the kind, but asking for the kind must not reveal that a foreign
        // object exists: an unknown or out-of-scope id is evaluated against the strictest read.
        $requiredPermission = self::REQUIRED_PERMISSION[(string) $kind] ?? 'priority_outreach_queue.read';
        $decision = $this->access->evaluate(
            $input['actor_type'], $input['actor_id'], $input['tenant_id'], $input['workspace_id'],
            $requiredPermission, 'released_prospect_output', $input['released_prospect_output_id'],
        );
        if ($decision['decision'] !== 'ALLOW') {
            return ['decision' => 'DENIED', 'required_permission' => $requiredPermission, 'reason_codes' => $decision['reason_codes'], 'detail' => null];
        }
        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], $requiredPermission)) {
            return ['decision' => 'DENIED', 'required_permission' => $requiredPermission, 'reason_codes' => ['TIER_DOES_NOT_GRANT_PERMISSION'], 'detail' => null];
        }
        if ($kind === null || !in_array((string) $kind, self::OUTPUT_KINDS, true)) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => $requiredPermission, 'reason_codes' => ['RELEASED_PROSPECT_OUTPUT_NOT_FOUND'], 'detail' => null];
        }
        [$table, $idColumn] = self::SOURCE_TABLE[(string) $kind];
        $row = $this->db->one(
            "SELECT rpo.released_prospect_output_id, rpo.output_kind, rpo.decision_record_id, rpo.released_at,
                    rpo.prospect_battlecard_brief_id, src.{$idColumn} AS source_id, src.fit_hypothesis_id,
                    fh.fit_band, fh.claim_kind, fh.uncertainty_explicit, fh.icp_fit_score, fh.icp_fit_reasons_json,
                    fh.status AS hypothesis_status, fh.company_signal_id,
                    cs.signal_class, cs.interpretation_kind, cs.confidence_band, cs.summary_safe AS signal_summary_safe,
                    cs.status AS signal_status, cs.created_at AS signal_created_at,
                    cp.display_name AS product_display_name, cp.product_status
             FROM released_prospect_outputs rpo
             JOIN {$table} src ON src.{$idColumn} = rpo.{$idColumn} AND src.tenant_id = rpo.tenant_id AND src.workspace_id = rpo.workspace_id
             JOIN fit_hypotheses fh ON fh.fit_hypothesis_id = src.fit_hypothesis_id AND fh.tenant_id = rpo.tenant_id AND fh.workspace_id = rpo.workspace_id
             LEFT JOIN company_signals cs ON cs.company_signal_id = fh.company_signal_id AND cs.tenant_id = rpo.tenant_id AND cs.workspace_id = rpo.workspace_id
             LEFT JOIN customer_products cp ON cp.customer_product_id = fh.customer_product_id AND cp.tenant_id = rpo.tenant_id
             WHERE rpo.released_prospect_output_id = ? AND rpo.tenant_id = ? AND rpo.workspace_id = ?",
            [$input['released_prospect_output_id'], $input['tenant_id'], $input['workspace_id']]
        );
        if ($row === null) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => $requiredPermission, 'reason_codes' => ['RELEASED_PROSPECT_OUTPUT_NOT_FOUND'], 'detail' => null];
        }
        $signalReleased = ($row['signal_status'] ?? null) === 'RELEASED';
        // The latest P03 post-outcome audit finding about this hypothesis or queue item, if any (P08).
        $audits = $this->db->all(
            "SELECT r.audit_category, r.completed_at, f.finding_type, f.severity, f.reason_codes
             FROM audit_runs r JOIN audit_findings f ON f.audit_run_id = r.audit_run_id
             WHERE r.tenant_id = ? AND r.workspace_id = ?
               AND r.audit_category IN ('SIGNAL_PAIRING_FRESHNESS', 'BUYER_INTENT_INVARIANT')
               AND (JSON_CONTAINS(r.target_object_refs, JSON_QUOTE(?)) OR JSON_CONTAINS(r.target_object_refs, JSON_QUOTE(?))
                    OR (r.audit_category = 'BUYER_INTENT_INVARIANT' AND r.target_object_refs = '[]'))
             ORDER BY r.requested_at DESC, r.audit_run_id DESC LIMIT 6",
            [$input['tenant_id'], $input['workspace_id'], (string) $row['fit_hypothesis_id'], (string) $row['source_id']]
        );
        $latest = [];
        foreach ($audits as $audit) {
            $latest[$audit['audit_category']] ??= [
                'audit_category' => $audit['audit_category'], 'completed_at' => $audit['completed_at'], 'finding_type' => $audit['finding_type'],
                'severity' => $audit['severity'], 'reason_codes' => json_decode((string) $audit['reason_codes'], true),
            ];
        }
        $reliance = $this->ledger->currentReliance((string) $row['decision_record_id']);

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'detail' => [
                'output' => ['released_prospect_output_id' => $row['released_prospect_output_id'], 'output_kind' => $row['output_kind'], 'decision_record_id' => $row['decision_record_id'], 'released_at' => $row['released_at']],
                'hypothesis' => [
                    'fit_band' => $row['fit_band'], 'claim_kind' => $row['claim_kind'], 'uncertainty_explicit' => (int) $row['uncertainty_explicit'] === 1,
                    'icp_fit_score' => $row['icp_fit_score'] === null ? null : (int) $row['icp_fit_score'],
                    'icp_fit_reasons' => $row['icp_fit_reasons_json'] === null ? [] : json_decode((string) $row['icp_fit_reasons_json'], true),
                    'status' => $row['hypothesis_status'],
                ],
                // The signal's text is shown only while the signal is still released; otherwise the
                // reader is told it is no longer released, not shown what it used to say.
                'signal' => $signalReleased
                    ? ['signal_class' => $row['signal_class'], 'interpretation_kind' => $row['interpretation_kind'], 'confidence_band' => $row['confidence_band'], 'summary_safe' => $row['signal_summary_safe'], 'observed_at' => $row['signal_created_at'], 'status' => 'RELEASED']
                    : ['status' => $row['signal_status'] === null ? 'UNAVAILABLE' : 'NO_LONGER_RELEASED'],
                'product' => ['display_name' => $row['product_display_name'], 'product_status' => $row['product_status']],
                'battlecard' => $row['prospect_battlecard_brief_id'] === null ? null : ['prospect_battlecard_brief_id' => $row['prospect_battlecard_brief_id']],
                'assurance' => array_values($latest),
                'current_reliance' => $reliance,
                'currently_relied_on' => $reliance === \Allfather\Loopia\Substrate\M10\Vocabulary::CURRENT_RELIANCE,
                'claim_ceiling_note' => 'A fit hypothesis is a reason to investigate. It is never a statement of buyer intent.',
            ],
        ];
    }

    /**
     * The release-boundary card refusals.
     *
     * `..._AT_RELEASE` in every code, because these are the SAME conditions the verdict gate
     * evaluated — an operator reading the audit trail needs to know the refusal happened at the
     * later boundary, on state that changed after the verdict.
     *
     * `data_ownership` is the P03-specific one: a customer catalogue is the TENANT's own material,
     * and a card that has stopped being tenant-owned cannot back a prospect release.
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
        if ((string) $card['data_ownership'] !== 'TENANT_OWNED') {
            return Outcome::refuse('SOURCE_CARD_NOT_TENANT_OWNED_AT_RELEASE', [
                'data_ownership' => (string) $card['data_ownership'],
            ], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }

        return null;
    }
}
