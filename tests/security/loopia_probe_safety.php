<?php

declare(strict_types=1);

/**
 * What LOOPIA_PROBE_001 may touch, and what it may emit.
 *
 * The probe is the one command in this repository that is DESIGNED to be run against a live host
 * with real credentials in `.env`, and whose output is designed to be pasted into a ticket. Those
 * two facts together are the risk: a probe that printed a connection error verbatim would paste a
 * hostname and a user name into a support thread, and a probe that wrote to a governed table would
 * do it on the host where the data is real.
 *
 * So three properties are asserted here rather than left to review.
 *
 *   IT TOUCHES NO GOVERNED TABLE. Every storage proof runs against a randomly-named disposable
 *   table it created in this run, and drops it in a `finally` — including when the proof threw.
 *   Litter left on a shared host is litter an operator has to recognise and remove by hand, at the
 *   moment they are already reading a failure report.
 *
 *   IT EMITS NO SECRET. The report names configuration KEYS and capability states. It never renders
 *   a value, never renders an exception message, and never renders a path outside the app root.
 *
 *   IT CANNOT HANG. The one place it deliberately contends for a lock bounds the wait before it
 *   blocks, so the worst case is a second rather than a process somebody has to kill on a host
 *   where they may not be able to see the process list.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$raw = (string) file_get_contents($root . '/ops/loopia-probe/probe.php');

/** The probe's EXECUTABLE source. Prose may name a table this file forbids. */
$code = implode('', array_map(
    static function (array|string $token): string {
        if (is_string($token)) {
            return $token;
        }

        return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
    },
    token_get_all($raw),
));

// ============================================================ it refuses a web SAPI first

// Also asserted structurally for every ops entry point by `deployment_boundary.php`. It is restated
// here because on the security plane the consequence is specific: reachable over HTTP this would be
// an unauthenticated way to make the server open a database connection and a network connection.
Assert::contains("if (PHP_SAPI !== 'cli') {", $code, 'the probe refuses any web SAPI');
Assert::true(
    strpos($code, "PHP_SAPI !== 'cli'") < strpos($code, 'require'),
    'and refuses BEFORE it loads the application',
);

// ============================================================ no governed table is named at all

// The strongest available form of "it will not touch your data": the identifiers do not appear.
foreach ([
    'tenants', 'workspaces', 'operators', 'platform_users', 'platform_sessions',
    'decision_records', 'decision_supersession_records', 'evidence_objects', 'raw_artifacts',
    'consent_registry', 'provider_credentials', 'subscription_tiers', 'schema_migrations',
    'auth_attempts', 'release_artifacts', 'customer_output_projections',
] as $governed) {
    Assert::false(
        str_contains($code, $governed),
        "the probe never names the governed table {$governed}",
    );
}

// Every table it does create is disposable and randomly named, so two operators probing the same
// database at the same time cannot collide — and a collision here would look like a capability
// failure rather than like two people running a probe.
Assert::contains("\$suffix = bin2hex(random_bytes(4));", $code, 'probe table names carry random entropy');
foreach (['probe_check_', 'probe_fk_parent_', 'probe_fk_child_', 'probe_lock_', 'probe_trigger_'] as $prefix) {
    Assert::contains("'{$prefix}' . \$suffix", $code, "the {$prefix} probe table is suffixed with that entropy");
}

// EVERY CREATE HAS A DROP, AND THE DROP IS IN A `finally`. A probe that cleaned up only on the happy
// path would leave its litter precisely on the hosts where something went wrong.
Assert::same(5, substr_count($code, 'CREATE TABLE '), 'the probe creates exactly five disposable tables');
foreach (['$checkTable', '$parent', '$child', '$lockTable', '$triggerTable'] as $table) {
    Assert::contains(
        "Database::identifier({$table})",
        $code,
        "the {$table} probe table is quoted as an identifier rather than interpolated raw",
    );
}
// Four drop sites cover five tables: the two foreign-key tables are dropped by one loop, in the
// order a foreign key demands.
Assert::same(4, substr_count($code, 'DROP TABLE IF EXISTS '), 'four drop sites');
Assert::contains(
    'foreach ([$child, $parent] as $table) {',
    $code,
    'and the pair is dropped child-first, which is the only order a foreign key permits',
);
Assert::contains('DROP TRIGGER IF EXISTS ', $code, 'the probe trigger is dropped with its table');
Assert::same(
    4,
    substr_count($code, '} finally {'),
    'each storage proof cleans up in a finally, so a thrown proof still tidies up',
);
Assert::false(str_contains($code, 'DROP DATABASE'), 'the probe drops no database');
Assert::false(str_contains($code, 'TRUNCATE'), 'and truncates nothing');

