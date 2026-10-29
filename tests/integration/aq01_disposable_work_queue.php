<?php

declare(strict_types=1);

/** AQ01 three-table family300 disposable-only, no native JobMachine changes. */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewQueue,HistoryReviewQueueStore,HistoryReviewPolicy,
    HistoryReviewDue,PointInTimeHistoricalBasis
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$path=$root.'/docs/longitudinal/proposals/300_history_review_queue_jobs_CANDIDATE.sql';
$sql=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(9,count($sql),'three disposable family300 tables and six immutable triggers');
$tables=[
    'history_review_work_claim_candidates','history_review_work_members',
    'history_review_work_candidates',
];
$installed=false;
try{
    foreach($sql as $stmt)$db->pdo()->exec($stmt);
    $installed=true;
    if(!defined('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-29T10:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'aq01-own');
    $other=Seed::tenantWorkspace($db,$clock,'aq01-other');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AQ01 Synthetic work','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$r)foreach($scope as $k=>$id)$r[$k]=$id;
    unset($r);
    $basis=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>[
            'scope'=>$scope,'period'=>$v['period'],
            'baseline_period'=>$v['baseline_period'],
            'baseline_null_reason'=>null,
            'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
            'rows'=>$v['rows'],'max_references'=>8,
            'coverage'=>[
                'state'=>'KNOWN','plan_version_id'=>'aq01.db.fixture',
                'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
                'failed'=>0,'skipped'=>0,'unreported'=>0,
            ],
        ],
        'review_at_utc'=>'2026-03-15T00:00:00Z',
        'previous_basis_digest'=>null,
    ]);
    Assert::true($basis->isOk(),'frozen HS01 selected synthetic basis');
    if(!$basis->isOk())return;
    $policy=HistoryReviewPolicy::freeze([
        'version'=>HistoryReviewPolicy::VERSION,'scope'=>$scope,
        'monitored_source_refs'=>['source_a','source_b','source_late'],
        'cadence_seconds'=>86400,'maximum_wait_seconds'=>604800,
        'cooldown_seconds'=>3600,'max_pending_work'=>3,
        'max_basis_references'=>20,'unique_event_threshold'=>null,
        'maximum_reviews_per_utc_day'=>2,'calendar'=>'UTC_SECONDS_V1',
        'supported_triggers'=>[
            'new_evidence'=>true,'review_clock'=>true,
            'verified_event_count'=>false,'contradiction'=>false,
        ],
    ]);
    Assert::true($policy->isOk(),'test candidate U05 policy frozen');
    if(!$policy->isOk())return;
    $tick='2026-03-16T00:00:00Z';
    $due=HistoryReviewDue::assess([
        'policy'=>$policy->value(),'basis'=>$basis->value(),
        'state'=>[
            'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
            'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
            'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
            'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
            'last_considered_at_utc'=>'2026-03-14T00:00:00Z',
            'last_considered_basis_digest'=>$basis->value()['basis_digest'],
            'pending_work'=>0,'reviews_queued_today'=>0,
            'current_source_posture'=>'OWNER_METADATA_READ_TEST_ONLY',
            'independent_event_count'=>null,'verified_contradiction_count'=>null,
        ],'at_utc'=>$tick,
    ]);
    Assert::true($due->isOk(),'frozen DU01 quiet due can be replayed');
    if(!$due->isOk())return;
    $work=HistoryReviewQueue::freeze([
        'policy'=>$policy->value(),'due'=>$due->value(),
        'graph'=>null,
        'members'=>[
            [
                'member_id'=>'clock1','member_kind'=>'QUIET_REVIEW_CLOCK',
                'reference_digest'=>null,
                'known_at_utc'=>$tick,'recorded_at_utc'=>$tick,
            ],
            [
                'member_id'=>'capture1','member_kind'=>'OBSERVATION',
                'reference_digest'=>str_repeat('a',64),
                'known_at_utc'=>'2026-03-15T00:00:00Z',
                'recorded_at_utc'=>'2026-03-01T00:00:00Z',
            ],
        ],
        'at_utc'=>$tick,'queue_state'=>[
            'pending_reviews'=>0,'pending_member_ids'=>[],
            'oldest_pending_at_utc'=>null,
            'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
        ],
    ]);
    Assert::true($work->isOk(),'safe candidate due and members frozen');
    if(!$work->isOk())return;
    $queue=HistoryReviewQueueStore::forDisposableTest($db,$clock);
    Assert::true($queue->isOk(),'fixture can construct metadata-only candidate store');
    if(!$queue->isOk())return;
    $queue=$queue->value();
    $first=$queue->recordCandidate($work->value());
    Assert::true($first->isOk(),'append-only synthetic due work persisted');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_WORK_CANDIDATE_RECORDED',$first->value()['action'],
        'no live scheduler enqueue');
    Assert::same(false,$first->value()['live_job_created'],
        'candidate work row cannot claim a native JobMachine job');
    $workId=$first->value()['history_review_work_candidate_id'];
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_review_work_members'),
        'original observation and quiet tick each retained as immutable metadata');
    Assert::same('EXACT_WORK_REPLAY_NO_WRITE',
        $queue->recordCandidate($work->value())->value()['action'],
        'duplicate tick cannot create duplicate work or double membership');
    $claimKey=hash('sha256','aq01.synthetic-disposable.claim');
    $claim=$queue->recordFixtureClaim($scope,$workId,$claimKey);
    Assert::true($claim->isOk(),'one disposable claim candidate can be recorded');
    Assert::same(false,$claim->value()['runtime_lease_acquired'],
        'test claim is NOT an actual job lease');
    Assert::same('EXACT_FIXTURE_CLAIM_REPLAY_NO_WRITE',
        $queue->recordFixtureClaim($scope,$workId,$claimKey)->value()['action'],
        'replayed delivery cannot double-claim one candidate');
    Assert::same('AQ01_DIFFERENT_CLAIM_REJECTED',
        $queue->recordFixtureClaim($scope,$workId,hash('sha256','other'))->reasonCode(),
        'different work claimant cannot overtake an outstanding candidate');
    $foreign=$scope;
    $foreign['tenant_id']=$other['tenant_id'];
    $foreign['workspace_id']=$other['workspace_id'];
    Assert::same('AQ01_WORK_NOT_IN_CURRENT_SCOPE',
        $queue->recordFixtureClaim($foreign,$workId,$claimKey)->reasonCode(),
        'foreign tenant cannot observe the claimed work identity');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM history_review_work_claim_candidates'),
        'no second fixture claim or real lease was inserted');
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '300%'"),
        'family 300 never reached live migration ledger');
    foreach($tables as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('append-only candidate deletion accepted');
        }catch(\PDOException){Assert::pass();}
    }
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec(
            'DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
