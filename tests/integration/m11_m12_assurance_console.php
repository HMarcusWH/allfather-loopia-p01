<?php

declare(strict_types=1);

/**
 * M11 assurance and M12 console, proved against real MariaDB.
 *
 * Both planes exist to REPORT truthfully about planes they do not control, so both are tested for
 * the same failure mode: saying something the canonical state does not support.
 *
 *   M11 must not let an integrity failure become a quiet finding, and must not act on what it
 *   finds — it recommends, the owner acts.
 *
 *   M12 must not let a visible surface become an authority, and must not show a material status
 *   that has no true owner basis behind it.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;
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
$m11 = new Assurance($db, $clock, $ledger);
$access = new AccessGuard($db, $clock);
$m12 = new Console($db, $clock, $access, $ledger, $m11);

$governed = Seed::governedReleaseChain($db, $clock, $scope);
$streamId = (string) $db->scalar(
    'SELECT decision_ledger_stream_id FROM decision_ledger_streams WHERE tenant_id = ? LIMIT 1',
    [$scope['tenant_id']]
);

// ============================================================ M11: the ledger integrity audit

// The profile CATEGORY is authority for what the audit may conclude. A release-readiness profile
// must not back ledger-integrity evidence: the finding would cite an audit that never asked the
// question it answers.
$wrongCategory = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'wrong.category', 'RELEASE_READINESS', 'Release readiness'
);
$refused = $m11->runLedgerIntegrityAudit($wrongCategory, $streamId);
Assert::true($refused->isRefusal(), 'a non-LEDGER_INTEGRITY profile cannot back a ledger-integrity audit');
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_LEDGER_INTEGRITY',
    $refused->reasonCode(),
    'named specifically, not as a generic profile failure',
);

$profileId = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'ledger.integrity', 'LEDGER_INTEGRITY', 'Ledger integrity'
);

// A foreign stream cannot be audited into this profile's scope.
$rival = Seed::tenantWorkspace($db, $clock, 'rival');
Seed::governedReleaseChain($db, $clock, $rival);
$foreignStream = (string) $db->scalar(
    'SELECT decision_ledger_stream_id FROM decision_ledger_streams WHERE tenant_id = ? LIMIT 1',
    [$rival['tenant_id']]
);
$outOfScope = $m11->runLedgerIntegrityAudit($profileId, $foreignStream);
Assert::same(
    'AUDIT_LEDGER_STREAM_NOT_FOUND_OR_OUT_OF_SCOPE',
    $outOfScope->reasonCode(),
    "another tenant's ledger cannot be audited under this profile's scope",
);

// A clean stream: CONTROL_PASS, and no incident.
$clean = $m11->runLedgerIntegrityAudit($profileId, $streamId);
Assert::true($clean->isOk(), 'a clean ledger audits successfully');
Assert::same('CONTROL_PASS', $clean->value()['finding_type'], 'as a CONTROL_PASS finding');
Assert::same('PASS', $clean->value()['chain_verification_status'], 'with a verified chain');
Assert::same(null, $clean->value()['incident_case_id'], 'and opens no incident');
Assert::same(
    'COMPLETED',
    (string) $db->scalar('SELECT status FROM audit_runs WHERE audit_run_id = ?', [$clean->value()['audit_run_id']]),
    'the run is COMPLETED',
);

// ============================================================ an integrity failure is never quiet

// Tamper with a record's payload, then re-audit. The tampering is done directly in storage because
// no application path can do it — which is the point: the audit must catch what no legitimate act
// could have produced.
$victim = (string) $db->scalar(
    'SELECT decision_record_id FROM decision_records WHERE ledger_stream_id = ? ORDER BY record_sequence_number LIMIT 1',
    [$streamId]
);
$trigger = (string) $db->scalar(
    "SELECT action_statement FROM information_schema.triggers
     WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_decision_records_no_update'"
);
$db->run('DROP TRIGGER trg_decision_records_no_update');
$db->run('UPDATE decision_records SET safe_decision_summary = ? WHERE decision_record_id = ?', [
    'Tampered after the fact.', $victim,
]);
// The append-only trigger is restored IMMEDIATELY, and its restoration is proved below: a test that
// left the schema weaker than it found it would silently disarm every later assertion.
$db->run("CREATE TRIGGER trg_decision_records_no_update BEFORE UPDATE ON decision_records FOR EACH ROW {$trigger}");
Assert::throws(
    static fn () => $db->run('UPDATE decision_records SET severity = ? WHERE decision_record_id = ?', ['S1', $victim]),
    \PDOException::class,
    'the append-only trigger is restored and enforcing again',
);

$tampered = $m11->runLedgerIntegrityAudit($profileId, $streamId);
Assert::same('INTEGRITY_FAILURE', $tampered->value()['finding_type'], 'a tampered record is an INTEGRITY_FAILURE');
Assert::true($tampered->value()['incident_evaluation_required'], 'requiring incident evaluation');
Assert::notSame(null, $tampered->value()['incident_case_id'], 'and an incident case IS opened — never a quiet finding');

$incidentId = (string) $tampered->value()['incident_case_id'];
$incident = $db->one(
    'SELECT incident_class, severity, status, current_release_or_operation_consequence, verified_resolution
     FROM incident_cases WHERE incident_case_id = ?',
    [$incidentId]
);
Assert::same('LEDGER_INTEGRITY', (string) $incident['incident_class'], 'classified as a ledger-integrity incident');
Assert::same('S4', (string) $incident['severity'], 'at S4');
Assert::same(
    'HOLD',
    (string) $incident['current_release_or_operation_consequence'],
    'and it HOLDS release while open — an unresolved integrity incident cannot leave release unaffected',
);
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM incident_timeline_entries WHERE incident_case_id = ? AND entry_type = 'OPENED'",
        [$incidentId]),
    'with an OPENED timeline entry',
);

// ============================================================ M11 recommends; it does not act

$recommendation = $m11->recommendContainment(
    $incidentId, 'BLOCK_RELEASE_OR_CLAIM', 'IMMEDIATE_FAIL_CLOSED', 'M10',
    'Suspend reliance on the affected records until the chain is re-verified.'
);
Assert::true($recommendation->isOk(), 'a containment is recommended');
$stored = $db->one(
    'SELECT is_not_self_executed, requires_owner_acknowledgement, owner_module_id
     FROM containment_recommendations WHERE containment_recommendation_id = ?',
    [$recommendation->value()['containment_recommendation_id']]
);
Assert::same(1, (int) $stored['is_not_self_executed'], 'recorded as NOT self-executed');
Assert::same(1, (int) $stored['requires_owner_acknowledgement'], 'and requiring the owner to acknowledge it');
Assert::same('M10', (string) $stored['owner_module_id'], 'naming the module that must act');

// Both flags are CHECK-pinned. A recommendation claiming to have executed itself cannot be stored,
// so an assurance plane that acts on its own findings is structurally impossible, not merely absent.
Assert::throws(
    static fn () => $db->insert('containment_recommendations', [
        'containment_recommendation_id' => Ids::new(),
        'schema_version' => '1',
        'incident_case_id' => $incidentId,
        'containment_class' => 'BLOCK_RELEASE_OR_CLAIM',
        'urgency' => 'IMMEDIATE_FAIL_CLOSED',
        'owner_module_id' => 'M10',
        'requires_owner_acknowledgement' => 1,
        'is_not_self_executed' => 0,
        'safe_recommendation_summary' => 'Self-executed.',
        'created_at' => $clock->now(),
    ]),
    \PDOException::class,
    'a self-executing containment recommendation is structurally impossible',
);

// An UNVERIFIED resolution does not lift the hold. Closing a case nobody verified is allowed —
// operators do it — but it must not silently restore what verification would have restored.
$unverified = $m11->resolveIncident($incidentId, 'Closed without re-verification.', false);
Assert::true($unverified->isOk(), 'an unverified resolution is permitted');
Assert::same(
    'HOLD',
    (string) $db->scalar(
        'SELECT current_release_or_operation_consequence FROM incident_cases WHERE incident_case_id = ?',
        [$incidentId]
    ),
    'but the hold REMAINS — nothing was shown to be fixed',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT verified_resolution FROM incident_cases WHERE incident_case_id = ?', [$incidentId]),
    'and the record says the resolution was not verified',
);

// ============================================================ M11 replay cannot act

$replayProfile = $m11->createReplayProfile($scope['tenant_id'], $scope['workspace_id'], 'regression', 'Regression replay');
$replayCase = $m11->createReplayCase($replayProfile, 'INCIDENT_ANALYSIS', [$victim]);
Assert::true($replayCase->isOk(), 'a replay case opens over the tampered record');
$caseRow = $db->one(
    'SELECT side_effect_mode, tool_and_action_dispatch_prohibited FROM replay_cases WHERE replay_case_id = ?',
    [$replayCase->value()['replay_case_id']]
);
Assert::same('NO_SIDE_EFFECT_TEST_ONLY', (string) $caseRow['side_effect_mode'], 'declared no-side-effect');
Assert::same(1, (int) $caseRow['tool_and_action_dispatch_prohibited'], 'with dispatch prohibited');

$replayed = $m11->executeReplayCase($replayCase->value()['replay_case_id']);
Assert::same('DIVERGENT', $replayed->value()['outcome_classification'], 'the replay finds the record DIVERGENT');
Assert::same(
    'LEDGER_PAYLOAD_DIGEST_MISMATCH',
    $replayed->value()['divergences'][0]['reason'],
    'naming the specific divergence — the record no longer digests to its stored value',
);

// A replay over another tenant's record is refused before it can compare anything.
$foreignRecord = (string) $db->scalar(
    'SELECT decision_record_id FROM decision_records WHERE ledger_stream_id = ? LIMIT 1',
    [$foreignStream]
);
Assert::same(
    'REPLAY_RECORD_NOT_FOUND_OR_OUT_OF_SCOPE',
    $m11->createReplayCase($replayProfile, 'AUDIT_VERIFICATION', [$foreignRecord])->reasonCode(),
    'a replay cannot reach across a tenant boundary',
);

// ============================================================ M12: the console grants no authority

$surfaceId = $m12->registerCommandSurface(
    $scope['tenant_id'], $scope['workspace_id'], 'proposal.submit', 'M04', 'SUBMIT_PROPOSAL', 'proposal.submit'
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT owner_revalidation_required FROM console_command_surface_definitions WHERE console_command_surface_id = ?',
        [$surfaceId]
    ),
    'a registered surface always requires owner revalidation',
);

$routeInput = static fn (array $scope, string $key, string $actorId): array => [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'command_surface_key' => $key,
    'actor_type' => 'OPERATOR',
    'actor_id' => $actorId,
    'resource_type' => 'proposal',
    'resource_id' => null,
    'correlation_id' => Ids::new(),
];

// An operator with NO role is denied by the same guard the owner would use. The button existing
// changed nothing.
$denied = $m12->routeCommand($routeInput($scope, 'proposal.submit', $scope['operator_id']));
Assert::same('DENIED', $denied['outcome'], 'an unauthorized operator is denied at the console');
Assert::same('CONSOLE_OWNER_COMMAND_DENIED', $denied['reason_code'], 'as an ordinary owner denial');
Assert::same(
    'OWNER_REJECTED',
    (string) $db->scalar('SELECT status FROM console_command_intents WHERE console_command_intent_id = ?',
        [$denied['console_command_intent_id']]),
    'and the intent records the rejection',
);

// Grant the role; the SAME route now routes. The difference is the M02 grant, not the console.
$role = Seed::role($db, $clock, 'proposal_author', ['proposal.submit']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$routed = $m12->routeCommand($routeInput($scope, 'proposal.submit', $scope['operator_id']));
Assert::same('ROUTED', $routed['outcome'], 'an authorized operator is routed');
Assert::same('M04', $routed['owner_module_id'], 'to the owning module');
Assert::same(
    'ROUTED_TO_OWNER',
    (string) $db->scalar('SELECT status FROM console_command_intents WHERE console_command_intent_id = ?',
        [$routed['console_command_intent_id']]),
    'recorded as ROUTED_TO_OWNER, never OWNER_ACCEPTED — the owner has not acted yet',
);
Assert::same(
    'ROUTED',
    (string) $db->scalar(
        'SELECT owner_decision FROM console_command_result_references WHERE console_command_intent_id = ?',
        [$routed['console_command_intent_id']]
    ),
    'and the result reference says ROUTED, not a fabricated owner acceptance',
);

// A PROHIBITED surface cannot be routed, and the attempt is still recorded — a prohibited control
// somebody tried to use is exactly the event an audit wants.
$m12->registerCommandSurface(
    $scope['tenant_id'], $scope['workspace_id'], 'external.send', 'M01', 'SEND_EXTERNAL', 'proposal.submit', 'PROHIBITED'
);
$prohibited = $m12->routeCommand($routeInput($scope, 'external.send', $scope['operator_id']));
Assert::same('REJECTED', $prohibited['outcome'], 'a PROHIBITED surface cannot be routed');
Assert::same('CONSOLE_COMMAND_SURFACE_NOT_ACTIVE_V1', $prohibited['reason_code'], 'named specifically');
Assert::same(
    'FAILED_TO_ROUTE',
    (string) $db->scalar('SELECT status FROM console_command_intents WHERE console_command_intent_id = ?',
        [$prohibited['console_command_intent_id']]),
    'and the attempt IS recorded, rather than refused silently',
);

// An attempt at an EXCLUDED capability is not an ordinary denial — it is a bypass attempt.
$m12->registerCommandSurface(
    $scope['tenant_id'], $scope['workspace_id'], 'excluded.try', 'M01', 'EXECUTE_EXTERNAL', 'crm.send'
);
$bypass = $m12->routeCommand($routeInput($scope, 'excluded.try', $scope['operator_id']));
Assert::same('DENIED', $bypass['outcome'], 'an excluded capability is denied');
Assert::same(
    'CONSOLE_CLIENT_ATTEMPTED_AUTHORITY_BYPASS',
    $bypass['reason_code'],
    'and named as a BYPASS ATTEMPT, not as an ordinary denial',
);

// A VALKYRIE is not a console principal, and is refused before any row is written.
$valkyrieRoute = $m12->routeCommand([
    ...$routeInput($scope, 'proposal.submit', $scope['operator_id']),
    'actor_type' => 'VALKYRIE',
]);
Assert::same('CONSOLE_ACTOR_TYPE_NOT_PERMITTED', $valkyrieRoute['reason_code'], 'a governed agent is not a console actor');
Assert::same(
    4,
    (int) $db->scalar('SELECT COUNT(*) FROM console_command_intents WHERE tenant_id = ?', [$scope['tenant_id']]),
    'and no intent row was written for it — the four earlier routes are all that exist',
);

// ============================================================ M12: no overclaim

$proposalId = $governed['proposal_id'];
$releaseRecordId = $governed['release']['decision_record_id'];

// The basis must BE this proposal's own release record. An arbitrary non-empty basis is refused,
// even though a real release record exists elsewhere in the system.
Assert::same(
    'CONSOLE_MATERIAL_STATUS_SHOWN_WITHOUT_OWNER_BASIS',
    $m12->recordDisplayReference([
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'representation_type' => 'INTERNAL_RELEASE_DISPLAY', 'displayed_object_type' => 'proposal',
        'displayed_object_id' => $proposalId, 'owner_basis_ref' => '   ', 'safe_label' => 'Released',
    ])['reason_code'],
    'an empty basis is no basis',
);
Assert::same(
    'CONSOLE_RELEASE_SHOWN_WITHOUT_M10_RECORD',
    $m12->recordDisplayReference([
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'representation_type' => 'INTERNAL_RELEASE_DISPLAY', 'displayed_object_type' => 'proposal',
        'displayed_object_id' => $proposalId, 'owner_basis_ref' => Ids::new(), 'safe_label' => 'Released',
    ])['reason_code'],
    'an arbitrary basis is refused — the cited record must BE this proposal\'s release',
);
Assert::same(
    'RECORDED',
    $m12->recordDisplayReference([
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'representation_type' => 'INTERNAL_RELEASE_DISPLAY', 'displayed_object_type' => 'proposal',
        'displayed_object_id' => $proposalId, 'owner_basis_ref' => $releaseRecordId, 'safe_label' => 'Released',
    ])['outcome'],
    'the real release record IS a basis',
);

// ============================================================ M12: blocking gaps stay ahead

// With the ledger-integrity incident open but no gap recorded, readiness reflects the proposal.
$readiness = $m12->buildReleaseReadiness($proposalId);
Assert::same(
    'READY_FOR_INTERNAL_DEMO_REVIEW',
    $readiness['release_readiness_state'],
    'a released proposal with a live basis is ready',
);
Assert::same($releaseRecordId, $readiness['released_basis_decision_record_id'], 'citing its own release record');

// A BLOCKING gap holds readiness BLOCKED even though the proposal is released with a live basis.
$gapId = $m11->recordGap(
    $scope['tenant_id'], $scope['workspace_id'], 'VALIDATION_GAP', 'CORE', 'BLOCK_RELEASE',
    'Ledger integrity verification failed; release evidence is not trustworthy.'
);
$blocked = $m12->buildReleaseReadiness($proposalId);
Assert::same(
    'BLOCKED',
    $blocked['release_readiness_state'],
    'a blocking gap holds readiness BLOCKED, ahead of an otherwise-ready release',
);
Assert::same(1, $blocked['blocking_gap_count'], 'and the gap is counted, not hidden');

// A non-blocking gap does not block. Both halves of the rule are load-bearing.
$m11->resolveGap($gapId, 'RESOLVED_WITH_EVIDENCE');
$m11->recordGap(
    $scope['tenant_id'], $scope['workspace_id'], 'POLICY_GAP', 'SOURCES', 'QUALIFY_CLAIM',
    'One source card carries a narrower licence than the others.'
);
$unblocked = $m12->buildReleaseReadiness($proposalId);
Assert::same(
    'READY_FOR_INTERNAL_DEMO_REVIEW',
    $unblocked['release_readiness_state'],
    'a resolved blocking gap no longer blocks, and a QUALIFY_CLAIM gap never did',
);

// A release whose basis is no longer relied upon is ROLLED_BACK, never ready. The console must not
// keep asserting a release the decision plane has withdrawn.
$ledger->supersede($releaseRecordId, 'SUPERSEDED_BY_NEW_DECISION', 'M11', Ids::new(), 'stand-down');
$rolledBack = $m12->buildReleaseReadiness($proposalId);
Assert::same(
    'ROLLED_BACK_OR_SUPERSEDED',
    $rolledBack['release_readiness_state'],
    'a withdrawn basis makes the projection say ROLLED_BACK, not ready',
);
Assert::same(
    null,
    $rolledBack['released_basis_decision_record_id'],
    'and it cites no basis, because it has none',
);

// The projection is REGENERABLE, not accumulated: each rebuild replaces the prior row.
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM console_release_readiness_projections WHERE proposal_id = ?', [$proposalId]),
    'four rebuilds left exactly one projection row — a read model is derived, not a second record',
);

// ============================================================ M12: the workspace context mirrors M02

$contextId = $m12->buildWorkspaceContext($scope['tenant_id'], $scope['workspace_id'], $scope['operator_id']);
$context = $db->one(
    'SELECT visible_permission_summaries FROM console_workspace_context_projections
     WHERE console_workspace_context_projection_id = ?',
    [$contextId]
);
Assert::true(
    in_array('proposal.submit', json_decode((string) $context['visible_permission_summaries'], true), true),
    'a granted permission is visible',
);

// A SUSPENDED operator's roles must vanish from the projection, because M02 would deny them. A read
// model that outlives the authority it describes is a read model that lies.
$db->run("UPDATE operators SET status = 'SUSPENDED' WHERE operator_id = ?", [$scope['operator_id']]);
$suspendedContext = $db->one(
    'SELECT visible_permission_summaries, visible_role_summaries
     FROM console_workspace_context_projections
     WHERE console_workspace_context_projection_id = ?',
    [$m12->buildWorkspaceContext($scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'])]
);
Assert::equalsList(
    [],
    json_decode((string) $suspendedContext['visible_permission_summaries'], true),
    'a suspended operator sees no permissions — the projection mirrors M02 rather than the membership alone',
);
Assert::equalsList(
    [],
    json_decode((string) $suspendedContext['visible_role_summaries'], true),
    'and no roles',
);
