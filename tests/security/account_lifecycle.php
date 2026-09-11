<?php

declare(strict_types=1);

/**
 * The platform-login lifecycle, against real MariaDB.
 *
 * Every claim here is one a support procedure depends on, and each has a failure mode that is
 * invisible until it matters:
 *
 *   A SUSPENSION THAT LEFT SESSIONS ALIVE looks like a suspension for twelve hours.
 *   A REACTIVATION THAT RESTORED THEM hands back a token issued under circumstances that have gone.
 *   A RETIREMENT THAT CAN BE UNDONE by the reactivate command is a long suspension wearing the word.
 *   A RESET THAT DID NOT REQUIRE A CHANGE leaves a credential two people know, indefinitely.
 *   A CROSS-TENANT BINDING gives a login a governance identity in a tenant it does not belong to.
 */

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$auth = new Authenticator($db, $clock);
$accounts = new AccountService($db, $clock, $auth);

$scope = Seed::tenantWorkspace($db, $clock, 'lifecycle');
$workspaceKey = (string) $db->scalar(
    'SELECT workspace_key FROM workspaces WHERE workspace_id = ?',
    [$scope['workspace_id']]
);
$ip = '198.51.100.7';
$strong = 'correct horse battery staple';

/** Sign in and return the session token, or null when authentication refused. */
$signIn = static function (string $email, string $password) use ($auth, $ip): ?string {
    $result = $auth->authenticate($email, $password, $ip);

    return $result->isOk() ? (string) $result->value()['session_token'] : null;
};

// ============================================================ creating a login

$operatorId = Seed::operator($db, $clock, $scope['tenant_id'], 'Invited partner');
$created = $accounts->createLogin($operatorId, 'Partner@Example.Test', 'Invited partner', $strong);
Assert::true($created->isOk(), 'a login is created for an existing governed operator');
$platformUserId = (string) $created->value()['platform_user_id'];

$state = $accounts->inspect($platformUserId);
Assert::same('partner@example.test', $state['email'], 'the address is normalized, so a login and a lookup agree');
Assert::same('ACTIVE', $state['status'], 'the account is active');
Assert::same(true, $state['must_change_password'], 'and an operator-issued password must be replaced before use');
Assert::same($scope['tenant_id'], $state['tenant_id'], 'the login is in the operator\'s own tenant');
Assert::same($operatorId, $state['operator_id'], 'and bound to the operator, which is what governance records');

// THE CREDENTIAL IS NOT IN THE REPORT. `inspect` is what the support CLI prints.
$encoded = json_encode($state, JSON_THROW_ON_ERROR);
foreach (['password_hash', 'password_algo', 'token', 'csrf', 'secret'] as $leak) {
    Assert::false(stripos($encoded, $leak) !== false, "the account report carries no {$leak}");
}
Assert::false(
    str_contains($encoded, (string) $db->scalar(
        'SELECT password_hash FROM platform_users WHERE platform_user_id = ?',
        [$platformUserId]
    )),
    'and not the stored hash under any other key either',
);

// ============================================================ what creating a login refuses

// A SECOND LOGIN FOR ONE OPERATOR. One login, one operator, in both directions.
Assert::same(
    'OPERATOR_ALREADY_BOUND',
    $accounts->createLogin($operatorId, 'second@example.test', 'Second', $strong)->reasonCode(),
    'one operator cannot acquire a second login',
);
// The same operator and the same address converges instead — an onboarding command must be re-runnable.
$converged = $accounts->createLogin($operatorId, 'partner@example.test', 'Invited partner', $strong);
Assert::true($converged->isOk(), 'the same operator and address converge');
Assert::same(false, $converged->value()['created'] ?? null, 'without creating anything');

// A CROSS-TENANT BINDING. The FK proves both rows exist; it does not prove they belong together.
$otherTenant = Seed::tenantWorkspace($db, $clock, 'elsewhere');
$foreignOperator = Seed::operator($db, $clock, $otherTenant['tenant_id'], 'Somebody else');
$crossTenant = $accounts->createLogin($foreignOperator, 'partner@example.test', 'Collision', $strong);
Assert::true($crossTenant->isRefusal(), 'a taken address is refused across tenants');
Assert::same('ACCOUNT_EMAIL_TAKEN', $crossTenant->reasonCode(), 'as a collision');
// WITHOUT NAMING WHOSE. A refusal that identified the other tenant would make this an enumerator.
Assert::false(
    str_contains(json_encode($crossTenant->detail, JSON_THROW_ON_ERROR), $scope['tenant_id']),
    'and the refusal names no other tenant — it would otherwise be an enumeration surface',
);

// AN OPERATOR THAT IS NOT ACTIVE. Binding a credential to a stood-down identity mints a login
// whose governance identity is already gone.
$suspendedOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Stood down');
$db->run("UPDATE operators SET status = 'SUSPENDED' WHERE operator_id = ?", [$suspendedOperator]);
Assert::same(
    'OPERATOR_NOT_BINDABLE',
    $accounts->createLogin($suspendedOperator, 'stooddown@example.test', 'Stood down', $strong)->reasonCode(),
    'a credential is not bound to a suspended operator',
);

