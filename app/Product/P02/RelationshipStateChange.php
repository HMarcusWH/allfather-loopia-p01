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
 * The route from a recorded relationship version to a governed verdict on releasing it.
 *
 * The twin path answers "what do we know about this company". This one answers "what do we say
 * about the line BETWEEN two companies" — supplies, competes with, depends on — and it is the
 * stronger claim of the two, because it asserts something about a party who is not the tenant.
 *
 *   entity_relationship_versions
 *     → an ENTITY_RELATIONSHIP_STATE_CHANGE binding (DRAFT)
 *     → M04 proposal, freezing the requested visibility and release status into the digest
 *     → M05 evidence bundle citing the version's own lineage
 *     → M07 HMWH RELATIONSHIP_STATE_REVIEW
 *     → M08 verdict over P02_RELATIONSHIP_STATE_ADMISSION   {@see RelationshipAdmission}
 *     → M10 release + M16 snapshot view                     {@see RelationshipRelease}
 *
 * THE PRODUCER'S AUTHORITY IS CHECKED HERE, before any evidence is frozen. Three separate things
 * must hold: the contract is ACTIVE and current and belongs to the named Valkyrie, the contract
 * carries an ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL output-class binding, and the mission
 * permits that output class. An agent authorized to curate twins is not thereby authorized to
 * assert relationships, and the difference is exactly what these three checks keep.
 *
 * NO EXTERNAL EFFECT. The requested action class is RELEASE_INTELLIGENCE and there is no send in
 * this vertical at all.
 */
