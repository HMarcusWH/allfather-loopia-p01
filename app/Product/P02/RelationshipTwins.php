<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P02 relationship twins — an EDGE between two organizations.
 *
 * "Rival Industries supplies Northern Logistics." That is a claim about the world, and the reason
 * this module is careful is that an edge is much easier to assert than to support. Two companies
 * appearing in the same article is not a supply relationship; a claim that they have one is a claim
 * somebody has to be able to defend.
 *
 * SO EVERY VERSION OF AN EDGE MUST CITE EVIDENCE. Not "should" — a version with no evidence link is
 * refused. An edge that could exist unsupported would let the graph fill with plausible-sounding
 * relationships nobody observed, and a graph like that is worse than no graph: it looks like
 * knowledge.
 *
 * The other invariants follow from the same concern:
 *
 *   AN EDGE HAS TWO DISTINCT ENDS. A self-edge is storage-refused. "X competes with X" is not a
 *   weak claim, it is not a claim.
 *
 *   CONFIDENCE AND FRESHNESS TRAVEL WITH THE EDGE. A relationship observed once, two years ago, at
 *   LOW confidence is a different thing from one confirmed last week — and an edge that recorded
 *   only its existence would flatten them into the same assertion.
 *
 *   THE UNCERTAINTY SUMMARY IS REQUIRED, as on entity twins and for the same reason.
 *
 * Versions are append-only. An edge changes by gaining a version.
 */
