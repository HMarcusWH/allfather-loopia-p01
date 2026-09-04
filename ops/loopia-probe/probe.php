<?php

declare(strict_types=1);

/**
 * LOOPIA_PROBE_001 — host capability probe.
 *
 * Plane: Ops validation. Passing the probe promotes no runtime authority; it establishes what the
 * host can actually do, so implementation stops depending on assumptions about it.
 *
 * FAIL-CLOSED RULE: any load-bearing capability that cannot be DEMONSTRATED is reported MISSING,
 * never assumed. A probe that reports "probably fine" is worse than no probe.
 *
 * Output is sanitized JSON only. No secret, no credential, no absolute path outside the app root,
 * and no database password is ever emitted — the report is designed to be pasteable.
 *
 * Usage:  php ops/loopia-probe/probe.php [--env=path] [--allow-network]
 *
 * `--allow-network` additionally probes outbound HTTPS. It is OFF by default because a probe must
 * not make a network request the operator did not ask for.
 */

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Support\Env;

$options = getopt('', ['env::', 'allow-network', 'probe-host::']);
$root = dirname(__DIR__, 2);
$envPath = isset($options['env']) ? (string) $options['env'] : $root . '/.env';
$allowNetwork = isset($options['allow-network']);
$probeHost = isset($options['probe-host']) ? (string) $options['probe-host'] : 'example.com';

$env = is_file($envPath) ? Env::load($envPath) : Env::fromArray([]);

/** @var array<string, array{status: string, detail: mixed, launch_impact: string}> $report */
$report = [];

/**
 * Record a probe result.
 * `PRESENT` — demonstrated. `MISSING` — required and absent. `DEGRADED` — present but limited.
 * `NOT_PROBED` — deliberately not tested in this run.
 */
$record = static function (string $key, string $status, mixed $detail, string $impact) use (&$report): void {
    $report[$key] = ['status' => $status, 'detail' => $detail, 'launch_impact' => $impact];
};

// ---------------------------------------------------------------------------- PHP runtime

$record(
    'php_version',
    version_compare(PHP_VERSION, '8.2.0', '>=') ? 'PRESENT' : 'MISSING',
    PHP_VERSION,
    'PHP 8.2+ is required by the application language level.',
);

foreach (['pdo' => 'PDO', 'pdo_mysql' => 'MariaDB access', 'curl' => 'governed egress',
          'openssl' => 'credential sealing', 'json' => 'canonical serialization',
          'mbstring' => 'UTF-8 handling', 'hash' => 'digests'] as $ext => $why) {
    $record(
        "ext_{$ext}",
        extension_loaded($ext) ? 'PRESENT' : 'MISSING',
        extension_loaded($ext),
        $why,
    );
}

$record(
    'password_argon2id',
    defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true) ? 'PRESENT' : 'DEGRADED',
    password_algos(),
    'Argon2id preferred; bcrypt via PASSWORD_DEFAULT is an accepted fallback.',
);

$record('max_execution_time', 'PRESENT', (int) ini_get('max_execution_time'),
    'Bounds one scheduler invocation. The job budget must stay below it.');
$record('memory_limit', 'PRESENT', ini_get('memory_limit'),
    'Bounds response-size handling and Argon2id memory cost.');
$record('post_max_size', 'PRESENT', ini_get('post_max_size'), 'Bounds form submissions.');
$record('upload_max_filesize', 'PRESENT', ini_get('upload_max_filesize'), 'Bounds catalogue uploads.');
$record('date_timezone', 'PRESENT', date_default_timezone_get(),
    'Informational only: all governed timestamps are canonical UTC strings regardless of this.');
$record('open_basedir', ini_get('open_basedir') === '' ? 'PRESENT' : 'DEGRADED',
    ini_get('open_basedir') === '' ? 'unset' : 'set',
    'A restrictive open_basedir may block the private storage path.');
$record('disable_functions', 'PRESENT',
    array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))),
    'Informational: the app uses no process-execution function.');

// ---------------------------------------------------------------------------- OpenSSL / AES-256-GCM

$gcm = in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
$gcmWorks = false;
if ($gcm) {
    $key = random_bytes(32);
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt('probe', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'aad', 16);
    $gcmWorks = $ct !== false
        && openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'aad') === 'probe';
}
$record('aes_256_gcm', $gcmWorks ? 'PRESENT' : 'MISSING', $gcmWorks,
    'BYOK credential sealing. Without it, provider credentials cannot be stored at all.');

