<?php

declare(strict_types=1);

/**
 * M16 governed release output, proved against real MariaDB.
 *
 * The dependency ordering — source-use profile -> artifact -> projection -> visibility activation —
 * is what makes a released claim traceable to what every contributing source permitted.
 *
 * The most important assertions here are the THREE independent storage pins that make every
 * audience beyond TENANT_INTERNAL designed-inert (V1-GAP-093). They are proven by attempting the
 * write and being refused by the DATABASE, not by trusting application code.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
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
$m16 = new ReleaseOutput($db, $clock, $ledger);

// ============================================================ THE inertness pins

// PIN 1 — a permitting visibility profile for a wider audience cannot be WRITTEN AT ALL.
foreach (['CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM'] as $wider) {
    try {
        $db->insert('output_visibility_profiles', [
            'output_visibility_profile_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'audience_class' => $wider,
            'profile_version' => 1,
            'activation_permitted' => 1,
            'required_permission_key' => 'briefing.read',
            'status' => 'ACTIVE',
            'created_at' => $clock->now(),
            'updated_at' => $clock->now(),
        ]);
        Assert::fail("a permitting profile for {$wider} should be structurally impossible");
    } catch (\PDOException) {
        Assert::pass();
    }
}

$m16->seedVisibilityProfiles($scope['tenant_id']);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT activation_permitted FROM output_visibility_profiles
         WHERE tenant_id = ? AND audience_class = 'TENANT_INTERNAL'",
        [$scope['tenant_id']]
    ),
    'TENANT_INTERNAL is an audience this projection may activate',
);
foreach (['CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM'] as $wider) {
    Assert::same(
        0,
        (int) $db->scalar(
            'SELECT activation_permitted FROM output_visibility_profiles WHERE tenant_id = ? AND audience_class = ?',
            [$scope['tenant_id'], $wider]
        ),
        "{$wider} is seeded EXPLICITLY inert, so its inertness is a recorded fact rather than an omission",
    );
}

// ============================================================ the governed chain

// The WHOLE governed chain, run through the real modules: valkyrie, contract, mission, proposal,
// evidence, frozen bundle, council review, arbiter verdict, M10 release record. Every step is a
// gate, and the foreign keys below would reject a shortcut anyway.
$governed = Seed::governedReleaseChain($db, $clock, $scope);
Assert::true($governed['verdict'] !== null, 'the governed chain reaches a verdict');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    $governed['verdict']['verdict'],
    'and the verdict permits an informational release',
);
Assert::true($governed['release'] !== null, 'and an M10 release record is appended');

$missionId = $governed['mission_id'];
$chain = $governed;

// ============================================================ the source-use profile

$profile = $m16->compileSourceUseProfile($scope['tenant_id'], $scope['workspace_id'], [$chain['source_ref_id']]);
Assert::true($profile->isOk(), 'a source-use profile compiles');
Assert::same('COMPILED_ALLOW', $profile->value()['status'], 'an approved source compiles to ALLOW');
Assert::equalsList([], $profile->value()['hold_reasons'], 'with no hold reasons');
$profileId = $profile->value()['release_source_use_profile_id'];

$noSources = $m16->compileSourceUseProfile($scope['tenant_id'], $scope['workspace_id'], []);
Assert::true($noSources->isRefusal(), 'an artifact with no contributing source is refused');
Assert::same(
    'SOURCE_USE_NO_CONTRIBUTING_SOURCES',
    $noSources->reasonCode(),
    'an unprovenanced release is what this spine exists to prevent',
);

// A source whose card is not approved HOLDS — and the hold is compiled, not thrown away.
$heldChain = Seed::p01SignalChain($db, $clock, $scope, $missionId, null, ['review_status' => 'blocked']);
$heldProfile = $m16->compileSourceUseProfile($scope['tenant_id'], $scope['workspace_id'], [$heldChain['source_ref_id']]);
Assert::same('COMPILED_HELD', $heldProfile->value()['status'], 'an unapproved source compiles to HELD');
Assert::true(
    in_array('SOURCE_CARD_NOT_APPROVED', $heldProfile->value()['hold_reasons'], true),
    'and the hold names WHY, which a missing profile could not',
);

// An expired review window holds too, even without an explicit expired status.
$expiredChain = Seed::p01SignalChain($db, $clock, $scope, $missionId, null, [
    'review_expires_at' => '2020-01-01T00:00:00.000Z',
]);
$expiredProfile = $m16->compileSourceUseProfile($scope['tenant_id'], $scope['workspace_id'], [$expiredChain['source_ref_id']]);
Assert::true(
    in_array('SOURCE_CARD_REVIEW_EXPIRED', $expiredProfile->value()['hold_reasons'], true),
    'a lapsed review window holds, even without an explicit expired status',
);

// The STRICTEST contributor governs: one internal-only source constrains the whole artifact.
$internalOnly = Seed::p01SignalChain($db, $clock, $scope, $missionId, null, [
    'license_class' => 'LICENSED_INTERNAL_ONLY',
    'customer_output_allowed' => 0,
]);
$mixed = $m16->compileSourceUseProfile(
    $scope['tenant_id'], $scope['workspace_id'],
    [$chain['source_ref_id'], $internalOnly['source_ref_id']]
);
Assert::same(
    'INTERNAL_ONLY',
    $mixed->value()['effective_license_ceiling'],
    'one internal-only source makes the whole artifact internal-only, however permissive its siblings',
);
Assert::same(
    'OPERATOR_ONLY',
    $mixed->value()['audience_ceiling'],
    'and the AUDIENCE ceiling narrows with it, rather than staying at the permissive default',
);
Assert::same(
    'TENANT_INTERNAL',
    $profile->value()['audience_ceiling'],
    'while a fully permissive profile still caps at TENANT_INTERNAL, never wider',
);

// ============================================================ the artifact

/** A released_briefings row for the artifact to reference. */
$mintBriefing = static function (string $decisionRecordId, string $proposalId) use ($db, $clock, $scope, $governed): string {
    $briefingId = Ids::new();
    $db->insert('released_briefings', [
        'released_briefing_id' => $briefingId,
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'briefing_kind' => 'BATTLECARD_BRIEF',
        'company_signal_id' => $governed['company_signal_id'],
        'proposal_id' => $proposalId,
        'decision_record_id' => $decisionRecordId,
        'released_at' => $clock->now(),
    ]);

    return $briefingId;
};

