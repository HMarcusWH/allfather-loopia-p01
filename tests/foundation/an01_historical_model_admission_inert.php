<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    ExecutionAdmission,ExecutionGrantStore,
    HistoryReviewAdmission,HistoryReviewReconciliation,HistoryReviewAttemptStore
};

$root=dirname(__DIR__,2);
Assert::false(in_array(HistoryReviewAdmission::PURPOSE,ExecutionGrantStore::PURPOSES,true),
    'manual historical-model purpose remains unadopted by active parent execution owner');
Assert::false(isset(ExecutionAdmission::CHECKPOINTS['HISTORICAL_MODEL_BEFORE_RESERVE']),
    'no new checkpoint can grant model execution');
Assert::true(is_file($root.'/docs/longitudinal/proposals/310_history_review_execution_CANDIDATE.sql'),
    'family310 remains a separate proposed candidate');
Assert::false(is_file($root.'/database/migrations/310_history_review_execution.sql'),
    'candidate 310 cannot be auto-migrated to main/staging');
$fakeDb=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
Assert::same('AN01_U03_U06_U08_RUNTIME_MODEL_ADMISSION_NOT_ADOPTED',
    HistoryReviewAttemptStore::forRuntime($fakeDb,new Clock(),[])->reasonCode(),
    'runtime constructor refuses before any DB connection or provider call');
foreach(['app/Workflow/HistoryReviewAdmission.php',
         'app/Workflow/HistoryReviewReconciliation.php'] as $path){
    $file=(string)file_get_contents($root.'/'.$path);
    foreach(['curl_exec(', 'new PDO(', 'new ModelExecutor(', 'releaseP01('] as $needle){
        Assert::false(str_contains($file,$needle),
            'pure AN01 candidate contracts cannot send external effects: '.$needle);
    }
}
foreach(['ops/history/admission-candidate.php',
         'ops/history/reconciliation-candidate.php'] as $path){
    $file=(string)file_get_contents($root.'/'.$path);
    Assert::true(str_contains($file,"PHP_SAPI !== 'cli'"),
        'candidate CLI refuses HTTP before including autoload');
    Assert::true(strpos($file,"PHP_SAPI !== 'cli'")<strpos($file,"autoload.php"),
        'client refuses before any implementation loads');
}
