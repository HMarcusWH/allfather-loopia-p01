<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P02 entity twins — a structured, versioned picture of an ORGANIZATION.
 *
 * An entity twin is what the tenant knows about a company: its industry, its segment, what it
 * appears to be doing. Every field carries a VALUE STATUS saying how it is known — observed,
 * provided, derived, inferred, unknown, conflicting — because a twin whose fields all looked alike
 * would be a twin that hid the difference between a fact and a guess.
 *
 * WHAT A TWIN IS NOT:
 *
 *   It is not a dossier on a person. Fields about psychology, emotions, health, politics, religion,
 *   sexuality, ethnicity or private life are refused, and so are fields that score an individual
 *   employee. A ROLE CLASS is permitted — an organization has a finance function — but the person
 *   holding it is not the subject of this system.
 *
 *   It is not a claim about intent. A buyer-intent field may exist as a HYPOTHESIS, and may not be
 *   asserted as a KNOWN FACT. Hypotheses are what this system trades in; certainties about a
 *   person's mind are not available to it.
 *
 *   It is not an offering twin. That path exists upstream and is deliberately not ported: the P03
 *   catalogue is this projection's single authority for what a customer sells, and a second one
 *   would make that question answerable two ways.
 *
 * The prohibitions are enforced by CONTENT, not by a declared flag — asking the writer to declare
 * whether they were building a dossier would put the boundary in the hands of the party crossing it.
 *
 * Versions are APPEND-ONLY. A twin changes by gaining a version, never by editing one, so what the
 * product believed last month stays readable and a released snapshot cannot be rewritten under it.
 */
final class EntityTwins
{
    /**
     * Value statuses that ASSERT a supported fact. A version carrying any of them REQUIRES at least
     * one source link before it may be released.
     *
     * @var list<string>
     */
    private const SUPPORTED_FACT_STATUSES = [
        'KNOWN_FACT', 'TENANT_PROVIDED', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'DERIVED_SUMMARY',
    ];

    /**
     * Value statuses that may NEVER enter a released snapshot.
     *
     * `appendVersion` already refuses the fields that produce them at the content screen; this is
     * the second, independent reading, at the boundary where the claim would become visible.
     *
     * @var list<string>
     */
    private const NON_RELEASABLE_STATUSES = ['UNSUPPORTED_CLAIM', 'PROHIBITED'];

    /**
     * The closed set of internal visibility scopes a snapshot may be released to.
     *
     * Public because the state-change and release modules must read the SAME set — three private
     * copies of a closed vocabulary is three chances for them to drift apart, and a scope that one
     * module accepts and another rejects is a hole in a default-deny.
     *
     * @var list<string>
     */
    public const VISIBILITY_SCOPES = [
        'TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS',
        'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW',
    ];

    /**
     * The closed set of release statuses a state change may REQUEST. `SUPERSEDED` and `RETRACTED`
     * exist in storage as outcomes of later events; neither is something a caller may ask for.
     *
     * @var list<string>
     */
    public const RELEASE_STATUSES = [
        'INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS',
    ];