$release = [
    'decision_record_id' => $governed['release']['decision_record_id'],
    'proposal_id' => $governed['proposal_id'],
    'arbiter_verdict_id' => $governed['verdict']['arbiter_verdict_id'],
];

$briefingId = $mintBriefing($release['decision_record_id'], $release['proposal_id']);

$artifact = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefingId,
    'decision_record_id' => $release['decision_record_id'],
    'arbiter_verdict_id' => $release['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [
        ['elementClass' => 'CLAIM', 'textSafe' => 'List price rose from 449 to 499 SEK.', 'confidence' => 'HIGH'],
    ],
    'uncertainty_summary_safe' => 'Applicability to existing customers is not observed.',
]);
Assert::true($artifact->isOk(), 'an artifact assembles');
Assert::same('ASSEMBLED', $artifact->value()['outcome'], 'as a first assembly');
Assert::same('READY_FOR_RELEASE', $artifact->value()['status'], 'and is releasable when the profile allows');
$artifactId = $artifact->value()['release_artifact_id'];

// Idempotency: a retry REPLAYS rather than re-validating state the first pass moved.
$retry = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefingId,
    'decision_record_id' => $release['decision_record_id'],
    'arbiter_verdict_id' => $release['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [],
    'uncertainty_summary_safe' => 'different content on the retry',
]);
Assert::same('IDEMPOTENT_REPLAY', $retry->value()['outcome'], 'a retry replays');
Assert::same($artifactId, $retry->value()['release_artifact_id'], 'returning the SAME artifact, not a second one');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts WHERE decision_record_id = ?', [$release['decision_record_id']]),
    'exactly one artifact exists for the release record',
);

