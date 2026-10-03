<?php

declare(strict_types=1);

/**
 * Private operator CLI for the P07 SYSTEM_SCHEDULER service seat.
 *
 * This provisions only the tenant-bound service identity and its narrow workspace membership.
 * It never admits an execution grant or schedule, never enters a secret, and never activates
 * source/model execution.
 *
 * Usage:
 *   php ops/monitor/scheduler-identity.php plan      --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   php ops/monitor/scheduler-identity.php provision --env=... --tenant-id=... --workspace-id=... --operator-id=... --plan-digest=<sha256>
 *   php ops/monitor/scheduler-identity.php status    --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   php ops/monitor/scheduler-identity.php suspend   --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   php ops/monitor/scheduler-identity.php revoke    --env=... --tenant-id=... --workspace-id=... --operator-id=...
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Workflow\SchedulerIdentityProvisioner;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'scheduler-identity: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
if (!in_array($command, ['plan', 'provision', 'status', 'suspend', 'revoke'], true)) {
    $fail('usage: scheduler-identity.php <plan|provision|status|suspend|revoke> --env=... --tenant-id=... --workspace-id=... --operator-id=... [--plan-digest=<sha256>]');
}

$known = ['env', 'tenant-id', 'workspace-id', 'operator-id', 'plan-digest'];
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], $known, $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$owner = new SchedulerIdentityProvisioner($db, new Clock());
$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $need('operator-id'),
];

$result = match ($command) {
    'plan' => $owner->plan($scope),
    'provision' => $owner->provision($scope, $need('plan-digest')),
    'status' => $owner->status($scope),
    'suspend' => $owner->standDown($scope, 'suspend'),
    'revoke' => $owner->standDown($scope, 'revoke'),
};

BetaCli::emit(
    $result->isOk()
        ? ['ok' => true, 'command' => $command, 'result' => $result->value()]
        : ['ok' => false, 'command' => $command, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? 0 : 1,
);
