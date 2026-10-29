<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Http\ReadAdapters\TemporalMemoryAdapter;
use Allfather\Loopia\Workflow\HistoryReviewCandidateReadModel;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),true,64,JSON_THROW_ON_ERROR);
$items=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='UI-01'));
Assert::same(1,count($items),'exactly one UI01 work package');
Assert::same('BUILT_INERT',$items[0]['build_status'],
    'candidate UI source code is not a registered route');
foreach(['native_permission','live_migration','external_effects',
         'customer_visibility','production_activation'] as $flag){
    Assert::same(false,$items[0][$flag],
        'UI01 cannot grant '.$flag);
}
Assert::same('ui01.inert-p01-temporal-memory.v1',TemporalMemoryAdapter::VERSION,
    'new strict temporal memory read projection version is explicit');
Assert::same('li16.r2.disposable-owner-read-model.v1',
    HistoryReviewCandidateReadModel::VERSION,
    'existing owner reader remains test-only and M02 gated');
$controller=(string)file_get_contents($root.'/app/Http/Controllers/ConsoleReadController.php');
Assert::false(str_contains($controller,'TemporalMemoryAdapter'),
    'no authorized live controller endpoint registered');
$adapter=(string)file_get_contents($root.'/app/Http/ReadAdapters/TemporalMemoryAdapter.php');
foreach(['new PDO(', 'file_get_contents(', 'curl_exec(', 'ModelExecutor',
    'source_url', 'mail(', 'DELETE FROM'] as $effect){
    Assert::false(str_contains($adapter,$effect),
        'candidate adapter cannot perform direct source/provider/DB side effects');
}
Assert::true(is_file($root.'/frontend/console/src/views/p01/history-reviews.jsx'),
    'UI01 React component exists but is not yet routed');
Assert::true(is_file($root.'/frontend/console/src/data/temporal-memory-view-model.js'),
    'frontend consumes safe owner-shaped metadata rather than fake findings');
