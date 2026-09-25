<?php

declare(strict_types=1);

/**
 * The console surfaces, driven through the real HTTP kernel.
 *
 * The claims under test are about the SURFACE, not the modules beneath it:
 *
 *   Being signed in is identity, not authority. Every surface re-runs the M02 guard, and an
 *   authenticated operator with no role is denied by each of them, individually.
 *
 *   A denial returns an EMPTY list and the guard's OWN reason codes — never a generic message,
 *   because a missing role, a missing tier and an unconfigured target have different remedies.
 *
 *   CSRF is verified by METHOD, not by route list, so a route added later cannot forget it.
 *
 *   Nothing renders unescaped.
 */

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\SurfaceController;
use Allfather\Loopia\Http\Controllers\WorkspaceController;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P05\Release as P05Release;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);

$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');
$auth = new Authenticator($db, $clock);
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$entitlements = new Entitlements($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);

$kernel = new Kernel($db, $clock, $auth, $view);
$sessions = new SessionController($auth, $view);
$kernel->route('GET', '/login', $sessions->showLogin(...));
$kernel->route('POST', '/login', $sessions->login(...));
$kernel->route('POST', '/logout', $sessions->logout(...));

$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('GET', '/console/workspaces', $workspaces->choose(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));

$console = new ConsoleController(
    $db, $clock, $access, new Console($db, $clock, $access, $ledger, $assurance),
    $assurance, $ledger, $view,
);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

$surfaces = new SurfaceController(
    new Surfacing($db, $access, $ledger, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
    $view,
);
$kernel->route('GET', '/console/briefings', $surfaces->briefings(...));
$kernel->route('GET', '/console/distributions', $surfaces->distributions(...));
$kernel->route('GET', '/console/marketing', $surfaces->marketing(...));

/** Build a request the way the kernel would receive one. */
$request = static fn (string $method, string $path, array $post = [], array $cookies = [], array $get = []): Request =>
    Request::fromGlobals(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.10'],
        $get,
        $post,
        $cookies,
    );

// ============================================================ unauthenticated

Assert::same(303, $kernel->handle($request('GET', '/console'))->status, 'an unidentified caller is redirected, not refused');
Assert::same('/login', $kernel->handle($request('GET', '/console'))->headers['Location'], 'to the login surface');
Assert::same(200, $kernel->handle($request('GET', '/login'))->status, 'which is public');
Assert::same(404, $kernel->handle($request('GET', '/console/invented'))->status, 'an unknown surface is 404');

// EVERY response carries the security headers, including the ones nobody remembers to set.
$headers = $kernel->handle($request('GET', '/login'))->headers;
Assert::contains("default-src 'none'", $headers['Content-Security-Policy'], 'the CSP forbids everything by default');
Assert::contains("frame-ancestors 'none'", $headers['Content-Security-Policy'], 'and framing');
Assert::same('nosniff', $headers['X-Content-Type-Options'], 'content type is not sniffed');
Assert::contains('no-store', $headers['Cache-Control'], 'and a governance page is never cached');
// No script at all. A surface that cannot execute script has one fewer way to lie about what it shows.
Assert::false(str_contains($headers['Content-Security-Policy'], 'script-src'), 'no script source is permitted at all');

// ============================================================ CSRF, by method — sign-in included

// A POST with no token is refused BEFORE routing — the handler is never reached.
Assert::same(
    403,
    $kernel->handle($request('POST', '/login', ['email' => 'x@y.z', 'password' => 'p']))->status,
    'a state-changing request with no CSRF token is refused before it reaches a handler',
);

// SIGN-IN IS NOT EXEMPT — and it is not unreachable either, which is the harder half. There is no
// session to bind a token to yet, so the login page mints one, carries it in a __Host- cookie and
// embeds the same value in the form; the POST is checked against that pair.
$loginPage = $kernel->handle($request('GET', '/login'));
$preAuthCookie = $loginPage->cookies[0] ?? '';
Assert::contains(Csrf::COOKIE_NAME . '=', $preAuthCookie, 'the sign-in page issues a pre-auth CSRF cookie');
Assert::contains('HttpOnly', $preAuthCookie, 'unreadable by script');
Assert::contains('SameSite=Strict', $preAuthCookie, 'and never sent on a cross-site request at all');
Assert::contains('Secure', $preAuthCookie, 'over TLS only');
// The __Host- prefix is only honoured with Path=/ and NO Domain — and that pair is exactly what
// stops a sibling subdomain from planting the cookie the sign-in POST is checked against.
Assert::contains('Path=/', $preAuthCookie, 'anchored at the root path');
Assert::false(str_contains($preAuthCookie, 'Domain='), 'and carrying no Domain, as the __Host- prefix requires');

preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $preAuthCookie, $cookieMatch);
$preAuthToken = $cookieMatch[1] ?? '';
Assert::true(strlen($preAuthToken) >= 32, 'the token is a real token, not a placeholder');
Assert::contains(
    'name="csrf_token" value="' . $preAuthToken . '"',
    $loginPage->body,
    'and the form carries the same value the cookie does',
);

