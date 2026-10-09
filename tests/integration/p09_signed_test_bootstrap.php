<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator,TestQaViewerBootstrap,TestMembershipLifecycle};
use Allfather\Loopia\Core\{Clock,Ids};
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
$target = Seed::operator($db, $clock, $scope['tenant_id'], 'QA viewer candidate, no login');
$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'QA read target');
$loc = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id']];
$from = $clock->now();
$until = Clock::format(Clock::parse($from)->modify('+1 hour'));
$overUntil = Clock::format(Clock::parse($from)->modify('+3600001 milliseconds'));
$bootstrap = new TestQaViewerBootstrap($db, $clock, 'testing');
$pair = sodium_crypto_sign_keypair();
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$secret = sodium_crypto_sign_secretkey($pair);
$otherPair = sodium_crypto_sign_keypair();
$otherPublic = base64_encode(sodium_crypto_sign_publickey($otherPair));
$sign = static fn (string $digest): string => base64_encode(
    sodium_crypto_sign_detached(TestQaViewerBootstrap::signatureMessage($digest), $secret)
);

Assert::same("P09_TEST_QA_VIEWER_V2\nabc\n", TestQaViewerBootstrap::signatureMessage('abc'),
    'owner signs a domain-separated, exact plan digest');
Assert::same('P09_BOOTSTRAP_ENVIRONMENT_REQUIRED',
    (new TestQaViewerBootstrap($db, $clock, 'production'))->plan($loc, $target, $from, $until)->reasonCode(),
    'no bootstrap in production, even with TEST tenant');
$over = $bootstrap->plan($loc, $target, $from, $overUntil);
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
    "SELECT role_id FROM roles WHERE role_key='ROLE_DEMO_VIEWER' AND role_version=1"
);
$db->run("DELETE FROM role_permissions WHERE role_id=? AND permission_key='decision.read'", [$role]);
Assert::same('P09_BOOTSTRAP_ROLE_DRIFT',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'no drifted viewer role set accepted');
$db->insert('role_permissions', ['role_id'=>$role,'permission_key'=>'decision.read']);
$db->insert('role_permissions', ['role_id'=>$role,'permission_key'=>'role.assign']);
Assert::same('P09_BOOTSTRAP_ROLE_DRIFT',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'a canonical DemoViewer widened with role.assign must fail closed');
$db->run("DELETE FROM role_permissions WHERE role_id=? AND permission_key='role.assign'", [$role]);

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
$stagingBootstrap = new TestQaViewerBootstrap($db, $clock, 'staging');
$stagingPlan = $stagingBootstrap->plan($loc, $target, $from, $until);
Assert::true($stagingPlan->isOk(), 'parallel TEST deployment class can plan independently');
Assert::same('testing', $plan->value()['definition']['app_environment'],
    'approved authority digest binds exact testing runtime');
Assert::same('staging', $stagingPlan->value()['definition']['app_environment'],
    'staging digest binds exact staging runtime');
Assert::notSame($digest, $stagingPlan->value()['plan_digest'],
    'signature cannot replay across testing and staging');
$crossEnvApply = $stagingBootstrap->apply(
    $loc, $target, $from, $until, $digest, $signature, $public
);
Assert::same('P09_BOOTSTRAP_PLAN_CHANGED',
    $crossEnvApply->isOk() ? 'UNEXPECTED_OK' : $crossEnvApply->reasonCode(),
    'valid testing signature cannot authorize staging');
Assert::same(0, $count(), 'cross-environment replay created no membership');
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
Assert::true($made->isOk(), 'valid signed plan creates exactly one finite read-only viewer seat');
$receipt = $made->value();
Assert::same(true, $receipt['owner_signature_verified'], 'signed receipt marks owner signature verification');
Assert::same($digest, $receipt['plan_digest'], 'exact approval bound to receipt');
Assert::same(1, $count(), 'exactly one row was inserted');
$persisted = $db->one(
    'SELECT m.status, m.valid_until, m.role_version, r.role_key
     FROM workspace_memberships m JOIN roles r ON r.role_id=m.role_id
     WHERE m.membership_id=?', [$receipt['membership_id']]
);
Assert::same(['ACTIVE',$until,1,'ROLE_DEMO_VIEWER'],
    [$persisted['status'],$persisted['valid_until'],(int)$persisted['role_version'],$persisted['role_key']],
    'correct role, version, status, finite expiry');

