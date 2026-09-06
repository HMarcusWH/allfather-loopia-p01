<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M16;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P02's adoption of M16 — the snapshot-view artifact composed inside the post-M10 transaction.
 *
 * This is ADDITIVE to the governed P02 release. M10 still owns the release; the released snapshot
 * row is still the product's record. What this adds is the frozen ARTIFACT and its audience
 * projections: the thing an operator or an authorized colleague actually reads.
 *
 * THREE OUTCOMES, AND THE DIFFERENCE BETWEEN THEM IS THE WHOLE DESIGN:
 *
 *   COMPOSED  the artifact is frozen, both projections exist, internal visibility is activated.
 *   HELD      the artifact is frozen and the OPERATOR hold-view exists — and nothing wider does.
 *             This is a governed ANSWER, not a failure: the sources could not clear a tenant-wide
 *             read, so the release stands and only an operator can see it.
 *   REFUSED   the composition could not be made at all. The caller rolls the post-M10 unit back.
 *
 * Treating HELD as a failure would throw away a real governed output and roll back a release the
 * arbiter allowed. Treating REFUSED as a hold would leave a release bound to an artifact that does
 * not exist. Both mistakes are silent, which is why the outcome is returned as a name.
 *
 * WHY A TWIN'S EXTERNAL SOURCES HOLD. Twin source links carry no governed licence card — there is
 * no card discipline for twin sources yet. So every non-tenant basis compiles as a CARDLESS
 * external contribution, which holds the profile by design. The tenant's OWN material folds
 * separately at the top of the lattice and never drags the artifact down with it.
 */
final class P02Adapter
{
    /**
     * Source bases that are the TENANT'S OWN material.
     *
     * These fold into ONE first-party contribution: they are not external claims needing a licence,
     * they are what the tenant already had.
     *
     * @var list<string>
     */
    private const TENANT_BASES = ['TENANT_OWNED', 'TENANT_PROVIDED_MANUAL', 'PRIOR_RELEASED_SNAPSHOT'];

    /** Least- to most-sensitive, for the conservative projection over contributing evidence. */
    private const SENSITIVITY_ORDER = [
        'PUBLIC_OR_NON_SENSITIVE', 'INTERNAL_DEMO', 'RESTRICTED_REVIEW', 'PROHIBITED_IN_V1',
    ];

    /** Evidence in either of these states cannot widen an output. */
    private const UNUSABLE_EVIDENCE_STATUSES = ['QUARANTINED', 'REVOKED_FOR_USE'];

    public function __construct(
        private readonly Database $db,
        private readonly ReleaseOutput $m16,
    ) {
    }

