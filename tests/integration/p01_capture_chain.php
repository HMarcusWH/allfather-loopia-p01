<?php

declare(strict_types=1);

/**
 * The P01 capture-to-diff chain, proved against real MariaDB.
 *
 * This is the product's first vertical: an approved source becomes a governed snapshot, two
 * snapshots become a recorded change, and every step that could produce an unprovenanced claim is
 * refused instead.
 *
 * The chain is deliberately built through the REAL modules — source card, registry, M06 gate, M01
 * dispatch, egress guard, M05 evidence — because the point of the test is that they compose. A
 * fixture that inserted a snapshot directly would prove nothing about the path that produces one.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Ingestion\FetchResponse;
use Allfather\Loopia\Ingestion\FixedHostResolver;
use Allfather\Loopia\Ingestion\FixtureTransport;
use Allfather\Loopia\Ingestion\NoNetworkTransport;
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\Product\P01\ChangeDetection;
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
$changes = new ChangeDetection($db, $clock, $evidence);

$role = Seed::role($db, $clock, 'tool_caller', ['tool.invoke']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$chain = Seed::governedToolChain($db, $clock, $scope);

// NO connector descriptor is seeded. A P01 capture rides its own governed read tool, not an M15
// connector dispatch, so the connector legs of the compiled policy are out of its scope entirely
// — and this chain running without one is part of what proves it.

// ============================================================ source cards are default-deny

$baseCard = [
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Competitor A pricing',
    'source_url_safe' => 'https://sources.example/pricing',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'customer_output_allowed' => false,
    'robots_policy_checked' => true,
    'terms_policy_checked' => true,
    'review_status' => 'approved',
    'caching_policy' => 'explicit_contract',
    'retention_class' => 'standard',
    'derived_summary_allowed' => true,
];

// Each refusal is proved on its own, with everything ELSE about the card left admissible — so a
// pass cannot come from a second, unnoticed reason.
$refusals = [
    'CARD_NOT_APPROVED' => ['review_status' => 'draft'],
    'LICENSE_PROHIBITED' => ['license_class' => 'PROHIBITED'],
    'USE_CLASS_PROHIBITED' => ['allowed_use_class' => 'prohibited'],
    'SENSITIVITY_BEYOND_PUBLIC_SCOPE' => ['pii_risk_class' => 'high'],
    'PUBLIC_WEB_TERMS_OR_ROBOTS_NOT_REVIEWED' => ['robots_policy_checked' => false],
    'CONTRACT_REQUIRED_BUT_MISSING' => ['license_class' => 'LICENSED_INTERNAL_ONLY'],
];
foreach ($refusals as $expected => $override) {
    $projected = $cards->project([...$baseCard, ...$override]);
    Assert::same('RECORDED_NOT_INGESTABLE', $projected['outcome'], "{$expected}: the card is not ingestable");
    Assert::same($expected, $projected['reason_code'], "{$expected}: named specifically");
    Assert::notSame(
        'ACTIVE_FOR_CAPTURE',
        $projected['source_reference_status'],
        "{$expected}: and its reference never reaches ACTIVE_FOR_CAPTURE",
    );
    // The card is STILL recorded. A source refused with no row would make "we never considered it"
    // and "we considered it and refused" indistinguishable, and the second is what an auditor needs.
    Assert::same(
        1,
        (int) $db->scalar('SELECT COUNT(*) FROM source_license_cards WHERE source_license_card_id = ?', [
            $projected['source_license_card_id'],
        ]),
        "{$expected}: but the card IS recorded, so the refusal is auditable",
    );
}

// Contract authority survives persistence and is re-read by the one source-policy compiler.
$licensed = $cards->project([...$baseCard,'source_url_safe'=>'https://sources.example/licensed','license_class'=>'LICENSED_INTERNAL_ONLY','contract_reference_id'=>'  fixture-contract-2026  ']);
Assert::same('PROJECTED',$licensed['outcome'],'licensed card with explicit reviewed authority projects');
$licensedRow=$db->one('SELECT * FROM source_license_cards WHERE source_license_card_id=?',[$licensed['source_license_card_id']]);
Assert::same('fixture-contract-2026',$licensedRow['contract_reference_id'],'contract reference is trimmed and persisted');
$ref=['source_ref_id'=>$licensed['source_ref_id'],'tenant_id'=>$scope['tenant_id'],'status'=>'ACTIVE_FOR_CAPTURE','content_sensitivity_class'=>'PUBLIC_OR_NON_SENSITIVE'];
$policy=\Allfather\Loopia\Ingestion\SourcePolicy::compile($ref,$licensedRow,null,null,strtotime($clock->now())*1000);
Assert::false(in_array('SOURCE_CONTRACT_REQUIRED_BUT_MISSING',$policy['fail_closed_reason_codes'],true),'persisted authority accepted at compile');
$legacy=$licensedRow;$legacy['contract_reference_id']=null;
$policy=\Allfather\Loopia\Ingestion\SourcePolicy::compile($ref,$legacy,null,null,strtotime($clock->now())*1000);
Assert::true(in_array('SOURCE_CONTRACT_REQUIRED_BUT_MISSING',$policy['fail_closed_reason_codes'],true),'legacy missing licence authority refuses without fabricated migration backfill');
$firstParty=[...$legacy,'source_type'=>'customer_upload','data_ownership'=>'TENANT_OWNED','allowed_use_class'=>'customer_owned_tenant_scoped'];
$policy=\Allfather\Loopia\Ingestion\SourcePolicy::compile($ref,$firstParty,null,null,strtotime($clock->now())*1000);
Assert::false(in_array('SOURCE_CONTRACT_REQUIRED_BUT_MISSING',$policy['fail_closed_reason_codes'],true),'existing explicit P03 tenant-owned upload semantics remain separate from external contracts');

// A high-sensitivity card lands RESTRICTED, not merely REGISTERED: the two are different states and
// the reference status has to say which.
Assert::same(
    'RESTRICTED',
    $cards->project([...$baseCard, 'sensitive_data_risk_class' => 'high'])['source_reference_status'],
    'a prohibited-sensitivity source is RESTRICTED, distinct from a merely unapproved one',
);

$approved = $cards->project($baseCard);
Assert::same('PROJECTED', $approved['outcome'], 'a fully admissible card projects');
Assert::same('ACTIVE_FOR_CAPTURE', $approved['source_reference_status'], 'onto an ACTIVE_FOR_CAPTURE reference');

// ============================================================ the monitored-source registry

$competitor = $p01->registerCompetitor(
    $scope['tenant_id'], $scope['workspace_id'], 'Competitor A', 'DIRECT', 'OPERATOR', $scope['operator_id']
);
Assert::true($competitor->isOk(), 'a competitor registers');
$competitorId = $competitor->value()['competitor_id'];
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM competitor_status_transitions WHERE competitor_id = ?', [$competitorId]),
    'and registration itself is recorded as a lifecycle transition',
);

// Three independent authorities, each refused on its own — and the CARD gate is reached first, so
// each case has to isolate the authority it is testing.
$unapproved = $cards->project([...$baseCard, 'review_status' => 'draft', 'source_url_safe' => 'https://sources.example/other']);
Assert::same(
    'SOURCE_CARD_NOT_APPROVED',
    $p01->registerMonitoredSource(
        $scope['tenant_id'], $scope['workspace_id'], $competitorId,
        $unapproved['source_ref_id'], $unapproved['source_license_card_id'], 'WEBSITE'
    )->reasonCode(),
    'an unapproved card cannot authorize monitoring',
);

// An APPROVED card whose sensitivity keeps its reference out of ACTIVE_FOR_CAPTURE is the case that
// isolates the reference gate: the card passes, and the reference is what refuses. Without this the
// reference check could be dead code hidden behind the card check.
$restricted = $cards->project([
    ...$baseCard,
    'sensitive_data_risk_class' => 'medium',
    'source_url_safe' => 'https://sources.example/restricted',
]);
Assert::same('approved', (string) $db->scalar(
    'SELECT review_status FROM source_license_cards WHERE source_license_card_id = ?',
    [$restricted['source_license_card_id']]
), 'the card itself is approved, so the card gate passes');
Assert::same(
    'SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE',
    $p01->registerMonitoredSource(
        $scope['tenant_id'], $scope['workspace_id'], $competitorId,
        $restricted['source_ref_id'], $restricted['source_license_card_id'], 'WEBSITE'
    )->reasonCode(),
    'and the REFERENCE is what refuses — an approved card cannot lift a restricted reference',
);

// A card for source A cannot authorize monitoring source B, even though both are approved.
$otherApproved = $cards->project([...$baseCard, 'source_url_safe' => 'https://sources.example/careers']);
Assert::same(
    'SOURCE_CARD_REFERENCE_MISMATCH',
    $p01->registerMonitoredSource(
        $scope['tenant_id'], $scope['workspace_id'], $competitorId,
        $approved['source_ref_id'], $otherApproved['source_license_card_id'], 'WEBSITE'
    )->reasonCode(),
    "one source's approved card is not authority for another source",
);

// A cross-workspace binding is a scope breach even inside one tenant.
$otherWorkspace = Seed::tenantWorkspace($db, $clock, 'acme2');
Assert::same(
    'COMPETITOR_TENANT_MISMATCH',
    $p01->registerMonitoredSource(
        $otherWorkspace['tenant_id'], $otherWorkspace['workspace_id'], $competitorId,
        $approved['source_ref_id'], $approved['source_license_card_id'], 'WEBSITE'
    )->reasonCode(),
    "another tenant's workspace cannot monitor this competitor",
);

$monitored = $p01->registerMonitoredSource(
    $scope['tenant_id'], $scope['workspace_id'], $competitorId,
    $approved['source_ref_id'], $approved['source_license_card_id'], 'WEBSITE'
);
Assert::true($monitored->isOk(), 'a fully authorized monitored source registers');
$monitoredSourceId = $monitored->value()['monitored_source_id'];

// An ARCHIVED competitor is terminal, and a monitored source cannot be registered under one.
$archivedCompetitor = $p01->registerCompetitor(
    $scope['tenant_id'], $scope['workspace_id'], 'Gone Ltd', 'DIRECT', 'OPERATOR', $scope['operator_id']
)->value()['competitor_id'];
$p01->changeCompetitorStatus(
    $archivedCompetitor, $scope['tenant_id'], $scope['workspace_id'], 'ARCHIVED', 'OPERATOR', $scope['operator_id']
);
Assert::same(
    'ILLEGAL_TRANSITION',
    $p01->changeCompetitorStatus(
        $archivedCompetitor, $scope['tenant_id'], $scope['workspace_id'], 'ACTIVE', 'OPERATOR', $scope['operator_id']
    )->reasonCode(),
    'ARCHIVED is terminal — an archived competitor cannot be quietly revived',
);
Assert::same(
    'COMPETITOR_NOT_LIVE',
    $p01->registerMonitoredSource(
        $scope['tenant_id'], $scope['workspace_id'], $archivedCompetitor,
        $approved['source_ref_id'], $approved['source_license_card_id'], 'WEBSITE'
    )->reasonCode(),
    'and nothing can be monitored under it',
);

// ============================================================ the governed capture

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

$page1 = "Competitor A\nList price 449 SEK\nSupport included\nFree trial 14 days";
$page2 = "Competitor A\nList price 499 SEK\nSupport included\nFree trial 30 days\nNew enterprise tier";

$fetch = static fn (string $body): FixtureTransport => new FixtureTransport([
    'https://sources.example/pricing' => new FetchResponse(200, 'text/html', null, $body),
]);
$resolver = new FixedHostResolver(['sources.example' => ['93.184.216.34']]);

$first = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'capture_url' => 'https://sources.example/pricing',
    'idempotency_key' => 'cap-1',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], $fetch($page1), $resolver);
Assert::true($first->isOk(), 'a governed capture succeeds');
Assert::same('CAPTURED', $first->value()['outcome'], 'and materializes a snapshot');
Assert::same(1, $first->value()['snapshot_sequence'], 'as sequence 1');
Assert::same(
    Digest::canonicalDigest(['url' => 'https://sources.example/pricing', 'body' => $page1]),
    $first->value()['content_hash'],
    'whose content hash IS the guard hash over the exact bytes read, bound to the URL',
);

// The snapshot cites M05 evidence, and that evidence cites the M06/M01 trail. A snapshot with no
// provenance is exactly what this chain exists to make impossible.
$snapshotEvidence = $db->one(
    'SELECT e.evidence_type, e.tool_request_id, e.content_hash
     FROM source_snapshots s JOIN evidence_objects e ON e.evidence_id = s.evidence_id
     WHERE s.source_snapshot_id = ?',
    [$first->value()['source_snapshot_id']]
);
Assert::same('HOST_RETRIEVAL_CAPTURE', (string) $snapshotEvidence['evidence_type'], 'the evidence is a host retrieval');
Assert::same(
    $first->value()['tool_request_id'],
    (string) $snapshotEvidence['tool_request_id'],
    'citing the exact M06 tool request that authorized it',
);
Assert::same(
    $first->value()['content_hash'],
    (string) $snapshotEvidence['content_hash'],
    'and carrying the same content hash the snapshot does',
);

// ============================================================ the capture URL must be in scope

$outOfScope = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    // Same host, DIFFERENT path — outside the authority the card actually grants.
    'capture_url' => 'https://sources.example/internal-admin',
    'idempotency_key' => 'cap-scope',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], new NoNetworkTransport(), $resolver);
Assert::same(
    'CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',
    $outOfScope->reasonCode(),
    "a URL outside the card's own path scope is refused BEFORE any dispatch",
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id = ?', [$monitoredSourceId]),
    'and no snapshot was written',
);

// Authority is re-read INSIDE the M01 callback, after M06 authorization but before DNS/HTTP. This
// simulates a source review being withdrawn in that exact gap. NoNetworkTransport makes any
// accidental network reach fail the test loudly.
$revokedInGap = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'capture_url' => 'https://sources.example/pricing',
    'idempotency_key' => 'cap-revoked-in-gap',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], new NoNetworkTransport(), $resolver, static function () use ($db, $approved): Outcome {
    $db->run("UPDATE source_license_cards SET review_status='blocked' WHERE source_license_card_id=?", [$approved['source_license_card_id']]);
    return Outcome::ok();
});
Assert::same('SOURCE_CARD_NOT_APPROVED', $revokedInGap->reasonCode(), 'source authority revoked after M06 but before I/O refuses at the actual fetch boundary');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id=?', [$monitoredSourceId]), 'the pre-dispatch refusal writes no snapshot');
$db->run("UPDATE source_license_cards SET review_status='approved' WHERE source_license_card_id=?", [$approved['source_license_card_id']]);

// ============================================================ the second capture and the diff

$second = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'capture_url' => 'https://sources.example/pricing',
    'idempotency_key' => 'cap-2',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], $fetch($page2), $resolver);
Assert::true($second->isOk(), 'a second capture succeeds');
Assert::same(2, $second->value()['snapshot_sequence'], 'as sequence 2');
Assert::notSame(
    $first->value()['content_hash'],
    $second->value()['content_hash'],
    'with a different content hash, because the page changed',
);

$beforeId = $first->value()['source_snapshot_id'];
$afterId = $second->value()['source_snapshot_id'];

// A REVERSED pair is refused: a diff always goes older to newer, or it reports the inverse change
// as though it had happened.
Assert::same(
    'SNAPSHOTS_NOT_FORWARD_ORDERED',
    $changes->detect([
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'monitored_source_id' => $monitoredSourceId,
        'before_snapshot_id' => $afterId, 'after_snapshot_id' => $beforeId,
    ])->reasonCode(),
    'a reversed snapshot pair is refused',
);

$diff = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $beforeId, 'after_snapshot_id' => $afterId,
    'before_text' => $page1, 'after_text' => $page2,
]);
Assert::true($diff->isOk(), 'the change is detected');
Assert::same('DETECTED', $diff->value()['outcome'], 'as a new detection');
Assert::true($diff->value()['change_detected'], 'the change itself is a FACT — the hashes differ');
Assert::same('MATERIAL', $diff->value()['change_significance'], 'and it is structurally MATERIAL');

// The derived evidence went through M05, and it is a CHANGE_DETECTION_REFERENCE — never canonical
// fact on its own. The distinction between the observed change and its interpretation lives here.
Assert::same(
    'CHANGE_DETECTION_REFERENCE',
    (string) $db->scalar('SELECT evidence_type FROM evidence_objects WHERE evidence_id = ?', [
        $diff->value()['change_evidence_id'],
    ]),
    'the derived evidence is a CHANGE_DETECTION_REFERENCE, not an observation',
);
Assert::same(
    'DERIVED_DIFF',
    (string) $db->scalar('SELECT capture_method FROM evidence_objects WHERE evidence_id = ?', [
        $diff->value()['change_evidence_id'],
    ]),
    'captured by derivation, not by a fetch that never happened',
);

// Idempotent: the same pair yields the existing diff. Two rows for one comparison would let a
// downstream count of "changes observed" double.
$again = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $beforeId, 'after_snapshot_id' => $afterId,
    'before_text' => $page1, 'after_text' => $page2,
]);
Assert::same('ALREADY_DETECTED', $again->value()['outcome'], 'a repeat detection REPLAYS');
Assert::same(
    $diff->value()['source_diff_id'],
    $again->value()['source_diff_id'],
    'returning the same diff rather than appending a second',
);

// Caller text is checked before replay: it cannot upgrade an unrelated captured change.
$pair = ['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'monitored_source_id'=>$monitoredSourceId,'before_snapshot_id'=>$beforeId,'after_snapshot_id'=>$afterId];
Assert::same('SNAPSHOT_TEXT_MISMATCH',$changes->detect([...$pair,'before_text'=>'invented text','after_text'=>$page2])->reasonCode(),'arbitrary text cannot reuse a legitimate prior diff');
Assert::same('DIFF_INPUT_CONFLICT',$changes->detect([...$pair,'material_change_threshold'=>1.0])->reasonCode(),'different threshold cannot silently reuse a prior classification');
foreach ([-1, 1.01, INF, NAN, '0.25'] as $invalid) {
    Assert::same('INVALID_MATERIAL_CHANGE_THRESHOLD',$changes->detect([...$pair,'material_change_threshold'=>$invalid])->reasonCode(),'invalid configuration refused before replay');
}
Assert::same('ALREADY_DETECTED',$changes->detect($pair)->value()['outcome'],'capture proof supplies comparison without caller text');
$proofJson = $db->scalar('SELECT text_provenance_json FROM source_snapshots WHERE source_snapshot_id=?',[$beforeId]);
Assert::false(str_contains((string)$proofJson,'449 SEK'),'retained comparison proof contains hashes, not source text');

// ============================================================ significance is not relevance

// The band is a STRUCTURAL measurement. These cases pin what it does and does not claim.
Assert::same(
    0.0,
    ChangeDetection::structuralChangeRatio("a\nb\nc", "a\nb\nc"),
    'identical text has zero structural distance',
);
Assert::same(
    1.0,
    ChangeDetection::structuralChangeRatio("a\nb", "c\nd"),
    'wholly different text has maximal distance',
);
Assert::same(
    0.0,
    ChangeDetection::structuralChangeRatio("a\n  b  \n\nc", "c\na\nb"),
    'whitespace and ordering are normalized away — the same lines are the same content',
);

// A hash change with NO line-level delta is MINOR, never MATERIAL — and a zero threshold cannot
// force otherwise. A sub-line edit is a real change that did not move the content.
$third = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'capture_url' => 'https://sources.example/pricing',
    'idempotency_key' => 'cap-3',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], $fetch($page2 . "   "), $resolver);
$whitespaceDiff = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $afterId, 'after_snapshot_id' => $third->value()['source_snapshot_id'],
    'before_text' => $page2, 'after_text' => $page2 . "   ",
    'material_change_threshold' => 0.0,
]);
Assert::same(
    'MINOR',
    $whitespaceDiff->value()['change_significance'],
    'a whitespace-only change is MINOR even at a zero threshold — a real change that moved nothing',
);

// A no-cache capture retains only its whole-content digest; no structural magnitude is invented.
$db->run("UPDATE source_license_cards SET caching_policy = 'no_cache' WHERE source_license_card_id = ?", [$approved['source_license_card_id']]);
$fourth = $capture->captureMonitoredSource([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'capture_url' => 'https://sources.example/pricing',
    'idempotency_key' => 'cap-4',
    'correlation_id' => Ids::new(),
    'governance' => $governance,
], $fetch($page1), $resolver);
$unknownDiff = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $third->value()['source_snapshot_id'],
    'after_snapshot_id' => $fourth->value()['source_snapshot_id'],
]);
Assert::same(
    'UNKNOWN',
    $unknownDiff->value()['change_significance'],
    'without retained capture proof, the magnitude is UNKNOWN — never a guessed band',
);

Assert::same(null,$db->scalar('SELECT text_provenance_json FROM source_snapshots WHERE source_snapshot_id=?',[$fourth->value()['source_snapshot_id']]),'no-cache source retains no line-level proof');
Assert::same('SNAPSHOT_TEXT_PROVENANCE_UNAVAILABLE',$changes->detect(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'monitored_source_id'=>$monitoredSourceId,'before_snapshot_id'=>$third->value()['source_snapshot_id'],'after_snapshot_id'=>$fourth->value()['source_snapshot_id'],'after_text'=>$page1])->reasonCode(),'caller text cannot reconstruct withheld comparison authority');

// ============================================================ nothing here is customer-visible

Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM source_license_cards WHERE tenant_id = ? AND customer_output_allowed = 1',
        [$scope['tenant_id']]
    ),
    'no source in this chain permits customer output',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_visibility_activations'),
    'and nothing in the capture path activated any visibility at all',
);
