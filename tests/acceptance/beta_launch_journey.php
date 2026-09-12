<?php

declare(strict_types=1);

/**
 * The whole invited-partner journey, as ONE composition, driven through the real HTTP kernel.
 *
 * WHY THIS EXISTS ALONGSIDE THE TESTS THAT ALREADY PASS. Every step below is covered somewhere:
 * `beta_operations_over_http.php` proves the lifecycle gate, `console_over_http.php` proves the
 * authority rail, `console_read_api.php` proves the read envelopes. What none of them proves is
 * that the steps COMPOSE — that an account onboarded by the real service, forced through the real
 * password change, handed the real entitlement bundle and pointed at the real workspace chooser
 * ends up able to read exactly what it bought and nothing else, and that each operator act along
 * the way takes effect on the session that is already open.
 *
 * That is the thing a beta launch actually depends on, and it is the thing a suite of correct unit
 * boundaries can be entirely green about while the journey is broken in the seam between two of
 * them. The specific seams this walks:
 *
 *   a JSON read must fail as JSON, never as an HTML redirect — a `fetch()` handed a sign-in page
 *   renders it into the console;
 *   the lifecycle gate must cover surfaces nobody remembered to list;
 *   changing a password must end the session that changed it;
 *   having no workspace must be a DISTINCT state from being denied, with a route out of it;
 *   an owner denial must never arrive as an empty result;
 *   and every operator act — reset, suspend, reactivate, retire — must take effect on the open
 *   session NOW rather than at the next sign-in.
 *
 * IT ASSERTS SHAPES AND DECISIONS, NOT ROWS. Where a read succeeds, what is checked is the access
 * decision, the projection state and the source descriptor. The governed payload is somebody else's
 * test; here it would only make this file long enough that nobody reads the part that matters.
 */

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\ConsoleReadEnvelope;
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
use Allfather\Loopia\Product\P04\Distribution;
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

