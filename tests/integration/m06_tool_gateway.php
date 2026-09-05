<?php

declare(strict_types=1);

/**
 * The M06 tool gateway and the M01 dispatch boundary, proved against real MariaDB.
 *
 * The single claim this file exists to establish: a governed tool call reaches a host ONLY through
 * the full gate chain, and every way of getting there without one is a recorded refusal.
 *
 * The gate chain is M02 access -> M03 contract -> M04 mission scope -> M06 descriptor -> V1
 * scope/safety -> idempotency. Each gate is failed INDEPENDENTLY below: a chain proved only by its
 * happy path proves that the gates run, not that any of them decides anything.
 */

use Allfather\Loopia\Core\FixedClock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M01\AdmissionToken;
use Allfather\Loopia\Substrate\M01\DispatchAdmission;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M06\AuthoritySnapshot;
use Allfather\Loopia\Substrate\M06\Gateway;
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
$m02 = new AccessGuard($db, $clock);
$m03e = new Eligibility($db, $clock);
$m01 = new Invocation($db, $clock);
$gateway = new Gateway($db, $clock, $m02, $m03e, $m01);

// The caller needs `tool.invoke` in this workspace before anything else is even consulted.
$role = Seed::role($db, $clock, 'tool_caller', ['tool.invoke']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);

$chain = Seed::governedToolChain($db, $clock, $scope);

/** @return array<string, mixed> */
$command = static function (array $chain, array $scope, array $overrides = []) use ($clock): array {
    return [
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'mission_id' => $chain['mission_id'],
        'assignment_id' => $chain['assignment_id'],
        'valkyrie_id' => $chain['valkyrie_id'],
        'capability_contract_id' => $chain['capability_contract_id'],
        'governed_tool_id' => $chain['governed_tool_id'],
        'governed_tool_operation_id' => $chain['governed_tool_operation_id'],
        'requested_action_class' => 'OBSERVE',
        'normalized_input_ref_safe' => 'source-ref://competitor-a/pricing',
        'required_entitlement_permission' => 'competition_monitoring.read',
        'idempotency_key' => 'k-' . substr(Ids::new(), 0, 12),
        'correlation_id' => Ids::new(),
        'calling_actor_type' => 'OPERATOR',
        'calling_actor_id' => $scope['operator_id'],
        ...$overrides,
    ];
};

// ============================================================ the happy path

$dispatch = $gateway->requestToolDispatch($command($chain, $scope));
Assert::same('ALLOWED_AND_DISPATCHED', $dispatch['kind'], 'a fully governed read dispatches');
Assert::same('SUCCEEDED', $dispatch['outcome_status'], 'and succeeds');
Assert::same('SOURCE_CANDIDATE', $dispatch['outcome_classification'], 'classified as a source candidate');
Assert::true($dispatch['evidence_capture_candidate'], 'and eligible to seed evidence');

// Every gate ran and was RECORDED. A chain that decides without leaving its reasoning is not an
// audit trail — it is an assertion that the reasoning happened.
$gateTypes = array_column(
    $db->all('SELECT gate_type FROM tool_gate_checks WHERE tool_request_id = ? ORDER BY assessed_at, gate_type',
        [$dispatch['tool_request_id']]),
    'gate_type'
);
sort($gateTypes);
Assert::equalsList(
    ['M02_ACCESS_SCOPE', 'M03_CAPABILITY_CONTRACT', 'M04_MISSION_SCOPE', 'M06_TOOL_DESCRIPTOR', 'V1_SCOPE_PROHIBITION'],
    $gateTypes,
    'all five gates left a row, in the canonical set',
);
Assert::same(
    5,
    (int) $db->scalar("SELECT COUNT(*) FROM tool_gate_checks WHERE tool_request_id = ? AND decision = 'PASS'",
        [$dispatch['tool_request_id']]),
    'and all five passed',
);

// The M01 execution ref is the evidence that the dispatch physically happened, bound to the
// dispatch row rather than merely to the request.
$execution = $db->one(
    'SELECT status, output_classification, side_effect_uncertainty, tool_dispatch_id
     FROM runtime_host_execution_refs WHERE tool_request_id = ?',
    [$dispatch['tool_request_id']]
);
Assert::same('SUCCEEDED', (string) $execution['status'], 'M01 recorded a SUCCEEDED execution');
Assert::same(
    $dispatch['tool_dispatch_id'],
    (string) $execution['tool_dispatch_id'],
    'bound to the dispatch act, not just to the request',
);
Assert::same(0, (int) $execution['side_effect_uncertainty'], 'with no side-effect uncertainty');

