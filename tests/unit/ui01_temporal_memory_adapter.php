<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\ReadAdapters\TemporalMemoryAdapter;
use Allfather\Loopia\Tests\Assert;

$tenant=Ids::new();$workspace=Ids::new();$operator=Ids::new();
$competitor=Ids::new();$question=Ids::new();$memory=Ids::new();
$session=[
    'tenant_id'=>$tenant,'active_workspace_id'=>$workspace,
    'operator_id'=>$operator,'query_tenant_override'=>'bad',
];
$owner=[
    'decision'=>'VISIBLE','required_permission'=>'evidence.read',
    'data'=>[
        'read_only'=>true,'candidate_only'=>true,
        'source_rights'=>'U03_NOT_ADOPTED',
        'runtime_actions_available'=>false,'page_size'=>20,
        'next_cursor'=>null,
        'rows'=>[[
            'temporal_memory_id'=>$memory,
            'v2_run_identity_digest'=>str_repeat('a',64),
            'rollup_digest'=>str_repeat('b',64),
            'data_class'=>'SOURCE_DIFF','window_kind'=>'MONTH',
            'period_start_utc'=>'2026-03-01T00:00:00Z',
            'period_end_utc'=>'2026-04-01T00:00:00Z',
            'knowledge_cutoff_utc'=>'2026-03-15T00:00:00Z',
            'selected_ref_count'=>3,'contrary_ref_count'=>1,
            'head_status'=>'AMBIGUOUS_HEAD_HOLD',
            'replay_status'=>'MATCH_METADATA_ONLY',
            'rights_status'=>'NOT_ADMITTED',
            'source_bytes_verified'=>false,
            'historical_reuse_admitted'=>false,
            'customer_release_authorized'=>false,
            'raw_source_text'=>'SENTINEL_PRIVATE_NEVER_PRESENTED',
            'source_url'=>'https://invalid.example/private',
            'credential_digest'=>'SENTINEL_PRIVATE_NEVER_PRESENTED',
        ]],
        'user_notes'=>'SENTINEL_PRIVATE_NEVER_PRESENTED',
    ],
];
$calls=[];
$read=static function(array $scope,int $limit,?string $cursor)use(&$calls,&$owner):array{
    $calls[]=['scope'=>$scope,'limit'=>$limit,'cursor'=>$cursor];
    return $owner;
};
$adapter=new TemporalMemoryAdapter($read);
Assert::same('NO_ACTIVE_WORKSPACE',
    $adapter->list(null,$competitor,$question)['decision'],
    'no session cannot see temporal memory');
Assert::same('NOT_FOUND',
    $adapter->list($session,'bad-competitor',$question)['decision'],
    'bad competitor selector fails before owner read');
Assert::same('NOT_FOUND',
    $adapter->list($session,$competitor,$question,0)['decision'],
    'no unbounded page size');
Assert::same('NOT_FOUND',
    $adapter->list($session,$competitor,$question,51)['decision'],
    'maximum 50 rows enforced');
Assert::same('NOT_FOUND',
    $adapter->list($session,$competitor,$question,20,'bad-cursor')['decision'],
    'unknown/invalid cursor never reaches source owner');
Assert::same(0,count($calls),'every bad selector refused before owner invocation');
$ok=$adapter->list($session,$competitor,$question);
Assert::same('VISIBLE',$ok['decision'],'owner-reported candidate metadata can be projected');
Assert::same(1,count($calls),'one scoped read through injected owner');
Assert::same([
    'tenant_id'=>$tenant,'workspace_id'=>$workspace,
    'actor_type'=>'OPERATOR','actor_id'=>$operator,
    'competitor_id'=>$competitor,'question_id'=>$question,
],$calls[0]['scope'],'scope comes exclusively from authenticated session');
Assert::same('evidence.read',$ok['required_permission'],
    'watchlist permission alone cannot authorize private memory metadata');
Assert::same(1,count($ok['data']['rows']),'bounded one-row presentation');
Assert::same('ui01.inert-p01-temporal-memory.v1',
    $ok['data']['projection_version'],'UI01 version explicit');
Assert::same('AMBIGUOUS_HEAD_HOLD',$ok['data']['rows'][0]['head_status'],
    'forked temporal heads cannot be collapsed into a winner');
Assert::same('NOT_ADMITTED',$ok['data']['rows'][0]['rights_status'],
    'candidate memory never implies U03 rights');
Assert::same(false,$ok['data']['historical_analysis_available'],
    'metadata page cannot launch a model');
Assert::same(false,$ok['data']['customer_output_authorized'],
    'read-only evidence cannot become a customer finding');
$bytes=json_encode($ok,JSON_THROW_ON_ERROR);
foreach(['SENTINEL_PRIVATE_NEVER_PRESENTED','source_url',
    'raw_source_text','credential_digest','invalid.example'] as $private){
    Assert::false(str_contains($bytes,$private),
        'owner-extra source/private bytes stripped from presentation: '.$private);
}
$owner['data']['next_cursor']=$memory;
Assert::same($memory,
    $adapter->list($session,$competitor,$question)['data']['next_cursor'],
    'only last visible memory id can be next page cursor');
$owner['data']['next_cursor']=Ids::new();
Assert::same('UNAVAILABLE',$adapter->list($session,$competitor,$question)['decision'],
    'source owner cannot invent an unrelated page boundary');
$owner['data']['next_cursor']=null;

$owner['data']['rows'][0]['source_bytes_verified']=true;
Assert::same('UNAVAILABLE',$adapter->list($session,$competitor,$question)['decision'],
    'owner-presented current rights may not be self-asserted in this candidate');
$owner['data']['rows'][0]['source_bytes_verified']=false;
$owner['data']['rows'][0]['contrary_ref_count']=4;
Assert::same('UNAVAILABLE',$adapter->list($session,$competitor,$question)['decision'],
    'contrary count cannot exceed selected references');
$owner['data']['rows'][0]['contrary_ref_count']=1;
$owner['data']['rows'][0]['replay_status']='MODEL_APPROVED';
Assert::same('UNAVAILABLE',$adapter->list($session,$competitor,$question)['decision'],
    'provider verdict cannot enter offline M17 replay status');
$owner['data']['rows'][0]['replay_status']='MATCH_METADATA_ONLY';
$owner['data']['source_rights']='ADMITTED_BY_CALLER';
Assert::same('UNAVAILABLE',$adapter->list($session,$competitor,$question)['decision'],
    'no self-asserted U03 current source rights');
$owner['data']['source_rights']='U03_NOT_ADOPTED';
$owner['decision']='DENIED';
Assert::same('DENIED',$adapter->list($session,$competitor,$question)['decision'],
    'real owner denial is not transformed into an empty visible history');
$owner['decision']='VISIBLE';
$owner['data']['rows']=[];
Assert::same([],$adapter->list($session,$competitor,$question)['data']['rows'],
    'owner-proven empty data remains empty, never inferred NO_CHANGE');
