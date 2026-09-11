<?php

declare(strict_types=1);

/**
 * The beta operations surfaces, driven through the real HTTP kernel.
 *
 * What this proves is the part a unit test cannot: that the lifecycle gate is enforced BETWEEN
 * routing and dispatch, so it covers every surface at once — the server-rendered console, the React
 * shell document, the workspace routes and every JSON read — rather than being remembered by each.
 *
 * And that the support reference reaches the responses where it matters most. A partner who meets a
 * 403 is exactly the partner who needs something to quote, so the 403 is asserted, not just the 200.
 */

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Controllers\AccountController;
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\ConsoleReadController;
use Allfather\Loopia\Http\Controllers\OperatorConsoleController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\SupportController;
use Allfather\Loopia\Http\Controllers\WorkspaceController;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\ReadAdapters\ConsoleContextAdapter;
use Allfather\Loopia\Http\ReadAdapters\DecisionLedgerAdapter;
use Allfather\Loopia\Http\ReadAdapters\SupportContextAdapter;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\RequestId;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\RelationshipRelease;
use Allfather\Loopia\Product\P02\RelationshipTwins;
use Allfather\Loopia\Product\P02\TwinRelease;
use Allfather\Loopia\Product\P03\Release as P03Release;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P05\Release as P05Release;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;
use Allfather\Loopia\Substrate\M16\P02Adapter;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$root = dirname(__DIR__, 2);
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'betahttp');
$workspaceKey = (string) $db->scalar(
    'SELECT workspace_key FROM workspaces WHERE workspace_id = ?',
    [$scope['workspace_id']]
);

$view = new View($root . '/app/Http/templates');
$auth = new Authenticator($db, $clock);
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);
$entitlements = new Entitlements($db, $clock);
$accounts = new AccountService($db, $clock, $auth);
$beta = new BetaBundle($db, $entitlements);
$m12 = new Console($db, $clock, $access, $ledger, $assurance);

$env = Env::fromArray([
    'SUPPORT_CONTACT_TEXT' => 'Reply to the thread your operator opened with you.',
    'APP_RELEASE_REF' => 'test-release-ref',
    // Present in configuration and NEVER rendered. The support page reads a closed list of two keys,
    // and this is here so "it does not leak" is proved against a configuration that HAS a secret.
    'DB_PASSWORD' => 'super-secret-database-password',
    'APP_MASTER_KEY' => 'super-secret-master-key',
]);

$kernel = new Kernel($db, $clock, $auth, $view);

$sessions = new SessionController($auth, $view);
$kernel->route('GET', '/login', $sessions->showLogin(...));
$kernel->route('POST', '/login', $sessions->login(...));
$kernel->route('POST', '/logout', $sessions->logout(...));

$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('GET', '/console/workspaces', $workspaces->choose(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));

$account = new AccountController($accounts, $view);
$kernel->route('GET', '/account', $account->show(...));
$kernel->route('GET', '/account/password', $account->showPasswordForm(...));
$kernel->route('POST', '/account/password', $account->changePassword(...));

$support = new SupportController($view, $env, new SupportContextAdapter($db), 'testing');
$kernel->route('GET', '/support', $support->show(...));

$contextAdapter = new ConsoleContextAdapter($db, $m12, $assurance);
$decisionAdapter = new DecisionLedgerAdapter($db, $clock, $access, $ledger);
$console = new ConsoleController($contextAdapter, $decisionAdapter, $view);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

$operatorConsole = new OperatorConsoleController(
    new ConsoleAssetManifest($root . '/public_html/assets/operator-console'),
    $view,
);
$kernel->route('GET', '/console/app', $operatorConsole->shell(...));

