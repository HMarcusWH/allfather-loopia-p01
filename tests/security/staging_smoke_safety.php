<?php

declare(strict_types=1);

/**
 * What the staging smoke client may hold, and what it may print.
 *
 * This is the only tool in the repository that is designed to sign in to a live deployment with a
 * real password and whose output is designed to be pasted into a ticket. Those two facts together
 * are the risk. A smoke run that printed one battlecard would be an export of a tenant's
 * intelligence to whoever reads the terminal; one that printed a `Set-Cookie` would be a session
 * token in a chat log; one that followed a redirect to another origin would present the deployment's
 * cookies to whatever answered.
 *
 * So the properties are asserted rather than reviewed. The emitted step record is a CLOSED set of
 * fields, and the response body is not among them — the body is read only to count its bytes, to
 * reach a reason code, and to find the workspace identifier the chooser offers.
 */

use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$path = $root . '/ops/staging-smoke.php';
$raw = (string) file_get_contents($path);

/** The client's EXECUTABLE source. Its prose names things this file forbids in code. */
$code = implode('', array_map(
    static function (array|string $token): string {
        if (is_string($token)) {
            return $token;
        }

        return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
    },
    token_get_all($raw),
));

// ============================================================ CLI only, before anything loads

Assert::contains("if (PHP_SAPI !== 'cli') {", $code, 'the smoke client refuses any web SAPI');
Assert::true(
    strpos($code, "PHP_SAPI !== 'cli'") < strpos($code, 'require'),
    'and refuses BEFORE it loads the application',
);

// ============================================================ HTTPS, refused rather than upgraded

Assert::contains(
    "(\$parts['scheme'] ?? '') !== 'https'",
    $code,
    'a base URL that is not https is refused',
);
Assert::contains(
    "\$fail('--base-url must be an https:// URL naming a host');",
    $code,
    'and refused by name — never silently rewritten to https, which would report a run against TLS nobody confirmed',
);
Assert::false(
    str_contains($code, "str_replace('http://', 'https://'"),
    'there is no scheme upgrade anywhere',
);
Assert::contains('CURLOPT_SSL_VERIFYPEER => true', $code, 'TLS peer verification is on');
Assert::contains('CURLOPT_SSL_VERIFYHOST => 2', $code, 'and host-name verification');
Assert::false(str_contains($code, 'CURLOPT_SSL_VERIFYPEER => false'), 'and neither is ever disabled');
Assert::contains('CURLOPT_PROTOCOLS_STR] =', $code, 'the transport is confined to https where the option exists');

// ============================================================ redirects are never followed

// A credential-bearing client that followed a redirect would present the deployment's cookies to
// whatever answered. Each step decides what a `Location` means; a cross-origin one fails the step.
Assert::contains('CURLOPT_FOLLOWLOCATION => false', $code, 'redirects are never followed automatically');
Assert::false(str_contains($code, 'CURLOPT_FOLLOWLOCATION => true'), 'and never enabled anywhere');
Assert::false(str_contains($code, 'CURLOPT_MAXREDIRS'), 'there is no redirect budget, because there is no redirect following');
Assert::contains('$sameOrigin = static function', $code, 'a redirect target is classified by origin');
Assert::contains(
    "str_starts_with(\$location, \$origin . '/')",
    $code,
    'and same-origin means this exact origin, not a host that merely starts the same way',
);

// ============================================================ no password reaches argv

Assert::contains(
    "BetaCli::parseArgs(\$argv, ['base-url', 'mode', 'email', 'password-stdin'], \$fail)",
    $code,
    'the client accepts a closed list of flags, none of which is a password',
);
foreach (BetaCli::REFUSED_SECRET_FLAGS as $refused) {
    Assert::false(
        str_contains($code, "\$args['{$refused}']"),
        "the client never reads --{$refused} from parsed arguments",
    );
}
Assert::same(
    2,
    substr_count($code, 'BetaCli::readPassword('),
    'both passwords are read through the one shared stdin reader',
);
Assert::contains('--password-stdin', $raw, 'and the usage documents the non-interactive route');
// BOTH LINES ARE READ BEFORE ANY REQUEST. A journey that needed a replacement password and
// discovered halfway through that it had none would leave the account mid-change.
Assert::true(
    strpos($code, "\$newPassword = BetaCli::readPassword(true,") < strpos($code, 'curl_init()'),
    'the replacement password is read before the first request is made',
);

// ============================================================ the step record is a closed set

$recordStart = (int) strpos($code, '$record = static function (');
$recordEnd = (int) strpos($code, '$decision = static function (');
$recordFn = substr($code, $recordStart, $recordEnd - $recordStart);

foreach ([
    "'step' => \$step,",
    "'method' => \$method,",
    "'path' => \$path,",
    "'status' => \$response['status'],",
    "'expected' => \$expected,",
    "'request_id' => RequestId::isWellFormed(\$response['request_id']) ? \$response['request_id'] : null,",
    "'content_type' => explode(';', \$response['content_type'])[0],",
    "'result' => \$ok ? 'PASS' : 'FAIL',",
    "'transport_error' => \$response['error'],",
] as $field) {
    Assert::contains($field, $recordFn, "the step record carries exactly: {$field}");
}
// THE BODY IS NOT AMONG THEM, and neither is anything that could carry one.
foreach (["\$response['body']", "'body' =>", 'Set-Cookie', '$cookies', '$password'] as $forbidden) {
    Assert::false(
        str_contains($recordFn, $forbidden),
        "no step record can carry {$forbidden}",
    );
}
// A REQUEST REFERENCE IS RECORDED ONLY IF IT IS ONE. An arbitrary header echoed back by something
// that is not this deployment would otherwise be reported as the deployment's own reference.
Assert::contains('RequestId::isWellFormed', $recordFn, 'the reference is shape-checked before it is recorded');

