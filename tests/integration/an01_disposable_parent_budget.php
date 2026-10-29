<?php

declare(strict_types=1);

/**
 * AN-01: 310 disposable-only parent/per-review candidate reservation.
 * No real M06/M01 call, execution grant, provider response or customer release.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewReconciliation,HistoryReviewAttemptStore,
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$path=$root.'/docs/longitudinal/proposals/310_history_review_execution_CANDIDATE.sql';
$ddl=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(9,count($ddl),'three 310 metadata tables plus six immutable triggers');
$tables=[
    'history_review_reconciliation_candidates',
    'history_review_attempt_candidates','history_review_budget_candidates',
];
$installed=false;
try {
    foreach($ddl as $sql)$db->pdo()->exec($sql);
    $installed=true;
    if(!defined('ALLFATHER_AN01_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_AN01_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-29T12:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'an01-owner');
    $other=Seed::tenantWorkspace($db,$clock,'an01-other');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AN01 test-only candidate',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE',
        'created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $input=[
        'version'=>HistoryReviewAdmission::VERSION,'scope'=>$scope,
        'review_id'=>'an01_review_one',
        'basis_digest'=>str_repeat('a',64),
        'question_digest'=>str_repeat('b',64),
        'prompt_bundle_digest'=>str_repeat('c',64),
        'model_policy_digest'=>str_repeat('d',64),
        'credential_ref_digest'=>str_repeat('e',64),
        'parent_grant_digest'=>str_repeat('f',64),
        'app_fingerprint'=>str_repeat('1',64),
        'request_digest'=>str_repeat('2',64),
        'attempt_id'=>'an01_attempt_one',
        'purpose'=>HistoryReviewAdmission::PURPOSE,
        'mode'=>HistoryReviewAdmission::MODE,
        'requested_at_utc'=>'2026-10-29T12:00:00Z',
        'valid_from_utc'=>'2026-10-29T11:00:00Z',
        'valid_until_utc'=>'2026-10-29T14:00:00Z',
        'parent_max_attempts'=>3,'review_max_attempts'=>2,
        'max_input_tokens'=>4096,'max_output_tokens'=>1024,
        'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
    ];
    $make=static function(array $i):array {
        $out=HistoryReviewAdmission::freeze($i);
        Assert::true($out->isOk(),'synthetic request metadata is versioned and bounded');
        if(!$out->isOk())throw new RuntimeException($out->reasonCode());
        return $out->value();
    };
    $candidate=$make($input);
    $store=HistoryReviewAttemptStore::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'candidate factory exists only after explicit disposable schema');
    if(!$store->isOk())return;
    $store=$store->value();
    $first=$store->reserveCandidate($candidate);
    Assert::true($first->isOk(),'parent and review budget candidate reserved');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_ATTEMPT_CANDIDATE_RESERVED',$first->value()['action'],
        'dry-run reservation only, not M06 or provider dispatch');
    Assert::same(1,$first->value()['parent_units_reserved'],'first parent unit counted');
    Assert::same(1,$first->value()['review_units_reserved'],'first review unit counted');
    Assert::same(false,$first->value()['runtime_execution_authorized'],
        'candidate reservation never enables actual model execution');
    Assert::same('EXACT_ATTEMPT_REPLAY_NO_WRITE',
        $store->reserveCandidate($candidate)->value()['action'],
        'retry of exact request/attempt cannot duplicate reservation');

    $duplicate=$input;$duplicate['attempt_id']='an01_retry_blind';
    Assert::same('AN01_REQUEST_ALREADY_RESERVED_WITH_DIFFERENT_ATTEMPT',
        $store->reserveCandidate($make($duplicate))->reasonCode(),
        'unknown outcome must not be blindly retransmitted using another attempt');
    $second=$input;$second['attempt_id']='an01_attempt_two';
    $second['request_digest']=str_repeat('3',64);
    $r2=$store->reserveCandidate($make($second));
    Assert::true($r2->isOk(),'second distinct request can consume a bounded unit');
    Assert::same(2,$r2->value()['review_units_reserved'],
        'the same review has exactly two candidate units counted');
    $third=$input;$third['attempt_id']='an01_attempt_three';
    $third['request_digest']=str_repeat('4',64);
    Assert::same('AN01_CANDIDATE_PARENT_OR_REVIEW_CAP_EXHAUSTED',
        $store->reserveCandidate($make($third))->reasonCode(),
        'same review cannot borrow remaining parent budget');
    $different=$third;$different['review_id']='an01_review_two';
    $r3=$store->reserveCandidate($make($different));
    Assert::true($r3->isOk(),'different review may spend last parent unit');
    Assert::same(3,$r3->value()['parent_units_reserved'],
        'parent budget accounts for all reviewed attempts');
    $fourth=$different;
    $fourth['request_digest']=str_repeat('5',64);
    $fourth['attempt_id']='an01_attempt_four';
    Assert::same('AN01_CANDIDATE_PARENT_OR_REVIEW_CAP_EXHAUSTED',
        $store->reserveCandidate($make($fourth))->reasonCode(),
        'same parent cannot reset aggregate budget for a new review');
    $widen=$second;$widen['parent_max_attempts']=64;
    Assert::same('AN01_PARENT_BUDGET_VERSION_CONFLICT',
        $store->reserveCandidate($make($widen))->reasonCode(),
        'changing the same parent digest cannot increase its budget');
    $changedBasis=$second;
    $changedBasis['attempt_id']='an01_attempt_changed_basis';
    $changedBasis['request_digest']=str_repeat('7',64);
    $changedBasis['basis_digest']=str_repeat('8',64);
    Assert::same('AN01_REVIEW_SCOPE_BASIS_OR_QUESTION_CHANGED',
        $store->reserveCandidate($make($changedBasis))->reasonCode(),
        'same review cannot change basis without a successor review identity');
    $changedQuestion=$second;
    $changedQuestion['attempt_id']='an01_attempt_changed_question';
    $changedQuestion['request_digest']=str_repeat('9',64);
    $changedQuestion['question_digest']=str_repeat('0',64);
    Assert::same('AN01_REVIEW_SCOPE_BASIS_OR_QUESTION_CHANGED',
        $store->reserveCandidate($make($changedQuestion))->reasonCode(),
        'same review cannot relabel a different question');
    $extend=$input;$extend['attempt_id']='an01_different_limit';
    $extend['request_digest']=str_repeat('6',64);
    $extend['review_max_attempts']=3;
    Assert::same('AN01_REVIEW_LIMIT_VERSION_CONFLICT',
        $store->reserveCandidate($make($extend))->reasonCode(),
        'existing review budget limit cannot be raised in a later attempt');

    $clock->advanceMs(3*60*1000);
    $unknown=[
        'request_candidate'=>$candidate,
        'attempt_receipt'=>[
            'attempt_id'=>$input['attempt_id'],
            'request_digest'=>$input['request_digest'],
            'receipt_kind'=>'TRANSMISSION_OUTCOME_UNKNOWN',
            'transmitted_at_utc'=>'2026-10-29T12:01:00Z',
            'receipt_known_at_utc'=>'2026-10-29T12:02:00Z',
            'retained_candidate_digest'=>null,
            'usage_disposition'=>'UNKNOWN',
            'current_provider_owner_verified'=>false,
        ],
        'inspected_at_utc'=>'2026-10-29T12:03:00Z',
    ];
    $recon=$store->recordReconciliationCandidate($scope,
        $first->value()['history_review_attempt_candidate_id'],$unknown);
    Assert::true($recon->isOk(),'disposable UNKNOWN report recorded append-only');
    Assert::same('UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD',$recon->value()['status'],
        'unknown provider result cannot become a retry or known-zero');
    Assert::same(false,$recon->value()['automatic_paid_retry_authorized'],
        'no future M06 dispatch can be inferred');
    Assert::same('EXACT_RECONCILIATION_REPLAY_NO_WRITE',
        $store->recordReconciliationCandidate($scope,
            $first->value()['history_review_attempt_candidate_id'],$unknown)->value()['action'],
        'identical lost-worker report does not write twice');
    $conflictOutcome=$unknown;
    $conflictOutcome['attempt_receipt']['receipt_kind']='NOT_SENT_CONFIRMED';
    $conflictOutcome['attempt_receipt']['transmitted_at_utc']=null;
    $conflictOutcome['attempt_receipt']['usage_disposition']='KNOWN_ZERO';
    Assert::same('AN01_RECONCILIATION_CONFLICTING_OUTCOME_HOLD',
        $store->recordReconciliationCandidate($scope,
            $first->value()['history_review_attempt_candidate_id'],
            $conflictOutcome)->reasonCode(),
        'same attempt cannot be both transmitted-unknown and affirmatively not sent');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM history_review_reconciliation_candidates'),
        'conflicting report cannot append a new history or refund');
    $foreign=$scope;
    $foreign['tenant_id']=$other['tenant_id'];
    $foreign['workspace_id']=$other['workspace_id'];
    Assert::same('AN01_ATTEMPT_NOT_IN_OWNER_SCOPE',
        $store->recordReconciliationCandidate($foreign,
            $first->value()['history_review_attempt_candidate_id'],$unknown)->reasonCode(),
        'foreign tenant cannot read or record the owner request');

    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_review_attempt_candidates'),
        'all consumed or UNKNOWN candidate units retained (no refund)');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM history_review_budget_candidates'),
        'one immutable parent candidate identity across reviews');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM history_review_reconciliation_candidates'),
        'one immutable unknown-outcome report');
    foreach($tables as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('append-only 310 deletion accepted');
        }catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '310%'"),
        'proposed 310 DDL never enters native release migration ledger');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
