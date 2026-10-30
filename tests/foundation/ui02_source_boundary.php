<?php

declare(strict_types=1);

use Allfather\Loopia\Http\ReadAdapters\HistoryReviewAdapter;
use Allfather\Loopia\Workflow\HistoryReviewCommandCandidate;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json',
),true,64,JSON_THROW_ON_ERROR);
$matches=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='UI-02'));
Assert::same(1,count($matches),'UI-02 has one frozen build package');
Assert::same('BUILT_INERT',$matches[0]['build_status'],
    'the fixture workbench cannot be a live customer feature');
foreach(['native_permission','customer_visibility','production_activation',
         'live_migration','external_effects'] as $key){
    Assert::same(false,$matches[0][$key],
        'source implementation cannot grant '.$key);
}
Assert::same('ui02.history-review-workbench.v1',HistoryReviewAdapter::VERSION,
    'separate, versioned scoped review read contract');
Assert::same('ui02.reviewed-command-candidate.v1',HistoryReviewCommandCandidate::VERSION,
    'reviewed command is not an M10 release');
foreach([
    'app/Http/Controllers/HistoryReviewController.php',
    'app/Http/ReadAdapters/HistoryReviewAdapter.php',
    'app/Http/ReadAdapters/HistoryReviewWorkbenchOwnerPort.php',
    'app/Http/templates/history-review.php',
    'frontend/console/src/views/p01/history-review-workbench.jsx',
    'frontend/console/src/data/history-review-workbench-view-model.js',
] as $path){
    Assert::true(is_file($root.'/'.$path),'UI-02 source exists: '.$path);
}
$front=(string)file_get_contents($root.'/public_html/index.php');
Assert::false(str_contains($front,'HistoryReviewController'),
    'no HTTP route registered without U03/U06/U07/U09 adoption');
$react=(string)file_get_contents($root.'/frontend/console/src/app/routes.jsx');
Assert::false(str_contains($react,'HistoryReviewWorkbenchPreview'),
    'the new preview remains unregistered from live navigation');
Assert::false(is_file($root.'/database/migrations/360_history_review_commands.sql'),
    'no standalone second shadow human-approval store added');
$template=(string)file_get_contents($root.'/app/Http/templates/history-review.php');
Assert::true(str_contains($template,'View::e('),
    'operator HTML uses existing escaped text transport');
Assert::false(str_contains($template,'<script'),
    'scriptless history workbench inherits strict CSP');