// ============================================================ idempotency

$repeatKey = 'k-repeat';
$first = $gateway->requestToolDispatch($command($chain, $scope, ['idempotency_key' => $repeatKey]));
Assert::same('ALLOWED_AND_DISPATCHED', $first['kind'], 'the first call under a key dispatches');

$replay = $gateway->requestToolDispatch($command($chain, $scope, ['idempotency_key' => $repeatKey]));
Assert::same('IDEMPOTENT_REPLAY', $replay['kind'], 'an identical repeat REPLAYS rather than dispatching again');
Assert::same(
    $first['tool_request_id'],
    $replay['prior_tool_request_id'],
    'and names the prior request, so the caller can find the original answer',
);

// The same key with DIFFERENT inputs is a conflict, never a second dispatch under a key the caller
// believed was already spent.
$conflict = $gateway->requestToolDispatch($command($chain, $scope, [
    'idempotency_key' => $repeatKey,
    'normalized_input_ref_safe' => 'source-ref://competitor-a/DIFFERENT',
]));
Assert::same('CONFLICT', $conflict['kind'], 'the same key with different inputs conflicts');
Assert::same('TOOL_IDEMPOTENCY_CONFLICT', $conflict['reason_code'], 'named specifically');
Assert::true($conflict['incident_evaluation_required'], 'and flagged for incident evaluation');

Assert::same(
    2,
    (int) $db->scalar("SELECT COUNT(*) FROM tool_dispatch_records WHERE status = 'DISPATCHED'"),
    'exactly two dispatches exist — the replay and the conflict produced none',
);

// ============================================================ each gate refuses on its own

/** Every refusal must leave the SF-003 non-dispatch proof and no dispatch record. */
$assertRefused = static function (array $decision, string $expectedKind, string $reasonFragment, string $why) use ($db): void {
    Assert::same($expectedKind, $decision['kind'], $why);
    Assert::true($decision['no_dispatch_confirmed'], 'with the positive non-dispatch proof');
    Assert::same(
        1,
        (int) $db->scalar('SELECT COUNT(*) FROM tool_denial_escalation_records WHERE tool_request_id = ?',
            [$decision['tool_request_id']]),
        'and exactly one escalation record',
    );
    Assert::same(
        0,
        (int) $db->scalar('SELECT COUNT(*) FROM tool_dispatch_records WHERE tool_request_id = ?',
            [$decision['tool_request_id']]),
        'and NO dispatch record at all',
    );
    Assert::true(
        in_array($reasonFragment, $decision['reason_codes'], true),
        "named by {$reasonFragment} rather than a generic failure",
    );
};

// --- gate 1: M02 access ------------------------------------------------------------------------

$stranger = Seed::operator($db, $clock, $scope['tenant_id'], 'No Roles');
$assertRefused(
    $gateway->requestToolDispatch($command($chain, $scope, ['calling_actor_id' => $stranger])),
    'DENIED',
    'TOOL_REQUEST_ROLE_ASSIGNMENT_NOT_ACTIVE',
    'a caller with no active role assignment is denied at the access gate',
);

// The tier entitlement composes INTO that same gate: an M02-authorized caller whose tenant tier
// does not grant the permission is still denied, and on the SAME gate row.
$tierDenied = $gateway->requestToolDispatch($command($chain, $scope, [
    'required_entitlement_permission' => 'p05_marketing.read',
]));
$assertRefused(
    $tierDenied,
    'DENIED',
    'TOOL_REQUEST_TIER_DOES_NOT_GRANT_PERMISSION',
    'a permission the tenant tier does not grant is not dispatchable',
);
Assert::same(
    'M02_ACCESS_SCOPE',
    (string) $db->scalar(
        "SELECT gate_type FROM tool_gate_checks WHERE tool_request_id = ? AND decision = 'FAIL'",
        [$tierDenied['tool_request_id']]
    ),
    'recorded on the ACCESS gate — one audit row, not a parallel authority plane',
);

// --- gate 2: M03 capability contract -----------------------------------------------------------

