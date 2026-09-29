<?php

declare(strict_types=1);

/**
 * LOOPIA_PROBE_001 — host capability probe.
 *
 * Plane: Ops validation. Passing the probe promotes no runtime authority; it establishes what the
 * host can actually do, so implementation stops depending on assumptions about it.
 *
 * FAIL-CLOSED RULE: any load-bearing capability that cannot be DEMONSTRATED is reported MISSING or
 * NOT_PROBED, never assumed. A probe that reports "probably fine" is worse than no probe.
 *
 * (Usage and flags are documented below, beside the parser. This header deliberately spells no flag
 * name: `deployment_boundary.php` proves that every ops entry point refuses a web SAPI before it
 * loads anything, and it proves it by position — so a word in this docblock that also appears in the
 * autoload line would make the file look as though it loaded first.)
 *
 * THREE VERDICTS, NOT ONE. Version 1 emitted a single `CAPABILITIES_DEMONSTRATED` that could be
 * reached while outbound HTTPS was `NOT_PROBED` — so the strongest sentence in the report was true
 * of a run that had deliberately not tested the thing live read depends on. The subjects are now
 * separate because they are answered by different evidence and gate different decisions:
 *
 *   `staging_host_verdict`      can this host run the application inertly?
 *   `live_read_host_verdict`    could this host perform a governed external read, IF one were ever
 *                               authorized? (It is not. See below.)
 *   `scheduler_transport_verdict`  can a recurring tick actually reach this deployment?
 *
 * NOTHING IS INFERRED FROM A VERSION STRING. MariaDB advertising 10.11 does not tell you that this
 * account may create a trigger, that foreign keys are enforced on this instance, or that row locking
 * behaves. Each is demonstrated against disposable, randomly-named probe tables and cleaned up
 * afterwards, including on failure.
 *
 * THE PROBE DOES NOT ACTIVATE ANYTHING. Network probing is opt-in, and when it is on the probe
 * makes ONE request to a host of the operator's choosing to demonstrate that outbound HTTPS works
 * at all. That is a capability measurement, not a governed source read: it goes through no source
 * card, no egress guard, no evidence spine, and it writes nothing. Demonstrating that live read
 * COULD work is exactly as far from activating it as reading the specification of a lock is from
 * opening the door.
 *
 * Output is sanitized JSON only. No secret, no credential, no absolute path outside the app root,
 * and no database password is ever emitted — the report is designed to be pasteable.
 */

// CLI ONLY, and refused BEFORE anything is loaded. `ops/` sits outside the served tree and the
// deployment-boundary test proves it, so this guard is the second layer: a probe that could be
// reached over the web would be an unauthenticated way to make the server open a connection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Support\Env;

/**
 * Usage:
 *   php ops/loopia-probe/probe.php [--env=path]
 *   php ops/loopia-probe/probe.php --env=.env --allow-network --probe-host=example.com
 *   php ops/loopia-probe/probe.php --env=.env --allow-network --require-live-read --probe-host=example.com
 *
 * `--allow-network` additionally probes DNS and outbound HTTPS. It is OFF by default because a probe
 * must not make a network request the operator did not ask for; with it off, live-read readiness is
 * NOT_QUALIFIED rather than assumed either way.
 *
 * `--require-live-read` makes a live-read verdict below READY a non-zero exit. Without it the exit
 * code follows the staging verdict alone, which is what CI and an inert staging qualification want.
 *
 * EXIT: 0 when the staging host is READY (and, under `--require-live-read`, the live-read host too).
 */
$options = getopt('', ['env::', 'allow-network', 'require-live-read', 'probe-host::']);
$root = dirname(__DIR__, 2);
$envPath = isset($options['env']) ? (string) $options['env'] : $root . '/.env';
$allowNetwork = isset($options['allow-network']);
$requireLiveRead = isset($options['require-live-read']);
$probeHost = isset($options['probe-host']) ? (string) $options['probe-host'] : 'example.com';

// A LIVE-READ REQUIREMENT WITHOUT NETWORK PERMISSION IS A CONTRADICTION, and the useful thing to do
// with a contradiction is refuse it. Answering NOT_QUALIFIED would look like a host finding rather
// than an invocation mistake, and the operator would go looking at the host.
if ($requireLiveRead && !$allowNetwork) {
    fwrite(STDERR, "probe: --require-live-read needs --allow-network; live-read readiness cannot be "
        . "demonstrated without making a request\n");
    exit(2);
}
if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/D', $probeHost) !== 1) {
    fwrite(STDERR, "probe: --probe-host must be a lowercase host name\n");
    exit(2);
}

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