// ============================================================ it cannot hang

Assert::contains(
    'SET SESSION innodb_lock_wait_timeout = 1',
    $code,
    'the contending connection bounds its wait BEFORE it blocks',
);
Assert::true(
    strpos($code, 'SET SESSION innodb_lock_wait_timeout = 1')
        < strpos($code, '$holder->pdo()->beginTransaction();'),
    'and sets that bound before the holder takes the lock',
);
Assert::contains('CURLOPT_TIMEOUT => 10', $code, 'the outbound HTTPS probe is time-bounded');

// ============================================================ it emits no secret

// The connection error path reports the CLASS of failure, never the driver message: a PDO message
// routinely carries the host, the user name and the database name.
Assert::contains("'error_class' => \$error::class", $code, 'a connection failure reports only its class');
Assert::false(
    str_contains($code, '$error->getMessage()'),
    'the probe never renders an exception message into its report',
);
// The password reaches exactly one place: the connection. It is never named in the report.
Assert::same(
    1,
    substr_count($code, "\$env->get('DB_PASSWORD', '')"),
    'DB_PASSWORD is read once, and only to open the connection',
);
foreach (['APP_MASTER_KEY', 'SCHEDULER_TOKEN', 'redactedSnapshot', 'DB_USER\'] =>'] as $absent) {
    Assert::false(str_contains($code, $absent), "the probe report never carries {$absent}");
}
// The database detail is a CLOSED set of keys. An `...$row` spread here is how a column nobody
// intended would reach a pasteable report.
Assert::contains("\$dbDetail = ['server_version' => \$version];", $code, 'the database detail is exactly the version');
Assert::false(str_contains($code, '$env->redactedSnapshot()'), 'the probe does not dump configuration');

// NO PATH REACHES THE REPORT. `$root` is an absolute path on the host, and a report designed to be
// pasted into a ticket must not carry the deployment's directory layout. The assertion is scoped to
// the emitted payload rather than to the file, because the probe legitimately uses `$root` to find
// the directories it measures.
$payload = substr($code, (int) strpos($code, 'echo json_encode(['));
Assert::false(str_contains($payload, '$root'), 'no absolute path is interpolated into the report');
Assert::false(str_contains($payload, '__DIR__'), 'nor a source directory');
Assert::false(str_contains($payload, '$envPath'), 'nor the configuration file path');

// The report is JSON on stdout and nothing else — there is no second sink to audit.
Assert::same(1, substr_count($code, 'echo json_encode('), 'the probe emits exactly one report');
Assert::false(str_contains($code, 'error_log('), 'and writes nothing to a log');
Assert::false(str_contains($code, 'file_put_contents($report'), 'and writes no report file');

// ============================================================ it writes only under storage/

// The one filesystem write it performs is the atomic-rename proof, and it happens inside
// `storage/`, which is private, outside the served tree, and already the directory the application
// writes to. A probe that proved atomic rename by writing next to `index.php` would be proving it
// in the one directory where a leftover file is served.
Assert::contains("\$storage = \$root . '/storage';", $code, 'the filesystem proof targets the private storage directory');
Assert::contains("\$dir = \$storage . '/probe-' . bin2hex(random_bytes(4));", $code, 'in a randomly-named subdirectory');
foreach (['@unlink($dst);', '@unlink($tmp);', '@rmdir($dir);'] as $cleanup) {
    Assert::contains($cleanup, $code, "and removes what it wrote: {$cleanup}");
}
Assert::false(str_contains($code, "public_html' . DIRECTORY_SEPARATOR"), 'nothing is written into the served tree');

// ============================================================ network is opt-in and single-purpose

