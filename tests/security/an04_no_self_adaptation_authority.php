<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewOutcomes,HistoryReviewFollowUp,HistoryReviewOutcomeStore
};

$fake=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
Assert::same('AN04_U03_U07_U10_RUNTIME_OUTCOME_NOT_ADOPTED',
    HistoryReviewOutcomeStore::forRuntime($fake,$clock,[
        'owner_approved'=>true,
        'prompt_weight_update'=>'ALLOW',
        'reliability_weight'=>1,
    ])->reasonCode(),
    'caller cannot install an outcome owner or self-adapting system');
$testOnly=HistoryReviewOutcomeStore::forDisposableTest($fake,$clock);
if(PHP_SAPI==='cli'
    && defined('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED')
    && constant('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED')===true){
    Assert::true($testOnly->isOk(),
        'synthetic 345 test marker does not grant real runtime factory');
}else{
    Assert::same('AN04_FAMILY345_RUNTIME_NOT_ADOPTED',
        $testOnly->reasonCode(),
        'without disposable schema factory is always denied');
}
Assert::same('AN04_U03_U07_U10_SOURCE_OR_MODEL_ACTION_NOT_ADOPTED',
    HistoryReviewFollowUp::dispatch(['caller_grant'=>'APPROVED'])->reasonCode(),
    'followup itself cannot spend, crawl or schedule work');
foreach([
    'app/Workflow/HistoryReviewOutcomes.php',
    'app/Workflow/HistoryReviewFollowUp.php',
    'app/Workflow/HistoryReviewOutcomeStore.php',
] as $path){
    $source=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', '->send(', 'mail(',
        'new SourceCaptureStage(', 'new SchedulerRunner(',
        'releaseP01(', 'unlink(', 'DELETE FROM',
    ] as $needle){
        Assert::false(str_contains($source,$needle),
            'proposed later evidence cannot trigger live action: '.$needle);
    }
}
$code=(string)file_get_contents(
    dirname(__DIR__,2).'/app/Workflow/HistoryReviewOutcomes.php');
foreach([
    'business_success_confirmed','automatic_prompt_or_weight_update',
    'automatic_schedule_update','m11_reliability_weight_admitted',
    'outcome_adjudicated_by_human','causal_attribution_established',
] as $flag){
    Assert::true(str_contains($code,$flag),
        'reported outcome cannot become accepted weight, fact or self-evaluation');
}