// --- no M10 record, no artifact, ever ---------------------------------------------------------------

$unbound = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefingId,
    'decision_record_id' => Ids::new(),
    'arbiter_verdict_id' => $release['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [],
    'uncertainty_summary_safe' => 'x',
]);
Assert::true($unbound->isRefusal(), 'an artifact with no release record behind it is refused');
Assert::same(
    'DECISION_RECORD_NOT_FOUND_OR_WRONG_TYPE',
    $unbound->reasonCode(),
    'no M10 record, no artifact, ever',
);

// --- an artifact must state its uncertainty -----------------------------------------------------------

$governed2 = Seed::governedReleaseChain($db, $clock, $scope);
$briefing2 = $mintBriefing($governed2['release']['decision_record_id'], $governed2['proposal_id']);
$noUncertainty = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefing2,
    'decision_record_id' => $governed2['release']['decision_record_id'],
    'arbiter_verdict_id' => $governed2['verdict']['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [],
    'uncertainty_summary_safe' => '   ',
]);
Assert::true($noUncertainty->isRefusal(), 'an artifact stating no uncertainty is refused');
Assert::same(
    'ARTIFACT_UNCERTAINTY_SUMMARY_REQUIRED',
    $noUncertainty->reasonCode(),
    'silence about uncertainty is a claim of certainty nobody established',
);

// ============================================================ projection and activation

$m16->releaseInternal($artifactId);
Assert::same(
    'RELEASED_INTERNAL',
    (string) $db->scalar('SELECT status FROM release_artifacts WHERE release_artifact_id = ?', [$artifactId]),
    'the artifact reaches RELEASED_INTERNAL',
);

// PIN 2 — an artifact can never reach a customer-visible status.
try {
    $db->run(
        "UPDATE release_artifacts SET status = 'RELEASED_CUSTOMER_VISIBLE' WHERE release_artifact_id = ?",
        [$artifactId]
    );
    Assert::fail('RELEASED_CUSTOMER_VISIBLE should be structurally impossible');
} catch (\PDOException) {
    Assert::pass();
}

$projection = $m16->projectForAudience($artifactId, 'TENANT_INTERNAL');
Assert::true($projection->isOk(), 'the artifact projects for TENANT_INTERNAL');
Assert::same('PROJECTED', $projection->value()['status'], 'and is PROJECTED');
$projectionId = $projection->value()['customer_output_projection_id'];

$activation = $m16->activateInternalVisibility($artifactId, $scope['tenant_id'], $scope['workspace_id']);
Assert::true($activation->isOk(), 'internal visibility activates');
Assert::same('ACTIVATED', $activation->value()['event_kind'], 'with an ACTIVATED event');

// PIN 3 — an ACTIVATED event for a wider audience cannot be written.
foreach (['CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM'] as $wider) {
    try {
        $db->insert('release_visibility_activations', [
            'release_visibility_activation_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'customer_output_projection_id' => $projectionId,
            'release_artifact_id' => $artifactId,
            'audience_class' => $wider,
            'event_kind' => 'ACTIVATED',
            'authority_decision_record_id' => $release['decision_record_id'],
            'reason_code' => 'INTERNAL_SCOPE_OF_RELEASE_VERDICT',
            'event_sequence' => 99,
            'occurred_at' => $clock->now(),
        ]);
        Assert::fail("an ACTIVATED event for {$wider} should be structurally impossible");
    } catch (\PDOException) {
        Assert::pass();
    }
}

// The application layer refuses a wider activation too, before the database has to. The check
// order matters: a MISSING projection is caught first, so the profile check is only reached once
// a projection exists — which is what this creates, so the refusal under test is the authority
// one rather than an incidental absence.
$widerNoProjection = $m16->activateInternalVisibility(
    $artifactId, $scope['tenant_id'], $scope['workspace_id'], 'CUSTOMER_VISIBLE'
);
Assert::same(
    'PROJECTION_REFUSED_OR_MISSING',
    $widerNoProjection->reasonCode(),
    'with no projection yet, that absence is what refuses first',
);

