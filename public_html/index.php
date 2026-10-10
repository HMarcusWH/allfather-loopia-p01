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
use Allfather\Loopia\Http\RequestLog;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Ops\MaintenanceGate;
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

// Build the request before configuration/database bootstrap so every failure can carry one reference.
$request = Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE);

/** Emit one expected pre-Kernel failure: stamped, recorded, and carrying no private diagnostic detail. */
$earlyFailure = static function (Response $response) use ($request): void {
    $stamped = $response->withHeader(RequestId::HEADER, $request->requestId);
    RequestLog::record($request->requestId, $request->method, $request->path, $stamped->status);
    $stamped->send();
};

// P10 operational quiescence happens before Bootstrap, authentication or DB composition. In
// particular, Authenticator::resolveSession updates last_seen_at, so checking later would make a
// "maintenance" GET a database writer. Keep the lease referenced until this process exits.
$maintenanceGate = new MaintenanceGate($root);
$maintenance = $maintenanceGate->acquireRuntimeLease();
if (!$maintenance->isOk()) {
    $earlyFailure(Response::json([
        'status' => 'MAINTENANCE',
        'reason_codes' => [$maintenance->reasonCode()],
        'request_reference' => $request->requestId,
    ], 503));
    return;
}
$maintenanceLease = $maintenance->value()['lease'];

// Last-resort boundary for failures that happen after bootstrap but before a request reaches Kernel
// (for example while a future composition root is being assembled). Kernel has its own boundary for
// dispatch/controller failures. Do not reuse `$earlyFailure` here: that closure describes the two
// expected startup refusals below, while this is an unexpected-failure diagnostic boundary.
set_exception_handler(static function (\Throwable $error) use ($request): void {
    RequestLog::recordFailure($request->requestId, $error);

    if (str_starts_with($request->path, '/console/api/')) {
        $response = Response::json([
            'status' => 'UNAVAILABLE',
            'reason_codes' => ['UNEXPECTED_ERROR'],
        ], 500);
    } else {
        $id = htmlspecialchars($request->requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response = Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>500</title></head>'
            . '<body><main><h1>500</h1><p>Something went wrong.</p><p>Support reference: <code>'
            . $id . '</code></p></main></body></html>',
            500,
        );
    }

    $stamped = $response->withHeader(RequestId::HEADER, $request->requestId);
    RequestLog::record($request->requestId, $request->method, $request->path, $stamped->status);
    $stamped->send();
});

