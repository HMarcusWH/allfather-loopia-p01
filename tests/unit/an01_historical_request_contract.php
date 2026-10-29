<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    ExecutionAdmission,ExecutionGrantStore,
    HistoryReviewAdmission,HistoryReviewReconciliation
};

Assert::false(in_array(HistoryReviewAdmission::PURPOSE,ExecutionGrantStore::PURPOSES,true),
    'unknown historical purpose cannot borrow a MANUAL_MONITOR or MODEL_EVALUATION grant');
Assert::false(isset(ExecutionAdmission::CHECKPOINTS['HISTORICAL_MODEL_BEFORE_DISPATCH']),
    'no unratified model dispatch checkpoint has been installed');

$scope=[
    'tenant_id'=>'test_tenant','workspace_id'=>'test_workspace',
    'competitor_id'=>'test_competitor','question_id'=>'test_question',
];
$input=[
    'version'=>HistoryReviewAdmission::VERSION,'scope'=>$scope,
    'review_id'=>'test_review','basis_digest'=>str_repeat('a',64),
    'question_digest'=>str_repeat('b',64),
    'prompt_bundle_digest'=>str_repeat('c',64),
    'model_policy_digest'=>str_repeat('d',64),
    'credential_ref_digest'=>str_repeat('e',64),
    'parent_grant_digest'=>str_repeat('f',64),
    'app_fingerprint'=>str_repeat('1',64),
    'request_digest'=>str_repeat('2',64),
    'attempt_id'=>'test_attempt_1',
    'purpose'=>HistoryReviewAdmission::PURPOSE,'mode'=>HistoryReviewAdmission::MODE,
    'requested_at_utc'=>'2026-10-29T12:00:00Z',
    'valid_from_utc'=>'2026-10-29T11:00:00Z',
    'valid_until_utc'=>'2026-10-29T14:00:00Z',
    'parent_max_attempts'=>12,'review_max_attempts'=>4,
    'max_input_tokens'=>4096,'max_output_tokens'=>1024,
    'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
];
$c=HistoryReviewAdmission::freeze($input);
Assert::true($c->isOk(),'bounded historical review request freezes for synthetic fixture');
if(!$c->isOk())return;
$manifest=$c->value();
Assert::true(HistoryReviewAdmission::verify($manifest)->isOk(),
    'candidate integrity is deterministic');
foreach(['owner_U03_ratified','owner_U06_ratified','owner_U08_ratified',
         'runtime_execution_authorized','model_attempt_reserved',
         'provider_disclosure_authorized','customer_release_authorized'] as $flag){
    Assert::same(false,$manifest[$flag],'candidate cannot mint '.$flag);
}
Assert::same('AN01_U03_U06_U08_HISTORICAL_MODEL_ADMISSION_NOT_RATIFIED',
    HistoryReviewAdmission::authorize($manifest)->reasonCode(),
    'a syntactically valid request is not an execution grant');

