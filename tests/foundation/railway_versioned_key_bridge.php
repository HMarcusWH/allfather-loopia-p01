<?php
declare(strict_types=1);

/** R03 offline-only Railway/P11 keyring bridge regression. Synthetic keys. */
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$renderer = $root . '/ops/railway/render-private-env.php';
$entrypoint = (string) file_get_contents($root . '/ops/railway/entrypoint.sh');
Assert::true(is_file($renderer), 'P11 Railway renderer exists outside served tree');
Assert::contains('KeyRing::fromEnv', (string) file_get_contents($renderer),
    'the real P11 KeyRing validates Railway master-key configuration');
Assert::contains('render-private-env.php "$config_tmp"', $entrypoint,
    'Railway entrypoint delegates to the validated renderer');
Assert::false(str_contains($entrypoint, 'APP_MASTER_KEY:?'),
    'legacy key is no longer mandatory in versioned mode');
Assert::contains('unset SCHEDULER_TOKEN SCHEDULER_TOKEN_HASH', $entrypoint,
    'inert scheduler secrets do not reach the Apache process environment');

$v1 = base64_encode(str_repeat('A', 32));
$v2 = base64_encode(str_repeat('B', 32));
$base = [
    'APP_ENV' => 'staging', 'DB_HOST' => 'db.internal.example',
    'DB_PORT' => '3306', 'DB_NAME' => 'allfather_fixture',
    'DB_USER' => 'fixture', 'DB_PASSWORD' => 'synthetic-fixture-password',
    'RAILWAY_GIT_COMMIT_SHA' => str_repeat('a', 40),
];

/** @return array{exit:int,stderr:string,stdout:string,contents:string,mode:int} */
$run = static function (array $overrides) use ($base, $root, $renderer): array {
    $env = array_replace($base, $overrides);
    $env = array_filter($env, static fn (string $value): bool => $value !== '');
    $tmp = sys_get_temp_dir() . '/allfather-r03-' . bin2hex(random_bytes(8));
    if (!mkdir($tmp, 0700)) {
        throw new RuntimeException('isolated R03 fixture directory unavailable');
    }
    $path = $tmp . '/.env.prepare';
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, $renderer, $path], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $root, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('R03 fixture subprocess refused');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        $bytes = is_file($path) ? (string) file_get_contents($path) : '';
        $mode = is_file($path) ? (((int) fileperms($path)) & 0777) : 0;
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($tmp);
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr,
        'contents' => $bytes, 'mode' => $mode];
};

$legacy = $run(['APP_MASTER_KEY' => $v1]);
Assert::same(0, $legacy['exit'], 'legacy Railway master key remains supported');
Assert::same(0600, $legacy['mode'], 'private configuration created with 0600');
Assert::contains('APP_MASTER_KEY=' . $v1, $legacy['contents'],
    'legacy credential bytes preserved exactly');
Assert::same('', $legacy['stdout'], 'successful renderer prints no credentials');
Assert::same('', $legacy['stderr'], 'successful renderer emits no diagnostic secrets');

$versioned = [
    'APP_MASTER_KEY_VERSIONS' => '1,2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V1' => $v1, 'APP_MASTER_KEY_V2' => $v2,
];
$successor = $run($versioned);
Assert::same(0, $successor['exit'], 'P11 versioned master-key mode works on Railway');
Assert::same(0600, $successor['mode'], 'P11 private config is mode 0600');
Assert::contains("APP_MASTER_KEY_VERSIONS=1,2\n", $successor['contents'], 'declared key versions retained');
Assert::contains("APP_MASTER_KEY_ACTIVE_VERSION=2\n", $successor['contents'], 'active write version retained');
Assert::contains('APP_MASTER_KEY_V1=' . $v1, $successor['contents'], 'v1 recovery key retained');
Assert::contains('APP_MASTER_KEY_V2=' . $v2, $successor['contents'], 'v2 active key retained');
Assert::false(str_contains($successor['contents'], "\nAPP_MASTER_KEY="),
    'versioned config cannot silently use legacy master key');
Assert::same('', $successor['stdout'], 'versioned renderer prints no secrets');
Assert::same('', $successor['stderr'], 'versioned renderer is quiet');

