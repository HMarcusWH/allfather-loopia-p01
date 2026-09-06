<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P02\PrivacyPatterns;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P04 release, surfacing and delivery assurance.
 *
 * M10 OWNS RELEASE. This module re-validates what P04 knows, hands the release to the ledger, and
 * then materializes two SIBLING records against the decision record it returns: the routing record
 * (who needed to know) and the released-output reference (the canonical pointer). Neither is the
 * release; the decision record is.
 *
 * TWO INVARIANTS ARE RE-ASSERTED AT THE RELEASE BOUNDARY, both already checked at the verdict:
 *
 *   The privacy invariant is re-scanned against the exact text about to be distributed. A summary
 *   edited after its verdict would otherwise carry a person past a gate that had cleared different
 *   content.
 *
 *   The source release must STILL be relied upon. A source withdrawn between the verdict and the
 *   release is a claim the organization has already retracted, and distributing it to a new
 *   audience would be re-asserting it.
 *
 * SURFACING IS A THREE-GATE DEFAULT-DENY, and the third gate is what makes roles mean anything: a
 * briefing composed for SALES is invisible in the CUSTOMER_SUCCESS view, whatever permissions the
 * caller holds. A denial returns an empty list, never a partial one.
 *
 * READ AND ACKNOWLEDGE ARE DELIVERY ASSURANCE, AND ONLY THAT. The table has a CHECK pinning
 * `is_delivery_assurance_only` to 1 and has no column for a score, a rank, a response time or a
 * diligence measure. The seat reference is a ROLE SEAT, not a person. There is no arrangement of
 * these rows that becomes employee monitoring, because the columns that would carry it do not
 * exist.
 */
