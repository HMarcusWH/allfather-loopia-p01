<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewRightsGateway,HistoricalEvidenceEligibility,HistoryReviewContext
};

foreach(['DETERMINISTIC_INTERNAL_REVIEW','MODEL_ASSISTED_HISTORICAL_ANALYSIS',
         'EXTERNAL_PROVIDER_BYTE_DISCLOSURE'] as $purpose){
    $result=HistoricalEvidenceEligibility::purposePosture($purpose);
    Assert::same(null,$result->value()['u03_contract_hash'],
        'no fake adopted canonical U03 hash exists for '.$purpose);
    Assert::same('PENDING',$result->value()['u03_owner_ratification'],
        'missing six owners cannot be simulated as approval');
    Assert::same(false,$result->value()['model_analysis_admitted'],
        'model inference remains held');
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/app/Workflow/HistoryReviewRightsGateway.php');
foreach(['curl_exec(', 'new PDO(', 'file_get_contents(', 'ModelExecutor::',
         'new ModelExecutor', 'releaseP01(', 'activate('] as $disallowed){
    Assert::false(str_contains($source,$disallowed),
        'HS02 owner inspection cannot perform fetch, model or release effects');
}
Assert::true(str_contains($source,'HistoricalCaptureProbe'),
    'existing M02/M05 native owner, not a competing rights SQL engine, handles readback');
Assert::true(str_contains($source,'HOLD_U03_HISTORICAL_USE_NOT_RATIFIED'),
    'native proof is guaranteed not to be U03 permission');
$context=new HistoryReviewContext();
Assert::same('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED',
    $context->revalidate(['user_claimed_permission'=>true])->reasonCode(),
    'legacy dispatch-time ModelContextAuthority refuses even forged grants');
$forged=HistoricalEvidenceEligibility::describe(['reported_disposition'=>'UNASSESSED',
    'purpose'=>'ALL_PURPOSES']);
Assert::same('LI05_DISPOSITION_CONTRACT_INVALID',$forged->reasonCode(),
    'existing v1 eligibility cannot be widened with new caller flags');