    private readonly M10BindingFloor $floor;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        ?M10BindingFloor $floor = null,
    ) {
        $this->floor = $floor ?? new M10BindingFloor($db);
    }

    /**
     * Register a twin.
     *
     * `is_first_party` and `is_external` are storage-pinned as exact complements, and OWN_COMPANY is
     * pinned to first-party — so a twin cannot be both the tenant and somebody else, and the
     * first-party leakage rules downstream always have an unambiguous subject to reason about.
     */
    public function registerTwin(
        string $tenantId,
        string $workspaceId,
        string $entityKind,
        string $displayName,
        array $attributes = [],
        ?string $createdByActorId = null,
    ): Outcome {
        $isFirstParty = $entityKind === 'OWN_COMPANY';
        $twinId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $twinId, $tenantId, $workspaceId, $entityKind, $displayName, $attributes,
            $isFirstParty, $createdByActorId, $now
        ): Outcome {
            $tx->insert('entity_twins', [
                'entity_twin_id' => $twinId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'entity_kind' => $entityKind,
                'display_name' => $displayName,
                'legal_name' => $attributes['legal_name'] ?? null,
                'domain' => $attributes['domain'] ?? null,
                'country' => $attributes['country'] ?? null,
                'jurisdiction' => $attributes['jurisdiction'] ?? null,
                'industry' => $attributes['industry'] ?? null,
                'segment' => $attributes['segment'] ?? null,
                'is_first_party' => $isFirstParty ? 1 : 0,
                'is_external' => $isFirstParty ? 0 : 1,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
                'created_by_actor_id' => $createdByActorId,
            ]);
            $tx->insert('entity_twin_status_history', [
                'entity_twin_status_history_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'entity_twin_id' => $twinId,
                'from_status' => null,
                'to_status' => 'ACTIVE',
                'reason_code' => 'REGISTERED',
                // Upstream hardcodes SYSTEM as the actor TYPE on this history table and defaults the actor
                // ID to the string "system" — `input.createdByActorId ?? "system"`. The port used
                // the twin's OWN id, so a row asked "who put this here?" answered with the thing
                // that was put there. It is the same fault the M10 writer column had, and the two
                // sibling release paths in this file already do it correctly.
                'initiated_by_actor_type' => 'SYSTEM',
                'initiated_by_actor_id' => $createdByActorId ?? 'system',
                'transitioned_at' => $now,
            ]);

            return Outcome::ok(['entity_twin_id' => $twinId, 'is_first_party' => $isFirstParty]);
        });
    }

    /**
     * Append a new VERSION of a twin's state.
     *
     * Every field is screened before anything is written. A version carrying one prohibited field is
     * refused entirely rather than written with that field dropped: silently discarding it would
     * leave the operator believing the twin holds something it does not, and the next writer would
     * try again.
     *
     * The UNCERTAINTY SUMMARY is required, and a version containing a CONFLICTING field must declare
     * the conflict. A twin that recorded contradictory evidence without saying so would present a
     * resolved picture of an unresolved question.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_id: string,
     *     claim_ceiling: string,
     *     fields: list<array{field_key: string, field_value_safe: ?string, value_status: string}>,
     *     uncertainty: array{summary_safe: string, has_conflict?: bool, conflict_summary_safe?: ?string},
     *     source_links?: list<array<string, mixed>>
     * } $input
     */
    public function appendVersion(array $input): Outcome
    {
        $twin = $this->db->one(
            'SELECT tenant_id, workspace_id, is_first_party, status FROM entity_twins WHERE entity_twin_id = ?',
            [$input['entity_twin_id']]
        );
        if ($twin === null) {
            return Outcome::refuse('ENTITY_TWIN_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $twin['tenant_id'] !== $input['tenant_id']
            || (string) $twin['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('ENTITY_TWIN_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if (!in_array((string) $twin['status'], ['ACTIVE', 'DRAFT', 'UNDER_REVIEW'], true)) {
            return Outcome::refuse('ENTITY_TWIN_NOT_MUTABLE', [
                'status' => (string) $twin['status'],
            ], 'POLICY');
        }

        // --- the privacy screen ---------------------------------------------------------------------
        // Run over EVERY field before anything is written. One prohibited field refuses the whole
        // version: dropping it silently would leave the operator believing the twin holds something
        // it does not.
        $screen = $this->screenFields($input['fields']);
        if ($screen !== null) {
            return $screen;
        }

        $uncertainty = $input['uncertainty'];
        if (trim($uncertainty['summary_safe']) === '') {
            return Outcome::refuse('TWIN_UNCERTAINTY_SUMMARY_REQUIRED', [], 'POLICY');
        }

        // A CONFLICTING field must be declared in the uncertainty summary. A twin that recorded
        // contradictory evidence without saying so would present a resolved picture of an
        // unresolved question.
        $hasConflictingField = false;
        $unknownCount = 0;
        foreach ($input['fields'] as $field) {
            if ($field['value_status'] === 'CONFLICTING') {
                $hasConflictingField = true;
            }
            if ($field['value_status'] === 'UNKNOWN') {
                $unknownCount++;
            }
        }
        $declaredConflict = (bool) ($uncertainty['has_conflict'] ?? false);
        if ($hasConflictingField && !$declaredConflict) {
            return Outcome::refuse('CONFLICTING_EVIDENCE_UNRESOLVED', [], 'POLICY', 'S3', 'BLOCK_RELEASE');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $input, $uncertainty, $declaredConflict, $unknownCount, $now
        ): Outcome {
            $sequence = 1 + (int) $tx->scalar(
                'SELECT COALESCE(MAX(version_sequence), 0) FROM entity_twin_versions WHERE entity_twin_id = ?',
                [$input['entity_twin_id']]
            );
            // The digest binds the SORTED field set to the version, so two versions with the same
            // content have the same digest and a field changed after the fact no longer verifies.
            $fields = $input['fields'];
            usort($fields, static fn (array $a, array $b): int => strcmp($a['field_key'], $b['field_key']));
            $versionDigest = Digest::canonicalDigest([
                'entityTwinId' => $input['entity_twin_id'],
                'versionSequence' => $sequence,
                'claimCeiling' => $input['claim_ceiling'],
                'fields' => array_map(static fn (array $f): array => [
                    'fieldKey' => $f['field_key'],
                    'fieldValueSafe' => $f['field_value_safe'],
                    'valueStatus' => $f['value_status'],
                ], $fields),
            ]);

            $versionId = Ids::new();
            $tx->insert('entity_twin_versions', [
                'entity_twin_version_id' => $versionId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'entity_twin_id' => $input['entity_twin_id'],
                'version_sequence' => $sequence,
                'claim_ceiling' => $input['claim_ceiling'],
                'version_digest' => $versionDigest,
                'created_at' => $now,
            ]);

            foreach ($fields as $field) {
                $tx->insert('entity_twin_state_summaries', [
                    'entity_twin_state_summary_id' => Ids::new(),
                    'schema_version' => '1',
                    'tenant_id' => $input['tenant_id'],
                    'entity_twin_version_id' => $versionId,
                    'field_key' => $field['field_key'],
                    'field_value_safe' => $field['field_value_safe'],
                    // How the field is KNOWN travels with it. A twin whose fields all looked alike
                    // would hide the difference between a fact and a guess.
                    'value_status' => $field['value_status'],
                    'created_at' => $now,
                ]);
            }

            $tx->insert('entity_twin_uncertainty_summaries', [
                'entity_twin_uncertainty_summary_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'entity_twin_version_id' => $versionId,
                'summary_safe' => $uncertainty['summary_safe'],
                'unknown_field_count' => $unknownCount,
                'has_conflict' => $declaredConflict ? 1 : 0,
                'conflict_summary_safe' => $uncertainty['conflict_summary_safe'] ?? null,
                'created_at' => $now,
            ]);

            foreach ($input['source_links'] ?? [] as $link) {
                $tx->insert('entity_twin_source_links', [
                    'entity_twin_source_link_id' => Ids::new(),
                    'schema_version' => '1',
                    'tenant_id' => $input['tenant_id'],
                    'entity_twin_version_id' => $versionId,
                    'evidence_id' => $link['evidence_id'] ?? null,
                    'source_ref_id' => $link['source_ref_id'] ?? null,
                    'source_basis' => $link['source_basis'],
                    'allowed_use' => $link['allowed_use'],
                    'license_basis_safe' => $link['license_basis_safe'] ?? null,
                    'freshness_observed_at' => $link['freshness_observed_at'] ?? null,
                    'created_at' => $now,
                ]);
            }

            $tx->run('UPDATE entity_twins SET updated_at = ? WHERE entity_twin_id = ?', [
                $now, $input['entity_twin_id'],
            ]);

            return Outcome::ok([
                'entity_twin_version_id' => $versionId,
                'version_sequence' => $sequence,
                'version_digest' => $versionDigest,
                'unknown_field_count' => $unknownCount,
            ]);
        });
    }

    /**
     * The anchor kinds a twin may carry.
     *
     * @var list<string>
     */
    public const ANCHOR_KINDS = ['SOURCE', 'LEGAL', 'LICENSE', 'RETENTION', 'PRIVACY', 'FACTUAL'];

    /**
     * Record an append-only ledger anchor for a twin.
     *
     * An anchor is the twin's standing declaration about a whole class of question: where its
     * material comes from, under what licence, how long it may be kept. It is not per-version,
     * because those answers do not change when a field does.
     *
     * A RETENTION anchor is release-gating — `RETENTION_POLICY_COVERAGE` in {@see TwinAssurance}
     * finds every released twin whose entity declares none. It is therefore AUTHORITY, and it must
     * come from inside the twin's own workspace: a tenant-wide caller could otherwise declare a
     * retention policy for a workspace it cannot see.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_id: string, anchor_kind: string,
     *     anchor_summary_safe: string, retention_policy_safe?: ?string
     * } $input
     */
    public function recordLedgerAnchor(array $input): Outcome
    {
        $twin = $this->db->one(
            'SELECT tenant_id, workspace_id FROM entity_twins WHERE entity_twin_id = ?',
            [$input['entity_twin_id']]
        );
        if ($twin === null) {
            return Outcome::refuse('ENTITY_TWIN_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $twin['tenant_id'] !== $input['tenant_id']
            || (string) $twin['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('ENTITY_TWIN_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if (!in_array($input['anchor_kind'], self::ANCHOR_KINDS, true)) {
            return Outcome::refuse('INVALID_ANCHOR_KIND', [
                'anchor_kind' => $input['anchor_kind'],
            ], 'POLICY');
        }

        $anchorId = Ids::new();
        $this->db->insert('entity_twin_ledger_anchors', [
            'entity_twin_ledger_anchor_id' => $anchorId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'entity_twin_id' => $input['entity_twin_id'],
            'anchor_kind' => $input['anchor_kind'],
            'anchor_summary_safe' => $input['anchor_summary_safe'],
            'retention_policy_safe' => $input['retention_policy_safe'] ?? null,
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['entity_twin_ledger_anchor_id' => $anchorId]);
    }

    /**
     * The storage release gate — validate, then write the immutable snapshot.
     *
     * Callers that are ALREADY inside a transaction use {@see releaseSnapshotTxFree}; this wrapper
     * exists for a standalone release and opens one of its own.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_version_id: string,
     *     visibility_scope: string, release_status: string, decision_record_id?: ?string,
     *     supersedes_release_id?: ?string, released_by_actor_id?: ?string
     * } $input
     */
    public function releaseSnapshot(array $input): Outcome
    {
        return $this->db->transaction(fn (): Outcome => $this->releaseSnapshotTxFree($input));
    }

    /**
     * The same gate WITHOUT opening a transaction — the caller must already be inside one.
     *
     * The governed release path composes this write and its own binding update into a single
     * post-M10 unit, so that there is never a released snapshot the state change does not point at.
     * Every refusal returns BEFORE any write, so a refused release leaves nothing behind.
     *
     * The gate, in order, and each condition is here because releasing without it would publish a
     * claim the system cannot stand behind:
     *
     *   the version exists, and is the caller's                — scope
     *   the visibility scope and release status are known      — closed vocabularies, default-deny
     *   the claim ceiling is not SUPERSEDED or RETRACTED       — a withdrawn version stays withdrawn
     *   an uncertainty summary exists                          — INV-P02: unknowns travel with claims
     *   no field is UNSUPPORTED_CLAIM or PROHIBITED            — content that may never be released
     *   a version asserting facts has at least one source link — no unsourced assertion
     *   a supersede target is a prior snapshot of THIS twin    — no cross-twin supersession
     *   the M10 binding floor                                  — last, and typed
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_version_id: string,
     *     visibility_scope: string, release_status: string, decision_record_id?: ?string,
     *     supersedes_release_id?: ?string, released_by_actor_id?: ?string
     * } $input
     */
    public function releaseSnapshotTxFree(array $input): Outcome
    {
        $version = $this->db->one(
            'SELECT entity_twin_id, tenant_id, workspace_id, version_sequence, claim_ceiling, version_digest
             FROM entity_twin_versions WHERE entity_twin_version_id = ?',
            [$input['entity_twin_version_id']]
        );
        if ($version === null) {
            return Outcome::refuse('VERSION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $version['tenant_id'] !== $input['tenant_id']
            || (string) $version['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if (!in_array($input['visibility_scope'], self::VISIBILITY_SCOPES, true)) {
            // Default-deny on the role lens: an unrecognized scope has no rule, and inventing one
            // at release time would decide who may see this by accident.
            return Outcome::refuse('VISIBILITY_RULE_MISSING', [
                'visibility_scope' => $input['visibility_scope'],
            ], 'POLICY');
        }
        if (!in_array($input['release_status'], self::RELEASE_STATUSES, true)) {
            return Outcome::refuse('INVALID_RELEASE_STATUS', [
                'release_status' => $input['release_status'],
            ], 'POLICY');
        }
        if (in_array((string) $version['claim_ceiling'], ['SUPERSEDED', 'RETRACTED'], true)) {
            return Outcome::refuse('RELEASE_CEILING_INVALID', [
                'claim_ceiling' => (string) $version['claim_ceiling'],
            ], 'POLICY', 'S4', 'BLOCK_RELEASE');
        }

        $uncertaintyId = $this->db->scalar(
            'SELECT entity_twin_uncertainty_summary_id FROM entity_twin_uncertainty_summaries
             WHERE entity_twin_version_id = ?',
            [$input['entity_twin_version_id']]
        );
        if ($uncertaintyId === null) {
            return Outcome::refuse('UNCERTAINTY_SUMMARY_MISSING', [], 'POLICY', 'S3', 'BLOCK_RELEASE');
        }

        $statuses = array_map(
            static fn (array $row): string => (string) $row['value_status'],
            $this->db->all(
                'SELECT value_status FROM entity_twin_state_summaries WHERE entity_twin_version_id = ?',
                [$input['entity_twin_version_id']]
            )
        );
        if (array_intersect($statuses, self::NON_RELEASABLE_STATUSES) !== []) {
            return Outcome::refuse('PROHIBITED_STATE_FIELD', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        if (array_intersect($statuses, self::SUPPORTED_FACT_STATUSES) !== []) {
            // A version that asserts facts must cite something. This is the release-side half of
            // the same invariant the arbiter reads: no supported claim without a source.
            $links = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM entity_twin_source_links WHERE entity_twin_version_id = ?',
                [$input['entity_twin_version_id']]
            );
            if ($links === 0) {
                return Outcome::refuse('UNSUPPORTED_TWIN_CLAIM', [], 'POLICY', 'S4', 'BLOCK_RELEASE');
            }
        }

        $supersedes = $input['supersedes_release_id'] ?? null;
        if ($supersedes !== null) {
            $prior = $this->db->one(
                'SELECT entity_twin_id, tenant_id FROM released_entity_twin_snapshots
                 WHERE released_entity_twin_snapshot_id = ?',
                [$supersedes]
            );
            if ($prior === null
                || (string) $prior['tenant_id'] !== $input['tenant_id']
                || (string) $prior['entity_twin_id'] !== (string) $version['entity_twin_id']
            ) {
                return Outcome::refuse('SUPERSEDE_TARGET_NOT_FOUND', [], 'PROVENANCE', 'S4');
            }
        }

        // The M10 binding floor — last gate before the write, and a typed refusal rather than a
        // NOT NULL driver error.
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
            'entityTwinId' => (string) $version['entity_twin_id'],
            'versionSequence' => (int) $version['version_sequence'],
            'versionDigest' => (string) $version['version_digest'],
            'claimCeiling' => (string) $version['claim_ceiling'],
            'visibilityScope' => $input['visibility_scope'],
            'releaseStatus' => $input['release_status'],
            'supersedesReleaseId' => $supersedes,
        ]);
        $now = $this->clock->now();

        $this->db->insert('released_entity_twin_snapshots', [
            'released_entity_twin_snapshot_id' => $snapshotId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'entity_twin_id' => (string) $version['entity_twin_id'],
            'entity_twin_version_id' => $input['entity_twin_version_id'],
            'entity_twin_uncertainty_summary_id' => (string) $uncertaintyId,
            'claim_ceiling' => (string) $version['claim_ceiling'],
            'visibility_scope' => $input['visibility_scope'],
            'snapshot_digest' => $snapshotDigest,
            'release_status' => $input['release_status'],
            'supersedes_release_id' => $supersedes,
            'decision_record_id' => $input['decision_record_id'],
            'released_at' => $now,
            'released_by_actor_id' => $input['released_by_actor_id'] ?? null,
        ]);

        $this->db->insert('entity_twin_status_history', [
            'entity_twin_status_history_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'entity_twin_id' => (string) $version['entity_twin_id'],
            'from_status' => null,
            'to_status' => 'ACTIVE',
            'reason_code' => $supersedes !== null ? 'SNAPSHOT_SUPERSEDED' : 'SNAPSHOT_RELEASED',
            // SYSTEM, as upstream's appendTwinStatusHistory hardcodes for every row it writes.
            'initiated_by_actor_type' => 'SYSTEM',
            'initiated_by_actor_id' => $input['released_by_actor_id'] ?? 'system',
            'transitioned_at' => $now,
        ]);
        $this->db->run('UPDATE entity_twins SET status = \'ACTIVE\', updated_at = ? WHERE entity_twin_id = ?', [
            $now, (string) $version['entity_twin_id'],
        ]);

        return Outcome::ok([
            'outcome' => 'RELEASED',
            'released_entity_twin_snapshot_id' => $snapshotId,
            'snapshot_digest' => $snapshotDigest,
        ]);
    }

    /**
     * The privacy screen. Returns a refusal, or null when every field is admissible.
     *
     * Each prohibition returns its OWN reason code, because the remedies differ: a person-overreach
     * field must be dropped, an employee-scoring field must be reconsidered entirely, and a
     * buyer-intent field only needs its status softened from fact to hypothesis.
     *
     * @param list<array{field_key: string, field_value_safe: ?string, value_status: string}> $fields
     */
    private function screenFields(array $fields): ?Outcome
    {
        foreach ($fields as $field) {
            $key = $field['field_key'];

            if (PrivacyPatterns::keyMatchesAny($key, PrivacyPatterns::PERSON_OVERREACH)) {
                return Outcome::refuse('PERSON_OVERREACH_FIELD', [
                    'field_key' => $key,
                ], 'POLICY', 'S5', 'BLOCK_RELEASE');
            }
            if (PrivacyPatterns::keyMatchesAny($key, PrivacyPatterns::EMPLOYEE_SCORING)) {
                return Outcome::refuse('EMPLOYEE_SCORING_FIELD', [
                    'field_key' => $key,
                ], 'POLICY', 'S5', 'BLOCK_RELEASE');
            }
            // A buyer-intent field as a HYPOTHESIS is fine — hypotheses are what this system trades
            // in. As a KNOWN FACT it is a claim about a person's mind that nothing observed.
            if (PrivacyPatterns::keyMatchesAny($key, PrivacyPatterns::BUYER_INTENT)
                && $field['value_status'] === 'KNOWN_FACT'
            ) {
                return Outcome::refuse('BUYER_INTENT_OVERCLAIM', [
                    'field_key' => $key,
                ], 'POLICY', 'S4', 'BLOCK_RELEASE');
            }
            // An individual identifier in a VALUE. A role class is permitted; the person holding it
            // is not the subject of this system.
            $value = $field['field_value_safe'];
            if ($value !== null && PrivacyPatterns::containsIndividualIdentifier($value)) {
                return Outcome::refuse('INDIVIDUAL_IDENTIFIER_IN_TWIN_FIELD', [
                    'field_key' => $key,
                ], 'POLICY', 'S5', 'BLOCK_RELEASE');
            }
        }

        return null;
    }
}