// A STAGE OF ITS OWN, rather than whatever `npm run stage` last produced. The generated public
// asset subtree exists in the CI job where the frontend was built and not in the database jobs, so
// a journey that read the real one would prove `/console/app` serves a shell in one lane and a 503
// in the other. The manifest contract itself is `operator_console_assets.php`'s subject; what this
// file needs is a stage that is always there.
$stage = sys_get_temp_dir() . '/allfather-journey-console-' . bin2hex(random_bytes(8));
@mkdir($stage . '/assets', 0o700, true);
file_put_contents($stage . '/console.J1K2L3M4.js', "console.log('shell');\n");
file_put_contents($stage . '/console.N5O6P7Q8.css', "body{}\n");
file_put_contents($stage . '/assets/logo-lockup.R9S8T7U6.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' . "\n");
file_put_contents($stage . '/manifest.json', (string) json_encode([
    'version' => 1,
    'entry' => ['script' => 'console.J1K2L3M4.js', 'style' => 'console.N5O6P7Q8.css'],
    'assets' => ['assets/logo-lockup.R9S8T7U6.svg'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {

$scope = Seed::tenantWorkspace($db, $clock, 'journey');

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
    'APP_RELEASE_REF' => 'journey-release-ref',
    // Present in configuration and never rendered, so "it does not leak" is proved against a
    // configuration that HAS secrets in it.
    'DB_PASSWORD' => 'journey-database-password',
    'APP_MASTER_KEY' => 'journey-master-key',
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

$kernel->route(
    'GET',
    '/console/app',
    (new OperatorConsoleController(new ConsoleAssetManifest($stage), $view))->shell(...),
);

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

// ALL NINE, including the two `beta_operations_over_http.php` does not register. A journey that
// exercised a subset would be a journey whose coverage depends on which list somebody updated last.
$readRoutes = [
    '/console/api/v1/context' => 'm12.consoleContext',
    '/console/api/v1/p01/battlecards' => 'p01.releasedBattlecards',
    '/console/api/v1/p02/entities' => 'p02.entitySnapshots',
    '/console/api/v1/p02/relationships' => 'p02.relationshipSnapshots',
    '/console/api/v1/p03/priority-queue' => 'p03.priorityQueue',
    '/console/api/v1/p03/monitored' => 'p03.monitoredProspects',
    '/console/api/v1/p04/distributions' => 'p04.roleDistributions',
    '/console/api/v1/p05/drafts' => 'p05.internalMarketingDrafts',
    '/console/api/v1/m10/decisions' => 'm10.decisions',
];
$kernel->route('GET', '/console/api/v1/context', $reads->context(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p01/battlecards', $reads->p01Battlecards(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/relationships', $reads->p02Relationships(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/monitored', $reads->p03Monitored(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p04/distributions', $reads->p04Distributions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p05/drafts', $reads->p05Drafts(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/m10/decisions', $reads->decisions(...), 'JSON_401');

$request = static fn (string $method, string $path, array $post = [], array $cookies = [], array $get = []): Request =>
    Request::fromGlobals(
        ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.17'],
        $get,
        $post,
        $cookies,
    );

/** Sign in through the front door and return the response with its cookie jar. */
$signIn = static function (string $email, string $password) use ($kernel, $request): array {
    $page = $kernel->handle($request('GET', '/login'));
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $page->cookies[0] ?? '', $pre);
    $preAuth = $pre[1] ?? '';
    $signedIn = $kernel->handle($request(
        'POST',
        '/login',
        ['csrf_token' => $preAuth, 'email' => $email, 'password' => $password],
        [Csrf::COOKIE_NAME => $preAuth],
    ));
    preg_match('/af_session=([^;]+)/', $signedIn->headers['Set-Cookie'] ?? '', $session);
    preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', $signedIn->cookies[0] ?? '', $csrf);

    return [
        'response' => $signedIn,
        'cookies' => ['af_session' => $session[1] ?? '', Csrf::COOKIE_NAME => $csrf[1] ?? ''],
        'csrf' => $csrf[1] ?? '',
    ];
};

$json = static fn ($response): array => json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

// ============================================================ 1-2. anonymous

// A BROWSER DOCUMENT REDIRECTS; A JSON READ DOES NOT. These are the same authentication failure
// answered two ways on purpose, and conflating them is how a `fetch()` ends up rendering a sign-in
// page into the console.
$anonShell = $kernel->handle($request('GET', '/console/app'));
Assert::same(303, $anonShell->status, 'anonymous: the React shell document redirects to sign-in');
Assert::same('/login', $anonShell->headers['Location'], 'and names the sign-in surface');

foreach (array_keys($readRoutes) as $path) {
    $response = $kernel->handle($request('GET', $path));
    Assert::same(401, $response->status, "anonymous: {$path} is a 401");
    Assert::contains('application/json', $response->headers['Content-Type'] ?? '', "and is JSON: {$path}");
    Assert::false(isset($response->headers['Location']), "and is NOT an HTML redirect: {$path}");
    $body = $json($response);
    Assert::same('UNAUTHENTICATED', $body['status'], "naming the state: {$path}");
    Assert::equalsList(['AUTHENTICATION_REQUIRED'], $body['reason_codes'], "and the reason: {$path}");
    Assert::false(array_key_exists('data', $body), "with no data envelope at all: {$path}");
    Assert::true(
        RequestId::isWellFormed($response->headers[RequestId::HEADER] ?? ''),
        "and a quotable support reference: {$path}",
    );
}

// ============================================================ 3. the invited partner is onboarded

// Through the REAL service, with the entitlement the beta actually sells. Nothing here writes a row
// an operator act could not produce.
//
// THE ROLE COMES FIRST, and the ordering is the product's rather than this test's convenience:
// `defineTier` refuses a permission the PLATFORM does not define, and the bundle mints none. The
// permissions enter the vocabulary through the governed registrations that grant them, which in a
// test is the role seed.
$partnerOperatorId = Seed::operator($db, $clock, $scope['tenant_id'], 'Invited partner');
$readerRole = Seed::role($db, $clock, 'journey_reader', [...BetaBundle::PERMISSIONS, 'decision.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $partnerOperatorId, $readerRole['role_id']);

Assert::true($beta->ensureTier()->isOk(), 'the beta tier exists');
Assert::true($beta->subscribeTenant($scope['tenant_id'])->isOk(), 'and the tenant holds it');

$issued = 'an operator issued passphrase';
$created = $accounts->createLogin($partnerOperatorId, 'partner@example.test', 'Invited partner', $issued);
Assert::true($created->isOk(), 'the partner is onboarded with an operator-issued password');
$partnerId = (string) $created->value()['platform_user_id'];
Assert::same(
    true,
    $accounts->findByEmailAddress('partner@example.test')['must_change_password'],
    'and must replace it before anything else',
);

$first = $signIn('partner@example.test', $issued);
Assert::same(303, $first['response']->status, 'authentication with the issued password succeeds');
Assert::same(
    '/account/password',
    $first['response']->headers['Location'],
    'and lands on the password surface rather than the console',
);
$cookies = $first['cookies'];
$csrf = $first['csrf'];

// ============================================================ 4-5. the gate covers everything

Assert::same(
    303,
    $kernel->handle($request('GET', '/console/app', [], $cookies))->status,
    'the React shell is refused before the password is replaced',
);
Assert::same(
    '/account/password',
    $kernel->handle($request('GET', '/console/app', [], $cookies))->headers['Location'],
    'and points at the one thing this account may do',
);

foreach ($readRoutes as $path => $source) {
    $response = $kernel->handle($request('GET', $path, [], $cookies));
    Assert::same(403, $response->status, "the lifecycle gate covers {$path}");
    $body = $json($response);
    // A DISTINCT STATE. Not 401 — the session is valid and re-authenticating would land here again.
    Assert::same('PASSWORD_CHANGE_REQUIRED', $body['status'], "with its own reason: {$path}");
    Assert::same('/account/password', $body['change_password_path'], "naming the remedy: {$path}");
    Assert::false(array_key_exists('data', $body), "and no governed data: {$path}");
}

// ============================================================ 6-7. changing it

$passwordPage = $kernel->handle($request('GET', '/account/password', [], $cookies));
Assert::same(200, $passwordPage->status, 'the password surface is reachable under the gate');
Assert::contains('name="csrf_token" value="' . $csrf . '"', $passwordPage->body, 'carrying the session CSRF token');

$chosen = 'a replacement passphrase the partner chose';
$noCsrf = $kernel->handle($request('POST', '/account/password', [
    'current_password' => $issued,
    'new_password' => $chosen,
    'confirm_password' => $chosen,
], $cookies));
Assert::same(403, $noCsrf->status, 'a password change without a CSRF token is refused');
Assert::same(
    true,
    $accounts->findByEmailAddress('partner@example.test')['must_change_password'],
    'and changes nothing',
);

$changed = $kernel->handle($request('POST', '/account/password', [
    'csrf_token' => $csrf,
    'current_password' => $issued,
    'new_password' => $chosen,
    'confirm_password' => $chosen,
], $cookies));
Assert::same(303, $changed->status, 'the change succeeds with a valid token');
Assert::same('/login?changed=1', $changed->headers['Location'], 'and sends the partner back to sign in');
Assert::same(
    false,
    $accounts->findByEmailAddress('partner@example.test')['must_change_password'],
    'the requirement is cleared',
);

// ============================================================ 8. every session ended, including this one

Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM platform_sessions WHERE platform_user_id = ? AND revoked_at IS NULL',
        [$partnerId]
    ),
    'no session survives the password change',
);
$staleShell = $kernel->handle($request('GET', '/console/app', [], $cookies));
Assert::same(303, $staleShell->status, 'the session that made the change no longer resolves');
Assert::same(
    '/login',
    $staleShell->headers['Location'],
    'and is anonymous rather than an account still under the gate',
);
Assert::same(
    401,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'and a JSON read from it is a 401, not a stale 200',
);

// ============================================================ 9-10. no workspace is its own state

$second = $signIn('partner@example.test', $chosen);
Assert::same(303, $second['response']->status, 'the replacement password authenticates');
$cookies = $second['cookies'];
$csrf = $second['csrf'];

$noWorkspace = $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies));
Assert::same(409, $noWorkspace->status, 'with no workspace selected the read is a 409');
$noWorkspaceBody = $json($noWorkspace);
Assert::same('m12.consoleContext', $noWorkspaceBody['source'], 'reporting the read it could not answer');
Assert::equalsList(['NO_ACTIVE_WORKSPACE'], $noWorkspaceBody['reason_codes'], 'and the state it is in');
Assert::same(null, $noWorkspaceBody['data'], 'with no data');
// NO REQUIRED PERMISSION, because no grant fixes this. Naming one would send an operator to ask for
// a permission they already hold.
Assert::same(
    null,
    $noWorkspaceBody['access']['required_permission'],
    'and no permission to go and ask for — this is a state, not a denial',
);

$consolePage = $kernel->handle($request('GET', '/console', [], $cookies));
Assert::same(409, $consolePage->status, 'the server-rendered console says the same thing');
Assert::contains('/console/workspaces', $consolePage->body, 'and points at the chooser, which is the remedy');

// ============================================================ 11-12. choosing a workspace

// A SECOND workspace this operator is NOT a member of, and a THIRD in another tenant entirely. The
// chooser is a membership projection: listing either would make it a workspace-enumeration surface.
$otherWorkspaceId = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $otherWorkspaceId,
    'tenant_id' => $scope['tenant_id'],
    'workspace_key' => 'ws-unjoined-' . substr($otherWorkspaceId, 0, 8),
    'schema_version' => '1',
    'display_name' => 'Workspace the partner does not belong to',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
]);
$foreign = Seed::tenantWorkspace($db, $clock, 'other-tenant');

$chooser = $kernel->handle($request('GET', '/console/workspaces', [], $cookies));
Assert::same(200, $chooser->status, 'the chooser renders');
Assert::contains($scope['workspace_id'], $chooser->body, 'offering the workspace this operator belongs to');
Assert::false(str_contains($chooser->body, $otherWorkspaceId), 'and not one in the same tenant they do not');
Assert::false(str_contains($chooser->body, $foreign['workspace_id']), 'and not another tenant\'s at all');

// THE LIST IS NOT THE AUTHORITY FOR THE POST. A workspace id in a form field is untrusted input
// whoever rendered the page before it, and a membership can be revoked between the two requests.
$refusedSelect = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $csrf, 'workspace_id' => $foreign['workspace_id']],
    $cookies,
));
Assert::same(403, $refusedSelect->status, 'selecting another tenant\'s workspace is refused');
$unjoinedSelect = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $csrf, 'workspace_id' => $otherWorkspaceId],
    $cookies,
));
Assert::same(403, $unjoinedSelect->status, 'and so is one in this tenant without a membership');
Assert::same(
    409,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'neither refusal selected anything',
);

