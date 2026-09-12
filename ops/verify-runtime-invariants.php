<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\RuntimeInvariantProbe;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'verify-runtime-invariants: ' . $message . PHP_EOL);
    exit($code);
};

$args = BetaCli::parseArgs($argv, ['env', 'tenant-key', 'workspace-key'], $fail);
$tenantKey = $args['tenant-key'] ?? '';
$workspaceKey = $args['workspace-key'] ?? '';
if ($tenantKey === '' || $tenantKey === '1') {
    $fail('--tenant-key=<internal TEST tenant key> is required');
}
if ($workspaceKey === '' || $workspaceKey === '1') {
    $fail('--workspace-key=<internal TEST workspace key> is required');
}

$root = dirname(__DIR__);
$configuration = BetaCli::configuration($root, $args, $fail);
if ($configuration['environment'] !== 'staging') {
    $fail('this host qualification command requires APP_ENV=staging');
}

$db = BetaCli::connect($configuration['env'], $fail);
$result = (new RuntimeInvariantProbe($db, new Clock()))->run($tenantKey, $workspaceKey);
BetaCli::emit($result, ($result['status'] ?? '') === 'RUNTIME_INVARIANTS_VERIFIED' ? 0 : 1);
