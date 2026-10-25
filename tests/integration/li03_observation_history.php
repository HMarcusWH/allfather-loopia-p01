<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\ObservationHistory;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }

$clock=Harness::clock();
$scope=Seed::tenantWorkspace($db,$clock,'history-a');
$foreign=Seed::tenantWorkspace($db,$clock,'history-b');
// Tier definitions can only reference permissions already in the M02 vocabulary.
// The previous QA fixture proved that gate by refusing TIER_PERMISSION_NOT_DEFINED.
$permissionRole=Seed::role($db,$clock,'HISTORY_TEST_READER',['competition_monitoring.read','evidence.read']);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$permissionRole['role_id']);
$ent=new Entitlements($db,$clock);
$tier=$ent->defineTier('history-qa','History QA',0,['competition_monitoring.read']);
Assert::true($tier->isOk(),'test-only tier created after M02 vocabulary exists');
if (!$tier->isOk()) { return; }
Assert::true($ent->subscribe($scope['tenant_id'],$tier->value()['subscription_tier_id'])->isOk(),'test tenant subscribed');
$actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
$reader=new ObservationHistory($db,$clock,new AccessGuard($db,$clock),$ent);

$empty=$reader->listSnapshots($actor,'missing-competitor');
Assert::same('NOT_FOUND',$empty['decision'],'foreign and unknown competitor ids do not enumerate');
Assert::same('NOT_FOUND',$reader->listSnapshots($actor,'anything',0)['decision'],'page limit zero rejected');
Assert::same('NOT_FOUND',$reader->listSnapshots($actor,'anything',51)['decision'],'unbounded page request rejected');

$competitorId=\Allfather\Loopia\Core\Ids::new();
$db->insert('competitors',[
    'competitor_id'=>$competitorId,'schema_version'=>'1','tenant_id'=>$scope['tenant_id'],
    'workspace_id'=>$scope['workspace_id'],'display_name'=>'Fictional Competitor',
    'competitor_class'=>'DIRECT','status'=>'ACTIVE','created_at'=>$clock->now(),
]);
Assert::same('VISIBLE',$reader->listSnapshots($actor,$competitorId)['decision'],'authorized read of empty history');
Assert::same([],$reader->listSnapshots($actor,$competitorId)['data']['rows'],'no fake observations when there are none');

$missionChain=Seed::governedToolChain($db,$clock,$scope);
$history=Seed::p01SignalChain($db,$clock,$scope,$missionChain['mission_id'],null);
$ownCompetitor=$history['competitor_id'];
$result=$reader->listSnapshots($actor,$ownCompetitor);
Assert::same('VISIBLE',$result['decision'],'eligible competitor history returns');
Assert::same(2,count($result['data']['rows']),'two real snapshots indexed without cloning M05 evidence');
Assert::same(true,$result['data']['read_only'],'read-only remains explicit');
Assert::same(false,$result['data']['business_materiality_available'],'no business judgement fabricated');
$allRows=$result['data']['rows'];
Assert::same(1,count(array_filter($allRows,static fn(array $r):bool=>$r['coverage_state']==='BASELINE')),'baseline kept separate');
Assert::same(1,count(array_filter($allRows,static fn(array $r):bool=>$r['coverage_state']==='STRUCTURAL_CHANGE_RECORDED')),'structural change only');
foreach ($allRows as $row) {
    Assert::same('NOT_ASSESSED',$row['business_materiality'],'diff magnitude not business materiality');
    Assert::same('NOT_INFERRED',$row['release_status'],'no release from historical data');
    foreach (['source_url_safe','body','candidate','content_hash','credentials'] as $private) {
        Assert::false(array_key_exists($private,$row),"history omits {$private}");
    }
}
$part1=$reader->listSnapshots($actor,$ownCompetitor,1);
Assert::same(1,count($part1['data']['rows']),'bounded first history page');
Assert::true(is_string($part1['data']['next_cursor']),'has next cursor when more snapshots exist');
$part2=$reader->listSnapshots($actor,$ownCompetitor,1,$part1['data']['next_cursor']);
Assert::same(1,count($part2['data']['rows']),'stable second page');
Assert::same(null,$part2['data']['next_cursor'],'pagination terminates');
Assert::notSame($part1['data']['rows'][0]['source_snapshot_id'],$part2['data']['rows'][0]['source_snapshot_id'],'no repeated cursor row');

$foreignActor=['tenant_id'=>$foreign['tenant_id'],'workspace_id'=>$foreign['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$foreign['operator_id']];
Assert::same('DENIED',$reader->listSnapshots($foreignActor,$ownCompetitor)['decision'],'foreign reader cannot borrow another tenant role');
Assert::same('NOT_FOUND',$reader->listSnapshots($actor,\Allfather\Loopia\Core\Ids::new())['decision'],'unknown selector indistinguishable from foreign');

$db->run("UPDATE source_license_cards SET review_status='blocked' WHERE source_license_card_id=?",[$history['source_license_card_id']]);
$withdrawn=$reader->listSnapshots($actor,$ownCompetitor);
Assert::same([],$withdrawn['data']['rows'],'withdrawn source hides existing historical rows');
Assert::same('NOT_FOUND',$reader->listSnapshots($actor,$ownCompetitor,1,$part1['data']['next_cursor'])['decision'],'revocation invalidates old cursor');
$db->run("UPDATE source_license_cards SET review_status='approved' WHERE source_license_card_id=?",[$history['source_license_card_id']]);

$db->run("UPDATE source_references SET status='REVOKED' WHERE source_ref_id=?",[$history['source_ref_id']]);
Assert::same([],$reader->listSnapshots($actor,$ownCompetitor)['data']['rows'],'M05 source revocation blocks reads');

Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id=?',[$history['monitored_source_id']]),'all raw snapshot history remains untouched');