$m16 = new ReleaseOutput($db, $clock, $ledger);
$p02M16 = new P02Adapter($db, $m16);
$reads = new ConsoleReadController(
    $contextAdapter,
    $decisionAdapter,
    new Surfacing($db, $access, $ledger, $entitlements),
    new TwinRelease($db, $clock, $ledger, new EntityTwins($db, $clock), $access, $entitlements, $p02M16),
    new RelationshipRelease($db, $clock, $ledger, new RelationshipTwins($db, $clock), $access, $entitlements, $p02M16),
    new P03Release($db, $clock, $ledger, $access, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
);
$jsonReadPaths = [
    '/console/api/v1/context',
    '/console/api/v1/p01/battlecards',
    '/console/api/v1/p02/entities',
    '/console/api/v1/p03/priority-queue',
    '/console/api/v1/p04/distributions',
    '/console/api/v1/p05/drafts',
    '/console/api/v1/m10/decisions',
];
$kernel->route('GET', '/console/api/v1/context', $reads->context(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p01/battlecards', $reads->p01Battlecards(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p04/distributions', $reads->p04Distributions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p05/drafts', $reads->p05Drafts(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/m10/decisions', $reads->decisions(...), 'JSON_401');

$request = static fn (string $method, string $path, array $post = [], array $cookies = [], array $get = []): Request =>
    Request::fromGlobals(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.44'],
        $get,
        $post,
        $cookies,
    );

/** Sign in through the front door and return the cookie jar. */
$signIn = static function (string $email, string $password) use ($kernel, $request): array {
    $page = $kernel->handle($request('GET', '/login'));
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $page->cookies[0] ?? '', $m);
    $preAuth = $m[1] ?? '';
    $signedIn = $kernel->handle($request(
        'POST',
        '/login',
        ['csrf_token' => $preAuth, 'email' => $email, 'password' => $password],
        [Csrf::COOKIE_NAME => $preAuth],
    ));
    preg_match('/af_session=([^;]+)/', $signedIn->headers['Set-Cookie'] ?? '', $sessionMatch);
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $signedIn->cookies[0] ?? '', $csrfMatch);

    return [
        'response' => $signedIn,
        'cookies' => [
            'af_session' => $sessionMatch[1] ?? '',
            Csrf::COOKIE_NAME => $csrfMatch[1] ?? '',
        ],
        'csrf' => $csrfMatch[1] ?? '',
    ];
};

// ============================================================ every response carries a reference

foreach ([
    ['GET', '/login', 200],
    ['GET', '/console', 303],
    ['GET', '/nope', 404],
] as [$method, $path, $expectedStatus]) {
    $response = $kernel->handle($request($method, $path));
    Assert::same($expectedStatus, $response->status, "{$method} {$path} answers {$expectedStatus}");
    $reference = $response->headers[RequestId::HEADER] ?? '';
    Assert::true(
        RequestId::isWellFormed($reference),
        "and carries an opaque support reference: {$method} {$path}",
    );
}

// The safe ERROR page shows it, because the partner meeting an error is the one who needs to quote
// something. A 404 renders through the same error template as a 403.
$notFound = $kernel->handle($request('GET', '/nope'));
Assert::contains('Support reference', $notFound->body, 'the safe error page shows the reference');
Assert::contains(
    $notFound->headers[RequestId::HEADER],
    $notFound->body,
    'and the page and the header carry the SAME reference — two would be useless to quote',
);

// A CSRF failure is the other error a confused partner meets, and it carries one too.
$csrfFailure = $kernel->handle($request('POST', '/login', ['email' => 'x@y.z', 'password' => 'p']));
Assert::same(403, $csrfFailure->status, 'an unverified POST is refused');
Assert::true(
    RequestId::isWellFormed($csrfFailure->headers[RequestId::HEADER] ?? ''),
    'and the refusal carries a reference too',
);

// TWO REQUESTS, TWO REFERENCES. A constant would correlate nothing.
Assert::notSame(
    $kernel->handle($request('GET', '/login'))->headers[RequestId::HEADER],
    $kernel->handle($request('GET', '/login'))->headers[RequestId::HEADER],
    'each request gets its own reference',
);

// ============================================================ an onboarded partner must change

$operatorId = Seed::operator($db, $clock, $scope['tenant_id'], 'Invited partner');
$issued = 'an operator issued passphrase';
Assert::true(
    $accounts->createLogin($operatorId, 'partner@example.test', 'Invited partner', $issued)->isOk(),
    'the partner is onboarded with an operator-issued password',
);

$firstSignIn = $signIn('partner@example.test', $issued);
Assert::same(303, $firstSignIn['response']->status, 'the partner signs in');
Assert::same(
    '/account/password',
    $firstSignIn['response']->headers['Location'],
    'and lands on the password surface rather than the console',
);
$cookies = $firstSignIn['cookies'];
$csrf = $firstSignIn['csrf'];

