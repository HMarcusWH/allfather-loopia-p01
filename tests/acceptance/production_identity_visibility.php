<?php

declare(strict_types=1);

/**
 * ACCEPTANCE (completion plan P12): production identity and customer visibility stay CLOSED until
 * the canonical owners adopt them — and the production preflight can only say so.
 *
 * P12 is the conditional package. No canonical decision defines a production identity class, a
 * customer audience or a production GO contract, so this file proves the inert state end to end:
 *
 *   - TEST/DEMO identities and historical ledger streams cannot be relabelled as production — the
 *     storage vocabulary refuses it, and so does the M10 owner;
 *   - an internal-only source cannot leak through a wider audience: M16 refuses the projection with
 *     no content, and no wider audience can even be marked activatable;
 *   - ops/deploy/production-preflight.php reports every missing prerequisite, never grants GO, never
 *     writes the governed lock or evidence registry, and is not satisfied by flipping a lock flag
 *     or by APP_ENV=production on its own;
 *   - the front controller registers no customer-facing route.
 */

require_once __DIR__ . '/../support/ProductReleaseFixture.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\ProductReleaseFixture;

$root = dirname(__DIR__, 2);
$preflight = static function (array $flags) use ($root): array {
    // This acceptance fixture speaks about one explicit synthetic production host.
    // Missing-target refusal is independently covered by tests/unit/production_preflight.php.
    $flags[] = '--target=prod.example.test';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ops/deploy/production-preflight.php');
    foreach ($flags as $flag) {
        $command .= ' ' . escapeshellarg($flag);
    }
    exec($command . ' 2>/dev/null', $lines, $code);

    return [$code, json_decode(implode("\n", $lines), true)];
};
$governed = static fn (): array => [hash_file('sha256', $root . '/upstream.lock.json'), hash_file('sha256', $root . '/deployment-evidence.json')];

// ============================================================ the preflight, against THIS release

Assert::case('P12: the production preflight reports every missing prerequisite and grants nothing');

$before = $governed();
[$code, $report] = $preflight(['--release-ref=' . str_repeat('a', 40)]);
Assert::same(1, $code, 'the preflight exits non-zero for this release');
Assert::same(['NOT_READY', false, false], [$report['verdict'] ?? null, $report['grants_go'] ?? null, $report['customer_visibility_granted'] ?? null], 'it is NOT_READY and grants neither GO nor customer visibility');
foreach ([
    'PRODUCTION_IDENTITY_NOT_IN_CANONICAL_VOCABULARY',
    'CUSTOMER_AUDIENCE_NOT_ADOPTED',
    'PRODUCTION_LAUNCH_NOT_AUTHORIZED',
    'HOSTING_ISOLATION_NOT_CLOSED',
    'PRODUCTION_RELEASE_NOT_QUALIFIED',
    'RECOVERY_NOT_EVIDENCED_ON_PRODUCTION',
] as $reason) {
    Assert::true(in_array($reason, $report['failed'] ?? [], true), "it names {$reason}");
}
$identity = array_values(array_filter($report['checks'], static fn (array $c): bool => $c['check'] === 'IDENTITY_VOCABULARY'))[0];
Assert::same(['DEMO', 'LOCAL_DEV', 'TEST'], $identity['facts']['environment_classes'], 'it reads the canonical identity vocabulary from the release schema itself');
Assert::same($before, $governed(), 'it wrote neither upstream.lock.json nor deployment-evidence.json');
Assert::same(1, $preflight([])[0], 'without a release ref it still refuses');
Assert::same(2, $preflight(['--release-ref=HEAD'])[0], 'a release ref must be an exact 40-hex SHA');
Assert::same(2, $preflight(['--mode=production'])[0], 'and there is no mode, apply or grant flag to pass');

$source = (string) file_get_contents($root . '/ops/deploy/production-preflight.php');
foreach (['file_put_contents', 'fopen(', 'Database::connect', 'rename(', 'unlink(', 'exec(', 'shell_exec'] as $forbidden) {
    Assert::false(str_contains($source, $forbidden), "the preflight source contains no {$forbidden}");
}
Assert::contains("'grants_go' => false", $source, 'grants_go is a literal false, not a computed value');

// ============================================================ flipping a flag is not a production release

Assert::case('P12: a hand-edited lock and a production APP_ENV do not make a release production-ready');

