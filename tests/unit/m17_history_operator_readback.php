<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewReadAdapter;
use Allfather\Loopia\Tests\Assert;

$tenant=Ids::new();$workspace=Ids::new();$operator=Ids::new();
$competitor=Ids::new();$question=Ids::new();$basisId=Ids::new();
$session=['tenant_id'=>$tenant,'active_workspace_id'=>$workspace,'operator_id'=>$operator];
$calls=0;
$owner=function(array $scope,int $limit)use(&$calls,$tenant,$workspace,$operator,$competitor,$question,$basisId):array {
    $calls++;
    Assert::same(['tenant_id'=>$tenant,'workspace_id'=>$workspace,'actor_type'=>'OPERATOR',
        'actor_id'=>$operator,'competitor_id'=>$competitor,'question_id'=>$question],
        $scope,'operator identity must derive only from the trusted session');
    return ['decision'=>'VISIBLE','required_permission'=>'evidence.read',
        'data'=>[
            'read_only'=>true,'candidate_only'=>true,'source_rights'=>'U03_NOT_ADOPTED',
            'rows'=>[[
                'history_review_basis_id'=>$basisId,'basis_digest'=>str_repeat('a',64),
                'head_status'=>'AMBIGUOUS_HEAD_HOLD',
                'due_status'=>'DUE_CANDIDATE_HUMAN_REVIEW_ONLY',
                'selected_count'=>3,'excluded_count'=>1,
                'raw_source_text'=>'DO NOT LEAK SOURCE BYTES',
                'source_url'=>'https://private.example/',
                'provider_token'=>'SHOULD_NOT_APPEAR',
            ]],
        ]];
};
$reader=new HistoryReviewReadAdapter($owner);
$ok=$reader->list($session,$competitor,$question);
Assert::same('VISIBLE',$ok['decision'],'private owner-authorized metadata readback can be projected');
Assert::same('AMBIGUOUS_HEAD_HOLD',$ok['data']['rows'][0]['head_status'],'fork ambiguity visible');
Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$ok['data']['rows'][0]['due_status'],'due reason visible');
Assert::same(false,$ok['data']['runtime_actions_available'],'readback cannot create an action');
$display=json_encode($ok,JSON_THROW_ON_ERROR);
foreach(['DO NOT LEAK','private.example','provider_token','SHOULD_NOT_APPEAR','raw_source_text'] as $secret) {
    Assert::false(str_contains($display,$secret),'metadata whitelist filters: '.$secret);
}
Assert::same('NO_ACTIVE_WORKSPACE',$reader->list(null,$competitor,$question)['decision'],
    'session-less requests blocked without calling owner');
Assert::same('NOT_FOUND',$reader->list($session,'bad-id',$question)['decision'],
    'untyped competitor selector rejected');
Assert::same('NOT_FOUND',$reader->list($session,$competitor,$question,51)['decision'],
    'operator cannot request unlimited history');
Assert::same(1,$calls,'invalid requests never call protected owner');
$denied=new HistoryReviewReadAdapter(static fn():array=>['decision'=>'DENIED','reason_codes'=>['SECRET']]);
Assert::same(['HISTORY_READ_NOT_AUTHORIZED'],$denied->list($session,$competitor,$question)['reason_codes'],
    'denied owner reason is not used to leak source/permission details');
$bad=new HistoryReviewReadAdapter(static fn():array=>['decision'=>'VISIBLE','required_permission'=>'evidence.read',
    'data'=>['read_only'=>true,'candidate_only'=>false,'source_rights'=>'U03_NOT_ADOPTED','rows'=>[]]]);
Assert::same('UNAVAILABLE',$bad->list($session,$competitor,$question)['decision'],
    'no adopted or unbounded owner answer may reach a purportedly candidate-only pane');