// OPEN_BASEDIR IS METADATA, NOT A VERDICT. Version 1 reported a configured open_basedir as DEGRADED,
// which reads as a finding — and on shared hosting it is the normal configuration. The load-bearing
// question is whether the exact private-storage operations this application performs actually work,
// and those are demonstrated below on their own. A restriction that does not block them is not a
// problem, and one that does will surface as a MISSING storage capability rather than as a guess.
//
// AND THE VALUE IS NOT REPORTED. On the shared hosting this probe is written for, `open_basedir`
// contains absolute account, domain and home-directory paths — and this report is designed to be
// pasted into a ticket and filed as an evidence artifact. An earlier revision of this PR widened
// the old `set`/`unset` summary to the raw setting, which put those paths in every artifact and
// broke the command's own guarantee that it discloses no absolute host path.
//
// The count is kept because it is the one thing a reader might act on — several entries usually
// means the private storage path was added to an existing restriction — and a count names nothing.
$basedir = (string) ini_get('open_basedir');
$record('open_basedir', 'PRESENT',
    $basedir === ''
        ? 'unset'
        : ['configured' => true, 'entries' => count(array_filter(explode(PATH_SEPARATOR, $basedir), static fn (string $e): bool => trim($e) !== ''))],
    'Informational. What matters is whether the private-storage operations below succeed.');
$record('disable_functions', 'PRESENT',
    array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))),
    'Informational: the app uses no process-execution function.');

// ---------------------------------------------------------------------------- OpenSSL / AES-256-GCM

// GUARDED, BECAUSE THE PROBE MUST SURVIVE THE HOST IT IS DIAGNOSING. `openssl_get_cipher_methods()`
// does not exist when ext/openssl is absent, and calling it there is a fatal Error — so the probe
// recorded `ext_openssl: MISSING` two dozen lines above and then died on the very next capability,
// before emitting a single line of the sanitized JSON. The one host that most needs this report is
// the host that cannot produce it.
//
// `function_exists` as well as `extension_loaded`, because `disable_functions` can remove an
// individual function from a loaded extension — a shared-hosting configuration this probe reports
// on a few lines above, and one that would fail in exactly the same way.
$opensslUsable = extension_loaded('openssl')
    && function_exists('openssl_get_cipher_methods')
    && function_exists('openssl_encrypt')
    && function_exists('openssl_decrypt');
$gcm = $opensslUsable && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
$gcmWorks = false;
if ($gcm) {
    $key = random_bytes(32);
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt('probe', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'aad', 16);
    $gcmWorks = $ct !== false
        && openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'aad') === 'probe';
}
$record(
    'aes_256_gcm',
    $gcmWorks ? 'PRESENT' : 'MISSING',
    // WHY it is missing, because the two reasons need different remedies: an absent extension is a
    // host request, and a present extension without the cipher is a build question.
    $opensslUsable ? $gcmWorks : 'openssl is unavailable on this host',
    'BYOK credential sealing. Without it, provider credentials cannot be stored at all.',
);

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
    $record('curl_writefunction', defined('CURLOPT_WRITEFUNCTION') ? 'PRESENT' : 'MISSING',
        defined('CURLOPT_WRITEFUNCTION'),
        'Streaming response-size cap: the transport aborts mid-body rather than buffering a reply '
        . 'of any size a remote host chooses to send.');
    // MISSING RATHER THAN DEGRADED when neither constant exists. A transport that cannot be confined
    // to https is a transport a redirect can move to another scheme, and "present but limited" is
    // the wrong description of a control that is simply absent.
    $record('curl_protocols_restrictable',
        defined('CURLOPT_PROTOCOLS_STR') || defined('CURLOPT_PROTOCOLS') ? 'PRESENT' : 'MISSING',
        defined('CURLOPT_PROTOCOLS_STR') ? 'CURLOPT_PROTOCOLS_STR' : (defined('CURLOPT_PROTOCOLS') ? 'CURLOPT_PROTOCOLS' : null),
        'Confines the transport to https, so a redirect cannot switch scheme.');
} else {
    foreach ([
        'curl_version', 'curl_ssl', 'curl_resolve_pinning', 'curl_timeout_ms',
        'curl_writefunction', 'curl_protocols_restrictable',
    ] as $absent) {
        $record($absent, 'MISSING', false, 'REQUIRED for live read; the curl extension is absent.');
    }
}

// ---------------------------------------------------------------------------- DNS

$record(
    'dns_function',
    function_exists('dns_get_record') ? 'PRESENT' : 'MISSING',
    function_exists('dns_get_record'),
    'The guard resolves and vets addresses BEFORE connecting. Without dns_get_record the SSRF '
    . 'screen cannot run and live capture stays disabled.',
);

