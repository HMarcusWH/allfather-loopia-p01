<?php

declare(strict_types=1);

/**
 * P06 deployed-surface and ROAD_TEST_001 tooling boundaries.
 *
 * These are structural because the code PR must not make a live source/model call merely to prove
 * that its operator tooling cannot do so accidentally.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$smoke = (string) file_get_contents($root . '/ops/staging-smoke.php');
$wrapper = (string) file_get_contents($root . '/ops/staging-monitor-smoke.php');
$controller = (string) file_get_contents($root . '/app/Http/Controllers/MonitorController.php');
$template = (string) file_get_contents($root . '/app/Http/templates/monitor.php');
$entry = (string) file_get_contents($root . '/public_html/index.php');
$exporter = (string) file_get_contents($root . '/ops/road-test/export-monitor-evidence.php');
$runbook = (string) file_get_contents($root . '/docs/ROAD_TEST_001_MONITOR.md');
$preflight = (string) file_get_contents($root . '/ops/deploy/preflight.php');

// One hardened HTTPS implementation, not a copied Monitor client.
Assert::contains("'monitor-boundary'", $smoke, 'the ordinary staging smoke owns the Monitor boundary mode');
Assert::contains("require __DIR__ . '/staging-smoke.php';", $wrapper, 'the Monitor command delegates to the hardened staging client');
Assert::contains('--mode=monitor-boundary', $wrapper, 'the wrapper fixes the one Monitor mode');
Assert::false(str_contains($wrapper, 'curl_init('), 'the wrapper does not grow a second HTTP transport');
Assert::same(1, substr_count($smoke, 'curl_init()'), 'there is one cURL construction point in staging smoke');

// Monitor scope comes from the authenticated session; action/run identities are body-only.
Assert::contains('$action = $request->body(\'action\');', $controller, 'Monitor mutation action comes from the POST body');
Assert::contains('$runId = $request->body(\'run_id\');', $controller, 'Monitor mutation run id comes from the POST body');
Assert::false(str_contains($controller, "query['tenant_id']"), 'Monitor controller never accepts tenant scope from query');
Assert::false(str_contains($controller, "query['workspace_id']"), 'Monitor controller never accepts workspace scope from query');
Assert::contains('$kernel->route(\'GET\', \'/console/monitor\'', $entry, 'Monitor has one authenticated GET workbench route');
Assert::contains('$kernel->route(\'POST\', \'/console/monitor\'', $entry, 'Monitor mutations share the CSRF-protected POST route');

// Exact inspection/review identities remain mandatory.
Assert::contains('name="request_digest"', $template, 'advance posts the exact inspected request digest');
Assert::contains('name="candidate_digest"', $template, 'human review posts the exact candidate digest');
Assert::contains('Continue with this request', $template, 'the workbench makes inspected-request continuation explicit');
Assert::contains('Record human review', $template, 'the workbench makes the human act explicit');

// Deployed smoke only exercises negative Monitor mutations; it never knows a valid run.
foreach ([
    'Monitor POST without CSRF is refused',
    'Monitor advance requires a body run id',
    'Monitor query run id cannot authorize advance',
    'Monitor review requires a body run id',
] as $predicate) {
    Assert::contains($predicate, $smoke, "Monitor smoke asserts: {$predicate}");
}
Assert::false(str_contains($smoke, 'MODEL_EVALUATION'), 'deployed smoke does not mint model-evaluation authority');
Assert::false(str_contains($smoke, 'MANUAL_MONITOR'), 'deployed smoke does not mint manual execution authority');

// Evidence exporter is a reader/reporter, never a registry writer or authority path.
Assert::contains("'records_evidence' => false", $exporter, 'exporter states that it files no evidence');
Assert::contains("'decides_road_test_result' => false", $exporter, 'exporter does not award itself PASS');
Assert::contains("'grants_runtime_authority' => false", $exporter, 'exporter grants no authority');
Assert::contains("'activates_live_read' => false", $exporter, 'exporter cannot activate live read');
Assert::false(str_contains($exporter, 'file_put_contents('), 'exporter writes no evidence/report file of its own');
Assert::false(str_contains($exporter, 'curl_'), 'exporter has no network transport');
Assert::false(str_contains($exporter, 'OpenAIResponsesTransport'), 'exporter cannot construct the model provider transport');
Assert::false(str_contains($exporter, 'deployment-evidence.json'), 'exporter cannot target the governed evidence registry');
Assert::false(str_contains($exporter, 'upstream.lock.json'), 'exporter cannot target the governed authority lock');

Assert::false(str_contains($exporter, 'payload_json'), 'exporter never selects Monitor event payloads that may contain governed text');
Assert::false(str_contains($exporter, 'EvidenceStore'), 'exporter does not open private artifact bytes');
Assert::false(str_contains($exporter, 'assessment_text'), 'exporter does not select human assessment text');

Assert::contains('provider_attempt_purposes', $exporter, 'exporter binds model receipts to their recorded execution purpose');
Assert::contains("pp.execution_purpose='MANUAL_MONITOR'", $exporter, 'exporter excludes evaluation attempts from ROAD_TEST model receipts');
Assert::contains("'material_provenance' => (string) \$row['material_provenance']", $exporter, 'exporter reports actual/synthetic provenance as a stored fact');

Assert::contains("'source_capture_run_ids' => \$sourceCaptureRunIds", $exporter, 'exporter ties the changed run to grant-wide baseline/change capture identities');
Assert::contains("'consumption_counts' => [", $exporter, 'exporter reports grant-wide source/model budget consumption');

foreach ([
    'ops/staging-monitor-smoke.php',
    'ops/road-test/export-monitor-evidence.php',
    'docs/ROAD_TEST_001_MONITOR.md',
] as $required) {
    Assert::contains($required, $preflight, "release preflight requires P06 operator material: {$required}");
}

// The operator protocol preserves the manual/authority separation.
foreach ([
    'human review #1',
    'human review #2',
    'scheduler remains empty',
    'does not decide PASS or FAIL',
    'File evidence in a separate reviewed PR',
    'Run P10 again against the populated staging state',
] as $phrase) {
    Assert::contains($phrase, $runbook, "ROAD_TEST_001 runbook preserves: {$phrase}");
}
Assert::contains('MODEL_EVALUATION', $runbook, 'evaluation authority is named separately');
Assert::contains('MANUAL_MONITOR', $runbook, 'manual product authority is named separately');
Assert::contains('SCHEDULED_OBSERVATION', $runbook, 'the road test explicitly refuses scheduled authority');
Assert::contains('two runs under the same bounded grant', $runbook, 'ROAD_TEST uses the Monitor baseline/change two-run model');
Assert::contains('changed run ID', $runbook, 'the evidence export targets the changed run while preserving baseline linkage');
