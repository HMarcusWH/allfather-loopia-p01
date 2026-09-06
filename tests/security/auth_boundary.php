<?php

declare(strict_types=1);

/**
 * Authentication and tenancy boundary, proved against real MariaDB.
 *
 * Cross-tenant and cross-workspace access must fail closed. These tests are the proof, and they
 * check the shape of the refusal as well as the fact of it: a refusal that distinguishes
 * "wrong password" from "no such account", or "exists but forbidden" from "not found", leaks the
 * thing it was meant to protect.
 */

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

$acme = Seed::tenantWorkspace($db, $clock, 'acme');
$rival = Seed::tenantWorkspace($db, $clock, 'rival');

$role = Seed::role($db, $clock, 'ROLE_COMPETITION_ANALYST', ['competitor.read', 'briefing.read']);
Seed::membership($db, $clock, $acme['tenant_id'], $acme['workspace_id'], $acme['operator_id'], $role['role_id']);

$acmeUser = Seed::platformUser($db, $clock, $acme['tenant_id'], $acme['operator_id'], 'analyst@acme.test');
$rivalUser = Seed::platformUser($db, $clock, $rival['tenant_id'], $rival['operator_id'], 'analyst@rival.test');

// --- successful login ------------------------------------------------------------------------------

$login = $auth->authenticate($acmeUser['email'], $acmeUser['password'], '198.51.100.10', 'TestAgent/1.0');
Assert::true($login->isOk(), 'valid credentials authenticate');
$session = $login->value();
Assert::same($acme['operator_id'], $session['operator_id'], 'the session binds to the GOVERNANCE operator identity');

// The token is never stored in the clear: a database dump must not yield a usable session.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM platform_sessions WHERE token_hash = ?', [$session['session_token']]),
    'the raw session token is NOT stored',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM platform_sessions WHERE token_hash = ?', [Authenticator::digest($session['session_token'])]),
    'only the token HASH is stored',
);

// --- indistinguishable failures ------------------------------------------------------------------------

$wrongPassword = $auth->authenticate($acmeUser['email'], 'not the password', '198.51.100.11');
$unknownAccount = $auth->authenticate('nobody@nowhere.test', 'not the password', '198.51.100.12');
Assert::true($wrongPassword->isRefusal(), 'a wrong password is refused');
Assert::true($unknownAccount->isRefusal(), 'an unknown account is refused');
Assert::same(
    $wrongPassword->reasonCode(),
    $unknownAccount->reasonCode(),
    'a wrong password and an unknown account are INDISTINGUISHABLE — no account enumeration',
);
Assert::same('AUTH_INVALID_CREDENTIALS', $wrongPassword->reasonCode(), 'and both carry the canonical reason code');

// --- session resolution -----------------------------------------------------------------------------------

$resolved = $auth->resolveSession($session['session_token']);
Assert::true($resolved->isOk(), 'a live session resolves');
Assert::same($acme['tenant_id'], $resolved->value()['tenant_id'], 'the session carries its tenant');

$bogus = $auth->resolveSession(Ids::token(32));
Assert::true($bogus->isRefusal(), 'a forged session token does not resolve');
Assert::same('SESSION_NOT_FOUND', $bogus->reasonCode(), 'and is refused by name');

// --- THE cross-tenant boundary ---------------------------------------------------------------------------

$crossTenant = $auth->setActiveWorkspace($session['session_id'], $rival['workspace_id']);
Assert::true($crossTenant->isRefusal(), 'a session CANNOT activate another tenant\'s workspace');
Assert::same(
    'WORKSPACE_NOT_FOUND_IN_SCOPE',
    $crossTenant->reasonCode(),
    'and the refusal says NOT FOUND, not FORBIDDEN — a FORBIDDEN would confirm the workspace exists',
);
Assert::same(
    null,
    $db->scalar('SELECT active_workspace_id FROM platform_sessions WHERE session_id = ?', [$session['session_id']]),
    'the refused activation left no state behind',
);

// --- membership is required even inside the right tenant ---------------------------------------------------

$ownWorkspace = $auth->setActiveWorkspace($session['session_id'], $acme['workspace_id']);
Assert::true($ownWorkspace->isOk(), 'a member CAN activate their own workspace');

$secondAcmeWorkspaceId = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $secondAcmeWorkspaceId,
    'tenant_id' => $acme['tenant_id'],
    'workspace_key' => 'ws-second',
    'schema_version' => '1',
    'display_name' => 'Second workspace',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
]);

$noMembership = $auth->setActiveWorkspace($session['session_id'], $secondAcmeWorkspaceId);
Assert::true($noMembership->isRefusal(), 'same tenant is NOT enough — workspace membership is required');
Assert::same('WORKSPACE_MEMBERSHIP_REQUIRED', $noMembership->reasonCode(), 'refused by name');

// --- logout and revocation ----------------------------------------------------------------------------------

$auth->logout($session['session_id']);
$afterLogout = $auth->resolveSession($session['session_token']);
Assert::true($afterLogout->isRefusal(), 'a logged-out session no longer resolves');
Assert::same('SESSION_REVOKED', $afterLogout->reasonCode(), 'refused by name');

// --- suspension kills live sessions ---------------------------------------------------------------------------

$second = $auth->authenticate($acmeUser['email'], $acmeUser['password'], '198.51.100.10');
Assert::true($second->isOk(), 'a fresh login succeeds after logout');
$db->run('UPDATE platform_users SET status = ? WHERE platform_user_id = ?', ['SUSPENDED', $acmeUser['platform_user_id']]);
$afterSuspend = $auth->resolveSession($second->value()['session_token']);
Assert::true($afterSuspend->isRefusal(), 'suspending the account kills its LIVE session, not just future logins');
Assert::same('AUTH_ACCOUNT_NOT_ACTIVE', $afterSuspend->reasonCode(), 'refused by name');

// --- rate limiting -------------------------------------------------------------------------------------------

$rateClock = Harness::clock('2026-05-01T00:00:00.000Z');
$rateAuth = new Authenticator($db, $rateClock);
// A distinct operator: `ux_platform_users_operator` pins one login per governance identity, so
// two logins cannot silently act as the same operator in the audit trail.
$victimOperator = Seed::operator($db, $rateClock, $rival['tenant_id'], 'Victim Operator');
$victim = Seed::platformUser($db, $rateClock, $rival['tenant_id'], $victimOperator, 'victim@rival.test');

$sawRateLimit = false;
for ($i = 0; $i < 14; $i++) {
    $attempt = $rateAuth->authenticate($victim['email'], 'guess-' . $i, '203.0.113.5');
    if ($attempt->isRefusal() && $attempt->reasonCode() === 'AUTH_RATE_LIMITED') {
        $sawRateLimit = true;
        break;
    }
}
Assert::true($sawRateLimit, 'brute force is rate limited before the attempt budget is unbounded');

// Even the CORRECT password is refused while the limit holds — otherwise the limiter is an oracle
// that tells an attacker exactly when they guessed right.
$correctWhileLimited = $rateAuth->authenticate($victim['email'], $victim['password'], '203.0.113.5');
Assert::true($correctWhileLimited->isRefusal(), 'the rate limit applies to a correct password too');

// The attempt ledger is append-only.
try {
    $db->run('DELETE FROM auth_attempts');
    Assert::fail('the auth attempt ledger should reject DELETE');
} catch (\PDOException) {
    Assert::pass();
}
