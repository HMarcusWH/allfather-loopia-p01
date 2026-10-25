<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\ReadAdapters\CompetitorHistoryAdapter;
use Allfather\Loopia\Tests\Assert;

$cid=Ids::new(); $sid=Ids::new(); $source=Ids::new(); $diff=Ids::new();
$tenant=Ids::new(); $workspace=Ids::new(); $operator=Ids::new();
$session=['tenant_id'=>$tenant,'active_workspace_id'=>$workspace,'operator_id'=>$operator,
    'tenant_id_query_untrusted'=>'ignored','active_scope_override'=>'ignored'];
$owner=[
    'decision'=>'VISIBLE','required_permission'=>'evidence.read','reason_codes'=>['GRANTED'],
    'data'=>[
        'competitor'=>['competitor_id'=>$cid,'display_name'=>'Fictional Competitor','status'=>'ACTIVE',
            'billing_plan'=>'PRIVATE'],
        'rows'=>[[
            'source_snapshot_id'=>$sid,'monitored_source_id'=>$source,'source_kind'=>'OFFICIAL_SITE',
            'snapshot_sequence'=>2,'captured_at'=>'2026-10-25 12:00:00',
            'coverage_state'=>'STRUCTURAL_CHANGE_RECORDED',
            'comparisons'=>[[
                'source_diff_id'=>$diff,'structural_significance'=>'MINOR',
                'detected_at'=>'2026-10-25 12:00:01','raw_text'=>'DO_NOT_RETURN',
            ]],
            'business_materiality'=>'NOT_ASSESSED',
            'observation_assessment'=>'NOT_IMPLEMENTED','release_status'=>'NOT_INFERRED',
            'candidate_text'=>'DO_NOT_RETURN','source_url_safe'=>'https://test.invalid',
            'credentials'=>'DO_NOT_RETURN',
        ]],
        'next_cursor'=>null,'page_size'=>20,'source_rights'=>'CURRENT_ELIGIBILITY_CHECKED',
        'business_materiality_available'=>false,'read_only'=>true,
        'internal_provider_status'=>'DO_NOT_RETURN',
    ]
];
$calls=[];
$read=static function (array $scope,string $id,int $limit,?string $cursor) use (&$calls,&$owner):array {
    $calls[]=['scope'=>$scope,'id'=>$id,'limit'=>$limit,'cursor'=>$cursor];
    return $owner;
};
$adapter=new CompetitorHistoryAdapter($read);

Assert::same('NO_ACTIVE_WORKSPACE',$adapter->history(null,$cid)['decision'],'anonymous session cannot inspect history');
Assert::same('NO_ACTIVE_WORKSPACE',$adapter->history(['tenant_id'=>$tenant],$cid)['decision'],'missing workspace/actor cannot inspect');
Assert::same('NOT_FOUND',$adapter->history($session,'foreign-competitor')['decision'],'malformed competitor selector is not enumerable');
Assert::same('NOT_FOUND',$adapter->history($session,$cid,0)['decision'],'page size zero refused before any owner call');
Assert::same('NOT_FOUND',$adapter->history($session,$cid,51)['decision'],'more than 50 rows refused before any owner call');
Assert::same('NOT_FOUND',$adapter->history($session,$cid,20,'not-a-uuid')['decision'],'malformed cursor refused');
Assert::same(0,count($calls),'no failed selector reached owner');

$result=$adapter->history($session,$cid);
Assert::same('VISIBLE',$result['decision'],'authorized metadata can pass the closed adapter');
Assert::same('evidence.read',$result['required_permission'],'history requires evidence read, not watchlist-only read');
Assert::same(1,count($calls),'one explicit owner read');
Assert::same([
    'tenant_id'=>$tenant,'workspace_id'=>$workspace,'actor_type'=>'OPERATOR','actor_id'=>$operator,
],$calls[0]['scope'],'read scope derived exclusively from authenticated session');
Assert::same($cid,$calls[0]['id'],'selector passed only after UUID verification');
Assert::same(20,$calls[0]['limit'],'default bounded page');
Assert::same(null,$calls[0]['cursor'],'no fabricated pagination');

