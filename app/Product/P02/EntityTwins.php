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
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
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
                'initiated_by_actor_type' => $createdByActorId === null ? 'SERVICE' : 'OPERATOR',
                'initiated_by_actor_id' => $createdByActorId ?? $twinId,
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
