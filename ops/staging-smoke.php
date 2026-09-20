<?php

declare(strict_types=1);

/**
 * Staging smoke client — the deployed site exercised over real HTTPS, from outside.
 *
 * WHAT THIS IS FOR, and why the test suite does not already cover it. The suite drives the Kernel
 * in-process: it proves the application's decisions. It cannot prove that Apache honoured a single
 * `.htaccess` directive, that the document root points where the runbook says, that TLS terminates,
 * that the private tree is unreachable over HTTP, or that the staged bundle the browser receives is
 * the one that was built. Every `<IfModule>` guard in this repository is a silent no-op on a host
 * missing the module, so those facts are established here or not at all.
 *
 * NOT AN APPLICATION ENDPOINT. It is a client. It runs from an operator's machine, talks to a URL
 * they supply, and has no route, no session of its own and no presence in the deployment.
 *
 * SECRET DISCIPLINE, inherited from PR7 and tightened for a tool whose output is meant to be pasted:
 *
 *   A PASSWORD NEVER REACHES ARGV. `--password` and its synonyms are refused BY NAME by the shared
 *   parser, because anything on argv is in `ps` and in the shell history file. Passwords arrive on
 *   stdin, one per line, and go straight into a request body.
 *
 *   NOTHING SENSITIVE IS EVER PRINTED. Not a password, not a cookie, not a `Set-Cookie`, not a CSRF
 *   token, not a session token, not a response body, not one field of governed product data. What
 *   the report carries is a step name, a method and path, a status code, the status expected, the
 *   opaque request reference, and a reason code. That list is closed, and `record()` is the only
 *   thing that writes to it.
 *
 *   GOVERNED DATA IS NEVER READ. The product endpoints are exercised for their ACCESS DECISION —
 *   200 versus 403 versus 409 — and the payload is discarded without being parsed for content. A
 *   smoke test that printed a battlecard would be a smoke test that exported a tenant's
 *   intelligence to whoever reads the terminal.
 *
 *   HTTPS ONLY, AND NO CROSS-ORIGIN REDIRECT. Plain HTTP is refused rather than upgraded, and
 *   redirects are never followed automatically — each step decides what to do with a `Location`,
 *   and a `Location` pointing at another origin fails the step instead of being fetched.
 */

// CLI ONLY, refused BEFORE anything is loaded. This command holds credentials in memory and speaks
// to a live deployment; it must never be something a web request can start.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\RequestId;
use Allfather\Loopia\Ops\BetaCli;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'staging-smoke: ' . $message . PHP_EOL);
    exit($code);
};

/**
 * Usage:
 *   php ops/staging-smoke.php --base-url=https://staging.allfatherbi.com --mode=anonymous
 *
 *   read -rs PW; printf '%s\n' "$PW" | php ops/staging-smoke.php \
 *     --base-url=https://staging.allfatherbi.com --mode=authenticated \
 *     --email=partner@example.test --password-stdin
 *
 *   # beta-journey reads TWO lines: the operator-issued password, then the replacement.
 *   read -rs PW; read -rs NEW; printf '%s\n%s\n' "$PW" "$NEW" | php ops/staging-smoke.php \
 *     --base-url=https://staging.allfatherbi.com --mode=beta-journey \
 *     --email=partner@example.test --password-stdin
 *
 * The base URL is an ARGUMENT rather than a constant. Hardcoding `staging.allfatherbi.com` would
 * make the tool unusable against the host it is being qualified on before DNS is pointed, and would
 * invite somebody to edit the constant rather than pass the flag.
 */
$modes = ['anonymous', 'authenticated', 'beta-journey'];
$args = BetaCli::parseArgs($argv, ['base-url', 'mode', 'email', 'password-stdin'], $fail);

$mode = $args['mode'] ?? '';
if (!in_array($mode, $modes, true)) {
    $fail('--mode is required and must be one of: ' . implode(', ', $modes));
}

$baseUrl = rtrim($args['base-url'] ?? '', '/');
$parts = $baseUrl === '' ? false : parse_url($baseUrl);
if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
    // REFUSED, NOT UPGRADED. A tool that silently rewrote http:// to https:// would report a
    // successful run against a deployment whose TLS was never confirmed.
    $fail('--base-url must be an https:// URL naming a host');
}
if (isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
    $fail('--base-url must be a bare origin: no path, query or fragment');
}
$origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

$email = $args['email'] ?? '';
$password = '';
$newPassword = '';
if ($mode !== 'anonymous') {
    if ($email === '' || $email === '1') {
        $fail('--email is required for this mode');
    }
    $password = BetaCli::readPassword(isset($args['password-stdin']), 'sign in', $fail);
    if ($mode === 'beta-journey') {
        // The SECOND line of the same stdin. Reading both here, before any request, means a
        // journey that is going to need a replacement password cannot get halfway through and then
        // discover it has nothing to change the password to.
        $newPassword = BetaCli::readPassword(true, 'replace the issued password', $fail);
        if ($newPassword === $password) {
            $fail('the replacement password is the same as the issued one');
        }
    }
}

