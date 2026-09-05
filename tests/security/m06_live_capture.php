<?php

declare(strict_types=1);

/**
 * The live capture path: where the M06 gate chain meets the egress guard.
 *
 * The claim under test is a JOIN of two separately-proved properties. The egress guard's SSRF
 * screen is proved on its own in `tests/security/egress_guard.php` and `ssrf_parity.php`; the gate
 * chain is proved on its own in `tests/integration/m06_tool_gateway.php`. What is proved HERE is
 * that they compose without a gap:
 *
 *   - a guarded REFUSAL cannot produce a SUCCEEDED execution, so it can never seed evidence;
 *   - the SPECIFIC upstream reason survives into the audit trail instead of being flattened;
 *   - no retry happens on its own; and
 *   - the network is reached ONLY through the injected transport, so nothing here touches it.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ingestion\EgressGuard;
use Allfather\Loopia\Ingestion\FetchResponse;
use Allfather\Loopia\Ingestion\FixedHostResolver;
use Allfather\Loopia\Ingestion\FixtureTransport;
use Allfather\Loopia\Ingestion\NoNetworkTransport;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
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
$gateway = new Gateway(
    $db, $clock, new AccessGuard($db, $clock), new Eligibility($db, $clock), new Invocation($db, $clock)
);

$role = Seed::role($db, $clock, 'tool_caller', ['tool.invoke']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);

$command = static function (array $chain, array $scope): array {
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
        'idempotency_key' => 'live-' . substr(Ids::new(), 0, 12),
        'correlation_id' => Ids::new(),
        'calling_actor_type' => 'OPERATOR',
        'calling_actor_id' => $scope['operator_id'],
    ];
};

/**
 * The adapter M01 requires: run the guard, then translate its answer into M01's vocabulary.
 *
 * M01 never learns the guard's vocabulary and never imports its transport — the whole reason the
 * fetch arrives as an injected callable. This function is the seam, and it is deliberately the ONLY
 * thing in this file that knows both sides.
 */
$liveFetch = static function (string $url, array $allowedHosts, $transport, $resolver): callable {
    return static function () use ($url, $allowedHosts, $transport, $resolver): array {
        $guarded = EgressGuard::guardedFetch($url, $allowedHosts, $transport, $resolver);

        return $guarded['outcome'] === 'FETCHED'
            ? ['kind' => 'FETCHED', 'content_hash' => $guarded['content_hash']]
            : ['kind' => 'REFUSED', 'reason_code' => $guarded['reason_code']];
    };
};

// ============================================================ a permitted read

$chain = Seed::governedToolChain($db, $clock, $scope);
$body = '<html><body>List price 499 SEK</body></html>';
$ok = $gateway->requestLiveSourceDispatch(
    $command($chain, $scope),
    $liveFetch(
        'https://sources.example/pricing',
        ['sources.example'],
        new FixtureTransport(['https://sources.example/pricing' => new FetchResponse(200, 'text/html', null, $body)]),
        new FixedHostResolver(['sources.example' => ['93.184.216.34']]),
    )
);
Assert::same('ALLOWED_AND_DISPATCHED', $ok['kind'], 'a permitted live read dispatches');
Assert::same('SUCCEEDED', $ok['outcome_status'], 'and succeeds');
Assert::same(
    Digest::canonicalDigest(['url' => 'https://sources.example/pricing', 'body' => $body]),
    $ok['result_digest'],
    'the provenance digest IS the guard content hash — the capture is bound to the exact bytes read',
);
Assert::true($ok['evidence_capture_candidate'], 'so it may seed evidence');

// ============================================================ every guarded refusal is fail-closed

/**
 * @param string $host        the host the URL names
 * @param list<string> $ips   what DNS answers for it
 * @param string $expected    the SPECIFIC upstream reason that must survive to the audit trail
 */
