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
 * One request. Returns status, headers of interest, and the body — which the CALLER may inspect but
 * must never put into a step record.
 *
 * @param array<string, string> $form
 * @return array{status: int, location: ?string, request_id: string, content_type: string, body: string, error: ?string}
 */
$send = static function (string $method, string $path, array $form = []) use ($origin, &$cookies, $fail): array {
    $handle = curl_init();
    $headerLines = [];
    $options = [
        CURLOPT_URL => $origin . $path,
        CURLOPT_RETURNTRANSFER => true,
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

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? curl_error($handle) : null;
    curl_close($handle);

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
        'body' => is_string($body) ? $body : '',
        'error' => $error === null ? null : substr($error, 0, 120),
    ];
};

/**
 * THE ONLY THING THAT WRITES A STEP RECORD, so there is one place to audit for what this command
 * can emit. Every field is a status, a path, an opaque reference or a reason code — never a body,
 * never a cookie, never a credential.
 *
 * @param list<int> $expected
 * @param array<string, mixed> $safe
 */
$record = static function (
    string $step,
    string $method,
    string $path,
    array $response,
    array $expected,
    array $safe = [],
) use (&$steps, &$failures): bool {
    $ok = in_array($response['status'], $expected, true) && $response['error'] === null;
    $steps[] = [
        'step' => $step,
        'method' => $method,
        'path' => $path,
        'status' => $response['status'],
        'expected' => $expected,
        'request_id' => RequestId::isWellFormed($response['request_id']) ? $response['request_id'] : null,
        'content_type' => explode(';', $response['content_type'])[0],
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

/** Whether a redirect target is somewhere this client may follow. */
$sameOrigin = static function (?string $location) use ($origin): bool {
    if ($location === null) {
        return false;
    }
    if (str_starts_with($location, '/')) {
        return true;
    }

    return str_starts_with($location, $origin . '/');
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

$health = $send('GET', '/healthz');
$healthClaims = [];
try {
    $decoded = json_decode($health['body'], true, 8, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) {
        $healthClaims = [
            'projection' => $decoded['projection'] ?? null,
            'customer_visible_output' => $decoded['customer_visible_output'] ?? null,
            'external_effect' => $decoded['external_effect'] ?? null,
        ];
    }
} catch (\JsonException) {
    $healthClaims = ['projection' => null];
}
$record('health', 'GET', '/healthz', $health, [200], $healthClaims);

$shell = $send('GET', '/console/app');
$record('anonymous console shell redirects to sign-in', 'GET', '/console/app', $shell, [303], [
    'location' => $shell['location'],
    'location_same_origin' => $sameOrigin($shell['location']),
]);

// THE DISTINCTION THAT MATTERS MOST for a browser client: an anonymous JSON read must answer JSON
// 401, not an HTML redirect. A fetch() that received a login page would render it into the console.
$anonContext = $send('GET', '/console/api/v1/context');
$record('anonymous JSON read is a JSON 401', 'GET', '/console/api/v1/context', $anonContext, [401], [
    ...$decision($anonContext),
    'is_html_redirect' => $anonContext['location'] !== null,
]);

$login = $send('GET', '/login');
$record('sign-in page', 'GET', '/login', $login, [200]);

$notFound = $send('GET', '/there-is-no-such-surface');
$record('unknown path', 'GET', '/there-is-no-such-surface', $notFound, [404]);

$unverified = $send('POST', '/login', ['email' => 'nobody@example.test', 'password' => 'not-a-password']);
$record('POST without a CSRF token is refused', 'POST', '/login', $unverified, [403]);

// The scheduler door is POST-only, and an unconfigured credential makes it unreachable rather than
// open. A GET must be 405 — never 200, and never a 404 that would hide a misconfigured schedule.
$schedulerGet = $send('GET', '/scheduler.php');
$record('scheduler endpoint refuses GET', 'GET', '/scheduler.php', $schedulerGet, [405]);

// THE PRIVATE TREE, over HTTP. This is the half `tests/foundation/deployment_boundary.php` cannot
// reach: it proves the layout, and this proves the host honours it.
foreach ([
    '/.env',
    '/app/autoload.php',
    '/database/migrations/001_identity_scope.sql',
    '/storage/request.log',
    '/worker/tick.php',
    '/ops/migrate.php',
    '/frontend/console/package.json',
    '/tests/run.php',
    '/composer.json',
    '/upstream.lock.json',
    '/deployment-evidence.json',
] as $private) {
    $response = $send('GET', $private);
    $record('private path is not served', 'GET', $private, $response, [403, 404], [
        'body_bytes' => strlen($response['body']),
    ]);
}

// ============================================================ authenticated modes

$csrfToken = static fn (): string => $cookies[Csrf::COOKIE_NAME] ?? '';

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
    $record('first sign-in lands on the password surface', 'POST', '/login', $first, [303], [
        'location' => $first['location'],
    ]);

    $gatedShell = $send('GET', '/console/app');
    $record('the console is gated before the password changes', 'GET', '/console/app', $gatedShell, [303], [
        'location' => $gatedShell['location'],
    ]);

    $gatedRead = $send('GET', '/console/api/v1/context');
    $record('and so is every governed read', 'GET', '/console/api/v1/context', $gatedRead, [403], [
        ...$decision($gatedRead),
    ]);

    $passwordForm = $send('GET', '/account/password');
    $record('the password surface itself is reachable', 'GET', '/account/password', $passwordForm, [200]);

    $changed = $send('POST', '/account/password', [
        'csrf_token' => $csrfToken(),
        'current_password' => $password,
        'new_password' => $newPassword,
        'confirm_password' => $newPassword,
    ]);
    $record('the password change succeeds', 'POST', '/account/password', $changed, [303], [
        'location' => $changed['location'],
    ]);

    // EVERY SESSION ENDED, including the one that made the change. The jar still holds whatever the
    // response left; the point is that the server no longer honours it.
    $afterChange = $send('GET', '/console/api/v1/context');
    $record('the old session is gone', 'GET', '/console/api/v1/context', $afterChange, [401], [
        ...$decision($afterChange),
    ]);

    $cookies = [];
    $password = $newPassword;
}

if ($mode !== 'anonymous') {
    $signedIn = $signIn($password);
    $record('sign-in', 'POST', '/login', $signedIn, [303], ['location' => $signedIn['location']]);

    // A workspace may not be selected yet. The chooser is the remedy, and the smoke run follows it
    // rather than reporting the 409 as a failure — that state is correct, not broken.
    if ($signedIn['location'] === '/console/workspaces') {
        $chooser = $send('GET', '/console/workspaces');
        $record('workspace chooser', 'GET', '/console/workspaces', $chooser, [200]);
        if (preg_match('/name="workspace_id" value="([0-9a-f-]{36})"/', $chooser['body'], $m) === 1) {
            $selected = $send('POST', '/console/workspace', [
                'csrf_token' => $csrfToken(),
                'workspace_id' => $m[1],
            ]);
            $record('workspace selected', 'POST', '/console/workspace', $selected, [303], [
                'location' => $selected['location'],
            ]);
        } else {
            $record('workspace chooser offers a workspace', 'GET', '/console/workspaces', [
                'status' => 0, 'location' => null, 'request_id' => '', 'content_type' => '',
                'body' => '', 'error' => 'no selectable workspace was offered',
            ], [200]);
        }
    }

    $appShell = $send('GET', '/console/app');
    $record('the React shell is served', 'GET', '/console/app', $appShell, [200], [
        'body_bytes' => strlen($appShell['body']),
    ]);

    foreach ($readPaths as $path) {
        $response = $send('GET', $path);
        // 200, 403, 409 and 503 are all VALID ANSWERS and are recorded as what they are. A smoke
        // test that demanded 200 everywhere would be demanding that the deployment grant every
        // permission to whichever account it was pointed at.
        $record('governed read', 'GET', $path, $response, [200, 403, 409, 503], $decision($response));
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
    $record('caller-supplied scope changes nothing', 'GET', '/console/api/v1/context', $injected, [$plain['status']], [
        ...$injectedDecision,
        'identical_to_unscoped_request' =>
            $plain['status'] === $injected['status']
            && ($plainDecision['access_decision'] ?? null) === ($injectedDecision['access_decision'] ?? null)
            && ($plainDecision['owner_state'] ?? null) === ($injectedDecision['owner_state'] ?? null)
            && ($plainDecision['has_data'] ?? null) === ($injectedDecision['has_data'] ?? null),
    ]);

    foreach (['/support', '/account'] as $surface) {
        $response = $send('GET', $surface);
        $record('operator surface', 'GET', $surface, $response, [200]);
    }

    $signedOut = $send('POST', '/logout', ['csrf_token' => $csrfToken()]);
    $record('sign-out', 'POST', '/logout', $signedOut, [303], ['location' => $signedOut['location']]);

    $afterSignOut = $send('GET', '/console/api/v1/context');
    $record('the session is gone after sign-out', 'GET', '/console/api/v1/context', $afterSignOut, [401], [
        ...$decision($afterSignOut),
    ]);
}

// ============================================================ report

// EVERY RESPONSE CARRIES A REFERENCE, and the support flow depends on it. A missing one is not a
// separate step; it is a property of the whole run, so it is reported as one.
$withoutReference = 0;
foreach ($steps as $step) {
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
    'status' => $failures === 0 && $withoutReference === 0 ? 'SMOKE_PASS' : 'SMOKE_FAIL',
    // Restated where an operator reads the result: a passing smoke run says the deployment answers
    // correctly. It records nothing, grants nothing, and is not itself the evidence — filing it is
    // a separate, reviewed act. See docs/DEPLOYMENT_EVIDENCE_MODEL.md §7.
    'records_evidence' => false,
    'grants_runtime_authority' => false,
    'activates_live_read' => false,
], $failures === 0 && $withoutReference === 0 ? 0 : 1);