// TWO network calls exist, both behind `--allow-network`, both against a host the operator named.
// Neither goes through a source card, the egress guard or the evidence spine, because neither is a
// governed read — and that separation is what makes running the probe safe on a host where live
// read must stay off.
Assert::same(1, substr_count($code, 'curl_exec('), 'exactly one outbound request exists in the probe');
// TWO resolutions, A and AAAA, of the SAME operator-named host — both records are wanted because a
// host that resolves only to IPv6 is a case the egress guard has to screen.
Assert::same(2, substr_count($code, 'dns_get_record($probeHost'), 'and exactly two lookups of that one host');
Assert::contains('@dns_get_record($probeHost, DNS_A)', $code, 'its A records');
Assert::contains('@dns_get_record($probeHost, DNS_AAAA)', $code, 'and its AAAA records');
Assert::contains('CURLOPT_SSL_VERIFYPEER => true', $code, 'TLS verification is on');
Assert::contains('CURLOPT_SSL_VERIFYHOST => 2', $code, 'and the host name is verified');
Assert::contains('CURLOPT_FOLLOWLOCATION => false', $code, 'and no redirect is followed');
Assert::true(
    strpos($code, 'if (!extension_loaded(\'curl\')) {') < strpos($code, 'curl_exec('),
    'the request is guarded by the flag and the extension before it is made',
);
// The probe host is validated before use: it is interpolated into a URL.
Assert::contains(
    "preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?\$/D', \$probeHost)",
    $code,
    'the operator-supplied probe host is validated before it reaches a URL',
);

// ============================================================ and it activates nothing

Assert::false(str_contains($code, 'CurlHttpsTransport'), 'the probe names no live transport');
Assert::false(str_contains($code, 'DnsHostResolver'), 'nor the real host resolver');
Assert::false(str_contains($code, 'SchedulerRunner'), 'and constructs no scheduler');
foreach ([
    "'grants_runtime_authority' => false",
    "'activates_live_read' => false",
    "'authorizes_deployment' => false",
] as $stated) {
    Assert::contains($stated, $code, "every report states {$stated}");
}

// ============================================================ it probes the path production takes

// THE GOVERNED TRANSPORT EMPTIES `CURLOPT_PROXY`, and the probe has to do the same or it is
// demonstrating a route production cannot use. cURL reads `HTTPS_PROXY` and `ALL_PROXY` from the
// environment unless told not to; on a host where only proxy-mediated egress works, an inheriting
// probe succeeds, reports PRESENT and produces a READY live-read verdict for a transport that will
// fail on its first request — the exact false pass a qualification tool must not produce.
Assert::contains("CURLOPT_PROXY => ''", $code, 'the probe disables environment proxies');
$transport = (string) file_get_contents($root . '/app/Ingestion/CurlHttpsTransport.php');
Assert::contains(
    "CURLOPT_PROXY => ''",
    $transport,
    'which is the same option value the governed transport sets, so the probe measures the same egress path',
);
Assert::contains("'proxy_disabled' => true", $code, 'and the report says so, because the reader needs the scope of the claim');

// ============================================================ the trigger proof is the SQLSTATE

// AN ACCOUNT THAT MAY CREATE TRIGGERS BUT LACKS DELETE ALSO THROWS HERE, and the row also survives
// because nothing deleted it. Accepting any database error as evidence that the trigger fired
// reported `trigger_support: PRESENT` for a host that had demonstrated nothing of the kind — and
// this is the last storage proof, so a READY staging verdict rested on it.
Assert::contains(
    "\$triggerFires = \$deleteSqlstate === '45000';",
    $code,
    'only SQLSTATE 45000 — the state SIGNAL raises — counts as the trigger having fired',
);
Assert::false(
    str_contains($code, "} catch (\\PDOException) {\n                \$triggerFires = true;"),
    'a bare PDOException is not accepted as proof',
);
Assert::contains(
    "'delete_sqlstate' => \$deleteSqlstate,",
    $code,
    'and the observed state is reported, so a 42000 privilege refusal is legible as what it is',
);
// THE SQLSTATE, NEVER THE MESSAGE. A driver message may name the account or the host.
Assert::false(
    str_contains($code, '$error->getMessage()'),
    'no driver message reaches the report',
);
Assert::contains(
    "(string) \$error->errorInfo[0]",
    $code,
    'the SQLSTATE is taken from the driver rather than parsed out of prose',
);