$selected = $kernel->handle($request(
    'POST',
    '/console/workspace',
    ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']],
    $cookies,
));
Assert::same(303, $selected->status, 'the workspace this operator belongs to is selected');
Assert::same('/console', $selected->headers['Location'], 'and the console is where that leads');

// ============================================================ 13. the React shell

$shell = $kernel->handle($request('GET', '/console/app', [], $cookies));
Assert::same(200, $shell->status, 'the React shell is served to a signed-in operator with a workspace');
Assert::same(
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
    $shell->headers['Content-Security-Policy'],
    'under the React security profile exactly',
);
Assert::contains('/assets/operator-console/console.J1K2L3M4.js', $shell->body, 'loading the locally staged bundle');
Assert::contains('/assets/operator-console/console.N5O6P7Q8.css', $shell->body, 'and its stylesheet');
Assert::false(str_contains($shell->body, 'http://'), 'naming no remote origin');
Assert::false(str_contains($shell->body, '<script>'), 'and carrying no inline script');

// ============================================================ 14. the context read

$context = $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies));
Assert::same(200, $context->status, 'the context read answers');
$contextBody = $json($context);
Assert::same('m12.consoleContext', $contextBody['source'], 'reporting its exact descriptor');
Assert::same('VISIBLE', $contextBody['access']['decision'], 'as a visible read');
// IT DESCRIBES THE SESSION'S SCOPE WITHOUT NAMING IT. The workspace the session selected is the one
// described, and no identifier for it, its tenant or its operator crosses into the browser — which
// is why there is nothing for the injection attempts below to compare against, and nothing for a
// screenshot to disclose.
Assert::same('Workspace', $contextBody['data']['workspace']['display_name'], 'describing the selected workspace');
Assert::same('TEST', $contextBody['data']['workspace']['environment_class'], 'with the workspace\'s own environment');
Assert::same(
    'RELEASE_INTELLIGENCE',
    $contextBody['data']['workspace']['active_v1_capability_ceiling'],
    'and the ceiling its work is bounded by',
);
Assert::same(false, $contextBody['data']['workspace']['external_side_effects_allowed'], 'which permits no external effect');
foreach (['tenant_id', 'workspace_id', 'operator_id'] as $identifier) {
    Assert::false(str_contains($context->body, $scope[$identifier]), "the context names no {$identifier}");
}
Assert::true(in_array('journey_reader', $contextBody['data']['roles'], true), 'carrying the current M02 roles');
foreach (BetaBundle::PERMISSIONS as $permission) {
    Assert::true(
        in_array($permission, $contextBody['data']['permissions'], true),
        "and the entitled permission {$permission}",
    );
}

