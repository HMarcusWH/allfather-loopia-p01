<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M03\Eligibility;

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
        // The output classes this mission may produce. Defaults to internal informational output —
        // the only kind most missions need — so naming anything else is a deliberate act.
        array $permittedOutputClasses = ['INTERNAL_INFORMATIONAL'],
        // Supported setup predeclares both source and tool scope before activation. A changed scope
        // is an explicit successor mission, never a post-activation widening.
        array $allowedSourceScopeIds = [],
        array $allowedToolIds = [],
        ?string $supersedesMissionId = null,
    ): string {
        $missionVersion = 1;
        if ($supersedesMissionId !== null) {
            $prior = $this->db->one('SELECT tenant_id,workspace_id,mission_type,mission_version FROM missions WHERE mission_id=?', [$supersedesMissionId]);
            if ($prior === null || $prior['tenant_id'] !== $tenantId || $prior['workspace_id'] !== $workspaceId || $prior['mission_type'] !== $missionType) {
                throw new \InvalidArgumentException('successor mission scope/type mismatch');
            }
            $missionVersion = (int) $prior['mission_version'] + 1;
        }
        $missionId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('missions', [
            'mission_id' => $missionId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'mission_version' => $missionVersion,
            'mission_type' => $missionType,
            'title' => $title,
            'objective' => $objective,
            'purpose_summary' => $objective,
            'target_scope' => 'WORKSPACE_SCOPED',
            'requested_action_classes' => json_encode(array_values($requestedActionClasses), JSON_THROW_ON_ERROR),
            'permitted_output_classes' => json_encode(array_values($permittedOutputClasses), JSON_THROW_ON_ERROR),
            'allowed_tool_ids' => json_encode(array_values(array_unique($allowedToolIds)), JSON_THROW_ON_ERROR),
            'allowed_source_scope_ids' => json_encode(array_values(array_unique($allowedSourceScopeIds)), JSON_THROW_ON_ERROR),
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
            'activated_by_operator_id' => $status === 'ACTIVE' && $createdByActorType === 'OPERATOR' ? $createdByActorId : null,
            'supersedes_mission_id' => $supersedesMissionId,
        ]);

        return $missionId;
    }

    /** Canonical DRAFT -> ACTIVE transition used by operator-supported Monitor setup. */
    public function activateMission(string $missionId, string $operatorId): Outcome
    {
        $row = $this->db->one('SELECT status FROM missions WHERE mission_id=?', [$missionId]);
        if ($row === null) return Outcome::refuse('MISSION_NOT_FOUND', [], 'PROVENANCE');
        if ($row['status'] !== 'DRAFT') return Outcome::refuse('MISSION_NOT_IN_DRAFT', ['status' => $row['status']], 'POLICY');
        $now = $this->clock->now();
        return $this->db->transaction(function (Database $tx) use ($missionId, $operatorId, $now): Outcome {
            $changed = $tx->run("UPDATE missions SET status='ACTIVE',activated_at=?,activated_by_operator_id=? WHERE mission_id=? AND status='DRAFT'", [$now, $operatorId, $missionId]);
            if ($changed !== 1) return Outcome::refuse('MISSION_ACTIVATION_RACED');
            $tx->insert('mission_status_transitions', [
                'transition_id' => Ids::new(), 'schema_version' => '1', 'mission_id' => $missionId,
                'from_status' => 'DRAFT', 'to_status' => 'ACTIVE', 'reason_code' => 'MISSION_ACTIVATED',
                'initiated_by_operator_id' => $operatorId, 'occurred_at' => $now,
            ]);
            return Outcome::ok(['mission_id' => $missionId, 'status' => 'ACTIVE']);
        });
    }

    /**
     * Strict canonical assignment for supported setup. Every assigned tool/action/output must
     * already be inside the ACTIVE mission and M03 must still permit it under this exact contract.
     */
    public function assignToMissionNarrowed(
        string $missionId, string $valkyrieId, string $capabilityContractId, string $assignmentRole,
        array $assignedToolIds, array $assignedActionClasses, string $assignedByOperatorId,
        string $status = 'ACTIVE', array $assignedOutputClasses = ['INTERNAL_INFORMATIONAL'],
    ): Outcome {
        $mission = $this->db->one('SELECT tenant_id,workspace_id,status,requested_action_classes,allowed_tool_ids,permitted_output_classes FROM missions WHERE mission_id=?', [$missionId]);
        if ($mission === null) return Outcome::refuse('MISSION_NOT_FOUND', [], 'PROVENANCE');
        if ($mission['status'] !== 'ACTIVE') return Outcome::refuse('MISSION_NOT_ACTIVE', ['status' => $mission['status']], 'MISSION');
        $actions=json_decode((string)$mission['requested_action_classes'],true,64,JSON_THROW_ON_ERROR);
        $tools=json_decode((string)$mission['allowed_tool_ids'],true,64,JSON_THROW_ON_ERROR);
        $outputs=json_decode((string)$mission['permitted_output_classes'],true,64,JSON_THROW_ON_ERROR);
        foreach($assignedActionClasses as $class)if(!in_array($class,$actions,true))return Outcome::refuse('ASSIGNMENT_ACTION_CLASS_OUTSIDE_MISSION_SCOPE',['action_class'=>$class],'POLICY','S4');
        foreach($assignedToolIds as $tool)if(!in_array($tool,$tools,true))return Outcome::refuse('ASSIGNMENT_TOOL_OUTSIDE_MISSION_SCOPE',['governed_tool_id'=>$tool],'POLICY','S4');
        foreach($assignedOutputClasses as $output)if(!in_array($output,$outputs,true))return Outcome::refuse('ASSIGNMENT_OUTPUT_OUTSIDE_MISSION_SCOPE',['output_class'=>$output],'POLICY','S4');
        $eligibility=new Eligibility($this->db,$this->clock);
        foreach($assignedToolIds as $tool)foreach($assignedActionClasses as $class){
            $decision=$eligibility->evaluateToolRight([
                'tenant_id'=>$mission['tenant_id'],'workspace_id'=>$mission['workspace_id'],'mission_id'=>$missionId,
                'valkyrie_id'=>$valkyrieId,'governed_tool_id'=>$tool,'requested_action_class'=>$class,'purpose'=>'MISSION_ASSIGNMENT',
            ]);
            if(!in_array($decision['decision'],['PERMITTED_BY_CONTRACT','REQUIRES_DOWNSTREAM_GATES'],true)||($decision['capability_contract_id']??null)!==$capabilityContractId){
                return Outcome::refuse('MISSION_BROADENS_CONTRACT',['reason_codes'=>$decision['reason_codes']??[]],'CONTRACT','S4');
            }
        }
        return $this->assignToMission($missionId,$valkyrieId,$capabilityContractId,$assignmentRole,$assignedToolIds,$assignedActionClasses,$assignedByOperatorId,$status,$assignedOutputClasses);
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
        array $assignedOutputClasses = ['INTERNAL_INFORMATIONAL'],
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
            'assigned_output_classes' => json_encode(array_values($assignedOutputClasses), JSON_THROW_ON_ERROR),
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

    /**
     * M04 does NOT release.
     *
     * Upstream states it outright: `appendInternalReleaseDecisionRecord` is "the ONE place in V1
     * that sets proposal_envelopes.status = 'RELEASED'". This module used to carry a `markReleased`
     * that performed the mutation itself after checking that an M10 record existed. That check was
     * real, but the ownership was wrong: the flip happened in a DIFFERENT transaction from the
     * decision, so a failure between them left a durable release record against a still-SUBMITTED
     * proposal, and "who releases" had two answers instead of one. The mutation now lives inside
     * the M10 release unit, and this module has no method that can reach RELEASED.
     *
     * The transition row for that flip is appended by M10, in that same transaction.
     */

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