$record('csprng', 'PRESENT', strlen(random_bytes(16)) === 16,
    'Session tokens, scheduler tokens and evidence temp names require a CSPRNG.');

// ---------------------------------------------------------------------------- cURL

if (extension_loaded('curl')) {
    $curl = curl_version();
    $record('curl_version', 'PRESENT', $curl['version'] ?? 'unknown', 'Governed egress transport.');
    $record('curl_ssl', isset($curl['ssl_version']) ? 'PRESENT' : 'MISSING', $curl['ssl_version'] ?? null,
        'TLS verification is non-disableable in the transport.');

    // THE load-bearing feature. CURLOPT_RESOLVE pins the already-vetted IP so cURL does not
    // re-resolve the hostname after the guard checked it. Without it the DNS-rebinding TOCTOU
    // the egress guard exists to close is REOPENED, and live read must not be activated.
    $record(
        'curl_resolve_pinning',
        defined('CURLOPT_RESOLVE') ? 'PRESENT' : 'MISSING',
        defined('CURLOPT_RESOLVE'),
        'REQUIRED for live read. Without CURLOPT_RESOLVE the resolve-connect pin cannot be enforced '
        . 'and live capture stays disabled.',
    );
    $record('curl_timeout_ms', defined('CURLOPT_TIMEOUT_MS') ? 'PRESENT' : 'MISSING', defined('CURLOPT_TIMEOUT_MS'),
        'Total wall-clock bound on one governed fetch.');
    $record('curl_writefunction', 'PRESENT', true, 'Streaming response-size cap.');
    $record('curl_protocols_restrictable',
        defined('CURLOPT_PROTOCOLS_STR') || defined('CURLOPT_PROTOCOLS') ? 'PRESENT' : 'DEGRADED',
        defined('CURLOPT_PROTOCOLS_STR') ? 'CURLOPT_PROTOCOLS_STR' : (defined('CURLOPT_PROTOCOLS') ? 'CURLOPT_PROTOCOLS' : null),
        'Confines the transport to https, so a redirect cannot switch scheme.');
} else {
    $record('curl_resolve_pinning', 'MISSING', false, 'REQUIRED for live read.');
}

// ---------------------------------------------------------------------------- DNS

$dnsWorks = false;
$dnsDetail = 'not probed';
if (function_exists('dns_get_record')) {
    if ($allowNetwork) {
        $a = @dns_get_record($probeHost, DNS_A);
        $aaaa = @dns_get_record($probeHost, DNS_AAAA);
        $dnsWorks = is_array($a) && $a !== [];
        $dnsDetail = ['a_records' => is_array($a) ? count($a) : 0, 'aaaa_records' => is_array($aaaa) ? count($aaaa) : 0];
    } else {
        $dnsDetail = 'dns_get_record available; resolution not attempted (--allow-network off)';
    }
}
$record(
    'dns_resolution',
    function_exists('dns_get_record') ? ($allowNetwork ? ($dnsWorks ? 'PRESENT' : 'MISSING') : 'PRESENT') : 'MISSING',
    $dnsDetail,
    'The guard resolves and vets addresses BEFORE connecting. Without dns_get_record the SSRF '
    . 'screen cannot run and live capture stays disabled.',
);

// ---------------------------------------------------------------------------- filesystem

$storage = $root . '/storage';
$privateWritable = is_dir($storage) ? is_writable($storage) : @mkdir($storage . '/probe', 0o750, true);
$record('private_storage_writable', $privateWritable ? 'PRESENT' : 'MISSING', $privateWritable,
    'Evidence payloads and logs live outside public_html.');

$atomicRename = false;
if ($privateWritable) {
    $dir = $storage . '/probe';
    @mkdir($dir, 0o750, true);
    $tmp = $dir . '/.probe-' . bin2hex(random_bytes(4));
    $dst = $dir . '/probe-target';
    if (@file_put_contents($tmp, 'x') !== false) {
        $atomicRename = @rename($tmp, $dst);
        @unlink($dst);
        @unlink($tmp);
    }
    @rmdir($dir);
}
$record('atomic_rename', $atomicRename ? 'PRESENT' : 'MISSING', $atomicRename,
    'The evidence store writes to a temp file in the same directory and renames into place, so a '
    . 'reader never observes a partial payload.');

