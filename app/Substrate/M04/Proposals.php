<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M04 — missions and proposal envelopes.
 *
 * Ported from `packages/allfather-control-substrate/src/m04/missions.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d, bounded to what the selected product flows use.
 *
 * A proposal envelope is what a Valkyrie ASKS for; nothing about creating one grants it. The
 * property that matters downstream is **immutability on submission**: `submit()` freezes the
 * envelope and computes `submission_digest` over its content, so the council reviews and the
 * arbiter decides on an exact artifact that cannot change underneath them. Every later stage cites
 * that digest, which is how a verdict can be proven to be about THIS proposal.
 */
final class Proposals
{
    public const PROPOSAL_TYPE = [
        'INTERNAL_BRIEFING',
        'CONTROLLED_DEMO_ACTION',
        'COMPANY_SIGNAL_BRIEF',                 // P01
        'BATTLECARD_BRIEF',                     // P01
        'FIT_HYPOTHESIS',                       // P03
        'PRIORITY_OUTREACH_QUEUE_ADMISSION',    // P03
        'INTELLIGENCE_DISTRIBUTION_ROUTING',    // P04
        'CRM_OUTREACH_EXECUTION',               // P07-A
        'ENTITY_TWIN_STATE_CHANGE',             // P02
        'ENTITY_RELATIONSHIP_STATE_CHANGE',     // P02
        'MARKETING_ASSET_DRAFT',                // P05
        'SOURCE_MONITORING_CONFIG_PROPOSAL',    // M15
        'SOURCE_MONITORING_TRIGGER_FINDING',    // M15
    ];

    public const REQUESTED_NEXT_STAGE = ['EVIDENCE_COLLECTION', 'HMWH_REVIEW', 'ARBITER_EVALUATION'];

    public const REVIEW_PATH = [
        'ARBITER_ONLY', 'HMWH_THEN_ARBITER', 'ARBITER_THEN_APPROVAL_FOR_DEMO_ACTION',
    ];

    public const PROPOSAL_STATUS = ['DRAFT', 'SUBMITTED', 'RELEASED', 'SUPERSEDED', 'WITHDRAWN', 'BLOCKED'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** Create an ACTIVE mission. Missions bound what a proposal may request. */
    public function createMission(
        string $tenantId,
        string $workspaceId,
        string $missionType,
        string $title,
        string $objective,
        array $requestedActionClasses,
        string $createdByActorType,
        string $createdByActorId,
        string $status = 'ACTIVE',
    ): string {
        $missionId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('missions', [
            'mission_id' => $missionId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'mission_version' => 1,
            'mission_type' => $missionType,
            'title' => $title,
            'objective' => $objective,
            'purpose_summary' => $objective,
            'target_scope' => 'WORKSPACE_SCOPED',
            'requested_action_classes' => json_encode(array_values($requestedActionClasses), JSON_THROW_ON_ERROR),
            'permitted_output_classes' => json_encode(['INTERNAL_INFORMATIONAL'], JSON_THROW_ON_ERROR),
            'allowed_tool_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'allowed_source_scope_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'completion_criteria' => 'Governed release recorded, or a refusal recorded with its reason code.',
            'explicit_prohibitions' => json_encode(
                ['NO_EXTERNAL_ACTION', 'NO_PUBLICATION', 'NO_CUSTOMER_VISIBLE_OUTPUT'],
                JSON_THROW_ON_ERROR
            ),
            'status' => $status,
            'valid_from' => $now,
            'created_by_actor_type' => $createdByActorType,
            'created_by_actor_id' => $createdByActorId,
            'created_at' => $now,
            'activated_at' => $status === 'ACTIVE' ? $now : null,
        ]);

        return $missionId;
    }

    /**
     * Create a DRAFT proposal envelope.
     *
     * @param array<string, mixed> $input
     */
    public function createDraft(array $input): Outcome
    {
        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM missions WHERE mission_id = ?',
            [$input['mission_id']]
        );
        if ($mission === null) {
            return Outcome::refuse('MISSION_NOT_FOUND', [], 'MISSION');
        }
        if ((string) $mission['status'] !== 'ACTIVE') {
            return Outcome::refuse('MISSION_NOT_ACTIVE', ['status' => (string) $mission['status']], 'MISSION');
        }
        if ((string) $mission['tenant_id'] !== (string) $input['tenant_id']
            || (string) $mission['workspace_id'] !== (string) $input['workspace_id']) {
            return Outcome::refuse('MISSION_SCOPE_MISMATCH', [], 'SCOPE');
        }

        // The action-class ceiling, enforced a third time — at M02 as a permission, at M03 as a
        // contract, and here as a request. A proposal that ASKS for an external action is refused
        // before it can be reviewed, so no downstream stage ever has to decide about one.
        if (in_array((string) $input['requested_action_class'], ['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'], true)) {
            return Outcome::refuse('PROPOSAL_ACTION_CLASS_ABOVE_CEILING', [
                'requested_action_class' => (string) $input['requested_action_class'],
            ], 'CONTRACT', 'S5', 'BLOCK_RELEASE');
        }

        $proposalId = Ids::new();
        $this->db->insert('proposal_envelopes', [
            'proposal_id' => $proposalId,
            'schema_version' => '1',
            'tenant_id' => (string) $input['tenant_id'],
            'workspace_id' => (string) $input['workspace_id'],
            'mission_id' => (string) $input['mission_id'],
            'producing_valkyrie_id' => (string) $input['producing_valkyrie_id'],
            'capability_contract_id' => (string) $input['capability_contract_id'],
            'capability_contract_version_id' => (string) $input['capability_contract_id'],
            'proposal_type' => (string) $input['proposal_type'],
            'requested_action_class' => (string) $input['requested_action_class'],
            'requested_next_stage' => (string) ($input['requested_next_stage'] ?? 'HMWH_REVIEW'),
            'title' => (string) $input['title'],
            'safe_purpose_summary' => (string) $input['safe_purpose_summary'],
            'proposed_content_or_action_summary' => (string) $input['proposed_content_or_action_summary'],
            'reasoning_summary' => $input['reasoning_summary'] ?? null,
            // Uncertainty is carried on the envelope, not discovered later: an unknown that is not
            // written down here has no way to reach the reviewer.
            'uncertainty_summary' => $input['uncertainty_summary'] ?? null,
            'declared_limitations' => json_encode(array_values($input['declared_limitations'] ?? []), JSON_THROW_ON_ERROR),
            'evidence_refs' => json_encode(array_values($input['evidence_refs'] ?? []), JSON_THROW_ON_ERROR),
            'evidence_bundle_id' => $input['evidence_bundle_id'] ?? null,
            'adverse_or_conflict_evidence_refs' => json_encode(
                array_values($input['adverse_or_conflict_evidence_refs'] ?? []),
                JSON_THROW_ON_ERROR
            ),
            'missingness_statement' => $input['missingness_statement'] ?? null,
            'requested_release_or_action_scope' => $input['requested_release_or_action_scope'] ?? null,
            'requested_review_path' => $input['requested_review_path'] ?? 'HMWH_THEN_ARBITER',
            'status' => 'DRAFT',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['proposal_id' => $proposalId]);
    }

    /**
     * Submit a proposal: freeze it and compute its submission digest.
     *
     * After this the envelope is IMMUTABLE. The council reviews, and the arbiter decides on, an
     * exact artifact — and every downstream record cites this digest, so a verdict can be proven
     * to be about THIS proposal rather than a later edit of it.
     */
    public function submit(string $proposalId): Outcome
    {
        $proposal = $this->db->one('SELECT * FROM proposal_envelopes WHERE proposal_id = ?', [$proposalId]);
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'MISSION');
        }
        if ((string) $proposal['status'] !== 'DRAFT') {
            return Outcome::refuse('PROPOSAL_NOT_DRAFT', ['status' => (string) $proposal['status']], 'MISSION');
        }

        $digest = Digest::canonicalDigest([
            'proposalId' => $proposalId,
            'missionId' => (string) $proposal['mission_id'],
            'producingValkyrieId' => (string) $proposal['producing_valkyrie_id'],
            'proposalType' => (string) $proposal['proposal_type'],
            'requestedActionClass' => (string) $proposal['requested_action_class'],
            'title' => (string) $proposal['title'],
            'safePurposeSummary' => (string) $proposal['safe_purpose_summary'],
            'proposedContentOrActionSummary' => (string) $proposal['proposed_content_or_action_summary'],
            'uncertaintySummary' => $proposal['uncertainty_summary'],
            'declaredLimitations' => json_decode((string) $proposal['declared_limitations'], true, 512, JSON_THROW_ON_ERROR),
            'evidenceRefs' => json_decode((string) $proposal['evidence_refs'], true, 512, JSON_THROW_ON_ERROR),
            'adverseOrConflictEvidenceRefs' => json_decode((string) $proposal['adverse_or_conflict_evidence_refs'], true, 512, JSON_THROW_ON_ERROR),
            'missingnessStatement' => $proposal['missingness_statement'],
        ]);

        $now = $this->clock->now();

        // The PRODUCER submits its own proposal, so the transition is attributed to the producing
        // Valkyrie. `SYSTEM` is not in the actor vocabulary for this ledger, and attributing a
        // governed act to a system placeholder would lose who actually performed it.
        $producingValkyrieId = (string) $proposal['producing_valkyrie_id'];

        return $this->db->transaction(function (Database $tx) use ($proposalId, $digest, $now, $producingValkyrieId): Outcome {
            $tx->run(
                'UPDATE proposal_envelopes SET status = ?, submitted_at = ?, submission_digest = ?
                 WHERE proposal_id = ? AND status = ?',
                ['SUBMITTED', $now, $digest, $proposalId, 'DRAFT']
            );
            $this->appendTransition(
                $tx, $proposalId, 'DRAFT', 'SUBMITTED', 'PROPOSAL_SUBMITTED',
                'VALKYRIE', $producingValkyrieId, $now
            );

            return Outcome::ok(['proposal_id' => $proposalId, 'submission_digest' => $digest]);
        });
    }

    /** Append a status transition. The envelope is never mutated by this — the ledger is separate. */
    public function appendTransition(
        Database $db,
        string $proposalId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
        string $actorType,
        string $actorId,
        string $at,
    ): void {
        $db->insert('proposal_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'proposal_id' => $proposalId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'initiated_by_actor_type' => $actorType,
            'initiated_by_actor_id' => $actorId,
            'occurred_at' => $at,
        ]);
    }

    public function get(string $proposalId): ?array
    {
        return $this->db->one('SELECT * FROM proposal_envelopes WHERE proposal_id = ?', [$proposalId]);
    }
}
