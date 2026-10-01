<?php
declare(strict_types=1);

/**
 * The native Monitor's edges: stand-down between stages stops the next external call without
 * rewriting history; two overlapping advances cannot both call out; and the browser can choose none
 * of scope, credential, grant, provider or model.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Workflow\NativeOperations;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];
$service = $fx['service'];
$grant = X::admitted($fx, X::manualDefinition($db, $clock, $fx, X::approveAll($fx, 'MANUAL_MONITOR')));

$baseline = X::value($service->startConfigured($scope, $fx['mission_id'], 'boundary-baseline'));
X::value($service->advance($scope, $baseline['monitor_run_id']));
$clock->advanceDays(1);
$run = X::value($service->startConfigured($scope, $fx['mission_id'], 'boundary-changed'));
$id = $run['monitor_run_id'];
X::value($service->advance($scope, $id));
X::value($service->advance($scope, $id));
$detail = X::value($service->detail($scope, $id));
$digest = $detail['preview_request']['request_digest'];

// ---------------------------------------------------------------- overlapping advances
$second = Harness::secondConnection();
$lock = 'monitor:' . substr(hash('sha256', $id), 0, 48);
Assert::same(1, (int) $second->scalar('SELECT GET_LOCK(?, 0)', [$lock]), 'another worker holds this run');
Assert::same('MONITOR_RUN_BUSY', $service->advance($scope, $id, $digest)->reasonCode(), 'a concurrent advance is refused, not queued behind a live call');
Assert::same(0, $fx['model']->calls, 'and made no call');
$second->scalar('SELECT RELEASE_LOCK(?)', [$lock]);

// ---------------------------------------------------------------- stand-down between stages
$eventsBefore = (int) $db->scalar('SELECT COUNT(*) FROM monitor_run_events WHERE monitor_run_id=?', [$id]);
$captures = $fx['grants']->consumed($grant['execution_grant_id'], 'SOURCE_CAPTURE');
$native = new NativeOperations($db, $clock, $fx['grants'], $fx['approvals'], $fx['decisions'], X::FINGERPRINT, $fx['decision_dir']);
$accessBeforeReadiness = (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records');
$readiness = $native->readiness($scope['tenant_id'], $scope['workspace_id'], $fx['mission_id']);
Assert::true(in_array($readiness['posture'], [NativeOperations::READY, NativeOperations::BLOCKED], true), 'an admitted manual mission is inspected as ready or explicitly blocked');
Assert::same(
    $accessBeforeReadiness,
    (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records'),
    'a successful current-permission readiness inspection does not append ALLOW audit rows',
);
$down = X::value($native->standDown($scope, 'suspend', 'OPERATOR_STAND_DOWN', null, $service));
Assert::same([$id], $down['stopped_runs'], 'the unfinished native run was stopped through its journal');
Assert::same('STOPPED', $db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?', [$id]), 'and is terminal');
Assert::same($eventsBefore + 1, (int) $db->scalar('SELECT COUNT(*) FROM monitor_run_events WHERE monitor_run_id=?', [$id]), 'the stop is one more event; nothing earlier was rewritten');
Assert::same('PASS', X::value($service->detail($scope, $id))['integrity'], 'the event chain still verifies');
Assert::same($captures, $fx['grants']->consumed($grant['execution_grant_id'], 'SOURCE_CAPTURE'), 'spent budget is not refunded');
Assert::same('MONITOR_STAGE_REQUIRES_REVIEW_OR_IS_TERMINAL', $service->advance($scope, $id, $digest)->reasonCode(), 'the stopped run cannot continue');
$fresh = X::value($service->startConfigured($scope, $fx['mission_id'], 'boundary-after-stand-down'));
Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $service->advance($scope, $fresh['monitor_run_id'])->reasonCode(), 'a new run cannot capture after stand-down');
Assert::same(0, $fx['model']->calls, 'no model call happened at any point after the stand-down');
Assert::same(2, $fx['source']->calls, 'and no fetch beyond the two admitted captures');
Assert::true((int) $db->scalar('SELECT COUNT(*) FROM monitor_capture_payloads') >= 2, 'retained capture evidence was not deleted');

// ---------------------------------------------------------------- the browser chooses nothing that matters
$controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/MonitorController.php');
preg_match_all('/\$request->body\(\'([a-z_]+)\'\)/', $controller, $body);
$fields = array_values(array_unique($body[1]));
sort($fields);
Assert::same(['action', 'assessment', 'candidate_digest', 'decision', 'idempotency_key', 'mission_id', 'request_digest', 'run_id'], $fields, 'the workbench reads only these body fields — no tenant, workspace, credential, grant, provider or model');
preg_match_all('/\$request->query\[\'([a-z_]+)\'\]/', $controller, $query);
Assert::same(['run'], array_values(array_unique($query[1])), 'and only a run selector from the query string');
Assert::contains("'workspace_id' => (string) \$session['active_workspace_id']", $controller, 'scope comes from the session');
$index = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/index.php');
Assert::contains("\$scope['tenant_id'], \$scope['workspace_id'],", $index, 'the composition is built for the session scope the controller resolved');
Assert::false(str_contains($index, 'MonitorFactory::forFixture'), 'the deployed entry point never composes fixtures');

// ---------------------------------------------------------------- readiness inspection is not a write permission
$nativeSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Workflow/NativeOperations.php');
Assert::contains("'MONITOR_PREFLIGHT'", $nativeSource, 'native readiness still evaluates current M02 permissions');
Assert::false(
    str_contains($nativeSource, "'MONITOR_PREFLIGHT', null, null, true"),
    'successful Monitor readiness does not retain ALLOW audit rows while claiming to be read-only',
);

