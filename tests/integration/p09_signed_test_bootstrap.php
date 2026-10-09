<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator,TestAuthorityBootstrap,TestMembershipLifecycle};
use Allfather\Loopia\Core\{Ids};
use Allfather\Loopia\Substrate\M02\{AccessGuard,Seed as M02Seed};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
if (!function_exists('sodium_crypto_sign_detached')) {
    Assert::skip('libsodium required for Ed25519 bootstrap approval'); return;
}
$clock = Harness::clock('2026-10-09T14:00:00.000Z');
$scope = Seed::tenantWorkspace($db, $clock, 'p09-signed-bootstrap');
(new M02Seed($db, $clock))->ensureVocabulary();
$target = Seed::operator($db, $clock, $scope['tenant_id'], 'First grantor, no login');
$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'QA read target');
$loc = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id']];
$from = $clock->now();
$until = '2026-10-09T15:00:00.000Z';
$bootstrap = new TestAuthorityBootstrap($db, $clock, 'testing');
$pair = sodium_crypto_sign_keypair();
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$secret = sodium_crypto_sign_secretkey($pair);
$otherPair = sodium_crypto_sign_keypair();
$otherPublic = base64_encode(sodium_crypto_sign_publickey($otherPair));
$sign = static fn (string $digest): string => base64_encode(
    sodium_crypto_sign_detached(TestAuthorityBootstrap::signatureMessage($digest), $secret)
);

Assert::same("P09_TEST_BOOTSTRAP_V1\nabc\n", TestAuthorityBootstrap::signatureMessage('abc'),
    'owner signs a domain-separated, exact plan digest');
Assert::same('P09_BOOTSTRAP_ENVIRONMENT_REQUIRED',
    (new TestAuthorityBootstrap($db, $clock, 'production'))->plan($loc, $target, $from, $until)->reasonCode(),
    'no bootstrap in production, even with TEST tenant');
$over = $bootstrap->plan($loc, $target, $from, '2026-10-09T15:00:00.001Z');
Assert::same('P09_BOOTSTRAP_WINDOW_INVALID',
    $over->isOk() ? 'UNEXPECTED_OK' : $over->reasonCode(),
    'one-hour plus 1ms refused');
Assert::same('P09_BOOTSTRAP_WINDOW_INVALID',
    $bootstrap->plan($loc, $target, '2026-10-09T14:06:00.000Z',
        '2026-10-09T14:55:00.000Z')->reasonCode(),
    'start more than five minutes ahead refused');

$other = Seed::tenantWorkspace($db, $clock, 'p09-foreign-bootstrap');
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_ACTIVE',
    $bootstrap->plan($loc, $other['operator_id'], $from, $until)->reasonCode(),
    'foreign operator cannot be trusted');
Assert::same('P09_BOOTSTRAP_NOT_ISOLATED_TEST',
    $bootstrap->plan(
        ['tenant_id' => $other['tenant_id'], 'workspace_id' => $scope['workspace_id']],
        $target, $from, $until
    )->reasonCode(), 'tenant-workspace mismatch refused');

$legacy = Seed::operator($db, $clock, $scope['tenant_id'], 'Already has legacy authority');
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$key = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$loc['workspace_id']]);
Assert::true($accounts->addRoleMembership($loc['tenant_id'], $key, $legacy,
    'ROLE_WORKSPACE_OPERATOR', 1)->isOk(), 'existing legacy role for negative test');
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY',
    $bootstrap->plan($loc, $legacy, $from, $until)->reasonCode(),
    'bootstrap cannot upgrade a legacy member');
$alreadyLogin = Seed::operator($db, $clock, $scope['tenant_id'], 'Preexisting login identity');
$db->insert('platform_users', [
    'platform_user_id' => Ids::new(), 'tenant_id' => $scope['tenant_id'],
    'operator_id' => $alreadyLogin, 'email' => 'p09-bootstrap-existing@example.test',
    'display_name' => 'Existing login', 'password_hash' => 'placeholder-not-a-credential',
    'password_algo' => 'argon2id', 'status' => 'RETIRED',
    'failed_attempts' => 0, 'password_changed_at' => $from, 'created_at' => $from
]);
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY',
    $bootstrap->plan($loc, $alreadyLogin, $from, $until)->reasonCode(),
    'even retired login binding excludes bootstrap');

$role = (string) $db->scalar(
    "SELECT role_id FROM roles WHERE role_key='ROLE_TENANT_ADMIN' AND role_version=2"
);
$db->run("DELETE FROM role_permissions WHERE role_id=? AND permission_key='role.assign'", [$role]);
Assert::same('P09_BOOTSTRAP_ROLE_DRIFT',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'no drifted Admin v2 role set accepted');
$db->insert('role_permissions', ['role_id'=>$role,'permission_key'=>'role.assign']);

