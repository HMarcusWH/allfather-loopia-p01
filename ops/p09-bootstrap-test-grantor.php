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
    // The original Railway PHP image starts as root, then serves requests as
    // www-data. A non-root CLI cannot claim this exceptional authority.
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $fail('bootstrap apply requires root-controlled private CLI');
    }
    $trustDir = dirname($pubPath);
    clearstatcache(true);
    if (is_link($trustDir) || !is_dir($trustDir) ||
        fileowner($trustDir) !== 0 ||
        (fileperms($trustDir) & 0777) !== 0700 ||
        is_link($pubPath) || !is_file($pubPath) ||
        fileowner($pubPath) !== 0 ||
        (fileperms($pubPath) & 0777) !== 0400 ||
        !is_readable($pubPath)) {
        $fail('trusted owner key must be root-owned directory 0700 and file 0400');
    }
    // Both public verification key and detached signature must stay out of
    // the served tree AND out of the www-data-writable persistent volume.
    $publicRoot = realpath($root . '/public_html');
    $writableRoot = realpath($root . '/storage');
    if ($publicRoot === false || $writableRoot === false || !str_starts_with($sigPath, '/')) {
        $fail('private approval paths not canonical');
    }
    foreach ([$pubPath, $sigPath] as $path) {
        $real = realpath($path);
        if (is_link($path) || $real === false || !is_file($real) || !is_readable($real) ||
            $real === $publicRoot ||
            str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR) ||
            $real === $writableRoot ||
            str_starts_with($real, $writableRoot . DIRECTORY_SEPARATOR) ||
            fileowner($real) !== 0 ||
            ((fileperms($real) & 0077) !== 0)) {
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