if (!extension_loaded('curl')) {
    $fail('the curl extension is required to reach a deployed site', 3);
}

// ---------------------------------------------------------------------------- the client
//
// An in-memory cookie jar. No file, because a cookie jar on disk is a session token on disk, and
// this process is short-lived by design.

/** @var array<string, string> $cookies */
$cookies = [];
/** @var list<array<string, mixed>> $steps */
$steps = [];
$failures = 0;

/**
 * The per-response byte ceiling.
 *
 * WITHOUT ONE, A MISCONFIGURED OR COMPROMISED ENDPOINT CAN EXHAUST THIS PROCESS. `CURLOPT_TIMEOUT`
 * bounds the wall clock and nothing else: a host streaming a body as fast as the link allows stays
 * comfortably inside twenty seconds while this command accumulates every byte in memory. The
 * governed transport caps its own reads for the same reason, and this client makes dozens of
 * requests — every governed read, every private-path probe — against a host it is in the middle of
 * deciding whether to trust.
 *
 * Two megabytes is far above anything this deployment serves: the shell document is a few hundred
 * bytes, and governed reads are bounded JSON. A response over the cap is a finding about the host,
 * so it aborts the transfer and fails that step rather than being silently truncated into a body
 * the caller would then parse as if it were whole.
 */
const SMOKE_MAX_RESPONSE_BYTES = 2_000_000;

/**
 * One request. Returns status, headers of interest, and the body — which the CALLER may inspect but
 * must never put into a step record.
 *
 * @param array<string, string> $form
 * @return array{status: int, location: ?string, request_id: string, content_type: string, body: string, error: ?string, oversized: bool}
 */