// RESOLUTION IS A SEPARATE FACT FROM THE FUNCTION EXISTING. Version 1 reported `dns_resolution`
// PRESENT when the function existed and network probing was off — so "DNS works here" was recorded
// on a run that never resolved anything. A capability nobody demonstrated is NOT_PROBED.
if (!function_exists('dns_get_record')) {
    $record('dns_resolution', 'MISSING', false, 'dns_get_record is unavailable on this host.');
} elseif (!$allowNetwork) {
    $record('dns_resolution', 'NOT_PROBED', 'pass --allow-network to resolve',
        'Actual resolution is required before live read; not attempted in this run.');
} else {
    $a = @dns_get_record($probeHost, DNS_A);
    $aaaa = @dns_get_record($probeHost, DNS_AAAA);
    // EITHER FAMILY COUNTS. The governed resolver screens A *and* AAAA, so an IPv6-only host is one
    // this deployment can resolve and reach. Requiring an A record would report MISSING_CAPABILITY
    // for a host that resolves fine and whose HTTPS probe then succeeds — a verdict contradicted by
    // the very next capability in the same report.
    $resolved = (is_array($a) && $a !== []) || (is_array($aaaa) && $aaaa !== []);
    $record(
        'dns_resolution',
        $resolved ? 'PRESENT' : 'MISSING',
        ['a_records' => is_array($a) ? count($a) : 0, 'aaaa_records' => is_array($aaaa) ? count($aaaa) : 0],
        'The guard resolves and vets addresses BEFORE connecting; it screens A and AAAA alike.',
    );
}

// ---------------------------------------------------------------------------- filesystem

$storage = $root . '/storage';
$privateWritable = is_dir($storage) ? is_writable($storage) : @mkdir($storage, 0o750, true);
$record('private_storage_writable', $privateWritable ? 'PRESENT' : 'MISSING', $privateWritable,
    'Evidence payloads and logs live outside public_html.');

$atomicRename = false;
$readBack = false;
if ($privateWritable) {
    $dir = $storage . '/probe-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0o750, true);
    $tmp = $dir . '/.probe-' . bin2hex(random_bytes(4));
    $dst = $dir . '/probe-target';
    if (@file_put_contents($tmp, 'probe-payload') !== false) {
        $atomicRename = @rename($tmp, $dst);
        // THE READ-BACK IS PART OF THE PROOF. A rename that returned true onto a filesystem that
        // then served stale or empty content would pass a rename check and fail the thing the
        // rename exists for: a reader never observing a partial payload.
        $readBack = $atomicRename && @file_get_contents($dst) === 'probe-payload';
    }
    @unlink($dst);
    @unlink($tmp);
    @rmdir($dir);
}
$record('atomic_rename', $atomicRename && $readBack ? 'PRESENT' : 'MISSING',
    ['renamed' => $atomicRename, 'read_back_intact' => $readBack],
    'The evidence store writes to a temp file in the same directory and renames into place, so a '
    . 'reader never observes a partial payload.');

// The layout claim, checked as a real path relationship rather than as a directory existing. A
// `storage/` INSIDE the served tree would satisfy "the directory exists" and would be served.
$publicReal = realpath($root . '/public_html');
$storageReal = realpath($storage);
$separated = is_string($publicReal) && is_string($storageReal)
    && !str_starts_with($storageReal . DIRECTORY_SEPARATOR, $publicReal . DIRECTORY_SEPARATOR);
$record('public_root_separation',
    is_dir($root . '/public_html') && $separated ? 'PRESENT' : 'MISSING',
    [
        'app_root_has_public_dir' => is_dir($root . '/public_html'),
        'private_storage_outside_public_root' => $separated,
    ],
    'Only public_html/ may be the document root. app/, storage/, database/, ops/ and .env must not '
    . 'be reachable over the web.');

// ---------------------------------------------------------------------------- MariaDB

/**
 * Prove one storage capability against disposable, randomly-named tables.
 *
 * Every proof cleans up in `finally`, including when the proof itself threw — a probe that left
 * `probe_fk_child_ab12cd34` behind on a shared host would be litter an operator has to recognise
 * and remove by hand, at the exact moment they are already reading a failure report.
 */
$dbStatus = 'NOT_PROBED';
$dbDetail = 'no DB_* configuration found';

