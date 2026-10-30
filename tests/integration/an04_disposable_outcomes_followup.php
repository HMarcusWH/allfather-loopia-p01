<?php

declare(strict_types=1);

/** AN04 disposable family330 + 345 owner-binding and append-only replay. */
require_once dirname(__DIR__).'/support/An03MockFinding.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed,An03MockFinding};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Workflow\{
    HistoricalFindingStore,HistoryReviewOutcomes,
    HistoryReviewFollowUp,HistoryReviewOutcomeStore
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$sql330=Migrator::splitStatements((string)file_get_contents(
    $root.'/docs/longitudinal/proposals/330_historical_finding_bindings_CANDIDATE.sql'));
$sql345=Migrator::splitStatements((string)file_get_contents(
    $root.'/docs/longitudinal/proposals/345_history_review_outcomes_CANDIDATE.sql'));
Assert::same(9,count($sql330),'three 330 tables and six immutable triggers');
Assert::same(6,count($sql345),'two 345 tables and four immutable triggers');
$tables=[
    'history_review_followup_conditions','history_review_outcome_annotations',
    'historical_finding_review_links','historical_finding_basis_bindings',
    'historical_finding_candidates',
];
$installed=false;
try{
    foreach([...$sql330,...$sql345] as $statement)$db->pdo()->exec($statement);
    $installed=true;
    if(!defined('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED',true);
    }
    if(!defined('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED',true);
    }
    $clock=Harness::clock('2026-10-30T15:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'an04-owner');
    $foreign=Seed::tenantWorkspace($db,$clock,'an04-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AN04 synthetic later outcomes',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE',
        'created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $inputs=An03MockFinding::inputs($scope);
    Assert::true($inputs->isOk(),'original multi-reference mock is available');
    if(!$inputs->isOk())return;
    $finding=HistoricalFindingAdapter::propose($inputs->value());
    Assert::true($finding->isOk(),'original as-of historical finding still provisional');
    if(!$finding->isOk())return;
    $store330=HistoricalFindingStore::forDisposableTest($db,$clock);
    $store345=HistoryReviewOutcomeStore::forDisposableTest($db,$clock);
    Assert::true($store330->isOk(),'exact original 330 owner fixture available');
    Assert::true($store345->isOk(),'separate AN04 annotation ledger available');
    if(!$store330->isOk()||!$store345->isOk())return;
    $store330=$store330->value();$store345=$store345->value();
    Assert::true($store330->persistCandidate($finding->value())->isOk(),
        'original finding recorded without any M10 release');
    $orig=$finding->value();
    $new=[
        'ref_id'=>'an04_later_ref_1','source_ref_id'=>'an04_later_source_1',
        'evidence_id'=>'an04_later_evidence_1',
        'snapshot_id'=>'an04_later_snapshot_1',
        'payload_sha256'=>str_repeat('d',64),
        'observed_at_utc'=>'2026-03-18T00:00:00Z',
        'known_at_utc'=>'2026-03-19T00:00:00Z',
        'polarity'=>'SUPPORT',
    ];
    $payload=[
        'finding'=>$orig,'prior_annotation'=>null,
        'disposition'=>'REPORTED_CONFIRMED_IN_SCOPE',
        'new_evidence'=>[$new],
        'inspected_at_utc'=>'2026-10-29T12:00:00Z',
        'deadline_utc'=>'2026-11-05T00:00:00Z',
        'next_check_code'=>'HUMAN_VALIDATE_SOURCE',
    ];
    $annot=HistoryReviewOutcomes::propose($payload);
    Assert::true($annot->isOk(),'later support remains reported, not adjudicated');
    if(!$annot->isOk())return;
    $first=$store345->recordAnnotation($orig,$annot->value());
    Assert::true($first->isOk(),'scoped outcome candidate bound to stored family330 finding');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_OUTCOME_ANNOTATION_RECORDED',$first->value()['action'],
        'new outcome appended, never backdated over original');
    Assert::same(false,$first->value()['business_outcome_adjudicated'],
        'reported confirmation cannot become verified commercial success');
    Assert::same('EXACT_OUTCOME_REPLAY_NO_WRITE',
        $store345->recordAnnotation($orig,$annot->value())->value()['action'],
        'original outcome cannot be written twice');
    $follow=HistoryReviewFollowUp::freeze([
        'annotation'=>$annot->value(),
        'followup_kind'=>'REQUEST_SOURCE_OWNER_REVIEW',
        'target_source_ref_ids'=>['an04_later_source_1'],
        'requested_at_utc'=>'2026-10-30T12:00:00Z',
        'not_before_utc'=>'2026-10-31T00:00:00Z',
        'deadline_utc'=>'2026-11-05T00:00:00Z',
        'operator_note_code'=>'REQUEST_OWNER_SOURCE_USE_AUTHORIZATION',
    ]);
    Assert::true($follow->isOk(),'proposed next step is bounded and unscheduled');
    if(!$follow->isOk())return;
    $work=$store345->recordFollowUp($follow->value());
    Assert::true($work->isOk(),'follow-up candidate bound to existing annotation');
    Assert::same('DISPOSABLE_FOLLOWUP_CONDITION_RECORDED',$work->value()['action'],
        'a persisted follow-up is NOT a JobMachine scheduler occurrence');
    Assert::same(false,$work->value()['scheduler_job_created'],
        'no unattended source fetch or model expenditure');
    Assert::same('EXACT_FOLLOWUP_REPLAY_NO_WRITE',
        $store345->recordFollowUp($follow->value())->value()['action'],
        'follow-up idempotency never duplicates source actions');
    $heads=$store345->candidateHeads($scope,$orig['finding_digest']);
    Assert::same('ONE_UNADJUDICATED_CANDIDATE_HEAD',$heads->value()['status'],
        'one provisional annotation is never a verified conclusion');

    $later=$payload;
    $later['prior_annotation']=$annot->value();
    $later['disposition']='REPORTED_CONTRADICTED';
    $later['inspected_at_utc']='2026-10-30T12:00:00Z';
    $later['new_evidence'][0]['ref_id']='an04_contrary_ref_2';
    $later['new_evidence'][0]['polarity']='CONTRARY';
    $later['new_evidence'][0]['known_at_utc']='2026-10-30T10:00:00Z';
    $later['new_evidence'][0]['observed_at_utc']='2026-10-30T09:00:00Z';
    $second=HistoryReviewOutcomes::propose($later);
    Assert::true($second->isOk(),'later contradiction creates typed successor');
    if(!$second->isOk())return;
    Assert::same('DISPOSABLE_OUTCOME_ANNOTATION_RECORDED',
        $store345->recordAnnotation($orig,$second->value())->value()['action'],
        'outcome successor appends without rewriting original');
    Assert::same('ONE_UNADJUDICATED_CANDIDATE_HEAD',
        $store345->candidateHeads($scope,$orig['finding_digest'])->value()['status'],
        'single linked successor is unadjudicated and canonical by lineage only');

    $fork=$later;
    $fork['new_evidence'][0]['ref_id']='an04_contrary_ref_3';
    $fork['new_evidence'][0]['payload_sha256']=str_repeat('f',64);
    $third=HistoryReviewOutcomes::propose($fork);
    Assert::true($third->isOk(),'distinct same-parent candidate is a separate branch');
    Assert::true($store345->recordAnnotation($orig,$third->value())->isOk(),
        'fork history append-only, not last-write-wins');
    Assert::same('AMBIGUOUS_OUTCOME_HEAD_HOLD',
        $store345->candidateHeads($scope,$orig['finding_digest'])->value()['status'],
        'incomparable outcome siblings cannot choose a winner on creation time');
    $other=$scope;$other['tenant_id']=$foreign['tenant_id'];
    $other['workspace_id']=$foreign['workspace_id'];
    Assert::same('NO_CANDIDATE',
        $store345->candidateHeads($other,$orig['finding_digest'])->value()['status'],
        'foreign workspace cannot discover another tenant outcome candidates');
    Assert::same(3,(int)$db->scalar(
        'SELECT COUNT(*) FROM history_review_outcome_annotations'),
        'original report, contradictory successor and independent fork retained');
    Assert::same(1,(int)$db->scalar(
        'SELECT COUNT(*) FROM history_review_followup_conditions'),
        'one bounded follow-up candidate, no duplicate schedule');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM historical_finding_candidates'),
        'the AN03 historical finding remains immutable across all later annotations');
    foreach($tables as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('append-only 330/345 accepted deletion');
        }catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '345%'"),
        'candidate family345 never became a native release migration');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec(
            'DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
