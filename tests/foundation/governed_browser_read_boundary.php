<?php

declare(strict_types=1);

/** Structural tripwires for PR6's deliberately narrow browser-read seam. */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$index = (string) file_get_contents($root . '/public_html/index.php');
$kernel = (string) file_get_contents($root . '/app/Http/Kernel.php');
$controller = (string) file_get_contents($root . '/app/Http/Controllers/ConsoleReadController.php');
$ledgerAdapter = (string) file_get_contents($root . '/app/Http/ReadAdapters/DecisionLedgerAdapter.php');
$entry = (string) file_get_contents($root . '/frontend/console/src/index.jsx');
$httpDatasource = (string) file_get_contents($root . '/frontend/console/src/data/http-console-data-source.js');
$meta = (string) file_get_contents($root . '/frontend/console/src/data/surface-reads.js');

$routes = [
    '/console/api/v1/context',
    '/console/api/v1/p01/battlecards',
    '/console/api/v1/p02/entities',
    '/console/api/v1/p02/own-company-drafts',
    '/console/api/v1/p02/relationships',
    '/console/api/v1/p03/priority-queue',
    '/console/api/v1/p03/monitored',
    '/console/api/v1/p04/distributions',
    '/console/api/v1/p05/drafts',
    '/console/api/v1/m10/decisions',
    '/console/api/v1/p02/entity-detail',
    '/console/api/v1/p03/prospect-detail',
    '/console/api/v1/monitor/runs',
    '/console/api/v1/monitor/run',
];
foreach ($routes as $route) {
    Assert::contains("\$kernel->route('GET', '{$route}'", $index, "PR6 registers exact GET read route {$route}");
}
Assert::false(str_contains($index, '/console/api/v1/p03/battlecards'), 'no P03 battlecard route is invented');
Assert::false(str_contains($index, '/console/api/v1/collection'), 'there is no generic browser collection route');
Assert::false(str_contains($index, '/console/api/v1/query'), 'there is no generic browser query route');
Assert::false(str_contains($index, '/console/api/v1/table'), 'there is no generic raw-table browser route');

Assert::contains("'JSON_401'", $index, 'console API routes opt into JSON authentication failure');
Assert::contains("'REDIRECT_LOGIN'", $kernel, 'ordinary protected HTML keeps redirect authentication semantics');
Assert::contains("'JSON_401'", $kernel, 'Kernel exposes only the explicit JSON API authentication mode');
Assert::false(str_contains($kernel, 'AccessGuard'), 'Kernel still performs identity/session routing only, never M02 authorization');

foreach (['tenant_id', 'workspace_id', 'actor_id'] as $scopeKey) {
    Assert::contains("'{$scopeKey}' => (string) \$session[", $controller, "browser scope {$scopeKey} is derived from resolved session state");
}
Assert::contains("'actor_type' => 'OPERATOR'", $controller, 'browser actor type is fixed server-side');
Assert::false(str_contains($controller, "\$request->query['tenant_id']"), 'browser cannot supply tenant scope');
Assert::false(str_contains($controller, "\$request->query['workspace_id']"), 'browser cannot supply workspace scope');
Assert::false(str_contains($controller, "\$request->query['operator_id']"), 'browser cannot supply operator identity');
Assert::contains("\$request->query['visibility_scope']", $controller, 'P02 exposes only its explicit owner visibility lens');
Assert::contains("\$request->query['target_org_role']", $controller, 'P04 exposes only its explicit owner role lens');

