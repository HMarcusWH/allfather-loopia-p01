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
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\SurfaceController;
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

$console = new ConsoleController(
    $db, $clock, $access, new Console($db, $clock, $access, $ledger, $assurance),
    $assurance, $ledger, $view,
);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

$surfaces = new SurfaceController(
    new Surfacing($db, $access, $ledger, $entitlements),
    new P04Release($db, $clock, $ledger, new Proposals($db, $clock), $access, $entitlements),
    new P05Release($db, $clock, $ledger, new Proposals($db, $clock), $access, $entitlements),
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

// ============================================================ CSRF, by method

// A POST with no token is refused BEFORE routing — the handler is never reached.
Assert::same(
    403,
    $kernel->handle($request('POST', '/login', ['email' => 'x@y.z', 'password' => 'p']))->status,
    'a state-changing request with no CSRF token is refused before it reaches a handler',
);

// ============================================================ sign in

$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id']);
$authenticated = $auth->authenticate($user['email'], $user['password'], '203.0.113.10');
Assert::true($authenticated->isOk(), 'the operator authenticates');
$cookie = ['af_session' => (string) $authenticated->value()['session_token']];
$sessionId = (string) $authenticated->value()['session_id'];

// An active workspace requires a MEMBERSHIP, re-checked at the point a workspace id in a form field
// becomes session state. Without one the session cannot select a workspace at all.
Assert::true(
    $auth->setActiveWorkspace($sessionId, $scope['workspace_id'])->isRefusal(),
    'a workspace cannot be selected without a membership in it',
);
Assert::same(
    409,
    $kernel->handle($request('GET', '/console', [], $cookie))->status,
    'and a session with no active workspace says so rather than guessing one',
);

// Grant a membership carrying ONE permission that no surface below needs. The operator now has
// identity AND a role — and still no authority for any of them, which is the case worth testing.
$unrelated = Seed::role($db, $clock, 'console_visitor', ['workspace.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $unrelated['role_id']);
Assert::true(
    $auth->setActiveWorkspace($sessionId, $scope['workspace_id'])->isOk(),
    'with a membership, the workspace is selected',
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

$csrf = (string) $authenticated->value()['csrf_token'];
$loggedOut = $kernel->handle($request('POST', '/logout', ['csrf_token' => $csrf], $cookie));
Assert::same(303, $loggedOut->status, 'sign-out redirects');
Assert::contains('Max-Age=0', $loggedOut->headers['Set-Cookie'], 'clearing the session cookie');
Assert::same(
    303,
    $kernel->handle($request('GET', '/console', [], $cookie))->status,
    'and the session no longer resolves — the server revoked it, not the browser',
);
