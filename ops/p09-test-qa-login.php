<?php

declare(strict_types=1);

/**
 * P09 signed TEST DemoViewer v1: CREATE LOGIN ONLY for an already applied
 * membership. The original owner-signed viewer plan is required; it is not a
 * substitute for a separately recorded owner login-creation decision.
 *
 * plan: --env, --tenant-id, --workspace-id, --operator-id, --membership-id,
 *       --email, --viewer-plan-digest
 * create: same plus --login-plan-digest=<SHA256> --signature-file=ABSOLUTE_PRIVATE_PATH
 *         --login-signature-file=ANOTHER_PRIVATE_PATH --password-stdin
 *
 * No beta onboarding, subscription, operator creation, role assignment or
 * account reset. An initial forced password change remains mandatory.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\TestQaViewerLogin;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;

$fail = static function (string $reason, int $code = 2): never {
    fwrite(STDERR, 'p09-test-qa-login: refused (' . $reason . ')' . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
if (!in_array($command, ['plan', 'create'], true)) $fail('command must be plan or create');
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','membership-id','email',
     'viewer-plan-digest','login-plan-digest','signature-file','login-signature-file','password-stdin'],
    $fail
);
$need = static function (string $name) use ($args, $fail): string {
    if (!isset($args[$name]) || $args[$name] === '' || $args[$name] === '1') {
        $fail('--' . $name . ' is required');
    }
    return $args[$name];
};
if ($command === 'plan' && (isset($args['signature-file']) || isset($args['login-signature-file'])
    || isset($args['login-plan-digest']) || isset($args['password-stdin']))) {
    $fail('plan accepts neither signature nor password');
}
if ($command === 'create' && (($args['password-stdin'] ?? '') !== '1')) {
    $fail('create requires --password-stdin');
}
$config = BetaCli::configuration(dirname(__DIR__), ['env' => $need('env')], $fail);
if (!in_array($config['environment'], ['testing','staging'], true)) $fail('TEST environment required');

$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id')];
$operatorId = $need('operator-id');
$membershipId = $need('membership-id');
$email = $need('email');
$viewerDigest = $need('viewer-plan-digest');
$signature = '';
$loginSignature = '';
$loginPlanDigest = '';
$trustedKey = '';

if ($command === 'create') {
    // The same fixed independent trust anchor used by the P09 bootstrap.
    // No caller-provided verifier and no opportunity to enroll one here.
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        $fail('create requires private root-controlled CLI');
    }
    $root = dirname(__DIR__);
    $keyDir = $root . '/.private-ops';
    $pubFile = $keyDir . '/p09_bootstrap_owner_ed25519.pub';
    $sigFile = $need('signature-file');
    $loginSigFile = $need('login-signature-file');
    $loginPlanDigest = $need('login-plan-digest');
    if ($sigFile === $loginSigFile) $fail('viewer and login approvals require separate signature files');
    $publicRoot = realpath($root . '/public_html');
    $storageRoot = realpath($root . '/storage');
    clearstatcache(true);
    if (is_link($keyDir) || !is_dir($keyDir) || fileowner($keyDir) !== 0
        || (fileperms($keyDir) & 0777) !== 0700
        || $publicRoot === false || $storageRoot === false || !str_starts_with($sigFile, '/')
        || !str_starts_with($loginSigFile, '/')) {
        $fail('private trust directory or paths invalid');
    }
    foreach ([$pubFile, $sigFile, $loginSigFile] as $file) {
        $real = realpath($file);
        if (is_link($file) || $real === false || !is_file($real)
            || !is_readable($real) || fileowner($real) !== 0
            || (($file === $pubFile ? fileperms($real) & 0777 : fileperms($real) & 0077)
                !== ($file === $pubFile ? 0400 : 0))
            || $real === $publicRoot || str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)
            || $real === $storageRoot || str_starts_with($real, $storageRoot . DIRECTORY_SEPARATOR)) {
            $fail('unsafe public verifier or detached signature');
        }
    }
    $trustedKey = trim((string) file_get_contents($pubFile));
    $signature = trim((string) file_get_contents($sigFile));
    $loginSignature = trim((string) file_get_contents($loginSigFile));
}

$db = BetaCli::connect($config['env'], $fail);
$login = new TestQaViewerLogin($db, new Clock(), $config['environment']);
if ($command === 'plan') {
    $outcome = $login->plan($scope, $operatorId, $membershipId, $email, $viewerDigest);
} else {
    $password = BetaCli::readPassword(true, 'open this reviewed TEST-only QA login', $fail);
    $outcome = $login->create(
        $scope, $operatorId, $membershipId, $email, $viewerDigest,
        $loginPlanDigest, $signature, $loginSignature, $trustedKey, $password
    );
    unset($password);
}
BetaCli::emit(
    $outcome->isOk()
        ? ['ok' => true, 'command' => $command, 'result' => $outcome->value()]
        : ['ok' => false, 'command' => $command, 'reason_code' => $outcome->reasonCode()],
    $outcome->isOk() ? 0 : 1,
);
