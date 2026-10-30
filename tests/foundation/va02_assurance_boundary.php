<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Workflow\HistoryReviewAssurance;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$matches=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='VA-02'));
Assert::same(1,count($matches),'VA02 unique frozen package');
Assert::same('BUILT_INERT',$matches[0]['build_status'],
    'source-only M11 metrics are not an authenticated operator audit');
foreach(['native_permission','customer_visibility',
         'production_activation','live_migration','external_effects'] as $flag){
    Assert::same(false,$matches[0][$flag],
        'VA02 cannot grant runtime output: '.$flag);
}
Assert::same('va02.m17-provenance-inspection.v1',M17Assurance::VERSION,
    'existing M17 lineage observed without new ledger');
Assert::same('va02.history-assurance-scorecard.v1',HistoryReviewAssurance::VERSION,
    'M11 assurance is a typed candidate with missing-proof status');
foreach([
    'app/Substrate/M17/Assurance.php',
    'app/Workflow/HistoryReviewAssurance.php',
    'ops/assurance/history.php',
    'ops/assurance/temporal.php',
    'docs/HISTORY_OPERATIONS_SCORECARD.md',
] as $path)Assert::true(is_file($root.'/'.$path),
    'assurance source and runbook present: '.$path);
Assert::false(is_file($root.'/database/migrations/370_assurance_history.sql'),
    'no second authoritative audit database installed');
foreach(['ops/assurance/history.php','ops/assurance/temporal.php'] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    Assert::true(str_contains($source,"PHP_SAPI !== 'cli'"),
        'CLI-only guard refuses web SAPI before app autoload');
    Assert::true(strpos($source,"PHP_SAPI !== 'cli'")<
        strpos($source,'autoload.php'),'guard precedes bootstrap');
}
