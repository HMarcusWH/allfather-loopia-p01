<?php

declare(strict_types=1);

/** IV-01: family-350 source-only append-only and resumable candidate proof. */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryDependencyIndex,HistoryInvalidation,HistoryInvalidationStore
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/350_history_dependencies_CANDIDATE.sql';
Assert::true(is_file($path),'family350 remains only a candidate SQL proposal');
$sql=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(9,count($sql),'three history candidate tables and six append-only triggers');
$tables=['history_invalidation_receipts','history_invalidation_events',
    'history_dependency_index_candidates'];
$installed=false;
try{
    foreach($sql as $statement)$db->pdo()->exec($statement);
    $installed=true;
    if(!defined('ALLFATHER_IV01_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_IV01_DISPOSABLE_SCHEMA_LOADED',true);
    Assert::same(6,(int)$db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'iv01_%_no_%'"),
        'all 6 new append-only invalidation triggers installed in TEST only');
    $clock=Harness::clock('2026-10-29T12:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'iv01-own');
    $foreign=Seed::tenantWorkspace($db,$clock,'iv01-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'IV01 Synthetic Evidence Graph','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $kinds=[
        'capture'=>'CAPTURE','section'=>'SECTION_DERIVATIVE',
        'assessment'=>'ASSESSMENT','basis'=>'HISTORICAL_BASIS',
        'memory'=>'TEMPORAL_MEMORY','analysis'=>'ANALYSIS',
        'finding'=>'FINDING','attention'=>'ATTENTION',
    ];
    $nodes=[];$links=[];$keys=array_keys($kinds);
    foreach($kinds as $id=>$kind)$nodes[]=[
        'node_id'=>$id,'kind'=>$kind,'owner_ref_digest'=>hash('sha256','mock-owner'.$id),
    ];
    for($i=0;$i<count($keys)-1;$i++)$links[]=[
        'parent_id'=>$keys[$i],'child_id'=>$keys[$i+1],'relation'=>'DERIVED_FROM',
    ];
    $graph=HistoryDependencyIndex::freeze([
        'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T09:00:00Z',
        'nodes'=>$nodes,'links'=>$links,
    ]);
    Assert::true($graph->isOk(),'bounded synthetic graph frozen independently');
    if(!$graph->isOk())return;
    $store=HistoryInvalidationStore::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'disposable schema test marker admits only synthetic metadata');
    if(!$store->isOk())return;
    $store=$store->value();
    $first=$store->recordGraph($graph->value());
    Assert::true($first->isOk(),'append-only graph candidate stored in disposable DB');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_GRAPH_RECORDED',$first->value()['action'],
        'first graph version is candidate, not M05 owner evidence');
    $graphId=$first->value()['history_dependency_index_id'];
    Assert::same('EXACT_GRAPH_REPLAY_NO_WRITE',
        $store->recordGraph($graph->value())->value()['action'],
        'repeating exact metadata graph never duplicates record');
    $cause=HistoryInvalidation::freezeCause([
        'cause_kind'=>'SOURCE_RIGHTS_REVOKED',
        'root_node_id'=>'capture',
        'owner_decision_ref_safe'=>'fixture_revoke',
        'owner_evidence_digest'=>str_repeat('b',64),
        'recorded_at_utc'=>'2026-10-29T10:00:00Z',
        'owner_cause_authenticated'=>false,
    ]);
    Assert::true($cause->isOk(),'source-origin cause remains explicitly unadmitted');
    $saved=$store->recordCause($scope,$graphId,$cause->value());
    Assert::true($saved->isOk(),'append-only event candidate recorded');
    if(!$saved->isOk())return;
    Assert::same('DISPOSABLE_CAUSE_RECORDED',$saved->value()['action'],
        'first cause is append-only and never deletes original evidence');
    $event=$saved->value()['history_invalidation_event_id'];
    Assert::same('EXACT_CAUSE_REPLAY_NO_WRITE',
        $store->recordCause($scope,$graphId,$cause->value())->value()['action'],
        'replayed exact source event never duplicates invalidation identity');
    $step=$store->advance($scope,$event,0,'2026-10-29T10:01:00Z',3);
    Assert::true($step->isOk(),'first batch persisted with bounded 3 object IDs');
    if(!$step->isOk())return;
    Assert::same('PROPAGATION_INCOMPLETE_HOLD',$step->value()['status'],
        'partial graph never asserts completed invalidation');
    Assert::same(3,$step->value()['next_cursor'],'first continuation cursor is explicit');
    Assert::same('EXACT_RECEIPT_REPLAY_NO_WRITE',
        $store->advance($scope,$event,0,'2026-10-29T10:01:00Z',3)->value()['action'],
        'identical cursor/tick is idempotent');
    Assert::same('IV01_CURSOR_REPLAY_CONFLICT',
        $store->advance($scope,$event,0,'2026-10-29T10:01:01Z',3)->reasonCode(),
        'same cursor with different tick is a conflict, not overwrite');
    Assert::same('IV01_RESUME_PREFIX_MISMATCH',
        $store->advance($scope,$event,7,'2026-10-29T10:02:00Z',3)->reasonCode(),
        'cannot skip unprocessed descendants through a new cursor');
    $step2=$store->advance($scope,$event,3,'2026-10-29T10:02:00Z',3);
    Assert::true($step2->isOk(),'second bounded continuation added');
    Assert::same(6,$step2->value()['next_cursor'],'second cursor advances without skipping');
    $step3=$store->advance($scope,$event,6,'2026-10-29T10:03:00Z',3);
    Assert::true($step3->isOk(),'third candidate chunk completes graph');
    Assert::same('COMPLETE_CANDIDATE_HOLD',$step3->value()['status'],
        'full synthetic propagation is still pending owner ratification');
    Assert::same(null,$step3->value()['next_cursor'],'completed candidate has no continuation');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_invalidation_receipts'),
        'exactly three immutable chunks; no duplicate writes');
    Assert::same('IV01_RESUME_PREFIX_MISMATCH',
        $store->advance($scope,$event,8,'2026-10-29T10:04:00Z',1)->reasonCode(),
        'terminal event cannot create an accidental fourth chunk');

    $outsider=$scope;
    $outsider['tenant_id']=$foreign['tenant_id'];
    $outsider['workspace_id']=$foreign['workspace_id'];
    Assert::same('IV01_GRAPH_NOT_IN_CURRENT_SCOPE',
        $store->recordCause($outsider,$graphId,$cause->value())->reasonCode(),
        'cross-tenant graph cannot be enumerated');
    Assert::same('IV01_EVENT_NOT_IN_CURRENT_SCOPE',
        $store->advance($outsider,$event,0,'2026-10-29T10:05:00Z',2)->reasonCode(),
        'cross-tenant cause cannot be read or written');
    foreach($tables as $table){
        try{$db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('candidate deletion bypassed append-only triggers');}
        catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '350%'"),
        'family350 not installed by native runtime migrator');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec(
            'DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
