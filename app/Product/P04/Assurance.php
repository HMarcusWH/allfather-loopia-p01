<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P02\PrivacyPatterns;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The four P04 audits — the ones that ask whether the distribution plane's promises still hold
 * over what it has ALREADY released.
 *
 * The admission gates all decide about the future. These look backwards, and that is the point: an
 * authority can be withdrawn after a release, a redaction policy can tighten, and neither event
 * reaches back through a gate that already ran.
 *
 *   DISTRIBUTION_AUTHORITY        Is every released briefing still routed to a CONFIGURED
 *                                 recipient? A role de-configured after release leaves a live
 *                                 briefing addressed to an audience nobody authorizes any more.
 *
 *   REDACTION_POLICY              Does each released briefing's FROZEN profile still cover its
 *                                 role's CURRENT redaction rules? Freezing the profile is right —
 *                                 it stops a later edit weakening a verdict — but it means a
 *                                 tightened policy leaves older releases behind, and somebody has
 *                                 to be told.
 *
 *   STAKEHOLDER_ROLE_PROVENANCE   Is every role observation still role-class, individual-free and
 *                                 public-sourced? The subject-kind arm can only fire if the storage
 *                                 CHECK were removed, and it is checked anyway.
 *
 *   NO_COVERT_EMPLOYEE_TELEMETRY  The defence-in-depth sweep: an individual identifier in a
 *                                 RELEASED briefing, or an acknowledgement that is not delivery
 *                                 assurance. The primary guarantees are the M08 rule and the
 *                                 storage pins; this is what catches anything that got past them.
 *
 * ALL FOUR ARE READ-ONLY over what they audit. None repairs what it finds — an audit that fixed
 * the fault would leave no evidence the fault ever existed, and the next run would pass without
 * anyone having looked. A failing audit opens an incident through the certified M11 intake, so a
 * finding is something a person is required to see rather than a row nobody reads.
 */
