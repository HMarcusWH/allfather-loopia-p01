<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M05;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\Scope;

/**
 * M05 — the evidence spine.
 *
 * Ported from `packages/allfather-control-substrate/src/m05/spine.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * M05 is the SOLE evidence-capture authority and the sole content owner: every producer records
 * evidence and raw artifacts through these commands, never by inserting into the tables directly.
 * That is what makes the source-admissibility law unbypassable — it runs inside `capture()`,
 * not in each producer's preamble.
 *
 * Two bindings are load-bearing and easy to lose in a port, so both are preserved:
 *  - **content binding (TF7-04).** A host-retrieval capture must cite the exact content the
 *    execution actually produced. Without it, a valid retrieval lends its authority pedigree to
 *    different content metadata, and every downstream consumer then reasons about provenance that
 *    is valid in form but bound to nothing.
 *  - **frozen admitting authority (TF7-05).** WHICH source status and sensitivity admitted the
 *    capture is stored on the evidence row. A later revocation cannot rewrite what was true when
 *    the evidence was taken, and replay can tell a lawful historical capture apart from one taken
 *    after the source was withdrawn.
 */
final class EvidenceSpine
{
    /** Bounded excerpt: an oversize artifact is REFUSED, never truncated — truncation would silently corrupt replay inputs. */
    public const RAW_ARTIFACT_EXCERPT_MAX_CHARS = 64000;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /** Register a source reference. The registry row is authority; it grants nothing on its own. */
    public function registerSource(
        string $tenantId,
        ?string $workspaceId,
        string $sourceType,
        ?string $sourceLocationSafe,
        ?string $lawfulOrPermittedUseScope,
        string $contentSensitivityClass,
        string $status = 'REGISTERED',
    ): string {
        $sourceRefId = Ids::new();
        $this->db->insert('source_references', [
            'source_ref_id' => $sourceRefId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'source_type' => $sourceType,
            'source_location_safe' => $sourceLocationSafe,
            'lawful_or_permitted_use_scope' => $lawfulOrPermittedUseScope,
            'content_sensitivity_class' => $contentSensitivityClass,
            'status' => $status,
            'registered_at' => $this->clock->now(),
        ]);

        return $sourceRefId;
    }

    /**
     * Change a source's status.
     *
     * This is the revocation lever the stand-down runbook pulls: moving a source to `REVOKED`
     * stops it admitting new captures IMMEDIATELY, because `capture()` re-reads status at capture
     * time. Evidence already captured keeps its frozen admitting authority and stays valid as
     * history.
     */
    public function setSourceStatus(string $sourceRefId, string $status): Outcome
    {
        $affected = $this->db->run(
            'UPDATE source_references SET status = ? WHERE source_ref_id = ?',
            [$status, $sourceRefId]
        );

        return $affected === 1
            ? Outcome::ok(['source_ref_id' => $sourceRefId, 'status' => $status])
            : Outcome::refuse('SOURCE_REFERENCE_NOT_FOUND');
    }