final class RelationshipStateChange
{
    private const OUTPUT_CLASS = 'ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL';

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
     * Create the DRAFT relationship state-change binding.
     *
     * The supersede target is validated up front rather than at the release gate, which runs after
     * M10 has already appended a decision record — a failure there would orphan that record.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, relationship_version_id: string,
     *     requested_visibility_scope: string, requested_release_status: string,
     *     supersedes_release_id?: ?string, admission_idempotency_key?: ?string
     * } $input
     */
    public function create(array $input): Outcome
    {
        $version = $this->db->one(
            'SELECT tenant_id, workspace_id, relationship_edge_id FROM entity_relationship_versions
             WHERE relationship_version_id = ?',
            [$input['relationship_version_id']]
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

        $supersedes = $input['supersedes_release_id'] ?? null;
        if ($supersedes !== null) {
            $prior = $this->db->one(
                'SELECT tenant_id, workspace_id, relationship_edge_id
                 FROM released_entity_relationship_snapshots
                 WHERE released_relationship_snapshot_id = ?',
                [$supersedes]
            );
            if ($prior === null
                || (string) $prior['tenant_id'] !== $input['tenant_id']
                || (string) $prior['workspace_id'] !== $input['workspace_id']
                || (string) $prior['relationship_edge_id'] !== (string) $version['relationship_edge_id']
            ) {
                return Outcome::refuse('SUPERSEDE_TARGET_NOT_FOUND', [], 'PROVENANCE', 'S4');
            }
        }

        $key = $input['admission_idempotency_key'] ?? null;
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($input, $supersedes, $key, $now): Outcome {
            if ($key !== null) {
                // UNIQUE per tenant where present. MariaDB has no partial unique index, so the
                // predicate is enforced here, in the same transaction as the insert.
                $existing = (int) $tx->scalar(
                    'SELECT COUNT(*) FROM entity_relationship_state_change_proposals
                     WHERE tenant_id = ? AND admission_idempotency_key = ?',
                    [$input['tenant_id'], $key]
                );
                if ($existing > 0) {
                    return Outcome::refuse('ADMISSION_IDEMPOTENCY_KEY_IN_USE', [], 'POLICY');
                }
            }

            $changeId = Ids::new();
            $tx->insert('entity_relationship_state_change_proposals', [
                'entity_relationship_state_change_proposal_id' => $changeId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'relationship_version_id' => $input['relationship_version_id'],
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
                'entity_relationship_state_change_proposal_id' => $changeId,
            ]);
        });
    }

    /**
     * Submit the binding as a governed ENTITY_RELATIONSHIP_STATE_CHANGE proposal.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string,
     *     entity_relationship_state_change_proposal_id: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submit(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, relationship_version_id,
                    requested_visibility_scope, requested_release_status
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
        if ((string) $change['change_status'] !== 'DRAFT') {
            return Outcome::refuse('STATE_CHANGE_NOT_DRAFT', [
                'change_status' => (string) $change['change_status'],
            ], 'POLICY');
        }

        $governance = $input['governance'];
        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status, permitted_output_classes
             FROM missions WHERE mission_id = ?',
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

        // --- the producer's authority, in three parts ----------------------------------------------
        $now = $this->clock->now();
        $contract = $this->db->one(
            'SELECT tenant_id, workspace_id, valkyrie_id, status, valid_from, valid_until
             FROM capability_contracts WHERE capability_contract_id = ?',
            [$governance['capability_contract_id']]
        );
        if ($contract === null
            || (string) $contract['tenant_id'] !== $input['tenant_id']
            || (string) $contract['workspace_id'] !== $input['workspace_id']
            || (string) $contract['valkyrie_id'] !== $governance['producing_valkyrie_id']
            || (string) $contract['status'] !== 'ACTIVE'
            || (string) $contract['valid_from'] > $now
            || ($contract['valid_until'] !== null && (string) $contract['valid_until'] <= $now)
        ) {
            return Outcome::refuse('PRODUCER_NOT_AUTHORIZED', ['detail' => 'contract'], 'CONTRACT', 'S4');
        }

        $binding = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM output_class_bindings
             WHERE capability_contract_id = ? AND output_class = ? AND status = 'ACTIVE'",
            [$governance['capability_contract_id'], self::OUTPUT_CLASS]
        );
        if ($binding === 0) {
            return Outcome::refuse('PRODUCER_NOT_AUTHORIZED', ['detail' => 'output_class'], 'CONTRACT', 'S4');
        }

        $missionOutputs = json_decode(
            (string) ($mission['permitted_output_classes'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR
        );
        if (!in_array(self::OUTPUT_CLASS, $missionOutputs, true)) {
            return Outcome::refuse('MISSION_OUTPUT_NOT_AUTHORIZED', [], 'MISSION', 'S4');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $change, $governance): Outcome {
            $sourceRefId = $this->evidence->registerSource(
                $input['tenant_id'],
                $input['workspace_id'],
                'PROJECT_CANONICAL_OBJECT',
                'relationship-state-basis:' . $input['entity_relationship_state_change_proposal_id'],
                'internal_entity_relationship',
                'INTERNAL_DEMO',
                'ACTIVE_FOR_CAPTURE',
            );
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'source_ref_id' => $sourceRefId,
                'evidence_type' => 'ENTITY_RELATIONSHIP_STATE_BASIS_REFERENCE',
                'captured_content_reference_safe' => 'Relationship state-change basis: version '
                    . (string) $change['relationship_version_id'] . '.',
                'content_hash' => Digest::canonicalDigest([
                    'entityRelationshipStateChangeProposalId' => $input['entity_relationship_state_change_proposal_id'],
                    'relationshipVersionId' => (string) $change['relationship_version_id'],
                ]),
                'capture_method' => 'ENTITY_RELATIONSHIP_STATE_BASIS_REFERENCE',
                'permitted_use_scope' => 'internal_entity_relationship',
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
                'entity relationship state-change basis evidence',
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
                'proposal_type' => 'ENTITY_RELATIONSHIP_STATE_CHANGE',
                'requested_action_class' => 'RELEASE_INTELLIGENCE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'Entity relationship state change',
                'safe_purpose_summary' =>
                    'HMWH relationship-state review of an entity-relationship state change for internal snapshot release.',
                'proposed_content_or_action_summary' =>
                    'Release an evidence-backed, uncertainty-aware relationship-state snapshot; internal only, never an external send.',
                'uncertainty_summary' =>
                    "The version carries an explicit uncertainty summary and a declared confidence; the edge is an observation, not a certainty.",
                'declared_limitations' => [
                    'Internal intelligence state only; no external dispatch, CRM write, or publish.',
                    'A relationship edge asserts an observed association, never an intention held by either party.',
                ],
                'evidence_refs' => [$evidenceId],
                'evidence_bundle_id' => $bundleId,
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
            // Bound to its proposal BEFORE any verdict: the arbiter's relationship context loads
            // THROUGH this binding, so an unbound change has no context and the six rules would
            // stand aside rather than judge.
            $tx->run(
                "UPDATE entity_relationship_state_change_proposals
                 SET proposal_id = ?, change_status = 'PENDING_VERDICT', updated_at = ?
                 WHERE entity_relationship_state_change_proposal_id = ?",
                [$proposalId, $now, $input['entity_relationship_state_change_proposal_id']]
            );
            $this->appendTransition(
                $tx,
                $input['entity_relationship_state_change_proposal_id'],
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
     * Open the M07 RELATIONSHIP_STATE_REVIEW for a submitted state change.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string,
     *     entity_relationship_state_change_proposal_id: string, review_mode?: string,
     *     initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openReview(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT tenant_id, workspace_id, change_status, proposal_id
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
            'RELATIONSHIP_STATE_REVIEW',
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

    /** Append a status transition. The table is append-only, so this is the whole history mechanism. */
    public function appendTransition(
        Database $db,
        string $changeId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
        string $now,
    ): void {
        $db->insert('entity_relationship_state_change_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'entity_relationship_state_change_proposal_id' => $changeId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'transitioned_at' => $now,
        ]);
    }
}
