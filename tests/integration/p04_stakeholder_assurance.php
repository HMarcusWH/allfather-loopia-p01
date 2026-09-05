<?php

declare(strict_types=1);

/**
 * P04 stakeholder-role observation and the four P04 audits.
 *
 * The claim under test, in one sentence: the product may observe that a company HAS a role, and
 * may not observe the person in it.
 *
 * That line is held in four independent places, and each is proved here on its own:
 *
 *   the storage CHECK, which admits no subject kind but ROLE_CLASS and has no column for a person;
 *   the public-source discipline, which refuses any observation not drawn from a public page;
 *   the label scan, which catches a person smuggled into a role's name;
 *   the M11 audits, which re-ask all of it over what has already been written.
 *
 * The audits are the layer that matters after the fact: a gate protects the future, and an audit
 * is what notices when the past stopped being true.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P04\Assurance as P04Assurance;
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Product\P04\DistributionAdmission;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P04\StakeholderRoles;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);

$ledger = new DecisionLedger($db, $clock);
$evidence = new EvidenceSpine($db, $clock);
$m11 = new M11Assurance($db, $clock, $ledger);
$stakeholders = new StakeholderRoles($db, $clock, $evidence);
$assurance = new P04Assurance($db, $clock, $m11);

// ============================================================ the public-source discipline

$publicSource = $evidence->registerSource(
    $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
    'https://rival.example/about/team', 'public_web', 'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
);
$customerSource = $evidence->registerSource(
    $scope['tenant_id'], $scope['workspace_id'], 'UPLOADED_ARTIFACT',
    'upload://crm-export.csv', 'internal_only', 'RESTRICTED_REVIEW', 'ACTIVE_FOR_CAPTURE'
);
$registeredOnly = $evidence->registerSource(
    $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
    'https://rival.example/careers', 'public_web', 'PUBLIC_OR_NON_SENSITIVE', 'REGISTERED'
);

$observe = static fn (string $sourceRefId, string $label, string $company = 'Rival AB'): array => [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_class_label' => $label,
    'subject_company_ref_safe' => $company,
    'source_ref_id' => $sourceRefId,
];

// A restricted, customer-owned card cannot back an observation about a third party's staff. This
// is the MIRROR of the catalogue rule: there customer data may never be public; here an
// observation about someone else's staff may ONLY be public.
Assert::same(
    'NON_PUBLIC_STAKEHOLDER_SOURCE',
    $stakeholders->observeRoleClass($observe($customerSource, 'Head of Sales'))->reasonCode(),
    'a restricted source cannot back a stakeholder observation',
);

// A public source that is merely REGISTERED is not yet admitted for capture. Both halves of the
// rule are required, and each is proved on its own.
Assert::same(
    'NON_PUBLIC_STAKEHOLDER_SOURCE',
    $stakeholders->observeRoleClass($observe($registeredOnly, 'Head of Sales'))->reasonCode(),
    'a public source not yet active for capture cannot back one either',
);

Assert::same(
    'SOURCE_NOT_FOUND',
    $stakeholders->observeRoleClass($observe(Ids::new(), 'Head of Sales'))->reasonCode(),
    'and an unknown source resolves to nothing rather than defaulting to permitted',
);

// ============================================================ the label may not carry a person

foreach ([
    'an email' => 'Head of Sales (anna.berg@rival.example)',
    'a handle' => 'Head of Sales — linkedin.com/in/anna-berg',
    'a phone-like run' => 'Head of Sales, direct +46 70 123 45 67',
] as $shape => $label) {
    Assert::same(
        'INDIVIDUAL_IDENTIFIER_FORBIDDEN',
        $stakeholders->observeRoleClass($observe($publicSource, $label))->reasonCode(),
        "a role label carrying {$shape} is a person wearing a role, and is refused",
    );
}

// The subject reference is scanned too. A company field is where a person would be smuggled in if
// only the label were checked.
Assert::same(
    'INDIVIDUAL_IDENTIFIER_FORBIDDEN',
    $stakeholders->observeRoleClass($observe($publicSource, 'Head of Sales', 'anna.berg@rival.example'))
        ->reasonCode(),
    'and so is the subject company reference',
);

// ============================================================ a clean observation

$observed = $stakeholders->observeRoleClass($observe($publicSource, 'Head of Sales'));
Assert::true($observed->isOk(), 'a role class from a public, capturable source is observed');
$observationId = $observed->value()['stakeholder_role_observation_id'];

$row = $db->one(
    'SELECT subject_kind, role_class_label, observation_status, basis_evidence_id
     FROM stakeholder_role_observations WHERE stakeholder_role_observation_id = ?',
    [$observationId]
);
Assert::same('ROLE_CLASS', (string) $row['subject_kind'], 'as a ROLE CLASS — the only subject kind that exists here');
Assert::same('ACTIVE', (string) $row['observation_status'], 'and ACTIVE');

// The observation cites DERIVED evidence, not a fact. That a role exists is an inference from a
// public page, and the evidence type says exactly that.
$basis = $db->one(
    'SELECT evidence_type, capture_method, sensitivity_class, permitted_use_scope
     FROM evidence_objects WHERE evidence_id = ?',
    [(string) $row['basis_evidence_id']]
);
Assert::same(
    'STAKEHOLDER_ROLE_PROVENANCE_REFERENCE',
    (string) $basis['evidence_type'],
    'the basis is a PROVENANCE reference — never canonical fact',
);
Assert::same(
    'DERIVED_ROLE_CLASS_OBSERVATION',
    (string) $basis['capture_method'],
    'captured as a derivation, not as an observation of fact',
);
// The producer states no scope of its own; M05 resolves it from the cited source, so the
// observation cannot claim a licence the source never granted.
Assert::same(
    'public_web',
    (string) $basis['permitted_use_scope'],
    "carrying the CITED SOURCE'S declared use scope, not one the producer chose",
);

// The transition ledger records how it got here.
Assert::same(
    'OBSERVED_FROM_PUBLIC_SOURCE',
    (string) $db->scalar(
        'SELECT reason_code FROM stakeholder_role_observation_status_transitions
         WHERE stakeholder_role_observation_id = ?',
        [$observationId]
    ),
    'and the transition names the authority it rests on',
);

// The STRUCTURAL guarantee: there is no column an individual could be written into. A policy can
// be changed by an edit; a schema cannot.
$columns = array_map(
    static fn (array $c): string => (string) $c['COLUMN_NAME'],
    $db->all(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['stakeholder_role_observations']
    )
);
foreach (['person', 'individual', 'employee', 'email', 'phone', 'full_name'] as $forbidden) {
    Assert::equalsList(
        [],
        array_values(array_filter($columns, static fn (string $c): bool => str_contains($c, $forbidden))),
        "the observation table has no `{$forbidden}` column — the strongest thing it can hold is a role",
    );
}

// ============================================================ STAKEHOLDER_ROLE_PROVENANCE audit

$provenanceProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p04.stakeholder', 'STAKEHOLDER_ROLE_PROVENANCE',
    'P04 stakeholder role provenance'
);

$clean = $assurance->runStakeholderRoleProvenanceAudit(['audit_profile_id' => $provenanceProfile]);
Assert::true($clean->isOk(), 'the provenance audit runs');
Assert::same('CONTROL_PASS', $clean->value()['finding_type'], 'and passes over a clean observation');
Assert::same(null, $clean->value()['incident_case_id'], 'opening no incident');

// A profile of the WRONG category cannot back this finding: it would cite an audit that never
// asked the question it answers.
$wrongCategory = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p04.wrong', 'REDACTION_POLICY', 'Wrong category'
);
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_STAKEHOLDER_ROLE_PROVENANCE',
    $assurance->runStakeholderRoleProvenanceAudit(['audit_profile_id' => $wrongCategory])->reasonCode(),
    'a profile of the wrong category cannot back a provenance finding',
);

// Now break it BEHIND the module's back — the state an audit exists to notice. The label is
// mutated directly, which is exactly what a bypassed guard would leave behind.
$db->run(
    'UPDATE stakeholder_role_observations SET role_class_label = ?
     WHERE stakeholder_role_observation_id = ?',
    ['Head of Sales (anna.berg@rival.example)', $observationId]
);
$caught = $assurance->runStakeholderRoleProvenanceAudit(['audit_profile_id' => $provenanceProfile]);
Assert::same(
    'PROVENANCE_OR_IP_CONCERN',
    $caught->value()['finding_type'],
    'an identifier written past the module is caught by the audit',
);
Assert::equalsList(
    [$observationId],
    $caught->value()['violating_refs'],
    'naming the exact observation',
);
Assert::notSame(null, $caught->value()['incident_case_id'], 'and OPENING AN INCIDENT — a finding nobody must look at is no finding');

// The audit did NOT repair what it found. An audit that fixed the fault would leave no evidence
// the fault ever existed, and the next run would pass without anyone having looked.
Assert::same(
    'Head of Sales (anna.berg@rival.example)',
    (string) $db->scalar(
        'SELECT role_class_label FROM stakeholder_role_observations WHERE stakeholder_role_observation_id = ?',
        [$observationId]
    ),
    'and left the row exactly as it found it',
);

// Restore, and the audit passes again — proving the finding tracked the state, not the run.
$db->run(
    'UPDATE stakeholder_role_observations SET role_class_label = ?
     WHERE stakeholder_role_observation_id = ?',
    ['Head of Sales', $observationId]
);
Assert::same(
    'CONTROL_PASS',
    $assurance->runStakeholderRoleProvenanceAudit(['audit_profile_id' => $provenanceProfile])
        ->value()['finding_type'],
    'and passes again once the state is repaired',
);

// A non-public source behind an observation is caught by the same audit.
$db->run(
    "UPDATE source_references SET content_sensitivity_class = 'RESTRICTED_REVIEW' WHERE source_ref_id = ?",
    [$publicSource]
);
Assert::same(
    'PROVENANCE_OR_IP_CONCERN',
    $assurance->runStakeholderRoleProvenanceAudit(['audit_profile_id' => $provenanceProfile])
        ->value()['finding_type'],
    'a source reclassified as restricted makes its existing observations findings',
);
$db->run(
    "UPDATE source_references SET content_sensitivity_class = 'PUBLIC_OR_NON_SENSITIVE' WHERE source_ref_id = ?",
    [$publicSource]
);

// ============================================================ a released distribution to audit

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

$readiness = new Readiness($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);
$council = new Council($db, $clock);
$proposals = new Proposals($db, $clock);
$distribution = new Distribution($db, $clock, $ledger);
$admission = new DistributionAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger
);
$release = new P04Release(
    $db, $clock, $ledger, $proposals, new AccessGuard($db, $clock), new Entitlements($db, $clock)
);

$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);

$src = Seed::p01ReleasedBriefing($db, $clock, $scope);
$briefingId = $distribution->composeRoleDistributionBriefing([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'source_kind' => 'P01_BRIEFING',
    'source_id' => $src['released_briefing_id'],
    'briefing_summary_safe' => 'Competitor list pricing rose; renewal conversations may reference it.',
])->value()['role_distribution_briefing_id'];

$admission->submitRoutingProposal([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'governance' => [
        'mission_id' => $src['mission_id'],
        'producing_valkyrie_id' => $src['producing_valkyrie_id'],
        'capability_contract_id' => $src['capability_contract_id'],
    ],
]);
$sessionId = $admission->openSensitivityReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
])->value()['review_session_id'];
$assignmentId = $council->assignParticipant(
    $sessionId, $src['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
)->value()['participant_assignment_id'];
$council->recordAssessment(
    $sessionId, $assignmentId, $src['critic_valkyrie_id'], 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.'
);
$council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');
$admission->admitRouting([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'arbiter_policy_set_id' => $policySetId,
]);
Assert::true(
    $release->releaseRoleDistribution([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $briefingId,
    ])->isOk(),
    'a briefing is released, giving the release-facing audits something to look at',
);

// ============================================================ DISTRIBUTION_AUTHORITY audit

$authorityProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p04.authority', 'DISTRIBUTION_AUTHORITY',
    'P04 distribution authority'
);
Assert::same(
    'CONTROL_PASS',
    $assurance->runDistributionAuthorityAudit(['audit_profile_id' => $authorityProfile])
        ->value()['finding_type'],
    'a briefing routed to a configured role passes the authority audit',
);

// De-configure the role AFTER release. The gate cannot reach back through a release that already
// happened, and this is exactly the gap the audit exists to report.
$db->run(
    'DELETE FROM console_redaction_policy_projections
     WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ?',
    [$scope['tenant_id'], $scope['workspace_id'], 'SALES']
);
$orphaned = $assurance->runDistributionAuthorityAudit(['audit_profile_id' => $authorityProfile]);
Assert::same(
    'SCOPE_VIOLATION',
    $orphaned->value()['finding_type'],
    'a live briefing addressed to a de-configured role is a scope violation',
);
Assert::equalsList([$briefingId], $orphaned->value()['violating_refs'], 'naming the briefing');
Assert::notSame(null, $orphaned->value()['incident_case_id'], 'and opening an incident');

// ============================================================ REDACTION_POLICY audit

// Reconfigure with a TIGHTER policy than the briefing froze. Freezing the profile is right — it
// stops a later edit weakening a verdict — but it means a tightened policy leaves older releases
// behind, and this is what says so.
$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN', 'REDACT_PIPELINE_VALUE'],
]);
$redactionProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p04.redaction', 'REDACTION_POLICY',
    'P04 redaction policy'
);
$missed = $assurance->runRedactionPolicyAudit(['audit_profile_id' => $redactionProfile]);
Assert::same(
    'POLICY_GAP',
    $missed->value()['finding_type'],
    'a tightened policy leaves already-released briefings under-redacted, and the audit says so',
);
Assert::equalsList(
    ['REDACTION_PROFILE_DOES_NOT_COVER_POLICY'],
    $missed->value()['reason_codes'],
    'named precisely',
);

// Loosening the policy back is NOT a finding: over-redaction discloses nothing.
$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => [],
]);
Assert::same(
    'CONTROL_PASS',
    $assurance->runRedactionPolicyAudit(['audit_profile_id' => $redactionProfile])->value()['finding_type'],
    'a frozen profile redacting MORE than the policy now requires is not a gap',
);

// ============================================================ NO_COVERT_EMPLOYEE_TELEMETRY

// The defence-in-depth sweep. Its whole purpose is to catch what got past the primary gates, so it
// is tested against state written directly past them.
Assert::same(
    'CONTROL_PASS',
    $assurance->runNoCovertEmployeeTelemetryAudit(['audit_profile_id' => $provenanceProfile])
        ->value()['finding_type'],
    'the telemetry sweep passes over clean released state',
);

$db->run(
    'UPDATE role_distribution_briefings SET briefing_summary_safe = ?
     WHERE role_distribution_briefing_id = ?',
    ['Renewal owner is anna.berg@acme.example.', $briefingId]
);
$leak = $assurance->runNoCovertEmployeeTelemetryAudit(['audit_profile_id' => $provenanceProfile]);
Assert::same(
    'OVERCLAIM_OR_UI_MISREPRESENTATION',
    $leak->value()['finding_type'],
    'an identifier written into a RELEASED briefing is caught after the fact',
);
Assert::equalsList(
    ['INDIVIDUAL_IDENTIFIER_IN_RELEASED_DISTRIBUTION'],
    $leak->value()['reason_codes'],
    'by its own reason code — the two conditions this sweep checks are distinct failures',
);
Assert::notSame(null, $leak->value()['incident_case_id'], 'and opening an incident');