$guard = new AccessGuard($db, $clock);
foreach (['workspace.read', 'decision.read', 'internal_release.read'] as $permission) {
    Assert::same('ALLOW', $guard->evaluate('OPERATOR',$target,$loc['tenant_id'],$loc['workspace_id'],
        $permission,'P09_QA_VIEWER')['decision'], 'QA viewer may read only: ' . $permission);
}
foreach (['tenant.administer','role.assign','role.revoke','mission.activate','tool.invoke',
          'workspace.create','identity.register','approval.decide_exact_artifact'] as $permission) {
    Assert::same('DENY', $guard->evaluate('OPERATOR',$target,$loc['tenant_id'],$loc['workspace_id'],
        $permission,'P09_QA_VIEWER')['decision'], 'QA viewer cannot delegate/execute: ' . $permission);
}
$member = new TestMembershipLifecycle($db, $clock, 'testing');
$delegation = $member->planAssign([...$loc,'operator_id'=>$target], $reader,
    'ROLE_COMPETITION_MONITOR',1,$from,$until);
Assert::same('P09_TEST_MEMBERSHIP_ADMIN_DENIED',
    $delegation->isOk() ? 'UNEXPECTED_OK' : $delegation->reasonCode(),
    'QA viewer cannot use P09 role-delegation API');
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'same operator cannot silently renew/reissue');
$next = Seed::operator($db, $clock, $scope['tenant_id'], 'Separate QA viewer candidate');
$nextPlan = $bootstrap->plan($loc, $next, $from, $until);
Assert::true($nextPlan->isOk(), 'a separate recipient requires a separate plan and signature');
Assert::notSame($digest,$nextPlan->value()['plan_digest'],
    'owner signature cannot be replayed for a different QA viewer');
$crossRecipient = $bootstrap->apply($loc, $next, $from, $until,
    $nextPlan->value()['plan_digest'], $signature, $public);
Assert::same('P09_BOOTSTRAP_OWNER_SIGNATURE_INVALID',
    $crossRecipient->isOk() ? 'UNEXPECTED_OK' : $crossRecipient->reasonCode(),
    'another recipient cannot reuse the prior signature');
$db->run("UPDATE workspace_memberships SET status='REVOKED' WHERE membership_id=?", [$receipt['membership_id']]);
Assert::same('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY',
    $bootstrap->plan($loc, $target, $from, $until)->reasonCode(),
    'revocation still blocks re-enrolling the same operator');
Assert::same('DENY', $guard->evaluate('OPERATOR',$target,$loc['tenant_id'],$loc['workspace_id'],
    'decision.read','P09_QA_VIEWER')['decision'], 'revocation stands down viewer immediately');

// Independent second plan/signature is the only route for a second recipient.
// Its own expiry prevents a long-lived delegated authority even without cleanup.
$nextApproval = $sign($nextPlan->value()['plan_digest']);
$second = $bootstrap->apply($loc, $next, $from, $until,
    $nextPlan->value()['plan_digest'], $nextApproval, $public);
Assert::true($second->isOk(), 'a separately owner-signed QA viewer may be admitted');
Assert::same('ALLOW', $guard->evaluate('OPERATOR',$next,$loc['tenant_id'],$loc['workspace_id'],
    'decision.read','P09_QA_VIEWER')['decision'], 'second signed viewer may read before expiry');
Assert::same('DENY', $guard->evaluate('OPERATOR',$next,$loc['tenant_id'],$loc['workspace_id'],
    'role.assign','P09_QA_VIEWER')['decision'], 'second signed viewer still cannot delegate');

$clock->advanceMs(61 * 60 * 1000);
Assert::same('DENY', $guard->evaluate('OPERATOR',$next,$loc['tenant_id'],$loc['workspace_id'],
    'decision.read','P09_QA_VIEWER')['decision'], 'finite signed viewer loses access after expiry');
Assert::same('P09_BOOTSTRAP_WINDOW_INVALID',
    $bootstrap->plan($loc, Seed::operator($db,$clock,$scope['tenant_id'],'Late attempt'),$from,$until)->reasonCode(),
    'expired bootstrap plan cannot be used as a late replay');