// CLEANUP FAILURE IS A CAPABILITY FINDING, not a swallowed exception. An account that can CREATE the
// disposable tables but cannot DROP them passes every proof below and leaves `probe_lock_*` and a
// trigger behind in the database — on a shared host, litter an operator has to recognise and remove
// by hand. Worse, the probe would report the host READY while having demonstrably failed its own
// disposable-operation guarantee.
/** @var list<string> $cleanupFailures */
$cleanupFailures = [];
$dbFacts = [
    'mariadb_version_floor' => ['NOT_PROBED', null],
    'innodb_available' => ['NOT_PROBED', null],
    'check_constraints_enforced' => ['NOT_PROBED', null],
    'transaction_rollback' => ['NOT_PROBED', null],
    'foreign_keys_enforced' => ['NOT_PROBED', null],
    'row_locking' => ['NOT_PROBED', null],
    'named_advisory_locking' => ['NOT_PROBED', null],
    'trigger_support' => ['NOT_PROBED', null],
];

if ($env->has('DB_NAME')) {
    $connect = static fn (): Database => Database::connect(
        $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
        $env->int('DB_PORT', 3306),
        $env->require('DB_NAME'),
        $env->require('DB_USER'),
        $env->get('DB_PASSWORD', '') ?? '',
        $env->get('DB_SOCKET'),
    );

    try {
        $db = $connect();
        $version = $db->serverVersion();
        $suffix = bin2hex(random_bytes(4));

        $dbFacts['mariadb_version_floor'] = [
            version_compare($version, '10.6', '>=') ? 'PRESENT' : 'MISSING',
            $version,
        ];
        $dbFacts['innodb_available'] = [
            (bool) $db->scalar(
                "SELECT COUNT(*) FROM information_schema.engines WHERE engine = 'InnoDB' AND support IN ('YES','DEFAULT')"
            ) ? 'PRESENT' : 'MISSING',
            null,
        ];

        // ---- CHECK enforcement and transactional rollback
        $checkTable = 'probe_check_' . $suffix;
        try {
            $db->run('CREATE TABLE ' . Database::identifier($checkTable) . ' (
                id CHAR(36) NOT NULL PRIMARY KEY,
                flag TINYINT(1) NOT NULL,
                CHECK (flag = 0)
            ) ENGINE=InnoDB');

            $checkEnforced = false;
            try {
                $db->run('INSERT INTO ' . Database::identifier($checkTable) . " (id, flag) VALUES ('a', 1)");
            } catch (\PDOException) {
                $checkEnforced = true;
            }
            $dbFacts['check_constraints_enforced'] = [$checkEnforced ? 'PRESENT' : 'MISSING', $checkEnforced];

            $rollbackWorks = false;
            try {
                $db->transaction(static function (Database $tx) use ($checkTable): void {
                    $tx->run('INSERT INTO ' . Database::identifier($checkTable) . " (id, flag) VALUES ('b', 0)");
                    throw new \RuntimeException('probe rollback');
                });
            } catch (\RuntimeException) {
                $rollbackWorks = (int) $db->scalar('SELECT COUNT(*) FROM ' . Database::identifier($checkTable)) === 0;
            }
            $dbFacts['transaction_rollback'] = [$rollbackWorks ? 'PRESENT' : 'MISSING', $rollbackWorks];
        } finally {
            try {
                $db->run('DROP TABLE IF EXISTS ' . Database::identifier($checkTable));
            } catch (\PDOException) {
                $cleanupFailures[] = $checkTable;
            }
        }

        // ---- FOREIGN KEY enforcement, demonstrated rather than inferred from the engine name
        $parent = 'probe_fk_parent_' . $suffix;
        $child = 'probe_fk_child_' . $suffix;
        try {
            $db->run('CREATE TABLE ' . Database::identifier($parent) . ' (
                id CHAR(36) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB');
            $db->run('CREATE TABLE ' . Database::identifier($child) . ' (
                id CHAR(36) NOT NULL PRIMARY KEY,
                parent_id CHAR(36) NOT NULL,
                CONSTRAINT fk_probe_' . $suffix . ' FOREIGN KEY (parent_id)
                    REFERENCES ' . Database::identifier($parent) . ' (id)
            ) ENGINE=InnoDB');

            $fkEnforced = false;
            try {
                $db->run(
                    'INSERT INTO ' . Database::identifier($child) . ' (id, parent_id) VALUES (?, ?)',
                    ['child-1', 'no-such-parent'],
                );
            } catch (\PDOException) {
                $fkEnforced = true;
            }
            $dbFacts['foreign_keys_enforced'] = [$fkEnforced ? 'PRESENT' : 'MISSING', $fkEnforced];
        } finally {
            foreach ([$child, $parent] as $table) {
                try {
                    $db->run('DROP TABLE IF EXISTS ' . Database::identifier($table));
                } catch (\PDOException) {
                    $cleanupFailures[] = $table;
                }
            }
        }

        // ---- ROW LOCKING, across TWO INDEPENDENT CONNECTIONS
        //
        // A race proved on one handle races nothing: the statements are serialized by the wire and
        // the second reads whatever the first committed. Two connections, each with its own
        // transaction and its own snapshot, is the only arrangement that demonstrates anything.
        //
        // BOUNDED TIGHTLY. Connection B sets a one-second lock wait before it blocks, so the worst
        // case for this probe is a second of waiting rather than a process an operator has to kill.
        $lockTable = 'probe_lock_' . $suffix;
        $holder = null;
        $contender = null;
        try {
            $db->run('CREATE TABLE ' . Database::identifier($lockTable) . ' (
                id CHAR(36) NOT NULL PRIMARY KEY,
                counter INT NOT NULL
            ) ENGINE=InnoDB');
            $db->run('INSERT INTO ' . Database::identifier($lockTable) . " (id, counter) VALUES ('row', 0)");

            $holder = $connect();
            $contender = $connect();
            $contender->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

            $holder->pdo()->beginTransaction();
            $holder->one('SELECT counter FROM ' . Database::identifier($lockTable) . " WHERE id = 'row' FOR UPDATE");

            $blocked = false;
            try {
                $contender->pdo()->beginTransaction();
                $contender->one('SELECT counter FROM ' . Database::identifier($lockTable) . " WHERE id = 'row' FOR UPDATE");
            } catch (\PDOException) {
                $blocked = true;
            }
            if ($contender->pdo()->inTransaction()) {
                $contender->pdo()->rollBack();
            }
            $holder->pdo()->rollBack();

            // AND THE LOCK IS RELEASED AGAIN. A database that blocked forever would satisfy the
            // first half and be useless: the claim is mutual exclusion, not deadlock.
            $acquiredAfter = false;
            try {
                $contender->pdo()->beginTransaction();
                $contender->one('SELECT counter FROM ' . Database::identifier($lockTable) . " WHERE id = 'row' FOR UPDATE");
                $acquiredAfter = true;
                $contender->pdo()->rollBack();
            } catch (\PDOException) {
                if ($contender->pdo()->inTransaction()) {
                    $contender->pdo()->rollBack();
                }
            }

            $dbFacts['row_locking'] = [
                $blocked && $acquiredAfter ? 'PRESENT' : 'MISSING',
                ['second_connection_blocked' => $blocked, 'released_on_rollback' => $acquiredAfter],
            ];
        } finally {
            foreach ([$holder, $contender] as $extra) {
                if ($extra !== null && $extra->pdo()->inTransaction()) {
                    try {
                        $extra->pdo()->rollBack();
                    } catch (\PDOException) {
                        // best-effort
                    }
                }
            }
            // Release the extra PDO sessions before the advisory-lock proof opens two more.
            // Shared hosting can enforce a low per-user connection ceiling; accumulating probe-only
            // sessions would turn that ceiling into a false capability failure.
            $holder = null;
            $contender = null;
            try {
                $db->run('DROP TABLE IF EXISTS ' . Database::identifier($lockTable));
            } catch (\PDOException) {
                $cleanupFailures[] = $lockTable;
            }
        }

        // ---- NAMED ADVISORY LOCKING, across TWO INDEPENDENT CONNECTIONS
        //
        // RunJournal and P05 source serialization use GET_LOCK/RELEASE_LOCK specifically so no SQL
        // transaction is held open across network I/O. Prove acquisition, ownership and release on
        // this account rather than assuming those functions are enabled from the MariaDB version.
        $advisoryName = 'probe_advisory_' . $suffix;
        $advisoryNestedName = 'probe_advisory_nested_' . $suffix;
        $advisoryHolder = null;
        $advisoryContender = null;
        try {
            $advisoryHolder = $connect();
            $advisoryContender = $connect();

            $holderAcquired = (int) $advisoryHolder->scalar('SELECT GET_LOCK(?, 0)', [$advisoryName]) === 1;
            // The runtime nests a source lock inside the already-held run lock on ONE connection.
            $holderNestedAcquired = $holderAcquired
                && (int) $advisoryHolder->scalar('SELECT GET_LOCK(?, 0)', [$advisoryNestedName]) === 1;
            $contenderBlocked = $holderNestedAcquired
                && (int) $advisoryContender->scalar('SELECT GET_LOCK(?, 0)', [$advisoryName]) === 0;
            $contenderCannotReleaseForeign = $holderAcquired
                && (int) $advisoryContender->scalar('SELECT RELEASE_LOCK(?)', [$advisoryName]) === 0;
            $nestedReleased = $holderNestedAcquired
                && (int) $advisoryHolder->scalar('SELECT RELEASE_LOCK(?)', [$advisoryNestedName]) === 1;
            $holderReleased = $nestedReleased
                && (int) $advisoryHolder->scalar('SELECT RELEASE_LOCK(?)', [$advisoryName]) === 1;
            $contenderAcquiredAfter = $holderReleased
                && (int) $advisoryContender->scalar('SELECT GET_LOCK(?, 0)', [$advisoryName]) === 1;
            $contenderReleased = $contenderAcquiredAfter
                && (int) $advisoryContender->scalar('SELECT RELEASE_LOCK(?)', [$advisoryName]) === 1;

            $advisoryWorks = $holderAcquired && $holderNestedAcquired && $contenderBlocked && $contenderCannotReleaseForeign
                && $nestedReleased && $holderReleased && $contenderAcquiredAfter && $contenderReleased;
            $dbFacts['named_advisory_locking'] = [
                $advisoryWorks ? 'PRESENT' : 'MISSING',
                [
                    'holder_acquired' => $holderAcquired,
                    'nested_lock_same_connection' => $holderNestedAcquired,
                    'contender_blocked' => $contenderBlocked,
                    'foreign_release_refused' => $contenderCannotReleaseForeign,
                    'nested_released_by_owner' => $nestedReleased,
                    'released_by_owner' => $holderReleased,
                    'acquired_after_release' => $contenderAcquiredAfter,
                    'released_after_acquire' => $contenderReleased,
                ],
            ];
        } catch (\PDOException) {
            $dbFacts['named_advisory_locking'] = ['MISSING', ['operation_failed' => true]];
        } finally {
            // Best-effort release on both sessions. A connection close also releases named locks.
            foreach ([$advisoryHolder, $advisoryContender] as $extra) {
                if ($extra !== null) {
                    try {
                        $extra->scalar('SELECT RELEASE_LOCK(?)', [$advisoryNestedName]);
                        $extra->scalar('SELECT RELEASE_LOCK(?)', [$advisoryName]);
                    } catch (\PDOException) {
                        // best-effort
                    }
                }
            }
            $advisoryHolder = null;
            $advisoryContender = null;
        }

        // ---- TRIGGER creation AND execution, on this account
        //
        // The append-only guarantees in SECURITY_BOUNDARY §9 are triggers. A host whose MariaDB user
        // lacks the TRIGGER privilege imports the migrations and drops them, and the deployment is
        // then a deployment whose central storage guarantee silently does not exist.
        $triggerTable = 'probe_trigger_' . $suffix;
        $triggerName = 'probe_trg_' . $suffix;
        try {
            $db->run('CREATE TABLE ' . Database::identifier($triggerTable) . ' (
                id CHAR(36) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB');
            $db->run('CREATE TRIGGER ' . Database::identifier($triggerName)
                . ' BEFORE DELETE ON ' . Database::identifier($triggerTable)
                . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe rows cannot be deleted'");
            $db->run('INSERT INTO ' . Database::identifier($triggerTable) . " (id) VALUES ('row')");

            // THE SQLSTATE IS THE PROOF, not the mere fact that the DELETE threw.
            //
            // An account that may create triggers but lacks the DELETE privilege also throws here —
            // SQLSTATE 42000, `command denied` — and the row also survives, because nothing deleted
            // it. Accepting any database error as evidence the trigger fired therefore reported
            // `trigger_support: PRESENT` for a host that had proved nothing of the kind, and this is
            // the LAST capability proof: a READY staging verdict then rested on it while the
            // account could not perform the DELETE operations the application itself issues.
            //
            // `SIGNAL SQLSTATE '45000'` is what the trigger above raises and what every append-only
            // trigger in `database/migrations/` raises, so that exact state is the thing to require.
            $triggerFires = false;
            $deleteSqlstate = null;
            try {
                $db->run('DELETE FROM ' . Database::identifier($triggerTable) . " WHERE id = 'row'");
            } catch (\PDOException $error) {
                // The driver's SQLSTATE, never the message: a message may name the account or host.
                $deleteSqlstate = is_array($error->errorInfo ?? null) && isset($error->errorInfo[0])
                    ? (string) $error->errorInfo[0]
                    : (string) $error->getCode();
                $triggerFires = $deleteSqlstate === '45000';
            }
            $survived = (int) $db->scalar('SELECT COUNT(*) FROM ' . Database::identifier($triggerTable)) === 1;
            $dbFacts['trigger_support'] = [
                $triggerFires && $survived ? 'PRESENT' : 'MISSING',
                [
                    'signal_raised' => $triggerFires,
                    'row_survived' => $survived,
                    // 45000 is the trigger. Anything else — 42000 above all — is the account being
                    // unable to issue the DELETE at all, which is a different and worse finding.
                    'delete_sqlstate' => $deleteSqlstate,
                ],
            ];
        } catch (\PDOException $error) {
            // Creation itself refused — almost always a missing TRIGGER privilege.
            $dbFacts['trigger_support'] = ['MISSING', ['created' => false, 'error_class' => $error::class]];
        } finally {
            try {
                $db->run('DROP TRIGGER IF EXISTS ' . Database::identifier($triggerName));
                $db->run('DROP TABLE IF EXISTS ' . Database::identifier($triggerTable));
            } catch (\PDOException) {
                $cleanupFailures[] = $triggerTable;
            }
        }

        $dbStatus = 'PRESENT';
        $dbDetail = ['server_version' => $version];
    } catch (\Throwable $error) {
        $dbStatus = 'MISSING';
        // The message may name a host or user; report only the driver's class of failure.
        $dbDetail = ['unreachable' => true, 'error_class' => $error::class];
    }
}

$record('mariadb', $dbStatus, $dbDetail, 'The storage pins are half of every governed invariant.');
$record(
    'probe_cleanup',
    $cleanupFailures === [] ? 'PRESENT' : 'MISSING',
    ['tables_left_behind' => count($cleanupFailures)],
    'Every proof above runs against a disposable table this probe created. An account that cannot '
    . 'drop them leaves litter in the database and has not demonstrated the disposable operation '
    . 'this probe promises, so the host is not READY.',
);
foreach ($dbFacts as $key => [$status, $detail]) {
    $record($key, $status, $detail, match ($key) {
        'mariadb_version_floor' => 'Loopia Företag is 10.6; the schema and its CHECK constraints need it.',
        'innodb_available' => 'Every governed table is InnoDB — transactions, foreign keys and row locks.',
        'check_constraints_enforced' => 'A parsed-and-ignored CHECK would leave every storage invariant unenforced.',
        'transaction_rollback' => 'A governed write that half-committed would release without its binding.',
        'foreign_keys_enforced' => 'Deferred FKs (900_deferred_foreign_keys) are the referential half of the schema.',
        'row_locking' => 'The job machine leases work by locking a row; without it two ticks claim one job.',
        'named_advisory_locking' => 'Monitor run/source serialization uses named session locks so no SQL transaction spans network I/O.',
        'trigger_support' => 'The append-only tables ARE triggers. Without the privilege they import and vanish.',
        default => 'Storage capability.',
    });
}

// ---------------------------------------------------------------------------- outbound HTTPS

if (!extension_loaded('curl')) {
    $record('outbound_https', 'MISSING', false, 'The curl extension is absent.');
} elseif (!$allowNetwork) {
    $record('outbound_https', 'NOT_PROBED', 'pass --allow-network to probe',
        'Governed read-only capture requires outbound HTTPS with TLS verification.');
} else {
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_URL => 'https://' . $probeHost . '/',
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 10,
        // THE SAME EGRESS PATH THE GOVERNED TRANSPORT USES, which is a DIRECT one.
        //
        // cURL reads `HTTPS_PROXY` and `ALL_PROXY` from the environment unless told not to, and
        // `CurlHttpsTransport` empties `CURLOPT_PROXY` for that exact reason: a proxy would undo the
        // ING-T03 resolve-connect pin by handing the hostname to somebody else to resolve. So on a
        // host where only proxy-mediated egress works, a probe that inherited the proxy would
        // succeed, report PRESENT, and produce a READY live-read verdict for a transport that will
        // fail on the very first request — the probe would have demonstrated a path production
        // cannot take.
        CURLOPT_PROXY => '',
    ]);
    $ok = curl_exec($handle) !== false;
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $err = curl_error($handle);
    // CurlHandle is object-backed on PHP 8+; curl_close() is a deprecated no-op in PHP 8.5.
    $record('outbound_https', $ok ? 'PRESENT' : 'MISSING',
        ['reached' => $ok, 'status' => $code, 'error' => $ok ? null : substr($err, 0, 120), 'proxy_disabled' => true],
        'Governed read-only capture requires outbound HTTPS with TLS verification, through a DIRECT '
        . 'connection: the governed transport empties CURLOPT_PROXY, so a proxy-only host cannot '
        . 'serve live read however well a proxied request performs.');
}

// ---------------------------------------------------------------------------- verdicts

/**
 * Reduce a required capability set to one verdict.
 *
 * MISSING dominates NOT_PROBED: a host known to lack something is a different answer from a host
 * nobody asked, and the worse of the two is what a reader should see first.
 *
 * @param list<string> $required
 */
$verdict = static function (array $required) use (&$report): array {
    $missing = [];
    $notProbed = [];
    foreach ($required as $key) {
        $status = $report[$key]['status'] ?? 'MISSING';
        if ($status === 'MISSING' || $status === 'DEGRADED') {
            $missing[] = $key;
        } elseif ($status === 'NOT_PROBED') {
            $notProbed[] = $key;
        }
    }

    return [
        'verdict' => match (true) {
            $missing !== [] => 'MISSING_CAPABILITY',
            $notProbed !== [] => 'NOT_QUALIFIED',
            default => 'READY',
        },
        'missing' => $missing,
        'not_demonstrated' => $notProbed,
    ];
};

$stagingRequired = [
    'php_version',
    'ext_pdo', 'ext_pdo_mysql', 'ext_curl', 'ext_openssl', 'ext_json', 'ext_mbstring', 'ext_hash',
    'aes_256_gcm', 'csprng',
    'private_storage_writable', 'atomic_rename', 'public_root_separation',
    'mariadb', 'mariadb_version_floor', 'innodb_available',
    'check_constraints_enforced', 'transaction_rollback', 'foreign_keys_enforced',
    'row_locking', 'named_advisory_locking', 'trigger_support', 'probe_cleanup',
];
$liveReadRequired = [
    'ext_curl', 'curl_ssl', 'curl_resolve_pinning', 'curl_timeout_ms', 'curl_writefunction',
    'curl_protocols_restrictable', 'dns_function', 'dns_resolution', 'outbound_https',
];

$staging = $verdict($stagingRequired);
$liveRead = $verdict($liveReadRequired);

// THE SCHEDULER VERDICT IS ALWAYS UNVERIFIED, AND SAYING SO IS THE POINT.
//
// What a probe CAN establish is that the CLI door exists: this process is running under the CLI
// SAPI on this host, and `worker/tick.php` is present beside it. What no probe can establish is
// which HTTP method Loopia's Kundzon scheduler uses, or whether it can set a custom header, because
// Loopia documents neither and nothing measurable from inside PHP answers it. That gap is recorded
// as LOOPIA_SCHEDULER_TRANSPORT_BLOCKER in docs/LOOPIA_HOST_MODEL.md §4 and is closed by one
// question to Loopia support — never by this file reporting READY, and never by weakening the
// POST-only endpoint to fit an assumption.
//
// It is moot for now in any case: the handler registry is empty in both composition roots, so a
// tick that arrived would correctly do nothing.
$tickPath = $root . '/worker/tick.php';
$scheduler = [
    'verdict' => is_file($tickPath) ? 'UNVERIFIED' : 'MISSING_CAPABILITY',
    'cli_tick_present' => is_file($tickPath),
    'cli_sapi' => PHP_SAPI,
    'kundzon_http_method' => 'NOT_DOCUMENTED',
    'kundzon_custom_headers' => 'NOT_DOCUMENTED',
    'blocker' => 'LOOPIA_SCHEDULER_TRANSPORT_BLOCKER',
    'preferred_transport' => 'EXTERNAL_SCHEDULER_OVER_SSH_TO_WORKER_TICK',
    'scheduler_handlers_registered' => false,
];

$missing = array_keys(array_filter($report, static fn (array $r): bool => $r['status'] === 'MISSING'));
$degraded = array_keys(array_filter($report, static fn (array $r): bool => $r['status'] === 'DEGRADED'));
$notProbed = array_keys(array_filter($report, static fn (array $r): bool => $r['status'] === 'NOT_PROBED'));

echo json_encode([
    'probe' => 'LOOPIA_PROBE_001',
    'probe_version' => '2.0',
    'projection' => 'LOOPIA_SME_MVP',
    'probed_at' => (new Clock())->now(),
    'network_probed' => $allowNetwork,
    'probe_host' => $allowNetwork ? $probeHost : null,
    'capabilities' => $report,
    'missing' => $missing,
    'degraded' => $degraded,
    'not_probed' => $notProbed,
    // THREE SUBJECTS, THREE ANSWERS. A single verdict could say "capabilities demonstrated" about a
    // run that never touched the network, which is the exact sentence version 1 could produce.
    'staging_host_verdict' => $staging['verdict'],
    'staging_host_missing' => $staging['missing'],
    'staging_host_not_demonstrated' => $staging['not_demonstrated'],
    'live_read_host_verdict' => $liveRead['verdict'],
    'live_read_host_missing' => $liveRead['missing'],
    'live_read_host_not_demonstrated' => $liveRead['not_demonstrated'],
    'scheduler_transport_verdict' => $scheduler['verdict'],
    'scheduler_transport' => $scheduler,
    // Passing establishes host capability ONLY. It grants no runtime authority, activates no live
    // read, and authorizes no deployment. A READY live-read verdict says the host COULD do it.
    'grants_runtime_authority' => false,
    'activates_live_read' => false,
    'authorizes_deployment' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$ok = $staging['verdict'] === 'READY' && (!$requireLiveRead || $liveRead['verdict'] === 'READY');

exit($ok ? 0 : 1);