$credentials = ['email' => 'x@y.z', 'password' => 'p'];
// NEITHER HALF ALONE. A form token without the cookie is a token an attacker could have read from
// a page they induced; a cookie without the form token is what every cross-site POST already sends.
Assert::same(
    403,
    $kernel->handle($request('POST', '/login', [...$credentials, 'csrf_token' => $preAuthToken]))->status,
    'the form token alone does not verify a sign-in — the matching cookie must be present',
);
Assert::same(
    403,
    $kernel->handle($request('POST', '/login', $credentials, [Csrf::COOKIE_NAME => $preAuthToken]))->status,
    'and the cookie alone does not either',
);
Assert::same(
    403,
    $kernel->handle($request(
        'POST',
        '/login',
        [...$credentials, 'csrf_token' => Csrf::issue()],
        [Csrf::COOKIE_NAME => $preAuthToken],
    ))->status,
    'a well-formed token that is not THIS cookie\'s token is refused',
);

// A token in the QUERY STRING is not a token. Accepting one would put it in access logs, in
// Referer headers, and in any link a caller could be talked into following.
Assert::same(
    403,
    $kernel->handle($request(
        'POST',
        '/login',
        $credentials,
        [Csrf::COOKIE_NAME => $preAuthToken],
        ['csrf_token' => $preAuthToken],
    ))->status,
    'and a token presented in the query string is not accepted at all',
);

// ============================================================ sign in

$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id']);

// THROUGH THE FRONT DOOR. Everything below rides the cookies this POST returns, so the console is
// exercised the way a browser reaches it rather than by handing it a session the test constructed.
$freshLogin = $kernel->handle($request('GET', '/login'));
preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $freshLogin->cookies[0], $signInMatch);
$signInToken = $signInMatch[1];

$signedIn = $kernel->handle($request(
    'POST',
    '/login',
    ['csrf_token' => $signInToken, 'email' => $user['email'], 'password' => $user['password']],
    [Csrf::COOKIE_NAME => $signInToken],
));
Assert::same(303, $signedIn->status, 'a sign-in carrying its page\'s own token succeeds');
Assert::same('/console', $signedIn->headers['Location'], 'and lands on the console');
Assert::contains('af_session=', $signedIn->headers['Set-Cookie'], 'setting the session cookie');

// THE TOKEN ROTATES AT THE BOUNDARY. The pre-auth token protected one POST and is then gone: a
// value an unauthenticated caller could have influenced must not become the authenticated one.
$rotatedCookie = $signedIn->cookies[0] ?? '';
Assert::contains(Csrf::COOKIE_NAME . '=', $rotatedCookie, 'and rotating the CSRF cookie');
Assert::false(
    str_contains($rotatedCookie, $signInToken),
    'the pre-auth token does NOT survive sign-in — it is replaced by the session\'s own',
);