// THE GATE, NOT THE REDIRECT, IS THE ENFORCEMENT. Every other authenticated surface refuses while
// the requirement stands — including the React shell document and every JSON read.
foreach (['/console', '/console/app', '/console/workspaces', '/console/decisions', '/account'] as $blocked) {
    $response = $kernel->handle($request('GET', $blocked, [], $cookies));
    Assert::same(303, $response->status, "{$blocked} is not reachable before the password is changed");
    Assert::same('/account/password', $response->headers['Location'], "and redirects to the password surface: {$blocked}");
}

foreach ($jsonReadPaths as $jsonPath) {
    $response = $kernel->handle($request('GET', $jsonPath, [], $cookies));
    Assert::same(403, $response->status, "{$jsonPath} fails closed under the lifecycle gate");
    $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    // A DISTINCT state. Not 401 — the session is valid and a re-login would land here again.
    Assert::same('PASSWORD_CHANGE_REQUIRED', $payload['status'], "with a distinct lifecycle reason: {$jsonPath}");
    Assert::equalsList(['PASSWORD_CHANGE_REQUIRED'], $payload['reason_codes'], 'and the matching reason code');
    // NO PRODUCT DATA, in any shape.
    Assert::false(array_key_exists('data', $payload), "and no data envelope at all: {$jsonPath}");
    Assert::false(array_key_exists('rows', $payload), "nor rows: {$jsonPath}");
    Assert::false(array_key_exists('briefings', $payload), "nor briefings: {$jsonPath}");
}

// Selecting a workspace is a state change, and it is behind the gate too.
$blockedSelect = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']],
    $cookies,
));
Assert::same(303, $blockedSelect->status, 'a workspace cannot be selected before the password is changed');
Assert::same('/account/password', $blockedSelect->headers['Location'], 'the gate covers state changes too');

// The two paths that ARE reachable.
$passwordPage = $kernel->handle($request('GET', '/account/password', [], $cookies));
Assert::same(200, $passwordPage->status, 'the password surface itself is reachable');
Assert::contains('Choose your password', $passwordPage->body, 'and says why it is compulsory');
Assert::contains('name="csrf_token" value="' . $csrf . '"', $passwordPage->body, 'carrying the session\'s own CSRF token');
// No dead-end rail: under the gate the navigation is not rendered, because every link in it leads
// somewhere this account may not go yet.
Assert::false(str_contains($passwordPage->body, 'Decision ledger'), 'the console rail is not rendered under the gate');
Assert::contains('Sign out instead', $passwordPage->body, 'but signing out is offered — an account that cannot leave teaches the wrong habit');

// ============================================================ changing it clears the requirement

// The current password is still required, even here.
$wrongCurrent = $kernel->handle($request('POST', '/account/password', [
    'csrf_token' => $csrf,
    'current_password' => 'not the issued password',
    'new_password' => 'a brand new long passphrase',
    'confirm_password' => 'a brand new long passphrase',
], $cookies));
Assert::same(400, $wrongCurrent->status, 'a change without the current password is refused');
Assert::contains('CURRENT_PASSWORD_INCORRECT', $wrongCurrent->body, 'naming the specific reason');
Assert::same(
    true,
    $accounts->findByEmailAddress('partner@example.test')['must_change_password'],
    'and the requirement still stands',
);

$mismatch = $kernel->handle($request('POST', '/account/password', [
    'csrf_token' => $csrf,
    'current_password' => $issued,
    'new_password' => 'a brand new long passphrase',
    'confirm_password' => 'a different long passphrase',
], $cookies));
Assert::same(400, $mismatch->status, 'a mistyped confirmation is refused before anything is written');
Assert::contains('PASSWORD_CONFIRMATION_MISMATCH', $mismatch->body, 'naming that specifically');

$chosen = 'a brand new long passphrase';
$changed = $kernel->handle($request('POST', '/account/password', [
    'csrf_token' => $csrf,
    'current_password' => $issued,
    'new_password' => $chosen,
    'confirm_password' => $chosen,
], $cookies));
Assert::same(303, $changed->status, 'the change succeeds');
Assert::same('/login?changed=1', $changed->headers['Location'], 'and sends the partner back to sign in');
Assert::contains('Max-Age=0', $changed->headers['Set-Cookie'], 'clearing the session cookie');
Assert::contains(Csrf::COOKIE_NAME . '=;', $changed->cookies[0] ?? '', 'and the CSRF cookie with it');

