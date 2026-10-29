<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob03Fixture.php';

use Allfather\Loopia\Core\{Digest,Ids};
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Product\P01\{
    Entitlements,ObservationHistory,ObservationAssessmentStore,
    ObservationFeatureCandidateStore,SourceOriginAssertionReader,
    ObservationFeatureSet
};
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Tests\{Assert,Harness,Seed,Ob03Fixture};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$files=[
    $root.'/docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql',
    $root.'/docs/longitudinal/proposals/265_observation_features_CANDIDATE.sql',
];
$statements=[
    Migrator::splitStatements((string)file_get_contents($files[0])),
    Migrator::splitStatements((string)file_get_contents($files[1])),
];
Assert::same(9,count($statements[0]),'OB02 family260 three candidate tables plus six triggers');
Assert::same(6,count($statements[1]),'OB03 family265 two candidate tables plus four triggers');
$tables=[
    'p01_source_origin_assertions',
    'p01_observation_features',
    'p01_observation_backfill_ledger',
    'p01_observation_assessment_refs',
    'p01_observation_assessments',
];
$installed=false;
try{
    foreach($statements as $set)foreach($set as $sql)$db->pdo()->exec($sql);
    $installed=true;
    if(!defined('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED',true);
    if(!defined('ALLFATHER_OB03_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_OB03_DISPOSABLE_SCHEMA_LOADED',true);
    Assert::same(4,(int)$db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'ob03_%_no_%'"
    ),'OB03 4 DB-level append-only triggers active in disposable MariaDB');

    $clock=Harness::clock('2026-10-29T08:00:00.000Z');
    $scope=Seed::tenantWorkspace($db,$clock,'ob03-a');
    $other=Seed::tenantWorkspace($db,$clock,'ob03-b');
    $role=Seed::role($db,$clock,'OB03_SOURCE_READER',['competition_monitoring.read','evidence.read']);
    Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
    $ent=new Entitlements($db,$clock);
    $tier=$ent->defineTier('ob03-fixture-only','OB03 Fixture Origin Reader',0,['competition_monitoring.read']);
    Assert::true($tier->isOk(),'native M02 vocabulary permits synthetic fixture role');
    if(!$tier->isOk())return;
    Assert::true($ent->subscribe($scope['tenant_id'],$tier->value()['subscription_tier_id'])->isOk(),
        'test tenant receives only existing read entitlement');
    $actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
        'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
    $foreign=['tenant_id'=>$other['tenant_id'],'workspace_id'=>$other['workspace_id'],
        'actor_type'=>'OPERATOR','actor_id'=>$other['operator_id']];

    $mission=Seed::governedToolChain($db,$clock,$scope);
    $chain=Seed::p01SignalChain($db,$clock,$scope,$mission['mission_id'],null);
    $competitorId=$chain['competitor_id'];
    $history=new ObservationHistory($db,$clock,new AccessGuard($db,$clock),$ent);
    $origin=new SourceOriginAssertionReader($db,$history);
    $current=$origin->inspect($actor,$competitorId,[
        $chain['after_snapshot_id'],$chain['before_snapshot_id'],
    ]);
    Assert::true($current->isOk(),'REAL M02/P01/M05 owner read validates two seeded snapshots'
        . ($current->isOk()?'':': '.$current->reasonCode()));
    if(!$current->isOk())return;
    $read=$current->value();
    Assert::same(2,$read['observed_captures'],'both actual P01/M05 snapshots read');
    Assert::same(1,$read['source_reference_count'],'same source ref, no invented second publisher');
    Assert::same(true,$read['current_owner_read_verified'],'current P01 card eligibility actually checked');
    Assert::same(null,$read['independent_origin_count'],'current M05 registration does not imply independence');
    Assert::same(false,$read['origin_independence_certified'],'U02 remains pending');
    Assert::same(false,$read['source_use_for_new_analysis_admitted'],'read != historical source reuse');

    $countsBefore=[
        'snapshots'=>(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots'),
        'diffs'=>(int)$db->scalar('SELECT COUNT(*) FROM source_diffs'),
        'signals'=>(int)$db->scalar('SELECT COUNT(*) FROM company_signals'),
        'provider'=>(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    ];
    $ob02=ObservationAssessmentStore::forDisposableTest($db,$clock,$history);
    Assert::true($ob02->isOk(),'predecessor 260 store explicitly disposable only');
    if(!$ob02->isOk())return;
    $saved=$ob02->value()->applyFixturePage($actor,$competitorId,2);
    Assert::true($saved->isOk(),'two existing P01 capture metadata records stored into disposable 260');
    if(!$saved->isOk())return;
    Assert::same(2,$saved->value()['new_assessments'],'both original captures linked as metadata');

    $row=$db->one(
        'SELECT p01_observation_assessment_id,record_json FROM p01_observation_assessments
         WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
           AND source_snapshot_id=? AND record_kind=?',
        [$scope['tenant_id'],$scope['workspace_id'],$competitorId,
         $chain['after_snapshot_id'],'LEGACY_METADATA_ONLY'],
    );
    Assert::true($row!==null,'after-capture assessment candidate exists in temporary 260');
    if($row===null)return;
    $assessment=json_decode($row['record_json'],true,64,JSON_THROW_ON_ERROR);
    $beforeEv=$db->one('SELECT evidence_id FROM source_snapshots WHERE source_snapshot_id=?',
        [$chain['before_snapshot_id']]);
    Assert::true($beforeEv!==null,'actual before evidence reference still retained by P01');
    if($beforeEv===null)return;

    // This CR-02 page projection is a separate synthetic HTML fixture, NOT
    // M05 primary source bytes. The persisted feature candidate records only
    // digests and never claims to authenticate those synthetic HTML bytes.
    $built=Ob03Fixture::positive('price-one-digit',$assessment,
        $chain['before_snapshot_id'],$beforeEv['evidence_id'],2);
    $feature=$built['result'];
    Assert::true(ObservationFeatureSet::verify($feature),
        'composed candidate retains the exact OB02 metadata and CR02 SHA');
    Assert::same('PRICE_CHANGED',$feature['feature_rows'][0]['change_kind'],
        'one-digit price change retained as descriptive candidate not economic fact');
    Assert::same(null,$feature['independent_corroboration_count'],
        'second synthetic mirror cannot become a corroborating independent witness');

    $factory=ObservationFeatureCandidateStore::forDisposableTest($db,$clock,$history);
    Assert::true($factory->isOk(),'only temporary schema+test workspace enables metadata insertion');
    if(!$factory->isOk())return;
    $store=$factory->value();
    $first=$store->recordFixture($actor,$competitorId,
        $row['p01_observation_assessment_id'],$feature);
    Assert::true($first->isOk(),'positive synthetic derived references stored in candidate 265'
        . ($first->isOk()?'':': '.$first->reasonCode()));
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_METADATA_REFERENCES_RECORDED',$first->value()['action'],
        'first call inserts feature + one verified M05 origin-reference candidate');
    Assert::same(1,$first->value()['features_inserted'],'one immutable feature candidate');
    Assert::same(1,$first->value()['origin_assertions_inserted'],'one source reference not two origins');
    Assert::same(null,$first->value()['independent_corroboration_count'],
        'even a stored candidate cannot certify independence');

    $again=$store->recordFixture($actor,$competitorId,
        $row['p01_observation_assessment_id'],$feature);
    Assert::true($again->isOk(),'exact repeated feature can be safely replayed');
    Assert::same('EXACT_REPLAY_NO_WRITE',$again->value()['action'],
        'metadata-only replay cannot inflate source/counts');
    Assert::same(0,$again->value()['features_inserted'],'idempotency write count stays zero');
    Assert::same($first->value()['feature_id'],$again->value()['feature_id'],
        'same immutable candidate id on replay');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_features'),
        'one derived feature row not duplicated');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM p01_source_origin_assertions'),
        'one M05 source reference candidate not a second publisher');
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM p01_observation_features
         WHERE event_count_state<>'UNKNOWN' OR commercial_materiality<>'NOT_ASSESSED'"
    ),'no commercial materiality or independent event count fabricated');

    $changed=$feature;
    $changed['byte_class_summary']['capture_count']=199;
    unset($changed['feature_digest']);
    $changed['feature_digest']=Digest::canonicalDigest($changed);
    Assert::true(ObservationFeatureSet::verify($changed),
        'counterfactual changed candidate remains hash-consistent but unverified by owner');
    $conflict=$store->recordFixture($actor,$competitorId,
        $row['p01_observation_assessment_id'],$changed);
    Assert::same('OB03_IMMUTABLE_FEATURE_CONFLICT',$conflict->reasonCode(),
        'changed same snapshot must never overwrite old derived assertion');

    Assert::same('OB03_ORIGIN_OWNER_HISTORY_DENIED',
        $origin->inspect($foreign,$competitorId,[$chain['after_snapshot_id']])->reasonCode(),
        'foreign actor cannot enumerate source/evidence identities');
    Assert::same('OB03_CANDIDATE_SCOPE_MISMATCH',
        $store->recordFixture($foreign,$competitorId,$row['p01_observation_assessment_id'],$feature)
            ->reasonCode(),'foreign tenant cannot save someone else source assertion');

    foreach([
        ['UPDATE p01_observation_features SET candidate_state=? WHERE p01_observation_feature_id=?',
            ['LIVE',$first->value()['feature_id']]],
        ['DELETE FROM p01_observation_features WHERE p01_observation_feature_id=?',
            [$first->value()['feature_id']]],
        ['UPDATE p01_source_origin_assertions SET origin_identity=? WHERE source_snapshot_id=?',
            ['INDEPENDENT',$chain['after_snapshot_id']]],
        ['DELETE FROM p01_source_origin_assertions WHERE source_snapshot_id=?',
            [$chain['after_snapshot_id']]],
    ] as [$sql,$args]){
        try{$db->run($sql,$args);Assert::fail('DB append-only origin or feature trigger bypassed');}
        catch(\PDOException){Assert::pass();}
    }
    $countsAfter=[
        'snapshots'=>(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots'),
        'diffs'=>(int)$db->scalar('SELECT COUNT(*) FROM source_diffs'),
        'signals'=>(int)$db->scalar('SELECT COUNT(*) FROM company_signals'),
        'provider'=>(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    ];
    Assert::same($countsBefore,$countsAfter,
        'underlying M05/P01 snapshots, diffs, signals and provider calls unchanged');

    $db->run("UPDATE source_license_cards SET review_status='blocked'
             WHERE source_license_card_id=?",[$chain['source_license_card_id']]);
    Assert::same('OB03_ORIGIN_NOT_IN_CURRENT_OWNER_WINDOW',
        $origin->inspect($actor,$competitorId,[$chain['after_snapshot_id']])->reasonCode(),
        'revoked M05 source-card removes snapshot from current eligible history');
    $revoked=$store->recordFixture($actor,$competitorId,
        $row['p01_observation_assessment_id'],$feature);
    Assert::true(!$revoked->isOk(),
        'source revocation blocks metadata replay/addition despite old candidate row');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_features'),
        'revocation does not rewrite immutable synthetic candidate history');
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '265_%'"
    ),'U02/U03 family265 was never installed through runtime migration');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