$d=$result['data'];
Assert::same('li16.competitor-history-metadata.v1',$d['projection_version'],'versioned closed metadata contract');
Assert::same(false,$d['business_materiality_available'],'no computed business significance');
Assert::same('NOT_ASSESSED',$d['rows'][0]['business_materiality'],'structural MINOR is not materiality');
Assert::same('NOT_INFERRED',$d['rows'][0]['release_status'],'capture not a customer release');
Assert::same('MINOR',$d['rows'][0]['comparisons'][0]['structural_significance'],'structural source classification retained');
Assert::same('CURRENT_ELIGIBILITY_CHECKED',$d['source_rights'],'source-use checks delegated to current owner');
Assert::same(true,$d['read_only'],'metadata is read-only');
foreach (['candidate_text','source_url_safe','credentials','body','prompt','content_hash'] as $blocked) {
    Assert::false(array_key_exists($blocked,$d['rows'][0]),'private row field excluded '.$blocked);
}
Assert::false(array_key_exists('raw_text',$d['rows'][0]['comparisons'][0]),'private comparison content excluded');
Assert::false(array_key_exists('internal_provider_status',$d),'private owner fields excluded');
Assert::false(array_key_exists('billing_plan',$d['competitor']),'private competitor field excluded');

$owner['data']['next_cursor']=$sid;
$page=$adapter->history($session,$cid,20);
Assert::same($sid,$page['data']['next_cursor'],'only exact last emitted snapshot may be page cursor');
$owner['data']['next_cursor']=Ids::new();
Assert::same('UNAVAILABLE',$adapter->history($session,$cid)['decision'],'owner cannot fabricate out-of-page next cursor');
$owner['data']['next_cursor']=null;
$owner['data']['read_only']=false;
Assert::same('UNAVAILABLE',$adapter->history($session,$cid)['decision'],'not explicitly read-only owner data refused');
$owner['data']['read_only']=true;
$owner['data']['source_rights']='NOT_CHECKED';
Assert::same('UNAVAILABLE',$adapter->history($session,$cid)['decision'],'historical rights not claimed without owner recheck');
$owner['data']['source_rights']='CURRENT_ELIGIBILITY_CHECKED';
$owner['data']['rows'][0]['business_materiality']='MINOR';
Assert::same('UNAVAILABLE',$adapter->history($session,$cid)['decision'],'candidate materiality cannot silently enter metadata adapter');
$owner['data']['rows'][0]['business_materiality']='NOT_ASSESSED';
$owner['data']['rows'][0]['comparisons'][0]['structural_significance']='AUTHORIZE_RELEASE';
Assert::same('UNAVAILABLE',$adapter->history($session,$cid)['decision'],'invalid structural value refused');
$owner['data']['rows'][0]['comparisons'][0]['structural_significance']='MINOR';

$owner=['decision'=>'DENIED','reason_codes'=>['M02_PERMISSION_DENIED']];
$denied=$adapter->history($session,$cid);
Assert::same('DENIED',$denied['decision'],'owner denial preserved');
Assert::same('evidence.read',$denied['required_permission'],'owner denial names historical evidence permission');
Assert::false(isset($denied['data']),'no data on denial');
$owner=['decision'=>'DENIED','reason_codes'=>['RAW <candidate> secret']];
Assert::same(['HISTORY_ACCESS_DENIED'],$adapter->history($session,$cid)['reason_codes'],'untrusted owner error never exposed');
$owner=['decision'=>'NOT_FOUND','reason_codes'=>['HISTORY_NOT_FOUND']];
Assert::same('NOT_FOUND',$adapter->history($session,$cid)['decision'],'unknown/foreign owner result remains nonenumerable');
Assert::false(isset($adapter->history($session,$cid)['data']),'not found does not carry private rows');
$thrower=new CompetitorHistoryAdapter(static function ():array { throw new \RuntimeException('private database connection info'); });
Assert::same('UNAVAILABLE',$thrower->history($session,$cid)['decision'],'owner failure fails closed');
Assert::false(isset($thrower->history($session,$cid)['exception']),'exception payload not exposed');
