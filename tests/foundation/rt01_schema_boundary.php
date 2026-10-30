<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\{
    HistorySchemaPlan,HistorySchemaFixtureInstaller,RuntimeInvariantProbe,
};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$packages=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='RT-01'));
Assert::same(1,count($packages),'RT01 is one frozen build package');
Assert::same('BUILT_INERT',$packages[0]['build_status'],
    'candidate source/SQL has not acquired production migration permission');
foreach([
    'native_permission','external_effects','live_migration',
    'customer_visibility','production_activation',
] as $flag){
    Assert::same(false,$packages[0][$flag],
        'RT01 cannot alter live authority: '.$flag);
}
Assert::same('rt01.candidate-physical-schema-plan.v1',HistorySchemaPlan::VERSION,
    'schema builder has a versioned source contract');
Assert::same('rt01.disposable-schema-verifier.v1',
    HistorySchemaFixtureInstaller::VERSION,
    'DDL fixture install is not native Migrator::up');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/260_350_schema_contracts/INSTALL_MANIFEST.json'),
    'exact physical SQL sources manifest packaged');
Assert::true(is_file(
    $root.'/tests/fixtures/longitudinal/schema_install_manifest.json'),
    'fixture-positive/negative installation contract packaged');
Assert::true(is_file($root.'/docs/HISTORY_SCHEMA_AND_STORAGE.md'),
    'full schema and recovery boundaries documented');
Assert::true(is_file($root.'/ops/history/migration-plan.php'),
    'read-only operator candidate migration plan exists');
Assert::false(is_file($root.'/database/migrations/260_longitudinal.sql'),
    'no new family 260 startup migration');
Assert::false(is_file($root.'/database/migrations/350_longitudinal.sql'),
    'no new family350 startup migration');
$cli=(string)file_get_contents($root.'/ops/history/migration-plan.php');
Assert::true(str_contains($cli,"PHP_SAPI !== 'cli'"),
    'CLI browser SAPI guarded');
Assert::true(strpos($cli,"PHP_SAPI !== 'cli'")<strpos($cli,'autoload.php'),
    'CLI guard must precede app or secret loading');
Assert::false(str_contains($cli,'--env='),
    'no private runtime DB credentials accepted in schema source CLI');
