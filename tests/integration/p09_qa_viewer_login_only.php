<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator,TestQaViewerBootstrap,TestQaViewerLogin};
use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Substrate\M02\{AccessGuard,Seed as M02Seed};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
if (!function_exists('sodium_crypto_sign_detached')) {
    Assert::skip('libsodium required for signed P09 QA login'); return;
}
$clock = Harness::clock('2026-10-09T14:00:00.000Z');
$seed = Seed::tenantWorkspace($db, $clock, 'p09-qa-login-only');
(new M02Seed($db, $clock))->ensureVocabulary();
$scope = ['tenant_id' => $seed['tenant_id'], 'workspace_id' => $seed['workspace_id']];
$target = Seed::operator($db, $clock, $seed['tenant_id'], 'P09 QA login test');
$start = $clock->now();
$until = Clock::format(Clock::parse($start)->modify('+30 minutes'));
$bootstrap = new TestQaViewerBootstrap($db, $clock, 'testing');
$before = $bootstrap->plan($scope, $target, $start, $until);
Assert::true($before->isOk(), 'signed VIEWER bootstrap can be independently planned');
$digest = $before->value()['plan_digest'];
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$signature = base64_encode(sodium_crypto_sign_detached(
    TestQaViewerBootstrap::signatureMessage($digest), $secret
));
$added = $bootstrap->apply($scope, $target, $start, $until, $digest, $signature, $public);
Assert::true($added->isOk(), 'test-only signed membership exists prior to creating the login');
$membershipId = $added->value()['membership_id'];
$login = new TestQaViewerLogin($db, $clock, 'testing');
$email = 'p09-qa-viewer@example.test';
$password = 'This-Is-A-Test-Only-Password-2026';
$membershipCount = static fn (): int => (int) $db->scalar(
    "SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='OPERATOR' AND principal_id=?",
    [$target]
);
$accountCount = static fn (): int => (int) $db->scalar(
    'SELECT COUNT(*) FROM platform_users WHERE operator_id=?', [$target]
);
Assert::same(1, $membershipCount(), 'one signed finite TEST membership exists');
Assert::same(0, $accountCount(), 'login does not pre-exist before its separate act');

$planned = $login->plan($scope, $target, $membershipId, $email, $digest);
Assert::true($planned->isOk(), 'private login plan matches persisted signed viewer seat');
Assert::same(false, $planned->value()['owner_signature_verified'], 'plan does not claim authorization');
Assert::same(true, $planned->value()['must_change_password'], 'forced first-password change is retained');
Assert::same('P09_QA_LOGIN_EMAIL_NOT_TEST_ONLY',
    $login->plan($scope, $target, $membershipId, 'customer@example.com', $digest)->reasonCode(),
    'non-synthetic email refused');
Assert::same('P09_QA_LOGIN_SIGNED_VIEWER_PLAN_CHANGED',
    $login->plan($scope, $target, $membershipId, $email, str_repeat('0', 64))->reasonCode(),
    'missing/wrong original plan digest refused');
Assert::same('P09_QA_LOGIN_OWNER_SIGNATURE_INVALID',
    $login->create($scope, $target, $membershipId, $email, $digest,
        base64_encode(str_repeat('a', 64)), $public, $password)->reasonCode(),
    'fake signature refuses before any login');
Assert::same('P09_QA_LOGIN_OWNER_SIGNATURE_INVALID',
    $login->create($scope, $target, $membershipId, $email, $digest,
        $signature, base64_encode(str_repeat('b', 32)), $password)->reasonCode(),
    'untrusted replacement verifier refuses');
Assert::same(0, $accountCount(), 'signature failures have no write');
Assert::same('P09_QA_LOGIN_ENVIRONMENT_REQUIRED',
    (new TestQaViewerLogin($db, $clock, 'production'))->plan(
        $scope, $target, $membershipId, $email, $digest
    )->reasonCode(), 'production refuses even signed TEST viewer');

$other = Seed::tenantWorkspace($db, $clock, 'p09-login-foreign');
Assert::same('P09_QA_LOGIN_NOT_ISOLATED_TEST',
    $login->plan(
        ['tenant_id' => $other['tenant_id'], 'workspace_id' => $other['workspace_id']],
        $target, $membershipId, $email, $digest
    )->reasonCode(), 'foreign TEST scope cannot claim viewer recipient');

$role = (string) $db->scalar(
    "SELECT role_id FROM roles WHERE role_key='ROLE_DEMO_VIEWER' AND role_version=1"
);
$db->insert('role_permissions', ['role_id' => $role, 'permission_key' => 'role.assign']);
Assert::same('P09_QA_LOGIN_VIEWER_ROLE_DRIFT',
    $login->plan($scope, $target, $membershipId, $email, $digest)->reasonCode(),
    'a viewer role widened with delegation cannot receive a login');
$db->run("DELETE FROM role_permissions WHERE role_id=? AND permission_key='role.assign'", [$role]);

$created = $login->create($scope, $target, $membershipId, $email, $digest, $signature, $public, $password);
Assert::true($created->isOk(), 'verified original signed viewer creates login only');
Assert::same(true, $created->value()['owner_signature_verified'], 'matching signature verified for login path');
Assert::same(true, $created->value()['must_change_password'], 'credential lifecycle is not bypassed');
Assert::same(1, $accountCount(), 'one new login bound to existing TEST operator');
Assert::same(1, $membershipCount(), 'login creation does not assign any further role');
$platformUserId = $created->value()['platform_user_id'];
$row = $db->one('SELECT must_change_password, status, email FROM platform_users WHERE platform_user_id=?',
    [$platformUserId]);
Assert::same([1, 'ACTIVE', $email],
    [(int) $row['must_change_password'], $row['status'], $row['email']],
    'password-change flag and synthetic identity persisted');
Assert::same('P09_QA_LOGIN_RECIPIENT_NOT_EMPTY',
    $login->plan($scope, $target, $membershipId, $email, $digest)->reasonCode(),
    'second login creation never silently resets the existing credential');

$guard = new AccessGuard($db, $clock);
Assert::same('ALLOW', $guard->evaluate('OPERATOR', $target, $scope['tenant_id'],
    $scope['workspace_id'], 'decision.read', 'P09_QA_LOGIN')['decision'],
    'existing signed viewer read authority survives login-only creation');
Assert::same('DENY', $guard->evaluate('OPERATOR', $target, $scope['tenant_id'],
    $scope['workspace_id'], 'role.assign', 'P09_QA_LOGIN')['decision'],
    'login-only does not create delegation rights');

// The supported account lifecycle retires the login AND revokes sessions.
// It does not rewrite the historical membership: expiry remains the M02 boundary.
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$retired = $accounts->retire($platformUserId, 'P09_QA_LOGIN_STAND_DOWN');
Assert::true($retired->isOk(), 'supported account lifecycle can retire QA identity');
Assert::same('RETIRED', $db->scalar(
    'SELECT status FROM platform_users WHERE platform_user_id=?', [$platformUserId]
), 'QA login is terminally retired');
Assert::same('ACTIVE', $db->scalar(
    'SELECT status FROM workspace_memberships WHERE membership_id=?', [$membershipId]
), 'retiring a login does not falsely claim membership status REVOKED');
$clock->advanceMs(31 * 60 * 1000);
Assert::same('DENY', $guard->evaluate('OPERATOR', $target, $scope['tenant_id'],
    $scope['workspace_id'], 'decision.read', 'P09_QA_LOGIN')['decision'],
    'viewer membership is effectively denied after its precise expiry');