$plan = $bootstrap->plan($loc, $target, $from, $until);
Assert::true($plan->isOk(), 'unbound TEST operator can be planned without mutation');
$digest = $plan->value()['plan_digest'];
Assert::same(false, $plan->value()['owner_signature_verified'], 'planning is not authorization');
Assert::same(3600, $plan->value()['max_window_seconds'], 'bootstrap bound tighter than P09 roles');
$count = static fn (): int => (int) $db->scalar(
    "SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='OPERATOR' AND principal_id=?",
    [$target]
);
Assert::same(0, $count(), 'plan created no membership');

$signature = $sign($digest);
Assert::same('P09_BOOTSTRAP_OWNER_SIGNATURE_INVALID',
    $bootstrap->apply($loc, $target, $from, $until, $digest, $signature, $otherPublic)->reasonCode(),
    'self-signed approval cannot pass the independently enrolled owner key');
Assert::same('P09_BOOTSTRAP_OWNER_SIGNATURE_INVALID',
    $bootstrap->apply($loc, $target, $from, $until, $digest, base64_encode(str_repeat('a', 64)), $public)->reasonCode(),
    'forged owner signature denied');
Assert::same('P09_BOOTSTRAP_PLAN_DIGEST_INVALID',
    $bootstrap->apply($loc, $target, $from, $until, 'not-a-digest', $signature, $public)->reasonCode(),
    'invalid digest denied');
Assert::same(0, $count(), 'forgery and invalid digest produced no membership');

$wrongDigest = str_repeat('a', 64);
$wrongSignature = $sign($wrongDigest);
Assert::same('P09_BOOTSTRAP_PLAN_CHANGED',
    $bootstrap->apply($loc, $target, $from, $until, $wrongDigest, $wrongSignature, $public)->reasonCode(),
    'a valid signature for a different digest does not authorize the state');
Assert::same(0, $count(), 'stale digest created no role');

$made = $bootstrap->apply($loc, $target, $from, $until, $digest, $signature, $public);
Assert::true($made->isOk(), 'valid signed plan creates exactly one finite Admin-v2 seat');
$receipt = $made->value();
Assert::same(true, $receipt['owner_signature_verified'], 'signed receipt marks owner signature verification');
Assert::same($digest, $receipt['plan_digest'], 'exact approval bound to receipt');
Assert::same(1, $count(), 'exactly one row was inserted');
$persisted = $db->one(
    'SELECT m.status, m.valid_until, m.role_version, r.role_key
     FROM workspace_memberships m JOIN roles r ON r.role_id=m.role_id
     WHERE m.membership_id=?', [$receipt['membership_id']]
);
Assert::same(['ACTIVE',$until,2,'ROLE_TENANT_ADMIN'],
    [$persisted['status'],$persisted['valid_until'],(int)$persisted['role_version'],$persisted['role_key']],
    'correct role, version, status, finite expiry');

$guard = new AccessGuard($db, $clock);
foreach (['tenant.administer', 'role.assign', 'role.revoke'] as $permission) {
    Assert::same('ALLOW', $guard->evaluate('OPERATOR',$target,$loc['tenant_id'],$loc['workspace_id'],
        $permission,'P09_BOOTSTRAP')['decision'], 'bootstrap seat grants canonical ' . $permission);
}
$member = new TestMembershipLifecycle($db, $clock, 'testing');
Assert::true($member->planAssign([...$loc,'operator_id'=>$target], $reader,
    'ROLE_COMPETITION_MONITOR',1,$from,$until)->isOk(), 'new grantor can PLAN regular bounded role');
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'same operator can never bootstrap again');
$next = Seed::operator($db, $clock, $scope['tenant_id'], 'Different proposed bootstrapper');
Assert::same('P09_BOOTSTRAP_ALREADY_USED',
    $bootstrap->plan($loc, $next, $from, $until)->reasonCode(),
    'tenant-wide durable one-use marker blocks subsequent operators');
$db->run("UPDATE workspace_memberships SET status='REVOKED' WHERE membership_id=?", [$receipt['membership_id']]);
Assert::same('P09_BOOTSTRAP_ALREADY_USED',
    $bootstrap->plan($loc, $next, $from, $until)->reasonCode(),
    'revocation never makes bootstrap repeatable');
Assert::same('DENY', $guard->evaluate('OPERATOR',$target,$loc['tenant_id'],$loc['workspace_id'],
    'role.assign','P09_BOOTSTRAP')['decision'], 'revocation stands down role immediately');

$clock->advanceMs(61 * 60 * 1000);
Assert::same('P09_BOOTSTRAP_WINDOW_INVALID',
    $bootstrap->plan($loc, Seed::operator($db,$clock,$scope['tenant_id'],'Late attempt'),$from,$until)->reasonCode(),
    'expired bootstrap plan cannot be used as a late replay');
