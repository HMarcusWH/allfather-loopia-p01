<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;

/**
 * The route from a recorded twin version to a governed verdict on releasing it.
 *
 * `EntityTwins::appendVersion` records what the tenant knows. It does NOT make that knowledge
 * visible to anyone — a version sitting in the tables is a private working note. THIS module is how
 * a version becomes a candidate for internal release, and it is the only way in:
 *
 *   entity_twin_versions
 *     → an ENTITY_TWIN_STATE_CHANGE binding (DRAFT)
 *     → M04 proposal envelope, freezing the requested visibility and release status into the digest
 *     → M05 evidence bundle citing the version's own lineage
 *     → M07 HMWH TWIN_STATE_REVIEW
 *     → M08 verdict over P02_TWIN_STATE_ADMISSION   {@see TwinAdmission}
 *     → M10 release                                 {@see TwinRelease}
 *
 * NO PLANE IS BYPASSED, and no step here decides anything. The binding carries a REQUEST; whether
 * the request is granted is M08's, and whether it becomes a release is M10's.
 *
 * THE REQUESTED SCOPE FREEZES AT SUBMISSION. The visibility scope and release status go into the
 * proposal's submission digest, so a state change cannot be reviewed as "internal draft to sales"
 * and released as something broader — the release path reads the binding, and the binding is the
 * thing the arbiter judged.
 *
 * THE EVIDENCE IS A DERIVED REFERENCE, never raw content. A state-change basis says which version
 * is being released and what its lineage is; the twin's actual field values stay in the twin tables
 * where the privacy screen governs them.
 *
 * NO EXTERNAL EFFECT IS REACHABLE FROM HERE. The requested action class is RELEASE_INTELLIGENCE, an
 * ENTITY_TWIN_STATE_CHANGE never routes to a dispatch, and there is no send in this vertical at all.
 */
