<?php

declare(strict_types=1);

/**
 * M05 evidence spine, proved against real MariaDB.
 *
 * The source-admissibility law is what stands between "we fetched a page" and "we may rely on
 * this". Each clause is exercised, because each is a distinct way a governed system silently
 * starts relying on material it has no authority over.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\SourceAdmission;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$acme = Seed::tenantWorkspace($db, $clock, 'acme');
$rival = Seed::tenantWorkspace($db, $clock, 'rival');
$m05 = new EvidenceSpine($db, $clock);

$body = '<html>Pricing: 499 SEK</html>';
$hash = Digest::contentDigest($body);

$capture = static fn (string $sourceRefId, array $overrides = []): array => array_merge([
    'tenant_id' => $acme['tenant_id'],
    'workspace_id' => $acme['workspace_id'],
    'source_ref_id' => $sourceRefId,
    'evidence_type' => 'SOURCE_CAPTURE',
    'captured_content_reference_safe' => 'evidence://sha256/' . substr($hash, 0, 12),
    'content_hash' => $hash,
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
], $overrides);

// --- STATUS: only ACTIVE_FOR_CAPTURE admits ----------------------------------------------------------

$registered = $m05->registerSource(
    $acme['tenant_id'], $acme['workspace_id'], 'WEB_RESOURCE',
    'https://competitor.example/pricing', 'OBSERVE_AND_DERIVE', 'PUBLIC_OR_NON_SENSITIVE',
    'REGISTERED'
);
$notYetAdmitted = $m05->capture($capture($registered));
Assert::true($notYetAdmitted->isRefusal(), 'a REGISTERED source does not yet admit capture');
Assert::same(SourceAdmission::EVIDENCE_SOURCE_NOT_ADMISSIBLE, $notYetAdmitted->reasonCode(), 'refused by name');

$m05->setSourceStatus($registered, 'ACTIVE_FOR_CAPTURE');
$captured = $m05->capture($capture($registered));
Assert::true($captured->isOk(), 'an ACTIVE_FOR_CAPTURE source admits capture');
$evidenceId = $captured->value()['evidence_id'];

// --- the FROZEN admitting authority (TF7-05) ------------------------------------------------------------

$row = $db->one(
    'SELECT admitting_source_status, admitting_source_sensitivity_class, permitted_use_scope
     FROM evidence_objects WHERE evidence_id = ?',
    [$evidenceId]
);
Assert::same('ACTIVE_FOR_CAPTURE', (string) $row['admitting_source_status'], 'the admitting status is frozen on the evidence');
Assert::same(
    'OBSERVE_AND_DERIVE',
    (string) $row['permitted_use_scope'],
    'the RESOLVED use scope comes from the source, not from the producer',
);

// Revoking the source now must NOT rewrite what was true when the evidence was taken.
$m05->setSourceStatus($registered, 'REVOKED');
$afterRevocation = $db->one('SELECT admitting_source_status FROM evidence_objects WHERE evidence_id = ?', [$evidenceId]);
Assert::same(
    'ACTIVE_FOR_CAPTURE',
    (string) $afterRevocation['admitting_source_status'],
    'a later revocation does NOT rewrite history — replay can tell a lawful capture from a later one',
);

// But it stops admitting NEW captures immediately, because status is re-read at capture time.
$afterRevoke = $m05->capture($capture($registered));
Assert::true($afterRevoke->isRefusal(), 'a REVOKED source stops admitting immediately');
Assert::same(SourceAdmission::EVIDENCE_SOURCE_NOT_ADMISSIBLE, $afterRevoke->reasonCode(), 'refused by name');

foreach (['RESTRICTED', 'QUARANTINED'] as $status) {
    $m05->setSourceStatus($registered, $status);
    $refused = $m05->capture($capture($registered));
    Assert::true($refused->isRefusal(), "a {$status} source does not admit capture");
}
$m05->setSourceStatus($registered, 'ACTIVE_FOR_CAPTURE');

// --- SCOPE: cross-tenant and cross-workspace ---------------------------------------------------------------

$crossTenant = $m05->capture($capture($registered, [
    'tenant_id' => $rival['tenant_id'],
    'workspace_id' => $rival['workspace_id'],
]));
Assert::true($crossTenant->isRefusal(), 'another tenant cannot capture from this source');
Assert::same(SourceAdmission::EVIDENCE_SCOPE_MISMATCH, $crossTenant->reasonCode(), 'refused by name');

$otherAcmeWorkspace = Seed::tenantWorkspace($db, $clock, 'acme-b');
$crossWorkspace = $m05->capture($capture($registered, [
    'workspace_id' => $otherAcmeWorkspace['workspace_id'],
]));
Assert::true($crossWorkspace->isRefusal(), 'a workspace-scoped source is not readable from another workspace');
Assert::same(SourceAdmission::EVIDENCE_SCOPE_MISMATCH, $crossWorkspace->reasonCode(), 'refused by name');

// A TENANT-WIDE source (null workspace) IS lawful for any workspace in its tenant — the column is
// nullable by design for this object class.
$tenantWide = $m05->registerSource(
    $acme['tenant_id'], null, 'WEB_RESOURCE', 'https://registry.example/x',
    'OBSERVE_ONLY', 'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
);
$fromOtherWorkspace = $m05->capture($capture($tenantWide, [
    'workspace_id' => $otherAcmeWorkspace['workspace_id'],
    'tenant_id' => $acme['tenant_id'],
]));
Assert::true($fromOtherWorkspace->isOk(), 'a tenant-wide source is lawful across workspaces in its tenant');

// --- USE SCOPE: the source binds it ----------------------------------------------------------------------------

$contradicting = $m05->capture($capture($registered, ['permitted_use_scope' => 'CUSTOMER_VISIBLE_ALLOWED']));
Assert::true($contradicting->isRefusal(), 'a producer cannot invent a use scope the source did not declare');
Assert::same(SourceAdmission::EVIDENCE_USE_SCOPE_INCOMPATIBLE, $contradicting->reasonCode(), 'refused by name');

$restating = $m05->capture($capture($registered, ['permitted_use_scope' => 'OBSERVE_AND_DERIVE']));
Assert::true($restating->isOk(), 'a producer MAY restate the source scope exactly');

// A source declaring NO scope cannot derive one.
$scopeless = $m05->registerSource(
    $acme['tenant_id'], $acme['workspace_id'], 'WEB_RESOURCE', 'https://x.example/',
    null, 'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
);
$undeclarable = $m05->capture($capture($scopeless));
Assert::true($undeclarable->isRefusal(), 'a scopeless source with no producer scope has nothing to record');
Assert::same(SourceAdmission::EVIDENCE_USE_SCOPE_INCOMPATIBLE, $undeclarable->reasonCode(), 'refused by name');

$producerStated = $m05->capture($capture($scopeless, ['permitted_use_scope' => 'OBSERVE_ONLY']));
Assert::true($producerStated->isOk(), 'but the producer MAY state one when the source declares none');

// --- SENSITIVITY: never classified below the source -------------------------------------------------------------

$restricted = $m05->registerSource(
    $acme['tenant_id'], $acme['workspace_id'], 'WEB_RESOURCE', 'https://feed.example/',
    'OBSERVE_ONLY', 'RESTRICTED_REVIEW', 'ACTIVE_FOR_CAPTURE'
);
$downgrade = $m05->capture($capture($restricted, ['sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE']));
Assert::true($downgrade->isRefusal(), 'evidence cannot be classified BELOW its source');
Assert::same(SourceAdmission::EVIDENCE_SENSITIVITY_DOWNGRADE, $downgrade->reasonCode(), 'refused by name');

$sameLevel = $m05->capture($capture($restricted, ['sensitivity_class' => 'RESTRICTED_REVIEW']));
Assert::true($sameLevel->isOk(), 'classifying at the source level is allowed');

$prohibited = $m05->registerSource(
    $acme['tenant_id'], $acme['workspace_id'], 'WEB_RESOURCE', 'https://banned.example/',
    'OBSERVE_ONLY', 'PROHIBITED_IN_V1', 'ACTIVE_FOR_CAPTURE'
);
$banned = $m05->capture($capture($prohibited, ['sensitivity_class' => 'PROHIBITED_IN_V1']));
Assert::true($banned->isRefusal(), 'a PROHIBITED_IN_V1 source is refused outright');
Assert::same(SourceAdmission::EVIDENCE_PROHIBITED_SENSITIVITY, $banned->reasonCode(), 'refused by name');

// An UNKNOWN sensitivity class sorts as RESTRICTED_REVIEW — fail closed, never as "public".
Assert::same(
    SourceAdmission::sensitivityRank('RESTRICTED_REVIEW'),
    SourceAdmission::sensitivityRank('SOMETHING_NOBODY_DEFINED'),
    'an unknown sensitivity class fails CLOSED, never as public',
);

// --- a missing source ----------------------------------------------------------------------------------------------

$missing = $m05->capture($capture(Ids::new()));
Assert::true($missing->isRefusal(), 'an unknown source reference is refused');
Assert::same(SourceAdmission::EVIDENCE_SOURCE_REFERENCE_MISSING, $missing->reasonCode(), 'refused by name');

// --- raw artifacts: bounded excerpt, bound lineage --------------------------------------------------------------------

$artifact = $m05->recordRawArtifact([
    'tenant_id' => $acme['tenant_id'],
    'workspace_id' => $acme['workspace_id'],
    'source_ref_id' => $registered,
    'artifact_type' => 'TEXT_CAPTURE',
    'content_hash' => $hash,
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'storage_reference_safe' => 'storage://evidence/sha256/' . substr($hash, 0, 8),
    'excerpt_safe' => substr($body, 0, 200),
    'access_classification' => 'INTERNAL_DEMO',
]);
Assert::true($artifact->isOk(), 'a raw artifact is recorded');
$artifactId = $artifact->value()['raw_artifact_id'];

$oversize = $m05->recordRawArtifact([
    'tenant_id' => $acme['tenant_id'],
    'workspace_id' => $acme['workspace_id'],
    'source_ref_id' => $registered,
    'artifact_type' => 'TEXT_CAPTURE',
    'content_hash' => $hash,
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'excerpt_safe' => str_repeat('x', EvidenceSpine::RAW_ARTIFACT_EXCERPT_MAX_CHARS + 1),
    'access_classification' => 'INTERNAL_DEMO',
]);
Assert::true($oversize->isRefusal(), 'an oversize excerpt is REFUSED');
Assert::same(
    'RAW_ARTIFACT_EXCERPT_TOO_LARGE',
    $oversize->reasonCode(),
    'not truncated — truncation would silently corrupt replay inputs while looking like success',
);

// An excerpt AT the limit is accepted, so the bound is exact rather than approximate.
$atLimit = $m05->recordRawArtifact([
    'tenant_id' => $acme['tenant_id'],
    'workspace_id' => $acme['workspace_id'],
    'source_ref_id' => $registered,
    'artifact_type' => 'TEXT_CAPTURE',
    'content_hash' => $hash,
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'excerpt_safe' => str_repeat('x', EvidenceSpine::RAW_ARTIFACT_EXCERPT_MAX_CHARS),
    'access_classification' => 'INTERNAL_DEMO',
]);
Assert::true($atLimit->isOk(), 'an excerpt exactly at the 64,000-character limit is stored');

// --- artifact lineage binding (TF7-04) ----------------------------------------------------------------------------------

$boundEvidence = $m05->capture($capture($registered, ['raw_artifact_id' => $artifactId]));
Assert::true($boundEvidence->isOk(), 'evidence citing a matching artifact is captured');

$wrongHash = $m05->capture($capture($registered, [
    'raw_artifact_id' => $artifactId,
    'content_hash' => Digest::contentDigest('different content'),
]));
Assert::true($wrongHash->isRefusal(), 'evidence cannot claim an artifact whose content hash differs');
Assert::same(
    SourceAdmission::EVIDENCE_RAW_ARTIFACT_NOT_BOUND,
    $wrongHash->reasonCode(),
    'the evidence would otherwise claim an artifact pedigree for content that artifact never held',
);

$otherSourceArtifact = $m05->recordRawArtifact([
    'tenant_id' => $acme['tenant_id'],
    'workspace_id' => $acme['workspace_id'],
    'source_ref_id' => $scopeless,
    'artifact_type' => 'TEXT_CAPTURE',
    'content_hash' => $hash,
    'capture_method' => 'GOVERNED_HTTPS_GET',
    'access_classification' => 'INTERNAL_DEMO',
])->value()['raw_artifact_id'];

$wrongSource = $m05->capture($capture($registered, ['raw_artifact_id' => $otherSourceArtifact]));
Assert::true($wrongSource->isRefusal(), 'evidence cannot cite an artifact belonging to a DIFFERENT source');
Assert::same(SourceAdmission::EVIDENCE_RAW_ARTIFACT_NOT_BOUND, $wrongSource->reasonCode(), 'refused by name');

// --- host-provenance capture is independently re-verified -------------------------------------------------------------------

$unverifiable = $m05->capture($capture($registered, [
    'evidence_type' => 'HOST_RETRIEVAL_CAPTURE',
    'tool_request_id' => Ids::new(),
    'tool_authorization_decision_id' => Ids::new(),
    'runtime_host_execution_ref' => 'exec-' . Ids::token(6),
]));
Assert::true($unverifiable->isRefusal(), 'a host capture with no real M06/M01 trail is refused');
Assert::same(
    SourceAdmission::EVIDENCE_HOST_PROVENANCE_INVALID,
    $unverifiable->reasonCode(),
    'M05 NEVER trusts the caller\'s claim about the trail',
);

$incomplete = $m05->capture($capture($registered, ['evidence_type' => 'HOST_RETRIEVAL_CAPTURE']));
Assert::true($incomplete->isRefusal(), 'a host capture missing its trail identifiers is refused');
Assert::same(SourceAdmission::EVIDENCE_HOST_PROVENANCE_INVALID, $incomplete->reasonCode(), 'refused by name');

// --- evidence is append-only ---------------------------------------------------------------------------------------------------

try {
    $db->run('DELETE FROM evidence_objects WHERE evidence_id = ?', [$evidenceId]);
    Assert::fail('evidence_objects should reject DELETE');
} catch (\PDOException) {
    Assert::pass();
}