preg_match('/af_session=([^;]+)/', $signedIn->headers['Set-Cookie'], $sessionMatch);
preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $rotatedCookie, $csrfMatch);
$cookie = ['af_session' => $sessionMatch[1], Csrf::COOKIE_NAME => $csrfMatch[1]];
$sessionCsrf = $csrfMatch[1];

// The session's digest is what the rotated cookie is checked against from here on — and only the
// digest was ever stored, so a database read yields nothing presentable.
$sessionId = (string) $db->scalar(
    'SELECT session_id FROM platform_sessions WHERE token_hash = ?',
    [Authenticator::digest($sessionMatch[1])]
);
Assert::true($sessionId !== '', 'the session row exists');
Assert::same(
    Csrf::digest($sessionCsrf),
    (string) $db->scalar('SELECT csrf_token_hash FROM platform_sessions WHERE session_id = ?', [$sessionId]),
    'and the stored CSRF value is a DIGEST of the cookie token, never the token',
);

// ============================================================ choosing a workspace

// An active workspace requires a MEMBERSHIP, re-checked at the point a workspace id in a form field
// becomes session state. Without one the session cannot select a workspace at all.
Assert::same(
    409,
    $kernel->handle($request('GET', '/console', [], $cookie))->status,
    'a session with no active workspace says so rather than guessing one',
);

// And it is not a dead end: the chooser is a real route. Before this existed, a signed-in operator
// holding a membership had no way to reach their own workspace — every console page answered 409
// and the only thing that could set one was a method no route called.
$emptyChooser = $kernel->handle($request('GET', '/console/workspaces', [], $cookie));
Assert::same(200, $emptyChooser->status, 'the chooser renders for a signed-in operator');
Assert::contains(
    'You hold no active membership',
    $emptyChooser->body,
    'and with no membership it says so plainly rather than listing the tenant\'s workspaces',
);
Assert::false(
    str_contains($emptyChooser->body, (string) $scope['workspace_id']),
    'a workspace the operator is not a member of is not named — the chooser is not an enumeration surface',
);

// The POST refuses for the same reason, and names it. It does NOT consult the list: a chooser that
// rendered nothing is not what stops the selection — the membership re-check is.
$deniedSelect = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $sessionCsrf, 'workspace_id' => $scope['workspace_id']],
    $cookie,
));
Assert::same(403, $deniedSelect->status, 'selecting a workspace without a membership is refused');
Assert::contains(
    'WORKSPACE_MEMBERSHIP_REQUIRED',
    $deniedSelect->body,
    'and the specific reason is named — a revoked membership and a closed workspace need different people',
);
Assert::same(
    null,
    $db->scalar('SELECT active_workspace_id FROM platform_sessions WHERE session_id = ?', [$sessionId]),
    'and the session\'s active workspace is unchanged',
);

// ANOTHER TENANT'S workspace is NOT_FOUND, not FORBIDDEN. The distinction is the whole point: a
// FORBIDDEN would confirm the id names something real, which turns the form into a probe.
$otherTenant = Seed::tenantWorkspace($db, $clock, 'rival');
$crossTenant = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $sessionCsrf, 'workspace_id' => $otherTenant['workspace_id']],
    $cookie,
));
Assert::same(403, $crossTenant->status, 'a cross-tenant workspace id is refused');
Assert::contains(
    'WORKSPACE_NOT_FOUND_IN_SCOPE',
    $crossTenant->body,
    'as NOT_FOUND rather than FORBIDDEN — the response cannot be used to enumerate another tenant',
);