$send = static function (string $method, string $path, array $form = []) use ($origin, &$cookies, $fail): array {
    $handle = curl_init();
    $headerLines = [];
    $body = '';
    $options = [
        CURLOPT_URL => $origin . $path,
        // The body is accumulated by the write callback below, under a ceiling, rather than by
        // cURL into an unbounded buffer.
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($_handle, string $chunk) use (&$body): int {
            // One byte of headroom, so an exactly-at-cap body is distinguishable from an over-cap
            // one and the overflow is OBSERVED rather than truncated away.
            $room = SMOKE_MAX_RESPONSE_BYTES + 1 - strlen($body);
            if ($room <= 0) {
                return 0; // a short write aborts the transfer
            }
            if (strlen($chunk) >= $room) {
                $body .= substr($chunk, 0, $room);

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
        // NEVER automatically. Each step decides what a redirect means, and a Location pointing at
        // another origin must fail rather than be fetched — a credential-bearing client that
        // followed one would present its cookies to whatever answered.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'allfather-staging-smoke/1.0',
        CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headerLines): int {
            $headerLines[] = $line;

            return strlen($line);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'https';
    } elseif (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $options[CURLOPT_COOKIE] = implode('; ', $pairs);
    }
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
    } elseif ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($handle, $options);

    $executed = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $oversized = strlen($body) > SMOKE_MAX_RESPONSE_BYTES;
    // THE CAP IS REPORTED AS ITSELF. Aborting the write makes cURL return a generic write error,
    // and a step that failed with "Failed writing body" would send an operator looking at their own
    // disk. The message is a fixed sentence composed here: it names no header, no body and no host.
    $error = match (true) {
        $oversized => 'response exceeded the ' . SMOKE_MAX_RESPONSE_BYTES . '-byte ceiling',
        $executed === false => curl_error($handle),
        default => null,
    };
    // PHP 8+ CurlHandle objects release their resource when they leave scope. Calling
    // curl_close() is a no-op there and is deprecated in PHP 8.5, so do not reintroduce it.

    $location = null;
    $requestId = '';
    $contentType = '';
    foreach ($headerLines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        if ($name === 'location') {
            $location = $value;
        } elseif ($name === 'content-type') {
            $contentType = $value;
        } elseif ($name === strtolower(RequestId::HEADER)) {
            $requestId = $value;
        } elseif ($name === 'set-cookie') {
            // PARSED, NEVER RECORDED. The jar lives in this process and nothing writes it out.
            $pair = explode(';', $value, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $cookieName = trim(substr($pair, 0, $eq));
                $cookieValue = trim(substr($pair, $eq + 1));
                if ($cookieValue === '') {
                    unset($cookies[$cookieName]);
                } else {
                    $cookies[$cookieName] = $cookieValue;
                }
            }
        }
    }

    return [
        'status' => $status,
        'location' => $location,
        'request_id' => $requestId,
        'content_type' => $contentType,
        'body' => $body,
        'error' => $error === null ? null : substr($error, 0, 120),
        'oversized' => $oversized,
    ];
};

/**
 * Whether a redirect target stays on the deployment under test.
 *
 * `//evil.example/path` IS THE CASE THIS EXISTS FOR. A scheme-relative URL begins with `/`, so a
 * naive "starts with a slash means it is a path" check reads it as same-origin when a browser would
 * resolve it to a different host entirely. An earlier revision did exactly that, which would have
 * let a qualification run bless an open redirect.
 */
$sameOrigin = static function (?string $location) use ($origin): bool {
    if ($location === null || $location === '') {
        return false;
    }
    if (str_starts_with($location, '//')) {
        // Scheme-relative: the authority is whatever follows, not this origin.
        return false;
    }
    if (str_starts_with($location, '/')) {
        return true;
    }

    return str_starts_with($location, $origin . '/') || $location === $origin;
};

/**
 * THE ONLY THING THAT WRITES A STEP RECORD, so there is one place to audit for what this command
 * can emit. Every field is a status, a path, an opaque reference or a reason code — never a body,
 * never a cookie, never a credential.
 *
 * A REDIRECT THAT LEAVES THIS ORIGIN FAILS THE STEP, here rather than at each call site. An earlier
 * revision recorded `location_same_origin` as a reportable field, so a 303 to `https://evil.example/`
 * could be PASS and still contribute to `SMOKE_PASS`. cURL never follows it, so this was not a
 * credential leak — but a qualification run that blessed an open redirect is a qualification run
 * that certified the wrong thing.
 *
 * `$alsoRequires` is for the checks whose subject is not the status code: a step may answer exactly
 * the expected status and still have failed, and the scope-override probe is precisely that shape.
 *
 * `$reference` says whether THIS response can carry `X-Request-ID` at all. Only the front
 * controller stamps it — `public_html/scheduler.php` is a separate entry point with its own
 * responder, and a static asset is served by the web server without the application running. A run
 * that demanded one everywhere counted those as faults and could never reach SMOKE_PASS on a
 * correctly configured host, which made Gate N unpassable rather than strict.
 *
 * `$allowedForeignRedirectHost` is a NAMED, SINGLE-STEP exception to "leaves this origin fails the
 * step" — nothing else in this client may redirect cross-origin. It exists for one case: Loopia's
 * own WAF answers `/.env` with a 302 to a host of its own, `roadblock.loopia.com`, before this
 * deployment's web server is ever reached. The comparison is host EQUALITY under HTTPS, decided
 * here rather than by string-prefixing the caller's `Location` — a prefix check would also accept
 * `roadblock.loopia.com.evil.example`, a different host that merely starts the same way, which is
 * the same mistake `$sameOrigin` above exists to rule out for this origin. Passing anything here
 * for any other step, or a redirect to any other host, still fails.
 *
 * @param list<int> $expected
 * @param array<string, mixed> $safe
 * @param 'REQUIRED'|'NOT_APPLICABLE'|'EITHER' $reference
 */
$record = static function (
    string $step,
    string $method,
    string $path,
    array $response,
    array $expected,
    array $safe = [],
    bool $alsoRequires = true,
    string $reference = 'REQUIRED',
    ?string $allowedForeignRedirectHost = null,
) use (&$steps, &$failures, $sameOrigin): bool {
    $redirectStaysHere = $response['location'] === null || $sameOrigin($response['location']);
    if (!$redirectStaysHere && $allowedForeignRedirectHost !== null && $response['location'] !== null) {
        $foreign = parse_url($response['location']);
        $redirectStaysHere = is_array($foreign)
            && ($foreign['scheme'] ?? '') === 'https'
            && ($foreign['host'] ?? '') === $allowedForeignRedirectHost
            && (!isset($foreign['port']) || (int) $foreign['port'] === 443);
    }
    $ok = in_array($response['status'], $expected, true)
        && $response['error'] === null
        && $redirectStaysHere
        && $alsoRequires;
    $steps[] = [
        'step' => $step,
        'method' => $method,
        'path' => $path,
        'status' => $response['status'],
        'expected' => $expected,
        'request_id' => RequestId::isWellFormed($response['request_id']) ? $response['request_id'] : null,
        'content_type' => explode(';', $response['content_type'])[0],
        'redirect_stays_on_origin' => $redirectStaysHere,
        'request_reference' => $reference,
        ...$safe,
        'result' => $ok ? 'PASS' : 'FAIL',
        'transport_error' => $response['error'],
    ];
    if (!$ok) {
        $failures++;
    }

    return $ok;
};

/**
 * A governed read's ACCESS DECISION, and nothing else.
 *
 * The body is decoded to reach the reason codes, which are the thing a smoke test is for, and the
 * `data` key is never touched. A helper that returned the payload would be the point where a
 * tenant's intelligence started reaching a terminal.
 *
 * @param array{body: string} $response
 * @return array<string, mixed>
 */
$decision = static function (array $response): array {
    try {
        $payload = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return ['envelope' => 'NOT_JSON'];
    }
    if (!is_array($payload)) {
        return ['envelope' => 'NOT_AN_OBJECT'];
    }

    return [
        'envelope' => 'JSON',
        'envelope_status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
        'access_decision' => is_string($payload['access']['decision'] ?? null) ? $payload['access']['decision'] : null,
        'owner_state' => is_string($payload['owner_state'] ?? null) ? $payload['owner_state'] : null,
        'reason_codes' => array_values(array_map('strval', (array) ($payload['reason_codes'] ?? []))),
        // WHETHER there is data, never what it is. The distinction between an owner denial and a
        // valid empty read is exactly what a smoke test needs to see, and neither requires a row.
        'has_data' => array_key_exists('data', $payload) && $payload['data'] !== null && $payload['data'] !== [],
    ];
};

$readPaths = [
    '/console/api/v1/context',
    '/console/api/v1/p01/battlecards',
    '/console/api/v1/p02/entities',
    '/console/api/v1/p02/relationships',
    '/console/api/v1/p03/priority-queue',
    '/console/api/v1/p03/monitored',
    '/console/api/v1/p04/distributions',
    '/console/api/v1/p05/drafts',
    '/console/api/v1/m10/decisions',
];

// ============================================================ anonymous, always

// THE CLAIMS ARE THE CHECK, not decoration beside a 200.
//
// `/healthz` is the one surface that states, in the deployment's own words, WHICH projection is
// running and that it produces no customer-visible output and takes no external action. A step that
// passed on the status code alone would record `SMOKE_PASS` for a host answering 200 with the wrong
// projection, with `external_effect: true`, or with HTML — which is to say for a deployment that is
// either not this one or not inert. Gate N quotes this result; it has to mean what it says.
$health = $send('GET', '/healthz');
$healthClaims = ['envelope' => 'NOT_JSON', 'projection' => null];
try {
    $decoded = json_decode($health['body'], true, 8, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) {
        $healthClaims = [
            'envelope' => 'JSON',
            'projection' => $decoded['projection'] ?? null,
            'customer_visible_output' => $decoded['customer_visible_output'] ?? null,
            'external_effect' => $decoded['external_effect'] ?? null,
        ];
    } else {
        $healthClaims = ['envelope' => 'NOT_AN_OBJECT', 'projection' => null];
    }
} catch (\JsonException) {
    // Left as NOT_JSON, which fails the step below rather than being reported beside a pass.
}
// IDENTICAL COMPARISONS, not truthiness: `customer_visible_output` absent decodes to null, and
// `null == false` in PHP. A deployment that had dropped the key would otherwise pass the check that
// exists to prove the key is there and false.
$healthInert = $healthClaims['envelope'] === 'JSON'
    && ($healthClaims['projection'] ?? null) === 'LOOPIA_SME_MVP'
    && ($healthClaims['customer_visible_output'] ?? null) === false
    && ($healthClaims['external_effect'] ?? null) === false;
$record('health', 'GET', '/healthz', $health, [200], [
    ...$healthClaims,
    'projection_and_inertness_as_expected' => $healthInert,
], $healthInert);

$shell = $send('GET', '/console/app');
$record('anonymous console shell redirects to sign-in', 'GET', '/console/app', $shell, [303], [
    'location' => $shell['location'],
    'location_same_origin' => $sameOrigin($shell['location']),
]);

// THE DISTINCTION THAT MATTERS MOST for a browser client: an anonymous JSON read must answer JSON
// 401, not an HTML redirect. A fetch() that received a login page would render it into the console.
$anonContext = $send('GET', '/console/api/v1/context');
// THE STEP'S NAME IS ITS PREDICATE, and gating on the status alone did not enforce it. A host
// answering 401 with an HTML error page passed this check while breaking exactly the contract it
// exists to prove: a `fetch()` that receives a login page renders it into the console. The JSON
// envelope, a JSON content type and the absence of a redirect are all part of the verdict.
$anonDecision = $decision($anonContext);
$anonIsJson = ($anonDecision['envelope'] ?? null) === 'JSON'
    && str_contains(strtolower(explode(';', $anonContext['content_type'])[0]), 'json')
    && $anonContext['location'] === null;
$record('anonymous JSON read is a JSON 401', 'GET', '/console/api/v1/context', $anonContext, [401], [
    ...$anonDecision,
    'is_html_redirect' => $anonContext['location'] !== null,
    'json_envelope_and_type' => $anonIsJson,
], $anonIsJson);

$login = $send('GET', '/login');
$record('sign-in page', 'GET', '/login', $login, [200]);

$notFound = $send('GET', '/there-is-no-such-surface');
$record('unknown path', 'GET', '/there-is-no-such-surface', $notFound, [404]);

$unverified = $send('POST', '/login', ['email' => 'nobody@example.test', 'password' => 'not-a-password']);
$record('POST without a CSRF token is refused', 'POST', '/login', $unverified, [403]);

// The scheduler door is POST-only, and an unconfigured credential makes it unreachable rather than
// open. A GET must be 405 — never 200, and never a 404 that would hide a misconfigured schedule.
$schedulerGet = $send('GET', '/scheduler.php');
// NO REFERENCE EXPECTED. `public_html/scheduler.php` is a separate entry point: it never builds a
// Request and never runs the Kernel, so it cannot stamp `X-Request-ID`. Demanding one here would
// fail a correctly configured deployment for a header that entry point has never sent.
$record('scheduler endpoint refuses GET', 'GET', '/scheduler.php', $schedulerGet, [405], [], true, 'NOT_APPLICABLE');

// Loopia's own WAF, ahead of this deployment's web server, answers `/.env` with a redirect to a
// host of its own rather than letting the request reach `public_html/`. That is a REFUSAL — the
// secret is not served — and is accepted here for exactly that one path and exactly that one host.
const LOOPIA_WAF_ROADBLOCK_HOST = 'roadblock.loopia.com';

// THE PRIVATE TREE, over HTTP. This is the half `tests/foundation/deployment_boundary.php` cannot
// reach: it proves the layout, and this proves the host honours it. Every private directory named
// there has at least one path probed here, plus a representative sensitive root filename — not only
// the paths a prior incident happened to be checked with.
foreach ([
    '/.env',
    '/app/autoload.php',
    '/database/migrations/001_identity_scope.sql',
    '/storage/request.log',
    '/worker/tick.php',
    '/ops/migrate.php',
    '/frontend/console/package.json',
    '/tests/run.php',
    '/docs/SECURITY_BOUNDARY.md',
    '/composer.json',
    '/upstream.lock.json',
    '/deployment-evidence.json',
    '/BOOTSTRAP.md',
    '/PROJECT_BOUNDARY.md',
    '/UPSTREAM_AUTHORITY.md',
    '/README.md',
] as $private) {
    $response = $send('GET', $private);
    // `/.env` ALONE may also answer 302 to Loopia's named roadblock host — its own WAF, refusing
    // before this deployment is reached at all. No other path gets this allowance: a 302 anywhere
    // else, or a 302 on `/.env` to any other host, is an arbitrary redirect and must still fail.
    $isEnvPath = $private === '/.env';
    $record('private path is not served', 'GET', $private, $response, $isEnvPath ? [403, 404, 302] : [403, 404], [
        'body_bytes' => strlen($response['body']),
    ], true, 'EITHER', $isEnvPath ? LOOPIA_WAF_ROADBLOCK_HOST : null);
}

// ============================================================ authenticated modes

// LOGIN ROTATES THE CSRF COOKIE. This accessor must observe the jar AFTER the GET /login
// response has updated it. An arrow function captures $cookies by value and snapshots the empty jar,
// which makes the following POST submit a stale token even though the live cookie is correct.
$csrfToken = static function () use (&$cookies): string {
    return $cookies[Csrf::COOKIE_NAME] ?? '';
};

/** Sign in through the front door. Returns the response; the cookie jar updates itself. */
$signIn = static function (string $withPassword) use ($send, $csrfToken, $email): array {
    $send('GET', '/login');

    return $send('POST', '/login', [
        'csrf_token' => $csrfToken(),
        'email' => $email,
        'password' => $withPassword,
    ]);
};

if ($mode === 'beta-journey') {
    $first = $signIn($password);
    $firstLandsOnPassword = $first['location'] === '/account/password';
    $record('first sign-in lands on the password surface', 'POST', '/login', $first, [303], [
        'location' => $first['location'],
        'lands_on_password_surface' => $firstLandsOnPassword,
    ], $firstLandsOnPassword);

    $gatedShell = $send('GET', '/console/app');
    $shellGatedToPassword = $gatedShell['location'] === '/account/password';
    $record('the console is gated before the password changes', 'GET', '/console/app', $gatedShell, [303], [
        'location' => $gatedShell['location'],
        'gated_to_password_surface' => $shellGatedToPassword,
    ], $shellGatedToPassword);

    $oldSessionToken = $cookies['af_session'] ?? '';
    $oldSessionPresent = $oldSessionToken !== '';
    $gatedRead = $send('GET', '/console/api/v1/context');
    $gatedDecision = $decision($gatedRead);
    $readGatedByPassword = ($gatedDecision['envelope_status'] ?? null) === 'PASSWORD_CHANGE_REQUIRED'
        && in_array('PASSWORD_CHANGE_REQUIRED', $gatedDecision['reason_codes'] ?? [], true);
    $record('and so is every governed read', 'GET', '/console/api/v1/context', $gatedRead, [403], [
        ...$gatedDecision,
        'password_change_gate' => $readGatedByPassword,
        'session_cookie_present' => $oldSessionPresent,
    ], $readGatedByPassword && $oldSessionPresent);

    $passwordForm = $send('GET', '/account/password');
    $record('the password surface itself is reachable', 'GET', '/account/password', $passwordForm, [200]);

    $changed = $send('POST', '/account/password', [
        'csrf_token' => $csrfToken(),
        'current_password' => $password,
        'new_password' => $newPassword,
        'confirm_password' => $newPassword,
    ]);
    $changedReturnsToLogin = $changed['location'] === '/login?changed=1';
    $record('the password change succeeds', 'POST', '/account/password', $changed, [303], [
        'location' => $changed['location'],
        'returns_to_login' => $changedReturnsToLogin,
    ], $changedReturnsToLogin);

    // EVERY SESSION ENDED, including the one that made the change. Do not prove only that the
    // response cleared the browser cookie: deliberately replay the exact OLD session token in memory
    // and require the server to refuse it. The token is never printed, recorded or written to disk.
    $cookies = ['af_session' => $oldSessionToken];
    $afterChange = $send('GET', '/console/api/v1/context');
    $afterChangeDecision = $decision($afterChange);
    $afterChangeIsJson = ($afterChangeDecision['envelope'] ?? null) === 'JSON'
        && str_contains(strtolower(explode(';', $afterChange['content_type'])[0]), 'json');
    $record('the old session is gone', 'GET', '/console/api/v1/context', $afterChange, [401], [
        ...$afterChangeDecision,
        'json_envelope_and_type' => $afterChangeIsJson,
    ], $afterChangeIsJson);

    $cookies = [];
    $password = $newPassword;
}

if ($mode !== 'anonymous') {
    $signedIn = $signIn($password);
    $signedInToConsole = $signedIn['location'] === '/console';
    $record('sign-in', 'POST', '/login', $signedIn, [303], [
        'location' => $signedIn['location'],
        'lands_on_console' => $signedInToConsole,
    ], $signedInToConsole);

    // EVERY fresh session starts with active_workspace_id = NULL. The login controller redirects to
    // /console, not to the chooser, so waiting for a /console/workspaces Location can never select
    // anything. Gate N must exercise the chooser explicitly or every governed read may return the
    // accepted 409 NO_ACTIVE_WORKSPACE and the smoke artifact can pass without testing a workspace.
    $chooser = $send('GET', '/console/workspaces');
    $record('workspace chooser', 'GET', '/console/workspaces', $chooser, [200]);
    if (preg_match('/name="workspace_id" value="([0-9a-f-]{36})"/', $chooser['body'], $m) === 1) {
        $selected = $send('POST', '/console/workspace', [
            'csrf_token' => $csrfToken(),
            'workspace_id' => $m[1],
        ]);
        $selectionReturnsToConsole = $selected['location'] === '/console';
        $record('workspace selected', 'POST', '/console/workspace', $selected, [303], [
            'location' => $selected['location'],
            'returns_to_console' => $selectionReturnsToConsole,
        ], $selectionReturnsToConsole);

        // Prove the POST changed session state. Product-specific reads may still legitimately deny,
        // but NO_ACTIVE_WORKSPACE is no longer an acceptable answer after a successful selection.
        $selectedContext = $send('GET', '/console/api/v1/context');
        $selectedDecision = $decision($selectedContext);
        $selectedContextIsJson = ($selectedDecision['envelope'] ?? null) === 'JSON'
            && str_contains(strtolower(explode(';', $selectedContext['content_type'])[0]), 'json');
        $workspaceIsActive = $selectedContextIsJson
            && !in_array('NO_ACTIVE_WORKSPACE', $selectedDecision['reason_codes'] ?? [], true);
        $record('workspace selection becomes session state', 'GET', '/console/api/v1/context', $selectedContext, [200, 403], [
            ...$selectedDecision,
            'json_envelope_and_type' => $selectedContextIsJson,
            'workspace_active' => $workspaceIsActive,
        ], $workspaceIsActive);
    } else {
        $record('workspace chooser offers a workspace', 'GET', '/console/workspaces', [
            'status' => 0, 'location' => null, 'request_id' => '', 'content_type' => '',
            'body' => '', 'error' => 'no selectable workspace was offered', 'oversized' => false,
        ], [200]);
    }

    $appShell = $send('GET', '/console/app');
    $record('the React shell is served', 'GET', '/console/app', $appShell, [200], [
        'body_bytes' => strlen($appShell['body']),
    ]);

    // THE SHELL IS NOT THE CONSOLE. A host can serve the document perfectly and answer 404, or the
    // front controller's HTML, for the hashed bundle it references — and the operator sees a blank
    // page while the smoke artifact says SMOKE_PASS. Asset delivery is one of the things this client
    // exists to establish on a host, and until now it counted the shell's bytes and stopped.
    //
    // The URLs come from the SHELL DOCUMENT rather than from the manifest on disk, because what
    // matters is what a browser will actually request. The patterns are the same content-hashed
    // shapes `ConsoleAssetManifest` enforces when it composes them, so a document naming anything
    // else yields no match and fails the step rather than sending this client after an arbitrary
    // URL it found in a response body.
    $assetTargets = [];
    if (preg_match(
        '#<link rel="stylesheet" href="(/assets/operator-console/console\.[A-Z0-9]+\.css)">#',
        $appShell['body'],
        $m,
    ) === 1) {
        $assetTargets['stylesheet'] = [$m[1], ['text/css']];
    }
    if (preg_match(
        '#<script type="module" src="(/assets/operator-console/console\.[A-Z0-9]+\.js)"></script>#',
        $appShell['body'],
        $m,
    ) === 1) {
        $assetTargets['script'] = [$m[1], ['text/javascript', 'application/javascript', 'application/x-javascript']];
    }
    foreach (['stylesheet', 'script'] as $kind) {
        if (!isset($assetTargets[$kind])) {
            // A shell that names no hashed asset of this kind is a broken console, reported as the
            // step it is rather than skipped into silence.
            $record("the console {$kind} is named by the shell", 'GET', '/console/app', [
                'status' => $appShell['status'], 'location' => null, 'request_id' => '',
                'content_type' => '', 'body' => '', 'oversized' => false,
                'error' => "the shell document names no content-hashed {$kind}",
            ], [200]);
            continue;
        }
        [$assetPath, $acceptable] = $assetTargets[$kind];
        $asset = $send('GET', $assetPath);
        $type = explode(';', $asset['content_type'])[0];
        // `type="module"` means a browser REFUSES a script the host labels text/plain, so the media
        // type is part of the check, not a note beside it. And a host whose rewrite rules swallow
        // the asset answers 200 with the front controller's HTML — hence the opening-angle check:
        // the bytes are never recorded, only whether they begin like a document.
        $looksLikeHtml = str_starts_with(ltrim($asset['body']), '<');
        $served = $asset['status'] === 200
            && in_array(strtolower($type), $acceptable, true)
            && $asset['body'] !== ''
            && !$looksLikeHtml;
        // NO REFERENCE EXPECTED: `.htaccess` serves `assets/` as itself, so the application never
        // runs for these and no header is stamped. That is the intended configuration — a static
        // asset routed through the front controller would be the misconfiguration.
        $record("the console {$kind} is delivered", 'GET', $assetPath, $asset, [200], [
            'media_type_acceptable' => in_array(strtolower($type), $acceptable, true),
            'body_bytes' => strlen($asset['body']),
            'looks_like_html' => $looksLikeHtml,
        ], $served, 'NOT_APPLICABLE');
    }

    foreach ($readPaths as $path) {
        $response = $send('GET', $path);
        // After workspace selection, 200, 403 and 503 are valid access/availability answers. A 409
        // is specifically NO_ACTIVE_WORKSPACE in this API and would contradict the selection proof
        // above. The RESPONSE CONTRACT is not optional either: every /console/api/ route is JSON.
        $readDecision = $decision($response);
        $readIsJson = ($readDecision['envelope'] ?? null) === 'JSON'
            && str_contains(strtolower(explode(';', $response['content_type'])[0]), 'json')
            && $response['location'] === null;
        $record('governed read', 'GET', $path, $response, [200, 403, 503], [
            ...$readDecision,
            'json_envelope_and_type' => $readIsJson,
        ], $readIsJson);
    }

    // THE SCOPE-OVERRIDE ATTEMPT. The browser must never be able to choose its own tenant,
    // workspace, operator or M10 environment by query parameter. The same read is issued with all
    // four present and its access decision must be identical.
    $plain = $send('GET', '/console/api/v1/context');
    $injected = $send(
        'GET',
        '/console/api/v1/context?tenant_id=00000000-0000-4000-8000-000000000000'
        . '&workspace_id=00000000-0000-4000-8000-000000000001'
        . '&operator_id=00000000-0000-4000-8000-000000000002'
        . '&environment_class=DEMO',
    );
    $plainDecision = $decision($plain);
    $injectedDecision = $decision($injected);
    // THE COMPARISON IS THE STEP, not a field beside it. An authenticated context read answers 200
    // whether or not the injected scope changed the decision, so gating on the status alone would
    // let the artifact report SMOKE_PASS while explicitly stating that the scope-override check
    // failed. It is passed as the extra predicate so a difference fails the run.
    $identical = ($plainDecision['envelope'] ?? null) === 'JSON'
        && ($injectedDecision['envelope'] ?? null) === 'JSON'
        && $plain['status'] === $injected['status']
        && ($plainDecision['access_decision'] ?? null) === ($injectedDecision['access_decision'] ?? null)
        && ($plainDecision['owner_state'] ?? null) === ($injectedDecision['owner_state'] ?? null)
        && ($plainDecision['has_data'] ?? null) === ($injectedDecision['has_data'] ?? null);
    $record(
        'caller-supplied scope changes nothing',
        'GET',
        '/console/api/v1/context',
        $injected,
        [$plain['status']],
        [...$injectedDecision, 'identical_to_unscoped_request' => $identical],
        $identical,
    );

    foreach (['/support', '/account'] as $surface) {
        $response = $send('GET', $surface);
        $record('operator surface', 'GET', $surface, $response, [200]);
    }

    $sessionBeforeLogout = $cookies['af_session'] ?? '';
    $sessionBeforeLogoutPresent = $sessionBeforeLogout !== '';
    $signedOut = $send('POST', '/logout', ['csrf_token' => $csrfToken()]);
    $signedOutToLogin = $signedOut['location'] === '/login';
    $record('sign-out', 'POST', '/logout', $signedOut, [303], [
        'location' => $signedOut['location'],
        'returns_to_login' => $signedOutToLogin,
        'session_cookie_present_before_logout' => $sessionBeforeLogoutPresent,
    ], $signedOutToLogin && $sessionBeforeLogoutPresent);

    // As above, replay the OLD token rather than relying on the clear-cookie response as proof.
    $cookies = ['af_session' => $sessionBeforeLogout];
    $afterSignOut = $send('GET', '/console/api/v1/context');
    $afterSignOutDecision = $decision($afterSignOut);
    $afterSignOutIsJson = ($afterSignOutDecision['envelope'] ?? null) === 'JSON'
        && str_contains(strtolower(explode(';', $afterSignOut['content_type'])[0]), 'json');
    $record('the session is gone after sign-out', 'GET', '/console/api/v1/context', $afterSignOut, [401], [
        ...$afterSignOutDecision,
        'json_envelope_and_type' => $afterSignOutIsJson,
    ], $afterSignOutIsJson);

    $cookies = [];
}

// ============================================================ report

// EVERY FRONT-CONTROLLER RESPONSE CARRIES A REFERENCE, and the support flow depends on it. A
// missing one is not a separate step; it is a property of the whole run, so it is reported as one.
//
// NOT EVERY RESPONSE CAN CARRY ONE, and counting the ones that cannot made this metric unsatisfiable.
// `public_html/scheduler.php` is a separate entry point that never runs the Kernel, and `assets/` is
// served by the web server without the application running at all — so a correctly configured
// deployment always produced a non-zero count, `SMOKE_FAIL`, and a Gate N that could not be passed.
// A check nobody can satisfy is not a strict check; it is a broken one, and the first thing it
// teaches an operator is to ignore the number.
//
// The exempt and optional counts are reported beside it, so a reader can see that the zero is a
// zero over the responses that were actually required to carry a reference.
$withoutReference = 0;
$referenceExempt = 0;
$referenceOptional = 0;
foreach ($steps as $step) {
    $expectation = $step['request_reference'] ?? 'REQUIRED';
    if ($expectation === 'NOT_APPLICABLE') {
        $referenceExempt++;
        continue;
    }
    if ($expectation === 'EITHER') {
        $referenceOptional++;
        continue;
    }
    if ($step['request_id'] === null) {
        $withoutReference++;
    }
}

BetaCli::emit([
    'smoke' => 'STAGING_SMOKE',
    'mode' => $mode,
    'origin' => $origin,
    'steps' => $steps,
    'step_count' => count($steps),
    'failures' => $failures,
    'responses_without_request_reference' => $withoutReference,
    // Named so the zero above cannot be read as "every response carried one".
    'responses_exempt_from_request_reference' => $referenceExempt,
    'responses_where_either_is_correct' => $referenceOptional,
    'status' => $failures === 0 && $withoutReference === 0 ? 'SMOKE_PASS' : 'SMOKE_FAIL',
    // Restated where an operator reads the result: a passing smoke run says the deployment answers
    // correctly. It records nothing, grants nothing, and is not itself the evidence — filing it is
    // a separate, reviewed act. See docs/DEPLOYMENT_EVIDENCE_MODEL.md §7.
    'records_evidence' => false,
    'grants_runtime_authority' => false,
    'activates_live_read' => false,
], $failures === 0 && $withoutReference === 0 ? 0 : 1);