// ============================================================ 15. every permitted governed read

// The ledger stream this workspace's decisions live in. Environment-pinned, and the browser cannot
// choose which one — that is asserted below.
Assert::true($ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'TEST')->isOk(), 'a TEST ledger stream exists');
Assert::true($ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'DEMO')->isOk(), 'and a DEMO stream may coexist');

// P04 HAS A THIRD GATE the other reads do not: a real org role that nobody configured as a
// recipient is still not a recipient. Configuring SALES is an admin act, performed here through the
// product's own method rather than by inserting the projection row.
Assert::true(
    (new Distribution($db, $clock, $ledger))->configureRoleRedactionPolicy([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'target_org_role' => 'SALES',
        'redaction_rules' => ['REDACT_INDIVIDUAL_IDENTIFIERS'],
    ])->isOk(),
    'SALES is configured as a distribution target',
);

$lensed = [
    '/console/api/v1/p02/entities' => ['visibility_scope' => 'SALES'],
    '/console/api/v1/p02/relationships' => ['visibility_scope' => 'SALES'],
    '/console/api/v1/p04/distributions' => ['target_org_role' => 'SALES'],
];
foreach ($readRoutes as $path => $source) {
    $response = $kernel->handle($request('GET', $path, [], $cookies, $lensed[$path] ?? []));
    Assert::same(200, $response->status, "an entitled, role-backed read answers: {$path}");
    $body = $json($response);
    Assert::same($source, $body['source'], "reporting its exact descriptor: {$path}");
    Assert::same('VISIBLE', $body['access']['decision'], "as a visible read: {$path}");
    // AN AUTHORIZED EMPTY READ IS `EMPTY`, NEVER DENIED. Nothing has been released into this
    // workspace yet, and that is a different fact from not being allowed to look.
    Assert::true(
        in_array($body['projection']['state'], ['EMPTY', 'READY'], true),
        "with a projection state rather than a refusal: {$path}",
    );
    Assert::notSame('DENIED', $body['owner_state'], "and an owner state that is not a denial: {$path}");
}