    /**
     * The single capture command.
     *
     * @param array<string, mixed> $input
     */
    public function capture(array $input): Outcome
    {
        $source = $this->db->one(
            'SELECT source_ref_id, tenant_id, workspace_id, content_sensitivity_class,
                    lawful_or_permitted_use_scope, status
             FROM source_references WHERE source_ref_id = ?',
            [$input['source_ref_id']]
        );
        if ($source === null) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_SOURCE_REFERENCE_MISSING, [], 'EVIDENCE');
        }

        $admission = SourceAdmission::evaluate(
            $source,
            (string) $input['tenant_id'],
            (string) $input['workspace_id'],
            $input['permitted_use_scope'] ?? null,
            (string) $input['sensitivity_class'],
        );
        if (!$admission['admitted']) {
            return Outcome::refuse($admission['reason_code'], [
                'source_ref_id' => (string) $input['source_ref_id'],
            ], 'EVIDENCE');
        }

        if (($input['evidence_type'] ?? null) === 'HOST_RETRIEVAL_CAPTURE') {
            $hostCheck = $this->verifyHostProvenance($input);
            if ($hostCheck !== null) {
                return $hostCheck;
            }
        }

        // Raw-artifact lineage: a cited artifact must belong to the SAME source this evidence
        // cites and carry the SAME content hash, or the evidence claims an artifact's pedigree for
        // content that artifact never held.
        if (isset($input['raw_artifact_id'])) {
            $artifact = $this->db->one(
                'SELECT source_ref_id, content_hash FROM raw_artifacts WHERE raw_artifact_id = ?',
                [$input['raw_artifact_id']]
            );
            if ($artifact === null
                || (string) $artifact['source_ref_id'] !== (string) $input['source_ref_id']
                || (string) $artifact['content_hash'] !== (string) $input['content_hash']) {
                return Outcome::refuse(SourceAdmission::EVIDENCE_RAW_ARTIFACT_NOT_BOUND, [], 'PROVENANCE');
            }
        }

        $evidenceId = Ids::new();
        $this->db->insert('evidence_objects', [
            'evidence_id' => $evidenceId,
            'schema_version' => '1',
            'tenant_id' => (string) $input['tenant_id'],
            'workspace_id' => (string) $input['workspace_id'],
            'mission_id' => $input['mission_id'] ?? null,
            'source_ref_id' => (string) $input['source_ref_id'],
            'raw_artifact_id' => $input['raw_artifact_id'] ?? null,
            'tool_request_id' => $input['tool_request_id'] ?? null,
            'tool_authorization_decision_id' => $input['tool_authorization_decision_id'] ?? null,
            'runtime_host_execution_ref' => $input['runtime_host_execution_ref'] ?? null,
            'evidence_type' => (string) $input['evidence_type'],
            'captured_content_reference_safe' => (string) $input['captured_content_reference_safe'],
            'content_hash' => (string) $input['content_hash'],
            'hash_algorithm' => 'sha256',
            'capture_method' => (string) $input['capture_method'],
            'captured_at' => $this->clock->now(),
            // The RESOLVED scope — never a caller label that contradicts the source, because the
            // predicate above already refused that case.
            'permitted_use_scope' => SourceAdmission::resolveUseScope($source, $input['permitted_use_scope'] ?? null),
            'evidence_status' => 'CAPTURED',
            'sensitivity_class' => (string) $input['sensitivity_class'],
            // TF7-05: WHICH source authority admitted this capture, FROZEN at capture time.
            'admitting_source_status' => (string) $source['status'],
            'admitting_source_sensitivity_class' => (string) $source['content_sensitivity_class'],
        ]);

        return Outcome::ok(['evidence_id' => $evidenceId]);
    }

    /**
     * Record a raw artifact. M05 stays the sole content owner.
     *
     * @param array<string, mixed> $input
     */
    public function recordRawArtifact(array $input): Outcome
    {
        $excerpt = (string) ($input['excerpt_safe'] ?? '');
        if (mb_strlen($excerpt) > self::RAW_ARTIFACT_EXCERPT_MAX_CHARS) {
            // Refused, not truncated: a silently shortened excerpt would corrupt replay inputs
            // while looking like a successful capture.
            return Outcome::refuse('RAW_ARTIFACT_EXCERPT_TOO_LARGE', [
                'max_chars' => self::RAW_ARTIFACT_EXCERPT_MAX_CHARS,
                'actual_chars' => mb_strlen($excerpt),
            ], 'EVIDENCE');
        }

        $source = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM source_references WHERE source_ref_id = ?',
            [$input['source_ref_id']]
        );
        if ($source === null) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_SOURCE_REFERENCE_MISSING, [], 'EVIDENCE');
        }

        // `raw_artifacts` carries no tenant/workspace of its own — it inherits scope from the
        // source it cites. The scope check therefore runs HERE, against the caller's scope, rather
        // than being implied by columns the row does not have.
        if (!Scope::isCompatible(
            (string) $source['tenant_id'],
            $source['workspace_id'] === null ? null : (string) $source['workspace_id'],
            (string) $input['tenant_id'],
            (string) $input['workspace_id'],
            tenantWideObjectPermitted: true,
        )) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_SCOPE_MISMATCH, [], 'SCOPE');
        }

        $rawArtifactId = Ids::new();
        $this->db->insert('raw_artifacts', [
            'raw_artifact_id' => $rawArtifactId,
            'schema_version' => '1',
            'source_ref_id' => (string) $input['source_ref_id'],
            'artifact_type' => (string) $input['artifact_type'],
            'content_hash' => (string) $input['content_hash'],
            'hash_algorithm' => 'sha256',
            'captured_at' => $this->clock->now(),
            'capture_method' => (string) $input['capture_method'],
            'storage_reference_safe' => $input['storage_reference_safe'] ?? null,
            // A BOUNDED excerpt-safe extract — never the full body, never secrets.
            'content_excerpt_safe' => $excerpt === '' ? null : $excerpt,
            'access_classification' => (string) $input['access_classification'],
        ]);

        return Outcome::ok(['raw_artifact_id' => $rawArtifactId]);
    }

    /**
     * Independently re-verify the M06/M01 trail. M05 NEVER trusts the caller's claim.
     *
     * @param array<string, mixed> $input
     */
    private function verifyHostProvenance(array $input): ?Outcome
    {
        if (empty($input['tool_request_id'])
            || empty($input['tool_authorization_decision_id'])
            || empty($input['runtime_host_execution_ref'])) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_HOST_PROVENANCE_INVALID, [], 'PROVENANCE');
        }

        $outcome = $this->db->one(
            'SELECT eo.outcome_status, eo.evidence_capture_candidate, eo.result_digest,
                    eo.result_reference_safe, d.decision,
                    r.tenant_id AS request_tenant_id, r.workspace_id AS request_workspace_id,
                    r.mission_id AS request_mission_id
             FROM tool_execution_outcomes eo
             JOIN tool_authorization_decisions d
               ON d.tool_authorization_decision_id = eo.tool_authorization_decision_id
             JOIN tool_requests r ON r.tool_request_id = eo.tool_request_id
             WHERE eo.tool_request_id = ?
               AND eo.tool_authorization_decision_id = ?
               AND eo.runtime_host_execution_ref = ?',
            [
                $input['tool_request_id'],
                $input['tool_authorization_decision_id'],
                $input['runtime_host_execution_ref'],
            ]
        );
        if ($outcome === null) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_HOST_PROVENANCE_INVALID, [], 'PROVENANCE');
        }
        if ((string) $outcome['decision'] !== 'ALLOW' || (string) $outcome['outcome_status'] !== 'SUCCEEDED') {
            return Outcome::refuse(SourceAdmission::EVIDENCE_HOST_OUTCOME_NOT_SUCCEEDED, [], 'PROVENANCE');
        }
        if ((int) $outcome['evidence_capture_candidate'] !== 1) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_HOST_OUTCOME_NOT_CAPTURE_CANDIDATE, [], 'PROVENANCE');
        }

        // Mission identity is compared with EXACT nullable equality, not "compatible if either
        // side is missing". A mission-free execution must not acquire mission-scoped provenance,
        // and a mission-scoped execution must not be cited by mission-free evidence: either would
        // let the evidence claim a lineage the execution never had.
        $requestMission = $outcome['request_mission_id'] === null ? null : (string) $outcome['request_mission_id'];
        $inputMission = $input['mission_id'] ?? null;
        if ((string) $outcome['request_tenant_id'] !== (string) $input['tenant_id']
            || (string) $outcome['request_workspace_id'] !== (string) $input['workspace_id']
            || $requestMission !== $inputMission) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_SCOPE_MISMATCH, [], 'SCOPE');
        }

        // A capture candidate without a recorded result digest cannot be bound to anything — the
        // binding is not optional, so its absence is a refusal rather than a skipped check.
        if ($outcome['result_digest'] === null
            || (string) $outcome['result_digest'] !== (string) $input['content_hash']
            || $outcome['result_reference_safe'] === null
            || (string) $outcome['result_reference_safe'] !== (string) $input['captured_content_reference_safe']) {
            return Outcome::refuse(SourceAdmission::EVIDENCE_CONTENT_NOT_BOUND_TO_EXECUTION, [], 'PROVENANCE');
        }

        return null;
    }
}