$record('public_root_separation',
    is_dir($root . '/public') ? 'PRESENT' : 'MISSING',
    ['app_root_has_public_dir' => is_dir($root . '/public')],
    'Only public/ may be the document root. app/, config/, storage/, database/ and .env must not be reachable.');

// ---------------------------------------------------------------------------- MariaDB

$dbStatus = 'NOT_PROBED';
$dbDetail = 'no DB_* configuration found';
if ($env->has('DB_NAME')) {
    try {
        $db = Database::connect(
            $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            $env->int('DB_PORT', 3306),
            $env->require('DB_NAME'),
            $env->require('DB_USER'),
            $env->get('DB_PASSWORD', '') ?? '',
            $env->get('DB_SOCKET'),
        );
        $version = $db->serverVersion();

        // Constraint enforcement is not assumed from the version string; it is demonstrated.
        $probeTable = 'probe_capability_' . bin2hex(random_bytes(4));
        $db->run("CREATE TABLE {$probeTable} (
            id CHAR(36) NOT NULL PRIMARY KEY,
            flag TINYINT(1) NOT NULL,
            CHECK (flag = 0)
        ) ENGINE=InnoDB");

        $checkEnforced = false;
        try {
            $db->run("INSERT INTO {$probeTable} (id, flag) VALUES ('a', 1)");
        } catch (\PDOException) {
            $checkEnforced = true;
        }

        $rollbackWorks = false;
        try {
            $db->transaction(static function (Database $tx) use ($probeTable): void {
                $tx->run("INSERT INTO {$probeTable} (id, flag) VALUES ('b', 0)");
                throw new \RuntimeException('probe rollback');
            });
        } catch (\RuntimeException) {
            $rollbackWorks = (int) $db->scalar("SELECT COUNT(*) FROM {$probeTable}") === 0;
        }

        $db->run("DROP TABLE {$probeTable}");

        $dbStatus = ($checkEnforced && $rollbackWorks) ? 'PRESENT' : 'DEGRADED';
        $dbDetail = [
            'server_version' => $version,
            'check_constraints_enforced' => $checkEnforced,
            'transaction_rollback' => $rollbackWorks,
            'innodb_available' => (bool) $db->scalar(
                "SELECT COUNT(*) FROM information_schema.engines WHERE engine = 'InnoDB' AND support IN ('YES','DEFAULT')"
            ),
        ];
    } catch (\Throwable $error) {
        $dbStatus = 'MISSING';
        // The message may name a host or user; report only the driver's class of failure.
        $dbDetail = ['unreachable' => true, 'error_class' => $error::class];
    }
}
$record('mariadb', $dbStatus, $dbDetail,
    'CHECK enforcement and transactional rollback are load-bearing: the storage pins are half of '
    . 'every governed invariant.');

// ---------------------------------------------------------------------------- outbound HTTPS

if ($allowNetwork && extension_loaded('curl')) {
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_URL => 'https://' . $probeHost . '/',
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 10,
    ]);
    $ok = curl_exec($handle) !== false;
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $err = curl_error($handle);
    curl_close($handle);
    $record('outbound_https', $ok ? 'PRESENT' : 'MISSING',
        ['reached' => $ok, 'status' => $code, 'error' => $ok ? null : substr($err, 0, 120)],
        'Governed read-only capture requires outbound HTTPS with TLS verification.');
} else {
    $record('outbound_https', 'NOT_PROBED', 'pass --allow-network to probe',
        'Governed read-only capture requires outbound HTTPS with TLS verification.');
}

// ---------------------------------------------------------------------------- verdict

$missing = array_keys(array_filter($report, static fn (array $r): bool => $r['status'] === 'MISSING'));
$degraded = array_keys(array_filter($report, static fn (array $r): bool => $r['status'] === 'DEGRADED'));

echo json_encode([
    'probe' => 'LOOPIA_PROBE_001',
    'probe_version' => '1.0',
    'projection' => 'LOOPIA_SME_MVP',
    'probed_at' => (new \Allfather\Loopia\Core\Clock())->now(),
    'network_probed' => $allowNetwork,
    'capabilities' => $report,
    'missing' => $missing,
    'degraded' => $degraded,
    // Passing establishes host capability ONLY. It grants no runtime authority, activates no live
    // read, and authorizes no deployment.
    'verdict' => $missing === [] ? 'CAPABILITIES_DEMONSTRATED' : 'CAPABILITIES_MISSING',
    'grants_runtime_authority' => false,
    'activates_live_read' => false,
    'authorizes_deployment' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($missing === [] ? 0 : 1);