// ============================================================ the body is read, never reported

// Five uses, each named below. Anything else would be the point where a governed payload started
// reaching a terminal.
Assert::same(
    5,
    substr_count($code, "\$response['body']") + substr_count($code, "\$chooser['body']")
        + substr_count($code, "\$health['body']") + substr_count($code, "\$appShell['body']"),
    'the response body is used in exactly five places',
);
Assert::contains(
    "json_decode(\$response['body'], true, 8, JSON_THROW_ON_ERROR)",
    $code,
    'once to reach the reason codes of a governed read',
);
Assert::contains(
    "json_decode(\$health['body'], true, 8, JSON_THROW_ON_ERROR)",
    $code,
    'once to read the health claims, which are constants of this projection rather than tenant data',
);
Assert::contains(
    "preg_match('/name=\"workspace_id\" value=\"([0-9a-f-]{36})\"/', \$chooser['body'], \$m)",
    $code,
    'once to find the workspace identifier the chooser itself offers',
);
foreach ([
    "'body_bytes' => strlen(\$appShell['body']),",
    "'body_bytes' => strlen(\$response['body']),",
] as $byteCount) {
    // A SIZE, NOT CONTENT. On the private-path probes the byte count is the useful signal: a 403
    // with a body is an error page, and a 200 with a body would be the file itself.
    Assert::contains($byteCount, $code, "and twice to count bytes: {$byteCount}");
}
foreach (['echo $response', 'echo $body', 'print_r(', 'var_dump(', 'var_export('] as $forbidden) {
    Assert::false(str_contains($code, $forbidden), "the client never dumps a response: {$forbidden}");
}

// THE GOVERNED READ HELPER RETURNS DECISIONS, NEVER ROWS.
$decisionStart = (int) strpos($code, '$decision = static function (');
$decisionEnd = (int) strpos($code, '$sameOrigin = static function');
$decisionFn = substr($code, $decisionStart, $decisionEnd - $decisionStart);
Assert::contains(
    "'has_data' => array_key_exists('data', \$payload) && \$payload['data'] !== null && \$payload['data'] !== [],",
    $decisionFn,
    'it reports WHETHER there is data — the distinction between an owner denial and a valid empty read',
);
Assert::false(
    str_contains($decisionFn, "'data' => \$payload['data']") || str_contains($decisionFn, "return \$payload;"),
    'and never the data itself',
);
foreach (['briefings', 'rows', 'outputs'] as $dataKey) {
    Assert::false(str_contains($decisionFn, "'{$dataKey}'"), "nor any governed collection key: {$dataKey}");
}

// ============================================================ the cookie jar never touches disk

// A cookie jar on disk is a session token on disk. This process is short-lived by design and keeps
// its jar in memory.
foreach (['CURLOPT_COOKIEJAR', 'CURLOPT_COOKIEFILE', 'CURLOPT_COOKIESESSION'] as $absent) {
    Assert::false(str_contains($code, $absent), "no cookie persistence option is set: {$absent}");
}
Assert::contains('$cookies = [];', $code, 'the jar is an in-memory array');
Assert::contains('$options[CURLOPT_COOKIE] = implode(\'; \', $pairs);', $code, 'and is sent per request from memory');
Assert::false(str_contains($code, 'file_put_contents'), 'the client writes no file at all');
Assert::false(str_contains($code, 'error_log('), 'and no log line');

// ============================================================ every request is bounded

Assert::contains('CURLOPT_TIMEOUT => 20', $code, 'each request has a total time bound');
Assert::contains('CURLOPT_CONNECTTIMEOUT => 10', $code, 'and a connect bound');
Assert::same(1, substr_count($code, 'curl_exec('), 'every request goes through the one transport function');

// ============================================================ it records nothing and grants nothing

// A passing smoke run is evidence an operator may file. It is not the filing: that is a separate,
// reviewed edit to `deployment-evidence.json`, and this command cannot make it. The two governed
// files appear here for one reason only — they are private-tree paths the deployed host must
// refuse over HTTP — so the assertion is that each appears exactly once, in that list.
foreach (['deployment-evidence.json', 'upstream.lock.json'] as $governed) {
    Assert::same(
        1,
        substr_count($code, $governed),
        "{$governed} is named once, as a path the host must not serve",
    );
    Assert::contains("'/{$governed}',", $code, "and it is named as a probed path, not as a local file");
    Assert::false(
        str_contains($code, "file_get_contents(\$root . '/{$governed}')"),
        "the smoke client never reads the local {$governed}",
    );
}
Assert::false(str_contains($code, '$root'), 'the client resolves no repository path at all — it is a client, not a tool that reads the tree');
foreach ([
    "'records_evidence' => false",
    "'grants_runtime_authority' => false",
    "'activates_live_read' => false",
] as $stated) {
    Assert::contains($stated, $code, "the report states {$stated}");
}
Assert::false(str_contains($code, 'Database::'), 'the smoke client opens no database connection');
Assert::false(str_contains($code, 'CurlHttpsTransport'), 'and names no governed transport');
