<?php

declare(strict_types=1);

/**
 * ACCEPTANCE: the claims a customer meets through the SURFACE, not through the modules.
 *
 * Every other layer calls a governed function directly. This one goes in through the front door —
 * the same kernel, the same cookies, the same rendered HTML a browser would receive — because a
 * guarantee that holds in the module and leaks in the surface is not a guarantee a customer has.
 *
 * The claims proved here are the ones that are ONLY about the surface:
 *
 *   "You cannot see anything until you sign in, and signing in shows you nothing by itself."
 *   "The console shows you your authority, and hiding a menu item is not one of its controls."
 *   "There is no button that sends, publishes, or acts outside this system — anywhere."
 *   "What you are shown carries its own provenance, recomputed rather than reported."
 *
 * The last one is the difference between a console and a dashboard. A dashboard shows a status
 * column; this shows a verification it performed while rendering the page.
 */

use Allfather\Loopia\Auth\Authenticator;
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
$assurance = new Assurance($db, $clock, $ledger);
$entitlements = new Entitlements($db, $clock);

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
    new P04Release($db, $clock, $ledger, new Proposals($db, $clock), $access, $entitlements),
    new P05Release($db, $clock, $ledger, new Proposals($db, $clock), $access, $entitlements),
    $view,
);
$kernel->route('GET', '/console/briefings', $surfaces->briefings(...));
$kernel->route('GET', '/console/distributions', $surfaces->distributions(...));
$kernel->route('GET', '/console/marketing', $surfaces->marketing(...));

$request = static fn (string $method, string $path, array $post = [], array $cookies = [], array $get = []): Request =>
    Request::fromGlobals(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.7'],
        $get,
        $post,
        $cookies,
    );

/** Sign in the way a browser does, and return its cookie jar. */
$signIn = static function (array $user) use ($kernel, $request): array {
    $page = $kernel->handle($request('GET', '/login'));
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $page->cookies[0], $token);
    $post = $kernel->handle($request(
        'POST',
        '/login',
        ['csrf_token' => $token[1], 'email' => $user['email'], 'password' => $user['password']],
        [Csrf::COOKIE_NAME => $token[1]],
    ));
    Assert::same(303, $post->status, 'the operator signs in through the form');
    preg_match('/af_session=([^;]+)/', $post->headers['Set-Cookie'], $session);
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $post->cookies[0], $csrf);

    return [
        'jar' => ['af_session' => $session[1], Csrf::COOKIE_NAME => $csrf[1]],
        'csrf' => $csrf[1],
    ];
};

// ==================== "You cannot see anything until you sign in" ====================

foreach (['/console', '/console/decisions', '/console/briefings', '/console/workspaces'] as $path) {
    $anonymous = $kernel->handle($request('GET', $path));
    Assert::same(303, $anonymous->status, "{$path} is not served to an unidentified caller");
    Assert::same('/login', $anonymous->headers['Location'], 'they are redirected to sign in');
    // REDIRECTED, not refused. The caller is not denied — they are unidentified, and a console
    // that answered 403 would be telling an anonymous caller which paths exist.
    Assert::false(
        str_contains($anonymous->body, 'Not visible to you'),
        "{$path} tells an anonymous caller nothing about itself, not even that they lack authority",
    );
}

$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id']);
$session = $signIn($user);

// ==================== "Signing in shows you nothing by itself" ====================

// Identity is not authority, and the console does not blur them. A signed-in operator with no
// membership cannot even choose a workspace to act in.
Assert::same(
    409,
    $kernel->handle($request('GET', '/console', [], $session['jar']))->status,
    'a signed-in operator with no workspace is told so rather than shown a default one',
);
$noWorkspace = $kernel->handle($request('GET', '/console', [], $session['jar']));
Assert::contains('NO_ACTIVE_WORKSPACE', $noWorkspace->body, 'and the reason code says which state it is in');
// A 409 about STATE, not a 403 about authority. Naming a required permission here would send an
// operator to ask for a grant they already hold, for a problem no grant can fix.
Assert::false(
    str_contains($noWorkspace->body, 'This surface requires'),
    'and it names no required permission — no grant fixes having no workspace',
);
Assert::contains(
    'not refusing you',
    $noWorkspace->body,
    'the page says so in words, rather than reading as a denial the operator cannot act on',
);

$chooser = $kernel->handle($request('GET', '/console/workspaces', [], $session['jar']));
Assert::contains(
    'You hold no active membership',
    $chooser->body,
    'and the chooser names the reason rather than presenting an empty list as a bug',
);