// Ask for the wider projection. It is REFUSED — the audience ceiling `compileSourceUseProfile`
// wrote caps at TENANT_INTERNAL, so no licence, however permissive, produces a PROJECTED row for
// a wider audience. The refused row carries the reason and NO content.
$widerProjection = $m16->projectForAudience($artifactId, 'CUSTOMER_VISIBLE');
Assert::true($widerProjection->isOk(), 'the projection call itself answers rather than throwing');
Assert::same('REFUSED', $widerProjection->value()['status'], 'and its answer is REFUSED');
$widerRow = $db->one(
    'SELECT status, refusal_reason_codes, content_elements_safe_json FROM customer_output_projections
     WHERE release_artifact_id = ? AND audience_class = ?',
    [$artifactId, 'CUSTOMER_VISIBLE']
);
Assert::same('REFUSED', (string) $widerRow['status'], 'the stored row is REFUSED');
Assert::same(
    ['AUDIENCE_ABOVE_SOURCE_USE_CEILING'],
    json_decode((string) $widerRow['refusal_reason_codes'], true),
    'named by the specific upstream reason code, not a generic failure',
);
Assert::same(
    '[]',
    (string) $widerRow['content_elements_safe_json'],
    'and it carries no content — a refused projection is not content one query away from exposure',
);

// So the activation refuses at the PROJECTION gate, before the authority row is even consulted.
$widerActivation = $m16->activateInternalVisibility(
    $artifactId, $scope['tenant_id'], $scope['workspace_id'], 'CUSTOMER_VISIBLE'
);
Assert::true($widerActivation->isRefusal(), 'a wider activation is refused');
Assert::same(
    'PROJECTION_REFUSED_OR_MISSING',
    $widerActivation->reasonCode(),
    'at the projection gate — a wider audience cannot obtain a PROJECTED row to activate',
);

// A REFUSED projection cannot be cleared away and retried into a permitting one: the table is
// append-only, and the unique (artifact, audience) index means the refusal, once recorded, is the
// standing answer for that pair.
try {
    $db->run('DELETE FROM customer_output_projections WHERE release_artifact_id = ? AND audience_class = ?', [
        $artifactId, 'CUSTOMER_VISIBLE',
    ]);
    Assert::fail('deleting a refused projection should be structurally impossible');
} catch (\PDOException) {
    Assert::pass();
}

// The authority row is an INDEPENDENT layer, so prove it independently. Forge a PROJECTED
// CUSTOMER_VISIBLE row directly in storage on a FRESH artifact, bypassing `projectForAudience`
// entirely — the state the projection gate above is meant to make unreachable. If it were ever
// reached (a later change to the ceiling rule, or a write path that skips the module) the
// activation must still refuse. Nothing in the application ever writes this row.
$governed3 = Seed::governedReleaseChain($db, $clock, $scope);
$briefing3 = $mintBriefing($governed3['release']['decision_record_id'], $governed3['proposal_id']);
$artifact3 = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefing3,
    'decision_record_id' => $governed3['release']['decision_record_id'],
    'arbiter_verdict_id' => $governed3['verdict']['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [
        ['elementClass' => 'CLAIM', 'textSafe' => 'List price rose from 449 to 499 SEK.', 'confidence' => 'HIGH'],
    ],
    'uncertainty_summary_safe' => 'Applicability to existing customers is not observed.',
]);
Assert::true($artifact3->isOk(), 'a third artifact assembles, to carry the forged projection');
$forgedArtifactId = $artifact3->value()['release_artifact_id'];

