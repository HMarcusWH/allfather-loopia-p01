<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator,TestMembershipLifecycle};
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M02\{AccessGuard,Seed as M02Seed};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock('2026-10-08T22:00:00.000Z');
$scope = Seed::tenantWorkspace($db, $clock, 'p09-bounded-membership');
(new M02Seed($db, $clock))->ensureVocabulary();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$workspaceKey = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);

// The grantor's pre-existing role is an explicit isolated TEST harness fixture,
// never self-provisioned by the new production-facing membership owner.
$admin = $accounts->addRoleMembership($scope['tenant_id'], $workspaceKey,
    $scope['operator_id'], 'ROLE_TENANT_ADMIN', 2);
Assert::true($admin->isOk(), 'harness grantor has explicitly seeded TenantAdmin v2');
$owner = new TestMembershipLifecycle($db, $clock, 'testing');
$guard = new AccessGuard($db, $clock);
$target = Seed::operator($db, $clock, $scope['tenant_id'], 'Synthetic P09 reader');
$start = '2026-10-08T22:00:00.000Z';
$until = '2026-10-08T23:00:00.000Z';

$count = static fn (): int => (int) $db->scalar(
    "SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='OPERATOR' AND principal_id=?", [$target]
);
Assert::same(0, $count(), 'target begins without memberships');
Assert::same('P09_TEST_WINDOW_NOT_CANONICAL',
    TestMembershipLifecycle::validateWindow($start, '2026-10-08T23:00:00Z', $start)->reasonCode(),
    'noncanonical expiry is rejected');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow($start, '2026-10-09T23:00:01.000Z', $start)->reasonCode(),
    'duration cannot exceed 24 hours');

$invalidRole = $owner->planAssign($scope, $target, 'ROLE_TENANT_ADMIN', 1, $start, $until);
Assert::same('P09_TEST_ROLE_NOT_ADOPTED', $invalidRole->reasonCode(),
    'TEST-only owner refuses Admin v1, selecting exactly the reviewed v2');
