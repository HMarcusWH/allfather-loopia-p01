<?php

declare(strict_types=1);

/**
 * CONFORMANCE: the P01 source-policy ADAPTER — the compiler's codes, in P01's vocabulary.
 *
 * P01 does not carry its own copy of the source-policy gates. It compiles through the ONE law
 * (`SourcePolicy::compile`) and then translates that law's fail-closed codes back into P01's own
 * rejection vocabulary. This suite is the characterization of that translation, and it exists
 * because the translation is where a port most easily goes wrong in a way nothing else notices:
 * the gates all still fire, the refusals all still happen, and every reason code is subtly the
 * wrong one — or, worse, a reason nobody mapped quietly becomes a capture.
 *
 * TWO CLAIMS ARE PROVED HERE, and they are different claims.
 *
 *   THE MAPPING. Each compiler code produces the P01 code its callers, audits and conformance
 *   transcripts were built on, and codes with no historical P01 equivalent NARROW to
 *   SOURCE_POLICY_PROHIBITED rather than inventing a new one or being dropped.
 *
 *   THE INDEPENDENCE. A P01 capture is NOT an M15 connector dispatch. It rides its own governed
 *   read tool, so the connector legs of the compile — whether this deployment has promoted a
 *   connector class and activated a descriptor row for it — are out of P01's scope entirely.
 *   This whole suite therefore seeds NO `ingestion_connector_descriptors` row, and captures
 *   still succeed. That is the point: an earlier revision of this projection resolved a descriptor
 *   and handed it to the compiler, which made every P01 capture silently depend on a platform row
 *   canonical P01 never required.
 *
 * The authority state is BENT after registration, because that is the only way to reach the
 * capture-time gates: the card gate at projection time would refuse most of these cards before
 * they could ever become a monitored source, and a gate that can only be reached through a gate
 * that already refused is untested code.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ingestion\FetchResponse;
use Allfather\Loopia\Ingestion\FixedHostResolver;
use Allfather\Loopia\Ingestion\FixtureTransport;
use Allfather\Loopia\Ingestion\NoNetworkTransport;
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\Product\P01\Registry as P01Registry;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
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
$evidence = new EvidenceSpine($db, $clock);
$gateway = new Gateway(
    $db, $clock, new AccessGuard($db, $clock), new Eligibility($db, $clock), new Invocation($db, $clock)
);
$capture = new Capture($db, $clock, $gateway, $evidence);
$cards = new SourceCards($db, $clock);
$p01 = new P01Registry($db, $clock);

$role = Seed::role($db, $clock, 'tool_caller', ['tool.invoke']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$chain = Seed::governedToolChain($db, $clock, $scope);

// DELIBERATELY ABSENT: no ingestion_connector_descriptors row is seeded anywhere in this suite.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM ingestion_connector_descriptors'),
    'the suite starts with NO promoted connector descriptor at all',
);

$competitorId = $p01->registerCompetitor(
    $scope['tenant_id'], $scope['workspace_id'], 'Competitor A', 'DIRECT', 'OPERATOR', $scope['operator_id']
)->value()['competitor_id'];

$governance = [
    'mission_id' => $chain['mission_id'],
    'assignment_id' => $chain['assignment_id'],
    'valkyrie_id' => $chain['valkyrie_id'],
    'capability_contract_id' => $chain['capability_contract_id'],
    'governed_tool_id' => $chain['governed_tool_id'],
    'governed_tool_operation_id' => $chain['governed_tool_operation_id'],
    'calling_actor_type' => 'OPERATOR',
    'calling_actor_id' => $scope['operator_id'],
];

$baseCard = [
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Competitor A pricing',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'customer_output_allowed' => false,
    'robots_policy_checked' => true,
    'terms_policy_checked' => true,
    'review_status' => 'approved',
    'caching_policy' => 'no_cache',
    'retention_class' => 'short',
];

$body = "Competitor A\nList price 449 SEK";
$resolver = new FixedHostResolver(['sources.example' => ['93.184.216.34']]);
$caseSeq = 0;

/**
 * Register one fully admissible monitored source of its own, so a bend in one case can never
 * change the answer in another.
 *
 * @return array{monitored_source_id: string, source_ref_id: string, source_license_card_id: string, url: string}
 */