$change=$input;$change['purpose']='MODEL_EVALUATION';
Assert::same('AN01_HISTORICAL_REQUEST_CONTRACT_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'historical review cannot borrow a MODEL_EVALUATION purpose');
$change=$input;$change['purpose']='MANUAL_MONITOR';
Assert::same('AN01_HISTORICAL_REQUEST_CONTRACT_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'existing monitor budget cannot be widened to history');
$change=$input;$change['parent_max_attempts']=2;
Assert::same('AN01_HISTORICAL_BUDGET_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'review limit cannot exceed parent budget');
$change=$input;$change['review_max_attempts']=5;
Assert::same('AN01_HISTORICAL_BUDGET_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'one manual review cannot silently exceed the 4-attempt fixture ceiling');
$change=$input;$change['requested_at_utc']='2026-10-29T15:00:00Z';
Assert::same('AN01_HISTORICAL_REQUEST_CONTRACT_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'expired request is not an executable historical admission');
$change=$input;$change['credential_ref_digest']='provider-secret';
Assert::same('AN01_HISTORICAL_REQUEST_DIGEST_INVALID',
    HistoryReviewAdmission::freeze($change)->reasonCode(),
    'credential bytes cannot replace a safe metadata digest');

$unknown=[
    'request_candidate'=>$manifest,
    'attempt_receipt'=>[
        'attempt_id'=>'test_attempt_1','request_digest'=>str_repeat('2',64),
        'receipt_kind'=>'TRANSMISSION_OUTCOME_UNKNOWN',
        'transmitted_at_utc'=>'2026-10-29T12:01:00Z',
        'receipt_known_at_utc'=>'2026-10-29T12:02:00Z',
        'retained_candidate_digest'=>null,
        'usage_disposition'=>'UNKNOWN',
        'current_provider_owner_verified'=>false,
    ],
    'inspected_at_utc'=>'2026-10-29T12:03:00Z',
];
$out=HistoryReviewReconciliation::inspect($unknown);
Assert::true($out->isOk(),'UNKNOWN transport outcome classified without retries');
if(!$out->isOk())return;
Assert::same('UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD',$out->value()['status'],
    'an uncertain transmission is a hold, never a claimed failure/zero cost');
Assert::same(true,$out->value()['usage_counted_as_spent_or_unknown'],
    'uncertain provider call continues to count against budget');
Assert::same(false,$out->value()['new_attempt_authorized'],
    'unknown must not create an automatic paid retry');
Assert::true(HistoryReviewReconciliation::verify($out->value())->isOk(),
    'bounded reconciliation receipt digest verifies');

$noSend=$unknown;
$noSend['attempt_receipt']['receipt_kind']='NOT_SENT_CONFIRMED';
$noSend['attempt_receipt']['transmitted_at_utc']=null;
$noSend['attempt_receipt']['usage_disposition']='KNOWN_ZERO';
$zero=HistoryReviewReconciliation::inspect($noSend);
Assert::same('NOT_SENT_CANDIDATE_CONFIRMATION_ONLY',$zero->value()['status'],
    'affirmatively not-sent is distinct from provider-unknown');
Assert::same(false,$zero->value()['usage_counted_as_spent_or_unknown'],
    'verified no-send is not an attempted paid model execution');
$positive=$unknown;
$positive['attempt_receipt']['receipt_kind']='PROVIDER_RETURN_RETAINED_METADATA';
$positive['attempt_receipt']['retained_candidate_digest']=str_repeat('3',64);
$positive['attempt_receipt']['usage_disposition']='KNOWN_NONZERO';
$safe=HistoryReviewReconciliation::inspect($positive);
Assert::same('RETAINED_METADATA_NOT_ADOPTED_FOR_USE',$safe->value()['status'],
    'retained provider metadata is not an authorized historical finding');
Assert::same(false,$safe->value()['provider_response_bytes_authenticated'],
    'caller metadata is not independently verified source/provider bytes');
$bad=$unknown;$bad['attempt_receipt']['usage_disposition']='KNOWN_ZERO';
Assert::same('AN01_RECONCILIATION_UNKNOWN_OUTCOME_CONTRACT_INVALID',
    HistoryReviewReconciliation::inspect($bad)->reasonCode(),
    'unknown transport must not be relabeled free');
$bad=$unknown;$bad['attempt_receipt']['current_provider_owner_verified']=true;
Assert::same('AN01_RECONCILIATION_IDENTITY_OR_OWNER_INVALID',
    HistoryReviewReconciliation::inspect($bad)->reasonCode(),
    'self-claimed provider authentication refuses');
$bad=$unknown;$bad['attempt_receipt']['attempt_id']='different_attempt';
Assert::same('AN01_RECONCILIATION_IDENTITY_OR_OWNER_INVALID',
    HistoryReviewReconciliation::inspect($bad)->reasonCode(),
    'attempt identity cannot be swapped during recovery');
$bad=$manifest;$bad['runtime_execution_authorized']=true;
$unsigned=$bad;unset($unsigned['candidate_digest']);
$bad['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN01_CANDIDATE_AUTHORITY_FORGED',
    HistoryReviewAdmission::verify($bad)->reasonCode(),
    'valid self-hash cannot mint an execution grant');