final class Release
{
    private const REQUIRED_PERMISSION = 'role_briefing.read';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * Release an admitted briefing.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, role_distribution_briefing_id: string,
     *     correlation_id?: ?string
     * } $input
     */
    public function releaseRoleDistribution(array $input): Outcome
    {
        $briefing = $this->db->one(
            'SELECT tenant_id, workspace_id, briefing_status, target_org_role, arbiter_verdict_id,
                    proposal_id, briefing_summary_safe, released_briefing_id, released_prospect_output_id
             FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($briefing === null) {
            return Outcome::refuse('BRIEFING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $briefing['tenant_id'] !== $input['tenant_id']
            || (string) $briefing['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BRIEFING_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Only an ADMITTED briefing may be released, which also closes the ordinary double-release
        // path: the first release moves it to DISTRIBUTED.
        if ((string) $briefing['briefing_status'] !== 'VERDICT_ISSUED' || $briefing['arbiter_verdict_id'] === null) {
            return Outcome::refuse('BRIEFING_NOT_VERDICT_ISSUED', [
                'briefing_status' => (string) $briefing['briefing_status'],
            ], 'POLICY');
        }
        // And defence in depth against an out-of-band reference: one canonical release per briefing,
        // whatever the status column happens to say.
        $alreadyReleased = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM released_distribution_outputs WHERE role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($alreadyReleased > 0) {
            return Outcome::refuse('ALREADY_RELEASED', [], 'POLICY');
        }

        // --- the release-boundary re-checks ---------------------------------------------------------
        if (PrivacyPatterns::containsIndividualIdentifier((string) $briefing['briefing_summary_safe'])) {
            return Outcome::refuse('INDIVIDUAL_IDENTIFIER_AT_RELEASE', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        $sourceRecordId = $this->briefingSourceDecisionRecord($briefing);
        if ($sourceRecordId === null || !$this->ledger->isCurrentlyRelied($sourceRecordId)) {
            return Outcome::refuse('SOURCE_RELEASE_INVALIDATED_AT_RELEASE', [
                'current_reliance' => $sourceRecordId === null
                    ? null
                    : $this->ledger->currentReliance($sourceRecordId),
            ], 'PROVENANCE', 'S4');
        }

        $verdictId = (string) $briefing['arbiter_verdict_id'];
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
        $released = $this->ledger->releaseOrResume($verdictId);
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        // --- the follow-on materialization, as ONE atomic unit -----------------------------------------
        // M10 has already committed its record. These four writes are a unit of their own: a routing
        // record with no released-output reference, or a DISTRIBUTED briefing with neither, would be
        // a half-materialized routing that the surface could not resolve.
        return $this->db->transaction(function (Database $tx) use (
            $input, $briefing, $decisionRecordId, $verdictId
        ): Outcome {
            $now = $this->clock->now();
            $routingRecordId = Ids::new();
            $tx->insert('distribution_routing_records', [
                'distribution_routing_record_id' => $routingRecordId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'role_distribution_briefing_id' => $input['role_distribution_briefing_id'],
                'target_org_role' => (string) $briefing['target_org_role'],
                'arbiter_verdict_id' => $verdictId,
                'decision_record_id' => $decisionRecordId,
                'routed_at' => $now,
            ]);

            $releasedOutputId = Ids::new();
            $tx->insert('released_distribution_outputs', [
                'released_distribution_output_id' => $releasedOutputId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'output_kind' => 'ROLE_DISTRIBUTION_BRIEFING',
                // The reference points at the BRIEFING, not at the routing record. They are
                // siblings of one release, not a parent and a child, and the exactly-one-FK CHECK
                // is what keeps that from being blurred.
                'role_distribution_briefing_id' => $input['role_distribution_briefing_id'],
                'distribution_routing_record_id' => null,
                'decision_record_id' => $decisionRecordId,
                'released_at' => $now,
            ]);

            $tx->run(
                "UPDATE role_distribution_briefings SET briefing_status = 'DISTRIBUTED'
                 WHERE role_distribution_briefing_id = ?",
                [$input['role_distribution_briefing_id']]
            );
            $tx->insert('role_distribution_briefing_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'role_distribution_briefing_id' => $input['role_distribution_briefing_id'],
                'from_status' => 'VERDICT_ISSUED',
                'to_status' => 'DISTRIBUTED',
                'reason_code' => 'M10_DECISION_RECORD_APPENDED',
                'transitioned_at' => $now,
            ]);

            // The proposal envelope is ALREADY RELEASED: M10 flipped it, in the same transaction
            // that wrote the decision record. There is nothing for this module to advance.

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_distribution_output_id' => $releasedOutputId,
                'distribution_routing_record_id' => $routingRecordId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => $verdictId,
            ]);
        });
    }

    /**
     * The three-gate surfacing.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, actor_type: string, actor_id: string,
     *     target_org_role: string, correlation_id?: ?string
     * } $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, outputs: list<array<string, mixed>>}
     */
    public function listVisibleRoleDistributions(array $input): array
    {
        // --- gate 1: M02 access ------------------------------------------------------------------
        $access = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            self::REQUIRED_PERMISSION,
            'role_distribution_briefing',
            null,
            $input['correlation_id'] ?? null,
        );
        if ($access['decision'] !== 'ALLOW') {
            return $this->denied($access['reason_codes']);
        }

        // --- gate 2: the commercial entitlement -----------------------------------------------------
        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], self::REQUIRED_PERMISSION)) {
            return $this->denied(['TIER_DOES_NOT_GRANT_PERMISSION']);
        }

        // --- gate 3: the role-distribution target ----------------------------------------------------
        // Two conditions, and the second is the one an operator can get wrong: a real org-role that
        // nobody configured as a recipient is still not a recipient.
        if (!in_array($input['target_org_role'], Distribution::TARGET_ORG_ROLES, true)) {
            return $this->denied(['UNKNOWN_TARGET_ROLE']);
        }
        $configured = $this->db->one(
            'SELECT 1 AS hit FROM console_redaction_policy_projections
             WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ? LIMIT 1',
            [$input['tenant_id'], $input['workspace_id'], $input['target_org_role']]
        );
        if ($configured === null) {
            return $this->denied(['DISTRIBUTION_TARGET_NOT_CONFIGURED']);
        }

        // The `target_org_role` predicate IS the cross-role boundary. A caller authorized for the
        // SALES view sees SALES briefings; the executive ones are not redacted for them, they are
        // not returned.
        $rows = $this->db->all(
            "SELECT b.role_distribution_briefing_id, b.target_org_role, b.briefing_summary_safe,
                    b.released_briefing_id, b.released_prospect_output_id,
                    rdo.decision_record_id, rdo.released_at
             FROM released_distribution_outputs rdo
             JOIN role_distribution_briefings b
               ON b.role_distribution_briefing_id = rdo.role_distribution_briefing_id
             WHERE rdo.tenant_id = ? AND rdo.workspace_id = ?
               AND rdo.output_kind = 'ROLE_DISTRIBUTION_BRIEFING'
               AND b.target_org_role = ?
             ORDER BY rdo.released_at, rdo.released_distribution_output_id",
            [$input['tenant_id'], $input['workspace_id'], $input['target_org_role']]
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => self::REQUIRED_PERMISSION,
            'reason_codes' => ['GRANTED'],
            'outputs' => $rows,
        ];
    }

    /**
     * Record a delivery-assurance event against a distributed briefing.
     *
     * `acknowledged_by_role_seat` is a SEAT — "SALES/seat-1" — and the column is text because a
     * seat is a position, not a person. Nothing here records who sat in it, how quickly they read,
     * or whether they read at all relative to anyone else.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, role_distribution_briefing_id: string,
     *     ack_state: string, acknowledged_by_role_seat: string
     * } $input
     */
    public function recordAcknowledgement(array $input): Outcome
    {
        $briefing = $this->db->one(
            'SELECT tenant_id, workspace_id, briefing_status
             FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
            [$input['role_distribution_briefing_id']]
        );
        if ($briefing === null) {
            return Outcome::refuse('BRIEFING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $briefing['tenant_id'] !== $input['tenant_id']
            || (string) $briefing['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('BRIEFING_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Only a DISTRIBUTED briefing can be acknowledged: nothing was delivered otherwise, and an
        // acknowledgement of an undelivered briefing would be assurance of a fiction.
        if ((string) $briefing['briefing_status'] !== 'DISTRIBUTED') {
            return Outcome::refuse('BRIEFING_NOT_DISTRIBUTED', [
                'briefing_status' => (string) $briefing['briefing_status'],
            ], 'POLICY');
        }
        // The seat reference must not name a person. A seat is "SALES/seat-1"; an email address in
        // this column would be the individual identity the whole plane is built to exclude.
        if (PrivacyPatterns::containsIndividualIdentifier($input['acknowledged_by_role_seat'])) {
            return Outcome::refuse('INDIVIDUAL_IDENTIFIER_FORBIDDEN', [
                'field' => 'acknowledged_by_role_seat',
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $ackId = Ids::new();
        $this->db->insert('distribution_acknowledgements', [
            'distribution_acknowledgement_id' => $ackId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'role_distribution_briefing_id' => $input['role_distribution_briefing_id'],
            'ack_state' => $input['ack_state'],
            // Hardcoded to 1 here and CHECK-pinned to 1 in storage. Two statements of the same
            // fact, because this is the column that would have to change first for read-tracking to
            // become anything else.
            'is_delivery_assurance_only' => 1,
            'acknowledged_by_role_seat' => $input['acknowledged_by_role_seat'],
            'acknowledged_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['distribution_acknowledgement_id' => $ackId]);
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
            // EMPTY, never partial. A denial that still returned a summary line would be a denial
            // in name only.
            'outputs' => [],
        ];
    }

    /** @param array<string, mixed> $briefing */
    private function briefingSourceDecisionRecord(array $briefing): ?string
    {
        $row = $briefing['released_briefing_id'] !== null
            ? $this->db->one(
                'SELECT decision_record_id FROM released_briefings WHERE released_briefing_id = ?',
                [(string) $briefing['released_briefing_id']]
            )
            : ($briefing['released_prospect_output_id'] !== null
                ? $this->db->one(
                    'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
                    [(string) $briefing['released_prospect_output_id']]
                )
                : null);

        return $row === null ? null : (string) $row['decision_record_id'];
    }
}
