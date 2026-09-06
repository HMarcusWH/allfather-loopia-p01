<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The twelve P02 audits — the re-scan of what was actually released.
 *
 * Every violation these audits look for is STRUCTURALLY PREVENTED somewhere upstream: by a CHECK
 * constraint, by the content screen in `EntityTwins`, by one of the fifteen admission rules, or by
 * the storage release gate. That is exactly why the audits exist. A control believed to be
 * unreachable and never re-checked is a control nobody would notice the loss of, and the failure
 * would be silent — a released twin carrying an employee score refuses nothing and crashes nothing.
 *
 * THEY READ, RECORD, AND NEVER REPAIR. Each audit computes the violating released rows, writes an
 * append-only run → check → finding, and on a violation opens an incident through the certified
 * M11 intake. It never edits the row it found. An audit that fixed the fault would leave no
 * evidence the fault ever existed, and the next question — how did this get released? — would have
 * no answer.
 *
 * THE PROFILE'S CATEGORY IS AUTHORITY for what it may conclude. A profile of one category cannot
 * back another's finding: the finding would cite an audit that never asked the question it answers.
 *
 * TWO OF THE TWELVE CURRENTLY HAVE NO PRODUCER IN THIS PORT, and they are here anyway.
 * `TWIN_RELATIONSHIP_EDGE_SUPPORT` scans `released_entity_relationship_snapshots`, and the
 * relationship release lifecycle is not ported; `STAKEHOLDER_PROFILE_MINIMIZATION` scans
 * `stakeholder_role_attributes`, which nothing here writes. Both therefore pass over zero rows
 * today. They are STANDING controls: the day either producer arrives, the scan is already watching
 * it, rather than being remembered afterwards. `docs/PORT_SCOPE_MATRIX.md` says which is which, so
 * a passing run is not mistaken for evidence that a producer exists.
 */
final class TwinAssurance
{
    /**
     * The twelve audit categories, in canonical order.
     *
     * The profile key IS the category — one profile per control per scope, so "has this control
     * run?" has one place to look.
     *
     * @var list<string>
     */
    public const AUDIT_CATEGORIES = [
        'TWIN_STATE_EVIDENCE_COVERAGE',
        'TWIN_RELATIONSHIP_EDGE_SUPPORT',
        'TWIN_UNCERTAINTY_VISIBILITY',
        'STAKEHOLDER_PROFILE_MINIMIZATION',
        'NO_EMPLOYEE_SCORING',
        'NO_PRIVATE_EXTERNAL_DATA',
        'SOURCE_LICENSE_CONFORMANCE',
        'TWIN_STATE_VERSION_INTEGRITY',
        'BUYER_INTENT_OVERCLAIM',
        'FIRST_PARTY_EXTERNAL_BOUNDARY',
        'RELEASE_CEILING_DECLARATION',
        'RETENTION_POLICY_COVERAGE',
    ];

