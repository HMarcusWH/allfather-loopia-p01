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
     * Assign a Valkyrie to a mission with an EXPLICIT tool and action-class scope.
     *
     * The assignment is the M04 half of the dispatch gate: M06 refuses a tool call whose governed
     * tool or action class is not named here, even when the capability contract would permit it.
     * A contract says what an agent may ever do; an assignment says what it may do on THIS mission.
     * Conflating the two would make every contract grant effective on every mission.
     *
     * Both scopes are stored as explicit lists, never as a wildcard: an assignment with an empty
     * tool list authorizes no tool at all, which is the correct reading of "nothing was assigned".
     *
     * @param list<string> $assignedToolIds
     * @param list<string> $assignedActionClasses
     */
    public function assignToMission(
        string $missionId,
        string $valkyrieId,
        string $capabilityContractId,
        string $assignmentRole,
        array $assignedToolIds,
        array $assignedActionClasses,
        string $assignedByOperatorId,
        string $status = 'ACTIVE',
    ): Outcome {
        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status, requested_action_classes FROM missions WHERE mission_id = ?',
            [$missionId]
        );
        if ($mission === null) {
            return Outcome::refuse('MISSION_NOT_FOUND', [], 'PROVENANCE');
        }

        // An assignment may never widen the mission. Granting an action class the mission itself
        // never requested would let the assignment become the authority instead of the mission.
        $missionClasses = json_decode((string) $mission['requested_action_classes'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($assignedActionClasses as $class) {
            if (!in_array($class, $missionClasses, true)) {
                return Outcome::refuse('ASSIGNMENT_ACTION_CLASS_OUTSIDE_MISSION_SCOPE', [
                    'action_class' => $class,
                ], 'POLICY', 'S4');
            }
        }

        $contract = $this->db->one(
            'SELECT valkyrie_id, status FROM capability_contracts WHERE capability_contract_id = ?',
            [$capabilityContractId]
        );
        if ($contract === null || (string) $contract['valkyrie_id'] !== $valkyrieId) {
            return Outcome::refuse('ASSIGNMENT_CONTRACT_NOT_FOR_VALKYRIE', [], 'CONTRACT', 'S4');
        }
        if ((string) $contract['status'] !== 'ACTIVE') {
            return Outcome::refuse('ASSIGNMENT_CONTRACT_NOT_ACTIVE', [
                'status' => (string) $contract['status'],
            ], 'CONTRACT');
        }

        $assignmentId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('mission_assignments', [
            'assignment_id' => $assignmentId,
            'schema_version' => '1',
            'tenant_id' => (string) $mission['tenant_id'],
            'workspace_id' => (string) $mission['workspace_id'],
            'mission_id' => $missionId,
            'valkyrie_id' => $valkyrieId,
            'capability_contract_id' => $capabilityContractId,
            // The contract IS its own version in this projection: contracts are append-only and a
            // change issues a new contract rather than mutating one, so there is no separate
            // version row that could name a different state than the contract itself.
            'capability_contract_version_id' => $capabilityContractId,
            'assignment_role' => $assignmentRole,
            'assigned_action_classes' => json_encode(array_values($assignedActionClasses), JSON_THROW_ON_ERROR),
            'assigned_tool_ids' => json_encode(array_values($assignedToolIds), JSON_THROW_ON_ERROR),
            'assigned_output_classes' => json_encode(['INTERNAL_INFORMATIONAL'], JSON_THROW_ON_ERROR),
            'status' => $status,
            'assigned_by_operator_id' => $assignedByOperatorId,
            'assigned_at' => $now,
        ]);

        // The mission's own allowed-tool list must name the tool too, or the M04 gate and the
        // mission record would disagree about what the mission may reach.
        $allowed = json_decode(
            (string) $this->db->scalar('SELECT allowed_tool_ids FROM missions WHERE mission_id = ?', [$missionId]),
            true, 512, JSON_THROW_ON_ERROR
        );
        $merged = array_values(array_unique([...$allowed, ...$assignedToolIds]));
        $this->db->run('UPDATE missions SET allowed_tool_ids = ? WHERE mission_id = ?', [
            json_encode($merged, JSON_THROW_ON_ERROR), $missionId,
        ]);

        return Outcome::ok(['assignment_id' => $assignmentId]);
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
    /**
     * Advance a proposal envelope to RELEASED — but ONLY against a real M10 release record.
     *
     * The envelope status is a claim about the proposal, and the M10 record is the decision that
     * claim rests on. If the envelope could reach RELEASED without one, "released" would become a
     * second, independently-settable assertion — and the console's release-readiness projection,
     * which reads BOTH, would then be reconciling two things that could disagree.
     *
     * So the record must be an INTERNAL_INFORMATIONAL_RELEASE for THIS proposal in THIS scope.
     * Not "some release record exists": the specific one that decided this release.
     */
    public function markReleased(string $proposalId, string $decisionRecordId): Outcome
    {
        $proposal = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }
        $status = (string) $proposal['status'];
        if ($status === 'RELEASED') {
            // Idempotent replay. A second release of the same proposal is not a second release.
            return Outcome::ok(['proposal_id' => $proposalId, 'status' => 'RELEASED', 'outcome' => 'REPLAYED']);
        }
        if ($status !== 'SUBMITTED') {
            return Outcome::refuse('PROPOSAL_NOT_IN_RELEASABLE_STATE', ['status' => $status], 'POLICY');
        }

        $record = $this->db->one(
            "SELECT decision_record_id FROM decision_records
             WHERE decision_record_id = ? AND proposal_id = ?
               AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
               AND tenant_id = ? AND workspace_id = ?",
            [$decisionRecordId, $proposalId, (string) $proposal['tenant_id'], (string) $proposal['workspace_id']]
        );
        if ($record === null) {
            return Outcome::refuse('PROPOSAL_RELEASE_WITHOUT_M10_RECORD', [
                'decision_record_id' => $decisionRecordId,
            ], 'PROVENANCE', 'S4', 'BLOCK_RELEASE');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($proposalId, $decisionRecordId, $status, $now): Outcome {
            $tx->run(
                'UPDATE proposal_envelopes SET status = ? WHERE proposal_id = ? AND status = ?',
                ['RELEASED', $proposalId, $status]
            );
            // M10 is the initiating actor, and the release record is the actor id: the transition
            // names the decision that caused it rather than the service that executed it.
            $this->appendTransition(
                $tx, $proposalId, $status, 'RELEASED', 'INTERNAL_INFORMATIONAL_RELEASE_RECORDED',
                'M10', $decisionRecordId, $now
            );

            return Outcome::ok(['proposal_id' => $proposalId, 'status' => 'RELEASED', 'outcome' => 'RELEASED']);
        });
    }

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