// EVERY session ended, including the one that made the change.
Assert::same(
    303,
    $kernel->handle($request('GET', '/console', [], $cookies))->status,
    'the old session no longer resolves',
);
Assert::same(
    '/login',
    $kernel->handle($request('GET', '/console', [], $cookies))->headers['Location'],
    'and is treated as anonymous, not as an account still under the gate',
);

// ============================================================ the console after the change

$second = $signIn('partner@example.test', $chosen);
Assert::same('/console', $second['response']->headers['Location'], 'the next sign-in lands on the console');
$cookies = $second['cookies'];
$csrf = $second['csrf'];

Assert::same(
    409,
    $kernel->handle($request('GET', '/console', [], $cookies))->status,
    'with no workspace selected the console says so — a distinct state from a denial',
);

// The JSON reads are no longer under the lifecycle gate; they answer on their own terms.
foreach ($jsonReadPaths as $jsonPath) {
    $payload = json_decode(
        $kernel->handle($request('GET', $jsonPath, [], $cookies))->body,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    Assert::notSame(
        'PASSWORD_CHANGE_REQUIRED',
        $payload['status'] ?? null,
        "{$jsonPath} is no longer blocked by the lifecycle gate",
    );
}

// ============================================================ the account surface

$accountPage = $kernel->handle($request('GET', '/account', [], $cookies));
Assert::same(200, $accountPage->status, 'the account surface renders');
Assert::contains('partner@example.test', $accountPage->body, 'showing the address this login signs in with');
Assert::contains('Invited partner', $accountPage->body, 'and the governed operator it acts as');
Assert::contains('Change password', $accountPage->body, 'with a route to changing the password');
Assert::contains(
    'not the authority itself',
    $accountPage->body,
    'and stating that the roles shown are presentation, re-decided on the server',
);

// IT SHOWS NO CREDENTIAL. The hash is in the same table as everything the page does show.
$storedHash = (string) $db->scalar(
    'SELECT password_hash FROM platform_users WHERE email = ?',
    ['partner@example.test']
);
Assert::false(str_contains($accountPage->body, $storedHash), 'the account page shows no password hash');
Assert::false(str_contains($accountPage->body, $cookies['af_session']), 'and no session token');
foreach (['password_hash', 'password_algo', 'token_hash'] as $column) {
    Assert::false(str_contains($accountPage->body, $column), "nor the {$column} column name");
}

// ============================================================ the support surface

$supportPage = $kernel->handle($request('GET', '/support', [], $cookies));
Assert::same(200, $supportPage->status, 'the support surface renders for a signed-in partner');
Assert::contains(
    $supportPage->headers[RequestId::HEADER],
    $supportPage->body,
    'and carries the same reference the response header does',
);
Assert::contains('Reply to the thread your operator opened with you.', $supportPage->body, 'showing the configured contact');
Assert::contains('test-release-ref', $supportPage->body, 'and the deployed release reference');

// THE CLAIM CEILING, restated where somebody surprised by the product will read it.
Assert::contains('is not buyer intent', $supportPage->body, 'the support page states that fit is not buyer intent');
Assert::contains('internal only', $supportPage->body, 'that outputs are internal only');
Assert::contains('No send, publish or CRM action exists', $supportPage->body, 'and that no external action exists');
Assert::contains('Reporting incorrect intelligence', $supportPage->body, 'separating incorrect intelligence from a technical failure');
Assert::contains('Known beta limitations', $supportPage->body, 'and naming the standing beta limitations');

// IT LEAKS NOTHING. Asserted against a configuration that HAS secrets in it.
foreach ([
    'super-secret-database-password',
    'super-secret-master-key',
    $cookies['af_session'],
    $storedHash,
    $scope['tenant_id'],
    $scope['workspace_id'],
    $scope['operator_id'],
] as $mustNotAppear) {
    Assert::false(
        $mustNotAppear !== '' && str_contains($supportPage->body, $mustNotAppear),
        'the support page discloses no secret or identifier',
    );
}
// The SESSION's own CSRF token IS on the page, in its own sign-out form, exactly as it is on every
// other authenticated page — and that is not a leak. It is scoped to this session, useless without
// the session cookie, and removing it would break the sign-out this page must not be without.
Assert::contains(
    'name="csrf_token" value="' . $csrf . '"',
    $supportPage->body,
    'the support page carries the session\'s own CSRF token for its own sign-out form',
);
foreach (['DB_PASSWORD', 'APP_MASTER_KEY', 'SELECT ', 'Stack trace', '/home/', '.env'] as $internal) {
    Assert::false(str_contains($supportPage->body, $internal), "the support page shows no internal detail: {$internal}");
}

// ============================================================ the workspace, then the shell

Seed::membership(
    $db,
    $clock,
    $scope['tenant_id'],
    $scope['workspace_id'],
    $operatorId,
    Seed::role($db, $clock, 'beta_http_reader', BetaBundle::PERMISSIONS)['role_id'],
);
$selected = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']],
    $cookies,
));
Assert::same(303, $selected->status, 'with a membership the workspace is selected');