final class Assurance
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /**
     * Is every released briefing still routed to a configured recipient?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runDistributionAuthorityAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'DISTRIBUTION_AUTHORITY');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $violating = $this->db->all(
            "SELECT b.role_distribution_briefing_id, b.target_org_role
             FROM released_distribution_outputs rdo
             JOIN role_distribution_briefings b
               ON b.role_distribution_briefing_id = rdo.role_distribution_briefing_id
             WHERE rdo.tenant_id = ? AND rdo.workspace_id = ?
               AND rdo.output_kind = 'ROLE_DISTRIBUTION_BRIEFING'
               AND NOT EXISTS (
                 SELECT 1 FROM console_redaction_policy_projections p
                 WHERE p.tenant_id = b.tenant_id AND p.workspace_id = b.workspace_id
                   AND p.target_org_role = b.target_org_role
               )",
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        $refs = array_map(
            static fn (array $r): string => (string) $r['role_distribution_briefing_id'],
            $violating
        );

        return $this->finish(
            $profile,
            'DISTRIBUTION_AUTHORITY',
            'P04_DISTRIBUTION_AUTHORITY',
            $refs,
            $refs === [] ? [] : ['DISTRIBUTION_TO_UNAUTHORIZED_ROLE'],
            'SCOPE_VIOLATION',
            'AUTHORITY_BYPASS',
            'A released distribution targets an org-role that is no longer a configured recipient.',
            'Every released distribution targets a currently-configured recipient role.',
        );
    }

    /**
     * Does each released briefing's frozen profile still cover its role's current rules?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runRedactionPolicyAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'REDACTION_POLICY');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $rows = $this->db->all(
            "SELECT b.role_distribution_briefing_id, b.redaction_profile, p.redaction_rules_json
             FROM released_distribution_outputs rdo
             JOIN role_distribution_briefings b
               ON b.role_distribution_briefing_id = rdo.role_distribution_briefing_id
             JOIN console_redaction_policy_projections p
               ON p.tenant_id = b.tenant_id AND p.workspace_id = b.workspace_id
                  AND p.target_org_role = b.target_org_role
             WHERE rdo.tenant_id = ? AND rdo.workspace_id = ?
               AND rdo.output_kind = 'ROLE_DISTRIBUTION_BRIEFING'",
            [$profile['tenant_id'], $profile['workspace_id']]
        );

        $refs = [];
        foreach ($rows as $row) {
            $applied = self::ruleList((string) $row['redaction_profile']);
            $required = self::ruleList((string) $row['redaction_rules_json']);
            // A MISS is a required rule the frozen profile does not carry. The reverse — a frozen
            // profile redacting more than the policy now requires — is not a finding: over-redaction
            // discloses nothing.
            if (array_diff($required, $applied) !== []) {
                $refs[] = (string) $row['role_distribution_briefing_id'];
            }
        }

        return $this->finish(
            $profile,
            'REDACTION_POLICY',
            'P04_REDACTION_POLICY',
            $refs,
            $refs === [] ? [] : ['REDACTION_PROFILE_DOES_NOT_COVER_POLICY'],
            'POLICY_GAP',
            'DISCLOSURE_OR_OVERCLAIM',
            "A released distribution's redaction profile no longer covers its role's required redactions.",
            "Every released distribution's redaction profile covers its role's configured redactions.",
        );
    }

    /**
     * Is every stakeholder observation role-class, individual-free and public-sourced?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runStakeholderRoleProvenanceAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'STAKEHOLDER_ROLE_PROVENANCE');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $rows = $this->db->all(
            'SELECT o.stakeholder_role_observation_id, o.subject_kind, o.role_class_label,
                    sr.content_sensitivity_class
             FROM stakeholder_role_observations o
             JOIN source_references sr ON sr.source_ref_id = o.source_ref_id
             WHERE o.tenant_id = ? AND o.workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        );

        $refs = [];
        foreach ($rows as $row) {
            // The subject-kind arm is structurally unreachable while the CHECK stands — which is
            // exactly why it is checked. An audit that only tested what the schema already
            // guarantees would go silent the moment the schema changed.
            $violates = (string) $row['subject_kind'] !== 'ROLE_CLASS'
                || PrivacyPatterns::containsIndividualIdentifier((string) $row['role_class_label'])
                || (string) $row['content_sensitivity_class'] !== 'PUBLIC_OR_NON_SENSITIVE';
            if ($violates) {
                $refs[] = (string) $row['stakeholder_role_observation_id'];
            }
        }

        return $this->finish(
            $profile,
            'STAKEHOLDER_ROLE_PROVENANCE',
            'P04_STAKEHOLDER_ROLE_PROVENANCE',
            $refs,
            $refs === [] ? [] : ['INDIVIDUAL_IDENTIFIER_IN_OBSERVATION'],
            'PROVENANCE_OR_IP_CONCERN',
            'PROVENANCE_OR_IP',
            'A stakeholder-role observation is non-role-class, carries an individual identifier, or is non-public-sourced.',
            'Every stakeholder-role observation is role-class, individual-free, and public-sourced.',
        );
    }

    /**
     * The defence-in-depth sweep over what has already been released.
     *
     * Two independent conditions, each with its OWN reason code, because they describe different
     * failures: content that should never have been released, and a ledger row that has stopped
     * being delivery assurance.
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runNoCovertEmployeeTelemetryAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'STAKEHOLDER_ROLE_PROVENANCE');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasonCodes = [];

        $released = $this->db->all(
            "SELECT b.role_distribution_briefing_id, b.briefing_summary_safe
             FROM released_distribution_outputs rdo
             JOIN role_distribution_briefings b
               ON b.role_distribution_briefing_id = rdo.role_distribution_briefing_id
             WHERE rdo.tenant_id = ? AND rdo.workspace_id = ?
               AND rdo.output_kind = 'ROLE_DISTRIBUTION_BRIEFING'",
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        foreach ($released as $row) {
            if (PrivacyPatterns::containsIndividualIdentifier((string) $row['briefing_summary_safe'])) {
                $refs[] = (string) $row['role_distribution_briefing_id'];
                $reasonCodes['INDIVIDUAL_IDENTIFIER_IN_RELEASED_DISTRIBUTION'] = true;
            }
        }

        $badAcks = $this->db->all(
            'SELECT distribution_acknowledgement_id FROM distribution_acknowledgements
             WHERE tenant_id = ? AND workspace_id = ? AND is_delivery_assurance_only <> 1',
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        foreach ($badAcks as $row) {
            $refs[] = (string) $row['distribution_acknowledgement_id'];
            $reasonCodes['ACKNOWLEDGEMENT_NOT_DELIVERY_ASSURANCE_ONLY'] = true;
        }

        return $this->finish(
            $profile,
            'STAKEHOLDER_ROLE_PROVENANCE',
            'P04_NO_COVERT_EMPLOYEE_TELEMETRY',
            $refs,
            array_keys($reasonCodes),
            'OVERCLAIM_OR_UI_MISREPRESENTATION',
            'DISCLOSURE_OR_OVERCLAIM',
            'A released distribution carries an individual identifier, or an acknowledgement is not delivery-assurance only.',
            'No released distribution carries an individual identifier; every acknowledgement is delivery assurance only.',
        );
    }

    /**
     * The rule list a JSON profile declares.
     *
     * Unparseable reads as EMPTY, which for the required side means "requires nothing" and for the
     * applied side means "covers nothing". Both directions fail toward reporting a gap rather than
     * hiding one.
     *
     * @return list<string>
     */
    private static function ruleList(string $json): array
    {
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return [];
        }

        return array_values(array_map(strval(...), $parsed));
    }

    /**
     * Resolve an ACTIVE profile of the expected category.
     *
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
        // The profile's CATEGORY is authority for what it may conclude. A redaction profile must
        // not back a distribution-authority finding: the finding would cite an audit that never
        // asked the question it answers.
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
     * Record the run, its check and its finding — and open an incident when it failed.
     *
     * @param array<string, string> $profile
     * @param list<string> $violatingRefs
     * @param list<string> $reasonCodes
     */
    private function finish(
        array $profile,
        string $auditCategory,
        string $checkKey,
        array $violatingRefs,
        array $reasonCodes,
        string $findingType,
        string $incidentClass,
        string $violationSummary,
        string $passSummary,
    ): Outcome {
        $failed = $violatingRefs !== [];
        $summary = $failed
            ? sprintf('%s: %d violation(s) — %s', $checkKey, count($violatingRefs), $violationSummary)
            : sprintf('%s: %s', $checkKey, $passSummary);
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $profile, $auditCategory, $checkKey, $violatingRefs, $reasonCodes,
            $findingType, $incidentClass, $summary, $failed, $now
        ): Outcome {
            $auditRunId = Ids::new();
            $tx->insert('audit_runs', [
                'audit_run_id' => $auditRunId,
                'schema_version' => '1',
                'audit_profile_id' => $profile['audit_profile_id'],
                'tenant_id' => $profile['tenant_id'],
                'workspace_id' => $profile['workspace_id'],
                'audit_category' => $auditCategory,
                // The run records WHAT it found, so a later reader can follow the finding to the
                // exact rows rather than re-deriving them against changed state.
                'target_object_refs' => json_encode($violatingRefs, JSON_THROW_ON_ERROR),
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
                'severity_if_failed' => $failed ? 'S3' : null,
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
                'severity' => 'S3',
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
                    'S3',
                    $incidentClass,
                    $summary,
                    array_map(static fn (string $ref): string => $auditCategory . ':' . $ref, $violatingRefs),
                )->value()['incident_case_id'];
            }

            return Outcome::ok([
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'audit_finding_id' => $findingId,
                'finding_type' => $failed ? $findingType : 'CONTROL_PASS',
                'violation_count' => count($violatingRefs),
                'violating_refs' => $violatingRefs,
                'reason_codes' => $reasonCodes,
                'incident_evaluation_required' => $failed,
                'incident_case_id' => $incidentCaseId,
            ]);
        });
    }
}
