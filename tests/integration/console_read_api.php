<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\ConsoleReadController;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\ReadAdapters\ConsoleContextAdapter;
use Allfather\Loopia\Http\ReadAdapters\DecisionLedgerAdapter;
use Allfather\Loopia\Http\Request;
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
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'browser-read');
$role = Seed::role($db, $clock, 'browser-reader', ['decision.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id'], 'browser-read@example.test');

$auth = new Authenticator($db, $clock);
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);
$entitlements = new Entitlements($db, $clock);
$m12 = new Console($db, $clock, $access, $ledger, $assurance);
$context = new ConsoleContextAdapter($db, $m12, $assurance);
$decisions = new DecisionLedgerAdapter($db, $clock, $access, $ledger);
$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');

$releaseOutput = new ReleaseOutput($db, $clock, $ledger);
$p02Adapter = new P02Adapter($db, $releaseOutput);
$reads = new ConsoleReadController(
    $context,
    $decisions,
    new Surfacing($db, $access, $ledger, $entitlements),
    new TwinRelease($db, $clock, $ledger, new EntityTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new RelationshipRelease($db, $clock, $ledger, new RelationshipTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new P03Release($db, $clock, $ledger, $access, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
);
$console = new ConsoleController($context, $decisions, $view);
$kernel = new Kernel($db, $clock, $auth, $view);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/api/v1/context', $reads->context(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p01/battlecards', $reads->p01Battlecards(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/relationships', $reads->p02Relationships(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/monitored', $reads->p03Monitored(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p04/distributions', $reads->p04Distributions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p05/drafts', $reads->p05Drafts(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/m10/decisions', $reads->decisions(...), 'JSON_401');

$request = static fn (string $path, array $query = [], array $cookies = []): Request => Request::fromGlobals(
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.66'],
    $query,
    [],
    $cookies,
);

$anonymousHtml = $kernel->handle($request('/console'));
Assert::same(303, $anonymousHtml->status, 'ordinary authenticated HTML still redirects unidentified callers');
Assert::same('/login', $anonymousHtml->headers['Location'], 'HTML authentication behavior is unchanged');

foreach ([
    '/console/api/v1/context',
    '/console/api/v1/p01/battlecards',
    '/console/api/v1/p02/entities',
    '/console/api/v1/p02/relationships',
    '/console/api/v1/p03/priority-queue',
    '/console/api/v1/p03/monitored',
    '/console/api/v1/p04/distributions',
    '/console/api/v1/p05/drafts',
    '/console/api/v1/m10/decisions',
] as $apiPath) {
    $anonymousApi = $kernel->handle($request($apiPath));
    Assert::same(401, $anonymousApi->status, "{$apiPath} returns JSON 401 instead of a login redirect");
    Assert::true(str_contains($anonymousApi->headers['Content-Type'], 'application/json'), "{$apiPath} authentication failure is JSON");
    Assert::same(
        'AUTHENTICATION_REQUIRED',
        json_decode($anonymousApi->body, true, 512, JSON_THROW_ON_ERROR)['reason_codes'][0],
        "{$apiPath} names the authentication failure explicitly",
    );
}

$signedIn = $auth->authenticate($user['email'], $user['password'], '203.0.113.66', 'console-read-api-test');
Assert::true($signedIn->isOk(), 'test operator receives a real session');
$tokens = $signedIn->value();
$cookies = ['af_session' => $tokens['session_token'], Csrf::COOKIE_NAME => $tokens['csrf_token']];

$noWorkspace = $kernel->handle($request('/console/api/v1/context', [], $cookies));
Assert::same(409, $noWorkspace->status, 'authenticated API distinguishes missing workspace from authorization denial');
$noWorkspaceBody = json_decode($noWorkspace->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('m12.consoleContext', $noWorkspaceBody['source'], 'missing-workspace response keeps the context descriptor source');
Assert::same(['NO_ACTIVE_WORKSPACE'], $noWorkspaceBody['reason_codes'], 'missing-workspace response carries only the named reason');

$selected = $auth->setActiveWorkspace($tokens['session_id'], $scope['workspace_id']);
Assert::true($selected->isOk(), 'active workspace is selected through the real authenticator gate');

$testStream = $ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'TEST');
Assert::true($testStream->isOk(), 'TEST decision stream exists');
$demoStream = $ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'DEMO');
Assert::true($demoStream->isOk(), 'a second ACTIVE DEMO stream may coexist in the same tenant/workspace');

$contextResponse = $kernel->handle($request('/console/api/v1/context', [
    'tenant_id' => 'caller-controlled-tenant',
    'workspace_id' => 'caller-controlled-workspace',
    'operator_id' => 'caller-controlled-operator',
], $cookies));
Assert::same(200, $contextResponse->status, 'context uses server session scope even when scope-like query keys are supplied');
$contextBody = json_decode($contextResponse->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('m12.consoleContext', $contextBody['source'], 'visible context reports the exact read descriptor source');
Assert::same('TEST', $contextBody['data']['workspace']['environment_class'], 'context reports the server-selected workspace environment');
Assert::true(in_array('browser-reader', $contextBody['data']['roles'], true), 'M12 context is rebuilt from current membership');
Assert::false(str_contains($contextResponse->body, 'caller-controlled-tenant'), 'caller tenant input is not reflected into the governed context');
Assert::false(str_contains($contextResponse->body, 'caller-controlled-workspace'), 'caller workspace input is not reflected into the governed context');
Assert::false(str_contains($contextResponse->body, 'caller-controlled-operator'), 'caller operator input is not reflected into the governed context');

// A membership can remain ACTIVE while its role lifecycle is no longer ACTIVE. M02 refuses that
// role for permission grants; browser context must not expose the stale role, its permissions, or
// workspace metadata merely because M12's regenerable projection still contains the membership.
$db->run("UPDATE roles SET status = 'SUSPENDED' WHERE role_id = ?", [$role['role_id']]);
$suspendedRoleContext = $kernel->handle($request('/console/api/v1/context', [], $cookies));
Assert::same(403, $suspendedRoleContext->status, 'an inactive role cannot keep browser context visible through an active membership');
$suspendedRoleBody = json_decode($suspendedRoleContext->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('DENIED', $suspendedRoleBody['access']['decision'], 'inactive-role context is an explicit denial');
Assert::same(['WORKSPACE_MEMBERSHIP_REQUIRED'], $suspendedRoleBody['reason_codes'], 'inactive-role context names the missing active role-backed membership');
Assert::same(null, $suspendedRoleBody['data'], 'inactive-role context leaks no workspace metadata or stale permissions');
$db->run("UPDATE roles SET status = 'ACTIVE' WHERE role_id = ?", [$role['role_id']]);
$restoredContext = $kernel->handle($request('/console/api/v1/context', [], $cookies));
Assert::same(200, $restoredContext->status, 'reactivating the role restores context on the next regenerated read');

$ledgerResponse = $kernel->handle($request('/console/api/v1/m10/decisions', ['environment_class' => 'DEMO'], $cookies));
Assert::same(200, $ledgerResponse->status, 'authorized decision read succeeds');
$ledgerBody = json_decode($ledgerResponse->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('m10.decisions', $ledgerBody['source'], 'decision response reports the exact ledger descriptor source');
Assert::same('TEST', $ledgerBody['data']['environment_class'], 'workspace environment pins the ledger even when caller requests DEMO');
Assert::same('EMPTY', $ledgerBody['projection']['state'], 'an authorized empty ledger is EMPTY rather than denied');

$p02MissingLens = $kernel->handle($request('/console/api/v1/p02/entities', [], $cookies));
Assert::same(403, $p02MissingLens->status, 'P02 requires an explicit visibility lens');
$p02Body = json_decode($p02MissingLens->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same('p02.entitySnapshots', $p02Body['source'], 'P02 lens denial reports the exact entity descriptor source');
Assert::same('DENIED', $p02Body['access']['decision'], 'missing P02 lens is an explicit denial');
Assert::same(['VISIBILITY_RULE_MISSING'], $p02Body['reason_codes'], 'missing P02 lens preserves the owner visibility reason');

foreach ([
    ['/console/api/v1/p01/battlecards', [], 'p01.releasedBattlecards'],
    ['/console/api/v1/p02/relationships', ['visibility_scope' => 'SALES'], 'p02.relationshipSnapshots'],
    ['/console/api/v1/p03/priority-queue', [], 'p03.priorityQueue'],
    ['/console/api/v1/p03/monitored', [], 'p03.monitoredProspects'],
    ['/console/api/v1/p04/distributions', ['target_org_role' => 'SALES'], 'p04.roleDistributions'],
    ['/console/api/v1/p05/drafts', [], 'p05.internalMarketingDrafts'],
] as [$path, $query, $source]) {
    $response = $kernel->handle($request($path, $query, $cookies));
    Assert::same(403, $response->status, "{$path} preserves the owner M02/tier denial rather than treating it as empty");
    $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    Assert::same($source, $body['source'], "{$path} reports the exact read descriptor source");
    Assert::same('DENIED', $body['access']['decision'], "{$path} exposes owner denial as denial");
    Assert::same(null, $body['data'], "{$path} returns no partial governed rows on denial");
}

$noBattlecard = $kernel->handle($request('/console/api/v1/p03/battlecards', [], $cookies));
Assert::same(404, $noBattlecard->status, 'PR6 does not invent a P03 battlecard route');