    /**
     * Compose the ENTITY_TWIN_SNAPSHOT_VIEW for a released twin snapshot.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string,
     *     released_entity_twin_snapshot_id: string, decision_record_id: string
     * } $input
     */
    public function composeTwinSnapshotArtifact(array $input): Outcome
    {
        $snap = $this->db->one(
            'SELECT s.entity_twin_version_id, s.release_status, s.visibility_scope,
                    v.version_digest, v.created_at AS observed_at,
                    u.summary_safe AS uncertainty, u.conflict_summary_safe AS conflict,
                    t.display_name AS twin_name
             FROM released_entity_twin_snapshots s
             JOIN entity_twin_versions v ON v.entity_twin_version_id = s.entity_twin_version_id
             JOIN entity_twin_uncertainty_summaries u
               ON u.entity_twin_uncertainty_summary_id = s.entity_twin_uncertainty_summary_id
             JOIN entity_twins t ON t.entity_twin_id = s.entity_twin_id
             WHERE s.released_entity_twin_snapshot_id = ?',
            [$input['released_entity_twin_snapshot_id']]
        );
        if ($snap === null) {
            return Outcome::refuse('SNAPSHOT_NOT_FOUND', [], 'PROVENANCE');
        }

        $links = $this->db->all(
            'SELECT source_basis, allowed_use, evidence_id, source_ref_id
             FROM entity_twin_source_links WHERE entity_twin_version_id = ?',
            [(string) $snap['entity_twin_version_id']]
        );

        $built = $this->contributionsFromSourceLinks(
            $input['tenant_id'],
            $input['workspace_id'],
            'twin:' . (string) $snap['entity_twin_version_id'],
            $links,
        );

        $elements = [
            [
                'ordinal' => 0,
                'elementClass' => 'SAFE_SUMMARY',
                'textSafe' => 'Released state snapshot of the entity twin "'
                    . (string) $snap['twin_name'] . '".',
                'fieldsSafe' => ['visibilityScope' => (string) $snap['visibility_scope']],
            ],
            [
                'ordinal' => 1,
                'elementClass' => 'UNCERTAINTY_BLOCK',
                'textSafe' => (string) $snap['uncertainty'],
            ] + ($snap['conflict'] === null
                ? []
                : ['fieldsSafe' => ['conflictSummary' => (string) $snap['conflict']]]),
            [
                'ordinal' => 2,
                'elementClass' => 'FRESHNESS_BLOCK',
                'textSafe' => "Freshness reflects the released version's recording clock.",
                'fieldsSafe' => ['observedAt' => (string) $snap['observed_at']],
            ],
            [
                'ordinal' => 3,
                'elementClass' => 'LIMITATION_NOTE',
                'textSafe' => 'Internal entity-twin state view; bounded model — unknowns stay unknown.',
            ],
        ];

        return $this->compose([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'artifact_kind' => 'ENTITY_TWIN_SNAPSHOT_VIEW',
            'consumer_column' => 'released_entity_twin_snapshot_id',
            'consumer_id' => $input['released_entity_twin_snapshot_id'],
            'decision_record_id' => $input['decision_record_id'],
            'claim_ceiling' => (string) $snap['release_status'],
            'internal_scope_lens' => (string) $snap['visibility_scope'],
            'elements' => $elements,
            'contributions' => $built['contributions'],
            'evidence_ids' => $built['evidence_ids'],
            'uncertainty_summary_safe' => (string) $snap['uncertainty'],
        ]);
    }