$freshSource = function (array $cardOverrides = []) use (
    &$caseSeq, $cards, $p01, $scope, $competitorId, $baseCard
): array {
    $url = sprintf('https://sources.example/case-%d', ++$caseSeq);
    $projected = $cards->project([...$baseCard, 'source_url_safe' => $url, ...$cardOverrides]);
    Assert::same(
        'ACTIVE_FOR_CAPTURE',
        $projected['source_reference_status'],
        "case {$caseSeq}: the card is admissible BEFORE the bend, so the bend is what refuses",
    );
    $monitored = $p01->registerMonitoredSource(
        $scope['tenant_id'], $scope['workspace_id'], $competitorId,
        $projected['source_ref_id'], $projected['source_license_card_id'], 'WEBSITE'
    );
    Assert::true($monitored->isOk(), "case {$caseSeq}: and it registers as a monitored source");

    return [
        'monitored_source_id' => $monitored->value()['monitored_source_id'],
        'source_ref_id' => $projected['source_ref_id'],
        'source_license_card_id' => $projected['source_license_card_id'],
        'url' => $url,
    ];
};

$runCapture = function (array $source, ?string $captureUrl = null, ?FetchResponse $response = null) use (
    $capture, $scope, $governance, $resolver, $body
) {
    $url = $captureUrl ?? $source['url'];
    $transport = $response === null && $captureUrl !== null && $captureUrl !== $source['url']
        ? new NoNetworkTransport()
        : new FixtureTransport([$url => $response ?? new FetchResponse(200, 'text/html', null, $body)]);

    return $capture->captureMonitoredSource([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'monitored_source_id' => $source['monitored_source_id'],
        'capture_url' => $url,
        'idempotency_key' => 'adapter-' . $source['monitored_source_id'],
        'correlation_id' => Ids::new(),
        'governance' => $governance,
    ], $transport, $resolver);
};

// ================================================ INDEPENDENCE: no connector row, capture succeeds

$clean = $freshSource();
$captured = $runCapture($clean);
Assert::true(
    $captured->isOk(),
    'a P01 capture succeeds with NO ingestion connector descriptor promoted or activated',
);
Assert::same('CAPTURED', $captured->value()['outcome'], 'and materializes a real snapshot');

// The same claim from the other side: the compile this capture rode was BLOCKED on its connector
// leg. P01 proceeded anyway because that leg is not P01's authority — and this assertion is what
// keeps that from being an accident nobody would notice if the compiler stopped emitting it.
$compiled = \Allfather\Loopia\Ingestion\SourcePolicy::compile(
    [
        'source_ref_id' => $clean['source_ref_id'],
        'tenant_id' => $scope['tenant_id'],
        'status' => 'ACTIVE_FOR_CAPTURE',
        'content_sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
    ],
    $db->one('SELECT * FROM source_license_cards WHERE source_license_card_id = ?', [$clean['source_license_card_id']]),
    null,
    null,
    strtotime('2026-01-01T00:00:00Z') * 1000,
);
Assert::same(
    'COMPILED_BLOCKED',
    $compiled['status'],
    'the compile itself is BLOCKED with no connector — so the compile STATUS is not what P01 gates on',
);
Assert::same(
    ['CONNECTOR_NOT_ACTIVATED'],
    $compiled['fail_closed_reason_codes'],
    'and the ONLY thing it blocked on is the connector leg P01 is not the authority for',
);

// An INACTIVE descriptor, and an ACTIVE one of an unrelated class, change nothing either — because
// P01 never reads the table at all. A capture that started passing only once a row existed would
// be the exact dependency this suite exists to forbid.
foreach ([['PUBLIC_WEB_PAGE', 'RETIRED'], ['JSON_API', 'ACTIVE']] as [$class, $status]) {
    $db->insert('ingestion_connector_descriptors', [
        'ingestion_connector_descriptor_id' => Ids::new(),
        'schema_version' => '1',
        'connector_class' => $class,
        'connector_version' => '1',
        'required_content_types' => json_encode(['application/pdf'], JSON_THROW_ON_ERROR),
        'status' => $status,
        'created_at' => $clock->now(),
        'updated_at' => $clock->now(),
    ]);
}
$stillFine = $runCapture($freshSource());
Assert::true(
    $stillFine->isOk(),
    'a RETIRED descriptor for this class, and an ACTIVE one for another, leave the capture unaffected',
);

// And the content-type bound did NOT quietly disappear with the descriptor. The guard's own default
// allowlist is what bounds the read — the descriptor's `required_content_types` never reached it.
$pdf = $runCapture(
    $freshSource(),
    null,
    new FetchResponse(200, 'application/pdf', null, $body),
);
Assert::same(
    'REFUSED_CONTENT_TYPE_NOT_ALLOWED',
    $pdf->reasonCode(),
    "a response outside the guard's default content-type allowlist is still refused",
);

