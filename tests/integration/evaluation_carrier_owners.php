<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator};
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Evaluation\EvaluationCarrier;
use Allfather\Loopia\Provider\ModelRegistration;
use Allfather\Loopia\Substrate\M02\Seed as Vocabulary;
use Allfather\Loopia\Substrate\M03\{Eligibility,Registry};
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'carrier-owner-compat');
(new Vocabulary($db, $clock))->ensureVocabulary();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$workspaceKey = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);
$value = static function (Outcome $result): mixed {
    if (!$result->isOk()) { throw new RuntimeException('owner refused: ' . $result->reasonCode()); }
    return $result->value();
};
$value($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $scope['operator_id'], 'ROLE_TENANT_ADMIN', 2));
$value($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $scope['operator_id'], 'ROLE_WORKSPACE_OPERATOR'));
$carrier = new EvaluationCarrier($db, $clock, [
    'target' => 'staging.invalid', 'release_ref' => str_repeat('c', 40), 'source_fingerprint' => str_repeat('a', 64),
]);

// Build the fixture independently through the real owners, not the carrier under test. Besides
// proving compatibility, this exposes owner exceptions directly in isolated CI rather than asking
// production code to print driver details that could contain private data.
Assert::case('carrier owner compatibility: model descriptor');
$model = $value((new ModelRegistration($db, $clock))->ensure($scope));
Assert::true(isset($model['governed_tool_id']), 'the existing model registration owner succeeds');
$m04 = new Proposals($db, $clock);
$m03 = new Registry($db, $clock);
$eligibility = new Eligibility($db, $clock);
Assert::case('carrier owner compatibility: TEST mission');
$mission = $m04->createMission($scope['tenant_id'], $scope['workspace_id'], 'TEST', EvaluationCarrier::TITLE,
    EvaluationCarrier::PURPOSE, ['ANALYZE'], 'OPERATOR', $scope['operator_id'], 'DRAFT',
    [], [], [$model['governed_tool_id']]);
$value($m04->activateMission($mission, $scope['operator_id']));
foreach (EvaluationCarrier::ROLES as $role => $class) {
    Assert::case('carrier owner compatibility: ' . $role);
    $agent = $m03->registerValkyrie($scope['tenant_id'], $scope['workspace_id'], EvaluationCarrier::TITLE . ' ' . $role,
        $class, EvaluationCarrier::PURPOSE, $scope['operator_id'], 'DRAFT');
    $contract = $value($m03->issueContract($agent, $scope['tenant_id'], $scope['workspace_id'],
        EvaluationCarrier::TITLE . ' ' . $role, $class, ['ANALYZE'], EvaluationCarrier::PROHIBITIONS))['capability_contract_id'];
    $value($m03->changeStatus($agent, 'ACTIVE', 'SYNTHETIC_EVALUATION_CARRIER_CREATED'));
    $eligibility->grantToolRight($contract, $model['governed_tool_id'], [ModelRegistration::OPERATION_KEY], 'INTERNAL_TRANSFORM', 'ANALYZE', false);
    // Model candidate retention is owned by the evaluation lane, not an M03 product-output binding.
    $value($m04->assignToMissionNarrowed($mission, $agent, $contract, 'EVALUATION_' . strtoupper($role),
        [$model['governed_tool_id']], ['ANALYZE'], $scope['operator_id'], assignedOutputClasses: []));
}
Assert::case('carrier owner compatibility: recognize and reuse exact owner-produced state');
$plan = $value($carrier->plan($scope));
Assert::same('REUSE', $plan['carrier_action'], 'independently composed owner state is recognized exactly');
Assert::same('ALREADY_CONFIGURED_MATCH', $value($carrier->apply($scope, $plan['plan_digest']))['status'], 'reviewed existing state is reusable without a new identity');
Assert::same('[]', $db->scalar('SELECT permitted_output_classes FROM missions WHERE mission_id=?', [$mission]), 'the carrier mission has no product-output scope');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM output_class_bindings'), 'preparation adds no product-output contract');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM mission_assignments WHERE assigned_output_classes <> '[]'"), 'neither assignment has product-output scope');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_execution_grants'), 'preparation has no execution grant');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_model_attempts'), 'preparation has no model attempt');