    /**
     * Compose the ENTITY_RELATIONSHIP_SNAPSHOT_VIEW for a released relationship snapshot.
     *
     * Relationship evidence links carry no `allowed_use` column, so external bases take the narrow
     * `EVIDENCE_REFERENCE_ONLY` reading: a derived summary is not granted by silence.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string,
     *     released_relationship_snapshot_id: string, decision_record_id: string
     * } $input
     */
    public function composeRelationshipSnapshotArtifact(array $input): Outcome
    {
        $snap = $this->db->one(
            'SELECT s.relationship_version_id, s.release_status, s.visibility_scope,
                    v.version_digest, v.created_at AS observed_at,
                    v.uncertainty_summary_safe AS uncertainty
             FROM released_entity_relationship_snapshots s
             JOIN entity_relationship_versions v
               ON v.relationship_version_id = s.relationship_version_id
             WHERE s.released_relationship_snapshot_id = ?',
            [$input['released_relationship_snapshot_id']]
        );
        if ($snap === null) {
            return Outcome::refuse('SNAPSHOT_NOT_FOUND', [], 'PROVENANCE');
        }

        // BLOCKED is a relationship-snapshot terminal, not a claim ceiling. Only RELEASED snapshots
        // reach this composition, so this maps defensively rather than expectantly.
        $ceiling = (string) $snap['release_status'] === 'BLOCKED'
            ? 'RETRACTED'
            : (string) $snap['release_status'];

        $links = array_map(
            static fn (array $l): array => [
                'source_basis' => (string) $l['source_basis'],
                'evidence_id' => $l['evidence_id'],
                'source_ref_id' => $l['source_ref_id'],
                'allowed_use' => 'EVIDENCE_REFERENCE_ONLY',
            ],
            $this->db->all(
                'SELECT source_basis, evidence_id, source_ref_id
                 FROM entity_relationship_evidence_links WHERE relationship_version_id = ?',
                [(string) $snap['relationship_version_id']]
            )
        );

        $built = $this->contributionsFromSourceLinks(
            $input['tenant_id'],
            $input['workspace_id'],
            'relationship:' . (string) $snap['relationship_version_id'],
            $links,
        );

        $elements = [
            [
                'ordinal' => 0,
                'elementClass' => 'SAFE_SUMMARY',
                'textSafe' => 'Released state snapshot of an entity relationship edge.',
                'fieldsSafe' => ['visibilityScope' => (string) $snap['visibility_scope']],
            ],
            [
                'ordinal' => 1,
                'elementClass' => 'UNCERTAINTY_BLOCK',
                'textSafe' => (string) $snap['uncertainty'],
            ],
            [
                'ordinal' => 2,
                'elementClass' => 'FRESHNESS_BLOCK',
                'textSafe' => "Freshness reflects the released version's recording clock.",
                'fieldsSafe' => ['observedAt' => (string) $snap['observed_at']],
            ],
            [
                'ordinal' => 3,
                'elementClass' => 'LIMITATION_NOTE',
                'textSafe' => 'Internal relationship state view; bounded model — unknowns stay unknown.',
            ],
        ];

        return $this->compose([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'artifact_kind' => 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW',
            'consumer_column' => 'released_relationship_snapshot_id',
            'consumer_id' => $input['released_relationship_snapshot_id'],
            'decision_record_id' => $input['decision_record_id'],
            'claim_ceiling' => $ceiling,
            'internal_scope_lens' => (string) $snap['visibility_scope'],
            'elements' => $elements,
            'contributions' => $built['contributions'],
            'evidence_ids' => $built['evidence_ids'],
            'uncertainty_summary_safe' => (string) $snap['uncertainty'],
        ]);
    }