final class TwinStateChange
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Proposals $proposals,
        private readonly EvidenceSpine $evidence,
        private readonly Readiness $readiness,
        private readonly Council $council,
    ) {
    }

    /**
     * Create the DRAFT state-change binding.
     *
     * The supersede target is validated HERE, up front, and not left to the release gate. The
     * release gate runs AFTER M10 has appended a decision record; a supersede target that failed
     * there would leave an orphaned record behind a release that never happened.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_version_id: string,
     *     requested_visibility_scope: string, requested_release_status: string,
     *     asserted_relationship_version_id?: ?string, supersedes_release_id?: ?string,
     *     admission_idempotency_key?: ?string
     * } $input
     */
    public function create(array $input): Outcome
    {
        $version = $this->db->one(
            'SELECT tenant_id, workspace_id, entity_twin_id FROM entity_twin_versions
             WHERE entity_twin_version_id = ?',
            [$input['entity_twin_version_id']]
        );
        if ($version === null) {
            return Outcome::refuse('VERSION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $version['tenant_id'] !== $input['tenant_id']
            || (string) $version['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if (!in_array($input['requested_visibility_scope'], EntityTwins::VISIBILITY_SCOPES, true)) {
            return Outcome::refuse('VISIBILITY_RULE_MISSING', [
                'requested_visibility_scope' => $input['requested_visibility_scope'],
            ], 'POLICY');
        }
        if (!in_array($input['requested_release_status'], EntityTwins::RELEASE_STATUSES, true)) {
            return Outcome::refuse('INVALID_RELEASE_STATUS', [
                'requested_release_status' => $input['requested_release_status'],
            ], 'POLICY');
        }

        $relationshipVersionId = $input['asserted_relationship_version_id'] ?? null;
        if ($relationshipVersionId !== null) {
            $rel = $this->db->one(
                'SELECT tenant_id, workspace_id FROM entity_relationship_versions
                 WHERE relationship_version_id = ?',
                [$relationshipVersionId]
            );
            if ($rel === null
                || (string) $rel['tenant_id'] !== $input['tenant_id']
                || (string) $rel['workspace_id'] !== $input['workspace_id']
            ) {
                return Outcome::refuse('RELATIONSHIP_VERSION_NOT_FOUND', [], 'PROVENANCE', 'S4');
            }
        }

        $supersedes = $input['supersedes_release_id'] ?? null;
        if ($supersedes !== null) {
            $prior = $this->db->one(
                'SELECT tenant_id, workspace_id, entity_twin_id FROM released_entity_twin_snapshots
                 WHERE released_entity_twin_snapshot_id = ?',
                [$supersedes]
            );
            if ($prior === null
                || (string) $prior['tenant_id'] !== $input['tenant_id']
                || (string) $prior['workspace_id'] !== $input['workspace_id']
                || (string) $prior['entity_twin_id'] !== (string) $version['entity_twin_id']
            ) {
                return Outcome::refuse('SUPERSEDE_TARGET_NOT_FOUND', [], 'PROVENANCE', 'S4');
            }
        }

        // The admission idempotency key is UNIQUE per tenant where present, and MariaDB has no
        // partial unique index — so the predicate is enforced here, in the same transaction as the
        // insert, rather than downgraded to a plain unique index that would reject a second NULL.
        $key = $input['admission_idempotency_key'] ?? null;

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $input, $relationshipVersionId, $supersedes, $key, $now
        ): Outcome {
            if ($key !== null) {
                $existing = (int) $tx->scalar(
                    'SELECT COUNT(*) FROM entity_twin_state_change_proposals
                     WHERE tenant_id = ? AND admission_idempotency_key = ?',
                    [$input['tenant_id'], $key]
                );
                if ($existing > 0) {
                    return Outcome::refuse('ADMISSION_IDEMPOTENCY_KEY_IN_USE', [], 'POLICY');
                }
            }

            $changeId = Ids::new();
            $tx->insert('entity_twin_state_change_proposals', [
                'entity_twin_state_change_proposal_id' => $changeId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'entity_twin_version_id' => $input['entity_twin_version_id'],
                'asserted_relationship_version_id' => $relationshipVersionId,
                'change_status' => 'DRAFT',
                'requested_visibility_scope' => $input['requested_visibility_scope'],
                'requested_release_status' => $input['requested_release_status'],
                'supersedes_release_id' => $supersedes,
                'admission_idempotency_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->appendTransition($tx, $changeId, null, 'DRAFT', 'STATE_CHANGE_CREATED', $now);

            return Outcome::ok([
                'outcome' => 'CREATED',
                'entity_twin_state_change_proposal_id' => $changeId,
            ]);
        });
    }

    /**
     * Submit the binding as a governed ENTITY_TWIN_STATE_CHANGE proposal.
     *
     * Everything from the basis evidence to the binding's status advance is ONE transaction. A
     * partial submission — a frozen evidence bundle with no proposal, or a proposal the binding does
     * not point at — would leave a governed object nothing owns.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_state_change_proposal_id: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submit(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, entity_twin_version_id,
                    requested_visibility_scope, requested_release_status
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
        if ((string) $change['change_status'] !== 'DRAFT') {
            return Outcome::refuse('STATE_CHANGE_NOT_DRAFT', [
                'change_status' => (string) $change['change_status'],
            ], 'POLICY');
        }

        $governance = $input['governance'];
        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM missions WHERE mission_id = ?',
            [$governance['mission_id']]
        );
        if ($mission === null) {
            return Outcome::refuse('MISSION_NOT_FOUND', [], 'MISSION');
        }
        if ((string) $mission['tenant_id'] !== $input['tenant_id']
            || (string) $mission['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('MISSION_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $mission['status'] !== 'ACTIVE') {
            return Outcome::refuse('MISSION_NOT_ACTIVE', ['status' => (string) $mission['status']], 'MISSION');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $change, $governance): Outcome {
            // A dedicated INTERNAL_DEMO source reference anchors the derived basis. The location is
            // the state change's own identity, not a URL — nothing was fetched to build this.
            $sourceRefId = $this->evidence->registerSource(
                $input['tenant_id'],
                $input['workspace_id'],
                'PROJECT_CANONICAL_OBJECT',
                'twin-state-basis:' . $input['entity_twin_state_change_proposal_id'],
                'internal_entity_twin',
                'INTERNAL_DEMO',
                'ACTIVE_FOR_CAPTURE',
            );
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'source_ref_id' => $sourceRefId,
                'evidence_type' => 'ENTITY_TWIN_STATE_BASIS_REFERENCE',
                'captured_content_reference_safe' => 'Entity-twin state-change basis: version '
                    . (string) $change['entity_twin_version_id'] . '.',
                'content_hash' => Digest::canonicalDigest([
                    'entityTwinStateChangeProposalId' => $input['entity_twin_state_change_proposal_id'],
                    'entityTwinVersionId' => (string) $change['entity_twin_version_id'],
                ]),
                'capture_method' => 'ENTITY_TWIN_STATE_BASIS_REFERENCE',
                'permitted_use_scope' => 'internal_entity_twin',
                'sensitivity_class' => 'INTERNAL_DEMO',
            ]);
            if ($captured->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $captured->reasonCode(),
                ], 'EVIDENCE');
            }
            $evidenceId = (string) $captured->value()['evidence_id'];

            $bundleId = $this->evidence->createBundleDraft(
                $input['tenant_id'], $input['workspace_id'], $governance['mission_id'], null,
                'entity twin state-change basis evidence',
            );
            $added = $this->evidence->addBundleItem($bundleId, $evidenceId);
            if ($added->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $added->reasonCode(),
                ], 'EVIDENCE');
            }
            $submittedBundle = $this->evidence->submitBundle($bundleId);
            if ($submittedBundle->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $submittedBundle->reasonCode(),
                ], 'EVIDENCE');
            }

            $draft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => 'ENTITY_TWIN_STATE_CHANGE',
                'requested_action_class' => 'RELEASE_INTELLIGENCE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'Entity twin state change',
                'safe_purpose_summary' =>
                    'HMWH twin-state review of an entity-twin state change for internal snapshot release.',
                'proposed_content_or_action_summary' =>
                    'Release an evidence-backed, uncertainty-aware twin-state snapshot; internal only, never an external send.',
                'uncertainty_summary' =>
                    'The version carries an explicit uncertainty summary; unknowns stay unknown and unsupported facts are blocked.',
                'declared_limitations' => [
                    'Internal intelligence state only; no external dispatch, CRM write, or publish.',
                    'People are minimized role objects; no psychographic, loyalty, or productivity profiling.',
                ],
                'evidence_refs' => [$evidenceId],
                'evidence_bundle_id' => $bundleId,
                // What the caller is ASKING for, frozen into the submission digest. The release path
                // reads it back off the binding, so a review of one scope cannot become a release
                // into another.
                'requested_release_or_action_scope' => json_encode([
                    'requestedVisibilityScope' => (string) $change['requested_visibility_scope'],
                    'requestedReleaseStatus' => (string) $change['requested_release_status'],
                ], JSON_THROW_ON_ERROR),
            ]);
            if ($draft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $draft->reasonCode(),
                ], 'POLICY');
            }
            $proposalId = (string) $draft->value()['proposal_id'];

            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                ], 'EVIDENCE');
            }

            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }

            $now = $this->clock->now();
            // The binding is bound to its proposal BEFORE any verdict, because the arbiter's P02
            // context is loaded THROUGH this binding: an unbound state change has no twin context,
            // and the fifteen rules would stand aside rather than judge.
            $tx->run(
                "UPDATE entity_twin_state_change_proposals
                 SET proposal_id = ?, change_status = 'PENDING_VERDICT', updated_at = ?
                 WHERE entity_twin_state_change_proposal_id = ?",
                [$proposalId, $now, $input['entity_twin_state_change_proposal_id']]
            );
            $this->appendTransition(
                $tx,
                $input['entity_twin_state_change_proposal_id'],
                'DRAFT',
                'PENDING_VERDICT',
                'STATE_CHANGE_PROPOSAL_SUBMITTED',
                $now,
            );

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'proposal_id' => $proposalId,
                'evidence_bundle_id' => $bundleId,
                'submission_digest' => (string) $submitted->value()['submission_digest'],
                'readiness_status' => $readiness['requirement_status'],
            ]);
        });
    }

    /**
     * Open the M07 TWIN_STATE_REVIEW for a submitted state change.
     *
     * The review purpose is named — a council session that did not say what it was reviewing could
     * later be cited as advice on something it never considered.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_state_change_proposal_id: string,
     *     review_mode?: string, initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openReview(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, proposal_id
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
        if ((string) $change['change_status'] !== 'PENDING_VERDICT' || $change['proposal_id'] === null) {
            return Outcome::refuse('STATE_CHANGE_NOT_PENDING_VERDICT', [
                'change_status' => (string) $change['change_status'],
            ], 'POLICY');
        }

        $proposal = $this->db->one(
            'SELECT mission_id, evidence_bundle_id FROM proposal_envelopes WHERE proposal_id = ?',
            [(string) $change['proposal_id']]
        );
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }

        $opened = $this->council->openSession(
            $input['tenant_id'],
            $input['workspace_id'],
            (string) $proposal['mission_id'],
            (string) $change['proposal_id'],
            $input['review_mode'] ?? 'MULTI_REVIEWER_DEMO',
            'TWIN_STATE_REVIEW',
            $input['initiated_by_actor_type'],
            $input['initiated_by_actor_id'],
            $proposal['evidence_bundle_id'] === null ? null : (string) $proposal['evidence_bundle_id'],
        );
        if ($opened->isRefusal()) {
            return Outcome::refuse('REVIEW_NOT_OPENED', ['detail' => $opened->reasonCode()], 'REVIEW');
        }

        return Outcome::ok([
            'outcome' => 'OPENED',
            'review_session_id' => (string) $opened->value()['review_session_id'],
            'proposal_id' => (string) $change['proposal_id'],
        ]);
    }

    /**
     * Append a status transition. The table is append-only, so this is the whole history mechanism.
     */
    public function appendTransition(
        Database $db,
        string $changeId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
        string $now,
    ): void {
        $db->insert('entity_twin_state_change_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'entity_twin_state_change_proposal_id' => $changeId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'transitioned_at' => $now,
        ]);
    }
}
