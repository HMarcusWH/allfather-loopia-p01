<?php

declare(strict_types=1);

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Controllers\AccountController;
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\ConsoleReadController;
use Allfather\Loopia\Http\Controllers\OperatorConsoleController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\SupportController;
use Allfather\Loopia\Http\Controllers\SurfaceController;
use Allfather\Loopia\Http\Controllers\WorkspaceController;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\ReadAdapters\ConsoleContextAdapter;
use Allfather\Loopia\Http\ReadAdapters\DecisionLedgerAdapter;
use Allfather\Loopia\Http\ReadAdapters\SupportContextAdapter;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\RequestId;
use Allfather\Loopia\Http\Response;
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
use Allfather\Loopia\Support\Bootstrap;

$root = dirname(__DIR__);

// ISSUED BEFORE ANYTHING CAN FAIL, and deliberately before configuration is even read.
//
// The Kernel stamps every response it produces, but the two failures below happen BEFORE a Kernel
// or a Request exists — and they are a misconfigured deployment and an unreachable database, which
// are exactly the two a beta partner is most likely to be looking at when they go hunting for
// something to quote. A reference that existed for every response except the ones that most need
// it would be a feature that works whenever it does not matter.
//
// The Request issued further down adopts this same value, so one request has one reference however
// far it gets.
$requestId = RequestId::issue();

try {
    $boot = Bootstrap::load($root);
} catch (\RuntimeException $error) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: no-store, private', true);
    header(RequestId::HEADER . ': ' . $requestId, true);
    echo json_encode(['status' => 'MISCONFIGURED', 'detail' => $error->getMessage()], JSON_THROW_ON_ERROR);
    return;
}
$env = $boot['env'];
Bootstrap::applyErrorPosture($boot['strict']);

try {
    $db = Database::connect(
        $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
        $env->int('DB_PORT', 3306),
        $env->require('DB_NAME'),
        $env->require('DB_USER'),
        $env->get('DB_PASSWORD', '') ?? '',
        $env->get('DB_SOCKET'),
    );
} catch (\Throwable) {
    Response::json(['status' => 'UNAVAILABLE'], 503)
        ->withHeader(RequestId::HEADER, $requestId)
        ->send();
    return;
}

$clock = new Clock();
$view = new View($root . '/app/Http/templates');
$auth = new Authenticator($db, $clock);
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);
$entitlements = new Entitlements($db, $clock);
$m12 = new Console($db, $clock, $access, $ledger, $assurance);
$kernel = new Kernel($db, $clock, $auth, $view);

$session = new SessionController($auth, $view);
$kernel->route('GET', '/login', $session->showLogin(...));
$kernel->route('POST', '/login', $session->login(...));
$kernel->route('POST', '/logout', $session->logout(...));

$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('GET', '/console/workspaces', $workspaces->choose(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));

// The account lifecycle surfaces. `/account/password` is the ONE authenticated path reachable while
// `must_change_password` is set, which is why it is a server-rendered form rather than a screen in
// the React shell: the shell's own route is behind the same gate.
$accounts = new AccountService($db, $clock, $auth);
$account = new AccountController($accounts, $view);
$kernel->route('GET', '/account', $account->show(...));
$kernel->route('GET', '/account/password', $account->showPasswordForm(...));
$kernel->route('POST', '/account/password', $account->changePassword(...));

// Help and support. It holds no database handle and no governed service — one session-scoped read
// through its adapter, and two non-secret configuration keys.
$support = new SupportController($view, $env, new SupportContextAdapter($db), $boot['environment']);
$kernel->route('GET', '/support', $support->show(...));

$contextAdapter = new ConsoleContextAdapter($db, $m12, $assurance);
$decisionAdapter = new DecisionLedgerAdapter($db, $clock, $access, $ledger);
$console = new ConsoleController($contextAdapter, $decisionAdapter, $view);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

$operatorConsole = new OperatorConsoleController(new ConsoleAssetManifest($root . '/public_html/assets/operator-console'), $view);
$kernel->route('GET', '/console/app', $operatorConsole->shell(...));

$p01Surfacing = new Surfacing($db, $access, $ledger, $entitlements);
$p04Release = new P04Release($db, $clock, $ledger, $access, $entitlements);
$p05Release = new P05Release($db, $clock, $ledger, $access, $entitlements);
$surfaces = new SurfaceController($p01Surfacing, $p04Release, $p05Release, $view);
$kernel->route('GET', '/console/briefings', $surfaces->briefings(...));
$kernel->route('GET', '/console/distributions', $surfaces->distributions(...));
$kernel->route('GET', '/console/marketing', $surfaces->marketing(...));

$m16 = new ReleaseOutput($db, $clock, $ledger);
$p02M16 = new P02Adapter($db, $m16);
$p02TwinRelease = new TwinRelease($db, $clock, $ledger, new EntityTwins($db, $clock), $access, $entitlements, $p02M16);
$p02RelationshipRelease = new RelationshipRelease($db, $clock, $ledger, new RelationshipTwins($db, $clock), $access, $entitlements, $p02M16);
$p03Release = new P03Release($db, $clock, $ledger, $access, $entitlements);

$reads = new ConsoleReadController($contextAdapter, $decisionAdapter, $p01Surfacing, $p02TwinRelease, $p02RelationshipRelease, $p03Release, $p04Release, $p05Release);
$kernel->route('GET', '/console/api/v1/context', $reads->context(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p01/battlecards', $reads->p01Battlecards(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/relationships', $reads->p02Relationships(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/monitored', $reads->p03Monitored(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p04/distributions', $reads->p04Distributions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p05/drafts', $reads->p05Drafts(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/m10/decisions', $reads->decisions(...), 'JSON_401');

$kernel->route('GET', '/healthz', static fn (): Response => Response::json([
    'service' => 'allfather-loopia',
    'projection' => 'LOOPIA_SME_MVP',
    'customer_visible_output' => false,
    'external_effect' => false,
]));

$kernel->handle(Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE, $requestId))->send();
