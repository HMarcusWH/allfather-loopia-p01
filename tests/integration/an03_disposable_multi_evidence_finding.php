<?php

declare(strict_types=1);

/**
 * AN-03: family330 append-only multi-evidence candidate bindings.
 * Disposable MariaDB only, no source fetch, M10 mutation or recipient.
 */
require_once dirname(__DIR__).'/support/An03MockFinding.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Tests\{Assert,Harness,Seed,An03MockFinding};
use Allfather\Loopia\Workflow\{HistoricalFindingStore,HistoryReviewRelease};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/330_historical_finding_bindings_CANDIDATE.sql';
$sql=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(9,count($sql),'three proposed family330 tables and six append-only triggers');
$tables=[
    'historical_finding_review_links','historical_finding_basis_bindings',
    'historical_finding_candidates',
];
$installed=false;
try{
    foreach($sql as $stmt)$db->pdo()->exec($stmt);
    $installed=true;
    if(!defined('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED',true);
    }
    $clock=Harness::clock('2026-10-29T12:05:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'an03-owner');
    $foreign=Seed::tenantWorkspace($db,$clock,'an03-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,
        'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],
        'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AN03 test-only multi evidence',
        'competitor_class'=>'DIRECT',
        'status'=>'ACTIVE',
        'created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],
        'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,
        'question_id'=>Ids::new(),
    ];
    $in=An03MockFinding::inputs($scope);
    Assert::true($in->isOk(),'exact owner-scope synthetic fixture exists');
    if(!$in->isOk())return;
    $finding=HistoricalFindingAdapter::propose($in->value());
    Assert::true($finding->isOk(),'two-source bundle plus reviewer chain creates provisional finding');
    if(!$finding->isOk())return;
    $store=HistoricalFindingStore::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'CLI/disposable factory after proposed SQL installed');
    if(!$store->isOk())return;
    $store=$store->value();
    $first=$store->persistCandidate($finding->value());
    Assert::true($first->isOk(),'source-only candidate appended under scoped native tenant+competitor');
    if(!$first->isOk())return;
    $id=$first->value()['historical_finding_candidate_id'];
    Assert::same('DISPOSABLE_FINDING_CANDIDATE_RECORDED',$first->value()['action'],
        'candidate record is not an M10 release');
    Assert::same(2,$first->value()['bound_primary_ref_count'],
        'both primary reference rows stored, not arbitrary single diff');
    Assert::same(false,$first->value()['native_m10_release_authorized'],
        'writing SQL never grants any customer release authority');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM historical_finding_basis_bindings'),
        'two independent source records are stored with distinct ordinal/ID keys');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM historical_finding_review_links'),
        'one exact analyst-critic chain linked, no fake human review');
    $replayed=$store->replayCandidate($scope,$id);
    Assert::true($replayed->isOk(),'readback checks all source refs and reviewer digests');
    if(!$replayed->isOk())return;
    Assert::same('PROVISIONAL_MULTI_REFERENCE_HUMAN_REVIEW_HOLD',
        $replayed->value()['status'],
        'replay is still a human-HOLD, not a released company signal');
    Assert::same(1,$replayed->value()['contrary_ref_count'],
        'contrary source survives the complete database round trip');
    Assert::same(false,$replayed->value()['human_delivery_approved'],
        'dual human review remains unresolved');
    Assert::same('EXACT_FINDING_REPLAY_NO_WRITE',
        $store->persistCandidate($finding->value())->value()['action'],
        'identical retries do not duplicate work, reviewer or sources');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM historical_finding_candidates'),
        'exact finding idempotency persists only one candidate');
    $foreignScope=$scope;
    $foreignScope['tenant_id']=$foreign['tenant_id'];
    $foreignScope['workspace_id']=$foreign['workspace_id'];
    Assert::same('AN03_FINDING_NOT_IN_CURRENT_SCOPE',
        $store->replayCandidate($foreignScope,$id)->reasonCode(),
        'foreign workspace cannot read any metadata of the finding');
    $wrongQuestion=$scope;$wrongQuestion['question_id']='unbound_question';
    Assert::same('AN03_FINDING_NOT_IN_CURRENT_SCOPE',
        $store->replayCandidate($wrongQuestion,$id)->reasonCode(),
        'same actor but different historical question cannot read a finding');

    // Even if a caller produces a syntactically valid release-looking claim,
    // this package refuses every possible M10/native action.
    Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
        HistoryReviewRelease::release($finding->value(),
            ['policy_verdict'=>'ALLOW_INFORMATIONAL_RELEASE'])->reasonCode(),
        'no fake multi-evidence M10 release owner exists');
    foreach($tables as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('append-only family330 accepted destructive delete');
        }catch(\PDOException){
            Assert::pass();
        }
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '330%'"),
        'family330 did not enter native migration application ledger');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
