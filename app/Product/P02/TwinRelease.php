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
 * The M10 release of an admitted twin state change, and the role-gated surface it becomes visible on.
 *
 * M10 OWNS RELEASE. Nothing here mints one, decides whether a verdict is releasable, or advances
 * the proposal envelope: `DecisionLedger::appendInternalReleaseDecisionRecord` is the single path
 * that can, and this module binds the immutable snapshot to what that wrote. The storage
 * release gate then runs AGAIN as defence in depth — it validated nothing at admission time, and
 * real time has passed since the verdict.
 *
 * P02 ADOPTS M16, as canonical does. The snapshot-view artifact is composed inside THIS
 * transaction, additively: M10 still owns the release and the snapshot row is still the product's
 * record, but the frozen artifact and its audience projections are what anyone actually reads.
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
final class TwinRelease
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly EntityTwins $twins,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
        private readonly P02Adapter $m16,
    ) {
    }

    /**
     * Release an admitted state change.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_state_change_proposal_id: string,
     *     released_by_actor_id?: ?string, correlation_id?: ?string
     * } $input
     */
    public function release(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, arbiter_verdict_id, entity_twin_version_id,
                    requested_visibility_scope, requested_release_status, supersedes_release_id,
                    released_entity_twin_snapshot_id
             FROM entity_twin_state_change_proposals
             WHERE entity_twin_state_change_proposal_id = ?',
            [$input['entity_twin_state_change_proposal_id']]
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
        if ($change['released_entity_twin_snapshot_id'] !== null) {
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
            $snapshot = $this->twins->releaseSnapshotTxFree([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'entity_twin_version_id' => (string) $change['entity_twin_version_id'],
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
            $snapshotId = (string) $snapshot->value()['released_entity_twin_snapshot_id'];

            $tx->run(
                'UPDATE entity_twin_state_change_proposals
                 SET released_entity_twin_snapshot_id = ?, updated_at = ?
                 WHERE entity_twin_state_change_proposal_id = ?',
                [$snapshotId, $this->clock->now(), $input['entity_twin_state_change_proposal_id']]
            );

            // The proposal envelope is ALREADY RELEASED: M10 flipped it, in the same transaction
            // that wrote the decision record. There is nothing for this module to advance.

            // The M16 composition rides THIS transaction, so a refusal takes the snapshot and the
            // binding down with it. A released snapshot pointing at an artifact that failed to
            // compose would be a release whose output does not exist.
            $composed = $this->m16->composeTwinSnapshotArtifact([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'released_entity_twin_snapshot_id' => $snapshotId,
                'decision_record_id' => $decisionRecordId,
            ]);
            if ($composed->isRefusal()) {
                return Outcome::refuse('ARTIFACT_COMPOSITION_REFUSED', [
                    'detail' => $composed->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_entity_twin_snapshot_id' => $snapshotId,
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
            'released_entity_twin_snapshot',
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
            'SELECT released_entity_twin_snapshot_id, entity_twin_id, claim_ceiling, visibility_scope,
                    release_status, decision_record_id, released_at
             FROM released_entity_twin_snapshots
             WHERE tenant_id = ? AND workspace_id = ? AND visibility_scope = ?
               AND decision_record_id IS NOT NULL
             ORDER BY released_at, released_entity_twin_snapshot_id',
            [$input['tenant_id'], $input['workspace_id'], $input['visibility_scope']]
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'rows' => $rows,
        ];
    }

    /**
     * One released entity-twin snapshot, in detail (P09): the released version's state fields with
     * their value status, its uncertainty, its source lineage and freshness, and whether the release
     * is CURRENTLY relied on — a historical release and a current one are different answers.
     *
     * The same two gates as the list (M02 `entity_twin.read`, then the tenant tier), and the object
     * must be a released snapshot in the caller's own tenant AND workspace. A foreign or unknown id is
     * NOT_FOUND either way, so a caller cannot learn that another workspace's snapshot exists.
     *
     * @param array{tenant_id: string, workspace_id: string, actor_type: string, actor_id: string, released_entity_twin_snapshot_id: string} $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, detail: ?array<string, mixed>}
     */
    public function visibleSnapshotDetail(array $input): array
    {
        $requiredPermission = 'entity_twin.read';
        $decision = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            $requiredPermission,
            'released_entity_twin_snapshot',
            $input['released_entity_twin_snapshot_id'],
        );
        if ($decision['decision'] !== 'ALLOW') {
            return ['decision' => 'DENIED', 'required_permission' => $requiredPermission, 'reason_codes' => $decision['reason_codes'], 'detail' => null];
        }
        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], $requiredPermission)) {
            return ['decision' => 'DENIED', 'required_permission' => $requiredPermission, 'reason_codes' => ['TIER_DOES_NOT_GRANT_PERMISSION'], 'detail' => null];
        }
        $snapshot = $this->db->one(
            'SELECT s.released_entity_twin_snapshot_id, s.entity_twin_id, s.entity_twin_version_id, s.entity_twin_uncertainty_summary_id,
                    s.claim_ceiling, s.visibility_scope, s.snapshot_digest, s.release_status, s.supersedes_release_id,
                    s.decision_record_id, s.released_at,
                    t.entity_kind, t.display_name, t.industry, t.segment, t.country, t.status AS twin_status,
                    v.version_sequence, v.claim_ceiling AS version_claim_ceiling
             FROM released_entity_twin_snapshots s
             JOIN entity_twins t ON t.entity_twin_id = s.entity_twin_id AND t.tenant_id = s.tenant_id AND t.workspace_id = s.workspace_id
             JOIN entity_twin_versions v ON v.entity_twin_version_id = s.entity_twin_version_id AND v.entity_twin_id = s.entity_twin_id
             WHERE s.released_entity_twin_snapshot_id = ? AND s.tenant_id = ? AND s.workspace_id = ? AND s.decision_record_id IS NOT NULL',
            [$input['released_entity_twin_snapshot_id'], $input['tenant_id'], $input['workspace_id']]
        );
        if ($snapshot === null) {
            return ['decision' => 'NOT_FOUND', 'required_permission' => $requiredPermission, 'reason_codes' => ['RELEASED_SNAPSHOT_NOT_FOUND'], 'detail' => null];
        }
        $versionId = (string) $snapshot['entity_twin_version_id'];
        $fields = $this->db->all(
            'SELECT field_key, field_value_safe, value_status FROM entity_twin_state_summaries
             WHERE entity_twin_version_id = ? AND tenant_id = ? ORDER BY field_key',
            [$versionId, $input['tenant_id']]
        );
        $uncertainty = $this->db->one(
            'SELECT summary_safe, unknown_field_count, has_conflict, conflict_summary_safe FROM entity_twin_uncertainty_summaries
             WHERE entity_twin_uncertainty_summary_id = ? AND entity_twin_version_id = ? AND tenant_id = ?',
            [(string) $snapshot['entity_twin_uncertainty_summary_id'], $versionId, $input['tenant_id']]
        );
        $lineage = $this->db->all(
            'SELECT source_basis, allowed_use, evidence_id, source_ref_id, freshness_observed_at FROM entity_twin_source_links
             WHERE entity_twin_version_id = ? AND tenant_id = ? ORDER BY created_at, entity_twin_source_link_id',
            [$versionId, $input['tenant_id']]
        );
        $freshest = null;
        foreach ($lineage as $link) {
            if ($link['freshness_observed_at'] !== null && ($freshest === null || $link['freshness_observed_at'] > $freshest)) {
                $freshest = (string) $link['freshness_observed_at'];
            }
        }
        $successor = $this->db->scalar(
            'SELECT released_entity_twin_snapshot_id FROM released_entity_twin_snapshots
             WHERE supersedes_release_id = ? AND tenant_id = ? AND workspace_id = ? ORDER BY released_at DESC LIMIT 1',
            [$snapshot['released_entity_twin_snapshot_id'], $input['tenant_id'], $input['workspace_id']]
        );
        $reliance = $this->ledger->currentReliance((string) $snapshot['decision_record_id']);

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'detail' => [
                'snapshot' => array_intersect_key($snapshot, array_flip(['released_entity_twin_snapshot_id', 'claim_ceiling', 'visibility_scope', 'snapshot_digest', 'release_status', 'supersedes_release_id', 'decision_record_id', 'released_at'])),
                'entity' => array_intersect_key($snapshot, array_flip(['entity_twin_id', 'entity_kind', 'display_name', 'industry', 'segment', 'country', 'twin_status'])),
                'version' => ['entity_twin_version_id' => $versionId, 'version_sequence' => (int) $snapshot['version_sequence'], 'claim_ceiling' => $snapshot['version_claim_ceiling']],
                'fields' => $fields,
                'uncertainty' => $uncertainty === null ? null : [
                    'summary_safe' => $uncertainty['summary_safe'], 'unknown_field_count' => (int) $uncertainty['unknown_field_count'],
                    'has_conflict' => (int) $uncertainty['has_conflict'] === 1, 'conflict_summary_safe' => $uncertainty['conflict_summary_safe'],
                ],
                'lineage' => $lineage,
                'freshest_source_observed_at' => $freshest,
                'current_reliance' => $reliance,
                'currently_relied_on' => $reliance === \Allfather\Loopia\Substrate\M10\Vocabulary::CURRENT_RELIANCE && $successor === null,
                'superseded_by' => $successor === null ? null : (string) $successor,
            ],
        ];
    }
}
