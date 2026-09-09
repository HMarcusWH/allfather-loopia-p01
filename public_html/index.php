<?php

declare(strict_types=1);

/**
 * The web entry point.
 *
 * Wiring only. Every decision this application makes lives in a governed module; this file
 * constructs them, maps routes, and hands the request over.
 */

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Controllers\ConsoleController;
use Allfather\Loopia\Http\Controllers\OperatorConsoleController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\SurfaceController;
use Allfather\Loopia\Http\Controllers\WorkspaceController;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
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
use Allfather\Loopia\Support\Bootstrap;

$root = dirname(__DIR__);

try {
    $boot = Bootstrap::load($root);
} catch (\RuntimeException $error) {
    // A deployment that may not start says so, and says it ONCE, before anything else runs. The
    // message names missing configuration KEYS — which are public, they are in `.env.example` —
    // and never a value, a path fragment or a driver detail.
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: no-store, private', true);
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
    // The reason is deliberately not echoed. A connection error can carry a hostname, a user name,
    // or a driver detail, and none of that belongs in a response to an unauthenticated caller.
    Response::json(['status' => 'UNAVAILABLE'], 503)->send();

    return;
}

$clock = new Clock();
$view = new View($root . '/app/Http/templates');
$auth = new Authenticator($db, $clock);
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$entitlements = new Entitlements($db, $clock);

$kernel = new Kernel($db, $clock, $auth, $view);

$session = new SessionController($auth, $view);
$kernel->route('GET', '/login', $session->showLogin(...));
$kernel->route('POST', '/login', $session->login(...));
$kernel->route('POST', '/logout', $session->logout(...));

// Which workspace the session acts in. Without this route a signed-in operator holding a
// membership had no way to reach their own workspace at all.
$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('GET', '/console/workspaces', $workspaces->choose(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));

$console = new ConsoleController(
    $db, $clock, $access, new Console($db, $clock, $access, $ledger, new Assurance($db, $clock, $ledger)),
    new Assurance($db, $clock, $ledger), $ledger, $view,
);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

// PR5 presentation shell only. The controller receives no product service, no M02 guard, no
// entitlement resolver and no database handle; governed browser reads remain deliberately unwired.
$operatorConsole = new OperatorConsoleController(
    new ConsoleAssetManifest($root . '/public_html/assets/operator-console'),
    $view,
);
$kernel->route('GET', '/console/app', $operatorConsole->shell(...));

$surfaces = new SurfaceController(
    new Surfacing($db, $access, $ledger, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
    $view,
);
$kernel->route('GET', '/console/briefings', $surfaces->briefings(...));
$kernel->route('GET', '/console/distributions', $surfaces->distributions(...));
$kernel->route('GET', '/console/marketing', $surfaces->marketing(...));

// A liveness probe that touches no governed state and says nothing about the tenant.
$kernel->route('GET', '/healthz', static fn (): Response => Response::json([
    'service' => 'allfather-loopia',
    'projection' => 'LOOPIA_SME_MVP',
    'customer_visible_output' => false,
    'external_effect' => false,
]));

$kernel->handle(Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE))->send();
