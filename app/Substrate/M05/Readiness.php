<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M05;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

/**
 * M05 evidence readiness for a proposal.
 *
 * Ported from `packages/allfather-control-substrate/src/m05/readiness.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * This is the answer M08 consumes as its second non-overridable seed. Only
 * `SATISFIED_FOR_EVALUATION` lets an evaluation continue; every other status maps to a verdict that
 * routes away from release.
 *
 * The scope closure (TF7-07) is defence in depth, deliberately. The safe API refuses foreign items
 * at add-time and the freeze refuses them again — but a bundle contaminated by direct SQL, or
 * frozen before those laws existed, must not reach `SATISFIED_FOR_EVALUATION` on the strength of
 * its hash alone. A hash faithfully freezing an INVALID composition is exactly the case that
 * matters: readiness re-derives closure rather than inheriting the freeze's verdict.
 */
final class Readiness
{
    public const STATUS = [
        'SATISFIED_FOR_EVALUATION', 'INCOMPLETE', 'CONFLICTED', 'STALE_FOR_USE',
        'QUARANTINED', 'FORBIDDEN_USE', 'UNKNOWN',
    ];

    /**
     * Precedence for blocking evidence-object states: a quarantine outranks a forbidden-use, which
     * outranks a conflict, which outranks staleness. The FIRST match decides.
     */
    private const STATUS_PRECEDENCE = [
        ['evidence_status' => 'QUARANTINED', 'readiness' => 'QUARANTINED', 'reason' => 'EVIDENCE_QUARANTINED'],
        ['evidence_status' => 'REVOKED_FOR_USE', 'readiness' => 'FORBIDDEN_USE', 'reason' => 'EVIDENCE_USE_FORBIDDEN'],
        ['evidence_status' => 'CONFLICTED', 'readiness' => 'CONFLICTED', 'reason' => 'EVIDENCE_CONFLICT_UNRESOLVED'],
        ['evidence_status' => 'STALE_FOR_USE', 'readiness' => 'STALE_FOR_USE', 'reason' => 'EVIDENCE_STALE_FOR_USE'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     proposal_id: string, mission_id: ?string, evidence_bundle_id: ?string, bundle_hash: ?string,
     *     requirement_status: string, cited_evidence_ids: list<string>,
     *     blocking_record_refs: list<string>, reason_codes: list<string>, evaluated_at: string
     * }
     */
    public function forProposal(string $proposalId): array
    {
        $at = $this->clock->now();

        $result = static fn (
            string $status,
            array $reasons,
            ?string $missionId = null,
            ?string $bundleId = null,
            ?string $bundleHash = null,
            array $cited = [],
            array $blocking = [],
        ): array => [
            'proposal_id' => $proposalId,
            'mission_id' => $missionId,
            'evidence_bundle_id' => $bundleId,
            'bundle_hash' => $bundleHash,
            'requirement_status' => $status,
            'cited_evidence_ids' => $cited,
            'blocking_record_refs' => $blocking,
            'reason_codes' => $reasons,
            'evaluated_at' => $at,
        ];

        $proposal = $this->db->one(
            'SELECT proposal_id, tenant_id, workspace_id, mission_id, evidence_bundle_id
             FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return $result('UNKNOWN', ['PROPOSAL_NOT_FOUND']);
        }
        $missionId = (string) $proposal['mission_id'];

        // Resolve the bundle: the proposal's explicit reference, else the latest bundle bound to it.
        $bundleId = $proposal['evidence_bundle_id'] === null ? null : (string) $proposal['evidence_bundle_id'];
        if ($bundleId === null) {
            $byProposal = $this->db->one(
                'SELECT evidence_bundle_id FROM evidence_bundles WHERE proposal_id = ?
                 ORDER BY created_at DESC, evidence_bundle_id DESC LIMIT 1',
                [$proposalId]
            );
            $bundleId = $byProposal === null ? null : (string) $byProposal['evidence_bundle_id'];
        }
        if ($bundleId === null) {
            return $result('INCOMPLETE', ['EVIDENCE_BUNDLE_MISSING'], $missionId);
        }

        $bundle = $this->db->one(
            'SELECT evidence_bundle_id, status, bundle_hash, tenant_id, workspace_id, mission_id, proposal_id
             FROM evidence_bundles WHERE evidence_bundle_id = ?',
            [$bundleId]
        );
        if ($bundle === null) {
            return $result('INCOMPLETE', ['EVIDENCE_BUNDLE_MISSING'], $missionId, $bundleId);
        }
        $bundleHash = $bundle['bundle_hash'] === null ? null : (string) $bundle['bundle_hash'];

        // TF7-07 — the bundle must belong to THIS proposal's scope before its contents matter. A
        // proposal pointing at another proposal's frozen basis is not an incomplete basis; it is an
        // UNUSABLE one, so it is FORBIDDEN_USE rather than INCOMPLETE.
        if ((string) $bundle['tenant_id'] !== (string) $proposal['tenant_id']
            || (string) $bundle['workspace_id'] !== (string) $proposal['workspace_id']
            || (string) $bundle['mission_id'] !== $missionId
            || ($bundle['proposal_id'] !== null && (string) $bundle['proposal_id'] !== $proposalId)) {
            return $result('FORBIDDEN_USE', ['EVIDENCE_BUNDLE_SCOPE_NOT_CLOSED'], $missionId, $bundleId, $bundleHash, [], [$bundleId]);
        }

        $status = (string) $bundle['status'];
        if ($status === 'QUARANTINED') {
            return $result('QUARANTINED', ['EVIDENCE_QUARANTINED'], $missionId, $bundleId, $bundleHash, [], [$bundleId]);
        }
        if ($status === 'REVOKED_FOR_USE') {
            return $result('FORBIDDEN_USE', ['EVIDENCE_USE_FORBIDDEN'], $missionId, $bundleId, $bundleHash, [], [$bundleId]);
        }
        if ($status !== 'SUBMITTED') {
            // DRAFT or SUPERSEDED: there is no frozen, usable basis to evaluate.
            return $result('INCOMPLETE', ['EVIDENCE_BUNDLE_NOT_SUBMITTED'], $missionId, $bundleId, $bundleHash);
        }

        // Defence in depth: re-derive membership closure rather than trusting the freeze.
        $violations = $this->scopeViolations($bundleId);
        if ($violations !== []) {
            return $result('FORBIDDEN_USE', ['EVIDENCE_BUNDLE_SCOPE_NOT_CLOSED'], $missionId, $bundleId, $bundleHash, [], $violations);
        }

        $items = $this->db->all(
            'SELECT i.evidence_id, e.evidence_status
             FROM evidence_bundle_items i
             JOIN evidence_objects e ON e.evidence_id = i.evidence_id
             WHERE i.evidence_bundle_id = ? ORDER BY i.evidence_id',
            [$bundleId]
        );
        $cited = array_map(static fn (array $r): string => (string) $r['evidence_id'], $items);

        if ($items === []) {
            return $result('INCOMPLETE', ['EVIDENCE_BUNDLE_EMPTY'], $missionId, $bundleId, $bundleHash, $cited);
        }

        foreach (self::STATUS_PRECEDENCE as $rule) {
            $blocking = array_values(array_map(
                static fn (array $r): string => (string) $r['evidence_id'],
                array_filter($items, static fn (array $r): bool => (string) $r['evidence_status'] === $rule['evidence_status'])
            ));
            if ($blocking !== []) {
                return $result($rule['readiness'], [$rule['reason']], $missionId, $bundleId, $bundleHash, $cited, $blocking);
            }
        }

        // Blocking missingness recorded against this proposal or its mission. A KNOWN gap that
        // blocks release is not the same as having no evidence — it is evidence that something
        // required is absent, and it holds the release just as firmly.
        $missingness = $this->db->all(
            'SELECT missingness_id FROM evidence_missingness_records
             WHERE blocks_release_or_action = 1 AND (proposal_id = ? OR (proposal_id IS NULL AND mission_id = ?))',
            [$proposalId, $missionId]
        );
        if ($missingness !== []) {
            return $result(
                'INCOMPLETE',
                ['EVIDENCE_MISSINGNESS_BLOCKS_RELEASE'],
                $missionId, $bundleId, $bundleHash, $cited,
                array_map(static fn (array $r): string => (string) $r['missingness_id'], $missingness),
            );
        }

        return $result('SATISFIED_FOR_EVALUATION', [], $missionId, $bundleId, $bundleHash, $cited);
    }

    /**
     * Evidence in a bundle whose scope does not match the bundle's own.
     *
     * @return list<string>
     */
    public function scopeViolations(string $bundleId): array
    {
        $rows = $this->db->all(
            'SELECT e.evidence_id
             FROM evidence_bundle_items i
             JOIN evidence_objects e ON e.evidence_id = i.evidence_id
             JOIN evidence_bundles b ON b.evidence_bundle_id = i.evidence_bundle_id
             WHERE i.evidence_bundle_id = ?
               AND (e.tenant_id <> b.tenant_id
                    OR e.workspace_id <> b.workspace_id
                    OR (e.mission_id IS NOT NULL AND e.mission_id <> b.mission_id))
             ORDER BY e.evidence_id',
            [$bundleId]
        );

        return array_map(static fn (array $r): string => (string) $r['evidence_id'], $rows);
    }
}
