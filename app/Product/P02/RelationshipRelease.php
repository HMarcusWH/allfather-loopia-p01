<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\P02Adapter;

/**
 * The M10 release of an admitted RELATIONSHIP state change, and its role-gated surface.
 *
 * Parity with the twin leg is deliberate and load-bearing: the same M10 ownership, the same binding
 * floor, the same post-M10 atomic unit, the same M16 adoption, the same two-gate surface. A
 * relationship is a stronger claim than a twin field — it asserts a line between two companies —
 * so it gets no weaker a path.
 *
 * M10 OWNS RELEASE. Nothing here mints one, decides whether a verdict is releasable, or advances
 * the proposal envelope: `DecisionLedger::appendInternalReleaseDecisionRecord` is the single
 * path that can, and this module binds the immutable snapshot to what that wrote. The storage
 * release gate then runs AGAIN as defence in depth — it validated nothing at admission time, and
 * real time has passed since the verdict.
 *
 * P02 ADOPTS M16 on this leg too, composing an ENTITY_RELATIONSHIP_SNAPSHOT_VIEW inside THIS
 * transaction. Relationship evidence links carry no `allowed_use` column, so external bases take
 * the narrow EVIDENCE_REFERENCE_ONLY reading — a derived summary is not granted by silence.
 *
 * THE THREE M16 OUTCOMES ARE NOT INTERCHANGEABLE:
 *
 *   COMPOSED  artifact frozen, operator and tenant-internal projections made, visibility activated.
 *   HELD      artifact frozen, operator hold-view made, nothing wider. The release STANDS. A twin's
 *             external sources carry no governed licence card, so they cannot clear a tenant-wide
 *             read — that is a governed answer about the sources, not a fault in the release.
 *   REFUSED   no artifact could be made. The whole post-M10 unit rolls back.
 *
 * An earlier revision of this port treated HELD as a reason to skip the adoption entirely, on the
 * reasoning that a held artifact was "the same as no artifact". It is not: the held artifact and
 * its operator view are governed output that a release is entitled to produce, and discarding them
 * lost a real surface.
 *
 * THE TWO WRITES AFTER M10 ARE ONE UNIT. M10 has already committed its decision record in its own
 * transaction; the snapshot insert and the binding update commit together or not at all, so there is
 * never a released snapshot the state change does not point at.
 *
 * NO EXTERNAL SIDE EFFECT. A released twin snapshot is an internal row that an authorized operator
 * can read. Nothing is sent, published, or written to any external system by this module.
 */
