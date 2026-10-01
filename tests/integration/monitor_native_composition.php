<?php
declare(strict_types=1);

/**
 * The deployed Monitor composition, per workspace. Without an ADMITTED MANUAL_MONITOR grant it is
 * the inactive composition — no network leaf exists. With one, the live leaves exist, and every step
 * still re-checks the run's own grant. This test inspects the composition; it never advances an
 * admitted composition, because that one really would open a socket.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Ingestion\{CurlHttpsTransport,DnsHostResolver,FixedHostResolver,NoNetworkTransport};
use Allfather\Loopia\Ops\StandDownPosture;
use Allfather\Loopia\Workflow\{MonitorFactory,MonitorService,NativeOperations};
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];
$compose = static fn (): MonitorService => MonitorFactory::forWorkspace($db, $clock, $fx['evidence_store'], $fx['secret_box'], $fx['decisions'], static fn (): string => X::FINGERPRINT, $scope['tenant_id'], $scope['workspace_id']);
$prop = static function (object $o, string $name): mixed { $r = new ReflectionProperty($o, $name); return $r->getValue($o); };
$leaves = static function (MonitorService $s) use ($prop): array {
    $capture = $prop($s, 'capture');
    $model = $prop($prop($s, 'models'), 'transport');
    return ['source' => get_class($prop($capture, 'transport')), 'resolver' => get_class($prop($capture, 'resolver')), 'model_activated' => $prop($model, 'activated'), 'native' => $prop($s, 'nativeEnabled')];
};
$native = new NativeOperations($db, $clock, $fx['grants'], $fx['approvals'], $fx['decisions'], X::FINGERPRINT, $fx['decision_dir']);

// ---------------------------------------------------------------- unadmitted: no network leaf at all
$inert = $compose();
Assert::same(['source' => NoNetworkTransport::class, 'resolver' => FixedHostResolver::class, 'model_activated' => false, 'native' => false], $leaves($inert), 'without an admitted grant the deployed composition constructs no live leaf');
$run = X::value($inert->startConfigured($scope, $fx['mission_id'], 'composition-native'));
Assert::same('MONITOR_NATIVE_EXECUTION_INACTIVE', $inert->advance($scope, $run['monitor_run_id'])->reasonCode(), 'a native step is refused before admission is even asked');
$detail = X::value($inert->detail($scope, $run['monitor_run_id']));
Assert::same(['required' => true, 'admitted' => false, 'reason_code' => 'EXECUTION_GRANT_NOT_ADMITTED'], $detail['execution_admission'], 'the workbench can still inspect, and says why it will not act');
Assert::same(NativeOperations::INERT, $native->readiness($scope['tenant_id'], $scope['workspace_id'])['posture'], 'the posture is INERT_STAGING');

// An admitted EVALUATION grant does not wire the product.
$evalApprovals = X::approveAll($fx, 'MODEL_EVALUATION', null, ['P01_SIGNAL_CLASSIFIER']);
X::admitted($fx, X::manualDefinition($db, $clock, $fx, $evalApprovals, ['purpose' => 'MODEL_EVALUATION', 'mission_id' => null, 'definition_digest' => null, 'evaluation_manifest_sha256' => str_repeat('7', 64), 'source_scope' => null, 'target_org_role' => null, 'limits' => ['max_model_attempts' => 10, 'max_source_captures' => 0]]));
Assert::same(NoNetworkTransport::class, $leaves($compose())['source'], 'an evaluation grant never constructs live read for the product');

// ---------------------------------------------------------------- admitted: the live leaves exist
$approvals = X::approveAll($fx, 'MANUAL_MONITOR');
$grant = X::admitted($fx, X::manualDefinition($db, $clock, $fx, $approvals));
Assert::same(['source' => CurlHttpsTransport::class, 'resolver' => DnsHostResolver::class, 'model_activated' => true, 'native' => true], $leaves($compose()), 'with an ADMITTED MANUAL_MONITOR grant the live leaves exist — behind every per-step check');
$ready = $native->readiness($scope['tenant_id'], $scope['workspace_id']);
Assert::same(NativeOperations::READY, $ready['posture'], 'the posture is MANUAL_PILOT_READY: ' . implode(',', $ready['reasons']));
Assert::same(false, $ready['posture_is_permission'], 'and says a posture is not permission');

// Readiness means the same principal could actually traverse the current manual path now, not only
// that a grant row exists.
$subscriptionStatus=(string)$db->scalar('SELECT status FROM tenant_subscriptions WHERE tenant_id=? ORDER BY started_at DESC LIMIT 1',[$scope['tenant_id']]);
$db->run("UPDATE tenant_subscriptions SET status='SUSPENDED' WHERE tenant_id=?",[$scope['tenant_id']]);
$noTier=$native->readiness($scope['tenant_id'],$scope['workspace_id']);
Assert::true(in_array('TENANT_ENTITLEMENT_NOT_CURRENT',$noTier['reasons'],true),'a lapsed monitoring entitlement blocks MANUAL_PILOT_READY');
$db->run('UPDATE tenant_subscriptions SET status=? WHERE tenant_id=?',[$subscriptionStatus,$scope['tenant_id']]);

$db->run("UPDATE workspace_memberships SET status='SUSPENDED' WHERE tenant_id=? AND workspace_id=? AND principal_type='OPERATOR' AND principal_id=? AND status='ACTIVE'",[$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id']]);
$noRole=$native->readiness($scope['tenant_id'],$scope['workspace_id']);
Assert::true((bool)array_filter($noRole['reasons'],static fn(string $reason):bool=>str_starts_with($reason,'PRINCIPAL_PERMISSION_NOT_CURRENT:')),'a principal whose current M02 role is gone is not pilot-ready');
$db->run("UPDATE workspace_memberships SET status='ACTIVE' WHERE tenant_id=? AND workspace_id=? AND principal_type='OPERATOR' AND principal_id=? AND status='SUSPENDED'",[$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id']]);

$elsewhere = new NativeOperations($db, $clock, $fx['grants'], $fx['approvals'], $fx['decisions'], str_repeat('9', 64), $fx['decision_dir']);
Assert::true(in_array('GRANT_BOUND_TO_ANOTHER_RELEASE', $elsewhere->readiness($scope['tenant_id'], $scope['workspace_id'])['reasons'], true), 'a grant for another release blocks the pilot on this one');
$other = X::create($db, $clock);
Assert::same(NoNetworkTransport::class, $leaves(MonitorFactory::forWorkspace($db, $clock, $other['evidence_store'], $other['secret_box'], $other['decisions'], X::FINGERPRINT, $other['scope']['tenant_id'], $other['scope']['workspace_id']))['source'], 'admission in one workspace wires nothing in another');

// ---------------------------------------------------------------- stand-down returns it to inert
Assert::true(in_array('1 execution grant(s) are still admitted or future-admitted — suspend or revoke them with ops/monitor/stand-down.php', StandDownPosture::findings(['admitted_execution_grants' => 1] + StandDownPosture::stoodDown(str_repeat('a', 40))), true), 'the stand-down verifier refuses while a grant is admitted');
$down = X::value($native->standDown($scope, 'suspend', 'TEST_STAND_DOWN', null, null));
Assert::same(2, count($down['grants']), 'every admitted grant in the workspace was suspended');
Assert::same(false, $down['refunds_budget'], 'stand-down refunds nothing');
Assert::same(false, $down['retracts_releases'], 'and retracts nothing');
Assert::same(NoNetworkTransport::class, $leaves($compose())['source'], 'after stand-down the composition is inert again');
Assert::same(NativeOperations::INERT, $native->readiness($scope['tenant_id'], $scope['workspace_id'])['posture'], 'and the posture is INERT again');
Assert::same(0, count(X::value($native->standDown($scope, 'suspend', 'TEST_STAND_DOWN', null, null))['grants']), 'a second suspend finds nothing admitted');
$revoked = X::value($native->standDown($scope, 'revoke', 'TEST_REVOKE', null, null));
Assert::same(['REVOKED', 'REVOKED'], array_column($revoked['grants'], 'status'), 'revoke also closes suspended grants');
Assert::same('REVOKED', $fx['grants']->status($grant['execution_grant_id']), 'the product grant is revoked');