$db->insert('customer_output_projections', [
    'customer_output_projection_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'release_artifact_id' => $forgedArtifactId,
    'audience_class' => 'CUSTOMER_VISIBLE',
    'status' => 'PROJECTED',
    'refusal_reason_codes' => '[]',
    'content_elements_safe_json' => '[]',
    'redaction_decisions_json' => '[]',
    'contains_claim' => 0,
    'caveat_blocks_present' => 1,
    'redaction_engine_version' => '1',
    'projection_digest' => str_repeat('0', 64),
    'projected_at' => $clock->now(),
]);
$forgedActivation = $m16->activateInternalVisibility(
    $forgedArtifactId, $scope['tenant_id'], $scope['workspace_id'], 'CUSTOMER_VISIBLE'
);
Assert::true($forgedActivation->isRefusal(), 'even a PROJECTED wider row cannot be activated');
Assert::same(
    'VISIBILITY_ACTIVATION_NOT_PERMITTED',
    $forgedActivation->reasonCode(),
    'default-deny on the authority row, which for a wider audience can never permit',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM release_visibility_activations
         WHERE audience_class <> 'OPERATOR_ONLY' AND audience_class <> 'TENANT_INTERNAL'"
    ),
    'and no wider activation event exists anywhere, by any path taken in this file',
);

// --- the audience ceiling binds at the INTERNAL end too -----------------------------------------------------

// An OPERATOR_ONLY audience ceiling must refuse a TENANT_INTERNAL projection. An earlier revision
// compared this ceiling against the LICENCE vocabulary ('PROHIBITED' / 'CUSTOMER_VISIBLE_ALLOWED'),
// which no audience-class value can equal: every wider audience was refused for the right reason by
// accident, but every INTERNAL audience was permitted unconditionally, so the ceiling did not bind
// where it was narrowest. The comparison now stays inside one vocabulary, and this proves it.
$governed4 = Seed::governedReleaseChain($db, $clock, $scope);
$briefing4 = $mintBriefing($governed4['release']['decision_record_id'], $governed4['proposal_id']);
$narrowArtifact = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefing4,
    'decision_record_id' => $governed4['release']['decision_record_id'],
    'arbiter_verdict_id' => $governed4['verdict']['arbiter_verdict_id'],
    'source_use_profile_id' => $mixed->value()['release_source_use_profile_id'],
    'content_elements' => [
        ['elementClass' => 'CLAIM', 'textSafe' => 'List price rose from 449 to 499 SEK.', 'confidence' => 'HIGH'],
    ],
    'uncertainty_summary_safe' => 'Applicability to existing customers is not observed.',
]);
Assert::true($narrowArtifact->isOk(), 'an artifact assembles under an OPERATOR_ONLY audience ceiling');
$narrowArtifactId = $narrowArtifact->value()['release_artifact_id'];

$narrowInternal = $m16->projectForAudience($narrowArtifactId, 'TENANT_INTERNAL');
Assert::same(
    'REFUSED',
    $narrowInternal->value()['status'],
    'TENANT_INTERNAL is above an OPERATOR_ONLY ceiling, so the projection is refused',
);
Assert::same(
    ['AUDIENCE_ABOVE_SOURCE_USE_CEILING'],
    json_decode(
        (string) $db->scalar(
            'SELECT refusal_reason_codes FROM customer_output_projections
             WHERE release_artifact_id = ? AND audience_class = ?',
            [$narrowArtifactId, 'TENANT_INTERNAL']
        ),
        true,
    ),
    'named by the same specific reason code as the wider refusal',
);

$narrowOperator = $m16->projectForAudience($narrowArtifactId, 'OPERATOR_ONLY');
Assert::same(
    'PROJECTED',
    $narrowOperator->value()['status'],
    'while the audience AT the ceiling still projects — the rule narrows, it does not just refuse',
);

// --- cross-tenant is not an existence oracle ---------------------------------------------------------------

$rival = Seed::tenantWorkspace($db, $clock, 'rival');
$foreign = $m16->activateInternalVisibility($artifactId, $rival['tenant_id'], $rival['workspace_id']);
$missing = $m16->activateInternalVisibility(Ids::new(), $rival['tenant_id'], $rival['workspace_id']);
Assert::same(
    $foreign->reasonCode(),
    $missing->reasonCode(),
    'a FOREIGN artifact and a MISSING one answer identically — no cross-tenant existence oracle',
);
Assert::same('SCOPE_MISMATCH', $foreign->reasonCode(), 'both by the same canonical code');

// --- deactivation is the rollback lever ---------------------------------------------------------------------

