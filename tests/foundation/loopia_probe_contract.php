<?php

declare(strict_types=1);

/**
 * The LOOPIA_PROBE_001 contract: what the probe must answer, and what it must never claim.
 *
 * The probe is the instrument the host qualification rests on, and an instrument that overstates is
 * worse than no instrument — a reader who trusts a verdict does not go and check the capability
 * themselves. Version 1 could emit `CAPABILITIES_DEMONSTRATED` on a run where outbound HTTPS was
 * `NOT_PROBED`, and its README listed capabilities (foreign keys, row locking, trigger behaviour)
 * that nothing in the file actually measured.
 *
 * So two things are asserted here. That the three verdicts are separate, closed and reducible from
 * declared capability sets — and that the README and the probe agree about what is measured, which
 * is the assertion that would have caught the original drift.
 *
 * These are STRUCTURAL. Running the probe proves it works on THIS machine; what this file proves is
 * that the shape cannot quietly change into the shape it had before.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$probePath = $root . '/ops/loopia-probe/probe.php';
$raw = (string) file_get_contents($probePath);

/** The probe's EXECUTABLE source — prose may discuss a verdict without asserting it. */
$code = implode('', array_map(
    static function (array|string $token): string {
        if (is_string($token)) {
            return $token;
        }

        return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
    },
    token_get_all($raw),
));

// ============================================================ three subjects, three verdicts

foreach (['staging_host_verdict', 'live_read_host_verdict', 'scheduler_transport_verdict'] as $subject) {
    Assert::contains("'{$subject}' =>", $code, "the probe reports {$subject} separately");
}
Assert::false(
    str_contains($code, 'CAPABILITIES_DEMONSTRATED'),
    'the single broad verdict is gone — it could be reached while the network was never probed',
);

// The verdict vocabulary is closed, and every member is reachable from the reducer.
foreach (['READY', 'NOT_QUALIFIED', 'MISSING_CAPABILITY', 'UNVERIFIED'] as $value) {
    Assert::contains("'{$value}'", $code, "the verdict vocabulary includes {$value}");
}

// MISSING DOMINATES NOT_PROBED. A host known to lack something and a host nobody asked are
// different answers, and the worse one has to win or a missing capability hides behind an
// un-run probe.
Assert::true(
    strpos($code, "\$missing !== [] => 'MISSING_CAPABILITY'") < strpos($code, "\$notProbed !== [] => 'NOT_QUALIFIED'"),
    'a MISSING capability outranks one that was merely not demonstrated',
);
// DEGRADED is not a pass for a REQUIRED capability. "Present but limited" is the shape a partial
// control has, and a partial control is what the fail-closed rule exists to refuse.
Assert::contains(
    "\$status === 'MISSING' || \$status === 'DEGRADED'",
    $code,
    'a DEGRADED required capability counts against the verdict rather than for it',
);

// ============================================================ the scheduler verdict cannot be READY

// Loopia documents neither the method Kundzon uses nor whether a custom header can be set, and
// nothing measurable from inside PHP answers either. A probe that reported READY would be closing a
// blocker by assertion. It is closed by one question to Loopia support, and until then the honest
// answer is UNVERIFIED.
Assert::contains(
    "'verdict' => is_file(\$tickPath) ? 'UNVERIFIED' : 'MISSING_CAPABILITY'",
    $code,
    'the scheduler transport verdict is UNVERIFIED or MISSING_CAPABILITY — never READY',
);
Assert::contains('LOOPIA_SCHEDULER_TRANSPORT_BLOCKER', $code, 'and names the recorded blocker');
Assert::contains(
    "'scheduler_handlers_registered' => false",
    $code,
    'and states that no handler is registered, which is why the question is not yet urgent',
);
Assert::contains(
    'LOOPIA_SCHEDULER_TRANSPORT_BLOCKER',
    (string) file_get_contents($root . '/docs/LOOPIA_HOST_MODEL.md'),
    'the blocker the probe names is the one the host model records',
);

// ============================================================ what each verdict actually requires

// The README used to list capabilities nothing measured. These are the entries whose absence would
// make a verdict a guess, so each is asserted to be IN the required set rather than merely probed.
$stagingRequired = [
    'php_version', 'ext_pdo', 'ext_pdo_mysql', 'ext_openssl', 'ext_json', 'ext_mbstring', 'ext_hash',
    'aes_256_gcm', 'csprng', 'private_storage_writable', 'atomic_rename', 'public_root_separation',
    'mariadb', 'mariadb_version_floor', 'innodb_available', 'check_constraints_enforced',
    'transaction_rollback', 'foreign_keys_enforced', 'row_locking', 'named_advisory_locking', 'trigger_support',
];
$stagingBlock = substr($code, (int) strpos($code, '$stagingRequired = ['), 900);
foreach ($stagingRequired as $capability) {
    Assert::contains("'{$capability}'", $stagingBlock, "staging readiness requires {$capability}");
}

