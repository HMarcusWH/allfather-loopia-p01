<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewReconciliation,ExecutionGrantStore,
    ExecutionAdmission
};

Assert::false(in_array(HistoryReviewAdmission::PURPOSE,
    ExecutionGrantStore::PURPOSES,true),
    'U06 historical review is not an existing authorized provider purpose');
Assert::false(in_array(HistoryReviewAdmission::PURPOSE,
    ExecutionAdmission::CHECKPOINTS['MODEL_BEFORE_DISPATCH'][0],true),
    'U06 cannot borrow live manual/evaluation model checkpoints');
foreach([
    'app/Workflow/HistoryReviewAdmission.php',
    'app/Workflow/HistoryReviewReconciliation.php',
] as $path){
    $s=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach(['curl_exec(', 'new PDO(', 'new ModelExecutor(', '->send(',
             'mail(', 'unlink(', 'DELETE FROM', 'releaseP01('] as $forbidden){
        Assert::false(str_contains($s,$forbidden),
            'AN01 candidate parser cannot send provider or source effects');
    }
}
Assert::same('AN01_U03_U06_U08_HISTORICAL_MODEL_ADMISSION_NOT_RATIFIED',
    HistoryReviewAdmission::authorize(['forged_allow'=>true])->reasonCode(),
    'future environment or caller flag cannot activate an unratified purpose');
$source=(string)file_get_contents(
    dirname(__DIR__,2).'/app/Workflow/HistoryReviewReconciliation.php');
Assert::true(str_contains($source,'UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD'),
    'unknown provider result is a named no-retry hold');
Assert::true(str_contains($source,'parent_budget_refunded'),
    'no automatic refund from unknown transport/worker state');