$deactivation = $m16->deactivateVisibility($projectionId, 'ROLLBACK');
Assert::true($deactivation->isOk(), 'visibility deactivates');
Assert::same('DEACTIVATED', $deactivation->value()['event_kind'], 'with a DEACTIVATED event');
Assert::true(
    $deactivation->value()['event_sequence'] > $activation->value()['event_sequence'],
    'appended AFTER the activation, so the read model sees the withdrawal as the latest state',
);
Assert::same(
    2,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM release_visibility_activations WHERE customer_output_projection_id = ?',
        [$projectionId]
    ),
    'both events are preserved — the history shows the content WAS visible and then was withdrawn',
);

// Withdrawing an already-withdrawn projection is refused, not appended twice: a second DEACTIVATED
// event would record a withdrawal that withdrew nothing.
$doubleWithdraw = $m16->deactivateVisibility($projectionId, 'ROLLBACK');
Assert::true($doubleWithdraw->isRefusal(), 'a second withdrawal is refused');
Assert::same(
    'VISIBILITY_NOT_CURRENTLY_ACTIVE',
    $doubleWithdraw->reasonCode(),
    'named specifically, so the audit trail distinguishes it from a missing projection',
);
Assert::same(
    2,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM release_visibility_activations WHERE customer_output_projection_id = ?',
        [$projectionId]
    ),
    'and no third event was appended',
);

// A projection that was never activated cannot be withdrawn either.
$neverActive = $m16->deactivateVisibility(
    (string) $db->scalar(
        'SELECT customer_output_projection_id FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class = ?',
        [$narrowArtifactId, 'OPERATOR_ONLY']
    ),
    'ROLLBACK',
);
Assert::same(
    'VISIBILITY_NOT_CURRENTLY_ACTIVE',
    $neverActive->reasonCode(),
    'a projection that was never activated has nothing to withdraw',
);

// --- artifact rollback preserves the record --------------------------------------------------------------------

$correction = $ledger->supersede(
    $release['decision_record_id'], 'SUPERSEDED_BY_NEW_DECISION', 'M11', Ids::new(), 'stand-down'
);
$rolledBack = $m16->rollbackArtifact(
    $artifactId, $correction->value()['successor_decision_record_id'], 'Owner stand-down.'
);
Assert::true($rolledBack->isOk(), 'the artifact rolls back');
Assert::same('ROLLED_BACK', $rolledBack->value()['status'], 'to ROLLED_BACK');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts WHERE release_artifact_id = ?', [$artifactId]),
    'and the artifact row is PRESERVED — the record of what was released is what makes the rollback provable',
);
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM release_lineage_events WHERE release_artifact_id = ? AND kind = 'ROLLBACK'", [$artifactId]),
    'with a lineage event recording the authority for it',
);

// Rolling back again REPLAYS. A second ROLLBACK lineage event under a different authority would
// make the record say two separate stand-downs happened.
$rollbackAgain = $m16->rollbackArtifact(
    $artifactId, $correction->value()['successor_decision_record_id'], 'Owner stand-down, again.'
);
Assert::true($rollbackAgain->isOk(), 'rolling back an already-rolled-back artifact answers OK');
Assert::same('REPLAYED', $rollbackAgain->value()['outcome'], 'as a replay, not a second stand-down');
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM release_lineage_events WHERE release_artifact_id = ? AND kind = 'ROLLBACK'", [$artifactId]),
    'and the lineage still records exactly one rollback',
);

// --- rollback WITHDRAWS live visibility ---------------------------------------------------------------------

