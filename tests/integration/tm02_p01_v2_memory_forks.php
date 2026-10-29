<?php

declare(strict_types=1);

/**
 * TM-02: owner-unadmitted P01 typed M17 v2 persistence, forks and replay
 * in independently disposable MariaDB. No source fetch/model/real rights.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2};
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryM17V2CandidateBridge};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql';
$sql=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(21,count($sql),'seven unadopted M17 tables plus fourteen append-only triggers');
$tables=[
    'compression_lineage_links','temporal_memory_status_transitions',
    'temporal_memories','temporal_rollups','temporal_compression_runs',
    'temporal_compression_profile_rules','temporal_compression_profiles',
];
$installed=false;
try{
    foreach($sql as $statement)$db->pdo()->exec($statement);
    $installed=true;
    if(!defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-29T10:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'tm02-owner');
    $b=Seed::tenantWorkspace($db,$clock,'tm02-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'display_name'=>'TM02 synthetic P01 memory','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$row)foreach($scope as $key=>$id)$row[$key]=$id;
    unset($row);
    $input=[
        'scope'=>$scope,'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>[
            'state'=>'KNOWN','plan_version_id'=>'tm02.fixture',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0,
        ],
    ];
    $freeze=static function(array $x,?string $prior=null):array {
        $b=PointInTimeHistoricalBasis::freeze([
            'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
            'legacy_input'=>$x,'previous_basis_digest'=>$prior,
            'review_at_utc'=>'2026-03-21T00:00:00Z',
        ]);
        Assert::true($b->isOk(),'synthetic HS01 metadata basis freezes');
        if(!$b->isOk())throw new RuntimeException('TM02_INVALID_BASIS');
        $bridge=HistoryM17V2CandidateBridge::compose(
            $b->value(),'SOURCE_DIFF','MONTH');
        Assert::true($bridge->isOk(),'synthetic U04 Option A v2 composition valid');
        if(!$bridge->isOk())throw new RuntimeException('TM02_INVALID_BRIDGE');
        return ['basis'=>$b->value(),'bridge'=>$bridge->value()];
    };
    $original=$freeze($input);
    $store=MemoryStore::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'unchanged test-only M17 factory starts with disposable schema');
    if(!$store->isOk())return;
    $store=$store->value();
    $first=$store->persistP01V2Candidate($original['bridge']['frozen_m17_inputs']);
    Assert::true($first->isOk(),'typed P01 memory v2 candidate stored via existing 280');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_CANDIDATE_RECORDED',$first->value()['action'],
        'append-only v2 first run stored without rights admission');
    $memoryId=$first->value()['memory_id'];
    $runId=$first->value()['compression_run_id'];
    $dbIdentity=$db->one(
        'SELECT derivation_identity_digest,input_digest,profile_digest,engine_version
         FROM temporal_compression_runs WHERE compression_run_id=?',
        [$runId],
    );
    Assert::same($original['bridge']['m17_v2_run_digest'],
        $dbIdentity['derivation_identity_digest'],
        'stored U04-A v2 run key equals its exact typed frozen candidate digest');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links'),
        'original two primary metadata references retained in full lineage');
    $replay=$store->replayP01V2Candidate($scope,$memoryId);
    Assert::true($replay->isOk(),'versioned v2 readback verifies run, all refs and rollup');
    if(!$replay->isOk())return;
    Assert::same('MATCH_METADATA_ONLY',$replay->value()['replay']['outcome'],
        'stored v2 run rederives exact original v1 deterministic rollup');
    Assert::same(1,$replay->value()['contrary_reference_count'],
        'contrary retained in DB and replay representation');
    Assert::same(false,$replay->value()['historical_reuse_admitted'],
        'verified memory is not primary M05 or new U03 rights');
    Assert::same('EXACT_REPLAY_NO_WRITE',$store->persistP01V2Candidate(
        $original['bridge']['frozen_m17_inputs'])->value()['action'],
        'identical v2 request cannot duplicate memory/output identity');
    $absent=$store->replayP01V2Candidate($scope,$memoryId,['ref_b']);
    Assert::true($absent->isOk(),'reported unavailable owner ref yields scoped replay limit');
    Assert::same('NOT_REPLAYABLE',$absent->value()['replay']['outcome'],
        'missing input never silently reconstructed from live network or model');
    $foreign=$scope;$foreign['tenant_id']=$b['tenant_id'];
    $foreign['workspace_id']=$b['workspace_id'];
    Assert::same('TM02_V2_MEMORY_NOT_IN_SCOPE',
        $store->replayP01V2Candidate($foreign,$memoryId)->reasonCode(),
        'cross-workspace read indistinguishable from nonexistent ID');

    $late=$input;$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $successor=$freeze($late,$original['basis']['basis_digest']);
    $child=$store->persistP01V2Candidate($successor['bridge']['frozen_m17_inputs'],$memoryId);
    Assert::true($child->isOk(),'late-known selected metadata creates linked v2 successor');
    if(!$child->isOk())return;
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links
         WHERE temporal_memory_id=?',[$child->value()['memory_id']]),
        'new successor adds one ref, does not rewrite old two');
    Assert::same('SINGLE_CANDIDATE_NOT_ADMITTED',$store->headCandidates(
        $scope,'SOURCE_DIFF','MONTH','2026-03-01T00:00:00Z',
        '2026-04-01T00:00:00Z')->value()['status'],
        'one linked successor is a single unadmitted candidate head');

    $forkInput=$input;
    $r=$v['rows'][2];
    $r['ref_id']='ref_fork';
    $r['source_ref_id']='source_fork';
    $r['evidence_id']='evidence_fork';
    $r['snapshot_id']='snapshot_fork';
    $r['assessment_id']=null;
    $r['event_at']=null;$r['analyzed_at']=null;
    $r['observed_at']='2026-03-10T00:00:00Z';
    $r['recorded_at']='2026-03-10T00:00:00Z';
    $r['known_at']='2026-03-14T00:00:00Z';
    $forkInput['rows'][]=$r;
    $other=$freeze($forkInput,$original['basis']['basis_digest']);
    $otherChild=$store->persistP01V2Candidate(
        $other['bridge']['frozen_m17_inputs'],$memoryId);
    Assert::true($otherChild->isOk(),'independent same-parent successor retains a fork');
    $heads=$store->headCandidates(
        $scope,'SOURCE_DIFF','MONTH','2026-03-01T00:00:00Z',
        '2026-04-01T00:00:00Z');
    Assert::same('AMBIGUOUS_HEAD_HOLD',$heads->value()['status'],
        'two incomparable successors must not choose latest-wins');

    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),
        'original and both children are immutable distinct output identities');
    Assert::same(8,(int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links'),
        '2 old + 3 successor + 3 fork selected refs retained');
    Assert::same('M17_CANDIDATE_IDENTICAL_RUN_CONFLICT',
        $store->persistP01V2Candidate($other['bridge']['frozen_m17_inputs'],
            $otherChild->value()['memory_id'])->reasonCode(),
        'same v2 run cannot be replayed with a different predecessor');
    $legacy=$store->persistCandidate($original['bridge']['frozen_m17_inputs']);
    Assert::true($legacy->isOk(),'old v1 candidate writer remains separately replayable');
    Assert::same('TM02_V2_DERIVATION_MISMATCH',
        $store->replayP01V2Candidate($scope,$legacy->value()['memory_id'])->reasonCode(),
        'new typed v2 replay never silently rewrites an original v1 identity');

    // Attempt a genuinely distinct v2 run with an unversioned v1 parent.
    // The new input must be distinct from both previously stored v2 heads,
    // so the predecessor check executes rather than the identical-run check.
    $newInput=$late;
    $additional=$v['rows'][2];
    $additional['ref_id']='ref_new_v1_parent_attack';
    $additional['evidence_id']='evidence_new_v1_parent_attack';
    $additional['snapshot_id']='snapshot_new_v1_parent_attack';
    $additional['source_ref_id']='source_new_v1_parent_attack';
    $additional['assessment_id']=null;
    $additional['analyzed_at']=null;
    $additional['observed_at']='2026-03-09T00:00:00Z';
    $additional['recorded_at']='2026-03-09T00:00:00Z';
    $additional['known_at']='2026-03-10T00:00:00Z';
    $newInput['rows'][]=$additional;
    $withV1Parent=$freeze($newInput,$original['basis']['basis_digest']);
    $refusedParent=$store->persistP01V2Candidate(
        $withV1Parent['bridge']['frozen_m17_inputs'],
        $legacy->value()['memory_id'],
    );
    Assert::same('TM02_V2_PREDECESSOR_VERSION_MISMATCH',
        $refusedParent->reasonCode(),
        'an original v1 memory must not be silently recast as a v2 predecessor');
    Assert::same(4,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),
        'rejected v1-to-v2 successor wrote no additional output');

    try{
        $db->run('UPDATE temporal_memories SET candidate_state=? WHERE temporal_memory_id=?',
            ['ADMITTED',$memoryId]);
        Assert::fail('DB immutable memory trigger accepted UPDATE');
    }catch(\PDOException){Assert::pass();}
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '280%'"),
        'candidate 280 was never applied as a live migration');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec(
            'DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
