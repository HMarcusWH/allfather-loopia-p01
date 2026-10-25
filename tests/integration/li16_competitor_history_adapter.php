<?php

declare(strict_types=1);

use Allfather\Loopia\Http\ReadAdapters\CompetitorHistoryAdapter;
use Allfather\Loopia\Product\P01\ObservationHistory;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$clock=Harness::clock();
$a=Seed::tenantWorkspace($db,$clock,'li16-tenant-A');
$b=Seed::tenantWorkspace($db,$clock,'li16-tenant-B');

$role=Seed::role($db,$clock,'LI16_READ_ONLY',['competition_monitoring.read','evidence.read']);
Seed::membership($db,$clock,$a['tenant_id'],$a['workspace_id'],$a['operator_id'],$role['role_id']);
$ent=new Entitlements($db,$clock);
$tier=$ent->defineTier('li16-qa','History operator preview (test only)',0,['competition_monitoring.read']);
Assert::true($tier->isOk(),'history test-only commercial tier defined');
if (!$tier->isOk()) return;
Assert::true($ent->subscribe($a['tenant_id'],$tier->value()['subscription_tier_id'])->isOk(),'test tenant tier admits P01 read');
$owner=new ObservationHistory($db,$clock,new AccessGuard($db,$clock),$ent);
$admitted=[];
$adapter=new CompetitorHistoryAdapter(static function (array $scope,string $competitorId,int $limit,?string $cursor) use ($owner,&$admitted):array {
    $admitted[]=['scope'=>$scope,'competitor'=>$competitorId,'limit'=>$limit,'cursor'=>$cursor];
    return $owner->listSnapshots($scope,$competitorId,$limit,$cursor);
});
$sessionA=['tenant_id'=>$a['tenant_id'],'operator_id'=>$a['operator_id'],'active_workspace_id'=>$a['workspace_id']];
$sessionB=['tenant_id'=>$b['tenant_id'],'operator_id'=>$b['operator_id'],'active_workspace_id'=>$b['workspace_id']];
$sessionA['workspace_id']='FOREIGN_QUERY_IGNORED';
Assert::same('NO_ACTIVE_WORKSPACE',$adapter->history(null,Ids::new())['decision'],'no session never consults owner');
Assert::same('NOT_FOUND',$adapter->history($sessionA,'not-a-uuid')['decision'],'malformed selector never reaches database');

$chain=Seed::governedToolChain($db,$clock,$a);
$proof=Seed::p01SignalChain($db,$clock,$a,$chain['mission_id'],null);
$competitor=$proof['competitor_id'];
$before=(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id=?',[$proof['monitored_source_id']]);
$first=$adapter->history($sessionA,$competitor,1);
Assert::same('VISIBLE',$first['decision'],'real owner joins M05/P01 captured history');
Assert::same(1,count($first['data']['rows']),'first page contains one authorized capture');
Assert::true(is_string($first['data']['next_cursor']),'there is a second page cursor');
Assert::same('NOT_ASSESSED',$first['data']['rows'][0]['business_materiality'],'no false commercial assessment');
Assert::same('NOT_INFERRED',$first['data']['rows'][0]['release_status'],'no false product release');
Assert::same('li16.competitor-history-metadata.v1',$first['data']['projection_version'],'closed presentation version');
Assert::same($a['workspace_id'],$admitted[count($admitted)-1]['scope']['workspace_id'],'attacker workspace field ignored');
$second=$adapter->history($sessionA,$competitor,1,$first['data']['next_cursor']);
Assert::same('VISIBLE',$second['decision'],'next history page is still owner authorized');
Assert::same(1,count($second['data']['rows']),'second page contains remaining captured source');
Assert::same(null,$second['data']['next_cursor'],'cursor ends at exhausted source history');
Assert::notSame($first['data']['rows'][0]['source_snapshot_id'],$second['data']['rows'][0]['source_snapshot_id'],'no duplicate between pages');
Assert::same('DENIED',$adapter->history($sessionB,$competitor)['decision'],'foreign tenant without permission denied before any data');
Assert::same('NOT_FOUND',$adapter->history($sessionA,Ids::new())['decision'],'unknown competitor names no foreign identity');

$db->run("UPDATE source_license_cards SET review_status='blocked' WHERE source_license_card_id=?",[$proof['source_license_card_id']]);
$revoked=$adapter->history($sessionA,$competitor,20);
Assert::same('VISIBLE',$revoked['decision'],'owner still permits restricted query, but rechecks source cards');
Assert::same([],$revoked['data']['rows'],'withdrawn source leaves no displayed captures');
Assert::same('NOT_FOUND',$adapter->history($sessionA,$competitor,1,$first['data']['next_cursor'])['decision'],'old cursor invalidated by rights withdrawal');

$db->run("UPDATE source_license_cards SET review_status='approved' WHERE source_license_card_id=?",[$proof['source_license_card_id']]);
$db->run("UPDATE source_references SET status='REVOKED' WHERE source_ref_id=?",[$proof['source_ref_id']]);
Assert::same([],$adapter->history($sessionA,$competitor)['data']['rows'],'M05 revocation withholds history');
Assert::same($before,(int)$db->scalar('SELECT COUNT(*) FROM source_snapshots WHERE monitored_source_id=?',[$proof['monitored_source_id']]),'no capture rows added/modified by read model');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),'history reads never spend provider tokens');