$tree = sys_get_temp_dir() . '/allfather-p12-' . Ids::new();
mkdir($tree . '/database/migrations', 0700, true);
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    copy($file, $tree . '/database/migrations/' . basename($file));
}
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['production_launch_authorized', 'owner_go_granted', 'customer_visible_output_authorized'] as $flag) {
    $lock['claim_boundary'][$flag] = true;
}
file_put_contents($tree . '/upstream.lock.json', json_encode($lock, JSON_THROW_ON_ERROR));
copy($root . '/deployment-evidence.json', $tree . '/deployment-evidence.json');
file_put_contents($tree . '/production.env', "APP_ENV=production\nDB_PASSWORD=never-printed\n");
try {
    [$code, $forged] = $preflight(['--root=' . $tree, '--release-ref=' . str_repeat('b', 40), '--env=' . $tree . '/production.env']);
    Assert::same([1, 'NOT_READY', false], [$code, $forged['verdict'] ?? null, $forged['grants_go'] ?? null], 'a lock with its authority flags flipped is still NOT_READY');
    $status = array_column($forged['checks'], 'status', 'check');
    Assert::same(['PASS', 'PASS', 'FAIL', 'FAIL', 'FAIL'], [$status['AUTHORITY_LOCKS'], $status['RUNTIME_POSTURE'], $status['IDENTITY_VOCABULARY'], $status['CUSTOMER_AUDIENCE'], $status['HOSTING_ISOLATION']],
        'the flags read as set and the posture is production, but identity, audience and hosting still refuse');
    Assert::false(str_contains(json_encode($forged), 'never-printed'), 'no env value is printed');
} finally {
    array_map('unlink', glob($tree . '/database/migrations/*.sql') ?: []);
    @unlink($tree . '/upstream.lock.json');
    @unlink($tree . '/deployment-evidence.json');
    @unlink($tree . '/production.env');
    @rmdir($tree . '/database/migrations');
    @rmdir($tree . '/database');
    @rmdir($tree);
}

// ============================================================ recovery evidence is production-scoped

Assert::case('P12: staging recovery evidence cannot satisfy production recovery readiness');

$tree = sys_get_temp_dir() . '/allfather-p12-recovery-' . Ids::new();
mkdir($tree . '/database/migrations', 0700, true);
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    copy($file, $tree . '/database/migrations/' . basename($file));
}
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($tree . '/upstream.lock.json', json_encode($lock, JSON_THROW_ON_ERROR));
$registry = json_decode((string) file_get_contents($root . '/deployment-evidence.json'), true, 512, JSON_THROW_ON_ERROR);
$record = static function (string $id, string $kind, string $environment, string $target, string $release, string $at): array {
    return [
        'record_id' => $id,
        'kind' => $kind,
        'result' => 'PASS',
        'release_ref' => $release,
        'environment' => $environment,
        'recorded_at' => $at,
        'evidence_ref' => 'docs/deployment-evidence/p12/' . strtolower($id) . '.json',
        'evidence_sha256' => str_repeat('d', 64),
        'target' => $target,
    ];
};
$release = str_repeat('c', 40);
$registry['records'][] = $record('P12_STAGE_RESTORE', 'FULL_RESTORE_DRILL', 'staging', 'staging.allfatherbi.com', $release, '2026-10-03T16:00:00.000Z');
$registry['records'][] = $record('P12_STAGE_ROTATE', 'KEY_ROTATION_DRILL', 'staging', 'staging.allfatherbi.com', $release, '2026-10-03T16:01:00.000Z');
file_put_contents($tree . '/deployment-evidence.json', json_encode($registry, JSON_THROW_ON_ERROR));
try {
    [$code, $stageRecovery] = $preflight(['--root=' . $tree, '--release-ref=' . $release]);
    Assert::same(1, $code, 'the temporary release is still not production-ready');
    $checks = array_column($stageRecovery['checks'], null, 'check');
    Assert::same('FAIL', $checks['RECOVERY_READINESS']['status'] ?? null, 'staging restore/rotation observations do not satisfy production recovery');
    Assert::same('RECOVERY_NOT_EVIDENCED_ON_PRODUCTION', $checks['RECOVERY_READINESS']['reason_code'] ?? null, 'the refusal names absent production recovery evidence');

    $registry['records'][] = $record('P12_PROD_RESTORE', 'FULL_RESTORE_DRILL', 'production', 'prod.example.test', $release, '2026-10-03T16:02:00.000Z');
    $registry['records'][] = $record('P12_PROD_ROTATE', 'KEY_ROTATION_DRILL', 'production', 'prod.example.test', $release, '2026-10-03T16:03:00.000Z');
    file_put_contents($tree . '/deployment-evidence.json', json_encode($registry, JSON_THROW_ON_ERROR));

    [$code, $prodRecovery] = $preflight(['--root=' . $tree, '--release-ref=' . $release]);
    Assert::same(1, $code, 'recovery observations alone still cannot make the release production-ready');
    $checks = array_column($prodRecovery['checks'], null, 'check');
    Assert::same('PASS', $checks['RECOVERY_READINESS']['status'] ?? null, 'both production recovery observations satisfy only the recovery prerequisite');
    Assert::same(false, $prodRecovery['grants_go'] ?? null, 'even complete recovery evidence never grants GO');
    Assert::same(false, $prodRecovery['customer_visibility_granted'] ?? null, 'and never grants customer visibility');
} finally {
    array_map('unlink', glob($tree . '/database/migrations/*.sql') ?: []);
    @unlink($tree . '/upstream.lock.json');
    @unlink($tree . '/deployment-evidence.json');
    @rmdir($tree . '/database/migrations');
    @rmdir($tree . '/database');
    @rmdir($tree);
}