    /**
     * Project a version's source links into M16 contributions.
     *
     * TENANT BASES FOLD INTO ONE first-party contribution at the top of the lattice. Every other
     * basis becomes its own CARDLESS external contribution, which holds the compile — deliberately,
     * because no governed card discipline exists for twin sources yet.
     *
     * EVIDENCE IS PROJECTED CONSERVATIVELY and can only NARROW the result: an unknown sensitivity
     * reads as RESTRICTED_REVIEW, out-of-scope evidence is ignored rather than trusted, and
     * quarantined or revoked evidence marks the whole set unusable. Nothing here can widen an output.
     *
     * @param list<array<string, mixed>> $links
     * @return array{contributions: list<array<string, mixed>>, evidence_ids: list<string>}
     */
    private function contributionsFromSourceLinks(
        string $tenantId,
        string $workspaceId,
        string $sourceRefPrefix,
        array $links,
    ): array {
        $evidenceIds = [];
        $sensitivity = 'PUBLIC_OR_NON_SENSITIVE';
        $evidenceUsable = true;

        foreach ($links as $link) {
            if (($link['evidence_id'] ?? null) === null) {
                continue;
            }
            $evidenceId = (string) $link['evidence_id'];
            $evidenceIds[] = $evidenceId;
            $row = $this->db->one(
                'SELECT evidence_status, sensitivity_class FROM evidence_objects
                 WHERE evidence_id = ? AND tenant_id = ? AND workspace_id = ?',
                [$evidenceId, $tenantId, $workspaceId]
            );
            if ($row === null) {
                // Out-of-scope evidence never widens anything; the assembly scope gate refuses it.
                continue;
            }
            if (in_array((string) $row['evidence_status'], self::UNUSABLE_EVIDENCE_STATUSES, true)) {
                $evidenceUsable = false;
            }
            $index = array_search((string) $row['sensitivity_class'], self::SENSITIVITY_ORDER, true);
            // Unknown sensitivity reads as RESTRICTED_REVIEW — fail closed on vocabulary drift.
            $effective = $index === false ? 2 : (int) $index;
            $current = array_search($sensitivity, self::SENSITIVITY_ORDER, true);
            if ($effective > (int) $current) {
                $sensitivity = self::SENSITIVITY_ORDER[$effective];
            }
        }

        $contributions = [];
        $hasTenantBasis = false;
        foreach ($links as $link) {
            if (in_array((string) $link['source_basis'], self::TENANT_BASES, true)) {
                $hasTenantBasis = true;
                break;
            }
        }
        // A version with NO links at all still folds a first-party contribution: the state came
        // from somewhere inside the tenant, and a profile with zero contributions cannot compile.
        if ($hasTenantBasis || $links === []) {
            $contributions[] = [
                'source_license_card_id' => null,
                'source_ref_id' => $sourceRefPrefix . ':first-party',
                'evidence_ids' => $evidenceIds,
                'license_ceiling' => 'OPEN_CC0',
                'customer_output_allowed' => true,
                'derived_summary_allowed' => true,
                'excerpt_customer_visible' => true,
                'sensitivity_class' => $sensitivity,
                'retention_class' => 'standard',
                'citation_policy' => ['OPERATOR_ONLY' => 'FULL_CITATION', 'TENANT_INTERNAL' => 'FULL_CITATION'],
                'data_ownership' => 'TENANT_OWNED',
                'card_state' => null,
                'evidence_usable' => $evidenceUsable,
            ];
        }

        foreach ($links as $link) {
            if (in_array((string) $link['source_basis'], self::TENANT_BASES, true)) {
                continue;
            }
            $contributions[] = [
                'source_license_card_id' => null,
                'source_ref_id' => $link['source_ref_id'] === null
                    ? $sourceRefPrefix . ':external'
                    : (string) $link['source_ref_id'],
                'evidence_ids' => ($link['evidence_id'] ?? null) === null ? [] : [(string) $link['evidence_id']],
                // The most-restrictive honest default for an uncarded source.
                'license_ceiling' => 'PUBLIC_WEB_RESTRICTED',
                'customer_output_allowed' => false,
                'derived_summary_allowed' => (string) ($link['allowed_use'] ?? '') === 'DERIVED_SUMMARY_ALLOWED',
                'excerpt_customer_visible' => false,
                'sensitivity_class' => $sensitivity,
                'retention_class' => 'standard',
                'citation_policy' => ['OPERATOR_ONLY' => 'FULL_CITATION', 'TENANT_INTERNAL' => 'SOURCE_CLASS_ONLY'],
                // PUBLIC ownership + no card reference ⇒ the compile HOLDs with
                // SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE. Deliberate: no card discipline exists
                // for twin sources yet, so an external basis cannot clear a tenant-wide read.
                'data_ownership' => 'PUBLIC',
                'card_state' => null,
                'evidence_usable' => $evidenceUsable,
            ];
        }

        return ['contributions' => $contributions, 'evidence_ids' => array_values(array_unique($evidenceIds))];
    }

