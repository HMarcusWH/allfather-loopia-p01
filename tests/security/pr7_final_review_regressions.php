<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Substrate\M02\AccessGuard;
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
$scope = Seed::tenantWorkspace($db, $clock, 'pr7-final');
$workspaceKey = (string) $db->scalar(
    'SELECT workspace_key FROM workspaces WHERE workspace_id = ?',
    [$scope['workspace_id']]
);

// A holder-initiated password change must re-check the current credential under the row lock that
// protects the write. The first change invalidates the old credential; a later write presenting that
// stale credential is refused rather than overwriting the newer password.
$raceOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Password race probe');
$oldPassword = 'correct horse battery staple';
$firstPassword = 'a newer password chosen first';
$secondPassword = 'a later competing password';
$raceAccount = $accounts->createLogin(
    $raceOperator,
    'password-race@example.test',
    'Password race probe',
    $oldPassword,
    false,
);
Assert::true($raceAccount->isOk(), 'the password-race account is created');
$raceId = (string) $raceAccount->value()['platform_user_id'];
Assert::true(
    $accounts->changePassword($raceId, $oldPassword, $firstPassword)->isOk(),
    'the current credential can replace itself',
);
$staleChange = $accounts->changePassword($raceId, $oldPassword, $secondPassword);
Assert::true($staleChange->isRefusal(), 'a stale current password cannot write a second replacement');
Assert::same(
    'CURRENT_PASSWORD_INCORRECT',
    $staleChange->reasonCode(),
    'the stale-current-password refusal is the ordinary current-password refusal',
);
Assert::true(
    $accounts->changePassword($raceId, $firstPassword, $secondPassword)->isOk(),
    'the actually current credential can still change the password',
);

// A login that already exists is not silently treated as onboardable when its own lifecycle has
// been suspended. Idempotent create/bind is convergence only for an ACTIVE account; reactivation is
// a deliberate support act.
$inactiveOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Inactive account probe');
$inactiveAccount = $accounts->createLogin(
    $inactiveOperator,
    'inactive-account@example.test',
    'Inactive account probe',
    $oldPassword,
    false,
);
Assert::true($inactiveAccount->isOk(), 'the lifecycle probe account is created');
$inactiveId = (string) $inactiveAccount->value()['platform_user_id'];
Assert::true($accounts->suspend($inactiveId)->isOk(), 'the lifecycle probe account is suspended');
$rebind = $accounts->createLogin(
    $inactiveOperator,
    'inactive-account@example.test',
    'Inactive account probe',
    $oldPassword,
    false,
);
Assert::true($rebind->isRefusal(), 'create/bind does not converge on a suspended login');
Assert::same('ACCOUNT_NOT_ACTIVE', $rebind->reasonCode(), 'the inactive account is refused by lifecycle state');

// ACTIVE status alone is not a current membership. M02 additionally requires the validity window,
// so onboarding convergence must apply the exact same time predicate or it can report a role as
// granted while the owner gate denies it.
$membershipOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Validity-window probe');
Seed::role($db, $clock, 'validity_window_reader', ['battlecard.read']);
$granted = $accounts->addRoleMembership(
    $scope['tenant_id'],
    $workspaceKey,
    $membershipOperator,
    'validity_window_reader',
);
Assert::true($granted->isOk(), 'the role membership is initially granted');
$membershipId = (string) $granted->value()['membership_id'];
$db->run(
    "UPDATE workspace_memberships SET valid_until = ? WHERE membership_id = ?",
    [$clock->now(), $membershipId]
);
$current = $accounts->addRoleMembership(
    $scope['tenant_id'],
    $workspaceKey,
    $membershipOperator,
    'validity_window_reader',
);
Assert::true($current->isRefusal(), 'an ACTIVE membership whose validity window ended is not convergence');
Assert::same('MEMBERSHIP_NOT_CURRENT', $current->reasonCode(), 'the time-invalid membership is refused by name');
Assert::same(
    $clock->now(),
    (string) $db->scalar('SELECT valid_until FROM workspace_memberships WHERE membership_id = ?', [$membershipId]),
    'the expired validity boundary is preserved rather than rewritten',
);
$decision = (new AccessGuard($db, $clock))->evaluate(
    'OPERATOR',
    $membershipOperator,
    $scope['tenant_id'],
    $scope['workspace_id'],
    'battlecard.read',
    'pr7_final_membership_probe',
);
Assert::notSame('ALLOW', $decision['decision'], 'M02 and onboarding agree that the expired membership grants nothing');

// Structural tripwires for the two race-sensitive fixes. The behavioural cases above cover the
// ordinary outcomes; these pin the locking/preflight shape that makes those outcomes survive
// concurrency and dry-run use.
$root = dirname(__DIR__, 2);
$accountSource = (string) file_get_contents($root . '/app/Auth/AccountService.php');
$onboardSource = (string) file_get_contents($root . '/ops/onboard-beta-user.php');
Assert::contains(
    'SELECT password_hash, status FROM platform_users WHERE platform_user_id = ? FOR UPDATE',
    $accountSource,
    'the current credential is re-read under a row lock before holder password replacement',
);
Assert::contains(
    "\$existingAccount['status'] !== 'ACTIVE'",
    $onboardSource,
    'onboarding preflight refuses a non-active existing platform account',
);
Assert::contains(
    'u.platform_user_id = ? AND u.tenant_id = ? FOR UPDATE',
    $onboardSource,
    'the real onboarding unit revalidates and locks an existing account before subscription changes',
);
Assert::contains(
    'SELECT status, valid_from, valid_until FROM workspace_memberships',
    $onboardSource,
    'dry-run checks the same membership validity window the owner gate uses',
);