$foreignChain = Seed::governedToolChain($db, $clock, Seed::tenantWorkspace($db, $clock, 'rival'));
$assertRefused(
    $gateway->requestToolDispatch($command($chain, $scope, [
        'valkyrie_id' => $foreignChain['valkyrie_id'],
    ])),
    'DENIED',
    'TOOL_VALKYRIE_TENANT_SCOPE_MISMATCH',
    "another tenant's agent holds no authority here",
);

// --- gate 3: M04 mission scope -----------------------------------------------------------------

$assertRefused(
    $gateway->requestToolDispatch($command($chain, $scope, ['assignment_id' => Ids::new()])),
    'DENIED',
    'ASSIGNMENT_NOT_FOUND',
    'a dispatch under an assignment that does not exist is refused',
);

// A contract that permits the tool is NOT enough: the mission assignment must name it too.
$otherTool = Seed::governedToolChain($db, $clock, $scope);
$db->run('UPDATE mission_assignments SET assigned_tool_ids = ? WHERE assignment_id = ?', [
    json_encode([], JSON_THROW_ON_ERROR), $otherTool['assignment_id'],
]);
$assertRefused(
    $gateway->requestToolDispatch($command($otherTool, $scope)),
    'DENIED',
    'TOOL_NOT_IN_ASSIGNMENT_SCOPE',
    'a tool the assignment does not name is out of mission scope, whatever the contract says',
);

// --- gate 4: M06 descriptor --------------------------------------------------------------------

$suspended = Seed::governedToolChain($db, $clock, $scope);
$db->run("UPDATE governed_tools SET status = 'SUSPENDED' WHERE governed_tool_id = ?", [
    $suspended['governed_tool_id'],
]);
$assertRefused(
    $gateway->requestToolDispatch($command($suspended, $scope)),
    'DENIED',
    'TOOL_NOT_ACTIVE',
    'a suspended tool is not dispatchable, however complete the rest of the chain',
);

// `operation_class` and `classified_action_class` are DIFFERENT fields. An operation declared
// FORBIDDEN by class must fail even when its action class looks benign.
$forbiddenOp = Seed::governedToolChain($db, $clock, $scope);
$db->run("UPDATE governed_tool_operations SET operation_class = 'FORBIDDEN' WHERE governed_tool_operation_id = ?", [
    $forbiddenOp['governed_tool_operation_id'],
]);
$assertRefused(
    $gateway->requestToolDispatch($command($forbiddenOp, $scope)),
    'DENIED',
    'TOOL_OPERATION_CLASS_FORBIDDEN',
    'a FORBIDDEN operation class is terminal, even with a benign action class',
);

// --- gate 5: V1 scope / safety -----------------------------------------------------------------

// Every action class above ANALYZE routes to REQUIRE_APPROVAL, and this projection has no M09
// plane, so REQUIRE_APPROVAL is TERMINAL here — not a step toward eventual dispatch.
$releaseChain = Seed::governedToolChain($db, $clock, $scope, 'SAFE_READ_ONLY', 'RELEASE_INTELLIGENCE', 'READ_ONLY_SOURCE');
$approvalRequired = $gateway->requestToolDispatch($command($releaseChain, $scope, [
    'requested_action_class' => 'RELEASE_INTELLIGENCE',
]));
$assertRefused(
    $approvalRequired,
    'REQUIRE_APPROVAL',
    'TOOL_REQUIRES_APPROVAL',
    'an action class above ANALYZE requires an approval this projection has no plane to give',
);
Assert::same(
    'REQUIRE_APPROVAL',
    (string) $db->scalar('SELECT status FROM tool_requests WHERE tool_request_id = ?', [$approvalRequired['tool_request_id']]),
    'and the request rests there — nothing in this projection can advance it',
);

// ============================================================ M01 is not reachable without M06