$role = Seed::role($db, $clock, 'briefing_reader', ['battlecard.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
Assert::same(
    303,
    $kernel->handle($request(
        'POST',
        '/console/workspace',
        ['csrf_token' => $session['csrf'], 'workspace_id' => $scope['workspace_id']],
        $session['jar'],
    ))->status,
    'with a membership the operator can choose the workspace to act in',
);

// ==================== "The console shows you your authority" ====================

$workspace = $kernel->handle($request('GET', '/console', [], $session['jar']));
Assert::same(200, $workspace->status, 'the workspace surface renders');
Assert::contains('briefing_reader', $workspace->body, 'naming the role the operator actually holds');
Assert::contains('battlecard.read', $workspace->body, 'and the permission it carries — no more');
Assert::false(
    str_contains($workspace->body, 'decision.read'),
    'and NOT a permission they do not hold, however plausible it would look on the page',
);

// ==================== "Hiding a menu item is not one of our controls" ====================

// The rail links to every surface regardless of authority, and the page says why. A console that
// hid what you cannot reach would teach operators that the menu is the boundary — and the first
// person to type a URL would discover it is not.
Assert::contains('/console/decisions', $workspace->body, 'the rail links to a surface this operator cannot read');
Assert::contains(
    'Hiding an item is not a permission',
    $workspace->body,
    'and the page states plainly that navigation is a convenience, not a boundary',
);
$denied = $kernel->handle($request('GET', '/console/decisions', [], $session['jar']));
Assert::same(403, $denied->status, 'following that link is refused on the server');
Assert::contains('decision.read', $denied->body, 'naming the permission it wanted');
Assert::contains(
    'list is empty rather than partial',
    $denied->body,
    'and the denial is total — a summary line on a denied surface would be a denial in name only',
);

// ==================== "What you are shown carries its own provenance" ====================

Seed::role($db, $clock, 'ledger_reader', ['decision.read']);
Seed::membership(
    $db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'],
    Seed::role($db, $clock, 'ledger_reader_2', ['decision.read'])['role_id']
);
Seed::p01ReleasedBriefing($db, $clock, $scope);

$ledgerPage = $kernel->handle($request('GET', '/console/decisions', [], $session['jar']));
Assert::same(200, $ledgerPage->status, 'granting the permission changes the answer, with no code change');
Assert::contains(
    'INTERNAL_INFORMATIONAL_RELEASE',
    $ledgerPage->body,
    'a released decision appears on the timeline',
);
Assert::contains('PASS', $ledgerPage->body, 'with the chain verification shown');
Assert::contains(
    'Recomputed from the stored records',
    $ledgerPage->body,
    'stated as RECOMPUTED — a status column would report whatever a tamperer set',
);

// ==================== "There is no button that sends, publishes, or acts" ====================

// Across every rendered page this operator can reach. The module-level guarantee is proved
// elsewhere; this is the one a customer can check by looking, and the one a future template edit
// would be the way to break.
$forbiddenControls = ['send', 'publish', 'export', 'email', 'sms', 'crm', 'webhook', 'deliver'];
$pages = [
    '/console' => $workspace,
    '/console/decisions' => $ledgerPage,
    '/console/briefings' => $kernel->handle($request('GET', '/console/briefings', [], $session['jar'])),
    '/console/distributions' => $kernel->handle($request('GET', '/console/distributions', [], $session['jar'])),
    '/console/marketing' => $kernel->handle($request('GET', '/console/marketing', [], $session['jar'])),
    '/console/workspaces' => $kernel->handle($request('GET', '/console/workspaces', [], $session['jar'])),
];
foreach ($pages as $path => $response) {
    // Only the ACTIONS a page offers: forms and their submit buttons. Prose may discuss sending —
    // the claim ceiling notice does exactly that, and censoring it would make the page less honest.
    preg_match_all('/<form\b.*?<\/form>/s', $response->body, $forms);
    foreach ($forms[0] as $form) {
        preg_match('/action="([^"]*)"/', $form, $action);
        $target = $action[1] ?? '';
        Assert::true(
            in_array($target, ['/logout', '/console/workspace'], true),
            "{$path}: every form targets a governed console route, not an action — found '{$target}'",
        );
        foreach ($forbiddenControls as $verb) {
            Assert::false(
                stripos($form, $verb) !== false,
                "{$path}: no form offers a '{$verb}' control",
            );
        }
    }
    // And the page says so itself, on every surface, rather than only in a document.
    Assert::contains(
        'no surface in this projection sends, publishes',
        $response->body,
        "{$path}: the page states its own ceiling",
    );
}

// ==================== "Signing out is the server's act, not the browser's" ====================

$out = $kernel->handle($request('POST', '/logout', ['csrf_token' => $session['csrf']], $session['jar']));
Assert::same(303, $out->status, 'sign-out redirects');
Assert::same(
    303,
    $kernel->handle($request('GET', '/console', [], $session['jar']))->status,
    'and the SAME cookie no longer resolves — the session was revoked, not merely forgotten',
);
