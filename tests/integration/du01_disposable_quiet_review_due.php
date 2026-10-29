<?php

declare(strict_types=1);

/** DU-01 disposable-only 270→290 candidate policy and quiet review proof. */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewBasis,PointInTimeHistoricalBasis,HistoryReviewBasisStore,
    HistoryReviewPolicy,HistoryReviewDue,HistoryReviewDueStore
};
use Allfather\Loopia\Substrate\M17\ReviewDue;

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$proposalPaths=[
    $root.'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
];
$tables=[
    'history_review_due_checks','history_review_policy_events','history_review_policies',
    'history_review_basis_refs','history_review_bases',
];
$installed=false;
try{
    foreach($proposalPaths as $path){
        Assert::true(is_file($path),'270 and 290 stay proposed, never production migrations');
        foreach(Migrator::splitStatements((string)file_get_contents($path)) as $sql){
            $db->pdo()->exec($sql);
        }
    }
    $installed=true;
    foreach(['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
             'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $flag){
        if(!defined($flag))define($flag,true);
    }
    $clock=Harness::clock('2026-10-29T09:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'du01-owner');
    $foreign=Seed::tenantWorkspace($db,$clock,'du01-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'DU01 synthetic quiet review','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$row)foreach($scope as $key=>$value)$row[$key]=$value;
    unset($row);
    $input=[
        'scope'=>$scope,'period'=>$v['period'],'baseline_period'=>$v['baseline_period'],
        'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>[
            'state'=>'KNOWN','plan_version_id'=>'du01.synthetic',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0,
        ],
    ];
    $candidate=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>$input,'previous_basis_digest'=>null,
        'review_at_utc'=>'2026-03-15T00:00:00Z',
    ]);
    Assert::true($candidate->isOk(),'HS01 v2 immutable candidate exists before 290 due');
    if(!$candidate->isOk())return;
    $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock)->value();
    $dueStore=HistoryReviewDueStore::forDisposableTest($db,$clock)->value();
    $saved=$basisStore->recordCandidate($candidate->value());
    Assert::true($saved->isOk(),'frozen v2 basis written only to temporary 270');
    if(!$saved->isOk())return;
    $basisId=$saved->value()['history_review_basis_id'];

    $policy=HistoryReviewPolicy::freeze([
        'version'=>HistoryReviewPolicy::VERSION,'scope'=>$scope,
        'monitored_source_refs'=>['source_late','source_a','source_b'],
        'cadence_seconds'=>86400,'maximum_wait_seconds'=>7*86400,
        'cooldown_seconds'=>3600,'max_pending_work'=>3,
        'max_basis_references'=>20,'unique_event_threshold'=>null,
        'maximum_reviews_per_utc_day'=>2,'calendar'=>'UTC_SECONDS_V1',
        'supported_triggers'=>[
            'new_evidence'=>true,'review_clock'=>true,
            'verified_event_count'=>false,'contradiction'=>false,
        ],
    ]);
    Assert::true($policy->isOk(),'bounded v2 question/source review policy built');
    if(!$policy->isOk())return;
    $recorded=$dueStore->recordPolicyCandidate($scope,$policy->value());
    Assert::true($recorded->isOk(),'v2 policy persisted in existing proposal 290 schema');
    if(!$recorded->isOk())return;
    $policyId=$recorded->value()['history_review_policy_id'];
    Assert::same('EXACT_POLICY_REPLAY_NO_WRITE',
        $dueStore->recordPolicyCandidate($scope,$policy->value())->value()['action'],
        'same immutable policy does not append repeated events');
    $state=[
        'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
        'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
        'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
        'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
        'last_considered_at_utc'=>'2026-03-14T00:00:00Z',
        'last_considered_basis_digest'=>$candidate->value()['basis_digest'],
        'pending_work'=>0,'reviews_queued_today'=>0,
        'current_source_posture'=>'OWNER_METADATA_READ_TEST_ONLY',
        'independent_event_count'=>null,'verified_contradiction_count'=>null,
    ];
    $tick='2026-03-16T00:00:00Z';
    $first=$dueStore->recordDueV2Candidate($scope,$policyId,$basisId,$state,$tick);
    Assert::true($first->isOk(),'quiet time-only v2 review-due record stored in 290');
    if(!$first->isOk())return;
    Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$first->value()['disposition'],
        'time-only due is a candidate; no business event asserted');
    Assert::same(false,$first->value()['model_call_authorized'],
        'no paid model execution from due SQL');
    Assert::same(false,$first->value()['U05_policy_adopted'],
        'candidate storage cannot adopt review policy');
    $old=(int)$db->scalar('SELECT COUNT(*) FROM history_review_due_checks');
    $again=$dueStore->recordDueV2Candidate($scope,$policyId,$basisId,$state,$tick);
    Assert::same('EXACT_DUE_REPLAY_NO_WRITE',$again->value()['action'],
        'same policy/basis/cut/tick inserts no duplicate');
    Assert::same($old,(int)$db->scalar('SELECT COUNT(*) FROM history_review_due_checks'),
        'same tick consumed no second work row');

    $conflict=$state;$conflict['pending_work']=3;
    Assert::same('DU01_DUE_SAME_TICK_STATE_CONFLICT',
        $dueStore->recordDueV2Candidate($scope,$policyId,$basisId,$conflict,$tick)->reasonCode(),
        'same immutable tick with different queue state refuses, never overwrites');
    $hold=$state;$hold['current_source_posture']='UNKNOWN';
    $refused=$dueStore->recordDueV2Candidate($scope,$policyId,$basisId,$hold,
        '2026-03-16T01:00:00Z');
    Assert::same('HOLD_CURRENT_RIGHTS',$refused->value()['disposition'],
        'unknown source eligibility is a due HOLD');
    $foreignScope=$scope;
    $foreignScope['tenant_id']=$foreign['tenant_id'];
    $foreignScope['workspace_id']=$foreign['workspace_id'];
    Assert::same('DU01_POLICY_OR_BASIS_NOT_FOUND',
        $dueStore->recordDueV2Candidate($foreignScope,$policyId,$basisId,$state,
            '2026-03-16T02:00:00Z')->reasonCode(),
        'foreign scope cannot access or query policy/basis');

    $legacy=HistoryReviewBasis::freeze($input);
    $legacyId=$basisStore->recordCandidate($legacy->value())->value()['history_review_basis_id'];
    $oldPolicy=[
        'version'=>ReviewDue::VERSION,'cadence_seconds'=>86400,
        'maximum_wait_seconds'=>7*86400,
        'cooldown_seconds'=>3600,'max_pending_work'=>3,
    ];
    $oldP=$dueStore->recordPolicyCandidate($scope,$oldPolicy);
    Assert::true($oldP->isOk(),'v1 M17 ReviewDue policy still persists unchanged');
    $oldDecision=$dueStore->recordDueCandidate($scope,$oldP->value()['history_review_policy_id'],
        $legacyId,[
            'last_considered_basis_digest'=>null,'last_considered_at_utc'=>null,
            'last_queued_at_utc'=>null,'pending_work'=>0,
            'source_eligibility'=>'NOT_ASSESSED',
        ],'2026-10-26T18:00:00Z');
    Assert::true($oldDecision->isOk(),'v1 due/replay coexists with new v2 candidate');
    Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$oldDecision->value()['disposition'],
        'v1 original cases still use original frozen policy law');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_review_due_checks'),
        '2 v2 checks plus independent original v1 check');
    foreach(['history_review_due_checks','history_review_policies'] as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('append-only candidate delete should fail');
        }catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE migration_id LIKE '270%' OR migration_id LIKE '290%'"),
        'no real database migration or operator authority added');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