// PIN — M01 refuses a dispatch presented WITHOUT an admission. This is INV-006: the dispatch path
// is not a function anyone may call, it is a function only a completed gate chain may call.
$naked = $m01->invokeAuthorizedHostTool([
    'tool_request_id' => $dispatch['tool_request_id'],
    'tool_authorization_decision_id' => $dispatch['tool_authorization_decision_id'],
    'governed_tool_id' => $chain['governed_tool_id'],
    'runtime_host_tool_ref' => $chain['runtime_host_tool_ref'],
    'action_class' => 'OBSERVE',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'correlation_id' => Ids::new(),
]);
Assert::same('REFUSED', $naked['outcome'], 'M01 refuses a dispatch with no admission');
Assert::same(
    'M06_DISPATCH_ADMISSION_ABSENT',
    $naked['reason_code'],
    'and says so specifically — the caller never held the authority, rather than losing it',
);
Assert::same(
    'REFUSED_PRE_DISPATCH',
    (string) $db->scalar(
        'SELECT status FROM runtime_host_execution_refs WHERE execution_ref_id = ?',
        [$naked['execution_ref_id']]
    ),
    'recorded as a PRE-dispatch refusal, because nothing was dispatched',
);

// A FORGED token — the right class, obtained the only way a caller could — carries no binding, so
// it is refused as NOT_GOVERNED rather than accepted on its shape.
$forged = $m01->invokeAuthorizedHostTool([
    'tool_request_id' => $dispatch['tool_request_id'],
    'tool_authorization_decision_id' => $dispatch['tool_authorization_decision_id'],
    'governed_tool_id' => $chain['governed_tool_id'],
    'runtime_host_tool_ref' => $chain['runtime_host_tool_ref'],
    'action_class' => 'OBSERVE',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'correlation_id' => Ids::new(),
], AdmissionToken::create());
Assert::same(
    'M06_DISPATCH_ADMISSION_NOT_GOVERNED',
    $forged['reason_code'],
    'a token of the right CLASS still authorizes nothing — the binding is not in the object',
);

// ============================================================ single-use admission

// A dispatch act may produce at most ONE terminal execution. Replaying a retained admission is
// refused even though every field on it is still correct.
$single = Seed::governedToolChain($db, $clock, $scope);
$singleCommand = $command($single, $scope);
$singleDispatch = $gateway->requestToolDispatch($singleCommand);
Assert::same('SUCCEEDED', $singleDispatch['outcome_status'], 'the first dispatch on this chain succeeds');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM governed_execution_claims WHERE tool_dispatch_id = ?',
        [$singleDispatch['tool_dispatch_id']]),
    'and takes exactly one durable execution claim',
);

// Mint a SECOND admission for the same, already-executed dispatch row — the strongest replay a
// caller could construct, because it is not a retained token but a fresh one describing the same
// authorized act. The append-only execution trail refuses it.
$replayAdmission = DispatchAdmission::mint($db, [
    'tool_request_id' => $singleDispatch['tool_request_id'],
    'tool_authorization_decision_id' => $singleDispatch['tool_authorization_decision_id'],
    'tool_dispatch_id' => $singleDispatch['tool_dispatch_id'],
    'governed_tool_id' => $single['governed_tool_id'],
    'governed_tool_operation_id' => $single['governed_tool_operation_id'],
    'runtime_host_binding_id' => $single['binding_id'],
    'runtime_host_tool_ref' => $single['runtime_host_tool_ref'],
    'action_class' => 'OBSERVE',
    'dispatched_input_digest' => (string) $db->scalar(
        'SELECT exact_dispatched_input_digest FROM tool_dispatch_records WHERE tool_dispatch_id = ?',
        [$singleDispatch['tool_dispatch_id']]
    ),
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'correlation_id' => $singleCommand['correlation_id'],
    'authority_snapshot_digest' => AuthoritySnapshot::digestFor(
        $db, $single['governed_tool_id'], $single['governed_tool_operation_id']
    ),
]);
$replayed = $m01->invokeAuthorizedHostTool([
    'tool_request_id' => $singleDispatch['tool_request_id'],
    'tool_authorization_decision_id' => $singleDispatch['tool_authorization_decision_id'],
    'governed_tool_id' => $single['governed_tool_id'],
    'runtime_host_tool_ref' => $single['runtime_host_tool_ref'],
    'action_class' => 'OBSERVE',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'correlation_id' => $singleCommand['correlation_id'],
], $replayAdmission);
Assert::same(
    'M06_DISPATCH_ADMISSION_ALREADY_EXECUTED',
    $replayed['reason_code'],
    'a second admission for an executed dispatch act is refused by the append-only trail',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM runtime_host_execution_refs
         WHERE tool_dispatch_id = ? AND status = 'SUCCEEDED'",
        [$singleDispatch['tool_dispatch_id']]
    ),
    'and the dispatch act still has exactly one successful execution',
);