// The case above deactivated first, so it never exercised the withdrawal. This one rolls back an
// artifact whose projection is CURRENTLY ACTIVATED. The read model decides visibility from the
// LATEST visibility event, so a rollback that moved only the artifact status would leave the
// content visible while the lineage claimed it had been withdrawn.
$governed5 = Seed::governedReleaseChain($db, $clock, $scope);
$briefing5 = $mintBriefing($governed5['release']['decision_record_id'], $governed5['proposal_id']);
$liveArtifact = $m16->assembleArtifact([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'released_briefing_id' => $briefing5,
    'decision_record_id' => $governed5['release']['decision_record_id'],
    'arbiter_verdict_id' => $governed5['verdict']['arbiter_verdict_id'],
    'source_use_profile_id' => $profileId,
    'content_elements' => [
        ['elementClass' => 'CLAIM', 'textSafe' => 'List price rose from 449 to 499 SEK.', 'confidence' => 'HIGH'],
    ],
    'uncertainty_summary_safe' => 'Applicability to existing customers is not observed.',
]);
$liveArtifactId = $liveArtifact->value()['release_artifact_id'];
$m16->releaseInternal($liveArtifactId);
$liveProjectionId = $m16->projectForAudience($liveArtifactId, 'TENANT_INTERNAL')
    ->value()['customer_output_projection_id'];
Assert::true(
    $m16->activateInternalVisibility($liveArtifactId, $scope['tenant_id'], $scope['workspace_id'])->isOk(),
    'the artifact is genuinely visible before the rollback',
);

$latestKind = static fn (string $projectionId): string => (string) $db->scalar(
    'SELECT event_kind FROM release_visibility_activations
     WHERE customer_output_projection_id = ? ORDER BY event_sequence DESC LIMIT 1',
    [$projectionId]
);
Assert::same('ACTIVATED', $latestKind($liveProjectionId), 'the latest event says ACTIVATED');

$liveCorrection = $ledger->supersede(
    $governed5['release']['decision_record_id'], 'SUPERSEDED_BY_NEW_DECISION', 'M11', Ids::new(), 'stand-down'
);
$liveRollback = $m16->rollbackArtifact(
    $liveArtifactId, $liveCorrection->value()['successor_decision_record_id'], 'Owner stand-down.'
);
Assert::true($liveRollback->isOk(), 'the live artifact rolls back');
Assert::equalsList(
    [$liveProjectionId],
    $liveRollback->value()['deactivated_projection_ids'],
    'and the rollback REPORTS which projections it withdrew, rather than withdrawing silently',
);
Assert::same(
    'DEACTIVATED',
    $latestKind($liveProjectionId),
    'the latest event now says DEACTIVATED — the content is no longer visible, not merely marked',
);
Assert::same(
    'ROLLBACK',
    (string) $db->scalar(
        'SELECT reason_code FROM release_visibility_activations
         WHERE customer_output_projection_id = ? ORDER BY event_sequence DESC LIMIT 1',
        [$liveProjectionId]
    ),
    'carrying the ROLLBACK reason from the closed vocabulary',
);
Assert::same(
    2,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM release_visibility_activations WHERE customer_output_projection_id = ?',
        [$liveProjectionId]
    ),
    'both events preserved — the history still shows the content WAS visible',
);

// --- rollback is not a delete -------------------------------------------------------------------------------

// §19: a rollback must never be implemented as DELETE FROM decision_records / evidence / release.
// The stand-down is provable only because everything it stood down still exists.
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM decision_records WHERE decision_record_id = ?', [
        $governed5['release']['decision_record_id'],
    ]),
    'the M10 decision record the release rested on survives the rollback',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts WHERE release_artifact_id = ?', [$liveArtifactId]),
    'so does the artifact row',
);
Assert::true(
    (int) $db->scalar('SELECT COUNT(*) FROM evidence_bundles WHERE evidence_bundle_id = ?', [$governed5['evidence_bundle_id']]) === 1,
    'and the evidence bundle behind it',
);
// The rollback wrote NO decision record of its own — M10 records the correction, M16 records the
// stand-down — so the chain must still verify unchanged from genesis.
$streamId = (string) $db->scalar(
    'SELECT decision_ledger_stream_id FROM decision_ledger_streams WHERE tenant_id = ? LIMIT 1',
    [$scope['tenant_id']]
);
Assert::same(
    'PASS',
    $ledger->verifyStream($streamId)['status'],
    'and the decision hash chain still verifies end to end after every rollback in this file',
);

// ============================================================ the audience vocabulary is CLOSED