$liveReadRequired = [
    'ext_curl', 'curl_ssl', 'curl_resolve_pinning', 'curl_timeout_ms', 'curl_writefunction',
    'curl_protocols_restrictable', 'dns_function', 'dns_resolution', 'outbound_https',
];
$liveReadBlock = substr($code, (int) strpos($code, '$liveReadRequired = ['), 400);
foreach ($liveReadRequired as $capability) {
    Assert::contains("'{$capability}'", $liveReadBlock, "live-read readiness requires {$capability}");
}

// THE TWO SETS ARE NOT THE SAME SET. Staging readiness must not depend on the network, or an inert
// deployment would be blocked by a capability it does not use; live-read readiness must not be
// satisfied by storage proofs that say nothing about egress.
Assert::false(in_array('outbound_https', $stagingRequired, true), 'an inert staging host needs no outbound HTTPS');
Assert::false(in_array('dns_resolution', $stagingRequired, true), 'nor DNS resolution');
Assert::false(in_array('trigger_support', $liveReadRequired, true), 'and a storage proof is not egress evidence');

// ============================================================ nothing is inferred from a version

// Each of these is DEMONSTRATED against a disposable table. A version string says what the server
// calls itself; it does not say that this ACCOUNT may create a trigger or that this instance
// enforces foreign keys.
Assert::contains('CONSTRAINT fk_probe_', $code, 'foreign key enforcement is proved with a real constraint');
Assert::contains('FOR UPDATE', $code, 'row locking is proved by actually taking a lock');
Assert::contains("SIGNAL SQLSTATE '45000'", $code, 'trigger support is proved by a trigger that fires');
Assert::contains('CHECK (flag = 0)', $code, 'CHECK enforcement is proved by a refused write');

// TWO CONNECTIONS, OR THE LOCK PROOF PROVES NOTHING. Statements on one handle are serialized by the
// wire: the "contender" would simply read what the holder committed.
Assert::contains('$holder = $connect();', $code, 'the lock proof opens a holder connection');
Assert::contains('$contender = $connect();', $code, 'and a SECOND, independent contender connection');
Assert::contains(
    'SET SESSION innodb_lock_wait_timeout = 1',
    $code,
    'and bounds the contention so the probe cannot hang on a host that blocks',
);
Assert::contains(
    "'released_on_rollback'",
    $code,
    'and proves the lock is RELEASED again — a database that blocked forever would pass half of this',
);

// NAMED SESSION LOCKS ARE A DIFFERENT PRIMITIVE. P05 uses them specifically to avoid holding an SQL
// transaction across DNS/HTTP, so they need their own host proof rather than inheriting row-locking.
Assert::contains("'named_advisory_locking' => ['NOT_PROBED', null]", $code, 'named advisory locking is a distinct capability');
Assert::contains("SELECT GET_LOCK(?, 0)", $code, 'the probe executes the same non-blocking named-lock primitive the Monitor uses');
Assert::contains("SELECT RELEASE_LOCK(?)", $code, 'and executes the matching release primitive');
Assert::contains("'nested_lock_same_connection' => \$holderNestedAcquired", $code, 'the same session can hold the run lock and nested source lock together');
Assert::contains("'foreign_release_refused' => \$contenderCannotReleaseForeign", $code, 'another connection cannot release the holder\'s lock');
Assert::contains("'acquired_after_release' => \$contenderAcquiredAfter", $code, 'the contender acquires after the owner releases');
Assert::contains('$holder = null;', $code, 'row-lock proof releases its extra connection objects before opening advisory-lock sessions');
Assert::contains('$advisoryHolder = null;', $code, 'advisory-lock proof releases its extra connection objects after cleanup');

// ============================================================ the network is opt-in, both ways

Assert::contains("'allow-network'", $code, 'network probing is a flag');
Assert::contains("'require-live-read'", $code, 'and requiring a live-read verdict is a separate one');
Assert::contains(
    'if ($requireLiveRead && !$allowNetwork) {',
    $code,
    'requiring live-read readiness without permission to make a request is refused as a contradiction',
);
Assert::true(
    strpos($code, 'if ($requireLiveRead && !$allowNetwork) {') < strpos($code, 'Env::load($envPath)'),
    'and refused before configuration is even read',
);
// The default run must stay green and non-network: CI invokes exactly this.
Assert::contains(
    "\$ok = \$staging['verdict'] === 'READY' && (!\$requireLiveRead || \$liveRead['verdict'] === 'READY');",
    $code,
    'the exit code follows the staging verdict unless live read was explicitly required',
);

