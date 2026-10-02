<?php

declare(strict_types=1);

/**
 * P09 product detail reads over the real Kernel: GET /console/api/v1/p02/entity-detail and
 * GET /console/api/v1/p03/prospect-detail.
 *
 * What must hold for every detail read:
 *   - scope comes ONLY from the session; the query value is a selector the owner re-checks;
 *   - permission is decided FIRST, so a caller without it learns nothing about what exists;
 *   - for a caller with it, another workspace's id answers exactly like an id that never existed;
 *   - a GET writes no product, release or ledger state;
 *   - source and model text arrives as JSON string data, never as markup;
 *   - the existing index reads keep working, with no duplicate route.
 */

require_once __DIR__ . '/../support/ProductReleaseFixture.php';

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Ids;
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
use Allfather\Loopia\Tests\ProductReleaseFixture;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$own = ProductReleaseFixture::create($db, $clock, 'detail-own');
$foreign = ProductReleaseFixture::create($db, $clock, 'detail-foreign');

$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$entitlements = new Entitlements($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);
$p02Adapter = new P02Adapter($db, new ReleaseOutput($db, $clock, $ledger));
$reads = new ConsoleReadController(
    new ConsoleContextAdapter($db, new Console($db, $clock, $access, $ledger, $assurance), $assurance),
    new DecisionLedgerAdapter($db, $clock, $access, $ledger),
    new Surfacing($db, $access, $ledger, $entitlements),
    new TwinRelease($db, $clock, $ledger, new EntityTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new RelationshipRelease($db, $clock, $ledger, new RelationshipTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new P03Release($db, $clock, $ledger, $access, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
);
$auth = new Authenticator($db, $clock);
$kernel = new Kernel($db, $clock, $auth, new View(dirname(__DIR__, 2) . '/app/Http/templates'));
$kernel->route('GET', '/console/api/v1/p02/entities', $reads->p02Entities(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/priority-queue', $reads->p03PriorityQueue(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p02/entity-detail', $reads->p02EntityDetail(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/p03/prospect-detail', $reads->p03ProspectDetail(...), 'JSON_401');

$get = static function (string $path, array $query = [], array $cookies = []) use ($kernel): array {
    $response = $kernel->handle(Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.71'], $query, [], $cookies));
    return [$response->status, json_decode($response->body, true, 512, JSON_THROW_ON_ERROR), $response];
};
$users = [];
$signIn = static function (string $tenantId, string $operatorId, string $email, ?string $workspaceId) use ($db, $clock, $auth, &$users): array {
    $user = $users[$operatorId] ??= Seed::platformUser($db, $clock, $tenantId, $operatorId, $email);
    $tokens = $auth->authenticate($user['email'], $user['password'], '203.0.113.71', 'product-detail-reads')->value();
    if ($workspaceId !== null) {
        Assert::true($auth->setActiveWorkspace($tokens['session_id'], $workspaceId)->isOk(), 'the reader selects their own workspace through the authenticator');
    }
    return ['af_session' => $tokens['session_token'], Csrf::COOKIE_NAME => $tokens['csrf_token']];
};

// ============================================================ anonymous and unscoped callers

foreach (['/console/api/v1/p02/entity-detail' => ['snapshot' => $own['snapshot_id']], '/console/api/v1/p03/prospect-detail' => ['output' => $own['output_id']]] as $path => $query) {
    [$status, $body] = $get($path, $query);
    Assert::same([401, 'AUTHENTICATION_REQUIRED'], [$status, $body['reason_codes'][0] ?? null], "{$path} answers an anonymous caller with JSON 401");
}
$noWorkspace = $signIn($own['scope']['tenant_id'], $own['reader'], 'detail-reader@example.test', null);
Assert::same(409, $get('/console/api/v1/p02/entity-detail', ['snapshot' => $own['snapshot_id']], $noWorkspace)[0], 'a session with no workspace gets 409, not a guess at scope');

$reader = $signIn($own['scope']['tenant_id'], $own['reader'], 'detail-reader@example.test', $own['scope']['workspace_id']);
$counts = static fn (): array => array_map(static fn (string $t): int => (int) $db->scalar("SELECT COUNT(*) FROM {$t}"), [
    'released_entity_twin_snapshots' => 'released_entity_twin_snapshots', 'released_prospect_outputs' => 'released_prospect_outputs',
    'decision_records' => 'decision_records', 'entity_twin_versions' => 'entity_twin_versions', 'fit_hypotheses' => 'fit_hypotheses', 'audit_runs' => 'audit_runs',
]);
$before = $counts();

// ============================================================ P02 entity detail

[$status, $body, $response] = $get('/console/api/v1/p02/entity-detail', ['snapshot' => $own['snapshot_id'], 'tenant_id' => $foreign['scope']['tenant_id'], 'workspace_id' => $foreign['scope']['workspace_id']], $reader);
Assert::same([200, 'p02.entityContextDetail', 'VISIBLE', 'entity_twin.read'], [$status, $body['source'], $body['owner_state'], $body['access']['required_permission']], 'the reader sees their own released snapshot in detail; query scope is ignored');
$detail = $body['data'];
Assert::same($own['snapshot_id'], $detail['snapshot']['released_entity_twin_snapshot_id'], 'it is the selected snapshot');
Assert::same(['headcount' => 'UNKNOWN', 'industry' => 'PUBLIC_SOURCE'], array_column($detail['fields'], 'value_status', 'field_key'), 'released state carries each value with its status, unknowns included');
Assert::contains('unknown', strtolower((string) $detail['uncertainty']['summary_safe']), 'and the uncertainty that travels with it');
Assert::same(['PUBLIC_SOURCE'], array_column($detail['lineage'], 'source_basis'), 'lineage names the source basis');
Assert::same([true, null], [$detail['currently_relied_on'], $detail['superseded_by']], 'and says the release is currently relied on and not superseded');
Assert::same('Rival <b>AB</b>', $detail['entity']['display_name'], 'source text is JSON string data, byte for byte, not interpreted');
Assert::contains('application/json', $response->headers['Content-Type'], 'served as JSON');
Assert::same('nosniff', $response->headers['X-Content-Type-Options'] ?? null, 'which a browser may not sniff into HTML');

[$foreignStatus, $foreignBody] = $get('/console/api/v1/p02/entity-detail', ['snapshot' => $foreign['snapshot_id']], $reader);
[$unknownStatus, $unknownBody] = $get('/console/api/v1/p02/entity-detail', ['snapshot' => Ids::new()], $reader);
Assert::same([404, 'NOT_FOUND', null], [$foreignStatus, $foreignBody['owner_state'], $foreignBody['data']], "another workspace's snapshot is NOT_FOUND, with no data");
Assert::same([$unknownStatus, $unknownBody], [$foreignStatus, $foreignBody], 'and its answer is byte-identical to an id that never existed');
Assert::same('VISIBLE', $foreignBody['access']['decision'], 'NOT_FOUND is not a denial: the caller holds the read permission');
foreach (['', 'not-a-uuid', str_repeat('a', 5000), $own['snapshot_id'] . "'--"] as $bad) {
    [$s, $b] = $get('/console/api/v1/p02/entity-detail', ['snapshot' => $bad], $reader);
    Assert::same([404, ['SELECTOR_INVALID']], [$s, $b['reason_codes']], 'a malformed selector names nothing and asks no owner: ' . substr($bad, 0, 20));
}

// ============================================================ P03 prospect detail

[$status, $body] = $get('/console/api/v1/p03/prospect-detail', ['output' => $own['output_id']], $reader);
Assert::same([200, 'p03.prospectDetail', 'priority_outreach_queue.read'], [$status, $body['source'], $body['access']['required_permission']], 'the reader sees their released queue item in detail');
$prospect = $body['data'];
Assert::same('PRIORITY_OUTREACH_QUEUE_ITEM', $prospect['output']['output_kind'], 'the released kind');
Assert::true(in_array($prospect['hypothesis']['claim_kind'], ['FIT_HYPOTHESIS', 'REASON_TO_INVESTIGATE'], true), 'the claim is a fit, never an intent');
Assert::contains('never a statement of buyer intent', $prospect['claim_ceiling_note'], 'and the ceiling is stated on the detail itself');
Assert::same(['RELEASED', true], [$prospect['signal']['status'], $prospect['currently_relied_on']], 'the signal is released and the output currently relied on');
Assert::contains('<script>alert(1)</script>', $prospect['signal']['summary_safe'], 'source-derived text arrives verbatim as string data — escaping is the renderer\'s job, never a rewrite here');
Assert::same(null, $prospect['battlecard'], 'no prospect battlecard is invented: the release path mints none');

[$foreignStatus, $foreignBody] = $get('/console/api/v1/p03/prospect-detail', ['output' => $foreign['output_id']], $reader);
[$unknownStatus, $unknownBody] = $get('/console/api/v1/p03/prospect-detail', ['output' => Ids::new()], $reader);
Assert::same(404, $foreignStatus, "another workspace's released prospect is NOT_FOUND");
Assert::same([$unknownStatus, $unknownBody], [$foreignStatus, $foreignBody], 'identical to an id that never existed');

// A withdrawn signal: the detail says so instead of showing what it used to say.
$db->run("UPDATE company_signals SET status = 'WITHDRAWN' WHERE company_signal_id = ?", [$own['signal_id']]);
$withdrawn = $get('/console/api/v1/p03/prospect-detail', ['output' => $own['output_id']], $reader)[1]['data']['signal'];
Assert::same(['status' => 'NO_LONGER_RELEASED'], $withdrawn, 'a signal that is no longer released is labelled, and its text is not shown');
$db->run("UPDATE company_signals SET status = 'RELEASED' WHERE company_signal_id = ?", [$own['signal_id']]);

// ============================================================ permission is decided first

// A colleague in the SAME workspace whose role grants neither product read.
$colleague = Seed::operator($db, $clock, $own['scope']['tenant_id'], 'Colleague without product reads');
Seed::membership($db, $clock, $own['scope']['tenant_id'], $own['scope']['workspace_id'], $colleague, Seed::role($db, $clock, 'no-product-read-' . substr(Ids::new(), 0, 8), ['decision.read'])['role_id']);
$tenantOperator = $signIn($own['scope']['tenant_id'], $colleague, 'no-read@example.test', $own['scope']['workspace_id']);
foreach ([
    ['/console/api/v1/p02/entity-detail', 'snapshot', [$own['snapshot_id'], $foreign['snapshot_id'], Ids::new()], 'entity_twin.read'],
    ['/console/api/v1/p03/prospect-detail', 'output', [$own['output_id'], $foreign['output_id'], Ids::new()], 'priority_outreach_queue.read'],
] as [$path, $key, $ids, $permission]) {
    $answers = [];
    foreach ($ids as $id) {
        [$s, $b] = $get($path, [$key => $id], $tenantOperator);
        $answers[] = [$s, $b['access']['decision'], $b['access']['required_permission'], $b['data']];
    }
    Assert::same([403, 'DENIED', $permission, null], $answers[0], "{$path}: without {$permission} the caller is denied");
    Assert::same([$answers[0], $answers[0]], [$answers[1], $answers[2]], "{$path}: and gets the same denial for a foreign or unknown id — existence is never revealed");
}

// ============================================================ GET wrote nothing; the indexes still work

Assert::same($before, $counts(), 'no detail read wrote product, release, ledger or audit-run state');
[$status, $body] = $get('/console/api/v1/p02/entities', ['visibility_scope' => 'BROAD_INTERNAL_BI'], $reader);
Assert::same([200, [$own['snapshot_id']]], [$status, array_column($body['data'], 'released_entity_twin_snapshot_id')], 'the P02 index still lists exactly this workspace\'s snapshot');
[$status, $body] = $get('/console/api/v1/p03/priority-queue', [], $reader);
Assert::same([200, [$own['output_id']]], [$status, array_column($body['data'], 'released_prospect_output_id')], 'the P03 index still lists exactly this workspace\'s queue item');

$index = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/index.php');
foreach (['/console/api/v1/p02/entity-detail', '/console/api/v1/p03/prospect-detail', '/console/api/v1/p02/entities', '/console/api/v1/p03/priority-queue'] as $route) {
    Assert::same(1, substr_count($index, "'{$route}'"), "{$route} is registered exactly once in the deployed front controller");
}