// AN OPERATOR THAT DOES NOT EXIST is refused rather than created on the way past.
Assert::same(
    'OPERATOR_NOT_FOUND',
    $accounts->createLogin(Ids::new(), 'ghost@example.test', 'Ghost', $strong)->reasonCode(),
    'an operator that does not exist is not invented',
);

// A WEAK PASSWORD is refused by the existing policy, not by a second one invented here.
Assert::same(
    'PASSWORD_TOO_SHORT',
    $accounts->createLogin(
        Seed::operator($db, $clock, $scope['tenant_id'], 'Short'),
        'short@example.test',
        'Short',
        'abc',
    )->reasonCode(),
    'the existing password policy is the one that applies',
);

// ============================================================ the forced first change

$token = $signIn('partner@example.test', $strong);
Assert::notSame(null, $token, 'correct credentials authenticate even under the change requirement');
$resolved = $auth->resolveSession((string) $token);
Assert::true($resolved->isOk(), 'and a session resolves');
Assert::same(
    true,
    $resolved->value()['must_change_password'],
    'carrying the lifecycle state, re-read on every request rather than captured at sign-in',
);

// The holder replaces it. Every session dies — including this one, which is the point.
$newPassword = 'a different long passphrase';
$changed = $accounts->changePassword($platformUserId, $strong, $newPassword);
Assert::true($changed->isOk(), 'the holder replaces the password');
Assert::true(($changed->value()['sessions_revoked'] ?? 0) >= 1, 'and every live session is revoked with it');
Assert::same(
    false,
    $accounts->inspect($platformUserId)['must_change_password'],
    'the requirement is cleared by the change that satisfies it',
);
Assert::true(
    $auth->resolveSession((string) $token)->isRefusal(),
    'the session that made the change no longer resolves — a change whose own session survived would leave stolen ones alive too',
);
Assert::same(null, $signIn('partner@example.test', $strong), 'the old password is gone');
$token = $signIn('partner@example.test', $newPassword);
Assert::notSame(null, $token, 'and the new one works');

// The current password is verified even though a session exists. A session proves somebody signed
// in; it does not prove the person at the keyboard now is the same one.
Assert::same(
    'CURRENT_PASSWORD_INCORRECT',
    $accounts->changePassword($platformUserId, 'not the password', 'yet another long passphrase')->reasonCode(),
    'a change without the current password is refused',
);
Assert::same(
    'PASSWORD_UNCHANGED',
    $accounts->changePassword($platformUserId, $newPassword, $newPassword)->reasonCode(),
    'and re-setting the same password is not a change',
);
Assert::true(
    $auth->resolveSession((string) $token)->isOk(),
    'and a refused change revokes nothing — the session that attempted it survives',
);

// ============================================================ the operator-issued reset

$issued = 'an operator issued passphrase';
$reset = $accounts->resetPassword($platformUserId, $issued);
Assert::true($reset->isOk(), 'an operator issues a replacement password');
Assert::true(($reset->value()['sessions_revoked'] ?? 0) >= 1, 'every live session ends with it');
Assert::true(
    $auth->resolveSession((string) $token)->isRefusal(),
    'the holder is signed out immediately, not at the next expiry',
);
Assert::same(
    true,
    $accounts->inspect($platformUserId)['must_change_password'],
    'and the requirement returns — two people know this password until it is replaced',
);

// It is not a parameter. There is no call shape that issues a password somebody else chose without
// also requiring it to be replaced.
$resetMethod = new ReflectionMethod(AccountService::class, 'resetPassword');
Assert::same(2, $resetMethod->getNumberOfParameters(), 'the reset takes no flag that could switch the requirement off');

$token = $signIn('partner@example.test', $issued);
Assert::notSame(null, $token, 'the issued password signs in');

// ============================================================ suspension

$suspended = $accounts->suspend($platformUserId);
Assert::true($suspended->isOk(), 'the account is suspended');
Assert::true(($suspended->value()['sessions_revoked'] ?? 0) >= 1, 'and its live sessions end in the same transaction');
Assert::true(
    $auth->resolveSession((string) $token)->isRefusal(),
    'an already-issued session stops resolving immediately',
);
Assert::same(null, $signIn('partner@example.test', $issued), 'and a suspended account cannot sign in');

// The revocation is recorded, not merely implied by the status check.
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM platform_sessions WHERE platform_user_id = ? AND revoked_at IS NULL',
        [$platformUserId]
    ),
    'no session row is left unrevoked',
);

// ============================================================ reactivation restores nothing

$revokedBefore = (int) $db->scalar(
    'SELECT COUNT(*) FROM platform_sessions WHERE platform_user_id = ? AND revoked_at IS NOT NULL',
    [$platformUserId]
);
Assert::true($accounts->reactivate($platformUserId)->isOk(), 'the account returns to service');
Assert::same(
    $revokedBefore,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM platform_sessions WHERE platform_user_id = ? AND revoked_at IS NOT NULL',
        [$platformUserId]
    ),
    'and every previously revoked session stays revoked — revocation is a decision, not a side effect',
);
Assert::true($auth->resolveSession((string) $token)->isRefusal(), 'the old token is still dead');
$token = $signIn('partner@example.test', $issued);
Assert::notSame(null, $token, 'the account signs in again, which is how it comes back');

