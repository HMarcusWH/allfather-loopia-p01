<?php

declare(strict_types=1);

/**
 * Private operator CLI for P11 master-key rewrap.
 *
 * No master key or provider secret is accepted on argv/stdin. Key material comes only from the
 * already-private env file and KeyRing chooses the configured active write version.
 *
 * Usage:
 *   php ops/provider/rotate-master-key.php status --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   php ops/provider/rotate-master-key.php plan   --env=... --tenant-id=... --workspace-id=... --operator-id=... [--limit=25]
 *   php ops/provider/rotate-master-key.php apply  --env=... --tenant-id=... --workspace-id=... --operator-id=... --plan-digest=<sha256> [--limit=25]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Provider\{CredentialRotation,KeyRing};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'provider-key-rotation: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env', 'tenant-id', 'workspace-id', 'operator-id', 'plan-digest', 'limit'],
    $fail,
);
$need = static function (string $name) use ($args, $fail): string {
    $value = $args[$name] ?? '';
    if ($value === '' || $value === '1') {
        $fail("--{$name} is required");
    }

    return $value;
};
$limit = CredentialRotation::MAX_BATCH;
if (($args['limit'] ?? '') !== '') {
    if (preg_match('/^[1-9][0-9]*$/D', (string) $args['limit']) !== 1) {
        $fail('--limit must be a positive integer');
    }
    $limit = (int) $args['limit'];
}

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$env = $config['env'];
try {
    $keys = KeyRing::fromEnv($env);
} catch (\RuntimeException $error) {
    $fail($error->getMessage(), 4);
}
$db = BetaCli::connect($env, $fail);
$service = new CredentialRotation($db, new Clock(), $keys);
$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $need('operator-id'),
];

$result = match ($command) {
    'status' => $service->status($scope),
    'plan' => $service->plan($scope, $limit),
    'apply' => $service->apply($scope, $need('plan-digest'), $limit),
    default => $fail(
        'usage: rotate-master-key.php <status|plan|apply> --env=... --tenant-id=... '
        . '--workspace-id=... --operator-id=... [--limit=25] [--plan-digest=<sha256>]'
    ),
};

BetaCli::emit(
    $result->isOk()
        ? ['ok' => true, 'result' => $result->value()]
        : ['ok' => false, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? 0 : 1,
);
