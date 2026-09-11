<?php

declare(strict_types=1);

/**
 * Day-to-day support for one beta login, without a line of hand-written SQL.
 *
 * Usage:
 *   php ops/manage-beta-user.php <command> --email=partner@example.com [--tenant-key=acme] [--env=path]
 *
 *   inspect          what state the account is in, and why a partner might be seeing what they see
 *   suspend          stop the account signing in; every live session ends now
 *   reactivate       return the account to full service: status ACTIVE, lock-out cleared,
 *                    outstanding sessions ended so it signs in again
 *
 * TWO LOCKS, AND `reactivate` OWNS ONE. `platform_users.failed_attempts` / `locked_until` is the
 * account lock, and reactivate clears it. `auth_attempts` additionally rate-limits an ADDRESS for
 * fifteen minutes, and that ledger is append-only by trigger — no command here clears it, on
 * purpose. So a partner who still cannot sign in immediately after a reactivation is waiting out a
 * window that expires by itself, not sitting behind a reactivation that failed.
 *   reset-password   issue a replacement password the holder must then change again
 *   revoke-sessions   end every live session, leaving the lifecycle state alone
 *   retire           terminal. There is no transition out of it.
 *
 * `reset-password` reads the new value from stdin, with --password-stdin, and from nowhere else.
 * A password on argv is refused by name: it would be in the process list and the shell history.
 * There is no interactive prompt — PHP has no portable way to suppress terminal echo — so use your
 * shell's own: read -rs PW; printf '%s\n' "$PW" | php ops/manage-beta-user.php reset-password ...
 *
 * WHY THIS EXISTS AT ALL. The alternative is an operator with a SQL client open against the beta
 * database, and every one of these commands is then an UPDATE somebody types from memory at the
 * moment a partner is already unhappy. `suspend` that forgot to revoke sessions is a suspension
 * that did not suspend anything for twelve hours.
 *
 * WHAT EVERY COMMAND EMITS is a sanitized state: identifiers, lifecycle states, counts and
 * timestamps. Never a password, a hash, an algorithm, a session token, a CSRF token, a provider
 * credential, a master key or any record belonging to another tenant.
 *
 * `--tenant-key` IS A SCOPE, NOT A FILTER. Given one, an address outside it is reported exactly as
 * an address that does not exist — otherwise this command would answer "does this person have a
 * login somewhere else in the deployment?", which is not a question support needs to ask.
 */

// CLI ONLY, refused BEFORE anything is loaded. These commands revoke credentials and end sessions;
// they must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Product\P01\Entitlements;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'manage-beta-user: ' . $message . PHP_EOL);
    exit($code);
};

/** The closed set of subcommands. Anything else is a typo, and a typo must not be a no-op. */
$commands = ['inspect', 'suspend', 'reactivate', 'reset-password', 'revoke-sessions', 'retire'];

$command = $argv[1] ?? '';
if ($command === '' || str_starts_with($command, '--')) {
    $fail('a command is required: ' . implode(', ', $commands));
}
if (!in_array($command, $commands, true)) {
    $fail("unknown command '{$command}'; known: " . implode(', ', $commands));
}

// The command itself is argv[1], so the flag parser starts after it. Passing the full argv would
// make the command name look like a bare positional argument and be refused as malformed.
$args = BetaCli::parseArgs(
    array_merge([$argv[0]], array_slice($argv, 2)),
    ['email', 'tenant-key', 'password-stdin', 'reason', 'env'],
    $fail,
);

if (($args['email'] ?? '') === '') {
    $fail('missing required argument --email');
}

$root = dirname(__DIR__);
$configuration = BetaCli::configuration($root, $args, $fail);
$env = $configuration['env'];
$environment = $configuration['environment'];

fwrite(STDERR, sprintf(
    "manage-beta-user: %s (APP_ENV=%s)%s",
    $command,
    $environment,
    PHP_EOL,
));

$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$auth = new Authenticator($db, $clock);
$accounts = new AccountService($db, $clock, $auth);

$tenantId = null;
if (($args['tenant-key'] ?? '') !== '') {
    $tenant = $db->one('SELECT tenant_id FROM tenants WHERE tenant_key = ?', [$args['tenant-key']]);
    if ($tenant === null) {
        $fail("no tenant with key '{$args['tenant-key']}'", 4);
    }
    $tenantId = (string) $tenant['tenant_id'];
}

$account = $accounts->findByEmailAddress($args['email'], $tenantId);
if ($account === null) {
    // The SAME message whether the address does not exist or belongs to another tenant. A support
    // command that distinguished them would answer a question about a tenant the operator did not
    // name.
    $fail('no account with that address in scope', 4);
}
$platformUserId = (string) $account['platform_user_id'];
$reason = $args['reason'] ?? null;

$result = match ($command) {
    'inspect' => null,
    'suspend' => $accounts->suspend($platformUserId, $reason ?? 'ACCOUNT_SUSPENDED_BY_OPERATOR'),
    'reactivate' => $accounts->reactivate($platformUserId),
    'revoke-sessions' => $accounts->revokeSessions($platformUserId, $reason ?? 'SESSIONS_REVOKED_BY_OPERATOR'),
    'retire' => $accounts->retire($platformUserId, $reason ?? 'ACCOUNT_RETIRED_BY_OPERATOR'),
    'reset-password' => $accounts->resetPassword(
        $platformUserId,
        // Read only once the account has been found and the command is certain to act. Prompting
        // first and failing afterwards would have had the operator type a password for nothing.
        BetaCli::readPassword(isset($args['password-stdin']), 'replace this password', $fail),
    ),
};

if ($result !== null && $result->isRefusal()) {
    $fail(sprintf('%s refused: %s', $command, implode(', ', $result->reasonCodes)), 5);
}

// Re-read AFTER the change, so the report is what is true now rather than what was asked for.
$state = $accounts->inspect($platformUserId);
$beta = new BetaBundle($db, new Entitlements($db, $clock));

BetaCli::emit([
    'status' => $command === 'inspect' ? 'INSPECTED' : 'APPLIED',
    'command' => $command,
    'environment' => $environment,
    'account' => $state,
    'memberships' => $accounts->memberships(
        (string) $state['tenant_id'],
        (string) $state['operator_id'],
    ),
    'commercial' => $beta->describeTenant((string) $state['tenant_id']),
    'sessions_revoked' => $result === null ? 0 : ($result->value()['sessions_revoked'] ?? 0),
    'live_read_activated' => false,
    'external_action_authorized' => false,
]);
