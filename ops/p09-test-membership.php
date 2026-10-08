<?php

declare(strict_types=1);

/**
 * P09 governed TEST membership. Private CLI only; NO HTTP, no raw SQL operator bypass.
 *
 * Commands:
 * plan-assign --env=private.env --tenant-id=... --workspace-id=... --operator-id=...
 *     --target-operator-id=... --role-key=... --role-version=1|2
 *     --valid-from=YYYY-MM-DDTHH:MM:SS.sssZ --valid-until=YYYY-MM-DDTHH:MM:SS.sssZ
 * assign: same flags + --plan-digest=sha256 (must have independently reviewed owner decision)
 * plan-revoke --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *     --membership-id=...
 * revoke: same flags + --plan-digest=sha256 (separately reviewed stand-down)
 *
 * This CLI neither creates a tenant/operator/login, nor grants execution or schedules.
 * A digest binds an exact state, not a signature or independent proof of owner consent.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\TestMembershipLifecycle;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'p09-test-membership: request refused (' . $message . ')' . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
if (!in_array($command, ['plan-assign', 'assign', 'plan-revoke', 'revoke'], true)) {
    $fail('command must be plan-assign, assign, plan-revoke or revoke');
}
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','target-operator-id',
     'role-key','role-version','valid-from','valid-until','membership-id','plan-digest'],
    $fail
);
$need = static function (string $name) use ($args, $fail): string {
    if (!isset($args[$name]) || $args[$name] === '' || $args[$name] === '1') {
        $fail('--' . $name . ' is required');
    }
    return (string) $args[$name];
};
$privateEnv = $need('env');
$configuration = BetaCli::configuration(dirname(__DIR__), ['env' => $privateEnv], $fail);
if (!in_array($configuration['env']->get('APP_ENV'), ['staging','testing'], true)) {
    $fail('APP_ENV must be staging/testing');
}

$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $need('operator-id'),
];
if (in_array($command, ['plan-assign','assign'], true)) {
    $version = $need('role-version');
    if (preg_match('/^[1-9][0-9]?$/D', $version) !== 1) {
        $fail('role-version must be a small explicit positive integer');
    }
    $definition = [
        $need('target-operator-id'), $need('role-key'), (int) $version,
        $need('valid-from'), $need('valid-until'),
    ];
} else {
    $membershipId = $need('membership-id');
}
$digest = in_array($command, ['assign','revoke'], true) ? $need('plan-digest') : null;

$db = BetaCli::connect($configuration['env'], $fail);
$owner = new TestMembershipLifecycle($db, new Clock(), $configuration['environment']);
$outcome = match ($command) {
    'plan-assign' => $owner->planAssign($scope, ...$definition),
    'assign' => $owner->assign($scope, ...[...$definition, $digest]),
    'plan-revoke' => $owner->planRevoke($scope, $membershipId),
    'revoke' => $owner->revoke($scope, $membershipId, $digest),
};
BetaCli::emit(
    $outcome->isOk()
        ? ['ok' => true, 'command' => $command, 'result' => $outcome->value()]
        : ['ok' => false, 'command' => $command, 'reason_code' => $outcome->reasonCode()],
    $outcome->isOk() ? 0 : 1,
);
