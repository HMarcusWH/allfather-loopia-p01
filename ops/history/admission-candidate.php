<?php

declare(strict_types=1);

/** AN-01: source-only inspection of an unratified historical request purpose. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\HistoryReviewAdmission;

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AN01_HISTORICAL_MODEL_OWNER_SESSION_REQUIRED\n");exit(3);
}
if($args[0]==='--status'||$args[0]==='--help'){
    echo json_encode([
        'version'=>HistoryReviewAdmission::VERSION,
        'purpose'=>HistoryReviewAdmission::PURPOSE,
        'state'=>'NOT_RATIFIED_NO_LIVE_EXECUTION',
        'existing_model_purposes_unchanged'=>true,
        'historical_rights_admitted'=>false,
        'execution_grant_created'=>false,
        'model_call_authorized'=>false,
        'parent_budget_spent'=>false,
        'customer_release_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_AN01_SYNTHETIC_TEST=1',
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit(0);
}
if(getenv('ALLFATHER_AN01_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AN01_SYNTHETIC_TEST_ONLY\n");exit(3);
}
try {
    $scope=['tenant_id'=>'an01_demo_tenant',
        'workspace_id'=>'an01_demo_workspace',
        'competitor_id'=>'an01_demo_competitor',
        'question_id'=>'an01_demo_question'];
    $input=[
        'version'=>HistoryReviewAdmission::VERSION,'scope'=>$scope,
        'review_id'=>'an01_synthetic_review',
        'basis_digest'=>str_repeat('a',64),
        'question_digest'=>str_repeat('b',64),
        'prompt_bundle_digest'=>str_repeat('c',64),
        'model_policy_digest'=>str_repeat('d',64),
        'credential_ref_digest'=>str_repeat('e',64),
        'parent_grant_digest'=>str_repeat('f',64),
        'app_fingerprint'=>str_repeat('1',64),
        'request_digest'=>str_repeat('2',64),
        'attempt_id'=>'an01_synthetic_attempt',
        'purpose'=>HistoryReviewAdmission::PURPOSE,
        'mode'=>HistoryReviewAdmission::MODE,
        'requested_at_utc'=>'2026-10-29T12:00:00Z',
        'valid_from_utc'=>'2026-10-29T11:00:00Z',
        'valid_until_utc'=>'2026-10-29T14:00:00Z',
        'parent_max_attempts'=>8,'review_max_attempts'=>4,
        'max_input_tokens'=>4096,'max_output_tokens'=>1024,
        'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
    ];
    $c=HistoryReviewAdmission::freeze($input);
    if(!$c->isOk())throw new \RuntimeException('AN01_SYNTHETIC_CANDIDATE_INVALID');
    echo json_encode([
        'version'=>HistoryReviewAdmission::VERSION,
        'candidate_digest'=>$c->value()['candidate_digest'],
        'parent_max_attempts'=>$c->value()['parent_max_attempts'],
        'review_max_attempts'=>$c->value()['review_max_attempts'],
        'mode'=>'SYNTHETIC_TEST_INSPECTION_ONLY',
        'source_body_bytes'=>0,
        'model_execution_authorized'=>false,
        'runtime_grant_admitted'=>false,
        'customer_release_authorized'=>false,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AN01_SYNTHETIC_PREVIEW_UNAVAILABLE\n");exit(4);
}
