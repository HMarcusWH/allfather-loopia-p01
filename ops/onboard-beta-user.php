<?php

declare(strict_types=1);

/**
 * Onboard one invited partner onto the closed beta, without a line of hand-written SQL.
 *
 * Usage:
 *   php ops/onboard-beta-user.php --tenant-key=acme --workspace-key=main \
 *       --email=partner@example.com --display-name="A Partner" \
 *       --operator-name="A Partner" [--roles=console_reader,p01_reader] \
 *       --password-stdin [--replace-live-subscription] [--env=path] [--dry-run]
 *
 *   The password is read from stdin and nowhere else. There is no interactive prompt: PHP has no
 *   portable way to suppress terminal echo, and a prompt that might display the password is worse
 *   than none. Use your shell's own no-echo read:
 *       read -rs PW; printf '%s\n' "$PW" | php ops/onboard-beta-user.php ... --password-stdin
 *
 * WHAT IT COMPOSES, in order, each step belonging to the component that owns it:
 *   1. locate an existing tenant and workspace                 (this file; they must already exist)
 *   2. locate or create the governed operator                  (this file; the scope, not a policy)
 *   3. ensure the beta tier exists exactly as declared         (Beta\BetaBundle)
 *   4. subscribe the tenant to it as a TRIAL                   (Beta\BetaBundle -> P01\Entitlements)
 *   5. create or bind the platform login                       (Auth\AccountService)
 *   6. add EXISTING role memberships                           (Auth\AccountService)
 *   7. force an initial password change                        (Auth\AccountService)
 *
 * WHAT IT WILL NOT DO, and each of these is a refusal rather than an omission:
 *
 *   IT PROVISIONS NOTHING. A tenant or workspace that does not exist is an error, not something to
 *   create on the way past. `ops/provision-staging.php` provisions, and it runs the five governed
 *   product registrations while doing so; inventing a tenant here would produce one with no
 *   registered capability and no policy sets, which fails later and further away.
 *
 *   IT MINTS NO ROLE AND NO PERMISSION. `--roles` names roles that must already be ACTIVE. A role
 *   key that does not exist is refused, because the one thing an onboarding path must never do is
 *   create authority on the way to handing somebody a password.
 *
 *   IT PUTS NO PASSWORD ON ARGV. Not as `--password`, which is refused by name with the reason.
 *   The value is read from stdin, is handed straight to the hasher, and appears in no output, no
 *   log and no JSON result.
 *
 *   IT AUTHORIZES NOTHING EXTERNAL. Onboarding a partner does not activate live read, does not
 *   enable an external action, and does not change a claim-boundary flag. The result restates it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Auth\PasswordPolicy;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Product\P01\Entitlements;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'onboard-beta-user: ' . $message . PHP_EOL);
    exit($code);
};

$known = [
    'tenant-key', 'workspace-key', 'email', 'display-name', 'operator-name',
    'roles', 'password-stdin', 'replace-live-subscription', 'env', 'dry-run',
];
$args = BetaCli::parseArgs($argv, $known, $fail);

foreach (['tenant-key', 'workspace-key', 'email', 'display-name'] as $required) {
    if (($args[$required] ?? '') === '') {
        $fail("missing required argument --{$required}");
    }
}

$root = dirname(__DIR__);
$configuration = BetaCli::configuration($root, $args, $fail);
$env = $configuration['env'];
$environment = $configuration['environment'];

$roleKeys = isset($args['roles'])
    ? array_values(array_filter(array_map('trim', explode(',', $args['roles']))))
    : [];

fwrite(STDERR, sprintf(
    "onboard-beta-user: tenant=%s workspace=%s roles=%s (APP_ENV=%s)%s%s",
    $args['tenant-key'],
    $args['workspace-key'],
    $roleKeys === [] ? 'none' : implode(',', $roleKeys),
    $environment,
    isset($args['dry-run']) ? ' DRY RUN' : '',
    PHP_EOL,
));

$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$auth = new Authenticator($db, $clock);
$accounts = new AccountService($db, $clock, $auth);
$beta = new BetaBundle($db, new Entitlements($db, $clock));

$tenant = $db->one(
    'SELECT tenant_id, status FROM tenants WHERE tenant_key = ?',
    [$args['tenant-key']]
);
if ($tenant === null) {
    $fail("no tenant with key '{$args['tenant-key']}'. Provision it first with ops/provision-staging.php.", 4);
}
if ((string) $tenant['status'] !== 'ACTIVE') {
    $fail("tenant '{$args['tenant-key']}' is {$tenant['status']}, not ACTIVE", 4);
}
$tenantId = (string) $tenant['tenant_id'];

$workspace = $db->one(
    'SELECT workspace_id, status FROM workspaces WHERE tenant_id = ? AND workspace_key = ?',
    [$tenantId, $args['workspace-key']]
);
if ($workspace === null) {
    $fail("no workspace '{$args['workspace-key']}' in tenant '{$args['tenant-key']}'", 4);
}
if ((string) $workspace['status'] !== 'ACTIVE') {
    $fail("workspace '{$args['workspace-key']}' is {$workspace['status']}, not ACTIVE", 4);
}

// Resolve the exact ACTIVE role version the service will use, once, before any write. The map is
// also used by the membership preflight so `--dry-run` checks the same role_id as the real grant.
$roleDefinitions = [];
foreach ($roleKeys as $roleKey) {
    $defined = $db->one(
        "SELECT role_id, role_version FROM roles WHERE role_key = ? AND status = 'ACTIVE'
         ORDER BY role_version DESC LIMIT 1",
        [$roleKey]
    );
    if ($defined === null) {
        $fail("role '{$roleKey}' is not an ACTIVE role. This command grants existing roles; it does not create them.", 4);
    }
    $roleDefinitions[$roleKey] = (string) $defined['role_id'];
}

$tierCheck = $beta->inspectTier();
if ($tierCheck->isRefusal()) {
    $fail(sprintf(
        'the %s tier is not usable: %s. %s',
        BetaBundle::TIER_KEY,
        $tierCheck->reasonCode(),
        $tierCheck->reasonCode() === 'TIER_PERMISSION_NOT_DEFINED'
            ? 'Run ops/provision-staging.php first — the product registrations declare the permission vocabulary this tier draws on.'
            : 'Resolve the existing tier definition deliberately; this command will not repair it.',
    ), 5);
}

$emailAddress = AccountService::normalizeEmailAddress($args['email']);
$existingAccount = $accounts->findByEmailAddress($emailAddress);

if ($existingAccount !== null && (string) $existingAccount['tenant_id'] !== $tenantId) {
    $fail('that address already has a login outside this tenant', 5);
}
if (!str_contains($emailAddress, '@') || $emailAddress === '') {
    $fail("'{$args['email']}' is not an address this command can bind a login to", 2);
}
if (trim($args['display-name']) === '') {
    $fail('--display-name cannot be blank', 2);
}
if ($existingAccount !== null && (string) $existingAccount['status'] !== 'ACTIVE') {
    $fail(sprintf(
        'the platform account behind that address is %s, not ACTIVE. Reactivation is a deliberate '
        . 'support act; a RETIRED account is terminal and cannot be onboarded again.',
        (string) $existingAccount['status'],
    ), 5);
}

$liveSubscription = $db->one(
    "SELECT ts.tenant_subscription_id, ts.status, st.tier_key
     FROM tenant_subscriptions ts
     JOIN subscription_tiers st ON st.subscription_tier_id = ts.subscription_tier_id
     WHERE ts.tenant_id = ? AND ts.status IN ('TRIAL', 'ACTIVE')",
    [$tenantId]
);
if ($liveSubscription !== null
    && (string) $liveSubscription['tier_key'] !== BetaBundle::TIER_KEY
    && !isset($args['replace-live-subscription'])
) {
    $fail(sprintf(
        "the tenant holds a live '%s' subscription. Pass --replace-live-subscription to transition "
        . 'it to CANCELLED and put the tenant on %s.',
        (string) $liveSubscription['tier_key'],
        BetaBundle::TIER_KEY,
    ), 5);
}

// Mirror the service's current-membership rule as well as the status rule. M02 treats ACTIVE as
// necessary but not sufficient: valid_from must have arrived and valid_until must still be ahead.
if ($existingAccount !== null) {
    $existingOperatorId = (string) $existingAccount['operator_id'];

    if ((string) $existingAccount['operator_status'] !== 'ACTIVE') {
        $fail(sprintf(
            "the governed operator behind that address is %s, not ACTIVE. A stood-down identity does "
            . 'not accumulate new role memberships; reinstate the operator deliberately first.',
            (string) $existingAccount['operator_status'],
        ), 5);
    }

    $at = $clock->now();
    foreach ($roleKeys as $roleKey) {
        $membership = $db->one(
            "SELECT status, valid_from, valid_until FROM workspace_memberships
             WHERE tenant_id = ? AND workspace_id = ? AND principal_type = 'OPERATOR'
               AND principal_id = ? AND role_id = ?",
            [$tenantId, (string) $workspace['workspace_id'], $existingOperatorId, $roleDefinitions[$roleKey]]
        );
        if ($membership === null) {
            continue;
        }
        if ((string) $membership['status'] !== 'ACTIVE') {
            $fail(sprintf(
                "the '%s' membership already exists and is %s. A withdrawn or expired membership is "
                . 'restored deliberately, not by re-running onboarding.',
                $roleKey,
                (string) $membership['status'],
            ), 5);
        }

        $validFrom = (string) $membership['valid_from'];
        $validUntil = $membership['valid_until'] === null ? null : (string) $membership['valid_until'];
        if ($validFrom > $at || ($validUntil !== null && $validUntil <= $at)) {
            $fail(sprintf(
                "the '%s' membership is ACTIVE but outside its validity window. A withdrawn or expired "
                . 'membership is restored deliberately, not by re-running onboarding.',
                $roleKey,
            ), 5);
        }
    }
}

if (isset($args['dry-run'])) {
    fwrite(STDERR, 'onboard-beta-user: dry run — scope, roles, tier, identity, subscription and '
        . 'membership state validated, nothing written' . PHP_EOL);
    BetaCli::emit([
        'status' => 'DRY_RUN_OK',
        'tenant_id' => $tenantId,
        'workspace_id' => (string) $workspace['workspace_id'],
        'tier_key' => BetaBundle::TIER_KEY,
        'tier_permissions' => BetaBundle::PERMISSIONS,
        'tier_exists' => $tierCheck->value()['subscription_tier_id'] !== null,
        'live_subscription_tier_key' => $liveSubscription === null
            ? null
            : (string) $liveSubscription['tier_key'],
        'would_replace_live_subscription' => $liveSubscription !== null
            && (string) $liveSubscription['tier_key'] !== BetaBundle::TIER_KEY,
        'roles' => $roleKeys,
        'account_exists' => $existingAccount !== null,
        'account_status' => $existingAccount['status'] ?? null,
        'password_required' => $existingAccount === null,
    ]);
}

$password = $existingAccount === null
    ? BetaCli::readPassword(isset($args['password-stdin']), 'open this account', $fail)
    : null;

if ($password !== null) {
    $policy = PasswordPolicy::validate($password);
    if ($policy !== []) {
        $fail('the password was not accepted: ' . implode(', ', $policy), 2);
    }
}

/** @var array<string, mixed> $result */
$result = $db->transaction(static function (Database $tx) use (
    $beta, $accounts, $clock, $args, $tenantId, $emailAddress, $existingAccount,
    $password, $roleKeys
): Outcome {
    // Revalidate an existing identity inside the write unit BEFORE changing the subscription. The
    // preflight is for operator feedback; this lock is the authority check that closes the race
    // between a successful dry/read phase and a concurrent suspend/retire.
    if ($existingAccount !== null) {
        $current = $tx->one(
            'SELECT u.status AS account_status, o.status AS operator_status
             FROM platform_users u
             JOIN operators o ON o.operator_id = u.operator_id
             WHERE u.platform_user_id = ? AND u.tenant_id = ? FOR UPDATE',
            [(string) $existingAccount['platform_user_id'], $tenantId]
        );
        if ($current === null) {
            return Outcome::refuse('ACCOUNT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $current['account_status'] !== 'ACTIVE') {
            return Outcome::refuse('ACCOUNT_NOT_ACTIVE', [
                'status' => (string) $current['account_status'],
            ], 'POLICY');
        }
        if ((string) $current['operator_status'] !== 'ACTIVE') {
            return Outcome::refuse('OPERATOR_NOT_BINDABLE', [
                'status' => (string) $current['operator_status'],
            ], 'POLICY');
        }
    }

    $subscription = $beta->subscribeTenant($tenantId, isset($args['replace-live-subscription']));
    if ($subscription->isRefusal()) {
        return $subscription;
    }

    $operatorId = $existingAccount !== null ? (string) $existingAccount['operator_id'] : null;
    if ($operatorId === null) {
        $operatorId = Ids::new();
        $tx->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => $args['operator-name'] ?? $args['display-name'],
            'status' => 'ACTIVE',
            'created_at' => $clock->now(),
        ]);
    }

    if ($existingAccount === null) {
        $created = $accounts->createLogin(
            $operatorId,
            $emailAddress,
            $args['display-name'],
            (string) $password,
            true,
        );
        if ($created->isRefusal()) {
            return $created;
        }
        $platformUserId = (string) $created->value()['platform_user_id'];
    } else {
        $platformUserId = (string) $existingAccount['platform_user_id'];
    }

    foreach ($roleKeys as $roleKey) {
        $membership = $accounts->addRoleMembership($tenantId, $args['workspace-key'], $operatorId, $roleKey);
        if ($membership->isRefusal()) {
            return $membership;
        }
    }

    return Outcome::ok([
        'operator_id' => $operatorId,
        'platform_user_id' => $platformUserId,
        'tenant_subscription_id' => $subscription->value()['tenant_subscription_id'] ?? null,
        'subscription_status' => $subscription->value()['status'] ?? null,
        'replaced_tenant_subscription_id' => $subscription->value()['replaced_tenant_subscription_id'] ?? null,
    ]);
});

