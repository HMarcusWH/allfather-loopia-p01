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
// A SCHEME-RELATIVE URL BEGINS WITH A SLASH AND IS NOT A PATH. `//evil.example/path` resolves to a
// different host entirely, and a "starts with /" check reads it as local — which an earlier revision
// did, and which would have let a qualification run bless an open redirect.
Assert::contains(
    "str_starts_with(\$location, '//')",
    $code,
    'a scheme-relative location is rejected before the leading-slash shortcut can accept it',
);
Assert::true(
    strpos($code, "str_starts_with(\$location, '//')") < strpos($code, "str_starts_with(\$location, '/')"),
    'and rejected FIRST, or the shortcut would answer before the check ran',
);

// REPORTED IS NOT ENFORCED. Redirect origin is part of the pass predicate rather than a field beside
// it: cURL never follows a cross-origin Location, so this is not a credential leak — but a run that
// recorded PASS for a 303 to another host would be certifying the wrong deployment.
Assert::contains(
    "\$redirectStaysHere = \$response['location'] === null || \$sameOrigin(\$response['location']);",
    $code,
    'every response with a Location has that Location checked',
);
Assert::contains('&& $redirectStaysHere', $code, 'and the result gates the step rather than decorating it');
// The same shape for checks whose subject is not the status code. An authenticated context read
// answers 200 whether or not injected scope changed the decision, so the comparison must gate.
Assert::contains('bool $alsoRequires = true,', $code, 'a step can carry an extra predicate');
Assert::contains('&& $alsoRequires;', $code, 'which also gates the result');
Assert::contains(
    "[...\$injectedDecision, 'identical_to_unscoped_request' => \$identical],\n        \$identical,",
    $code,
    'and the scope-override comparison is passed as that predicate, not merely reported',
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

// Ten uses, each named below. Anything else would be the point where a governed payload started
// reaching a terminal.
Assert::same(
    10,
    substr_count($code, "\$response['body']") + substr_count($code, "\$chooser['body']")
        + substr_count($code, "\$health['body']") + substr_count($code, "\$appShell['body']")
        + substr_count($code, "\$asset['body']"),
    'the response body is used in exactly ten places',
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
    "'body_bytes' => strlen(\$asset['body']),",
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

// ============================================================ CSRF reads the LIVE cookie jar

// Gate N's authenticated failure on Loopia came from the qualification client itself: GET /login
// rotated the CSRF cookie, while an arrow-function accessor had already captured the initial empty
// array by value. The POST therefore paired the fresh cookie with a stale/empty form token. Lock the
// causal shape here, not merely the existence of a CSRF field.
Assert::contains(
    '$csrfToken = static function () use (&$cookies): string {',
    $code,
    'the CSRF accessor captures the in-memory jar BY REFERENCE, so a Set-Cookie mutation is observable',
);
Assert::false(
    str_contains($code, '$csrfToken = static fn'),
    'an arrow-function snapshot cannot return as the CSRF accessor',
);
$signInStart = (int) strpos($code, '$signIn = static function');
$signInEnd = (int) strpos($code, "if ($mode === 'beta-journey')");
$signInFn = substr($code, $signInStart, $signInEnd - $signInStart);
Assert::true(
    strpos($signInFn, "$send('GET', '/login');") < strpos($signInFn, "'csrf_token' => $csrfToken()"),
    'sign-in first lets /login rotate the jar and only then reads the current token for the POST',
);
Assert::false(
    str_contains($code, 'curl_close('),
    'the smoke client carries no PHP 8.5-deprecated curl_close() call',
);

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

// ============================================================ every response is bounded too

// THE TIMEOUT BOUNDS THE CLOCK AND NOTHING ELSE. A host streaming a body as fast as the link allows
// stays comfortably inside twenty seconds while this process accumulates every byte in memory —
// and this client makes dozens of requests against a host it is in the middle of deciding whether
// to trust. `CURLOPT_RETURNTRANSFER` with no write callback is exactly the unbounded read.
Assert::contains('const SMOKE_MAX_RESPONSE_BYTES = 2_000_000;', $code, 'there is a per-response byte ceiling');
Assert::contains('CURLOPT_RETURNTRANSFER => false', $code, 'the body is not accumulated by cURL into an unbounded buffer');
Assert::contains('CURLOPT_WRITEFUNCTION', $code, 'but by a write callback');
Assert::contains(
    '$room = SMOKE_MAX_RESPONSE_BYTES + 1 - strlen($body);',
    $code,
    'which keeps one byte of headroom, so an over-cap body is observed rather than truncated into a plausible one',
);
Assert::contains('if ($room <= 0) {', $code, 'and aborts the transfer once the cap is reached');
Assert::contains(
    '$body .= substr($chunk, 0, $room);',
    $code,
    'taking the overflow byte and stopping, rather than accepting the rest of the chunk',
);
// AND EXCEEDING IT FAILS THE STEP. A ceiling that only truncated would hand the caller a partial
// body to parse as though it were whole.
Assert::contains(
    '$oversized = strlen($body) > SMOKE_MAX_RESPONSE_BYTES;',
    $code,
    'the overflow is detected after the transfer',
);
Assert::contains(
    "\$oversized => 'response exceeded the ' . SMOKE_MAX_RESPONSE_BYTES . '-byte ceiling',",
    $code,
    'and reported as a transport error, which is what fails the step',
);

// ============================================================ a step is more than its status code

// `$record()` decides PASS from the expected status and the transport error. Several checks in this
// client have a subject that is not the status at all — an authenticated context read answers 200
// whether or not injected scope changed the decision — so those must be able to fail a step whose
// status was exactly what was expected.
Assert::contains('bool $alsoRequires = true,', $code, 'a step can carry an extra pass predicate');
Assert::contains('&& $alsoRequires;', $code, 'and it is part of the verdict, not a reported field');
Assert::contains(
    '&& $redirectStaysHere',
    $code,
    'a redirect that leaves this origin fails the step rather than being noted beside a pass',
);
// SCHEME-RELATIVE IS THE CASE THAT LOOKS SAME-ORIGIN. `//evil.example/path` begins with a slash.
Assert::contains(
    "if (str_starts_with(\$location, '//')) {",
    $code,
    'a scheme-relative Location is not mistaken for a path on this origin',
);

// ============================================================ /healthz is checked, not just reached

// This is the one surface that states which projection is running and that it is inert. A step that
// passed on the status alone would report SMOKE_PASS for a host answering 200 with the wrong
// projection or with `external_effect: true` — a deployment that is either not this one or not inert.
Assert::contains(
    "&& (\$healthClaims['projection'] ?? null) === 'LOOPIA_SME_MVP'",
    $code,
    'the health step requires the expected projection',
);
foreach (['customer_visible_output', 'external_effect'] as $claim) {
    Assert::contains(
        "&& (\$healthClaims['{$claim}'] ?? null) === false",
        $code,
        "and requires {$claim} to be identically false — a missing key decodes to null, and null == false",
    );
}
Assert::contains("\$healthClaims['envelope'] === 'JSON'", $code, 'and requires a JSON object at all');
Assert::contains('], $healthInert);', $code, 'and that predicate is passed to the step, not merely reported');

// ============================================================ the console shell is not the console

// A host can serve the document perfectly and answer 404, or the front controller's HTML, for the
// hashed bundle it references — the operator sees a blank page while the artifact says SMOKE_PASS.
Assert::contains(
    "\$asset = \$send('GET', \$assetPath);",
    $code,
    'the assets the shell references are actually fetched',
);
Assert::contains(
    "'#<script type=\"module\" src=\"(/assets/operator-console/console\\.[A-Z0-9]+\\.js)\"></script>#'",
    $code,
    'the script URL is taken from the shell document, matched against the content-hashed shape',
);
Assert::contains(
    "'#<link rel=\"stylesheet\" href=\"(/assets/operator-console/console\\.[A-Z0-9]+\\.css)\">#'",
    $code,
    'and so is the stylesheet — so a document naming anything else yields no match rather than sending this client after an arbitrary URL',
);
Assert::contains(
    "\$served = \$asset['status'] === 200",
    $code,
    'a fetched asset must answer 200',
);
Assert::contains(
    'in_array(strtolower($type), $acceptable, true)',
    $code,
    "and carry a media type a browser will execute — type=\"module\" refuses a script labelled text/plain",
);
Assert::contains(
    "\$looksLikeHtml = str_starts_with(ltrim(\$asset['body']), '<');",
    $code,
    'and must not be the front controller HTML a swallowing rewrite would return',
);
Assert::contains(
    '], $served, ',
    $code,
    'all of which is the step verdict rather than a note beside it',
);
// The asset bytes are never recorded — only their count and whether they open like a document.
Assert::false(str_contains($code, "'body' => \$asset"), 'the asset body is never put in a step record');

// ============================================================ a metric nobody can satisfy is broken

// NOT EVERY RESPONSE CAN CARRY `X-Request-ID`. Only the front controller stamps one:
// `public_html/scheduler.php` is a separate entry point that never runs the Kernel, and `assets/` is
// served by the web server without the application running at all. Counting those as faults meant a
// correctly configured deployment always produced a non-zero count, `SMOKE_FAIL`, and a Gate N that
// could not be passed. A check nobody can satisfy is not a strict check; the first thing it teaches
// an operator is to ignore the number.
Assert::contains(
    "string \$reference = 'REQUIRED',",
    $code,
    'each step declares whether its response can carry a request reference at all',
);
foreach ([
    ["\$record('scheduler endpoint refuses GET', 'GET', '/scheduler.php', \$schedulerGet, [405], [], true, 'NOT_APPLICABLE');",
        'the scheduler entry point is exempt — it never runs the Kernel'],
    ["], \$served, 'NOT_APPLICABLE');",
        'and so are the static assets, which the web server serves without the application'],
    ["], true, 'EITHER', \$isEnvPath ? LOOPIA_WAF_ROADBLOCK_HOST : null);",
        'a private path is either — the web server may refuse it before the application ever runs — '
        . 'and only /.env may instead carry the named foreign-redirect exception'],
] as [$needle, $why]) {
    Assert::contains($needle, $code, $why);
}
Assert::contains(
    "if (\$expectation === 'NOT_APPLICABLE') {",
    $code,
    'the exempt responses are not counted as missing',
);
Assert::contains(
    "'responses_exempt_from_request_reference' => \$referenceExempt,",
    $code,
    'and the exempt count is reported, so the zero cannot be read as "every response carried one"',
);

// ============================================================ a JSON 401 must be JSON

// The step is named for the contract it proves — a `fetch()` that receives a login page renders it
// into the console — and gating on the status alone did not enforce it. A host answering 401 with
// HTML passed the check that exists to catch exactly that.
Assert::contains(
    "\$anonIsJson = (\$anonDecision['envelope'] ?? null) === 'JSON'",
    $code,
    'the anonymous refusal must decode as a JSON envelope',
);
Assert::contains(
    "str_contains(strtolower(explode(';', \$anonContext['content_type'])[0]), 'json')",
    $code,
    'and be served with a JSON media type',
);
Assert::contains(
    "&& \$anonContext['location'] === null;",
    $code,
    'and must not be a redirect, which is the HTML sign-in path this step exists to exclude',
);
Assert::contains('], $anonIsJson);', $code, 'and all of that is the step verdict, not a field beside it');

// ============================================================ the Loopia WAF roadblock is a named, single-path exception

// REAL-HOST INCIDENT, 2026-09-18: `/.env` on staging.allfatherbi.com answered 302 to
// `https://roadblock.loopia.com/...` — Loopia's own WAF, refusing before this deployment is ever
// reached. That is a refusal and belongs in the accepted set, but ONLY for that one path and ONLY
// for that one host: `$record`'s general rule ("a redirect that leaves this origin fails the step")
// must still hold everywhere else, or a qualification run could bless an open redirect on any other
// private path by mistake.
Assert::contains(
    "?string \$allowedForeignRedirectHost = null,",
    $code,
    '$record takes an explicit, named exception for one foreign redirect host per step — nothing is accepted by default',
);
Assert::contains(
    "const LOOPIA_WAF_ROADBLOCK_HOST = 'roadblock.loopia.com';",
    $code,
    'the one accepted host is a named constant, not a string repeated at each use',
);
Assert::contains(
    "\$isEnvPath = \$private === '/.env';",
    $code,
    'the exception is scoped to exactly one probed path',
);
Assert::contains(
    "\$isEnvPath ? [403, 404, 302] : [403, 404]",
    $code,
    'every other private path still requires exactly 403 or 404 — 302 is only ever expected for /.env',
);
Assert::contains(
    "\$isEnvPath ? LOOPIA_WAF_ROADBLOCK_HOST : null",
    $code,
    'and the foreign-host allowance is passed only for /.env — every other call passes null',
);
// EXACT HOST EQUALITY, not a prefix. A prefix check would also accept
// `roadblock.loopia.com.evil.example`, a different host that merely starts the same way — the same
// mistake `$sameOrigin` above already guards against for this deployment's own origin.
Assert::contains(
    "(\$foreign['host'] ?? '') === \$allowedForeignRedirectHost",
    $code,
    'the foreign redirect target is checked by host EQUALITY, not by string-prefixing the Location',
);
Assert::contains(
    "(\$foreign['scheme'] ?? '') === 'https'",
    $code,
    'and the scheme is checked too — a plaintext redirect claiming that host is not the same guarantee',
);
Assert::false(
    str_contains($code, "str_starts_with(\$response['location'], 'https://" . 'roadblock.loopia.com'),
    'the exception is never implemented as a Location prefix check',
);
// THE EXCEPTION ONLY WIDENS ONE STEP'S VERDICT WHEN THE CALLER ASKS FOR IT — the default parameter
// is null, so every OTHER `$record` call in this file (there are many) is unaffected and a redirect
// leaving the origin still fails those steps exactly as before.
Assert::same(
    2,
    substr_count($code, 'LOOPIA_WAF_ROADBLOCK_HOST'),
    // Declared once, referenced once at the single call site that may pass it — a third occurrence
    // would mean a second path had quietly been granted the same allowance.
    'the roadblock host constant is declared once and referenced exactly once',
);

// The private-path probe covers more than the paths one incident happened to be checked with — one
// representative path per private directory named in `tests/foundation/deployment_boundary.php`,
// plus a sensitive root filename beyond the two already asserted above.
foreach ([
    '/docs/SECURITY_BOUNDARY.md', '/BOOTSTRAP.md', '/PROJECT_BOUNDARY.md', '/UPSTREAM_AUTHORITY.md', '/README.md',
] as $expanded) {
    Assert::contains("'{$expanded}',", $code, "the private-path probe covers {$expanded}, not only the originally reported paths");
}