// ================================================ THE MAPPING, one compiler leg at a time

$nowMs = strtotime('2026-01-01T00:00:00Z') * 1000;
$past = \Allfather\Loopia\Core\Clock::format(new \DateTimeImmutable('2025-01-01T00:00:00Z'));

/** @var list<array{0: string, 1: string, 2: callable, 3: string}> */
$cases = [
    [
        'SOURCE_CARD_NOT_APPROVED',
        'SOURCE_CARD_NOT_APPROVED',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_license_cards SET review_status = 'draft' WHERE source_license_card_id = ?",
                [$s['source_license_card_id']]
            );
        },
        'an unapproved card refuses under P01\'s own historical code',
    ],
    [
        'SOURCE_REFERENCE_NOT_ACTIVE',
        'SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_references SET status = 'REVOKED' WHERE source_ref_id = ?",
                [$s['source_ref_id']]
            );
        },
        'a revoked reference refuses as NOT_ACTIVE_FOR_CAPTURE, not as the compiler spells it',
    ],
    [
        'SOURCE_CARD_REVIEW_EXPIRED',
        'SOURCE_CARD_REVIEW_EXPIRED',
        function (array $s) use ($db, $past): void {
            $db->run(
                'UPDATE source_license_cards SET review_expires_at = ? WHERE source_license_card_id = ?',
                [$past, $s['source_license_card_id']]
            );
        },
        'a lapsed card review refuses with its own code, distinct from an unapproved card',
    ],
    [
        'ROBOTS_NOT_CHECKED',
        'TERMS_RECHECK_OVERDUE_AT_CAPTURE',
        // The card declares a recheck window and no review exists to satisfy it.
        function (array $s) use ($db): void {
            $db->run(
                'UPDATE source_license_cards SET recheck_terms_after_ms = 86400000 WHERE source_license_card_id = ?',
                [$s['source_license_card_id']]
            );
        },
        'an unchecked robots policy folds into P01\'s single recheck-overdue code',
    ],
    [
        'TERMS_RECHECK_OVERDUE',
        'TERMS_RECHECK_OVERDUE_AT_CAPTURE',
        // Robots and terms both CHECKED, but the declared window has elapsed. This isolates the
        // terms leg from the robots leg above: without it, one of the two mappings would be dead.
        function (array $s) use ($db, $past): void {
            $db->run(
                'UPDATE source_license_cards SET recheck_terms_after_ms = 86400000 WHERE source_license_card_id = ?',
                [$s['source_license_card_id']]
            );
            $db->insert('source_terms_reviews', [
                'source_terms_review_id' => Ids::new(),
                'schema_version' => '1',
                'source_license_card_id' => $s['source_license_card_id'],
                'robots_policy_checked' => 1,
                'terms_policy_checked' => 1,
                'reviewed_by' => 'operator',
                'reviewed_at' => $past,
                'expires_at' => null,
            ]);
        },
        'a checked-but-stale review refuses under the same recheck-overdue code',
    ],
    [
        'SOURCE_RETENTION_PROHIBITED',
        'SOURCE_RETENTION_PROHIBITED',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_license_cards SET retention_class = 'prohibited' WHERE source_license_card_id = ?",
                [$s['source_license_card_id']]
            );
        },
        'a prohibited retention class refuses under its own code',
    ],
    [
        'SOURCE_USE_PROHIBITED',
        'SOURCE_POLICY_PROHIBITED',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_license_cards SET allowed_use_class = 'prohibited' WHERE source_license_card_id = ?",
                [$s['source_license_card_id']]
            );
        },
        'a prohibited use class NARROWS to SOURCE_POLICY_PROHIBITED — P01 has no historical code for it',
    ],
    [
        'SOURCE_CONTRACT_REQUIRED_BUT_MISSING',
        'SOURCE_POLICY_PROHIBITED',
        function (array $s) use ($db): void {
            $db->run("UPDATE source_license_cards SET license_class = 'LICENSED_INTERNAL_ONLY', contract_reference_id = NULL WHERE source_license_card_id = ?", [$s['source_license_card_id']]);
        },
        'legacy licensed card without retained authority refuses at capture time',
    ],
    [
        'SOURCE_LICENSE_PROHIBITED',
        'SOURCE_POLICY_PROHIBITED',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_license_cards SET license_class = 'PROHIBITED' WHERE source_license_card_id = ?",
                [$s['source_license_card_id']]
            );
        },
        'a prohibited licence class narrows the same way',
    ],
    [
        'SOURCE_SENSITIVITY_PROHIBITED',
        'SOURCE_POLICY_PROHIBITED',
        function (array $s) use ($db): void {
            $db->run(
                "UPDATE source_references SET content_sensitivity_class = 'PROHIBITED_IN_V1' WHERE source_ref_id = ?",
                [$s['source_ref_id']]
            );
        },
        'and so does a prohibited sensitivity class on the reference',
    ],
    [
        'SOURCE_URL_MISSING_OR_INVALID',
        'SOURCE_URL_MISSING_OR_INVALID',
        function (array $s) use ($db): void {
            $db->run(
                'UPDATE source_license_cards SET source_url_safe = NULL WHERE source_license_card_id = ?',
                [$s['source_license_card_id']]
            );
        },
        'a card with no URL cannot anchor an allowlist, and says so in P01\'s own words',
    ],
];