Assert::contains(
    'None selected',
    $supportPage->body,
    'before a workspace is chosen the support page says so rather than guessing one',
);
$withWorkspace = $kernel->handle($request('GET', '/support', [], $cookies));
Assert::false(
    str_contains($withWorkspace->body, 'None selected'),
    'once a workspace is selected the support page reflects it',
);
$workspaceLabel = (string) $db->scalar(
    'SELECT display_name FROM workspaces WHERE workspace_id = ?',
    [$scope['workspace_id']]
);
Assert::contains($workspaceLabel, $withWorkspace->body, 'naming the workspace by its display label');
Assert::false(
    str_contains($withWorkspace->body, $scope['workspace_id']),
    'and never by identifier — a support page is a page people screenshot',
);

// The persistent utilities exist on the server-rendered console too.
$consolePage = $kernel->handle($request('GET', '/console', [], $cookies));
Assert::same(200, $consolePage->status, 'the console renders with a workspace selected');
foreach (['href="/account"', 'href="/support"', 'href="/console/workspaces"'] as $utility) {
    Assert::contains($utility, $consolePage->body, "the rail carries the persistent utility {$utility}");
}
Assert::contains('<form method="post" action="/logout"', $consolePage->body, 'and sign-out remains a POST');
Assert::contains(
    'name="csrf_token" value="' . $csrf . '"',
    $consolePage->body,
    'carrying the session\'s own CSRF token',
);
Assert::false(str_contains($consolePage->body, 'href="/logout"'), 'sign-out is never a GET link');

// A GET to /logout is not a route at all, which is the structural half of the same claim.
Assert::same(404, $kernel->handle($request('GET', '/logout', [], $cookies))->status, 'there is no GET sign-out route');

// And an authenticated POST still requires the SESSION's token, not a cookie pair the caller chose.
Assert::same(
    403,
    $kernel->handle($request(
        'POST',
        '/logout',
        ['csrf_token' => Csrf::issue()],
        $cookies,
    ))->status,
    'a token that is not the session\'s does not verify sign-out',
);

// ============================================================ suspension takes effect immediately

$live = $signIn('partner@example.test', $chosen);
Assert::same(303, $live['response']->status, 'the partner holds a live session');
Assert::same(
    200,
    $kernel->handle($request('GET', '/account', [], $live['cookies']))->status,
    'and can reach their account',
);

Assert::true($accounts->suspend((string) $accounts->findByEmailAddress('partner@example.test')['platform_user_id'])->isOk(), 'an operator suspends the account');
$afterSuspend = $kernel->handle($request('GET', '/account', [], $live['cookies']));
Assert::same(303, $afterSuspend->status, 'the live session stops working on the very next request');
Assert::same('/login', $afterSuspend->headers['Location'], 'and is treated as anonymous');
foreach ($jsonReadPaths as $jsonPath) {
    $response = $kernel->handle($request('GET', $jsonPath, [], $live['cookies']));
    Assert::same(401, $response->status, "a suspended account's JSON read is unauthenticated: {$jsonPath}");
}

// Reactivation does not hand the old session back.
Assert::true($accounts->reactivate((string) $accounts->findByEmailAddress('partner@example.test')['platform_user_id'])->isOk(), 'the account is reactivated');
Assert::same(
    303,
    $kernel->handle($request('GET', '/account', [], $live['cookies']))->status,
    'and the previously revoked session stays revoked — the partner signs in again',
);
Assert::same(
    '/console',
    $signIn('partner@example.test', $chosen)['response']->headers['Location'],
    'which works, and does not re-enter the password gate',
);
