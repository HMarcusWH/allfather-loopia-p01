<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The five P07-A audits.
 *
 *   DELIVERY_RECONCILIATION   Is every consumed authorization matched by exactly ONE outcome, in
 *                             both directions? Two outcomes on one authorization is a double
 *                             contact. An outcome with no consumed authorization is a contact
 *                             nobody authorized. A consumed authorization with no outcome is a
 *                             contact nobody recorded — the one that leaves no trace, and the
 *                             reason this is checked both ways.
 *
 *   CONSENT_STATE_INTEGRITY   Does every frozen artifact rest on a genuinely affirmative consent?
 *                             The freeze already re-checks it; this catches a binding that reached
 *                             the table without passing that gate.
 *
 *   CONTENT_COMPLIANCE        Is every recorded outcome attributable to a CONSUMED artifact — the
 *                             one whose payload integrity was verified?
 *
 *   PII_FIREWALL              Did any raw identifier survive into a stored artifact, proposal or
 *                             consent evidence?
 *
 *   EXTERNAL_EFFECT_GUARD     THE ONE THAT MATTERS MOST HERE. Five independent things that would
 *                             each have to be true for an external effect to be possible, checked
 *                             as facts about stored state rather than as beliefs about the code.
 *                             It is S5, because a positive finding would mean this projection did
 *                             something it has no code to do.
 *
 * All five READ. None repairs. A failing audit opens an incident through the certified M11 intake.
 */