    /**
     * The shared composition: profile → frozen artifact → operator projection → (held stops here)
     * → internal projection → authority → activation.
     *
     * The ORDER is the law. The operator hold-view is persisted BEFORE the held check, so a held
     * artifact still has somewhere an operator can read it. Everything wider comes after.
     *
     * @param array<string, mixed> $input
     */
    private function compose(array $input): Outcome
    {
        $profile = $this->m16->compileSourceUseProfileFromContributions(
            (string) $input['tenant_id'],
            (string) $input['workspace_id'],
            $input['contributions'],
        );
        if ($profile->isRefusal()) {
            return Outcome::refuse('SOURCE_USE_PROFILE_REFUSED', [
                'detail' => $profile->reasonCode(),
            ], 'PROVENANCE');
        }

        $assembled = $this->m16->assembleArtifact([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'artifact_kind' => $input['artifact_kind'],
            $input['consumer_column'] => $input['consumer_id'],
            'decision_record_id' => $input['decision_record_id'],
            'source_use_profile_id' => $profile->value()['release_source_use_profile_id'],
            'content_elements' => $input['elements'],
            'uncertainty_summary_safe' => $input['uncertainty_summary_safe'],
            'claim_ceiling' => $input['claim_ceiling'],
            'internal_scope_lens' => $input['internal_scope_lens'],
        ]);
        if ($assembled->isRefusal()) {
            return Outcome::refuse('ARTIFACT_ASSEMBLY_REFUSED', [
                'detail' => $assembled->reasonCode(),
            ], 'POLICY');
        }
        $artifactId = (string) $assembled->value()['release_artifact_id'];

        // A REPLAY is already composed. Re-projecting would mint a second projection for an
        // artifact that already has one, which is how a retry turns into a duplicate.
        if ((string) $assembled->value()['outcome'] === 'IDEMPOTENT_REPLAY') {
            return Outcome::ok([
                'outcome' => 'COMPOSED',
                'release_artifact_id' => $artifactId,
                'replayed' => true,
            ]);
        }

        $held = (string) $assembled->value()['status'] === 'HELD_SOURCE_USE';

        // The operator hold-view exists either way. A held artifact nobody could read would be a
        // governed object with no reader, which is worse than no artifact at all.
        $operator = $this->m16->projectForAudience($artifactId, 'OPERATOR_ONLY');
        if ($operator->isRefusal()) {
            return Outcome::refuse('ARTIFACT_PROJECTION_REFUSED', [
                'audience_class' => 'OPERATOR_ONLY',
                'detail' => $operator->reasonCode(),
            ], 'POLICY');
        }

        if ($held) {
            // HELD IS A GOVERNED ANSWER. The release stands, the artifact is frozen, the operator
            // can see it, and nothing wider was activated. Returning a refusal here would roll back
            // a release the arbiter allowed.
            return Outcome::ok([
                'outcome' => 'HELD',
                'release_artifact_id' => $artifactId,
                'hold_reasons' => $profile->value()['hold_reasons'],
            ]);
        }

        $this->m16->releaseInternal($artifactId);

        $internal = $this->m16->projectForAudience($artifactId, 'TENANT_INTERNAL');
        if ($internal->isRefusal() || (string) $internal->value()['status'] !== 'PROJECTED') {
            return Outcome::refuse('ARTIFACT_PROJECTION_REFUSED', [
                'audience_class' => 'TENANT_INTERNAL',
            ], 'POLICY');
        }

        // `entity_twin.read` governs the two P02 snapshot families, and ONLY those. Registering it
        // here rather than trusting whatever the tenant's first product family happened to seed is
        // what makes P01 and P02 registration order irrelevant.
        $authority = $this->m16->seedVisibilityProfiles(
            (string) $input['tenant_id'],
            'entity_twin.read',
            ['ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW'],
        );
        if ($authority->isRefusal()) {
            return Outcome::refuse('ACCESS_RULE_CONFLICT', [
                'detail' => $authority->reasonCode(),
            ], 'POLICY');
        }

        foreach (['OPERATOR_ONLY', 'TENANT_INTERNAL'] as $audienceClass) {
            $activated = $this->m16->activateInternalVisibility(
                $artifactId,
                (string) $input['tenant_id'],
                (string) $input['workspace_id'],
                $audienceClass,
            );
            if ($activated->isRefusal()) {
                return Outcome::refuse('ARTIFACT_ACTIVATION_REFUSED', [
                    'audience_class' => $audienceClass,
                    'detail' => $activated->reasonCode(),
                ], 'POLICY');
            }
        }

        return Outcome::ok([
            'outcome' => 'COMPOSED',
            'release_artifact_id' => $artifactId,
        ]);
    }
}