// BLOCKED_QUARANTINED is a terminal-deny PSEUDO-AUDIENCE: a rendering state for content that has
// been withheld, never a place content is sent. It belongs to the vocabulary because a caller can
// name it and the answer must be a stated refusal — and it is deliberately absent from the ladder,
// from every storage CHECK, and from the seeded profiles, so no row anywhere could make it a target.
Assert::true(
    in_array('BLOCKED_QUARANTINED', ReleaseOutput::AUDIENCE_CLASS, true),
    'the pseudo-audience IS in the vocabulary — a name a caller can use must have a stated answer',
);
Assert::false(
    in_array('BLOCKED_QUARANTINED', ReleaseOutput::AUDIENCE_ORDER, true),
    'and is NOT on the ladder — "narrower than a refusal" is not a question with an answer',
);
Assert::same(
    ReleaseOutput::AUDIENCE_ORDER,
    array_values(array_diff(ReleaseOutput::AUDIENCE_CLASS, ReleaseOutput::PSEUDO_AUDIENCES)),
    'the ladder is exactly the vocabulary minus its pseudo-audiences — nothing else is off it',
);

// The ladder is ORDERED, inner to wider, and the order is the monotonicity law's subject.
Assert::same(0, ReleaseOutput::audienceOrderIndex('OPERATOR_ONLY'), 'OPERATOR_ONLY is innermost');
Assert::true(
    ReleaseOutput::audienceOrderIndex('TENANT_INTERNAL') < ReleaseOutput::audienceOrderIndex('CUSTOMER_VISIBLE'),
    'and TENANT_INTERNAL is narrower than CUSTOMER_VISIBLE',
);

// Asking where the pseudo-audience sits THROWS rather than returning a sentinel. A -1 or a null
// would let the category error travel — most likely as "not found, therefore widest" or "not found,
// therefore narrowest", both answers to a question nobody should be asking.
foreach ([
    'BLOCKED_QUARANTINED' => 'refusal-rendering pseudo-audience',
    'INVENTED_AUDIENCE' => 'not an audience class at all',
] as $notATarget => $expectedReason) {
    try {
        ReleaseOutput::audienceOrderIndex($notATarget);
        Assert::fail("audienceOrderIndex({$notATarget}) should not return a position");
    } catch (\ValueError $error) {
        Assert::contains($expectedReason, $error->getMessage(), "{$notATarget}: and the message says why");
    }
}

// AT THE PROJECTION GATE the two failures are named separately, and neither is reported as an
// audience above the ceiling. The pseudo-audience is not too wide — it is not an audience.
$pseudo = $m16->projectForAudience($liveArtifactId, 'BLOCKED_QUARANTINED');
Assert::same(
    'AUDIENCE_NOT_A_PROJECTION_TARGET',
    $pseudo->reasonCode(),
    'projecting for the pseudo-audience is refused as a category error, not as a ceiling breach',
);
Assert::same(
    'AUDIENCE_CLASS_UNKNOWN',
    $m16->projectForAudience($liveArtifactId, 'INVENTED_AUDIENCE')->reasonCode(),
    'and an audience outside the vocabulary is refused by name too',
);
// A REFUSED projection row is a governed record; a refusal at the vocabulary gate writes NOTHING,
// because there is no audience for the row to be about.
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM customer_output_projections
         WHERE release_artifact_id = ? AND audience_class IN ('BLOCKED_QUARANTINED', 'INVENTED_AUDIENCE')",
        [$liveArtifactId]
    ),
    'and no projection row is written for either — a row about a non-audience would be a false record',
);

// NO PROFILE IS SEEDED for the pseudo-audience, so even the read path's audience gate has nothing
// to find. Six ladder rows, and only those.
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM output_visibility_profiles WHERE audience_class = 'BLOCKED_QUARANTINED'"
    ),
    'the pseudo-audience is seeded no visibility profile at all',
);
Assert::same(
    count(ReleaseOutput::AUDIENCE_ORDER),
    (int) $db->scalar(
        'SELECT COUNT(*) FROM output_visibility_profiles WHERE tenant_id = ?',
        [$scope['tenant_id']]
    ),
    'exactly one profile per LADDER audience is seeded — the inert ones explicitly, the extra one never',
);