foreach ($cases as [$compilerCode, $expected, $bend, $because]) {
    $source = $freshSource();
    $bend($source);
    $outcome = $runCapture($source);
    // Checked before the code is read, so a mapping that stops refusing altogether reports the
    // capture it wrongly allowed instead of dying on an unreadable reason code.
    if (!$outcome->isRefusal()) {
        Assert::fail("{$compilerCode}: the bent authority must REFUSE, but the capture was ALLOWED");

        continue;
    }
    Assert::same($expected, $outcome->reasonCode(), "{$compilerCode} -> {$expected}: {$because}");
    Assert::same(
        0,
        (int) $db->scalar(
            'SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id = ?',
            [$source['monitored_source_id']]
        ),
        "{$compilerCode}: and no snapshot was written",
    );
    // The refusal carries the compiler's OWN code alongside P01's, so an operator reading the audit
    // trail can see which authority actually failed rather than only how P01 renamed it.
    Assert::true(
        in_array($compilerCode, $outcome->detail['fail_closed_reason_codes'] ?? [], true),
        "{$compilerCode}: and the compiler's own code is carried into the refusal detail",
    );
}

// SOURCE_SCOPE_MISMATCH is mapped, but it is SHADOWED in the capture path: P01's own scope check
// runs before the compile and refuses a card that describes a different reference with the SAME
// code. Both routes therefore answer identically, which is the property worth pinning — the map
// entry exists so the adapter is TOTAL, not because the compiler leg is the reachable one.
$mismatch = $freshSource();
$other = $freshSource();
$db->run(
    'UPDATE monitored_sources SET source_license_card_id = ? WHERE monitored_source_id = ?',
    [$other['source_license_card_id'], $mismatch['monitored_source_id']]
);
Assert::same(
    'MONITORED_SOURCE_SCOPE_MISMATCH',
    $runCapture($mismatch)->reasonCode(),
    "another source's card is refused as a scope mismatch, the same code the compiler leg maps to",
);

// CAPTURE_URL_OUTSIDE_SOURCE_SCOPE is P01's OWN code, raised after the compile from the compiled
// allowed_url_scope — not a compiler reason at all. It belongs here because the adapter change
// moved the code that reaches it.
$scoped = $freshSource();
Assert::same(
    'CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',
    $runCapture($scoped, 'https://sources.example/internal-admin')->reasonCode(),
    'a capture URL outside the compiled scope is refused before any dispatch',
);
Assert::same(
    'CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',
    $runCapture($scoped, $scoped['url'] . '-admin')->reasonCode(),
    'a sibling path that merely shares the card path prefix is not inside the source scope',
);
Assert::same(
    'CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',
    $runCapture($scoped, $scoped['url'] . '/../admin')->reasonCode(),
    'dot-segment traversal is not a spelling of an authorized child path',
);
Assert::same(
    'CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',
    $runCapture($scoped, $scoped['url'] . '/%2e%2e/admin')->reasonCode(),
    'percent-encoded dot-segment traversal is refused before dispatch',
);
$child = $freshSource();
Assert::true(
    $runCapture($child, $child['url'] . '/details', new FetchResponse(200, 'text/html', null, $body))->isOk(),
    'a true child path remains inside the reviewed source scope',
);

// ================================================ THE ADAPTER ITSELF: total, and closed

// Reached directly, because two of its behaviours cannot be produced through the runtime: a
// compiler code this projection has never seen, and a compile whose ONLY codes are connector legs.
// A fail-closed default that no test can reach is a fail-closed default nobody has checked.
$adapter = new ReflectionMethod(Capture::class, 'p01ReasonFor');
$reasonFor = static fn (array $codes): ?string => $adapter->invoke(null, $codes);