// ============================================================ 16. the denial cases

// P02 WITHOUT ITS LENS. The owner rule needs an explicit visibility scope; absent it the read is a
// denial naming the missing rule rather than an empty list.
foreach (['/console/api/v1/p02/entities', '/console/api/v1/p02/relationships'] as $path) {
    $response = $kernel->handle($request('GET', $path, [], $cookies));
    Assert::same(403, $response->status, "{$path} without its visibility lens is denied");
    $body = $json($response);
    Assert::same('DENIED', $body['access']['decision'], "as an explicit denial: {$path}");
    Assert::equalsList(['VISIBILITY_RULE_MISSING'], $body['reason_codes'], "naming the missing rule: {$path}");
    Assert::same(null, $body['data'], "and carrying nothing: {$path}");
    // THE DISTINCTION THIS WHOLE FILE EXISTS FOR. An owner denial must never arrive looking like a
    // valid read that happened to find nothing.
    Assert::notSame('EMPTY', $body['projection']['state'], "an owner denial is not an empty result: {$path}");
}

// A CONFIGURED PERMISSION IS NOT A CONFIGURED RECIPIENT. `EXECUTIVE_CEO` is a real member of the
// org-role vocabulary and nobody made it a distribution target here, so the read is denied naming
// that — not an empty list, which would read as "the executives have no briefings".
$unconfigured = $kernel->handle(
    $request('GET', '/console/api/v1/p04/distributions', [], $cookies, ['target_org_role' => 'EXECUTIVE_CEO'])
);
Assert::same(403, $unconfigured->status, 'an unconfigured distribution target is denied');
Assert::equalsList(
    ['DISTRIBUTION_TARGET_NOT_CONFIGURED'],
    $json($unconfigured)['reason_codes'],
    'naming the admin act that is missing rather than the permission, which the caller holds',
);
$unknownRole = $kernel->handle(
    $request('GET', '/console/api/v1/p04/distributions', [], $cookies, ['target_org_role' => 'JANITOR'])
);
Assert::same(403, $unknownRole->status, 'and a role outside the vocabulary is denied too');
Assert::equalsList(['UNKNOWN_TARGET_ROLE'], $json($unknownRole)['reason_codes'], 'as an unknown role');

