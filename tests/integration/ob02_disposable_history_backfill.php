<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Product\P01\{Entitlements,ObservationHistory,ObservationAssessmentStore};
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

/** Positive U01/U02-INERT QA against disposable MariaDB only. */
$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$path=$root.'/docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql';
Assert::true(is_file($path),'260 schema is proposal outside runtime migrator');
$statements=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(9,count($statements),'three candidate tables and six immutable triggers are parsed');

$tables=['p01_observation_backfill_ledger','p01_observation_assessment_refs','p01_observation_assessments'];
$installed=false;
try{
    foreach($statements as $stmt)$db->pdo()->exec($stmt);
    $installed=true;
    if(!defined('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED',true);
    }
    Assert::same(6,(int)$db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'ob02_%_no_%'"
    ),'all six candidate append-only triggers installed');

    $clock=Harness::clock('2026-10-28T21:00:00.000Z');
    $scope=Seed::tenantWorkspace($db,$clock,'ob02-scope');
    $other=Seed::tenantWorkspace($db,$clock,'ob02-foreign');
    $role=Seed::role($db,$clock,'OB02_FIXTURE_READER',['competition_monitoring.read','evidence.read']);
    Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
    $ent=new Entitlements($db,$clock);
    $tier=$ent->defineTier('ob02-fixture-only','OB02 Fixture Reader',0,['competition_monitoring.read']);
    Assert::true($tier->isOk(),'existing entitlement tier from M02 permission vocabulary');
    if(!$tier->isOk())return;
    Assert::true($ent->subscribe($scope['tenant_id'],$tier->value()['subscription_tier_id'])->isOk(),
        'fixture tenant has live tier read permission');

    $actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
        'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
    $foreign=['tenant_id'=>$other['tenant_id'],'workspace_id'=>$other['workspace_id'],
        'actor_type'=>'OPERATOR','actor_id'=>$other['operator_id']];
    $reader=new ObservationHistory($db,$clock,new AccessGuard($db,$clock),$ent);
    $chain=Seed::governedToolChain($db,$clock,$scope);
    $existing=Seed::p01SignalChain($db,$clock,$scope,$chain['mission_id'],null);
    $competitorId=$existing['competitor_id'];

    $before=[
        'snapshots'=>(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots'),
        'diffs'=>(int)$db->scalar('SELECT COUNT(*) FROM source_diffs'),
        'signals'=>(int)$db->scalar('SELECT COUNT(*) FROM company_signals'),
        'provider'=>(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    ];
    $preview=$reader->previewAssessmentBackfill($actor,$competitorId,1);
    Assert::true($preview->isOk(),'existing P01 history owner grants current eligible preview');
    if(!$preview->isOk())return;
    $page=$preview->value();
    Assert::same(true,$page['preview_only'],'new history is read-only by default');
    Assert::same(false,$page['native_persistent_apply_authorized'],'read permission does not confer assessment write');
    Assert::same(1,$page['record_count'],'bounded cursor page returns one history metadata link');
    Assert::true(is_string($page['next_cursor']),'two native snapshots result in an opaque next cursor');
    Assert::same('NOT_ASSESSED',$page['records'][0]['assessment_status'],
        'old price signal does not become fabricated v2 business assessment');
    Assert::same('NOT_RECORDED_IN_LEGACY_P01',$page['question_identity'],
        'missing historical question is not synthesized');
    Assert::same('CURRENT_FILTERED_READ_ONLY',$page['records'][0]['source_use_readback'],
        'provenance follows current source history read without minting new model rights');

    $writer=ObservationAssessmentStore::forDisposableTest($db,$clock,$reader);
    Assert::true($writer->isOk(),'disposable-only candidate schema explicitly selected for QA');
    if(!$writer->isOk())return;
    $store=$writer->value();

    $first=$store->applyFixturePage($actor,$competitorId,1);
    Assert::true($first->isOk(),'first bounded cursor page writes only candidate metadata');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_METADATA_LINKS_RECORDED',$first->value()['action'],'page-one positive insert');
    Assert::same(1,$first->value()['new_assessments'],'one legacy metadata link, not one historical finding');
    Assert::same($page['records'][0]['reference_count'],$first->value()['new_refs'],
        'first cursor page records precisely its original M05/P01 refs, independent of capture UUID tie order');
    Assert::same(false,$first->value()['historical_assessments_created'],
        'append is metadata only, no model candidate reconstructed');
    $again=$store->applyFixturePage($actor,$competitorId,1);
    Assert::true($again->isOk(),'same page can be replayed exactly');
    Assert::same('EXACT_PAGE_REPLAY_NO_WRITE',$again->value()['action'],'idempotency ledger prevents duplicate records');
    Assert::same(0,$again->value()['new_assessments'],'replay produces zero new candidate rows');
    Assert::same($first->value()['ledger_id'],$again->value()['ledger_id'],'same immutable ledger identity');

    $second=$store->applyFixturePage($actor,$competitorId,1,$page['next_cursor']);
    Assert::true($second->isOk(),'second page admits previous baseline metadata link');
    if(!$second->isOk())return;
    Assert::same(1,$second->value()['new_assessments'],'second capture exactly once');
    Assert::same(3-$page['records'][0]['reference_count'],$second->value()['new_refs'],
        'second cursor page retains remaining references regardless of equal captured-at UUID ordering');
    Assert::same(null,$second->value()['next_cursor'],'cursor terminates correctly');

    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_assessments'),
        'exactly two metadata links, zero invented model assessments');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_assessment_refs'),
        'all three exact evidence and structural diff links retained');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_backfill_ledger'),
        'bounded cursor pages have distinct immutable receipts');
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM p01_observation_assessments
         WHERE assessment_status<>'NOT_ASSESSED' OR business_significance<>'NOT_ASSESSED'
            OR known_at<source_captured_at OR human_annotation_id IS NOT NULL OR run_id IS NOT NULL"
    ),'no backdating, inference, annotation or invented historical model run');

    $id=(string)$db->scalar('SELECT p01_observation_assessment_id FROM p01_observation_assessments
        WHERE tenant_id=? AND workspace_id=? AND competitor_id=? AND source_snapshot_id=?',
        [$scope['tenant_id'],$scope['workspace_id'],$competitorId,$existing['after_snapshot_id']]);
    Assert::true(Ids::isValid($id),'stored assessment candidate gets distinct owner-scoped UUID');
    $replay=$store->replayMetadata($actor,$competitorId,$id);
    Assert::true($replay->isOk(),'candidate ref graph replays under current P01 source rights');
    if($replay->isOk()){
        Assert::same('MATCH_METADATA_ONLY',$replay->value()['integrity'],'replay proves metadata, not model truth');
        Assert::same(false,$replay->value()['source_rights_for_new_model_use'],
            'read of prior metadata is not new source reuse permission');
    }
    Assert::same('OB02_HISTORY_NOT_AVAILABLE',
        $store->replayMetadata($foreign,$competitorId,$id)->reasonCode(),
        'foreign tenant cannot enumerate history or assessment via opaque ID');
    Assert::same('OB02_OWNER_HISTORY_NOT_AVAILABLE',
        $reader->previewAssessmentBackfill($foreign,$competitorId,1)->reasonCode(),
        'new preview cannot borrow foreign M02 membership');
    Assert::same('OB02_BACKFILL_SELECTOR_INVALID',
        $reader->previewAssessmentBackfill($actor,$competitorId,21)->reasonCode(),
        'unbounded cursor pages refused');

    foreach([
        ['UPDATE p01_observation_assessments SET candidate_state=? WHERE p01_observation_assessment_id=?',
            ['PROMOTED',$id]],
        ['DELETE FROM p01_observation_assessment_refs WHERE p01_observation_assessment_id=?',
            [$id]],
        ['DELETE FROM p01_observation_backfill_ledger WHERE competitor_id=?',[$competitorId]],
    ] as [$sql,$args]){
        try{$db->run($sql,$args);Assert::fail('append-only trigger did not refuse UPDATE/DELETE');}
        catch(\PDOException){Assert::pass();}
    }
    $after=[
        'snapshots'=>(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots'),
        'diffs'=>(int)$db->scalar('SELECT COUNT(*) FROM source_diffs'),
        'signals'=>(int)$db->scalar('SELECT COUNT(*) FROM company_signals'),
        'provider'=>(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    ];
    Assert::same($before,$after,'legacy snapshots, diffs, signals and provider calls unchanged');

    $db->run("UPDATE source_license_cards SET review_status='blocked'
             WHERE source_license_card_id=?",[$existing['source_license_card_id']]);
    $noRights=$reader->previewAssessmentBackfill($actor,$competitorId,1);
    Assert::true($noRights->isOk(),'owner may return an empty preview after revocation');
    Assert::same(0,$noRights->value()['record_count'],'revoked source rows invisible');
    Assert::same('OB02_CURRENT_SOURCE_RIGHTS_NOT_PROVEN',
        $store->replayMetadata($actor,$competitorId,$id)->reasonCode(),
        'replay rights are rechecked and held after card revocation');
    $applyRevoked=$store->applyFixturePage($actor,$competitorId,1);
    Assert::true($applyRevoked->isRefusal(),'re-applying old cursor after revocation cannot rewrite prior links');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM p01_observation_assessments'),
        'revocation leaves prior append-only candidate metadata untouched');
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '260_%'"
    ),'family 260 never entered the production migration ledger');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