final class RelationshipRelease
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly RelationshipTwins $relationships,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
        private readonly P02Adapter $m16,
    ) {
    }

    /**
     * Release an admitted state change.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_relationship_state_change_proposal_id: string,
     *     released_by_actor_id?: ?string, correlation_id?: ?string
     * } $input
     */
    public function release(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, arbiter_verdict_id, relationship_version_id,
                    requested_visibility_scope, requested_release_status, supersedes_release_id,
                    released_relationship_snapshot_id
             FROM entity_relationship_state_change_proposals
             WHERE entity_relationship_state_change_proposal_id = ?',
            [$input['entity_relationship_state_change_proposal_id']]
        );
        if ($change === null) {
            return Outcome::refuse('STATE_CHANGE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $change['tenant_id'] !== $input['tenant_id']
            || (string) $change['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('STATE_CHANGE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $change['change_status'] !== 'VERDICT_ISSUED' || $change['arbiter_verdict_id'] === null) {
            return Outcome::refuse('STATE_CHANGE_NOT_VERDICT_ISSUED', [
                'change_status' => (string) $change['change_status'],
            ], 'POLICY', 'S4');
        }
        // The idempotency guard, checked BEFORE M10 is asked for anything. A re-call fails by name
        // rather than minting a second decision record for the same change.
        if ($change['released_relationship_snapshot_id'] !== null) {
            return Outcome::refuse('ALREADY_RELEASED', [], 'POLICY');
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
        $released = $this->ledger->releaseOrResume((string) $change['arbiter_verdict_id']);
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        // --- the post-M10 unit ---------------------------------------------------------------------
        return $this->db->transaction(function (Database $tx) use (
            $input, $change, $decisionRecordId
        ): Outcome {
            $snapshot = $this->relationships->releaseSnapshotTxFree([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'relationship_version_id' => (string) $change['relationship_version_id'],
                // Read back off the BINDING — the scope the arbiter judged, not one the caller
                // supplies now. A release cannot widen what the review considered.
                'visibility_scope' => (string) $change['requested_visibility_scope'],
                'release_status' => (string) $change['requested_release_status'],
                'supersedes_release_id' => $change['supersedes_release_id'] === null
                    ? null
                    : (string) $change['supersedes_release_id'],
                'decision_record_id' => $decisionRecordId,
                'released_by_actor_id' => $input['released_by_actor_id'] ?? null,
            ]);
            if ($snapshot->isRefusal()) {
                return Outcome::refuse('SNAPSHOT_REFUSED', [
                    'detail' => $snapshot->reasonCode(),
                ], 'POLICY');
            }
            $snapshotId = (string) $snapshot->value()['released_relationship_snapshot_id'];

            $tx->run(
                'UPDATE entity_relationship_state_change_proposals
                 SET released_relationship_snapshot_id = ?, updated_at = ?
                 WHERE entity_relationship_state_change_proposal_id = ?',
                [$snapshotId, $this->clock->now(), $input['entity_relationship_state_change_proposal_id']]
            );

            // The proposal envelope is ALREADY RELEASED: M10 flipped it, in the same transaction
            // that wrote the decision record. There is nothing for this module to advance.

            // The M16 composition rides THIS transaction, so a refusal takes the snapshot and the
            // binding down with it. A released snapshot pointing at an artifact that failed to
            // compose would be a release whose output does not exist.
            $composed = $this->m16->composeRelationshipSnapshotArtifact([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'released_relationship_snapshot_id' => $snapshotId,
                'decision_record_id' => $decisionRecordId,
            ]);
            if ($composed->isRefusal()) {
                return Outcome::refuse('ARTIFACT_COMPOSITION_REFUSED', [
                    'detail' => $composed->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_relationship_snapshot_id' => $snapshotId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => (string) $change['arbiter_verdict_id'],
                'release_artifact_id' => (string) $composed->value()['release_artifact_id'],
                // COMPOSED or HELD. A held artifact is a completed release with an operator-only
                // view, and the caller is told which it got rather than having to infer it.
                'artifact_outcome' => (string) $composed->value()['outcome'],
                'artifact_hold_reasons' => $composed->value()['hold_reasons'] ?? [],
            ]);
        });
    }

    /**
     * Surface RELEASED twin snapshots under a TWO-gate default-deny.
     *
     * Visible only when BOTH M02 grants `entity_twin.read` AND the tenant's ACTIVE subscription tier
     * includes it. A role grant is not a purchase, and either gate denying makes the whole list
     * empty — never partial, because a partial answer to "may I see this" is a yes.
     *
     * THE VISIBILITY SCOPE IS THE ROLE LENS, and only snapshots released TO it come back. An
     * unrecognized scope is default-denied rather than treated as "all" — a typo must not widen a
     * view, and a scope with no rule has no answer.
     *
     * An M10-unbound row is never surfaced. The column is NOT NULL and the release gate enforces the
     * binding floor, so such a row cannot exist; the filter stays as the third, cheapest reading.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, actor_type: string, actor_id: string,
     *     visibility_scope: string, correlation_id?: ?string
     * } $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, rows: list<array<string, mixed>>}
     */
    public function listVisibleSnapshots(array $input): array
    {
        $requiredPermission = 'entity_twin.read';

        if (!in_array($input['visibility_scope'], EntityTwins::VISIBILITY_SCOPES, true)) {
            return [
                'decision' => 'DENIED',
                'required_permission' => $requiredPermission,
                'reason_codes' => ['VISIBILITY_RULE_MISSING'],
                'rows' => [],
            ];
        }

        // Gate 1 — M02 server-side authority.
        $decision = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            $requiredPermission,
            'released_entity_relationship_snapshot',
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

        $rows = $this->db->all(
            'SELECT released_relationship_snapshot_id, relationship_edge_id, claim_ceiling, visibility_scope,
                    release_status, decision_record_id, released_at
             FROM released_entity_relationship_snapshots
             WHERE tenant_id = ? AND workspace_id = ? AND visibility_scope = ?
               AND decision_record_id IS NOT NULL
             ORDER BY released_at, released_relationship_snapshot_id',
            [$input['tenant_id'], $input['workspace_id'], $input['visibility_scope']]
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'rows' => $rows,
        ];
    }
}