if ($result->isRefusal()) {
    $fail(sprintf(
        'onboarding refused: %s%s. Nothing was written.',
        implode(', ', $result->reasonCodes),
        $result->reasonCode() === 'TENANT_ALREADY_SUBSCRIBED'
            ? '. The tenant holds a different live subscription; pass --replace-live-subscription to transition it'
            : '',
    ), 5);
}

$operatorId = (string) $result->value()['operator_id'];
$platformUserId = (string) $result->value()['platform_user_id'];
$state = $accounts->inspect($platformUserId);

BetaCli::emit([
    'status' => 'ONBOARDED',
    'environment' => $environment,
    'tenant_id' => $tenantId,
    'workspace_id' => (string) $workspace['workspace_id'],
    'operator_id' => $operatorId,
    'platform_user_id' => $platformUserId,
    'email' => $emailAddress,
    'account_status' => $state['status'] ?? null,
    'must_change_password' => $state['must_change_password'] ?? null,
    'account_created' => $existingAccount === null,
    'subscription' => [
        'tier_key' => BetaBundle::TIER_KEY,
        'status' => $result->value()['subscription_status'] ?? null,
        'monthly_fee_minor_units' => BetaBundle::MONTHLY_FEE_MINOR_UNITS,
        'tenant_subscription_id' => $result->value()['tenant_subscription_id'] ?? null,
        'replaced_tenant_subscription_id' => $result->value()['replaced_tenant_subscription_id'] ?? null,
        'permissions' => BetaBundle::PERMISSIONS,
    ],
    'roles_granted' => $roleKeys,
    'live_read_activated' => false,
    'external_action_authorized' => false,
    'customer_visible_output_authorized' => false,
]);
