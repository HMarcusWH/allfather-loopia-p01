<?php

declare(strict_types=1);

/**
 * P09 first TEST grantor. Private CLI only; never HTTP or an automatic boot path.
 * plan: --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *       --valid-from=... --valid-until=...
 * apply: same plus --plan-digest=... --signature-file=/private/approval.sig
 *
 * The owner must sign "P09_TEST_BOOTSTRAP_V1\n<PLAN_DIGEST>\n" with an
 * independently held Ed25519 private key. The public key is enrolled at the
 * FIXED, non-web, root-controlled storage path BEFORE execution; there is no
 * --public-key argument and no self-registration of a trust anchor.
 *
 * This does not create logins, run setup, admit a schedule or call providers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\TestAuthorityBootstrap;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;

$fail = static function (string $reason, int $code = 2): never {
    fwrite(STDERR, 'p09-bootstrap-test-grantor: refused (' . $reason . ')' . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
if (!in_array($command, ['plan', 'apply'], true)) $fail('command must be plan or apply');
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','valid-from','valid-until','plan-digest','signature-file'],
    $fail
);
$need = static function (string $name) use ($args, $fail): string {
    if (!isset($args[$name]) || $args[$name] === '' || $args[$name] === '1') {
        $fail('--' . $name . ' is required');
    }
    return $args[$name];
};
// No implicit fallback: an explicitly named PRIVATE staging/test config is mandatory.
$config = BetaCli::configuration(dirname(__DIR__), ['env' => $need('env')], $fail);
if (!in_array($config['environment'], ['staging', 'testing'], true)) $fail('TEST environment required');
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id')];
$operator = $need('operator-id');
$from = $need('valid-from');
$until = $need('valid-until');
$signature = '';
$trustedKey = '';

if ($command === 'apply') {
    $digest = $need('plan-digest');
    $root = dirname(__DIR__);
    $pubPath = $root . '/.private-ops/p09_bootstrap_owner_ed25519.pub';
    $sigPath = $need('signature-file');
    // Trust material may never reside on the www-data-writable storage mount.
    // The enrollment directory and key must be root-owned and immutable to
    // the web process; creating/enrolling it is a separate owner operation.
    $trustDir = dirname($pubPath);
    if (is_link($trustDir) || !is_dir($trustDir) ||
        fileowner($trustDir) !== 0 || ((fileperms($trustDir) & 0022) !== 0) ||
        !is_file($pubPath) || fileowner($pubPath) !== 0) {
        $fail('trusted owner key not enrolled in root-controlled directory');
    }
    // Neither the signing key nor the approval signature may be sourced from a
    // web-visible location or through a link into another filesystem.
    foreach ([$pubPath, $sigPath] as $path) {
        if (is_link($path) || !is_file($path) || !is_readable($path) ||
            (realpath($path) !== false &&
             str_starts_with(realpath($path), realpath($root . '/public_html') . DIRECTORY_SEPARATOR)) ||
            ((fileperms($path) & 0022) !== 0)) {
            $fail('missing or unsafe private approval material');
        }
    }
    $trustedKey = trim((string) file_get_contents($pubPath));
    $signature = trim((string) file_get_contents($sigPath));
}

$db = BetaCli::connect($config['env'], $fail);
$bootstrap = new TestAuthorityBootstrap($db, new Clock(), $config['environment']);
$outcome = $command === 'plan'
    ? $bootstrap->plan($scope, $operator, $from, $until)
    : $bootstrap->apply($scope, $operator, $from, $until, $digest, $signature, $trustedKey);
BetaCli::emit(
    $outcome->isOk()
        ? ['ok' => true, 'command' => $command, 'result' => $outcome->value()]
        : ['ok' => false, 'command' => $command, 'reason_code' => $outcome->reasonCode()],
    $outcome->isOk() ? 0 : 1,
);
