<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

/**
 * A deliberately disposable M02 integration control, exercising the EXISTING
 * access owner (not replacing it with fake grants).
 *
 * The existing Harness::freshDb() truncates its DB. This new test performs a
 * name/environment check BEFORE calling it so a mistakenly supplied staging
 * DSN is refused without touching tables.
 */
$root = dirname(__DIR__, 2);
$envPath = getenv('TEST_ENV_FILE');
if (!is_string($envPath) || $envPath === '') {
    $envPath = $root . '/.env.testing';
}
if (!str_starts_with($envPath, '/')) {
    $envPath = $root . '/' . $envPath;
}
if (!is_file($envPath)) {
    Assert::skip('BF01 disposable MariaDB env file not available');
    return;
}
$env = Env::load($envPath);
$name = $env->get('DB_NAME', '');
$kind = $env->get('APP_ENV', '');
if ($kind !== 'testing'
    || !is_string($name)
    || preg_match('/(^|_)(test|testing|qa|qualification|ci)($|_)/i', $name) !== 1
    || preg_match('/(staging|prod|production|restore)/i', $name) === 1) {
    Assert::fail('BF01 destructive test requires explicitly named disposable DB and APP_ENV=testing');
    return;
}
$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'bf01-offline');
$guard = new AccessGuard($db, $clock);

$pre = $guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], 'evidence.read', 'EVIDENCE', null, null, true);
Assert::same('DENY', $pre['decision'], 'real M02 denies absent role membership');
$role = Seed::role($db, $clock, 'bf01-evidence-reader', ['evidence.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$allow = $guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], 'evidence.read', 'EVIDENCE', null, null, true);
Assert::same('ALLOW', $allow['decision'], 'real M02 permits correctly owned synthetic role');
$other = Seed::tenantWorkspace($db, $clock, 'bf01-other');
$foreign = $guard->evaluate('OPERATOR', $scope['operator_id'], $other['tenant_id'], $other['workspace_id'], 'evidence.read', 'EVIDENCE', null, null, true);
Assert::same('DENY', $foreign['decision'], 'real M02 refuses foreign tenant/workspace identity');
Assert::same(3, (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records'), 'real authority owner recorded all evaluated outcomes');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_execution_grants'), 'synthetic fixture created zero native execution grants');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots'), 'no external capture');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM decision_records'), 'no M10 release');