$assertRefused = static function (
    string $label, string $url, array $allowedHosts, array $resolution, string $expected
) use ($db, $clock, $scope, $gateway, $command, $liveFetch): void {
    $chain = Seed::governedToolChain($db, $clock, $scope);
    $decision = $gateway->requestLiveSourceDispatch(
        $command($chain, $scope),
        $liveFetch($url, $allowedHosts, new NoNetworkTransport(), new FixedHostResolver($resolution)),
    );

    Assert::same('FAILED', $decision['outcome_status'], "{$label}: the outcome is FAILED, never SUCCEEDED");
    Assert::same(
        'FAILURE_ONLY',
        $decision['outcome_classification'],
        "{$label}: classified FAILURE_ONLY, so it cannot be mistaken for a source candidate",
    );
    Assert::false($decision['evidence_capture_candidate'], "{$label}: and cannot seed evidence");
    Assert::same(
        $expected,
        (string) $db->scalar(
            'SELECT error_code FROM tool_execution_outcomes WHERE tool_request_id = ?',
            [$decision['tool_request_id']]
        ),
        "{$label}: the SPECIFIC upstream reason survives into the outcome, not a generic failure",
    );
    Assert::same(
        'FAILED',
        (string) $db->scalar(
            'SELECT status FROM runtime_host_execution_refs WHERE tool_request_id = ? AND tool_dispatch_id IS NOT NULL',
            [$decision['tool_request_id']]
        ),
        "{$label}: and the M01 execution ref is FAILED",
    );
    Assert::same(
        1,
        (int) $db->scalar(
            'SELECT COUNT(*) FROM runtime_host_execution_refs WHERE tool_dispatch_id = ?',
            [$decision['tool_dispatch_id']]
        ),
        "{$label}: exactly ONE execution — the refusal was not retried",
    );
};

// The SSRF families. Each is already proved at the guard; what is proved here is that the guard's
// answer is what the governed audit trail ends up saying.
$assertRefused(
    'loopback', 'https://internal.example/admin', ['internal.example'],
    ['internal.example' => ['127.0.0.1']], 'REFUSED_BLOCKED_IP',
);
$assertRefused(
    'link-local metadata', 'https://meta.example/latest/meta-data', ['meta.example'],
    ['meta.example' => ['169.254.169.254']], 'REFUSED_BLOCKED_IP',
);
$assertRefused(
    'private RFC1918', 'https://intranet.example/', ['intranet.example'],
    ['intranet.example' => ['10.0.0.5']], 'REFUSED_BLOCKED_IP',
);
$assertRefused(
    'host not allowlisted', 'https://elsewhere.example/', ['sources.example'],
    ['elsewhere.example' => ['93.184.216.34']], 'REFUSED_HOST_NOT_ALLOWLISTED',
);
$assertRefused(
    'plaintext scheme', 'http://sources.example/', ['sources.example'],
    ['sources.example' => ['93.184.216.34']], 'REFUSED_SCHEME_NOT_HTTPS',
);
// A host that resolves to a public AND a blocked address is refused: one blocked answer is enough,
// because the caller does not choose which address the connection uses.
$assertRefused(
    'mixed resolution', 'https://sources.example/', ['sources.example'],
    ['sources.example' => ['93.184.216.34', '127.0.0.1']], 'REFUSED_BLOCKED_IP',
);

// ============================================================ the path itself is read-only

// A SAFE_INTERNAL_TRANSFORM binding may run the synthetic path but NOT the live one: a transform
// has no source to read, so a live capture on that binding is a capability nobody granted.
$transformChain = Seed::governedToolChain($db, $clock, $scope, 'SAFE_INTERNAL_TRANSFORM', 'ANALYZE', 'INTERNAL_TRANSFORM');
$transformCommand = $command($transformChain, $scope);
$transformCommand['requested_action_class'] = 'ANALYZE';
$transformLive = $gateway->requestLiveSourceDispatch(
    $transformCommand,
    $liveFetch(
        'https://sources.example/pricing', ['sources.example'],
        new FixtureTransport(['https://sources.example/pricing' => new FetchResponse(200, 'text/html', null, 'x')]),
        new FixedHostResolver(['sources.example' => ['93.184.216.34']]),
    )
);
Assert::same('FAILED', $transformLive['outcome_status'], 'a transform binding cannot take the live path');
Assert::same(
    'LIVE_CAPTURE_REQUIRES_SAFE_READ_ONLY',
    (string) $db->scalar(
        'SELECT error_code FROM tool_execution_outcomes WHERE tool_request_id = ?',
        [$transformLive['tool_request_id']]
    ),
    'named specifically, so the boundary is legible in the audit trail',
);
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM runtime_host_execution_refs
         WHERE tool_request_id = ? AND status = 'SUCCEEDED'",
        [$transformLive['tool_request_id']]
    ),
    'and nothing was fetched — the refusal precedes the callable',
);