// A locked account is unlocked by reactivation, or the first mistyped password would re-lock it.
$db->run(
    "UPDATE platform_users SET status = 'LOCKED', failed_attempts = 10, locked_until = ? WHERE platform_user_id = ?",
    ['2099-01-01T00:00:00.000Z', $platformUserId]
);
Assert::true($accounts->reactivate($platformUserId)->isOk(), 'a locked account is reactivated');
$unlocked = $accounts->inspect($platformUserId);
Assert::same(0, $unlocked['failed_attempts'], 'and its attempt counter is cleared');
Assert::same(null, $unlocked['locked_until'], 'along with the lock itself');

// ============================================================ revoking sessions alone

$token = $signIn('partner@example.test', $issued);
$revoked = $accounts->revokeSessions($platformUserId);
Assert::true($revoked->isOk(), 'sessions can be revoked without a lifecycle change');
Assert::true($auth->resolveSession((string) $token)->isRefusal(), 'the session is gone');
Assert::same('ACTIVE', $accounts->inspect($platformUserId)['status'], 'and the account is still ACTIVE');
Assert::notSame(null, $signIn('partner@example.test', $issued), 'so the holder can sign in again');

// ============================================================ retirement is terminal

$token = $signIn('partner@example.test', $issued);
Assert::true($accounts->retire($platformUserId)->isOk(), 'the account is retired');
Assert::true($auth->resolveSession((string) $token)->isRefusal(), 'its sessions end');
Assert::same(null, $signIn('partner@example.test', $issued), 'and it can no longer authenticate');

// THE HALF THAT MATTERS: the same command that undoes a suspension must not undo this.
$resurrect = $accounts->reactivate($platformUserId);
Assert::true($resurrect->isRefusal(), 'a retired account is NOT reactivated');
Assert::same('ACCOUNT_TRANSITION_NOT_PERMITTED', $resurrect->reasonCode(), 'the transition is refused by name');
Assert::same('RETIRED', $accounts->inspect($platformUserId)['status'], 'and it stays retired');
Assert::same(
    'ACCOUNT_RETIRED',
    $accounts->resetPassword($platformUserId, 'yet another long passphrase')->reasonCode(),
    'nor is it un-retired by reissuing a credential for it',
);
Assert::same(null, $signIn('partner@example.test', $issued), 'it remains unable to sign in');

// ============================================================ role membership grants nothing new

$freshOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Role probe');
Assert::same(
    'ROLE_NOT_DEFINED',
    $accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $freshOperator, 'role_that_does_not_exist')->reasonCode(),
    'an unknown role key is refused, never minted — onboarding must not create authority',
);

Seed::role($db, $clock, 'beta_console_reader', ['battlecard.read']);
$granted = $accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $freshOperator, 'beta_console_reader');
Assert::true($granted->isOk(), 'an existing role is granted');
Assert::same(true, $granted->value()['created'] ?? null, 'creating the membership');
$regranted = $accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $freshOperator, 'beta_console_reader');
Assert::same(false, $regranted->value()['created'] ?? null, 'and re-granting converges rather than duplicating');
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM workspace_memberships
         WHERE tenant_id = ? AND principal_id = ? AND principal_type = 'OPERATOR'",
        [$scope['tenant_id'], $freshOperator]
    ),
    'one membership, not two',
);

// CROSS-TENANT, both ways round.
Assert::same(
    'OPERATOR_NOT_IN_TENANT',
    $accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $foreignOperator, 'beta_console_reader')->reasonCode(),
    'an operator from another tenant cannot be given a membership here',
);
Assert::same(
    'WORKSPACE_NOT_FOUND_IN_SCOPE',
    $accounts->addRoleMembership($otherTenant['tenant_id'], $workspaceKey, $foreignOperator, 'beta_console_reader')->reasonCode(),
    'and a workspace key resolves only inside its own tenant',
);

// ============================================================ lookup is scoped, not filtered

Assert::notSame(null, $accounts->findByEmailAddress('partner@example.test'), 'an account is found by address');
Assert::notSame(
    null,
    $accounts->findByEmailAddress('partner@example.test', $scope['tenant_id']),
    'and inside its own tenant',
);
// A SCOPE, NOT A FILTER: out of scope is indistinguishable from absent, or the support command
// becomes a way to ask whether an address exists in some other tenant.
Assert::same(
    null,
    $accounts->findByEmailAddress('partner@example.test', $otherTenant['tenant_id']),
    'an address outside the named tenant reads exactly as an address that does not exist',
);
Assert::same(
    null,
    $accounts->findByEmailAddress('nobody@example.test', $otherTenant['tenant_id']),
    'which is the same answer an absent address gets',
);
