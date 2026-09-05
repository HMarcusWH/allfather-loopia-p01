<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P05;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The three P05 audits.
 *
 *   MARKETING_EVIDENCE_CITATION    Does every comparative claim rest on evidence that is real,
 *                                  in scope, fresh, AND actually about this draft? The admission
 *                                  gate checks the first two; this checks all four, because a
 *                                  citation can be a real evidence object and still be evidence
 *                                  for something else.
 *
 *   MARKETING_DRAFT_ONLY_INVARIANT Has anything external happened? Two things would show it: a
 *                                  status transition naming a publish, send, dispatch or CRM
 *                                  writeback, or a released row whose draft is not
 *                                  RELEASED_INTERNAL. There is no code path here that could write
 *                                  either — which is exactly why the audit looks for them. The
 *                                  interesting failure is the one no code path explains.
 *
 *   MARKETING_CONTENT_COMPLIANCE   Does any draft OR VARIANT carry a forbidden pattern? Variants
 *                                  matter as much as drafts: copy that never went through the
 *                                  admission gate is still copy somebody will read.
 *
 * All three READ. None repairs. A failing audit opens an incident through the certified M11 intake.
 */
final class Assurance
{
    /**
     * Evidence statuses a citation may rest on.
     *
     * Stale, conflicted, quarantined or revoked evidence is not weaker support for a comparative
     * claim; it is support the organization has already stopped standing behind.
     *
     * @var list<string>
     */
    private const CITABLE_EVIDENCE_STATUSES = ['CAPTURED', 'ASSESSED'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /**
     * Does every comparative claim rest on evidence that is real, in scope, fresh and related?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runEvidenceCitedClaimAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'MARKETING_EVIDENCE_CITATION');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];

        $drafts = $this->db->all(
            'SELECT marketing_asset_draft_id, draft_content_safe FROM marketing_asset_drafts
             WHERE tenant_id = ? AND workspace_id = ?',
            [$profile['tenant_id'], $profile['workspace_id']]
        );

        foreach ($drafts as $draft) {
            $draftId = (string) $draft['marketing_asset_draft_id'];
            $requiresEvidence = MarketingPatterns::hasComparativeClaim((string) $draft['draft_content_safe']);

            $citations = $this->db->all(
                'SELECT c.marketing_claim_citation_id, c.claim_text_safe, c.cited_evidence_id,
                        e.tenant_id AS evidence_tenant_id, e.workspace_id AS evidence_workspace_id,
                        e.evidence_status, e.evidence_type, e.permitted_use_scope,
                        sr.source_location_safe
                 FROM marketing_claim_citations c
                 LEFT JOIN evidence_objects e ON e.evidence_id = c.cited_evidence_id
                 LEFT JOIN source_references sr ON sr.source_ref_id = e.source_ref_id
                 WHERE c.marketing_asset_draft_id = ? AND c.tenant_id = ?',
                [$draftId, $profile['tenant_id']]
            );
            // Only citations whose OWN text is comparative count. A draft comparing itself to a
            // competitor and citing evidence for an unrelated claim has cited nothing for the
            // claim that needed it.
            $comparative = array_values(array_filter(
                $citations,
                static fn (array $c): bool => MarketingPatterns::hasComparativeClaim((string) $c['claim_text_safe'])
            ));

            if ($requiresEvidence && $comparative === []) {
                $refs[] = $draftId;
                $reasons['P05_CITATION_REQUIRED_CLAIM_UNCITED'] = true;
                continue;
            }

            // Each citation is judged on its own, and the FIRST failing condition names it. A
            // citation that is both out of scope and stale is reported as out of scope, because
            // that is the more fundamental problem.
            $hasValid = false;
            foreach ($comparative as $citation) {
                $invalidReason = $this->citationDefect($citation, $profile, $draftId);
                if ($invalidReason === null) {
                    $hasValid = true;
                    continue;
                }
                $refs[] = (string) $citation['marketing_claim_citation_id'];
                $reasons[$invalidReason] = true;
            }
            if ($requiresEvidence && !$hasValid) {
                $refs[] = $draftId;
                $reasons['P05_CITATION_REQUIRED_CLAIM_UNCITED'] = true;
            }
        }

        $refs = array_values(array_unique($refs));
        sort($refs);
        $reasonCodes = array_keys($reasons);
        sort($reasonCodes);

        return $this->finish(
            $profile,
            'MARKETING_EVIDENCE_CITATION',
            'P05_EVIDENCE_CITED_CLAIM_INVARIANT',
            $refs,
            $reasonCodes,
            'EVIDENCE_OR_AUTHORITY_GAP',
            'S3',
            'DISCLOSURE_OR_OVERCLAIM',
            'A comparative marketing claim lacks a valid in-scope, fresh, related evidence citation.',
            'Every comparative marketing claim cites valid in-scope, fresh, related governed evidence.',
        );
    }

    /**
     * Has anything external happened?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runDraftOnlyInvariantAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'MARKETING_DRAFT_ONLY_INVARIANT');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $reasons = [];

        // The transition ledger is where an external act would leave its trace. Both the status it
        // moved TO and the reason it gives are scanned: a publish recorded under an innocuous
        // status would still name itself in the reason.
        $external = $this->db->all(
            "SELECT t.transition_id
             FROM marketing_asset_draft_status_transitions t
             JOIN marketing_asset_drafts d ON d.marketing_asset_draft_id = t.marketing_asset_draft_id
             WHERE d.tenant_id = ? AND d.workspace_id = ?
               AND (UPPER(COALESCE(t.to_status, '')) IN ('PUBLISHED', 'SENT', 'DISPATCHED', 'CRM_WRITEBACK')
                 OR UPPER(COALESCE(t.reason_code, '')) LIKE '%PUBLISH%'
                 OR UPPER(COALESCE(t.reason_code, '')) LIKE '%SEND%'
                 OR UPPER(COALESCE(t.reason_code, '')) LIKE '%DISPATCH%'
                 OR UPPER(COALESCE(t.reason_code, '')) LIKE '%CRM%')",
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        foreach ($external as $row) {
            $refs[] = (string) $row['transition_id'];
            $reasons['P05_ILLEGAL_EXTERNAL_STATUS_TRANSITION'] = true;
        }

        // A released row whose draft is not RELEASED_INTERNAL, or whose scope disagrees with the
        // release's. Either is a released asset the release path could not have produced.
        $drift = $this->db->all(
            "SELECT r.released_marketing_asset_id
             FROM released_marketing_assets r
             JOIN marketing_asset_drafts d ON d.marketing_asset_draft_id = r.marketing_asset_draft_id
             WHERE r.tenant_id = ? AND r.workspace_id = ?
               AND (d.asset_status <> 'RELEASED_INTERNAL'
                 OR d.tenant_id <> r.tenant_id
                 OR d.workspace_id <> r.workspace_id)",
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        foreach ($drift as $row) {
            $refs[] = (string) $row['released_marketing_asset_id'];
            $reasons['P05_RELEASED_ASSET_NOT_INTERNAL'] = true;
        }

        $refs = array_values(array_unique($refs));
        sort($refs);
        $reasonCodes = array_keys($reasons);
        sort($reasonCodes);

        return $this->finish(
            $profile,
            'MARKETING_DRAFT_ONLY_INVARIANT',
            'P05_DRAFT_ONLY_INVARIANT',
            $refs,
            $reasonCodes,
            'RELEASE_BLOCKING_CONDITION',
            // S4, higher than the other two. An external effect in a plane with no external path
            // is not a content problem; it is evidence that something is writing rows nothing in
            // this codebase can explain.
            'S4',
            'UNKNOWN_OR_UNEXPECTED_EFFECT',
            'A marketing asset shows external publish, send, dispatch or CRM drift, or a released row that is not internal.',
            'Marketing assets remain internal drafts or RELEASED_INTERNAL records.',
        );
    }

    /**
     * Does any draft or variant carry a forbidden pattern?
     *
     * @param array{audit_profile_id: string} $input
     */
    public function runForbiddenPatternAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'MARKETING_CONTENT_COMPLIANCE');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $refs = [];
        $rows = [
            ...$this->db->all(
                'SELECT marketing_asset_draft_id AS id, draft_content_safe AS content
                 FROM marketing_asset_drafts WHERE tenant_id = ? AND workspace_id = ?',
                [$profile['tenant_id'], $profile['workspace_id']]
            ),
            // VARIANTS TOO. A variant never passes through the admission gate on its own, so it is
            // the likelier place for non-compliant copy to sit unnoticed.
            ...$this->db->all(
                'SELECT v.marketing_asset_variant_id AS id, v.variant_content_safe AS content
                 FROM marketing_asset_variants v
                 JOIN marketing_asset_drafts d ON d.marketing_asset_draft_id = v.marketing_asset_draft_id
                 WHERE d.tenant_id = ? AND d.workspace_id = ?',
                [$profile['tenant_id'], $profile['workspace_id']]
            ),
        ];
        foreach ($rows as $row) {
            if (MarketingPatterns::hasForbiddenPattern((string) $row['content'])) {
                $refs[] = (string) $row['id'];
            }
        }
        sort($refs);