    /**
     * Field-key fragments that assert buyer intent.
     *
     * The audit only objects when one is released as a KNOWN_FACT. A buyer-intent HYPOTHESIS is
     * exactly what this system trades in; a certainty about what a company will do is not.
     *
     * @var list<string>
     */
    private const BUYER_INTENT_KEYS = [
        'buyer_intent', 'intent_to_buy', 'ready_to_buy', 'will_buy', 'purchase_intent',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /**
     * Seed and activate all twelve profiles for one scope.
     *
     * @return array<string, string> category => audit_profile_id
     */
    public function seedProfiles(string $tenantId, string $workspaceId): array
    {
        $ids = [];
        foreach (self::AUDIT_CATEGORIES as $category) {
            $existing = $this->db->scalar(
                'SELECT audit_profile_id FROM audit_profiles
                 WHERE tenant_id = ? AND workspace_id = ? AND profile_key = ?',
                [$tenantId, $workspaceId, $category]
            );
            $ids[$category] = $existing !== null
                ? (string) $existing
                : $this->m11->createAuditProfile(
                    $tenantId,
                    $workspaceId,
                    $category,
                    $category,
                    'P02 ' . strtolower(str_replace('_', ' ', $category)),
                );
        }

        return $ids;
    }

    /**
     * Run the audit the profile's category names, over the released surface in the profile's scope.
     *
     * @param array{
     *     audit_profile_id: string, requested_by_actor_type?: string, requested_by_actor_id?: ?string
     * } $input
     */
    public function runAudit(array $input): Outcome
    {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status, audit_category FROM audit_profiles
             WHERE audit_profile_id = ?',
            [$input['audit_profile_id']]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('AUDIT_PROFILE_NOT_FOUND_OR_INACTIVE', [], 'PROVENANCE');
        }
        $category = (string) $profile['audit_category'];
        if (!in_array($category, self::AUDIT_CATEGORIES, true)) {
            return Outcome::refuse('AUDIT_PROFILE_CATEGORY_NOT_P02', [
                'audit_category' => $category,
            ], 'POLICY');
        }

        $tenantId = (string) $profile['tenant_id'];
        $workspaceId = (string) $profile['workspace_id'];
        $spec = $this->spec($category, $tenantId, $workspaceId);

        return $this->record(
            $input['audit_profile_id'],
            $tenantId,
            $workspaceId,
            $category,
            $spec,
            $input['requested_by_actor_type'] ?? 'SERVICE',
            $input['requested_by_actor_id'] ?? null,
        );
    }