final class RelationshipTwins
{
    /**
     * The closed set of visibility scopes and release statuses, shared with the twin path.
     *
     * Deliberately the SAME constants: a scope one half of P02 accepts and the other rejects is a
     * hole in a default-deny that neither module's own tests would see.
     */
    private readonly M10BindingFloor $floor;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        ?M10BindingFloor $floor = null,
    ) {
        $this->floor = $floor ?? new M10BindingFloor($db);
    }

    /**
     * Create an edge between two twins.
     *
     * Both ends must exist IN THE SAME SCOPE. An edge reaching across a tenant boundary would
     * assert a relationship one side could not see — and the side that could not see it would have
     * no way to contest it.
     */
    public function createEdge(
        string $tenantId,
        string $workspaceId,
        string $sourceTwinId,
        string $targetTwinId,
        string $relationshipType,
        string $direction = 'SOURCE_TO_TARGET',
        // Upstream's `initiatedByActorId?: string`. Optional there and optional here; what it must
        // NOT do is fall back to the edge's own id when nobody supplies one.
        ?string $createdByActorId = null,
    ): Outcome {
        if ($sourceTwinId === $targetTwinId) {
            // Refused here AND storage-pinned. "X competes with X" is not a weak claim; it is not a
            // claim, and it would corrupt every traversal that assumed edges connect two things.
            return Outcome::refuse('RELATIONSHIP_SELF_EDGE', [], 'POLICY');
        }

        foreach (['source' => $sourceTwinId, 'target' => $targetTwinId] as $end => $twinId) {
            $twin = $this->db->one(
                'SELECT tenant_id, workspace_id, status FROM entity_twins WHERE entity_twin_id = ?',
                [$twinId]
            );
            if ($twin === null) {
                return Outcome::refuse('RELATIONSHIP_END_NOT_FOUND', ['end' => $end], 'PROVENANCE');
            }
            if ((string) $twin['tenant_id'] !== $tenantId || (string) $twin['workspace_id'] !== $workspaceId) {
                return Outcome::refuse('RELATIONSHIP_END_SCOPE_MISMATCH', ['end' => $end], 'SCOPE', 'S4');
            }
            if (!in_array((string) $twin['status'], ['ACTIVE', 'DRAFT', 'UNDER_REVIEW'], true)) {
                return Outcome::refuse('RELATIONSHIP_END_NOT_LIVE', [
                    'end' => $end, 'status' => (string) $twin['status'],
                ], 'POLICY');
            }
        }

        $edgeId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $edgeId, $tenantId, $workspaceId, $sourceTwinId, $targetTwinId,
            $relationshipType, $direction, $now, $createdByActorId
        ): Outcome {
            $tx->insert('entity_relationship_edges', [
                'relationship_edge_id' => $edgeId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'source_entity_twin_id' => $sourceTwinId,
                'target_entity_twin_id' => $targetTwinId,
                'relationship_type' => $relationshipType,
                'direction' => $direction,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tx->insert('entity_relationship_status_history', [
                'relationship_status_history_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'relationship_edge_id' => $edgeId,
                'from_status' => null,
                'to_status' => 'ACTIVE',
                'reason_code' => 'CREATED',
                // Upstream hardcodes SYSTEM as the actor TYPE on this history table and defaults the actor
                // ID to the string "system" — `input.initiatedByActorId ?? "system"`. The port used
                // the edge's OWN id, so a row asked "who put this here?" answered with the thing
                // that was put there. It is the same fault the M10 writer column had, and the two
                // sibling release paths in this file already do it correctly.
                'initiated_by_actor_type' => 'SYSTEM',
                'initiated_by_actor_id' => $createdByActorId ?? 'system',
                'transitioned_at' => $now,
            ]);

            return Outcome::ok(['relationship_edge_id' => $edgeId]);
        });
    }

    /**
     * Append a version of an edge's state — its confidence, its freshness, its support.
     *
     * EVIDENCE IS REQUIRED. An edge that could exist unsupported would let the graph fill with
     * plausible-sounding relationships nobody observed, and a graph like that is worse than no
     * graph: it looks like knowledge.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, relationship_edge_id: string,
     *     confidence: string, freshness_observed_at: string, business_relevance: string,
     *     allowed_use: string, uncertainty_summary_safe: string, claim_ceiling: string,
     *     evidence_links: list<array{evidence_id?: ?string, source_ref_id?: ?string, source_basis: string}>
     * } $input
     */
    public function appendVersion(array $input): Outcome
    {
        $edge = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM entity_relationship_edges
             WHERE relationship_edge_id = ?',
            [$input['relationship_edge_id']]
        );
        if ($edge === null) {
            return Outcome::refuse('RELATIONSHIP_EDGE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $edge['tenant_id'] !== $input['tenant_id']
            || (string) $edge['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('RELATIONSHIP_EDGE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        // THE SUPPORT REQUIREMENT. An edge asserts something about the world; a version of it with
        // nothing behind it asserts the same thing with nothing behind it.
        if (($input['evidence_links'] ?? []) === []) {
            return Outcome::refuse('RELATIONSHIP_EDGE_UNSUPPORTED', [], 'EVIDENCE', 'S3', 'BLOCK_RELEASE');
        }
        if (trim($input['uncertainty_summary_safe']) === '') {
            return Outcome::refuse('RELATIONSHIP_UNCERTAINTY_SUMMARY_REQUIRED', [], 'POLICY');
        }

        // Every cited evidence object must be IN SCOPE. An edge supported by evidence the workspace
        // cannot see is an edge nobody there can check.
        foreach ($input['evidence_links'] as $link) {
            $evidenceId = $link['evidence_id'] ?? null;
            if ($evidenceId === null) {
                continue;
            }
            $evidence = $this->db->one(
                'SELECT tenant_id, workspace_id FROM evidence_objects WHERE evidence_id = ?',
                [$evidenceId]
            );
            if ($evidence === null
                || (string) $evidence['tenant_id'] !== $input['tenant_id']
                || (string) $evidence['workspace_id'] !== $input['workspace_id']
            ) {
                return Outcome::refuse('RELATIONSHIP_EVIDENCE_SCOPE_MISMATCH', [
                    'evidence_id' => $evidenceId,
                ], 'SCOPE', 'S4');
            }
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($input, $now): Outcome {
            $sequence = 1 + (int) $tx->scalar(
                'SELECT COALESCE(MAX(version_sequence), 0) FROM entity_relationship_versions
                 WHERE relationship_edge_id = ?',
                [$input['relationship_edge_id']]
            );
            // The digest binds the CLAIM — its confidence, its freshness, its ceiling — not just the
            // fact of the edge. Two versions asserting the same relationship at different confidence
            // are different claims and must have different identities.
            $versionDigest = Digest::canonicalDigest([
                'relationshipEdgeId' => $input['relationship_edge_id'],
                'versionSequence' => $sequence,
                'confidence' => $input['confidence'],
                'freshnessObservedAt' => $input['freshness_observed_at'],
                'businessRelevance' => $input['business_relevance'],
                'allowedUse' => $input['allowed_use'],
                'claimCeiling' => $input['claim_ceiling'],
            ]);

            $versionId = Ids::new();
            $tx->insert('entity_relationship_versions', [
                'relationship_version_id' => $versionId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'relationship_edge_id' => $input['relationship_edge_id'],
                'version_sequence' => $sequence,
                // Confidence and freshness travel WITH the claim. An edge observed once two years
                // ago at LOW confidence is a different thing from one confirmed last week, and an
                // edge recording only its existence would flatten them into the same assertion.
                'confidence' => $input['confidence'],
                'freshness_observed_at' => $input['freshness_observed_at'],
                'business_relevance' => $input['business_relevance'],
                'allowed_use' => $input['allowed_use'],
                'uncertainty_summary_safe' => $input['uncertainty_summary_safe'],
                'claim_ceiling' => $input['claim_ceiling'],
                'version_digest' => $versionDigest,
                'created_at' => $now,
            ]);

            foreach ($input['evidence_links'] as $link) {
                $tx->insert('entity_relationship_evidence_links', [
                    'relationship_evidence_link_id' => Ids::new(),
                    'schema_version' => '1',
                    'tenant_id' => $input['tenant_id'],
                    'relationship_version_id' => $versionId,
                    'evidence_id' => $link['evidence_id'] ?? null,
                    'source_ref_id' => $link['source_ref_id'] ?? null,
                    'source_basis' => $link['source_basis'],
                    'created_at' => $now,
                ]);
            }

            $tx->run(
                'UPDATE entity_relationship_edges SET updated_at = ?, last_reviewed_at = ?
                 WHERE relationship_edge_id = ?',
                [$now, $input['freshness_observed_at'], $input['relationship_edge_id']]
            );

            return Outcome::ok([
                'relationship_version_id' => $versionId,
                'version_sequence' => $sequence,
                'version_digest' => $versionDigest,
            ]);
        });
    }

    /**
     * The relationship storage release gate — validate, then write the immutable snapshot.
     *
     * Callers already inside a transaction use {@see releaseSnapshotTxFree}; this opens one.
     *
     * @param array<string, mixed> $input
     */
    public function releaseSnapshot(array $input): Outcome
    {
        return $this->db->transaction(fn (): Outcome => $this->releaseSnapshotTxFree($input));
    }

    /**
     * The same gate WITHOUT opening a transaction — the caller must already be inside one.
     *
     * Every refusal returns BEFORE any write. The gate, in order:
     *
     *   the version exists, and is the caller's        — scope
     *   the version is not already released            — one fresh release per version
     *   scope and status are known                     — closed vocabularies, default-deny
     *   the claim ceiling is not withdrawn             — SUPERSEDED and RETRACTED stay withdrawn
     *   at least one evidence link exists              — an edge nobody cited is an assertion
     *   a supersede target is a prior snapshot of THIS edge
     *   the M10 binding floor                          — last, and typed
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, relationship_version_id: string,
     *     visibility_scope: string, release_status: string, decision_record_id?: ?string,
     *     supersedes_release_id?: ?string, released_by_actor_id?: ?string
     * } $input
     */
    public function releaseSnapshotTxFree(array $input): Outcome
    {
        $version = $this->db->one(
            'SELECT relationship_edge_id, tenant_id, workspace_id, version_sequence, claim_ceiling,
                    version_digest
             FROM entity_relationship_versions WHERE relationship_version_id = ?',
            [$input['relationship_version_id']]
        );
        if ($version === null) {
            return Outcome::refuse('VERSION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $version['tenant_id'] !== $input['tenant_id']
            || (string) $version['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        // One fresh release per version. Storage says so too with a unique index; this names the
        // condition rather than letting the caller meet a driver error.
        $already = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM released_entity_relationship_snapshots WHERE relationship_version_id = ?',
            [$input['relationship_version_id']]
        );
        if ($already > 0) {
            return Outcome::refuse('ALREADY_RELEASED_VERSION', [], 'POLICY');
        }

        if (!in_array($input['visibility_scope'], EntityTwins::VISIBILITY_SCOPES, true)) {
            return Outcome::refuse('VISIBILITY_RULE_MISSING', [
                'visibility_scope' => $input['visibility_scope'],
            ], 'POLICY');
        }
        if (!in_array($input['release_status'], EntityTwins::RELEASE_STATUSES, true)) {
            return Outcome::refuse('INVALID_RELEASE_STATUS', [
                'release_status' => $input['release_status'],
            ], 'POLICY');
        }
        if (in_array((string) $version['claim_ceiling'], ['SUPERSEDED', 'RETRACTED'], true)) {
            return Outcome::refuse('RELEASE_CEILING_INVALID', [
                'claim_ceiling' => (string) $version['claim_ceiling'],
            ], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }

        // An edge is a CLAIM about two companies. One that cites nothing is an assertion, and the
        // arbiter's own rule refuses it too — this is the storage-side half of the same invariant.
        $evidence = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM entity_relationship_evidence_links WHERE relationship_version_id = ?',
            [$input['relationship_version_id']]
        );
        if ($evidence === 0) {
            return Outcome::refuse('RELATIONSHIP_EDGE_UNSUPPORTED', [], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }

        $supersedes = $input['supersedes_release_id'] ?? null;
        if ($supersedes !== null) {
            $prior = $this->db->one(
                'SELECT relationship_edge_id, tenant_id FROM released_entity_relationship_snapshots
                 WHERE released_relationship_snapshot_id = ?',
                [$supersedes]
            );
            if ($prior === null
                || (string) $prior['tenant_id'] !== $input['tenant_id']
                || (string) $prior['relationship_edge_id'] !== (string) $version['relationship_edge_id']
            ) {
                return Outcome::refuse('SUPERSEDE_TARGET_NOT_FOUND', [], 'PROVENANCE', 'S4');
            }
        }

        $floor = $this->floor->check(
            $input['tenant_id'],
            $input['workspace_id'],
            $input['decision_record_id'] ?? null,
        );
        if (!$floor['ok']) {
            return Outcome::refuse($floor['reason_code'], [], 'PROVENANCE', 'S4', 'BLOCK_RELEASE');
        }

        $snapshotId = Ids::new();
        $snapshotDigest = Digest::canonicalDigest([
            'relationshipEdgeId' => (string) $version['relationship_edge_id'],
            'versionSequence' => (int) $version['version_sequence'],
            'versionDigest' => (string) $version['version_digest'],
            'claimCeiling' => (string) $version['claim_ceiling'],
            'visibilityScope' => $input['visibility_scope'],
            'releaseStatus' => $input['release_status'],
            'supersedesReleaseId' => $supersedes,
        ]);
        $now = $this->clock->now();

        $this->db->insert('released_entity_relationship_snapshots', [
            'released_relationship_snapshot_id' => $snapshotId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'relationship_edge_id' => (string) $version['relationship_edge_id'],
            'relationship_version_id' => $input['relationship_version_id'],
            'claim_ceiling' => (string) $version['claim_ceiling'],
            'visibility_scope' => $input['visibility_scope'],
            'snapshot_digest' => $snapshotDigest,
            'release_status' => $input['release_status'],
            'supersedes_release_id' => $supersedes,
            'decision_record_id' => $input['decision_record_id'],
            'released_at' => $now,
            'released_by_actor_id' => $input['released_by_actor_id'] ?? null,
        ]);

        $this->db->insert('entity_relationship_status_history', [
            'relationship_status_history_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'relationship_edge_id' => (string) $version['relationship_edge_id'],
            'from_status' => null,
            'to_status' => 'ACTIVE',
            'reason_code' => $supersedes !== null ? 'SNAPSHOT_SUPERSEDED' : 'SNAPSHOT_RELEASED',
            // SYSTEM, as upstream's appendRelationshipStatusHistory hardcodes for every row.
            'initiated_by_actor_type' => 'SYSTEM',
            'initiated_by_actor_id' => $input['released_by_actor_id'] ?? 'system',
            'transitioned_at' => $now,
        ]);
        $this->db->run(
            "UPDATE entity_relationship_edges SET status = 'ACTIVE', updated_at = ?
             WHERE relationship_edge_id = ?",
            [$now, (string) $version['relationship_edge_id']]
        );

        return Outcome::ok([
            'outcome' => 'RELEASED',
            'released_relationship_snapshot_id' => $snapshotId,
            'snapshot_digest' => $snapshotDigest,
        ]);
    }
}