try {
    $boot = Bootstrap::load($root);
} catch (\RuntimeException $error) {
    $earlyFailure(Response::json(
        ['status' => 'MISCONFIGURED', 'detail' => $error->getMessage()],
        503,
    ));
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
    $earlyFailure(Response::json(['status' => 'UNAVAILABLE'], 503));
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
$kernel = new Kernel($db, $clock, $auth, $view, $maintenanceGate);

$session = new SessionController($auth, $view);
$kernel->route('GET', '/login', $session->showLogin(...));
$kernel->route('POST', '/login', $session->login(...));
$kernel->route('POST', '/logout', $session->logout(...));

// The service root is an authenticated entry point, not a second public surface.
// Kernel still redirects anonymous requests to /login and enforces password change.
$kernel->route('GET', '/', static fn (Request $request, ?array $session): Response => Response::redirect('/console/app'));

$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('GET', '/console/workspaces', $workspaces->choose(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));

$accounts = new AccountService($db, $clock, $auth);
$account = new AccountController($accounts, $view);
$kernel->route('GET', '/account', $account->show(...));
$kernel->route('GET', '/account/password', $account->showPasswordForm(...));
$kernel->route('POST', '/account/password', $account->changePassword(...));

$support = new SupportController($view, $env, new SupportContextAdapter($db), $boot['environment']);
$kernel->route('GET', '/support', $support->show(...));

$contextAdapter = new ConsoleContextAdapter($db, $m12, $assurance);
$decisionAdapter = new DecisionLedgerAdapter($db, $clock, $access, $ledger);
$console = new ConsoleController($contextAdapter, $decisionAdapter, $view);
$kernel->route('GET', '/console', $console->workspace(...));
$kernel->route('GET', '/console/decisions', $console->decisions(...));

$operatorConsole = new OperatorConsoleController(new ConsoleAssetManifest($root . '/public_html/assets/operator-console'), $view);
$kernel->route('GET', '/console/app', $operatorConsole->shell(...));

// Inspection and manual composition UI. The composition is built for the session's workspace:
// live read and the provider wire exist only when that workspace holds an ADMITTED MANUAL_MONITOR
// grant, which needs a reviewed owner decision under docs/decisions/. None ships, so this is inert.
// The closure composes nothing until a Monitor route actually calls it.
$monitorFor = static fn (array $scope): \Allfather\Loopia\Workflow\MonitorOperations => \Allfather\Loopia\Workflow\MonitorFactory::forWorkspace(
    $db, $clock, new \Allfather\Loopia\Storage\EvidenceStore($root . '/storage/evidence'),
    \Allfather\Loopia\Provider\KeyRing::fromEnv($env),
    \Allfather\Loopia\Workflow\OwnerDecisionRegistry::forRoot($root),
    static fn (): string => \Allfather\Loopia\Ops\SourceFingerprint::compute($root),
    $scope['tenant_id'], $scope['workspace_id'],
);
if ($request->path === '/console/monitor') {
$monitorController = new \Allfather\Loopia\Http\Controllers\MonitorController($monitorFor, $view);
$kernel->route('GET', '/console/monitor', $monitorController->show(...));
$kernel->route('POST', '/console/monitor', $monitorController->submit(...));
}

// P09 administration forms over the same owners the private CLIs use. Admission of a schedule or a
// grant is NOT here: it needs a reviewed owner decision file and stays a command-line act.
if ($request->path === '/console/monitor/schedule') {
$scheduleFor = static function () use ($db, $clock, $root): \Allfather\Loopia\Workflow\ScheduleService {
    $decisions = \Allfather\Loopia\Workflow\OwnerDecisionRegistry::forRoot($root);
    $grants = new \Allfather\Loopia\Workflow\ExecutionGrantStore($db, $clock, new \Allfather\Loopia\PromptOps\PromptApprovalStore($db, $clock, $decisions), $decisions);
    return new \Allfather\Loopia\Workflow\ScheduleService($db, $clock, $grants, $decisions, new \Allfather\Loopia\Workflow\MissionConfiguration($db, $clock));
};
$scheduleController = new \Allfather\Loopia\Http\Controllers\MonitorScheduleController($scheduleFor, $view);
$kernel->route('GET', '/console/monitor/schedule', $scheduleController->show(...));
$kernel->route('POST', '/console/monitor/schedule', $scheduleController->submit(...));
}
if ($request->path === '/console/monitor/setup') {
$setupController = new \Allfather\Loopia\Http\Controllers\MonitorSetupController(
    static fn (): \Allfather\Loopia\Workflow\MonitorSetup => new \Allfather\Loopia\Workflow\MonitorSetup($db, $clock, new \Allfather\Loopia\Storage\EvidenceStore($root . '/storage/evidence')),
    $view,
);
$kernel->route('GET', '/console/monitor/setup', $setupController->show(...));
$kernel->route('POST', '/console/monitor/setup', $setupController->submit(...));
}

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

$reads = new ConsoleReadController($contextAdapter, $decisionAdapter, $p01Surfacing, $p02TwinRelease, $p02RelationshipRelease, $p03Release, $p04Release, $p05Release, new \Allfather\Loopia\Http\ReadAdapters\MonitorReadAdapter($monitorFor));
$kernel->route('GET', '/console/api/v1/context', $reads->context(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p01/battlecards', $reads->p01Battlecards(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/relationships', $reads->p02Relationships(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/monitored', $reads->p03Monitored(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p04/distributions', $reads->p04Distributions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p05/drafts', $reads->p05Drafts(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/m10/decisions', $reads->decisions(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entity-detail', $reads->p02EntityDetail(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/prospect-detail', $reads->p03ProspectDetail(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/monitor/runs', $reads->monitorRuns(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/monitor/run', $reads->monitorRun(...), 'JSON_401');

$kernel->route('GET', '/healthz', static fn (): Response => Response::json([
    'service' => 'allfather-loopia',
    'projection' => 'LOOPIA_SME_MVP',
    'customer_visible_output' => false,
    'external_effect' => false,
]));

$kernel->handle($request)->send();