// EVERY READ FAILS CLOSED, and it is the COVERAGE that is the invariant rather than any one read.
// `safe()` converts a throw into a 503 UNAVAILABLE envelope carrying no data; a read method that
// forgot to route through it would answer a caller with whatever an uncaught exception produces —
// a 500 page, a stack trace, or in a misconfigured deployment the query that failed.
//
// So the assertion is arithmetic rather than a spot-check: as many `safe()` calls as there are
// public read methods, and no route registered for a method that is not one of them.
$readMethods = [];
foreach ((new ReflectionClass(\Allfather\Loopia\Http\Controllers\ConsoleReadController::class))
    ->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if (!$method->isConstructor()) {
        $readMethods[] = $method->getName();
    }
}
sort($readMethods, SORT_STRING);
Assert::equalsList(
    [
        'context', 'decisions', 'monitorRun', 'monitorRuns', 'p01Battlecards', 'p02Entities', 'p02EntityDetail', 'p02OwnCompanyDrafts', 'p02Relationships',
        'p03Monitored', 'p03PriorityQueue', 'p03ProspectDetail', 'p04Distributions', 'p05Drafts',
    ],
    $readMethods,
    'the browser read controller exposes exactly the fourteen registered reads (plus own-company draft) and nothing else',
);
// P09 detail selectors are the only other query values a read accepts, and each is a SELECTOR the
// owner re-checks against the session scope — never scope itself.
foreach (["\$request->query['snapshot']", "\$request->query['output']", "\$request->query['run']"] as $selector) {
    Assert::contains($selector, $controller, "a P09 detail read takes only its selector {$selector}");
}
// Every /console/api/v1 route the front controller registers is a GET with JSON authentication.
preg_match_all('#\$kernel->route\(\'([A-Z]+)\', \'(/console/api/v1/[^\']+)\'[^;]*;#', $index, $apiRoutes, PREG_SET_ORDER);
Assert::same(count($routes), count($apiRoutes), 'no API route exists beyond the explicit list');
foreach ($apiRoutes as [$whole, $method, $path]) {
    Assert::same('GET', $method, "{$path} is a read");
    Assert::contains("'JSON_401'", $whole, "{$path} answers an anonymous caller with JSON 401");
}
Assert::same(count($apiRoutes), count(array_unique(array_column($apiRoutes, 2))), 'and no API route is registered twice');
// ASSERTED PER METHOD, not by counting call sites. The two P03 siblings delegate to one private
// helper, so there are eight `safe()` sites for nine reads — and a count would therefore have to
// encode that arithmetic, which is exactly the kind of assertion that gets "fixed" by bumping the
// number when a tenth read arrives unwrapped.
$controllerLines = file($root . '/app/Http/Controllers/ConsoleReadController.php');
foreach ($readMethods as $name) {
    $method = new ReflectionMethod(\Allfather\Loopia\Http\Controllers\ConsoleReadController::class, $name);
    $body = implode('', array_slice(
        $controllerLines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));
    Assert::true(
        str_contains($body, '$this->safe(') || str_contains($body, '$this->p03Read('),
        "{$name}() routes its read through the fail-closed wrapper",
    );
}
// And the wrapper is what the name claims: the helper the P03 pair delegates to wraps too, so the
// indirection cannot be the way a read escapes.
$p03Helper = new ReflectionMethod(\Allfather\Loopia\Http\Controllers\ConsoleReadController::class, 'p03Read');
Assert::contains(
    '$this->safe(',
    implode('', array_slice(
        $controllerLines,
        $p03Helper->getStartLine() - 1,
        $p03Helper->getEndLine() - $p03Helper->getStartLine() + 1,
    )),
    'and the shared P03 helper wraps as well — the indirection is not an escape hatch',
);
Assert::contains(
    'catch (\\Throwable) { return ConsoleReadEnvelope::unavailable($source); }',
    $controller,
    'which converts ANY throw into an UNAVAILABLE envelope rather than letting it reach the caller',
);

Assert::contains('environment_class = ? AND status = \'ACTIVE\'', $ledgerAdapter, 'M10 lookup pins environment as part of active stream identity');
Assert::false(str_contains($ledgerAdapter, 'LIMIT 1'), 'environment-pinned M10 lookup has no ambiguous LIMIT 1 fallback');
Assert::false(str_contains($ledgerAdapter, "\$request"), 'M10 environment cannot come from HTTP caller input');
Assert::contains('verifyStream', $ledgerAdapter, 'ledger integrity is recomputed by M10 rather than trusted from a status field');

Assert::contains('HttpConsoleDataSource', $entry, 'production React entry uses the governed HTTP datasource');
Assert::false(str_contains($entry, 'CanonicalConsoleDataSource'), 'production entry no longer uses the inert Phase-1 datasource');
foreach (['P01_BATTLECARDS','P02_OWN_DRAFT','P02_ENTITY_INDEX','P02_RELATION_INDEX','P02_ENTITY_DETAIL','P03_QUEUE','P03_WATCH','P03_PROSPECT_DETAIL','P04_DISTRIBUTIONS','P05_DRAFTS','LEDGER','MONITOR_RUNS','MONITOR_RUN'] as $read) {
    Assert::contains("READS.{$read}", $httpDatasource, "HTTP datasource explicitly maps {$read}");
}
Assert::false(str_contains($httpDatasource, 'P03_BATTLECARDS]:'), 'HTTP datasource contains no P03 battlecard endpoint mapping');
Assert::false(str_contains($httpDatasource, 'OVERVIEW_PULSE]:'), 'unimplemented Overview read is not browser-mapped');
Assert::contains('[READS.LEDGER,"M10","OWNER_READ_WIRED_ENV_PINNED",true]', $meta, 'metadata states the exact repaired M10 browser-read condition without implying source activation');
Assert::contains('[READS.P03_BATTLECARDS,"P03","NEEDS_READ_MODEL",true]', $meta, 'deferred P03 battlecard stays explicitly unavailable');
Assert::contains('[READS.OVERVIEW_PULSE,"M12","NEEDS_READ_MODEL",false]', $meta, 'Overview remains unavailable rather than being fabricated');

$ownDraft = (string) file_get_contents($root . '/app/Product/P02/OwnCompanyDraftRead.php');
Assert::contains("'entity_twin.read'", $ownDraft, 'P02 draft requires the existing M02 entity-read authority');
Assert::contains("'tenant.administer'", $ownDraft, 'unreleased own-company draft also requires tenant-admin authority');
Assert::contains('$this->access->evaluate(', $ownDraft, 'P02 draft uses the real M02 guard');
Assert::contains('$this->entitlements->tenantTierGrants(', $ownDraft, 'P02 draft is narrowed by the tenant tier');
Assert::contains("t.tenant_id=? AND t.workspace_id=?", $ownDraft, 'first-party draft rows remain tenant/workspace scoped');
Assert::contains("t.entity_kind='OWN_COMPANY'", $ownDraft, 'no third-party draft is leaked');
Assert::contains("v.claim_ceiling='INTERNAL_DRAFT'", $ownDraft, 'read never manufactures released output');
Assert::contains('MAX(v2.version_sequence)', $ownDraft, 'older draft versions are not surfaced after later updates');
Assert::false(str_contains($ownDraft, 'SELECT *'), 'no unreviewed fields enter P02 draft response');