Assert::same(null, $reasonFor([]), 'a clean compile maps to no P01 refusal');
Assert::same(
    null,
    $reasonFor(['CONNECTOR_NOT_ACTIVATED', 'CONNECTOR_CLASS_NOT_PROMOTED', 'SOURCE_TYPE_UNSUPPORTED']),
    'the three connector legs are the ONLY codes a P01 capture may ignore',
);
Assert::same(
    'SOURCE_POLICY_PROHIBITED',
    $reasonFor(['A_REASON_THIS_PROJECTION_HAS_NEVER_SEEN']),
    'an UNMAPPED compiler code refuses fail-closed — an unfamiliar reason is not a reason to capture',
);
Assert::same(
    'SOURCE_POLICY_PROHIBITED',
    $reasonFor(['CONNECTOR_NOT_ACTIVATED', 'A_FUTURE_COMPILER_REASON']),
    'and a connector leg alongside it does not dilute that refusal',
);

// The ORDER is the law: when several authorities fail at once, the refusal names the most
// fundamental one. Reversing the input proves the map's order decides, not the compiler's.
Assert::same(
    'SOURCE_CARD_NOT_APPROVED',
    $reasonFor(['SOURCE_URL_MISSING_OR_INVALID', 'SOURCE_CARD_NOT_APPROVED']),
    'an unapproved card outranks a missing URL however the compiler ordered them',
);
Assert::same(
    'SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE',
    $reasonFor(['SOURCE_SENSITIVITY_PROHIBITED', 'SOURCE_REFERENCE_NOT_ACTIVE']),
    'and an inactive reference outranks a narrowed policy prohibition',
);
Assert::same(
    'MONITORED_SOURCE_SCOPE_MISMATCH',
    $reasonFor(['SOURCE_SCOPE_MISMATCH']),
    'the shadowed scope leg maps to the same code P01 raises for itself',
);

// ================================================ TOTALITY: the map and the skip list are CLOSED

// The strongest property of the adapter is not any single mapping — it is that NOTHING falls
// through. Upstream gets this from a union type its compiler checks exhaustively; PHP has no such
// check, so it is asserted. Two directions, and both matter:
//
//   NOTHING UNMAPPED. Every reason the compiler can emit is either translated to a P01 code or
//   explicitly scoped out. A code added to the compiler without a decision about P01 fails here
//   rather than fail-closing in production under a reason nobody chose.
//
//   NOTHING QUIETLY EXCUSED. The skip list is EXACTLY the three connector legs. Moving a real
//   authority code into it — the one edit that would turn a refusal into a capture without
//   touching a single gate — fails here.
$vocabulary = \Allfather\Loopia\Ingestion\SourcePolicy::FAIL_CLOSED_REASON_CODES;

$mapConst = (new ReflectionClass(Capture::class))->getConstant('P01_REASON_BY_COMPILER_CODE');
$skipConst = (new ReflectionClass(Capture::class))->getConstant('P01_OUT_OF_SCOPE_COMPILER_CODES');
$mapped = array_column($mapConst, 0);

Assert::same(
    ['CONNECTOR_CLASS_NOT_PROMOTED', 'CONNECTOR_NOT_ACTIVATED', 'SOURCE_TYPE_UNSUPPORTED'],
    (static function (array $codes): array { sort($codes); return $codes; })($skipConst),
    'the skip list is EXACTLY the three connector legs — no authority code may be added to it',
);
Assert::same(
    [],
    array_values(array_diff($vocabulary, $mapped, $skipConst)),
    'every reason code the compiler can emit is either mapped to a P01 code or scoped out',
);
Assert::same(
    [],
    array_values(array_diff($mapped, $vocabulary)),
    'and the map contains no code the compiler cannot emit — a stale entry is a dead branch',
);
Assert::same(
    [],
    array_values(array_diff($skipConst, $vocabulary)),
    'nor does the skip list',
);

// The vocabulary constant itself has to stay true, or every assertion above is vacuous. It is
// checked against the compiler's own source: the set of codes it actually refuses with.
preg_match_all(
    "/\\\$refuse\('([A-Z_]+)'\)/",
    (string) file_get_contents(dirname(__DIR__, 2) . '/app/Ingestion/SourcePolicy.php'),
    $matches,
);
$actuallyRefused = array_values(array_unique($matches[1]));
sort($actuallyRefused);
$declared = $vocabulary;
sort($declared);
Assert::same(
    $declared,
    $actuallyRefused,
    'and the declared vocabulary IS what the compiler refuses with — no drift between them',
);
