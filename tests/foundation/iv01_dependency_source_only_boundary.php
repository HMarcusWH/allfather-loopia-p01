<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryDependencyIndex,HistoryInvalidation,HistoryInvalidationStore};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$rows=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='IV-01'));
Assert::same(1,count($rows),'IV01 has one manifest entry');
Assert::same('BUILT_INERT',$rows[0]['build_status'],
    'IV01 invalidation is source candidate not operational revocation');
foreach(['native_permission','live_migration','external_effects',
         'customer_visibility','production_activation'] as $field){
    Assert::same(false,$rows[0][$field],'IV01 cannot silently activate '.$field);
}
Assert::same(HistoryDependencyIndex::MAX_NODES,256,
    'graph is strictly bounded before any traversal');
Assert::same(HistoryInvalidation::MAX_BATCH,32,
    'resumable invalidation has bounded batches');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/350_history_dependencies_CANDIDATE.sql'),
    'family350 is a separate candidate file');
Assert::false(is_file($root.'/database/migrations/350_history_dependencies.sql'),
    'family350 is not a native live migration');
$source=(string)file_get_contents($root.'/app/Workflow/HistoryInvalidationStore.php');
Assert::true(str_contains($source,'forDisposableTest'),
    'candidate metadata writer available only under disposable test schema');
Assert::true(str_contains($source,'ALLFATHER_IV01_DISPOSABLE_SCHEMA_LOADED'),
    'non-exportable TEST-only flag is required');
