<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M02\{AccessGuard,Seed as M02Seed};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{ScheduleService,SchedulerIdentityProvisioner};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock('2026-10-03T12:00:00.000Z');
$scope = Seed::tenantWorkspace($db, $clock, 'scheduler-provision');
$m02 = new M02Seed($db, $clock);
$m02->ensureVocabulary();

$adminRole = Seed::role($db, $clock, 'scheduler-admin-' . substr(Ids::new(), 0, 8), [
    'tenant.administer', 'identity.register', 'identity.read',
    'identity.suspend_revoke', 'role.assign', 'role.revoke',
]);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $adminRole['role_id']);

$owner = new SchedulerIdentityProvisioner($db, $clock);
$plan = $owner->plan($scope);
Assert::true($plan->isOk(), 'an authorized operator can plan the scheduler service seat');
$p = $plan->value();
Assert::same(['CREATE', 'CREATE', false, false], [
    $p['service_action'], $p['membership_action'], $p['grants_execution'], $p['admits_schedule'],
], 'planning creates identity+membership only and grants no execution');
Assert::same(SchedulerIdentityProvisioner::ROLE_KEY, $p['definition']['role_key'], 'the plan names the canonical narrow scheduler role');
$plannedPermissions = ScheduleService::SERVICE_PERMISSIONS;
sort($plannedPermissions, SORT_STRING);
Assert::same(
    $plannedPermissions,
    $p['definition']['permissions'],
    'the plan exposes exactly the service permissions ScheduleService requires',
);

$before = [
    'services' => (int) $db->scalar("SELECT COUNT(*) FROM service_identities WHERE service_class='SYSTEM_SCHEDULER'"),
    'memberships' => (int) $db->scalar("SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='SERVICE'"),
];
$wrong = $owner->provision($scope, str_repeat('0', 64));
Assert::same('SCHEDULER_IDENTITY_PLAN_CHANGED', $wrong->reasonCode(), 'a different reviewed digest refuses');
Assert::same($before, [
    'services' => (int) $db->scalar("SELECT COUNT(*) FROM service_identities WHERE service_class='SYSTEM_SCHEDULER'"),
    'memberships' => (int) $db->scalar("SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='SERVICE'"),
], 'a stale/forged digest writes nothing');

$made = $owner->provision($scope, $p['plan_digest']);
Assert::true($made->isOk(), 'the exact reviewed plan provisions');
$m = $made->value();
Assert::same([true, true, false, false], [
    $m['service_created'], $m['membership_created'], $m['grants_execution'], $m['admits_schedule'],
], 'the first apply creates only the service seat');

$service = $db->one(
    'SELECT tenant_id, service_key, service_class, status FROM service_identities WHERE service_identity_id=?',
    [$m['service_identity_id']]
);
Assert::same([$scope['tenant_id'], SchedulerIdentityProvisioner::SERVICE_CLASS, 'ACTIVE'], [
    $service['tenant_id'], $service['service_class'], $service['status'],
], 'the service identity is tenant-bound, SYSTEM_SCHEDULER and ACTIVE');

$rolePermissions = array_map(
    static fn (array $r): string => (string) $r['permission_key'],
    $db->all(
        "SELECT rp.permission_key
         FROM workspace_memberships wm
         JOIN roles r ON r.role_id=wm.role_id
         JOIN role_permissions rp ON rp.role_id=wm.role_id
         WHERE wm.membership_id=? AND r.role_key=?
         ORDER BY rp.permission_key",
        [$m['membership_id'], SchedulerIdentityProvisioner::ROLE_KEY]
    )
);
$expected = ScheduleService::SERVICE_PERMISSIONS;
sort($expected, SORT_STRING);
Assert::same($expected, $rolePermissions, 'the scheduler membership grants exactly four permissions');

$guard = new AccessGuard($db, $clock);
foreach (ScheduleService::SERVICE_PERMISSIONS as $permission) {
    Assert::same(
        'ALLOW',
        $guard->evaluate('SERVICE', $m['service_identity_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MONITOR_SCHEDULE')['decision'],
        "scheduler service holds {$permission}",
    );
}
foreach (['mission.create', 'mission.activate', 'evidence.submit', 'proposal.submit', 'hmwh.initiate_review', 'approval.decide_exact_artifact', 'decision.read', 'internal_release.read', 'incident.manage'] as $permission) {
    Assert::same(
        'DENY',
        $guard->evaluate('SERVICE', $m['service_identity_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MONITOR_SCHEDULE')['decision'],
        "scheduler service does not hold {$permission}",
    );
}

$againPlan = $owner->plan($scope);
Assert::true($againPlan->isOk(), 'the existing exact seat can be planned again');
Assert::same(['REUSE', 'REUSE', $p['plan_digest']], [
    $againPlan->value()['service_action'],
    $againPlan->value()['membership_action'],
    $againPlan->value()['plan_digest'],
], 'the second plan converges on the same identity/membership and digest');
$again = $owner->provision($scope, $againPlan->value()['plan_digest']);
Assert::same([false, false, $m['service_identity_id'], $m['membership_id']], [
    $again->value()['service_created'],
    $again->value()['membership_created'],
    $again->value()['service_identity_id'],
    $again->value()['membership_id'],
], 'provisioning is idempotent');

$otherWorkspace = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $otherWorkspace,
    'tenant_id' => $scope['tenant_id'],
    'workspace_key' => 'scheduler-other',
    'schema_version' => '1',
    'display_name' => 'Other workspace',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
]);
Assert::same(
    'DENY',
    $guard->evaluate('SERVICE', $m['service_identity_id'], $scope['tenant_id'], $otherWorkspace, 'mission.read', 'MONITOR_SCHEDULE')['decision'],
    'the workspace membership does not bleed into a sibling workspace',
);

$standDown = $owner->standDown($scope, 'suspend');
Assert::true($standDown->isOk(), 'an authorized operator can suspend the service seat');
Assert::same(
    'DENY',
    $guard->evaluate('SERVICE', $m['service_identity_id'], $scope['tenant_id'], $scope['workspace_id'], 'mission.read', 'MONITOR_SCHEDULE')['decision'],
    'suspension immediately removes service authority',
);
Assert::same('SCHEDULER_IDENTITY_NOT_ACTIVE', $owner->plan($scope)->reasonCode(), 'provisioning refuses to silently revive a suspended service');

$revoked = $owner->standDown($scope, 'revoke');
Assert::true($revoked->isOk(), 'the suspended seat can be deliberately revoked');
Assert::same('REVOKED', (string) $db->scalar('SELECT status FROM service_identities WHERE service_identity_id=?', [$m['service_identity_id']]), 'revocation is durable');
