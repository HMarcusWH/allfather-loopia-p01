<?php
declare(strict_types=1);

/**
 * Private CLI for the tenant's model-provider key. There is no HTTP route to any of this.
 *
 * Usage (every command also takes --env=<private env> --tenant-id --workspace-id --operator-id):
 *   describe                                   credential ids, labels and states; what a save would rotate
 *   save     --secret-stdin [--label=<text>] --acknowledge-rotation=<none|id,id,...>
 *   revoke   --credential-id=<id>
 *
 * THE SECRET IS READ FROM STDIN ONLY — never from argv, a query string, a JSON file or the
 * environment — and is never printed, logged or returned. For example:
 *   read -rs KEY; printf '%s\n' "$KEY" | php ops/provider/credential.php save ... --secret-stdin
 *
 * Saving rotates every other active key of the tenant. `--acknowledge-rotation` must list exactly
 * the credential ids `describe` reports under save_would_rotate (or `none`), or the save refuses.
 * A new key never retargets a frozen mission or grant: those keep naming the old key and refuse
 * until an explicit successor setup and a newly admitted grant name the new one.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Provider\{CredentialStore,KeyRing};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'credential: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], ['env', 'tenant-id', 'workspace-id', 'operator-id', 'secret-stdin', 'label', 'acknowledge-rotation', 'credential-id'], $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '' || ($args[$name] ?? '') === '1') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];
$secret = null;
if ($command === 'save') {
    if (($args['secret-stdin'] ?? '') !== '1') {
        $fail('save reads the key only from stdin: pass --secret-stdin and pipe it in');
    }
    $acknowledged = $need('acknowledge-rotation');
    $line = fgets(STDIN, 1100);
    $secret = $line === false ? '' : rtrim($line, "\r\n");
    if ($secret === '') {
        $fail('--secret-stdin was given but stdin held no key');
    }
}

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$store = new CredentialStore($db, new Clock(), KeyRing::fromEnv($config['env']));

$result = match ($command) {
    'describe' => $store->describe($scope),
    'save' => $store->save($scope, $secret, ($args['label'] ?? '') !== '' ? $args['label'] : 'Monitor OpenAI', $acknowledged === 'none' ? [] : explode(',', $acknowledged)),
    'revoke' => $store->revoke($scope, $need('credential-id')),
    default => $fail('usage: credential.php <describe|save|revoke> --env=... --tenant-id=... --workspace-id=... --operator-id=... [...]'),
};
if ($secret !== null && function_exists('sodium_memzero')) {
    sodium_memzero($secret);
}

// The credential digest is an identity for grants, not for operators; it is not printed either.
$value = $result->isOk() ? $result->value() : null;
if (is_array($value)) {
    unset($value['credential_digest']);
}
BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $value] : ['ok' => false, 'reason_code' => $result->reasonCode(), 'detail' => $result->detail], $result->isOk() ? 0 : 1);
