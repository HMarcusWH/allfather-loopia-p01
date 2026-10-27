<?php

declare(strict_types=1);

/**
 * R2 synthetic qualification: 270 history → 280 M17 → 290 due.
 * Real MariaDB 10.6/10.11, but NO live source, provider, source rights or job.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewBasis,HistoryReviewBasisStore,
    HistoryM17CandidateBridge,HistoryReviewDueStore,HistoryReviewCandidateReadModel
};
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewReadAdapter;
use Allfather\Loopia\Substrate\M17\{ReviewDue,MemoryStore};

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$root=dirname(__DIR__,2);
$proposals=[
    $root.'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
];
$tables=['history_review_due_checks','history_review_policy_events','history_review_policies',
    'compression_lineage_links','temporal_memory_status_transitions','temporal_memories',
    'temporal_rollups','temporal_compression_runs','temporal_compression_profile_rules',
    'temporal_compression_profiles','history_review_basis_refs','history_review_bases'];
$installed=false;
try {
    foreach($proposals as $path){
        Assert::true(is_file($path),'R2 G21 and review schema proposals remain outside database/migrations');
        foreach(Migrator::splitStatements((string)file_get_contents($path)) as $stmt) {
            $db->pdo()->exec($stmt);
        }
    }
    $installed=true;
    foreach(['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
             'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',
             'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $flag) {
        if(!defined($flag))define($flag,true);
    }
    $clock=Harness::clock('2026-10-26T18:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'r2-a');
    $b=Seed::tenantWorkspace($db,$clock,'r2-b');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'display_name'=>'Synthetic R2','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=['tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new()];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$r) foreach($scope as $k=>$value)$r[$k]=$value;
    unset($r);
    $input=['scope'=>$scope,'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage']];
    $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock)->value();
    $memoryStore=MemoryStore::forDisposableTest($db,$clock)->value();
    $dueStore=HistoryReviewDueStore::forDisposableTest($db,$clock)->value();
    $base=HistoryReviewBasis::freeze($input)->value();
    $basis=$basisStore->recordCandidate($base)->value();
    $bridge=HistoryM17CandidateBridge::compose($base,'SOURCE_DIFF','MONTH')->value();
    $memory=$memoryStore->persistCandidate($bridge['frozen_m17_inputs'])->value();
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),
        'R2 synthetic path persists one metadata-only memory and complete lineage');
    $policy=[
        'version'=>ReviewDue::VERSION,'cadence_seconds'=>86400,
        'maximum_wait_seconds'=>604800,'cooldown_seconds'=>3600,'max_pending_work'=>3,
    ];
    $p=$dueStore->recordPolicyCandidate($scope,$policy);
    Assert::true($p->isOk(),'separate bounded due policy candidate stored');
    $policyId=$p->value()['history_review_policy_id'];
    Assert::same(false,$p->value()['U05_policy_adopted'],'policy creation is not owner ratification');
    $again=$dueStore->recordPolicyCandidate($scope,$policy);
    Assert::same('EXACT_POLICY_REPLAY_NO_WRITE',$again->value()['action'],
        'same policy returns one immutable version/event');
    $state=[
        'last_considered_basis_digest'=>null,'last_considered_at_utc'=>null,
        'last_queued_at_utc'=>null,'pending_work'=>0,'source_eligibility'=>'NOT_ASSESSED',
    ];
    $at='2026-10-26T18:00:00Z';
    $due=$dueStore->recordDueCandidate($scope,$policyId,
        $basis['history_review_basis_id'],$state,$at);
    Assert::true($due->isOk(),'M17 basis creates a bounded, non-executing review-due record');
    Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$due->value()['disposition'],
        'first candidate basis may suggest operator review, not model spending');
    foreach(['model_call_authorized','notification_authorized','scheduler_action_authorized',
             'U05_policy_adopted','U03_historical_reuse_admitted'] as $key) {
        Assert::same(false,$due->value()[$key],'due record cannot grant '.$key);
    }
    Assert::same('EXACT_DUE_REPLAY_NO_WRITE',$dueStore->recordDueCandidate(
        $scope,$policyId,$basis['history_review_basis_id'],$state,$at)->value()['action'],
        'exact check replay does not duplicate reviewer attention');
    $changed=$state;$changed['pending_work']=3;
    Assert::same('LI08C_DUE_CLOCK_IDENTITY_CONFLICT',$dueStore->recordDueCandidate(
        $scope,$policyId,$basis['history_review_basis_id'],$changed,$at)->reasonCode(),
        'same basis-policy-clock cannot conceal a different queue state');
    $late=$input;$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $newBasis=HistoryReviewBasis::freeze($late)->value();
    $newStored=$basisStore->recordCandidate($newBasis)->value();
    $newBridge=HistoryM17CandidateBridge::compose($newBasis,'SOURCE_DIFF','MONTH')->value();
    $child=$memoryStore->persistCandidate($newBridge['frozen_m17_inputs'],$memory['memory_id']);
    Assert::true($child->isOk(),'late evidence produces an immutable M17 successor');
    $second=$state;
    $second['last_considered_basis_digest']=$base['manifest_digest'];
    $second['last_considered_at_utc']='2026-03-15T00:00:00Z';
    $next=$dueStore->recordDueCandidate($scope,$policyId,
        $newStored['history_review_basis_id'],$second,'2026-10-26T18:00:01Z');
    Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$next->value()['disposition'],
        'new known-at basis requests a new candidate review');
    $held=$second;$held['source_eligibility']='CURRENT_RIGHTS_BLOCKED';
    $blocked=$dueStore->recordDueCandidate($scope,$policyId,
        $newStored['history_review_basis_id'],$held,'2026-10-26T18:00:02Z');
    Assert::same('HOLD_CURRENT_RIGHTS',$blocked->value()['disposition'],
        'rights narrowing causes HOLD instead of automatic analysis');
    $all=$dueStore->listDueCandidates($scope,20);
    Assert::same(3,count($all->value()['rows']),'all independent due, successor and hold records retained');
    $foreign=$scope;$foreign['tenant_id']=$b['tenant_id'];$foreign['workspace_id']=$b['workspace_id'];
    Assert::same(0,count($dueStore->listDueCandidates($foreign,20)->value()['rows']),
        'foreign tenant receives an empty metadata result');
    Assert::same('LI08C_POLICY_OR_BASIS_NOT_FOUND',$dueStore->recordDueCandidate(
        $foreign,$policyId,$newStored['history_review_basis_id'],$state,'2026-10-26T18:00:03Z'
    )->reasonCode(),'foreign tenant cannot spend a different tenant policy');
    // Full private readback: owner-enforced M02 permissions followed by the
    // exact presentation whitelist. No HTTP route or source bytes are involved.
    $role=Seed::role($db,$clock,'r2-history-evidence-read',[
        'competition_monitoring.read','evidence.read',
    ]);
    Seed::membership($db,$clock,$a['tenant_id'],$a['workspace_id'],
        $a['operator_id'],$role['role_id']);
    $owner=HistoryReviewCandidateReadModel::forDisposableTest($db,$clock);
    Assert::true($owner->isOk(),'test-only M02-scoped history reader constructed');
    $operatorRead=$owner->value();
    $presentation=new HistoryReviewReadAdapter(
        static fn(array $readScope,int $limit):array=>$operatorRead->read($readScope,$limit)
    );
    $session=['tenant_id'=>$a['tenant_id'],'active_workspace_id'=>$a['workspace_id'],
        'operator_id'=>$a['operator_id']];
    $screen=$presentation->list($session,$competitor,$scope['question_id'],20);
    Assert::same('VISIBLE',$screen['decision'],
        'authorized operator receives only scoped synthetic candidate metadata');
    Assert::same(2,count($screen['data']['rows']),
        'private readback includes both immutable point-in-time basis versions');
    Assert::same(false,$screen['data']['runtime_actions_available'],
        'readback does not turn due decisions into a job');
    $display=json_encode($screen,JSON_THROW_ON_ERROR);
    foreach(['source_url','raw_source_text','payload_sha256','provider_token'] as $private){
        Assert::false(str_contains($display,$private),
            'private operator presentation cannot leak raw owner content: '.$private);
    }
    $unassigned=$session;$unassigned['operator_id']=Ids::new();
    Assert::same('DENIED',$presentation->list(
        $unassigned,$competitor,$scope['question_id'],20)['decision'],
        'M02 rejects unassigned principals before any historical read');
    Assert::same('NOT_FOUND',$presentation->list(
        $session,'bogus-competitor-id',$scope['question_id'],20)['decision'],
        'invalid cross-scope selector never queries the owner reader');
    foreach(['history_review_due_checks','history_review_policies'] as $table){
        try{$db->run('DELETE FROM '.Database::identifier($table));Assert::fail('DELETE accepted');}
        catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations
        WHERE migration_id LIKE '27%' OR migration_id LIKE '28%' OR migration_id LIKE '29%'"),
        'three proposal migration families cannot enter live migration ledger');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table) $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
