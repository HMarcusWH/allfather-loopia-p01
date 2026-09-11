<?php

declare(strict_types=1);

/**
 * Onboard one invited partner onto the closed beta, without a line of hand-written SQL.
 *
 * Usage:
 *   php ops/onboard-beta-user.php --tenant-key=acme --workspace-key=main \
 *       --email=partner@example.com --display-name="A Partner" \
 *       --operator-name="A Partner" [--roles=console_reader,p01_reader] \
 *       [--password-stdin] [--replace-live-subscription] [--env=path] [--dry-run]
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
 *   The value is read from a silent prompt or from stdin, is handed straight to the hasher, and
 *   appears in no output, no log and no JSON result.
 *
 *   IT AUTHORIZES NOTHING EXTERNAL. Onboarding a partner does not activate live read, does not
 *   enable an external action, and does not change a claim-boundary flag. The result restates it.
 */

// CLI ONLY, refused BEFORE anything is loaded. Onboarding mints a credential; it must never be
// reachable over HTTP, and living outside the document root is a property of a deployment's
// configuration rather than of this file.
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

// --- the scope must already exist ------------------------------------------------------------------

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

// The roles are checked BEFORE anything is written. Discovering an unknown role key after the
// login exists leaves a half-onboarded partner, and the operator has to work out which half.
foreach ($roleKeys as $roleKey) {
    $defined = $db->one(
        "SELECT role_id FROM roles WHERE role_key = ? AND status = 'ACTIVE'",
        [$roleKey]
    );
    if ($defined === null) {
        $fail("role '{$roleKey}' is not an ACTIVE role. This command grants existing roles; it does not create them.", 4);
    }
}

// The tier definition is checked before anything is written too, for the same reason — and through
// the READ-ONLY inspection, not `ensureTier`, because `ensureTier` CREATES the tier when it is
// absent. Using it here would mean a --dry-run had added a row to the platform's global commercial
// catalogue and then reported that nothing was written.
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

// EVERY CHECK THAT CAN BE MADE WITHOUT WRITING IS MADE HERE, before the first write and before the
// operator is asked for a password. Discovering a bad address after the tenant has been subscribed
// leaves a commercial change behind for an onboarding that failed — and with
// --replace-live-subscription it would have cancelled the tenant's previous subscription to get
// there. The transaction below is the second half of the same rule; this half is what stops the
// operator typing a password for a run that was never going to work.
if ($existingAccount !== null && (string) $existingAccount['tenant_id'] !== $tenantId) {
    // The address exists in ANOTHER tenant. Refused without confirming whose — naming it would make
    // this command an enumerator over the deployment's other tenants.
    $fail('that address already has a login outside this tenant', 5);
}
if (!str_contains($emailAddress, '@') || $emailAddress === '') {
    $fail("'{$args['email']}' is not an address this command can bind a login to", 2);
}
if (trim($args['display-name']) === '') {
    $fail('--display-name cannot be blank', 2);
}

if (isset($args['dry-run'])) {
    fwrite(STDERR, 'onboard-beta-user: dry run — scope, roles, tier and identity validated, nothing written' . PHP_EOL);
    BetaCli::emit([
        'status' => 'DRY_RUN_OK',
        'tenant_id' => $tenantId,
        'workspace_id' => (string) $workspace['workspace_id'],
        'tier_key' => BetaBundle::TIER_KEY,
        'tier_permissions' => BetaBundle::PERMISSIONS,
        // Whether the tier would be CREATED by a real run, which is the thing a dry run must be
        // able to say without having done it.
        'tier_exists' => $tierCheck->value()['subscription_tier_id'] !== null,
        'roles' => $roleKeys,
        'account_exists' => $existingAccount !== null,
    ]);
}

// --- the credential ---------------------------------------------------------------------------------

// Read BEFORE any write, so an operator who abandons the prompt leaves nothing behind.
$password = $existingAccount === null
    ? BetaCli::readPassword(
        isset($args['password-stdin']),
        "Initial password for {$emailAddress}: ",
        $fail,
    )
    : null;

if ($password !== null) {
    // The policy the account service would apply, applied here too — so a password that is going to
    // be refused is refused before the tenant's commercial state is touched.
    $policy = PasswordPolicy::validate($password);
    if ($policy !== []) {
        $fail('the password was not accepted: ' . implode(', ', $policy), 2);
    }
}

// --- ONE UNIT ---------------------------------------------------------------------------------------
//
// Subscription, operator, login and role memberships commit together or not at all. Onboarding that
// failed halfway used to leave a beta subscription, an orphan operator and no login — a state an
// operator then has to recognise and unpick by hand, which is the thing this command exists to
// avoid. `Database::transaction` rolls back on a refused Outcome, so every refusal below undoes the
// writes that preceded it.

/** @var array<string, mixed> $result */
$result = $db->transaction(static function (Database $tx) use (
    $beta, $accounts, $clock, $args, $tenantId, $emailAddress, $existingAccount,
    $password, $roleKeys
): Outcome {
    $subscription = $beta->subscribeTenant($tenantId, isset($args['replace-live-subscription']));
    if ($subscription->isRefusal()) {
        return $subscription;
    }

    // An operator is the scope a login acts as, not a policy any module owns — the same reason
    // `ops/provision-staging.php` writes this row directly and nothing else it touches.
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

// --- what happened, sanitized --------------------------------------------------------------------------

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
        // The row's OWN status, not the constant a NEW subscription would have been created with —
        // a tenant already on the beta tier as ACTIVE is correctly subscribed, and reporting TRIAL
        // for it would tell an operator something untrue on the run least likely to be checked.
        'status' => $result->value()['subscription_status'] ?? null,
        'monthly_fee_minor_units' => BetaBundle::MONTHLY_FEE_MINOR_UNITS,
        'tenant_subscription_id' => $result->value()['tenant_subscription_id'] ?? null,
        'replaced_tenant_subscription_id' => $result->value()['replaced_tenant_subscription_id'] ?? null,
        'permissions' => BetaBundle::PERMISSIONS,
    ],
    'roles_granted' => $roleKeys,
    // Restated on every successful run, because a command that hands somebody a working login is
    // exactly where "so we are live now" starts.
    'live_read_activated' => false,
    'external_action_authorized' => false,
    'customer_visible_output_authorized' => false,
]);