        return $this->finish(
            $profile,
            'MARKETING_CONTENT_COMPLIANCE',
            'P05_FORBIDDEN_MARKETING_PATTERN_INVARIANT',
            $refs,
            $refs === [] ? [] : ['P05_FORBIDDEN_MARKETING_PATTERN_PERSISTED'],
            'OVERCLAIM_OR_UI_MISREPRESENTATION',
            'S3',
            'DISCLOSURE_OR_OVERCLAIM',
            'A marketing draft or variant carries a dark-pattern, deception or sensitive-trait-targeting marker.',
            'No marketing draft or variant carries a forbidden pattern.',
        );
    }

    /**
     * What is wrong with this citation, if anything.
     *
     * Ordered from most to least fundamental, and the FIRST match names it. A citation that points
     * at nothing is not also "stale" — it has no status to be stale.
     *
     * @param array<string, mixed> $citation
     * @param array<string, string> $profile
     */
    private function citationDefect(array $citation, array $profile, string $draftId): ?string
    {
        if ($citation['evidence_tenant_id'] === null) {
            return 'P05_CITED_EVIDENCE_INVALID';
        }
        if ((string) $citation['evidence_tenant_id'] !== $profile['tenant_id']
            || (string) $citation['evidence_workspace_id'] !== $profile['workspace_id']
        ) {
            return 'P05_CITED_EVIDENCE_SCOPE_MISMATCH';
        }
        if (!in_array((string) $citation['evidence_status'], self::CITABLE_EVIDENCE_STATUSES, true)) {
            return 'P05_CITED_EVIDENCE_STALE';
        }
        if (!self::hasClaimLineage($citation, $draftId)) {
            return 'P05_CITED_EVIDENCE_UNRELATED';
        }

        return null;
    }

    /**
     * Is this evidence actually ABOUT this draft?
     *
     * Two shapes qualify: the draft's own admission basis, and a claim basis minted for this draft.
     * Both are identified by the source-location convention the producers write, so the audit is
     * checking a fact about the evidence's provenance rather than trusting a label on it.
     *
     * A real, in-scope, fresh evidence object about a DIFFERENT draft fails here — which is the
     * whole point. Citing something true that is not about this claim is still not citing this
     * claim.
     *
     * @param array<string, mixed> $citation
     */
    private static function hasClaimLineage(array $citation, string $draftId): bool
    {
        $location = $citation['source_location_safe'] === null
            ? ''
            : (string) $citation['source_location_safe'];
        $type = (string) $citation['evidence_type'];
        $scope = (string) $citation['permitted_use_scope'];

        if ($type === 'MARKETING_ASSET_DRAFT_BASIS_REFERENCE'
            && $scope === 'marketing_asset_draft_admission'
            && $location === 'marketing-asset-draft-basis:' . $draftId
        ) {
            return true;
        }

        return $type === 'DERIVED_ANNOTATION_REFERENCE'
            && $scope === 'marketing_claim_basis'
            && str_starts_with($location, 'marketing-claim-basis:' . $draftId . ':');
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
        string $severity,
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