// A SUSPENDED ROLE IS NOT A MEMBERSHIP. The membership row stays ACTIVE; M02 refuses the role, and
// the browser context must not keep exposing it because M12's regenerable projection still has it.
$db->run("UPDATE roles SET status = 'SUSPENDED' WHERE role_id = ?", [$readerRole['role_id']]);
$suspendedRole = $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies));
Assert::same(403, $suspendedRole->status, 'an inactive role cannot keep context visible through an active membership');
Assert::equalsList(
    ['WORKSPACE_MEMBERSHIP_REQUIRED'],
    $json($suspendedRole)['reason_codes'],
    'naming the missing active role-backed membership',
);
Assert::same(null, $json($suspendedRole)['data'], 'and leaking no stale permissions');
$db->run("UPDATE roles SET status = 'ACTIVE' WHERE role_id = ?", [$readerRole['role_id']]);
Assert::same(
    200,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'restoring the role restores the read on the next regenerated pass',
);

// ============================================================ and UNAVAILABLE is its own answer too

// FIVE DISTINCT SEMANTICS, not four. 401 (no identity), 403 (identity without authority), 409 (a
// state no grant fixes) and 200+EMPTY (authorized, nothing to show) are exercised above against the
// live kernel. The fifth is the one a journey cannot stage without breaking the deployment it is
// driving: a read that FAILED. It is pinned here against the envelope itself.
//
// The distinction that matters is EMPTY versus UNAVAILABLE. An authorized read that found nothing
// is a fact about the tenant; a read that could not run is a fact about the deployment, and a
// console that showed the second as the first would tell a partner their workspace is empty when
// the truth is that nobody knows.
$unavailable = ConsoleReadEnvelope::unavailable('m10.decisions');
Assert::same(503, $unavailable->status, 'a read that could not run is a 503');
$unavailableBody = json_decode($unavailable->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('m10.decisions', $unavailableBody['source'], 'reporting which read it was');
Assert::equalsList(['READ_UNAVAILABLE'], $unavailableBody['reason_codes'], 'and naming the state');
Assert::same('UNAVAILABLE', $unavailableBody['projection']['state'], 'the projection is UNAVAILABLE');
Assert::notSame('EMPTY', $unavailableBody['projection']['state'], 'never EMPTY — that is a different fact');
Assert::same('ERROR', $unavailableBody['projection']['stale_status'], 'and its staleness is unknown-because-error');
Assert::same(null, $unavailableBody['data'], 'carrying no data');
// NOT DRESSED AS A DENIAL EITHER. A failed read is not a statement about the caller's authority,
// and naming a required permission would send them to ask for one they may already hold.
Assert::same(null, $unavailableBody['access']['required_permission'], 'and no permission to go and ask for');

// ============================================================ the browser cannot choose its scope

// FOUR THINGS THE CALLER MUST NOT BE ABLE TO SET. Three are identity; the fourth is the M10
// environment, which decides WHICH LEDGER STREAM answers and is a property of the workspace.
$injection = [
    'tenant_id' => $foreign['tenant_id'],
    'workspace_id' => $foreign['workspace_id'],
    'operator_id' => $foreign['operator_id'],
    'environment_class' => 'DEMO',
];
foreach ($readRoutes as $path => $source) {
    $plain = $kernel->handle($request('GET', $path, [], $cookies, $lensed[$path] ?? []));
    $injected = $kernel->handle($request('GET', $path, [], $cookies, ($lensed[$path] ?? []) + $injection));
    Assert::same($plain->status, $injected->status, "caller-supplied scope changes no status: {$path}");
    Assert::same(
        $json($plain)['access']['decision'],
        $json($injected)['access']['decision'],
        "nor the access decision: {$path}",
    );
    foreach ($injection as $key => $value) {
        Assert::false(
            str_contains($injected->body, (string) $value),
            "and is never reflected back: {$path} {$key}",
        );
    }
}
// The ledger is pinned to the WORKSPACE's environment, not the caller's.
$ledgerRead = $kernel->handle($request('GET', '/console/api/v1/m10/decisions', [], $cookies, ['environment_class' => 'DEMO']));
Assert::same(
    'TEST',
    $json($ledgerRead)['data']['environment_class'],
    'the M10 environment stays the workspace\'s even when the caller asks for another',
);

// ============================================================ 17. support

$supportPage = $kernel->handle($request('GET', '/support', [], $cookies));
Assert::same(200, $supportPage->status, 'the support surface renders');
Assert::contains('Reply to the thread your operator opened with you.', $supportPage->body, 'showing the configured contact');
Assert::contains('journey-release-ref', $supportPage->body, 'and the deployed release reference');
Assert::contains(
    $supportPage->headers[RequestId::HEADER],
    $supportPage->body,
    'and the same reference its own header carries, which is what makes "quote this" answerable',
);
foreach (['journey-database-password', 'journey-master-key'] as $secret) {
    Assert::false(str_contains($supportPage->body, $secret), 'and no configured secret');
}

// ============================================================ 18. an operator resets the password

// IMMEDIATELY, on the session that is already open. A reset that took effect at the next sign-in
// would leave a compromised session live for as long as it stayed open, which is the situation a
// reset exists to end.
$replacement = 'a second operator issued passphrase';
Assert::true($accounts->resetPassword($partnerId, $replacement)->isOk(), 'an operator resets the password');
Assert::same(
    401,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'the open session is invalid at once',
);
Assert::same(
    true,
    $accounts->findByEmailAddress('partner@example.test')['must_change_password'],
    'and the account must replace the operator-issued password again',
);
Assert::true(
    $auth->authenticate('partner@example.test', $chosen, '198.51.100.17', 'journey')->isRefusal(),
    'the password the partner chose no longer authenticates',
);

// ============================================================ 19. back through the gate

$third = $signIn('partner@example.test', $replacement);
Assert::same('/account/password', $third['response']->headers['Location'], 'the reset lands back on the password surface');
$cookies = $third['cookies'];
$csrf = $third['csrf'];
$settled = 'the passphrase the partner settled on';
$reChanged = $kernel->handle($request('POST', '/account/password', [
    'csrf_token' => $csrf,
    'current_password' => $replacement,
    'new_password' => $settled,
    'confirm_password' => $settled,
], $cookies));
Assert::same(303, $reChanged->status, 'and the second change succeeds the same way as the first');

$fourth = $signIn('partner@example.test', $settled);
$cookies = $fourth['cookies'];
$csrf = $fourth['csrf'];
$kernel->handle($request('POST', '/console/workspace', ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']], $cookies));
Assert::same(
    200,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'the partner is back in the console',
);

// ============================================================ 20. suspension

Assert::true($accounts->suspend($partnerId, 'JOURNEY_SUSPENDED')->isOk(), 'an operator suspends the account');
Assert::same(
    401,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'the open session is invalid at once, not at the next sign-in',
);
Assert::same(
    303,
    $kernel->handle($request('GET', '/console/app', [], $cookies))->status,
    'and the browser document is anonymous',
);
Assert::true(
    $auth->authenticate('partner@example.test', $settled, '198.51.100.17', 'journey')->isRefusal(),
    'a suspended account cannot authenticate at all',
);

// ============================================================ 21. reactivation

Assert::true($accounts->reactivate($partnerId)->isOk(), 'an operator reactivates the account');
Assert::same(
    401,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'the OLD session stays invalid — reactivation restores the account, never a session',
);
$fifth = $signIn('partner@example.test', $settled);
Assert::same(303, $fifth['response']->status, 'a fresh sign-in is what returns the partner to service');
$cookies = $fifth['cookies'];
$csrf = $fifth['csrf'];
$kernel->handle($request('POST', '/console/workspace', ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']], $cookies));
Assert::same(
    200,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'and the reads answer again',
);

// ============================================================ 22. retirement is terminal

Assert::true($accounts->retire($partnerId, 'JOURNEY_RETIRED')->isOk(), 'an operator retires the account');
Assert::same(
    401,
    $kernel->handle($request('GET', '/console/api/v1/context', [], $cookies))->status,
    'the open session ends immediately',
);
Assert::true(
    $auth->authenticate('partner@example.test', $settled, '198.51.100.17', 'journey')->isRefusal(),
    'and authentication is refused',
);
Assert::true(
    $accounts->reactivate($partnerId)->isRefusal(),
    'there is no transition out of retirement — it is terminal, and a second lifecycle command says so',
);

// ============================================================ P03 keeps its siblings apart

// TWO ACCOUNTS, ONE PERMISSION EACH. The two P03 reads are siblings on the same release table, and
// the failure they are shaped against is one permission being treated as authority over both — a
// queue-only partner reading the monitored list, or the reverse. Each is proved to be DENIED rather
// than empty, because an empty list would look like "there is nothing to see".
$queueOnly = Seed::operator($db, $clock, $scope['tenant_id'], 'Queue-only partner');
Seed::membership(
    $db,
    $clock,
    $scope['tenant_id'],
    $scope['workspace_id'],
    $queueOnly,
    Seed::role($db, $clock, 'journey_queue_only', ['priority_outreach_queue.read'])['role_id'],
);
$prospectOnly = Seed::operator($db, $clock, $scope['tenant_id'], 'Prospect-only partner');
Seed::membership(
    $db,
    $clock,
    $scope['tenant_id'],
    $scope['workspace_id'],
    $prospectOnly,
    Seed::role($db, $clock, 'journey_prospect_only', ['prospect.read'])['role_id'],
);

$sibling = static function (string $operatorId, string $email) use ($accounts, $auth, $kernel, $request, $scope): array {
    Assert::true(
        $accounts->createLogin($operatorId, $email, 'Sibling partner', 'an issued sibling passphrase', false)->isOk(),
        "{$email} is onboarded",
    );
    $signedIn = $auth->authenticate($email, 'an issued sibling passphrase', '198.51.100.17', 'journey-sibling');
    Assert::true($signedIn->isOk(), "{$email} authenticates");
    $tokens = $signedIn->value();
    Assert::true(
        $auth->setActiveWorkspace($tokens['session_id'], $scope['workspace_id'])->isOk(),
        "{$email} selects the workspace",
    );

    return ['af_session' => $tokens['session_token'], Csrf::COOKIE_NAME => $tokens['csrf_token']];
};

foreach ([
    ['queue-only@example.test', $queueOnly, '/console/api/v1/p03/priority-queue', '/console/api/v1/p03/monitored', 'prospect.read'],
    ['prospect-only@example.test', $prospectOnly, '/console/api/v1/p03/monitored', '/console/api/v1/p03/priority-queue', 'priority_outreach_queue.read'],
] as [$email, $operatorId, $allowed, $refused, $missingPermission]) {
    $siblingCookies = $sibling($operatorId, $email);

    $permitted = $kernel->handle($request('GET', $allowed, [], $siblingCookies));
    Assert::same(200, $permitted->status, "{$email} may read {$allowed}");
    Assert::same('VISIBLE', $json($permitted)['access']['decision'], "as a visible read: {$email}");

    $denied = $kernel->handle($request('GET', $refused, [], $siblingCookies));
    Assert::same(403, $denied->status, "{$email} may NOT read its sibling {$refused}");
    $deniedBody = $json($denied);
    Assert::same('DENIED', $deniedBody['access']['decision'], "as an explicit denial: {$email}");
    Assert::same(
        $missingPermission,
        $deniedBody['access']['required_permission'],
        "naming the permission that is missing rather than the one they hold: {$email}",
    );
    Assert::same(null, $deniedBody['data'], "and carrying no rows: {$email}");
    Assert::notSame(
        'EMPTY',
        $deniedBody['projection']['state'],
        "a sibling denial is never dressed as an empty list: {$email}",
    );
}

} finally {
    $removeTree($stage);
}
