<?php
declare(strict_types=1);

/**
 * Private CLI for repeatable Monitor mission setup. There is no HTTP route to any of this.
 *
 * Usage (every command also takes --env=<private env> --tenant-id --workspace-id --operator-id):
 *   plan     --request=<setup.json> [--successor-of=<setup_receipt_id>]   read-only dry run
 *   apply    --request=<setup.json> --plan-digest=<hex> [--successor-of=<setup_receipt_id>]
 *   receipt  --setup-key=<key>
 *
 * `plan` makes no setup/provisioning write and prints, for every object the mission needs, REUSE / CREATE / CONFLICT /
 * REFUSE and a plan digest. `apply` performs exactly that reviewed plan through the existing owners
 * and refuses if anything changed since. M02 may still append a denied access-evaluation audit record. Setup needs the operator's existing M02 roles; it never grants a
 * role, subscribes a tenant, approves a source, rotates a credential or admits native execution.
 * A changed request needs --successor-of: the old mission and its configuration are never rewritten.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Workflow\MonitorSetup;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'setup: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], ['env', 'tenant-id', 'workspace-id', 'operator-id', 'request', 'plan-digest', 'successor-of', 'setup-key'], $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '' || ($args[$name] ?? '') === '1') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
$root = dirname(__DIR__, 2);
$request = static function () use ($need, $fail, $root): array {
    $path = realpath($need('request'));
    $public = realpath($root . '/public_html');
    if ($path === false || !is_file($path) || filesize($path) > 65536 || ($public !== false && str_starts_with($path, $public . DIRECTORY_SEPARATOR))) {
        $fail('--request must name a readable private file outside public_html');
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $fail('--request is not valid JSON');
    }

    return is_array($value) ? $value : $fail('--request must be a JSON object');
};

$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$setup = new MonitorSetup($db, new Clock(), new EvidenceStore($root . '/storage/evidence'));
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];
$successor = ($args['successor-of'] ?? '') !== '' ? $args['successor-of'] : null;

$result = match ($command) {
    'plan' => $setup->plan($scope, $request(), $successor),
    'apply' => $setup->apply($scope, $request(), $need('plan-digest'), $successor),
    'receipt' => $setup->receipt($scope, $need('setup-key')),
    default => $fail('usage: setup.php <plan|apply|receipt> --env=... --tenant-id=... --workspace-id=... --operator-id=... [...]'),
};

// Identifiers, digests, step actions and reason codes only — never a credential or profile body.
BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode(), 'detail' => $result->detail], $result->isOk() ? 0 : 1);
