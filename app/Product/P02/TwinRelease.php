<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * The M10 release of an admitted twin state change, and the role-gated surface it becomes visible on.
 *
 * M10 OWNS RELEASE. Nothing here mints one: `DecisionLedger::appendInternalRelease` is the single
 * path that can, and this module binds the immutable snapshot to what that wrote. The storage
 * release gate then runs AGAIN as defence in depth — it validated nothing at admission time, and
 * real time has passed since the verdict.
 *
 * P02 DOES NOT ROUTE THROUGH M16 IN THIS PORT, and that is a recorded exclusion rather than an
 * omission: upstream composes an additive M16 snapshot-view artifact inside this same transaction,
 * whose documented outcome for a twin's external sources is a HELD freeze, because twin source links
 * carry no governed card. The release itself is identical either way. Adopting M16 here would mean
 * porting a projection layer whose only reachable result is an operator hold view — so the port
 * carries the release and declares the artifact composition NOT PORTED, rather than claiming an M16
 * adoption it does not have.
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
        private readonly Proposals $proposals,
        private readonly EntityTwins $twins,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
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

        $verdict = $this->db->one(
            'SELECT av.arbiter_verdict_id, av.verdict, av.basis_hash, av.proposal_id,
                    pe.evidence_bundle_id
             FROM arbiter_verdicts av
             JOIN proposal_envelopes pe ON pe.proposal_id = av.proposal_id
             WHERE av.arbiter_verdict_id = ?',
            [(string) $change['arbiter_verdict_id']]
        );
        if ($verdict === null || (string) $verdict['verdict'] !== 'ALLOW_INFORMATIONAL_RELEASE') {
            return Outcome::refuse('VERDICT_DOES_NOT_PERMIT_RELEASE', [
                'verdict' => $verdict === null ? null : (string) $verdict['verdict'],
            ], 'POLICY', 'S4');
        }

        // --- M10 owns the release ------------------------------------------------------------------
        $streamId = $this->ledger->ensureActiveStream($input['tenant_id'], $input['workspace_id']);
        $released = $this->ledger->appendInternalRelease(
            $streamId,
            'Released entity-twin state snapshot for internal visibility.',
            [
                [
                    'owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT',
                    'input_object_id' => (string) $change['arbiter_verdict_id'],
                    'input_object_version_or_digest' => (string) $verdict['basis_hash'],
                    'reference_role' => 'VERDICT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL',
                    'input_object_id' => (string) $verdict['proposal_id'],
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
            ['proposal_id' => (string) $verdict['proposal_id']],
        );
        if ($released->isRefusal()) {
            return Outcome::refuse('M10_RELEASE_REJECTED', [
                'detail' => $released->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $released->value()['decision_record_id'];

        // --- the post-M10 unit ---------------------------------------------------------------------
        return $this->db->transaction(function (Database $tx) use (
            $input, $change, $verdict, $decisionRecordId
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

            $marked = $this->proposals->markReleased((string) $verdict['proposal_id'], $decisionRecordId);
            if ($marked->isRefusal()) {
                return Outcome::refuse('PROPOSAL_RELEASE_ADVANCE_FAILED', [
                    'detail' => $marked->reasonCode(),
                ], 'POLICY');
            }

            return Outcome::ok([
                'outcome' => 'RELEASED',
                'released_entity_twin_snapshot_id' => $snapshotId,
                'decision_record_id' => $decisionRecordId,
                'arbiter_verdict_id' => (string) $change['arbiter_verdict_id'],
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
}
