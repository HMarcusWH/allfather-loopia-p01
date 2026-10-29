<?php

declare(strict_types=1);

use Allfather\Loopia\Core\{Clock,Digest};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Jobs\{HistoryReviewDueHandler,TemporalRollupHandler,JobMachine};
use Allfather\Loopia\Workflow\{HistoryReviewQueueStore,HistoryReviewQueue};

$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
Assert::same('AQ01_U05_U08_RUNTIME_QUEUE_NOT_ADOPTED',
    HistoryReviewQueueStore::forRuntime($db,$clock,[
        'claimed_admin_approval'=>true,
    ])->reasonCode(),
    'even self-signed U05/U08 flags cannot create a runtime queue');
$fixture=HistoryReviewQueueStore::forDisposableTest($db,$clock);
if(defined('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED')
    && constant('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED')===true
    && PHP_SAPI==='cli'){
    Assert::true($fixture->isOk(),
        'an earlier disposable integration marker is still TEST-only');
}else{
    Assert::same('AQ01_U05_U08_QUEUE_FACTORY_NOT_ADOPTED',
        $fixture->reasonCode(),
        'no test marker means candidate store refuses');
}
Assert::same('AQ01_U05_U08_REVIEW_DUE_SCHEDULER_NOT_ADOPTED',
    HistoryReviewDueHandler::activate(['grant'=>'ALLOW'])->reasonCode(),
    'candidate due handler has no native scheduler registration');
Assert::same('AQ01_U04_U05_U08_TEMPORAL_JOB_NOT_ADOPTED',
    TemporalRollupHandler::activate(['grant'=>'ALLOW'])->reasonCode(),
    'candidate rollup handler cannot trigger an M17 writer');
Assert::false(isset(JobMachine::TYPE_TRANSITIONS['HISTORY_REVIEW_DUE']),
    'existing durable machine receives no new unauthorized review job type');
Assert::false(isset(JobMachine::TYPE_TRANSITIONS['TEMPORAL_ROLLUP']),
    'existing durable machine receives no new unauthorized temporal job type');

foreach([
    'app/Workflow/HistoryReviewQueue.php',
    'app/Workflow/HistoryReviewQueueStore.php',
    'app/Jobs/HistoryReviewDueHandler.php',
    'app/Jobs/TemporalRollupHandler.php',
] as $path){
    $src=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach(['curl_exec(', 'file_get_contents(', 'new ModelExecutor(', '->send(',
        'new SchedulerRunner(', 'mail(', 'DELETE FROM', 'unlink(',
        'SourceCaptureStage::execute('] as $forbidden){
        Assert::false(str_contains($src,$forbidden),
            'AQ01 inert handlers and queue have no source/provider/action egress');
    }
}
$src=(string)file_get_contents(dirname(__DIR__,2).'/app/Workflow/HistoryReviewQueue.php');
foreach(['independent_event_count','independent_origin_count',
    'coalescing_discarded_members','HOLD_CRITICAL_SOURCE_OR_INVALIDATION_REVIEW',
    'HOLD_FROZEN_CUTOFF_EXCEEDED'] as $required){
    Assert::true(str_contains($src,$required),
        'critical/unknown provenance cannot be silently removed: '.$required);
}