// Grant a membership carrying ONE permission that no surface below needs. The operator now has
// identity AND a role — and still no authority for any of them, which is the case worth testing.
$unrelated = Seed::role($db, $clock, 'console_visitor', ['workspace.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $unrelated['role_id']);

// Multiple roles confer memberships in the same workspace, not additional workspaces.
$secondVisitorRole = Seed::role($db, $clock, 'console_second_visitor', ['workspace.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $secondVisitorRole['role_id']);

// A SECOND WORKSPACE IN THE SAME TENANT, with no membership in it. This is the enumeration case a
// tenant-scoped query would leak and a cross-tenant test would never catch: same tenant, ACTIVE,
// and still none of this operator's business.
$siblingWorkspaceId = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $siblingWorkspaceId,
    'tenant_id' => $scope['tenant_id'],
    'workspace_key' => 'ws-sibling',
    'schema_version' => '1',
    'display_name' => 'Sibling workspace',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
]);

$chooser = $kernel->handle($request('GET', '/console/workspaces', [], $cookie));
Assert::same(
    1,
    substr_count($chooser->body, 'name="workspace_id" value="' . $scope['workspace_id'] . '"'),
    'two active role memberships render one selectable workspace',
);
Assert::contains(
    (string) $scope['workspace_id'],
    $chooser->body,
    'with a membership the workspace appears in the chooser',
);
Assert::false(
    str_contains($chooser->body, $siblingWorkspaceId),
    'a workspace in the SAME tenant with no membership does not — the list is memberships, not the tenant',
);
Assert::false(
    str_contains($chooser->body, 'Sibling workspace'),
    'not by name either',
);
Assert::false(
    str_contains($chooser->body, (string) $otherTenant['workspace_id']),
    'and another tenant\'s workspace still does not, membership or no membership',
);
Assert::same(
    403,
    $kernel->handle($request(
        'POST',
        '/console/workspace',
        ['csrf_token' => $sessionCsrf, 'workspace_id' => $siblingWorkspaceId],
        $cookie,
    ))->status,
    'and it cannot be selected by id either — the POST re-checks membership, it does not trust the list',
);

$selected = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $sessionCsrf, 'workspace_id' => $scope['workspace_id']],
    $cookie,
));
Assert::same(303, $selected->status, 'with a membership, the workspace is selected');
Assert::same('/console', $selected->headers['Location'], 'and the session lands on the console');
Assert::same(
    $scope['workspace_id'],
    (string) $db->scalar('SELECT active_workspace_id FROM platform_sessions WHERE session_id = ?', [$sessionId]),
    'the selection is SESSION state, held server-side — the browser never supplies the workspace id again',
);

// ============================================================ identity is not authority

// Signed in, WITH a role — just not one any of these surfaces needs. Every one denies, and each
// names the permission it wanted. A role grant is not a blanket grant.
foreach ([
    '/console/decisions' => 'decision.read',
    '/console/briefings' => 'battlecard.read',
    '/console/distributions' => 'role_briefing.read',
    '/console/marketing' => 'marketing_asset.read',
] as $path => $permission) {
    $response = $kernel->handle($request('GET', $path, [], $cookie));
    Assert::same(403, $response->status, "{$path} denies an operator whose role does not carry its permission");
    Assert::contains(
        $permission,
        $response->body,
        "and names the permission it wanted: {$permission}",
    );
    Assert::contains('Not visible to you', $response->body, 'stating the denial plainly');
}

// The workspace surface renders for an authenticated operator, because it shows their authority
// rather than gating on it — and showing somebody an empty set of roles is the correct answer.
$workspace = $kernel->handle($request('GET', '/console', [], $cookie));
Assert::same(200, $workspace->status, 'the workspace surface renders');
Assert::contains('console_visitor', $workspace->body, 'showing the role the operator actually holds');
Assert::contains('workspace.read', $workspace->body, 'and the permission it carries — no more');
// The navigation disclaimer is ON THE PAGE, not just in a comment. An operator who thinks the menu
// is the boundary will eventually test it.
Assert::contains(
    'Hiding an item is not a permission',
    $workspace->body,
    'and the page states that navigation is a convenience, not a boundary',
);

// ============================================================ authority changes the answer

