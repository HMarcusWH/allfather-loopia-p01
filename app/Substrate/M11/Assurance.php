<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M11;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * M11 audit, incident and replay — the assurance plane.
 *
 * It OBSERVES and RECORDS. It never grants authority, never executes a containment, and never
 * writes to the plane it audits. `audit_profiles.does_not_grant_authority` and
 * `containment_recommendations.is_not_self_executed` are both CHECK-pinned to 1, so a profile or a
 * recommendation that claimed otherwise could not be stored at all.
 *
 * Two laws shape everything here:
 *
 *   1. AN INTEGRITY FAILURE IS NEVER A QUIET FINDING. A failed ledger-integrity audit opens an
 *      incident in the same scope, so a responder actually triages it. A finding nobody is
 *      required to look at is indistinguishable from no finding.
 *   2. A REPLAY MAY NOT ACT. `replay_cases.tool_and_action_dispatch_prohibited` is CHECK-pinned to
 *      1 and `side_effect_mode` admits exactly one value, so a replay that could dispatch cannot
 *      be recorded as a replay.
 */
final class Assurance
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
    ) {
    }

    // ---------------------------------------------------------------- audit profiles and runs

    public function createAuditProfile(
        string $tenantId,
        string $workspaceId,
        string $profileKey,
        string $auditCategory,
        string $displayName,
        string $status = 'ACTIVE',
    ): string {
        $profileId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('audit_profiles', [
            'audit_profile_id' => $profileId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'profile_key' => $profileKey,
            'profile_version' => '1',
            'display_name' => $displayName,
            'audit_category' => $auditCategory,
            // CHECK-pinned to 1. An audit profile that granted authority would make the assurance
            // plane a second authority plane, which is precisely what it exists not to be.
            'does_not_grant_authority' => 1,
            'status' => $status,
            'created_at' => $now,
            'activated_at' => $status === 'ACTIVE' ? $now : null,
        ]);

        return $profileId;
    }

    /**
     * Verify a decision ledger stream and record the result as governed audit evidence.
     *
     * The audit is READ-ONLY over the plane it audits: it does not append to the stream it just
     * verified. A failure's canonical follow-up is an INCIDENT, not a ledger entry — writing to the
     * ledger to report that the ledger is broken would be recording evidence in the medium under
     * suspicion.
     */
    public function runLedgerIntegrityAudit(
        string $auditProfileId,
        string $ledgerStreamId,
        string $requestedByActorType = 'SERVICE',
        ?string $requestedByActorId = null,
    ): Outcome {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status, audit_category FROM audit_profiles WHERE audit_profile_id = ?',
            [$auditProfileId]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('AUDIT_PROFILE_NOT_FOUND_OR_INACTIVE', [], 'PROVENANCE');
        }
        // Only a LEDGER_INTEGRITY profile may authorize a LEDGER_INTEGRITY run. A release-readiness
        // or replay profile must not back ledger-integrity evidence under the wrong programme —
        // the finding would then cite an audit that never asked the question it answers.
        if ((string) $profile['audit_category'] !== 'LEDGER_INTEGRITY') {
            return Outcome::refuse('AUDIT_PROFILE_CATEGORY_NOT_LEDGER_INTEGRITY', [
                'audit_category' => (string) $profile['audit_category'],
            ], 'POLICY');
        }

        // Query-layer scope enforcement: a foreign stream cannot be verified and have its findings
        // recorded under this profile's scope.
        $stream = $this->db->one(
            'SELECT tenant_id, workspace_id FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
            [$ledgerStreamId]
        );
        if ($stream === null
            || (string) $stream['tenant_id'] !== (string) $profile['tenant_id']
            || (string) $stream['workspace_id'] !== (string) $profile['workspace_id']
        ) {
            return Outcome::refuse('AUDIT_LEDGER_STREAM_NOT_FOUND_OR_OUT_OF_SCOPE', [], 'SCOPE', 'S4');
        }

        $auditRunId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('audit_runs', [
            'audit_run_id' => $auditRunId,
            'schema_version' => '1',
            'audit_profile_id' => $auditProfileId,
            'tenant_id' => (string) $profile['tenant_id'],
            'workspace_id' => (string) $profile['workspace_id'],
            'audit_category' => 'LEDGER_INTEGRITY',
            'target_object_refs' => '[]',
            'target_ledger_stream_id' => $ledgerStreamId,
            'requested_by_actor_type' => $requestedByActorType,
            'requested_by_actor_id' => $requestedByActorId,
            'status' => 'RUNNING',
            'requested_at' => $now,
        ]);

        $verification = $this->ledger->verifyStream($ledgerStreamId);
        $passed = $verification['status'] === 'PASS';

        $checkId = Ids::new();
        $this->db->insert('audit_check_records', [
            'audit_check_record_id' => $checkId,
            'schema_version' => '1',
            'audit_run_id' => $auditRunId,
            'check_key' => 'LEDGER_CHAIN_AND_REFERENCE_INTEGRITY',
            'decision' => $passed ? 'PASS' : 'FAIL',
            'reason_codes' => json_encode(
                $passed ? [] : ['LEDGER_CHAIN_VERIFICATION_FAILURE'],
                JSON_THROW_ON_ERROR
            ),
            'severity_if_failed' => $passed ? null : 'S4',
            'safe_summary' => sprintf(
                'Ledger integrity audit: status=%s, records_verified=%d, failures=%d.',
                $verification['status'],
                $verification['records_verified'],
                count($verification['failures']),
            ),
            'evaluated_at' => $now,
        ]);

        $findingId = Ids::new();
        $this->db->insert('audit_findings', [
            'audit_finding_id' => $findingId,
            'schema_version' => '1',
            'audit_run_id' => $auditRunId,
            'audit_check_record_id' => $checkId,
            'finding_type' => $passed ? 'CONTROL_PASS' : 'INTEGRITY_FAILURE',
            'finding_status' => 'OPEN',
            'severity' => $passed ? 'S1' : 'S4',
            'confidence_classification' => 'KNOWN',
            'current_reliance_consequence' => $passed ? 'NONE' : 'INCIDENT_EVALUATION_REQUIRED',
            'safe_finding_summary' => $passed
                ? 'Decision ledger chain and references verified.'
                : 'Decision ledger integrity or reference completeness failed.',
            'reason_codes' => json_encode(
                $passed ? [] : ['LEDGER_CHAIN_VERIFICATION_FAILURE'],
                JSON_THROW_ON_ERROR
            ),
            'incident_evaluation_required' => $passed ? 0 : 1,
            'decision_ledger_record_required' => $passed ? 0 : 1,
            'created_at' => $now,
        ]);

        $this->db->run(
            "UPDATE audit_runs SET status = 'COMPLETED', summary = ?, completed_at = ? WHERE audit_run_id = ?",
            [sprintf('Ledger integrity audit %s.', $passed ? 'CONTROL_PASS' : 'INTEGRITY_FAILURE'), $now, $auditRunId]
        );

        // A ledger-integrity failure is never just a quiet finding: it opens an incident in the
        // profile's scope so responders actually triage it.
        $incidentCaseId = null;
        if (!$passed) {
            $incidentCaseId = $this->openIncident(
                (string) $profile['tenant_id'],
                (string) $profile['workspace_id'],
                'M11',
                ['LEDGER_CHAIN_VERIFICATION_FAILURE'],
                'S4',
                'LEDGER_INTEGRITY',
                'Decision ledger integrity verification failed; reliance on affected records is suspended pending triage.',
                ['ledger_stream:' . $ledgerStreamId],
            )->value()['incident_case_id'];
        }

        return Outcome::ok([
            'audit_run_id' => $auditRunId,
            'audit_check_record_id' => $checkId,
            'audit_finding_id' => $findingId,
            'finding_type' => $passed ? 'CONTROL_PASS' : 'INTEGRITY_FAILURE',
            'chain_verification_status' => $verification['status'],
            'incident_evaluation_required' => !$passed,
            'incident_case_id' => $incidentCaseId,
        ]);
    }

    // ---------------------------------------------------------------- incidents

    /**
     * Raise an incident evaluation request and open a case for it.
     *
     * The request and the case are separate rows on purpose: the request records that something
     * ASKED for evaluation, and the case records that evaluation CONCLUDED an incident exists.
     * Collapsing them would lose the distinction between "reported" and "accepted".
     *
     * @param list<string> $reasonCodes
     * @param list<string> $relatedObjectRefs
     */
    public function openIncident(
        string $tenantId,
        string $workspaceId,
        string $triggerModuleId,
        array $reasonCodes,
        string $severity,
        string $incidentClass,
        string $safeSummary,
        array $relatedObjectRefs = [],
    ): Outcome {
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $triggerModuleId, $reasonCodes, $severity,
            $incidentClass, $safeSummary, $relatedObjectRefs, $now
        ): Outcome {
            $requestId = Ids::new();
            $tx->insert('incident_evaluation_requests', [
                'incident_evaluation_request_id' => $requestId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'trigger_owner_module_id' => $triggerModuleId,
                'trigger_reason_codes' => json_encode(array_values($reasonCodes), JSON_THROW_ON_ERROR),
                'trigger_severity' => $severity,
                'related_decision_record_ids' => '[]',
                'related_object_refs' => json_encode(array_values($relatedObjectRefs), JSON_THROW_ON_ERROR),
                'status' => 'CASE_OPENED',
                'triage_decision' => 'OPEN_CASE',
                'created_at' => $now,
            ]);

            $caseId = Ids::new();
            $tx->insert('incident_cases', [
                'incident_case_id' => $caseId,
                'schema_version' => '1',
                'incident_evaluation_request_id' => $requestId,
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'incident_class' => $incidentClass,
                'severity' => $severity,
                'status' => 'OPEN',
                // The consequence a case carries while OPEN. An S4/S5 incident HOLDS by default:
                // an unresolved integrity or authority incident cannot leave release unaffected
                // while nobody has looked at it.
                'current_release_or_operation_consequence' => in_array($severity, ['S4', 'S5'], true) ? 'HOLD' : 'NONE',
                'safe_summary' => $safeSummary,
                'verified_resolution' => 0,
                'created_at' => $now,
            ]);

            $tx->insert('incident_timeline_entries', [
                'incident_timeline_entry_id' => Ids::new(),
                'schema_version' => '1',
                'incident_case_id' => $caseId,
                'entry_type' => 'OPENED',
                'safe_detail_summary' => $safeSummary,
                'related_object_ref' => $relatedObjectRefs[0] ?? null,
                'recorded_by_actor_type' => 'SERVICE',
                'recorded_by_actor_id' => null,
                'occurred_at' => $now,
            ]);

            return Outcome::ok([
                'incident_case_id' => $caseId,
                'incident_evaluation_request_id' => $requestId,
            ]);
        });
    }

    /**
     * Recommend a containment. M11 RECOMMENDS; the owning module ACTS.
     *
     * `is_not_self_executed` and `requires_owner_acknowledgement` are both CHECK-pinned to 1, so a
     * recommendation that claimed to have executed itself could not be written. That pin is the
     * whole point: an assurance plane that could act on its findings would be an authority plane
     * with no gate in front of it.
     */
    public function recommendContainment(
        string $incidentCaseId,
        string $containmentClass,
        string $urgency,
        string $ownerModuleId,
        string $summary,
    ): Outcome {
        $case = $this->db->one(
            'SELECT status FROM incident_cases WHERE incident_case_id = ?',
            [$incidentCaseId]
        );
        if ($case === null) {
            return Outcome::refuse('INCIDENT_CASE_NOT_FOUND', [], 'PROVENANCE');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $incidentCaseId, $containmentClass, $urgency, $ownerModuleId, $summary, $now
        ): Outcome {
            $recommendationId = Ids::new();
            $tx->insert('containment_recommendations', [
                'containment_recommendation_id' => $recommendationId,
                'schema_version' => '1',
                'incident_case_id' => $incidentCaseId,
                'containment_class' => $containmentClass,
                'urgency' => $urgency,
                'owner_module_id' => $ownerModuleId,
                'requires_owner_acknowledgement' => 1,
                'is_not_self_executed' => 1,
                'safe_recommendation_summary' => $summary,
                'created_at' => $now,
            ]);
            $tx->run(
                "UPDATE incident_cases SET status = 'CONTAINMENT_RECOMMENDED' WHERE incident_case_id = ?",
                [$incidentCaseId]
            );
            $tx->insert('incident_timeline_entries', [
                'incident_timeline_entry_id' => Ids::new(),
                'schema_version' => '1',
                'incident_case_id' => $incidentCaseId,
                'entry_type' => 'CONTAINMENT_RECOMMENDED',
                'safe_detail_summary' => $summary,
                'related_object_ref' => $recommendationId,
                'recorded_by_actor_type' => 'SERVICE',
                'recorded_by_actor_id' => null,
                'occurred_at' => $now,
            ]);

            return Outcome::ok(['containment_recommendation_id' => $recommendationId]);
        });
    }

    /**
     * Resolve an incident.
     *
     * A resolution WITHOUT verified evidence is recorded as unverified rather than refused: an
     * operator may legitimately close a case they could not fully verify, and pretending otherwise
     * would push that reality outside the record. What is NOT allowed is calling it verified when
     * it is not — `verified_resolution` says which happened.
     */
    public function resolveIncident(string $incidentCaseId, string $summary, bool $verified): Outcome
    {
        $case = $this->db->one(
            'SELECT status FROM incident_cases WHERE incident_case_id = ?',
            [$incidentCaseId]
        );
        if ($case === null) {
            return Outcome::refuse('INCIDENT_CASE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (in_array((string) $case['status'], ['RESOLVED', 'CLOSED_AS_NON_INCIDENT'], true)) {
            return Outcome::refuse('INCIDENT_ALREADY_CLOSED', [
                'status' => (string) $case['status'],
            ], 'POLICY');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($incidentCaseId, $summary, $verified, $now): Outcome {
            $tx->run(
                "UPDATE incident_cases
                 SET status = 'RESOLVED', verified_resolution = ?, resolved_at = ?,
                     current_release_or_operation_consequence = ?
                 WHERE incident_case_id = ?",
                // The release consequence lifts ONLY on a verified resolution. An unverified close
                // leaves the hold in place, because nothing was actually shown to be fixed.
                [$verified ? 1 : 0, $now, $verified ? 'NONE' : 'HOLD', $incidentCaseId]
            );
            $tx->insert('incident_timeline_entries', [
                'incident_timeline_entry_id' => Ids::new(),
                'schema_version' => '1',
                'incident_case_id' => $incidentCaseId,
                'entry_type' => 'RESOLVED',
                'safe_detail_summary' => $summary,
                'related_object_ref' => null,
                'recorded_by_actor_type' => 'OPERATOR',
                'recorded_by_actor_id' => null,
                'occurred_at' => $now,
            ]);

            return Outcome::ok(['incident_case_id' => $incidentCaseId, 'verified_resolution' => $verified]);
        });
    }

    // ---------------------------------------------------------------- gaps

    public function recordGap(
        string $tenantId,
        string $workspaceId,
        string $gapType,
        string $affectedPlane,
        string $effectOnV1,
        string $summary,
        ?string $sourceFindingId = null,
        ?string $sourceIncidentCaseId = null,
    ): string {
        $gapId = Ids::new();
        $this->db->insert('gap_records', [
            'gap_record_id' => $gapId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'gap_type' => $gapType,
            'affected_plane' => $affectedPlane,
            'effect_on_v1' => $effectOnV1,
            'status' => 'OPEN',
            'safe_gap_summary' => $summary,
            'source_finding_id' => $sourceFindingId,
            'source_incident_case_id' => $sourceIncidentCaseId,
            'created_at' => $this->clock->now(),
        ]);

        return $gapId;
    }

    /**
     * The gaps that BLOCK, in one scope.
     *
     * A gap blocks while its effect is one of the three blocking effects AND its status is not one
     * of the four resolved ones. Both halves matter: a resolved BLOCK_RELEASE gap no longer blocks,
     * and an open QUALIFY_CLAIM gap never did.
     *
     * @return list<array{gap_record_id: string, gap_type: string, effect_on_v1: string, status: string}>
     */
    public function listBlockingGaps(string $tenantId, string $workspaceId): array
    {
        return $this->db->all(
            "SELECT gap_record_id, gap_type, effect_on_v1, status FROM gap_records
             WHERE tenant_id = ? AND workspace_id = ?
               AND effect_on_v1 IN ('BLOCK_FEATURE', 'BLOCK_RELEASE', 'REQUIRE_INCIDENT_REVIEW')
               AND status NOT IN ('RESOLVED_WITH_EVIDENCE', 'ACCEPTED_LIMITATION', 'SUPERSEDED', 'CLOSED')
             ORDER BY created_at, gap_record_id",
            [$tenantId, $workspaceId]
        );
    }

    public function resolveGap(string $gapRecordId, string $status): Outcome
    {
        if (!in_array($status, ['RESOLVED_WITH_EVIDENCE', 'ACCEPTED_LIMITATION', 'SUPERSEDED', 'CLOSED'], true)) {
            return Outcome::refuse('GAP_RESOLUTION_STATUS_NOT_TERMINAL', ['status' => $status], 'POLICY');
        }
        $updated = $this->db->run(
            'UPDATE gap_records SET status = ?, updated_at = ? WHERE gap_record_id = ?',
            [$status, $this->clock->now(), $gapRecordId]
        );

        return $updated === 0
            ? Outcome::refuse('GAP_RECORD_NOT_FOUND', [], 'PROVENANCE')
            : Outcome::ok(['gap_record_id' => $gapRecordId, 'status' => $status]);
    }

    // ---------------------------------------------------------------- replay

    public function createReplayProfile(
        string $tenantId,
        string $workspaceId,
        string $profileKey,
        string $displayName,
    ): string {
        $profileId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('replay_profiles', [
            'replay_profile_id' => $profileId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'profile_key' => $profileKey,
            'profile_version' => '1',
            'display_name' => $displayName,
            'requires_no_side_effect_enforcement' => 1,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'activated_at' => $now,
        ]);

        return $profileId;
    }

    /**
     * Open a replay case over a set of decision records.
     *
     * The case is DECLARED no-side-effect at its storage pin, not merely intended to be:
     * `side_effect_mode` admits exactly one value and `tool_and_action_dispatch_prohibited` is
     * pinned to 1. A replay that could dispatch cannot be recorded as a replay at all.
     *
     * @param list<string> $originalDecisionRecordIds
     */
    public function createReplayCase(
        string $replayProfileId,
        string $replayPurpose,
        array $originalDecisionRecordIds,
    ): Outcome {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM replay_profiles WHERE replay_profile_id = ?',
            [$replayProfileId]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('REPLAY_PROFILE_NOT_FOUND_OR_INACTIVE', [], 'PROVENANCE');
        }

        // Every cited record must exist IN THIS SCOPE. A replay over a record the profile's
        // workspace cannot see would produce a comparison nobody in that workspace could verify.
        foreach ($originalDecisionRecordIds as $recordId) {
            $record = $this->db->one(
                'SELECT tenant_id, workspace_id FROM decision_records WHERE decision_record_id = ?',
                [$recordId]
            );
            if ($record === null
                || (string) $record['tenant_id'] !== (string) $profile['tenant_id']
                || (string) $record['workspace_id'] !== (string) $profile['workspace_id']
            ) {
                return Outcome::refuse('REPLAY_RECORD_NOT_FOUND_OR_OUT_OF_SCOPE', [
                    'decision_record_id' => $recordId,
                ], 'SCOPE', 'S4');
            }
        }

        $caseId = Ids::new();
        $this->db->insert('replay_cases', [
            'replay_case_id' => $caseId,
            'schema_version' => '1',
            'replay_profile_id' => $replayProfileId,
            'tenant_id' => (string) $profile['tenant_id'],
            'workspace_id' => (string) $profile['workspace_id'],
            'replay_purpose' => $replayPurpose,
            'original_decision_record_ids' => json_encode(array_values($originalDecisionRecordIds), JSON_THROW_ON_ERROR),
            'original_object_refs' => '[]',
            'side_effect_mode' => 'NO_SIDE_EFFECT_TEST_ONLY',
            'tool_and_action_dispatch_prohibited' => 1,
            'status' => 'READY',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['replay_case_id' => $caseId]);
    }

    /**
     * Execute a replay: re-derive each cited record's canonical basis and compare it to what was
     * stored.
     *
     * This is the ONLY thing a replay does. It reads, it re-derives, it compares — and the
     * comparison result is the whole output. It dispatches nothing, and there is no code path here
     * that could: the module imports no dispatch entrypoint at all.
     *
     * A DIVERGENCE is a finding about the RECORD, not about the replay. It means the stored basis
     * no longer digests to its stored value, so anything relying on that record is relying on
     * something that changed after the fact.
     */
    public function executeReplayCase(string $replayCaseId): Outcome
    {
        $case = $this->db->one(
            'SELECT tenant_id, workspace_id, status, original_decision_record_ids
             FROM replay_cases WHERE replay_case_id = ?',
            [$replayCaseId]
        );
        if ($case === null) {
            return Outcome::refuse('REPLAY_CASE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $case['status'] !== 'READY') {
            return Outcome::refuse('REPLAY_CASE_NOT_READY', ['status' => (string) $case['status']], 'POLICY');
        }

        $recordIds = json_decode((string) $case['original_decision_record_ids'], true, 512, JSON_THROW_ON_ERROR);
        $divergences = [];
        foreach ($recordIds as $recordId) {
            $verified = $this->ledger->verifyRecordPayload($recordId);
            if (!$verified['ok']) {
                $divergences[] = ['decision_record_id' => $recordId, 'reason' => $verified['reason']];
            }
        }

        $classification = $divergences === [] ? 'IDENTICAL' : 'DIVERGENT';
        $this->db->run(
            "UPDATE replay_cases SET status = 'COMPLETED' WHERE replay_case_id = ?",
            [$replayCaseId]
        );

        return Outcome::ok([
            'replay_case_id' => $replayCaseId,
            'outcome_classification' => $classification,
            'records_replayed' => count($recordIds),
            'divergences' => $divergences,
        ]);
    }
}