Assert::same('P09_TEST_TARGET_INVALID_OR_SELF',
    $owner->planAssign($scope, $scope['operator_id'], 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    'the grantor cannot silently promote themself');
Assert::same('P09_TEST_ROLE_NOT_ADOPTED',
    $owner->planAssign($scope, $target, 'ROLE_ACTION_APPROVER', 1, $start, $until)->reasonCode(),
    'approval roles are not within the P09 fixture entitlement');

$unauthorized = Seed::operator($db, $clock, $scope['tenant_id'], 'Unprivileged tester');
Assert::same('P09_TEST_MEMBERSHIP_ADMIN_DENIED',
    $owner->planAssign([...$scope, 'operator_id' => $unauthorized],
        $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    'without role.assign and tenant.administer no plan is granted');

$plan = $owner->planAssign($scope, $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until);
Assert::true($plan->isOk(), 'legitimate finite TEST role can be planned');
$p = $plan->value();
Assert::same(false, $p['owner_authorization_verified'],
    'a digest is not owner approval or a signature');
Assert::same(86400, $p['max_window_seconds'], 'bounded interval is declared');
Assert::same($until, $p['definition']['valid_until'], 'exact expiration is bound to plan');
Assert::same(0, $count(), 'planning does not create a membership');

Assert::same('P09_TEST_PLAN_CHANGED',
    $owner->assign($scope, $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until, str_repeat('a', 64))->reasonCode(),
    'forged digest cannot confer authority');
Assert::same(0, $count(), 'digest mismatch creates no role');

$made = $owner->assign($scope, $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until, $p['plan_digest']);
Assert::true($made->isOk(), 'exact reviewed plan creates the bounded role');
$id = $made->value()['membership_id'];
$row = $db->one('SELECT status, valid_from, valid_until, role_version, principal_id FROM workspace_memberships WHERE membership_id=?', [$id]);
Assert::same(['ACTIVE', $start, $until, 1, $target],
    [$row['status'], $row['valid_from'], $row['valid_until'], (int) $row['role_version'], $row['principal_id']],
    'persisted role is version-pinned, scoped and time bounded');
Assert::same('ALLOW',
    $guard->evaluate('OPERATOR', $target, $scope['tenant_id'], $scope['workspace_id'], 'mission.read', 'P09_TEST')['decision'],
    'target may read via the finite role before expiry');
Assert::same('P09_TEST_MEMBERSHIP_ALREADY_EXISTS',
    $owner->planAssign($scope, $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    're-run does not widen, prolong, reactivate or overwrite the existing membership');

$otherWorkspace = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $otherWorkspace, 'tenant_id' => $scope['tenant_id'],
    'workspace_key' => 'p09-foreign', 'schema_version' => '1', 'display_name' => 'Foreign test',
    'workspace_type' => 'TEST', 'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0, 'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE', 'created_at' => $clock->now(),
]);
Assert::same('DENY',
    $guard->evaluate('OPERATOR', $target, $scope['tenant_id'], $otherWorkspace, 'mission.read', 'P09_TEST')['decision'],
    'membership cannot bleed across TEST workspaces');

$revocation = $owner->planRevoke($scope, $id);
Assert::true($revocation->isOk(), 'revocation is planned separately from assignment');
Assert::same('P09_TEST_PLAN_CHANGED',
    $owner->revoke($scope, $id, str_repeat('0', 64))->reasonCode(),
    'stale or forged revocation digest refuses');
Assert::same('ACTIVE', $db->scalar('SELECT status FROM workspace_memberships WHERE membership_id=?', [$id]),
    'wrong revocation digest leaves current membership intact');
Assert::true($owner->revoke($scope, $id, $revocation->value()['plan_digest'])->isOk(),
    'reviewed stand-down revokes the exact membership');
Assert::same('REVOKED', $db->scalar('SELECT status FROM workspace_memberships WHERE membership_id=?', [$id]),
    'membership state is permanently withdrawn');
Assert::same('DENY',
    $guard->evaluate('OPERATOR', $target, $scope['tenant_id'], $scope['workspace_id'], 'mission.read', 'P09_TEST')['decision'],
    'revocation takes effect immediately without depending on login lifecycle');
Assert::same('P09_TEST_MEMBERSHIP_NOT_ACTIVE', $owner->planRevoke($scope, $id)->reasonCode(),
    'withdrawn membership is not silently revived');
Assert::same('P09_TEST_MEMBERSHIP_ALREADY_EXISTS',
    $owner->planAssign($scope, $target, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    'new assign path cannot resurrect the withdrawn membership');

// Legacy beta onboarding role remains unlimited by design; new TEST owner is NOT
// permitted to revoke its membership as if it were one of its own bounded grants.
$legacy = Seed::operator($db, $clock, $scope['tenant_id'], 'Legacy');
Assert::true($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey,
    $legacy, 'ROLE_WORKSPACE_OPERATOR', 1)->isOk(), 'legacy assignment exists');
$legacyId = (string) $db->scalar(
    'SELECT membership_id FROM workspace_memberships WHERE tenant_id=? AND principal_id=?', [$scope['tenant_id'], $legacy]
);
Assert::same('P09_TEST_MEMBERSHIP_NOT_BOUNDED_IN_SCOPE',
    $owner->planRevoke($scope, $legacyId)->reasonCode(),
    'the TEST-only tool never revokes arbitrary unbounded legacy roles');

// Anti-tenant confusion is checked before either mutating action.
$db->run("UPDATE tenants SET tenant_type='INTERNAL_DEMO_OWNER' WHERE tenant_id=?", [$scope['tenant_id']]);
Assert::same('P09_TEST_SCOPE_NOT_ISOLATED',
    $owner->planAssign($scope, $unauthorized, 'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    'tenant-type drift closes authority');
$db->run("UPDATE tenants SET tenant_type='TEST' WHERE tenant_id=?", [$scope['tenant_id']]);

$otherTenant = Seed::tenantWorkspace($db, $clock, 'other-p09');
Assert::same('P09_TEST_TARGET_NOT_ACTIVE_IN_TENANT',
    $owner->planAssign($scope, $otherTenant['operator_id'],
        'ROLE_WORKSPACE_OPERATOR', 1, $start, $until)->reasonCode(),
    'foreign-tenant target cannot be enrolled');
Assert::same(1, $count(), 'the only target membership remains the revoked historical record');

// Expiry is an AccessGuard decision, not a cron cleanup or login-side effect.
$expiring = Seed::operator($db, $clock, $scope['tenant_id'], 'Expiring synthetic test operator');
$expiryStart = $clock->now();
$expiryUntil = \Allfather\Loopia\Core\Clock::format(
    \Allfather\Loopia\Core\Clock::parse($expiryStart)->modify('+30 minutes')
);
$expiryPlan = $owner->planAssign($scope, $expiring,
    'ROLE_COMPETITION_MONITOR', 1, $expiryStart, $expiryUntil);
Assert::true($expiryPlan->isOk(), 'a second finite member can be planned');
Assert::true($owner->assign($scope, $expiring, 'ROLE_COMPETITION_MONITOR', 1,
    $expiryStart, $expiryUntil, $expiryPlan->value()['plan_digest'])->isOk(),
    'finite role assigned to a separate operator');
Assert::same('ALLOW',
    $guard->evaluate('OPERATOR', $expiring, $scope['tenant_id'], $scope['workspace_id'],
        'competition_monitoring.read', 'P09_TEST')['decision'], 'finite role works before expiry');
$clock->advanceMs(31 * 60 * 1000);
Assert::same('DENY',
    $guard->evaluate('OPERATOR', $expiring, $scope['tenant_id'], $scope['workspace_id'],
        'competition_monitoring.read', 'P09_TEST')['decision'], 'role automatically stops at expiry');
Assert::same('P09_TEST_MEMBERSHIP_ALREADY_EXISTS',
    $owner->planAssign($scope, $expiring, 'ROLE_COMPETITION_MONITOR', 1,
        $clock->now(), \Allfather\Loopia\Core\Clock::format(
            \Allfather\Loopia\Core\Clock::parse($clock->now())->modify('+30 minutes')
        ))->reasonCode(), 'expired membership is never silently renewed');

// The trusted host environment is an independent guard, not inferred from a TEST tenant.
Assert::same('P09_TEST_ENVIRONMENT_REQUIRED',
    (new TestMembershipLifecycle($db, $clock, 'production'))->planAssign(
        $scope, $unauthorized, 'ROLE_WORKSPACE_OPERATOR', 1,
        $clock->now(), \Allfather\Loopia\Core\Clock::format(
            \Allfather\Loopia\Core\Clock::parse($clock->now())->modify('+30 minutes')
        )
    )->reasonCode(), 'even an active TEST tenant cannot obtain assignments on a production environment');

// Drift in the shared role permission set must never be papered over by a plan.
$compRole = (string) $db->scalar(
    "SELECT role_id FROM roles WHERE role_key='ROLE_COMPETITION_MONITOR' AND role_version=1"
);
$db->run('DELETE FROM role_permissions WHERE role_id=? AND permission_key=?',
    [$compRole, 'competition_monitoring.read']);
$fresh = Seed::operator($db, $clock, $scope['tenant_id'], 'Role vocabulary drift target');
Assert::same('P09_TEST_ROLE_VOCABULARY_CONFLICT',
    $owner->planAssign($scope, $fresh, 'ROLE_COMPETITION_MONITOR', 1,
        $clock->now(), \Allfather\Loopia\Core\Clock::format(
            \Allfather\Loopia\Core\Clock::parse($clock->now())->modify('+30 minutes')
        ))->reasonCode(), 'narrow owner detects canonical role-permission drift');
