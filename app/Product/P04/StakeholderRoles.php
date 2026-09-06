<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P02\PrivacyPatterns;
use Allfather\Loopia\Substrate\M02\Scope;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;

/**
 * P04 stakeholder-role observation — privacy-fenced to a ROLE CLASS, and only from a public source.
 *
 * The product may observe that a target company HAS a role — "there is a Head of Sales here" — and
 * may not observe the person in it. That distinction is what separates competitive research from
 * building a file on somebody.
 *
 * FOUR FENCES, and each is doing different work:
 *
 *   `subject_kind` is CHECK-pinned to `ROLE_CLASS`. There is no INDIVIDUAL member and no column for
 *   an individual identifier, so the strongest thing this table can hold is a role.
 *
 *   The cited source must be PUBLIC and CAPTURABLE. A customer-owned or restricted card is
 *   REFUSED — this is the mirror image of the catalogue rule, where customer data may never be
 *   public: here an observation about a third party's staff may ONLY be public.
 *
 *   The role LABEL is scanned for individual identifiers. "Head of Sales" is a role;
 *   "Head of Sales (anna@rival.example)" is a person wearing one, and the label is where that would
 *   sneak in.
 *
 *   NO HR, SOCIAL OR BEHAVIOURAL FEED IS EVER READ. There is no code path here that could: the only
 *   input is a source reference that already passed the public-source discipline.
 *
 * The observation cites derived evidence and is never canonical fact on its own. That a role exists
 * is an inference from a public page, and inferences say so.
 *
 * ORDER IS UPSTREAM'S. The three canonical guards run in the canonical order and return the
 * canonical reason codes, so an input upstream refuses is refused here for the same stated reason.
 * The subject-company scan is an ADDITION at the end of the guard chain (see
 * docs/PORT_SCOPE_MATRIX.md §4.1): placing it last means it can only refuse inputs upstream would
 * have ACCEPTED, never relabel a refusal upstream already names.
 */
final class StakeholderRoles
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceSpine $evidence,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, role_class_label: string,
     *     subject_company_ref_safe: string, source_ref_id: string,
     *     competitor_id?: ?string, mission_id?: ?string
     * } $input
     */
    public function observeRoleClass(array $input): Outcome
    {
        $source = $this->db->one(
            'SELECT tenant_id, workspace_id, content_sensitivity_class, status
             FROM source_references WHERE source_ref_id = ?',
            [$input['source_ref_id']]
        );
        if ($source === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        // Tenant AND workspace. A source scoped to another workspace of the same tenant is as
        // foreign here as another tenant's; a tenant-wide source (NULL workspace) stays available.
        if (!Scope::isCompatible(
            (string) $source['tenant_id'],
            $source['workspace_id'] === null ? null : (string) $source['workspace_id'],
            (string) $input['tenant_id'],
            (string) $input['workspace_id'],
            tenantWideObjectPermitted: true,
        )) {
            return Outcome::refuse('SOURCE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // The source-card discipline: a stakeholder observation may ONLY be backed by a public,
        // capturable source. A customer-owned, restricted or revoked card fails closed here.
        if ((string) $source['content_sensitivity_class'] !== 'PUBLIC_OR_NON_SENSITIVE'
            || (string) $source['status'] !== 'ACTIVE_FOR_CAPTURE'
        ) {
            return Outcome::refuse('NON_PUBLIC_STAKEHOLDER_SOURCE', [
                'content_sensitivity_class' => (string) $source['content_sensitivity_class'],
                'status' => (string) $source['status'],
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        // Defence-in-depth: a role CLASS label must never carry an individual identifier (it is a
        // role, never a person). The same detector the M08 privacy rule and the P02 twin screen use.
        if (PrivacyPatterns::containsIndividualIdentifier($input['role_class_label'])) {
            return Outcome::refuse('INDIVIDUAL_IDENTIFIER_FORBIDDEN', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        // The added fence. The subject reference names a COMPANY; a person smuggled into it would
        // be an individual observation wearing a company's label, which is the exact thing the
        // ROLE_CLASS pin exists to prevent.
        if (PrivacyPatterns::containsIndividualIdentifier($input['subject_company_ref_safe'])) {
            return Outcome::refuse('INDIVIDUAL_IDENTIFIER_FORBIDDEN', [
                'field' => 'subject_company_ref_safe',
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $observationDigest = Digest::canonicalDigest([
            'roleClassLabel' => $input['role_class_label'],
            'subjectCompanyRefSafe' => $input['subject_company_ref_safe'],
            'sourceRefId' => $input['source_ref_id'],
        ]);

        return $this->db->transaction(function (Database $tx) use ($input, $observationDigest): Outcome {
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $input['mission_id'] ?? null,
                'source_ref_id' => $input['source_ref_id'],
                // A PROVENANCE REFERENCE, never an observation of fact. That a role exists is an
                // inference from a public page, and the evidence type says so.
                'evidence_type' => 'STAKEHOLDER_ROLE_PROVENANCE_REFERENCE',
                'captured_content_reference_safe' => 'Role-class observation: '
                    . $input['role_class_label'] . ' @ ' . $input['subject_company_ref_safe']
                    . ' (public source).',
                'content_hash' => $observationDigest,
                'capture_method' => 'DERIVED_ROLE_CLASS_OBSERVATION',
                // No `permitted_use_scope`: the CITED SOURCE'S declared scope governs, and M05
                // resolves it. A producer stating a scope of its own would be claiming a licence
                // the source never granted.
                'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
            ]);
            if ($captured->isRefusal()) {
                // The public/scope guards above already passed, so a capture refusal here is an
                // unexpected admission failure. It fails closed onto the non-public guard — the
                // upstream reason code — and carries M05's specific code so nothing is lost.
                return Outcome::refuse('NON_PUBLIC_STAKEHOLDER_SOURCE', [
                    'evidence_refusal' => $captured->reasonCode(),
                ], 'POLICY', 'S5', 'BLOCK_RELEASE');
            }

            $now = $this->clock->now();
            $observationId = Ids::new();
            $tx->insert('stakeholder_role_observations', [
                'stakeholder_role_observation_id' => $observationId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                // CHECK-pinned to the only member. There is no INDIVIDUAL to write.
                'subject_kind' => 'ROLE_CLASS',
                'role_class_label' => $input['role_class_label'],
                'subject_company_ref_safe' => $input['subject_company_ref_safe'],
                'competitor_id' => $input['competitor_id'] ?? null,
                'source_ref_id' => $input['source_ref_id'],
                'basis_evidence_id' => $captured->value()['evidence_id'],
                'observation_status' => 'ACTIVE',
                'created_at' => $now,
            ]);
            $tx->insert('stakeholder_role_observation_status_transitions', [
                'transition_id' => Ids::new(),
                'schema_version' => '1',
                'stakeholder_role_observation_id' => $observationId,
                'from_status' => null,
                'to_status' => 'ACTIVE',
                'reason_code' => 'OBSERVED_FROM_PUBLIC_SOURCE',
                'transitioned_at' => $now,
            ]);

            return Outcome::ok([
                'stakeholder_role_observation_id' => $observationId,
                'basis_evidence_id' => $captured->value()['evidence_id'],
            ]);
        });
    }
}