    /**
     * What each control looks for, and what a violation of it MEANS.
     *
     * @return array{
     *     refs: list<string>, reason_code: string, finding_type: string, severity: string,
     *     trigger_module: string, incident_class: string, violation: string, control: string
     * }
     */
    private function spec(string $category, string $tenantId, string $workspaceId): array
    {
        return match ($category) {
            // A released snapshot that asserts a supported fact with no source link behind it, or
            // that carries a field status which may never be released at all.
            'TWIN_STATE_EVIDENCE_COVERAGE' => [
                'refs' => $this->snapshotRefs($tenantId, $workspaceId,
                    "(EXISTS (SELECT 1 FROM entity_twin_state_summaries f
                              WHERE f.entity_twin_version_id = s.entity_twin_version_id
                                AND f.value_status IN ('KNOWN_FACT','TENANT_PROVIDED','PUBLIC_SOURCE','LICENSED_SOURCE','DERIVED_SUMMARY'))
                      AND NOT EXISTS (SELECT 1 FROM entity_twin_source_links l
                                      WHERE l.entity_twin_version_id = s.entity_twin_version_id))
                     OR EXISTS (SELECT 1 FROM entity_twin_state_summaries f2
                                WHERE f2.entity_twin_version_id = s.entity_twin_version_id
                                  AND f2.value_status IN ('UNSUPPORTED_CLAIM','PROHIBITED'))"),
                'reason_code' => 'UNSUPPORTED_RELEASED_TWIN_CLAIM',
                'finding_type' => 'EVIDENCE_OR_AUTHORITY_GAP',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'violation' => 'A released twin snapshot asserts a supported fact with no source link, or carries an unsupported or prohibited field.',
                'control' => "Every released twin snapshot's asserted facts are source-linked; no unsupported or prohibited field is released.",
            ],
            'TWIN_RELATIONSHIP_EDGE_SUPPORT' => [
                'refs' => $this->refs(
                    'SELECT s.released_relationship_snapshot_id AS id
                     FROM released_entity_relationship_snapshots s
                     WHERE s.tenant_id = ? AND s.workspace_id = ?
                       AND NOT EXISTS (SELECT 1 FROM entity_relationship_evidence_links l
                                       WHERE l.relationship_version_id = s.relationship_version_id)',
                    [$tenantId, $workspaceId]
                ),
                'reason_code' => 'RELEASED_RELATIONSHIP_EDGE_UNSUPPORTED',
                'finding_type' => 'EVIDENCE_OR_AUTHORITY_GAP',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'violation' => 'A released relationship snapshot cites no evidence link.',
                'control' => 'Every released relationship snapshot cites at least one evidence link.',
            ],
            // An unknown that is not visible is a certainty the reader never agreed to. Both halves
            // matter: a missing summary, and a recorded conflict the summary does not declare.
            'TWIN_UNCERTAINTY_VISIBILITY' => [
                'refs' => $this->snapshotRefs($tenantId, $workspaceId,
                    "NOT EXISTS (SELECT 1 FROM entity_twin_uncertainty_summaries u
                                 WHERE u.entity_twin_version_id = s.entity_twin_version_id)
                     OR EXISTS (SELECT 1 FROM entity_twin_state_summaries f
                                WHERE f.entity_twin_version_id = s.entity_twin_version_id
                                  AND f.value_status = 'CONFLICTING'
                                  AND EXISTS (SELECT 1 FROM entity_twin_uncertainty_summaries u2
                                              WHERE u2.entity_twin_version_id = s.entity_twin_version_id
                                                AND u2.has_conflict = 0))"),
                'reason_code' => 'UNCERTAINTY_NOT_VISIBLE',
                'finding_type' => 'OVERCLAIM_OR_UI_MISREPRESENTATION',
                'severity' => 'S3',
                'trigger_module' => 'M12',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A released twin snapshot lacks an uncertainty summary, or an unresolved conflict is hidden.',
                'control' => 'Every released twin snapshot carries a visible uncertainty summary, and conflicts are represented.',
            ],
            'STAKEHOLDER_PROFILE_MINIMIZATION' => [
                'refs' => $this->forbiddenKeyRefs(
                    'SELECT stakeholder_role_attribute_id AS id, attribute_key AS field_key
                     FROM stakeholder_role_attributes WHERE tenant_id = ?',
                    [$tenantId]
                ),
                'reason_code' => 'FORBIDDEN_STAKEHOLDER_ATTRIBUTE',
                'finding_type' => 'PROVENANCE_OR_IP_CONCERN',
                'severity' => 'S3',
                'trigger_module' => 'M12',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A stakeholder role profile carries a psychographic, sensitive, or scoring attribute.',
                'control' => 'Every stakeholder role profile carries only minimized business-role attributes.',
            ],
            'NO_EMPLOYEE_SCORING' => [
                'refs' => $this->forbiddenKeyRefs(
                    'SELECT s.released_entity_twin_snapshot_id AS id, f.field_key AS field_key
                     FROM released_entity_twin_snapshots s
                     JOIN entity_twin_state_summaries f
                       ON f.entity_twin_version_id = s.entity_twin_version_id
                     WHERE s.tenant_id = ? AND s.workspace_id = ?',
                    [$tenantId, $workspaceId]
                ),
                'reason_code' => 'EMPLOYEE_SCORING_FIELD_RELEASED',
                'finding_type' => 'PROVENANCE_OR_IP_CONCERN',
                'severity' => 'S3',
                'trigger_module' => 'M12',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A released twin snapshot carries an employee scoring or surveillance field.',
                'control' => 'No released twin snapshot carries an employee scoring or surveillance field.',
            ],
            'NO_PRIVATE_EXTERNAL_DATA' => [
                'refs' => $this->snapshotRefs($tenantId, $workspaceId,
                    "t.is_external = 1 AND EXISTS (SELECT 1 FROM entity_twin_state_summaries f
                                                   WHERE f.entity_twin_version_id = s.entity_twin_version_id
                                                     AND f.value_status = 'PROHIBITED')"),
                'reason_code' => 'PRIVATE_EXTERNAL_DATA_RELEASED',
                'finding_type' => 'PROVENANCE_OR_IP_CONCERN',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A released external-twin snapshot carries a prohibited (private) field.',
                'control' => 'No released external-twin snapshot carries private data.',
            ],
            'SOURCE_LICENSE_CONFORMANCE' => [
                'refs' => $this->snapshotRefs($tenantId, $workspaceId,
                    "EXISTS (SELECT 1 FROM entity_twin_source_links l
                             WHERE l.entity_twin_version_id = s.entity_twin_version_id
                               AND l.allowed_use = 'RESTRICTED')"),
                'reason_code' => 'RESTRICTED_SOURCE_RELEASED',
                'finding_type' => 'PROVENANCE_OR_IP_CONCERN',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'violation' => 'A released twin snapshot rests on a licence-restricted source.',
                'control' => 'No released twin snapshot rests on a licence-restricted source.',
            ],
            // Two FRESH releases of one version means two current answers to the same question.
            // A correction is a supersession, and a supersession says which answer replaced which.
            'TWIN_STATE_VERSION_INTEGRITY' => [
                'refs' => $this->refs(
                    'SELECT s.released_entity_twin_snapshot_id AS id
                     FROM released_entity_twin_snapshots s
                     WHERE s.tenant_id = ? AND s.workspace_id = ? AND s.supersedes_release_id IS NULL
                       AND s.entity_twin_version_id IN (
                         SELECT entity_twin_version_id FROM (
                           SELECT entity_twin_version_id FROM released_entity_twin_snapshots
                           WHERE supersedes_release_id IS NULL
                           GROUP BY entity_twin_version_id HAVING COUNT(*) > 1
                         ) dup
                       )',
                    [$tenantId, $workspaceId]
                ),
                'reason_code' => 'MULTIPLE_FRESH_RELEASES_FOR_VERSION',
                'finding_type' => 'INTEGRITY_FAILURE',
                'severity' => 'S3',
                'trigger_module' => 'M10',
                'incident_class' => 'INTEGRITY',
                'violation' => 'A twin version has more than one fresh (non-supersession) released snapshot.',
                'control' => 'Each twin version has at most one fresh released snapshot; corrections supersede.',
            ],
            'BUYER_INTENT_OVERCLAIM' => [
                'refs' => $this->buyerIntentRefs($tenantId, $workspaceId),
                'reason_code' => 'BUYER_INTENT_OVERCLAIM_RELEASED',
                'finding_type' => 'OVERCLAIM_OR_UI_MISREPRESENTATION',
                'severity' => 'S3',
                'trigger_module' => 'M12',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A released twin snapshot asserts buyer intent as a KNOWN_FACT rather than a hypothesis.',
                'control' => 'Buyer-intent fields stay hypotheses in released snapshots; none is a certainty.',
            ],
            'FIRST_PARTY_EXTERNAL_BOUNDARY' => [
                'refs' => $this->snapshotRefs($tenantId, $workspaceId,
                    "t.is_external = 1 AND EXISTS (SELECT 1 FROM entity_twin_source_links l
                                                   WHERE l.entity_twin_version_id = s.entity_twin_version_id
                                                     AND l.source_basis = 'TENANT_OWNED')"),
                'reason_code' => 'FIRST_PARTY_DATA_ON_EXTERNAL_TWIN',
                'finding_type' => 'SCOPE_VIOLATION',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'SCOPE_OR_TENANT_BREACH',
                'violation' => "A released external-twin snapshot rests on the tenant's own first-party data.",
                'control' => 'No external-twin snapshot rests on first-party data — the leak has no path.',
            ],
            'RELEASE_CEILING_DECLARATION' => [
                'refs' => $this->refs(
                    "SELECT s.released_entity_twin_snapshot_id AS id
                     FROM released_entity_twin_snapshots s
                     WHERE s.tenant_id = ? AND s.workspace_id = ?
                       AND s.claim_ceiling IN ('SUPERSEDED','RETRACTED')",
                    [$tenantId, $workspaceId]
                ),
                'reason_code' => 'INVALID_RELEASE_CLAIM_CEILING',
                'finding_type' => 'POLICY_GAP',
                'severity' => 'S3',
                'trigger_module' => 'M10',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'violation' => 'A released twin snapshot declares a withdrawn claim ceiling (SUPERSEDED or RETRACTED).',
                'control' => 'Every released twin snapshot declares a valid internal claim ceiling.',
            ],
            'RETENTION_POLICY_COVERAGE' => [
                'refs' => $this->refs(
                    "SELECT s.released_entity_twin_snapshot_id AS id
                     FROM released_entity_twin_snapshots s
                     WHERE s.tenant_id = ? AND s.workspace_id = ?
                       AND NOT EXISTS (SELECT 1 FROM entity_twin_ledger_anchors a
                                       WHERE a.entity_twin_id = s.entity_twin_id
                                         AND a.anchor_kind = 'RETENTION')",
                    [$tenantId, $workspaceId]
                ),
                'reason_code' => 'RETENTION_POLICY_NOT_DECLARED',
                'finding_type' => 'POLICY_GAP',
                'severity' => 'S3',
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'violation' => "A released twin snapshot's entity declares no RETENTION ledger anchor.",
                'control' => "Every released twin's entity declares a RETENTION ledger anchor.",
            ],
        };
    }

    /**
     * Released twin snapshots in scope matching a predicate over `s` (snapshot) and `t` (twin).
     *
     * The predicate is a CONSTANT written in this file — never caller input — which is why it is
     * interpolated. The scope is always parameterised.
     *
     * @return list<string>
     */
    private function snapshotRefs(string $tenantId, string $workspaceId, string $predicate): array
    {
        return $this->refs(
            "SELECT s.released_entity_twin_snapshot_id AS id
             FROM released_entity_twin_snapshots s
             JOIN entity_twins t ON t.entity_twin_id = s.entity_twin_id
             WHERE s.tenant_id = ? AND s.workspace_id = ? AND ({$predicate})",
            [$tenantId, $workspaceId]
        );
    }

    /**
     * @param list<string> $params
     * @return list<string>
     */
    private function refs(string $sql, array $params): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->db->all($sql, $params)
        )));
    }

    /**
     * Rows whose `field_key` matches a person-overreach or employee-scoring pattern.
     *
     * The matcher is the SAME detector the write path screens with. A gate and the audit that
     * checks it must not disagree about what a forbidden field is — two definitions would mean the
     * audit could pass a field the gate would have refused, and neither would be wrong.
     *
     * @param list<string> $params
     * @return list<string>
     */
    private function forbiddenKeyRefs(string $sql, array $params): array
    {
        $ids = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $key = (string) $row['field_key'];
            if (PrivacyPatterns::keyMatchesAny($key, PrivacyPatterns::EMPLOYEE_SCORING)
                || PrivacyPatterns::keyMatchesAny($key, PrivacyPatterns::PERSON_OVERREACH)
            ) {
                $ids[] = (string) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function buyerIntentRefs(string $tenantId, string $workspaceId): array
    {
        $ids = [];
        $rows = $this->db->all(
            "SELECT s.released_entity_twin_snapshot_id AS id, f.field_key
             FROM released_entity_twin_snapshots s
             JOIN entity_twin_state_summaries f
               ON f.entity_twin_version_id = s.entity_twin_version_id
             WHERE s.tenant_id = ? AND s.workspace_id = ? AND f.value_status = 'KNOWN_FACT'",
            [$tenantId, $workspaceId]
        );
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row['field_key']));
            foreach (self::BUYER_INTENT_KEYS as $fragment) {
                if (str_contains($key, $fragment)) {
                    $ids[] = (string) $row['id'];
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The append-only run → check → finding, and the incident a violation opens.
     *
     * @param array{
     *     refs: list<string>, reason_code: string, finding_type: string, severity: string,
     *     trigger_module: string, incident_class: string, violation: string, control: string
     * } $spec
     */
    private function record(
        string $auditProfileId,
        string $tenantId,
        string $workspaceId,
        string $category,
        array $spec,
        string $requestedByActorType,
        ?string $requestedByActorId,
    ): Outcome {
        $violated = $spec['refs'] !== [];
        $reasonCodes = $violated ? [$spec['reason_code']] : [];
        $summary = $violated ? $spec['violation'] : $spec['control'];
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $auditProfileId, $tenantId, $workspaceId, $category, $spec, $violated, $reasonCodes,
            $summary, $requestedByActorType, $requestedByActorId, $now
        ): Outcome {
            $auditRunId = Ids::new();
            $tx->insert('audit_runs', [
                'audit_run_id' => $auditRunId,
                'schema_version' => '1',
                'audit_profile_id' => $auditProfileId,
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'audit_category' => $category,
                // The violating rows are named in the run itself. A finding that said only "three
                // violations" would leave an operator to re-derive which three.
                'target_object_refs' => json_encode($spec['refs'], JSON_THROW_ON_ERROR),
                'requested_by_actor_type' => $requestedByActorType,
                'requested_by_actor_id' => $requestedByActorId,
                'status' => 'RUNNING',
                'requested_at' => $now,
            ]);

            $checkId = Ids::new();
            $tx->insert('audit_check_records', [
                'audit_check_record_id' => $checkId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'check_key' => $category,
                'decision' => $violated ? 'FAIL' : 'PASS',
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'severity_if_failed' => $violated ? $spec['severity'] : null,
                'safe_summary' => $violated
                    ? $category . ': ' . count($spec['refs']) . ' violation(s).'
                    : $category . ': control pass.',
                'evaluated_at' => $now,
            ]);

            $findingId = Ids::new();
            $tx->insert('audit_findings', [
                'audit_finding_id' => $findingId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                // A PASS is recorded as a finding too. "The control ran and held" is evidence; an
                // absent row is indistinguishable from an audit that never ran.
                'finding_type' => $violated ? $spec['finding_type'] : 'CONTROL_PASS',
                'finding_status' => 'OPEN',
                'severity' => $violated ? $spec['severity'] : 'S1',
                'confidence_classification' => 'KNOWN',
                'current_reliance_consequence' => $violated ? 'INCIDENT_EVALUATION_REQUIRED' : 'NONE',
                'safe_finding_summary' => $summary,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'incident_evaluation_required' => $violated ? 1 : 0,
                'decision_ledger_record_required' => $violated ? 1 : 0,
                'created_at' => $now,
            ]);

            $tx->run(
                "UPDATE audit_runs SET status = 'COMPLETED', summary = ?, completed_at = ?
                 WHERE audit_run_id = ?",
                [$category . ' ' . ($violated ? 'FAIL' : 'PASS') . '.', $now, $auditRunId]
            );

            // A violation opens an incident through the CERTIFIED M11 intake, not a private one.
            // A finding nobody is required to look at is indistinguishable from no finding.
            $incidentCaseId = null;
            if ($violated) {
                $opened = $this->m11->openIncident(
                    $tenantId,
                    $workspaceId,
                    $spec['trigger_module'],
                    $reasonCodes,
                    $spec['severity'],
                    $spec['incident_class'],
                    $spec['violation'],
                    array_map(static fn (string $ref): string => $category . ':' . $ref, $spec['refs']),
                );
                if ($opened->isRefusal()) {
                    return Outcome::refuse('AUDIT_INCIDENT_NOT_OPENED', [
                        'detail' => $opened->reasonCode(),
                    ], 'POLICY');
                }
                $incidentCaseId = (string) $opened->value()['incident_case_id'];
            }

            return Outcome::ok([
                'outcome' => 'COMPLETED',
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'audit_finding_id' => $findingId,
                'audit_category' => $category,
                'violation_count' => count($spec['refs']),
                'violating_refs' => $spec['refs'],
                'reason_codes' => $reasonCodes,
                'incident_case_id' => $incidentCaseId,
            ]);
        });
    }
}