// ============================================================ passing grants nothing

foreach ([
    "'grants_runtime_authority' => false",
    "'activates_live_read' => false",
    "'authorizes_deployment' => false",
] as $disclaimer) {
    Assert::contains($disclaimer, $code, "the report states {$disclaimer}");
}
// A READY live-read verdict says the host COULD. Nothing in the probe wires the transport, and the
// structural proof of that lives in `no_external_action_surface.php`, which scans ops/ too.
Assert::false(str_contains($code, 'new CurlHttpsTransport'), 'the probe constructs no live transport');
Assert::false(str_contains($code, 'new DnsHostResolver'), 'nor the real host resolver');

// ============================================================ the README describes THIS probe

// The drift this catches is the one that already happened once: a README listing capabilities the
// file never measured. Every capability named in the README's required list must be a key the probe
// actually records.
$readme = (string) file_get_contents($root . '/ops/loopia-probe/README.md');
preg_match_all('/^- `([a-z0-9_]+)`/m', $readme, $documented);
Assert::true($documented[1] !== [], 'the README lists the capabilities it claims are probed');
foreach ($documented[1] as $capability) {
    Assert::contains(
        "'{$capability}'",
        $code,
        "the README names {$capability} and the probe records it",
    );
}
foreach (['staging_host_verdict', 'live_read_host_verdict', 'scheduler_transport_verdict'] as $subject) {
    Assert::contains($subject, $readme, "the README documents the {$subject} it now emits");
}
Assert::contains(
    'ALLFATHER_LOOPIA_NON_SECRET_CANARY_',
    $readme,
    'and documents the pre-secret canary, whose whole point is that it is worthless',
);

// ============================================================ the probe survives the host it diagnoses

// A DIAGNOSTIC THAT DIES ON A BROKEN HOST IS NO DIAGNOSTIC. The probe recorded `ext_openssl:
// MISSING` and then called `openssl_get_cipher_methods()` unconditionally two dozen lines later — an
// undefined-function fatal on exactly that host, before a single line of the sanitized JSON was
// emitted. The one host that most needs this report was the one that could not produce it.
//
// So every extension-dependent call sits behind a guard, and the guard comes FIRST in the file.
// `function_exists` as well as `extension_loaded` for OpenSSL, because `disable_functions` can
// remove an individual function from a loaded extension — a shared-hosting configuration the probe
// reports on a few lines above, and one that fails in exactly the same way.
Assert::contains(
    "\$opensslUsable = extension_loaded('openssl')",
    $code,
    'the AES-GCM demonstration is guarded by the extension',
);
foreach (['openssl_get_cipher_methods', 'openssl_encrypt', 'openssl_decrypt'] as $needed) {
    Assert::contains(
        "function_exists('{$needed}')",
        $code,
        "and by {$needed} existing, which disable_functions can remove from a loaded extension",
    );
}
Assert::contains(
    '$gcm = $opensslUsable && in_array(',
    $code,
    'and the cipher list is only consulted once the guard has passed',
);
// EACH FAMILY'S GUARD PRECEDES ITS FIRST USE. Stated as an ordering rather than a spelling, because
// what went wrong was a call that ran before the check, not a check that was absent.
foreach ([
    'openssl' => ["extension_loaded('openssl')", 'openssl_get_cipher_methods('],
    'curl' => ["extension_loaded('curl')", 'curl_version('],
    'dns' => ["function_exists('dns_get_record')", 'dns_get_record($probeHost'],
] as $family => [$guard, $firstUse]) {
    Assert::true(
        strpos($code, $guard) < strpos($code, $firstUse),
        "the {$family} guard is evaluated before the first {$family} call, so an absent extension is a verdict rather than a fatal",
    );
}
// AND A MISSING EXTENSION STILL REACHES A VERDICT. `aes_256_gcm` is in the staging-required set, so
// an openssl-less host answers MISSING_CAPABILITY with a full report rather than exiting on a fatal.
Assert::contains(
    "'aes_256_gcm'",
    $code,
    'the AES-GCM capability is recorded either way',
);
Assert::contains(
    "\$opensslUsable ? \$gcmWorks : 'openssl is unavailable on this host'",
    $code,
    'and says WHICH of the two failures it was — an absent extension is a host request, a present one without the cipher is a build question',
);