final class Assurance
{
    /**
     * The record types a SYNTHETIC dispatch may carry.
     *
     * A real external send would produce something outside this set, so an outcome carrying an
     * unexpected type is evidence of an effect this projection cannot explain.
     *
     * @var list<string>
     */
    public const SYNTHETIC_DELIVERY_RECORD_TYPES = [
        'HARMLESS_DEMO_ACTION_COMPLETED',
        'HARMLESS_DEMO_ACTION_FAILED',
        'HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /** @param array{audit_profile_id: string} $input */
    public function runDeliveryReconciliationAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'DELIVERY_RECONCILIATION');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];

        // (a) A DOUBLE CONTACT: one authorization, more than one outcome.
        foreach ($this->db->all(
            'SELECT outreach_execution_authorization_id FROM delivery_outcome_records
             WHERE tenant_id = ? AND workspace_id = ?
             GROUP BY outreach_execution_authorization_id HAVING COUNT(*) > 1',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['outreach_execution_authorization_id'];
            $reasons['DOUBLE_DELIVERY_OUTCOME'] = true;
        }

        // (b) An outcome whose authorization was never consumed: a contact nobody authorized.
        foreach ($this->db->all(
            "SELECT d.delivery_outcome_record_id FROM delivery_outcome_records d
             JOIN outreach_execution_authorizations a
               ON a.outreach_execution_authorization_id = d.outreach_execution_authorization_id
             WHERE d.tenant_id = ? AND d.workspace_id = ? AND a.authorization_status <> 'CONSUMED'",
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['delivery_outcome_record_id'];
            $reasons['OUTCOME_WITHOUT_CONSUMED_AUTHORIZATION'] = true;
        }

        // (c) The OTHER direction, and the more dangerous one: an authorization that was spent with
        //     no outcome recorded. Reconciliation must be 1:1 both ways, or a contact that left no
        //     trace would look exactly like one that never happened.
        foreach ($this->db->all(
            "SELECT a.outreach_execution_authorization_id FROM outreach_execution_authorizations a
             WHERE a.tenant_id = ? AND a.workspace_id = ? AND a.authorization_status = 'CONSUMED'
               AND NOT EXISTS (
                 SELECT 1 FROM delivery_outcome_records d
                 WHERE d.outreach_execution_authorization_id = a.outreach_execution_authorization_id
               )",
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['outreach_execution_authorization_id'];
            $reasons['CONSUMED_AUTHORIZATION_WITHOUT_OUTCOME'] = true;
        }

        return $this->finish(
            $profile, 'DELIVERY_RECONCILIATION', 'P07_DELIVERY_RECONCILIATION',
            $refs, $reasons, 'INTEGRITY_FAILURE', 'S4', 'INTEGRITY',
            'A delivery outcome and its single-use authorization do not reconcile one-to-one.',
            'Every consumed authorization has exactly one recorded outcome, and vice versa.',
        );
    }

    /** @param array{audit_profile_id: string} $input */
    public function runConsentStateIntegrityAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'CONSENT_STATE_INTEGRITY');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];
        foreach ($this->db->all(
            "SELECT f.frozen_outreach_artifact_id FROM frozen_outreach_artifacts f
             JOIN consent_registry c ON c.consent_registry_entry_id = f.consent_registry_entry_id
             WHERE f.tenant_id = ? AND f.workspace_id = ?
               AND (c.consent_state <> 'OPTED_IN' OR c.lawful_basis = 'NONE')",
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['frozen_outreach_artifact_id'];
            $reasons['FROZEN_ARTIFACT_WITHOUT_AFFIRMATIVE_CONSENT'] = true;
        }

        return $this->finish(
            $profile, 'CONSENT_STATE_INTEGRITY', 'P07_CONSENT_STATE_INTEGRITY',
            $refs, $reasons, 'POLICY_GAP', 'S4', 'DISCLOSURE_OR_OVERCLAIM',
            'A frozen outreach artifact rests on a consent entry that is not affirmative.',
            'Every frozen artifact rests on an affirmative consent with a real lawful basis.',
        );
    }

    /** @param array{audit_profile_id: string} $input */
    public function runContentComplianceAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'CONTENT_COMPLIANCE');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];
        // An outcome against a non-consumed artifact cannot be attested to match the payload whose
        // integrity was verified — the verification happens AT the consume.
        foreach ($this->db->all(
            "SELECT d.delivery_outcome_record_id FROM delivery_outcome_records d
             JOIN frozen_outreach_artifacts f
               ON f.frozen_outreach_artifact_id = d.frozen_outreach_artifact_id
             WHERE d.tenant_id = ? AND d.workspace_id = ? AND f.artifact_status <> 'CONSUMED'",
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['delivery_outcome_record_id'];
            $reasons['DELIVERED_OUTCOME_FOR_NON_CONSUMED_ARTIFACT'] = true;
        }

        return $this->finish(
            $profile, 'CONTENT_COMPLIANCE', 'P07_CONTENT_COMPLIANCE',
            $refs, $reasons, 'INTEGRITY_FAILURE', 'S4', 'INTEGRITY',
            'A delivery outcome is recorded against an artifact whose payload integrity was never verified.',
            'Every recorded outcome is attributable to a consumed, integrity-verified artifact.',
        );
    }

    /**
     * Did any raw identifier survive into stored state?
     *
     * Three places, each its own reason code, because they are three different failures of the
     * firewall: the artifact that would have been sent, the proposal that was reviewed, and the
     * evidence that recorded the consent.
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runPiiFirewallAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'CONTENT_COMPLIANCE');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];

        foreach ($this->db->all(
            'SELECT frozen_outreach_artifact_id, exact_payload_reference_safe
             FROM frozen_outreach_artifacts WHERE tenant_id = ? AND workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            if (PiiFirewall::containsRawPii((string) $row['exact_payload_reference_safe'])) {
                $refs[] = (string) $row['frozen_outreach_artifact_id'];
                $reasons['UN_PSEUDONYMIZED_PII_IN_FROZEN_ARTIFACT'] = true;
            }
        }

        foreach ($this->db->all(
            'SELECT outreach_execution_proposal_id, content_to_scan_safe, exact_payload_reference_safe
             FROM outreach_execution_proposals WHERE tenant_id = ? AND workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            if (PiiFirewall::containsRawPii((string) $row['content_to_scan_safe'])
                || PiiFirewall::containsRawPii((string) $row['exact_payload_reference_safe'])
            ) {
                $refs[] = (string) $row['outreach_execution_proposal_id'];
                $reasons['UN_PSEUDONYMIZED_PII_IN_OUTREACH_PROPOSAL'] = true;
            }
        }

        foreach ($this->db->all(
            "SELECT evidence_id, captured_content_reference_safe FROM evidence_objects
             WHERE tenant_id = ? AND workspace_id = ? AND evidence_type = 'CONSENT_STATE_REFERENCE'",
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            if (PiiFirewall::containsRawPii((string) $row['captured_content_reference_safe'])) {
                $refs[] = (string) $row['evidence_id'];
                $reasons['UN_PSEUDONYMIZED_PII_IN_CONSENT_EVIDENCE'] = true;
            }
        }

        return $this->finish(
            $profile, 'CONTENT_COMPLIANCE', 'P07_PII_FIREWALL',
            $refs, $reasons, 'PROVENANCE_OR_IP_CONCERN', 'S4', 'DISCLOSURE_OR_OVERCLAIM',
            'A raw individual identifier survived into a stored outreach artifact, proposal or evidence object.',
            'No stored outreach object carries an un-pseudonymized identifier.',
        );
    }

    /**
     * The external-effect guard.
     *
     * FIVE FACTS ABOUT STORED STATE, not five beliefs about the code. Each would have to be false
     * for an external effect to be possible, and the audit reads them rather than reasoning about
     * them — which is the only way to catch a change nothing in this codebase made.
     *
     * S5, because a positive finding here means the projection did something it has no code to do.
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runExternalEffectGuardAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'EXTERNAL_EFFECT_GUARD');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];

        // (a) The workspace pin. The V1 boundary itself.
        $workspace = $this->db->one(
            'SELECT external_side_effects_allowed FROM workspaces WHERE workspace_id = ?',
            [$profile['workspace_id']]
        );
        if ($workspace !== null && (int) $workspace['external_side_effects_allowed'] !== 0) {
            $refs[] = $profile['workspace_id'];
            $reasons['EXTERNAL_SIDE_EFFECTS_ALLOWED_ENABLED'] = true;
        }

        // (b) No capability contract may carry human-approval or execution authority.
        foreach ($this->db->all(
            'SELECT capability_contract_id FROM capability_contracts
             WHERE tenant_id = ? AND (workspace_id = ? OR workspace_id IS NULL)
               AND human_approval_or_execution_authority <> 0',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            $refs[] = (string) $row['capability_contract_id'];
            $reasons['EXECUTION_AUTHORITY_PIN_RELAXED'] = true;
        }

        // (c) and (d) Every channel binding must stay inert. This projection ports no producer for
        //     that table, so the expected count is zero — but the audit reads rows rather than
        //     assuming none, because a row nothing wrote is exactly what it exists to find.
        foreach ($this->db->all(
            'SELECT outreach_channel_binding_id, external_effect_permitted, adapter_type
             FROM outreach_channel_bindings WHERE tenant_id = ? AND workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            if ((int) $row['external_effect_permitted'] !== 0) {
                $refs[] = (string) $row['outreach_channel_binding_id'];
                $reasons['CHANNEL_EXTERNAL_EFFECT_PERMITTED'] = true;
            }
            if ((string) $row['adapter_type'] !== 'NO_OP_TEST_EVENT') {
                $refs[] = (string) $row['outreach_channel_binding_id'];
                $reasons['REAL_CHANNEL_ADAPTER_BOUND'] = true;
            }
        }

        // (e) Every recorded outcome must carry a SYNTHETIC record type. A real send would leave a
        //     different one, so this catches an effect by its trace rather than by its cause.
        foreach ($this->db->all(
            'SELECT d.delivery_outcome_record_id, r.record_type
             FROM delivery_outcome_records d
             JOIN decision_records r ON r.decision_record_id = d.decision_record_id
             WHERE d.tenant_id = ? AND d.workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        ) as $row) {
            if (!in_array((string) $row['record_type'], self::SYNTHETIC_DELIVERY_RECORD_TYPES, true)) {
                $refs[] = (string) $row['delivery_outcome_record_id'];
                $reasons['NON_SYNTHETIC_DELIVERY_OUTCOME'] = true;
            }
        }

        return $this->finish(
            $profile, 'EXTERNAL_EFFECT_GUARD', 'P07_EXTERNAL_EFFECT_GUARD',
            $refs, $reasons, 'SCOPE_VIOLATION', 'S5', 'UNKNOWN_OR_UNEXPECTED_EFFECT',
            'An external-effect pin has been relaxed, or an outcome was recorded that a synthetic dispatch could not produce.',
            'Every external-effect pin holds and every recorded outcome is synthetic.',
        );
    }

    /**
     * @return array<string, string>|Outcome
     */
    private function activeProfile(string $auditProfileId, string $expectedCategory): array|Outcome
    {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status, audit_category FROM audit_profiles
             WHERE audit_profile_id = ?',
            [$auditProfileId]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('AUDIT_PROFILE_NOT_FOUND_OR_INACTIVE', [], 'PROVENANCE');
        }
        if ((string) $profile['audit_category'] !== $expectedCategory) {
            return Outcome::refuse('AUDIT_PROFILE_CATEGORY_NOT_' . $expectedCategory, [
                'audit_category' => (string) $profile['audit_category'],
            ], 'POLICY');
        }

        return [
            'audit_profile_id' => $auditProfileId,
            'tenant_id' => (string) $profile['tenant_id'],
            'workspace_id' => (string) $profile['workspace_id'],
        ];
    }

    /**
     * @param array<string, string> $profile
     * @param list<string> $violatingRefs
     * @param array<string, true> $reasons
     */
    private function finish(
        array $profile,
        string $auditCategory,
        string $checkKey,
        array $violatingRefs,
        array $reasons,
        string $findingType,
        string $severity,
        string $incidentClass,
        string $violationSummary,
        string $passSummary,
    ): Outcome {
        $refs = array_values(array_unique($violatingRefs));
        sort($refs);
        $reasonCodes = array_keys($reasons);
        sort($reasonCodes);

        $failed = $refs !== [];
        $summary = $failed
            ? sprintf('%s: %d violation(s) — %s', $checkKey, count($refs), $violationSummary)
            : sprintf('%s: %s', $checkKey, $passSummary);
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $profile, $auditCategory, $checkKey, $refs, $reasonCodes,
            $findingType, $severity, $incidentClass, $summary, $failed, $now
        ): Outcome {
            $auditRunId = Ids::new();
            $tx->insert('audit_runs', [
                'audit_run_id' => $auditRunId,
                'schema_version' => '1',
                'audit_profile_id' => $profile['audit_profile_id'],
                'tenant_id' => $profile['tenant_id'],
                'workspace_id' => $profile['workspace_id'],
                'audit_category' => $auditCategory,
                'target_object_refs' => json_encode($refs, JSON_THROW_ON_ERROR),
                'requested_by_actor_type' => 'SERVICE',
                'status' => 'RUNNING',
                'requested_at' => $now,
            ]);

            $checkId = Ids::new();
            $tx->insert('audit_check_records', [
                'audit_check_record_id' => $checkId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'check_key' => $checkKey,
                'decision' => $failed ? 'FAIL' : 'PASS',
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'severity_if_failed' => $failed ? $severity : null,
                'safe_summary' => $summary,
                'evaluated_at' => $now,
            ]);

            $findingId = Ids::new();
            $tx->insert('audit_findings', [
                'audit_finding_id' => $findingId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'finding_type' => $failed ? $findingType : 'CONTROL_PASS',
                'finding_status' => 'OPEN',
                'severity' => $severity,
                'confidence_classification' => 'KNOWN',
                'current_reliance_consequence' => $failed ? 'INCIDENT_EVALUATION_REQUIRED' : 'NONE',
                'safe_finding_summary' => $summary,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'incident_evaluation_required' => $failed ? 1 : 0,
                'decision_ledger_record_required' => $failed ? 1 : 0,
                'created_at' => $now,
            ]);

            $tx->run(
                "UPDATE audit_runs SET status = 'COMPLETED', summary = ?, completed_at = ?
                 WHERE audit_run_id = ?",
                [$summary, $now, $auditRunId]
            );

            $incidentCaseId = null;
            if ($failed) {
                $incidentCaseId = $this->m11->openIncident(
                    $profile['tenant_id'],
                    $profile['workspace_id'],
                    'M11',
                    $reasonCodes,
                    $severity,
                    $incidentClass,
                    $summary,
                    array_map(static fn (string $ref): string => $auditCategory . ':' . $ref, $refs),
                )->value()['incident_case_id'];
            }

            return Outcome::ok([
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'audit_finding_id' => $findingId,
                'finding_type' => $failed ? $findingType : 'CONTROL_PASS',
                'violation_count' => count($refs),
                'violating_refs' => $refs,
                'reason_codes' => $reasonCodes,
                'incident_evaluation_required' => $failed,
                'incident_case_id' => $incidentCaseId,
            ]);
        });
    }
}
