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
    '/console/api/v1/p02/relationships',
    '/console/api/v1/p03/priority-queue',
    '/console/api/v1/p03/monitored',
    '/console/api/v1/p04/distributions',
    '/console/api/v1/p05/drafts',
    '/console/api/v1/m10/decisions',
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

Assert::contains('environment_class = ? AND status = \'ACTIVE\'', $ledgerAdapter, 'M10 lookup pins environment as part of active stream identity');
Assert::false(str_contains($ledgerAdapter, 'LIMIT 1'), 'environment-pinned M10 lookup has no ambiguous LIMIT 1 fallback');
Assert::false(str_contains($ledgerAdapter, "\$request"), 'M10 environment cannot come from HTTP caller input');
Assert::contains('verifyStream', $ledgerAdapter, 'ledger integrity is recomputed by M10 rather than trusted from a status field');

Assert::contains('HttpConsoleDataSource', $entry, 'production React entry uses the governed HTTP datasource');
Assert::false(str_contains($entry, 'CanonicalConsoleDataSource'), 'production entry no longer uses the inert Phase-1 datasource');
foreach (['P01_BATTLECARDS','P02_ENTITY_INDEX','P02_RELATION_INDEX','P03_QUEUE','P03_WATCH','P04_DISTRIBUTIONS','P05_DRAFTS','LEDGER'] as $read) {
    Assert::contains("READS.{$read}", $httpDatasource, "HTTP datasource explicitly maps {$read}");
}
Assert::false(str_contains($httpDatasource, 'P03_BATTLECARDS]:'), 'HTTP datasource contains no P03 battlecard endpoint mapping');
Assert::false(str_contains($httpDatasource, 'OVERVIEW_PULSE]:'), 'unimplemented Overview read is not browser-mapped');
Assert::contains('[READS.LEDGER,"M10","OWNER_READ_WIRED_ENV_PINNED",true]', $meta, 'metadata states the exact repaired M10 browser-read condition without implying source activation');
Assert::contains('[READS.P03_BATTLECARDS,"P03","NEEDS_READ_MODEL",true]', $meta, 'deferred P03 battlecard stays explicitly unavailable');
Assert::contains('[READS.OVERVIEW_PULSE,"M12","NEEDS_READ_MODEL",false]', $meta, 'Overview remains unavailable rather than being fabricated');