// ============================================================ the database refuses production identity

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();

Assert::case('P12: TEST/DEMO identities and historical streams cannot masquerade as production');

$fx = ProductReleaseFixture::create($db, $clock, 'p12-identity');
$scope = $fx['scope'];
$refusedByStorage = static function (callable $write): bool {
    try {
        $write();
    } catch (\PDOException) {
        return true;
    }

    return false;
};
Assert::true($refusedByStorage(static fn () => $db->run('UPDATE workspaces SET environment_class = ? WHERE workspace_id = ?', ['PRODUCTION', $scope['workspace_id']])), 'a TEST workspace cannot be relabelled PRODUCTION');
Assert::true($refusedByStorage(static fn () => $db->run('UPDATE tenants SET tenant_type = ? WHERE tenant_id = ?', ['CUSTOMER', $scope['tenant_id']])), 'nor a TEST tenant relabelled CUSTOMER');
Assert::true($refusedByStorage(static fn () => $db->run('UPDATE decision_ledger_streams SET environment_class = ? WHERE tenant_id = ?', ['PRODUCTION', $scope['tenant_id']])), 'nor a historical ledger stream reinterpreted as production');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM workspaces WHERE environment_class NOT IN ('LOCAL_DEV', 'TEST', 'DEMO')"), 'no workspace outside the canonical vocabulary exists');
$stream = null;
try {
    $stream = (new DecisionLedger($db, $clock))->createStream($scope['tenant_id'], $scope['workspace_id'], 'PRODUCTION');
} catch (\PDOException) {
}
Assert::true($stream === null || !$stream->isOk(), 'the M10 owner will not open a PRODUCTION stream');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM decision_ledger_streams WHERE environment_class = 'PRODUCTION'"), 'and none exists');

Assert::case('P12: internal-only release content cannot leak through a wider audience');

$artifactId = (string) $db->scalar('SELECT release_artifact_id FROM release_artifacts WHERE released_entity_twin_snapshot_id = ?', [$fx['snapshot_id']]);
Assert::true($artifactId !== '', 'the released P02 snapshot has its M16 artifact');
$m16 = new ReleaseOutput($db, $clock, new DecisionLedger($db, $clock));
foreach (['CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE'] as $audience) {
    $projected = $m16->projectForAudience($artifactId, $audience);
    $value = $projected->isOk() ? $projected->value() : null;
    Assert::true(!$projected->isOk() || $value['status'] === 'REFUSED', "a {$audience} projection of an internal artifact is refused");
    if ($value !== null) {
        $stored = $db->one('SELECT status, content_elements_safe_json FROM customer_output_projections WHERE customer_output_projection_id = ?', [$value['customer_output_projection_id']]);
        Assert::same(['REFUSED', '[]'], [$stored['status'], $stored['content_elements_safe_json']], "and the refused {$audience} row stores no content");
    }
}
Assert::true($refusedByStorage(static fn () => $db->insert('output_visibility_profiles', [
    'output_visibility_profile_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'audience_class' => 'CUSTOMER_VISIBLE',
    'profile_version' => '99', 'activation_permitted' => 1, 'required_permission_key' => 'battlecard.read', 'internal_scope_lens' => null,
    'status' => 'ACTIVE', 'created_at' => $clock->now(), 'updated_at' => $clock->now(),
])), 'no profile can mark a customer audience activatable');
Assert::true($refusedByStorage(static fn () => $db->run("UPDATE release_artifacts SET status = 'RELEASED_CUSTOMER_VISIBLE' WHERE release_artifact_id = ?", [$artifactId])), 'and no artifact can be released customer-visible');

// ============================================================ no customer-facing surface exists

Assert::case('P12: the deployed front controller has no customer-facing route');

$index = (string) file_get_contents($root . '/public_html/index.php');
// The one permitted root route redirects into the authenticated operator console.
// Keep the exact redirect target/shape pinned: simply allowing "/" would mask a
// future customer-visible root handler.
$authenticatedRootRedirect = <<<'PHP'
$kernel->route('GET', '/', static fn (Request $request, ?array $session): Response => Response::redirect('/console/app'));
PHP;
Assert::contains($authenticatedRootRedirect, $index, 'root is only an authenticated operator-console redirect');
preg_match_all("#\\\$kernel->route\\('(?:GET|POST)', '([^']+)'#", $index, $routes);
Assert::same(
    1,
    count(array_filter($routes[1], static fn (string $path): bool => $path === '/')),
    'exactly one explicit operator root route; no extra customer root handler',
);
foreach ($routes[1] as $path) {
    Assert::true(
        $path === '/' || $path === '/login' || $path === '/logout' || $path === '/healthz' || $path === '/support' || str_starts_with($path, '/account') || str_starts_with($path, '/console'),
        "{$path} is an operator, account or health route — not a customer surface",
    );
}
Assert::contains("'customer_visible_output' => false", $index, 'and the health endpoint still states no customer-visible output');