$legacyBlanks = $run(['APP_MASTER_KEY' => $v1, 'APP_MASTER_KEY_VERSIONS' => '',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '']);
Assert::same(0, $legacyBlanks['exit'], 'legacy mode tolerates absent/blank versioned placeholders');
Assert::false(str_contains($legacyBlanks['contents'], 'APP_MASTER_KEY_VERSIONS='),
    'blank placeholders are not serialized as partial P11 mode');

// A CLI operator must not be able to place a secret render target in an HTTP
// document root or in a group-readable directory, even with a valid key.
$runUnsafeTarget = static function (string $directory) use ($root, $renderer, $base, $v1): array {
    $target = $directory . '/.env.prepare';
    $proc = proc_open([PHP_BINARY, $renderer, $target], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root, array_replace($base, ['APP_MASTER_KEY' => $v1]));
    if (!is_resource($proc)) {
        throw new RuntimeException('unsafe target fixture subprocess unavailable');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err, is_file($target)];
};
$unsafeBase = sys_get_temp_dir() . '/allfather-r03-target-' . bin2hex(random_bytes(8));
mkdir($unsafeBase, 0700);
try {
    mkdir($unsafeBase . '/public_html', 0700);
    $served = $runUnsafeTarget($unsafeBase . '/public_html');
    Assert::same(1, $served[0], 'renderer refuses any public_html config target');
    Assert::same(false, $served[3], 'served directory never contains private credentials');
    Assert::same('', $served[1], 'served-target refusal prints no secrets');
    rmdir($unsafeBase . '/public_html');
    mkdir($unsafeBase . '/shared', 0755);
    chmod($unsafeBase . '/shared', 0755);
    $shared = $runUnsafeTarget($unsafeBase . '/shared');
    Assert::same(1, $shared[0], 'renderer refuses group-readable private directory');
    Assert::same(false, $shared[3], 'shared directory never receives credential file');
    rmdir($unsafeBase . '/shared');
} finally {
    if (is_dir($unsafeBase . '/public_html')) {
        rmdir($unsafeBase . '/public_html');
    }
    if (is_dir($unsafeBase . '/shared')) {
        rmdir($unsafeBase . '/shared');
    }
    rmdir($unsafeBase);
}

$refusals = [
    'no master key' => [],
    'mixed legacy/versioned' => $versioned + ['APP_MASTER_KEY' => $v1],
    'active version not declared' => array_replace($versioned, ['APP_MASTER_KEY_ACTIVE_VERSION' => '3']),
    'missing active selection' => array_replace($versioned, ['APP_MASTER_KEY_ACTIVE_VERSION' => '']),
    'missing v1' => array_replace($versioned, ['APP_MASTER_KEY_V1' => '']),
    'extra undeclared v3' => array_replace($versioned, ['APP_MASTER_KEY_V3' => $v1]),
    'malformed version name' => array_replace($versioned, ['APP_MASTER_KEY_VX' => $v1]),
    'invalid short key' => array_replace($versioned, ['APP_MASTER_KEY_V2' => 'short']),
    'newline injection' => ['APP_MASTER_KEY' => $v1, 'DB_PASSWORD' => "bad\nINJECT=1"],
    'leading space in password' => ['APP_MASTER_KEY' => $v1, 'DB_PASSWORD' => ' password'],
    'quoted password ambiguity' => ['APP_MASTER_KEY' => $v1, 'DB_PASSWORD' => '"password"'],
    'not staging' => ['APP_MASTER_KEY' => $v1, 'APP_ENV' => 'production'],
];
// PHP array union preserves left-hand entries; replace for the mixed fixture.
$refusals['mixed legacy/versioned'] = array_replace($versioned, ['APP_MASTER_KEY' => $v1]);
foreach ($refusals as $reason => $input) {
    $result = $run($input);
    Assert::same(1, $result['exit'], "R03 refuses {$reason}");
    Assert::same('', $result['contents'], "R03 refusal {$reason} writes no private file");
    Assert::same('', $result['stdout'], "R03 refusal {$reason} does not leak to stdout");
    Assert::contains('PRIVATE_CONFIG_REFUSED', $result['stderr'], "R03 refusal {$reason} is generic");
    Assert::false(str_contains($result['stderr'], $v1) || str_contains($result['stderr'], $v2)
        || str_contains($result['stderr'], 'synthetic-fixture-password'),
        "R03 refusal {$reason} prints no synthetic secrets");
}
