<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M17\HistoricalCaptureProbe;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$clock=Harness::clock('2026-10-26T18:00:00.000Z');
$a=Seed::tenantWorkspace($db,$clock,'m17-source-a');
$b=Seed::tenantWorkspace($db,$clock,'m17-source-b');
$role=Seed::role($db,$clock,'m17-history-read',['competition_monitoring.read','evidence.read']);
Seed::membership($db,$clock,$a['tenant_id'],$a['workspace_id'],$a['operator_id'],$role['role_id']);
Seed::membership($db,$clock,$b['tenant_id'],$b['workspace_id'],$b['operator_id'],$role['role_id']);

$root=sys_get_temp_dir().'/m17-history-'.bin2hex(random_bytes(6));
$probe=new HistoricalCaptureProbe($db,$clock,new EvidenceStore($root));
$scope=['actor_type'=>'OPERATOR','actor_id'=>$a['operator_id'],
    'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id']];
$foreignScope=['actor_type'=>'OPERATOR','actor_id'=>$b['operator_id'],
    'tenant_id'=>$b['tenant_id'],'workspace_id'=>$b['workspace_id']];
$competitor=Ids::new();
$snapshot=Ids::new();
$at='2026-10-26T18:00:00.000Z';

Assert::same('M17_HISTORICAL_OWNER_REF_UNAVAILABLE',
    $probe->inspect($scope,$competitor,$snapshot,$at)->reasonCode(),
    'authorized actor cannot invent an M05 owner ref from an arbitrary UUID');
Assert::same('M17_HISTORICAL_OWNER_REF_UNAVAILABLE',
    $probe->inspect($foreignScope,$competitor,$snapshot,$at)->reasonCode(),
    'foreign scope does not reveal owner ref existence');
$bad=$scope;$bad['actor_id']=Ids::new();
Assert::same('M17_HISTORICAL_SCOPE_DENIED',
    $probe->inspect($bad,$competitor,$snapshot,$at)->reasonCode(),
    'unregistered actor refused before owner read');
$bad=$scope;$bad['actor_type']='MODEL';
Assert::same('M17_HISTORICAL_ACTOR_SCOPE_INVALID',
    $probe->inspect($bad,$competitor,$snapshot,$at)->reasonCode(),
    'unrecognized principal may not become historical consumer');
$bad=$scope;$bad['unreviewed_grant']='YES';
Assert::same('M17_HISTORICAL_ACTOR_SCOPE_INVALID',
    $probe->inspect($bad,$competitor,$snapshot,$at)->reasonCode(),
    'extra asserted grant cannot expand scope');
Assert::same('M17_HISTORICAL_QUERY_INVALID',
    $probe->inspect($scope,$competitor,$snapshot,'2026-10-26T18:00:00Z')->reasonCode(),
    'unknown timestamp serialization refused');
Assert::same('M17_HISTORICAL_QUERY_INVALID',
    $probe->inspect($scope,$competitor,'', $at)->reasonCode(),
    'missing owner-native snapshot id refused');
Assert::same('M17_HISTORICAL_FUTURE_CUTOFF',
    $probe->inspect($scope,$competitor,$snapshot,'2026-10-27T18:00:00.000Z')->reasonCode(),
    'future knowledge cutoff cannot be selected');

$reflection=new ReflectionMethod(HistoricalCaptureProbe::class,'inspect');
Assert::same(4,$reflection->getNumberOfRequiredParameters(),
    'consumer cannot skip principal/competitor/snapshot/knowledge cutoff');
Assert::same('li05b.native-history-preflight-candidate.v1',HistoricalCaptureProbe::VERSION,
    'preflight is a versioned candidate and not a retroactive authorization');