$role = Seed::role($db, $clock, 'console_reader', ['decision.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);

$decisions = $kernel->handle($request('GET', '/console/decisions', [], $cookie));
Assert::same(200, $decisions->status, 'granting the role changes the answer — with no code change');
Assert::contains('Decision ledger', $decisions->body, 'and the ledger renders');

// The other three still deny: one role is one permission, and each surface named a different one.
Assert::same(
    403,
    $kernel->handle($request('GET', '/console/briefings', [], $cookie))->status,
    'while a surface needing a DIFFERENT permission still denies — the grant was not a blanket one',
);

// ============================================================ the ledger is verified, not asserted

$source = Seed::p01ReleasedBriefing($db, $clock, $scope);
$withRecords = $kernel->handle($request('GET', '/console/decisions', [], $cookie));
Assert::contains('INTERNAL_INFORMATIONAL_RELEASE', $withRecords->body, 'a released decision appears on the timeline');
Assert::contains('Chain verification', $withRecords->body, 'and the chain verification is shown');
Assert::contains(
    'Recomputed from the stored records',
    $withRecords->body,
    'stated as recomputed rather than read — a status column would report whatever a tamperer set',
);

// ============================================================ reflected input is escaped

// The `role` query parameter is CALLER-SUPPLIED and is reflected onto the distributions page, which
// makes it the reflected-XSS vector on this surface. It reaches the page escaped, and the escaping
// is not optional: `View::e` is the only route from a value to the page, and no template is given a
// raw helper — because the moment one exists, somebody uses it on a field like this.
$hostile = '<script>alert(1)</script>';
$reflected = $kernel->handle($request('GET', '/console/distributions', [], $cookie, ['role' => $hostile]));
Assert::false(
    str_contains($reflected->body, $hostile),
    'a reflected query parameter never reaches the page as markup',
);
Assert::contains(
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    $reflected->body,
    'it reaches it escaped',
);
// And the guard still refuses: an unknown role is not merely unrenderable, it is not a recipient.
Assert::same(403, $reflected->status, 'while the surface denies it on its own terms too');

// The escaping helper itself, on the shapes that matter.
foreach ([
    '<b>x</b>' => '&lt;b&gt;x&lt;/b&gt;',
    '"quoted"' => '&quot;quoted&quot;',
    "it's" => 'it&#039;s',
] as $raw => $expected) {
    Assert::same($expected, View::e($raw), "View::e escapes {$raw}");
}

// ============================================================ sign out

// The RENDERED page is where the token has to appear, or every authenticated form in the console
// submits an empty one and the operator meets a 403 they cannot explain. The console never stores
// the token, so the only place it can come from is the caller's own verified cookie.
$navPage = $kernel->handle($request('GET', '/console', [], $cookie));
Assert::contains(
    'name="csrf_token" value="' . $sessionCsrf . '"',
    $navPage->body,
    'an authenticated page renders the session\'s own CSRF token into its forms',
);

// An authenticated POST is checked against the SESSION's digest, not against the cookie pair — so
// a caller who plants a cookie of their own choosing still cannot verify one.
Assert::same(
    403,
    $kernel->handle($request(
        'POST',
        '/logout',
        ['csrf_token' => $signInToken],
        [...$cookie, Csrf::COOKIE_NAME => $signInToken],
    ))->status,
    'a matching cookie pair does NOT verify an authenticated POST — the session is the authority',
);

$loggedOut = $kernel->handle($request('POST', '/logout', ['csrf_token' => $sessionCsrf], $cookie));
Assert::same(303, $loggedOut->status, 'sign-out redirects');
Assert::contains('Max-Age=0', $loggedOut->headers['Set-Cookie'], 'clearing the session cookie');
Assert::contains(
    Csrf::COOKIE_NAME . '=;',
    $loggedOut->cookies[0] ?? '',
    'and clearing the CSRF cookie too — one that outlived its session would break the next sign-in',
);
Assert::same(
    303,
    $kernel->handle($request('GET', '/console', [], $cookie))->status,
    'and the session no longer resolves — the server revoked it, not the browser',
);
