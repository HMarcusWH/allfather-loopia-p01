<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\{InputSet,Replay,RollupEngine};
use Allfather\Loopia\Tests\{Assert,Li07aFixture};

require_once dirname(__DIR__) . '/support/Li07aFixture.php';
$v=Li07aFixture::data();
$inputs=Li07aFixture::inputs();
$rollup=RollupEngine::derive($inputs)->value();
$check=static fn($expected,$frozen,$unavailable)=>Replay::check([
    'expected_rollup'=>$expected,'frozen_inputs'=>$frozen,'reported_unavailable_ref_ids'=>$unavailable,
]);
$match=$check($rollup,$inputs,[]);
Assert::true($match->isOk(),'replay on exact frozen metadata is a value');
Assert::same('MATCH_METADATA_ONLY',$match->value()['outcome'],'never calls it a source-content match');
Assert::same($rollup['rollup_digest'],$match->value()['expected_digest'],'replay binds expected candidate');
Assert::same($rollup['rollup_digest'],$match->value()['actual_digest'],'recalculated candidate matches');
Assert::same(false,$match->value()['source_bytes_verified'],'no source reads took place');
Assert::same(false,$match->value()['historical_reuse_admitted'],'no model authority by replay');
$missing=$check($rollup,null,[])->value();
Assert::same('NOT_REPLAYABLE',$missing['outcome'],'no frozen input is not a pass');
Assert::same('FROZEN_INPUTS_UNAVAILABLE',$missing['reason_code'],'explicit inability to replay');
$redacted=$check($rollup,$inputs,['ref_b'])->value();
Assert::same('NOT_REPLAYABLE',$redacted['outcome'],'reported unavailable ref stops replay');
Assert::same('INPUT_REF_UNAVAILABLE_REPORTED',$redacted['reason_code'],'reported unavailable input surfaced explicitly');
Assert::same(['ref_b'],$redacted['reported_unavailable_ref_ids'],'ref ID rather than raw bytes in refusal');
Assert::same('M17_REPLAY_UNAVAILABLE_REF_INVALID',$check($rollup,$inputs,['not_in_basis'])->reasonCode(),
    'unrelated ref does not pollute replay scope');
Assert::same('M17_REPLAY_UNAVAILABLE_REF_INVALID',$check($rollup,$inputs,['ref_a','ref_a'])->reasonCode(),
    'duplicate unavailable list is refused');
$lateInputs=Li07aFixture::inputs([...$v['refs'],$v['late_ref']]);
$diverged=$check($rollup,$lateInputs,[]);
Assert::true($diverged->isOk(),'late-input rederivation returns a bounded comparison');
Assert::same('DIVERGED',$diverged->value()['outcome'],'late evidence cannot match old exact digest');
Assert::same('M17_TEMPORAL_REPLAY_DIVERGENCE_CANDIDATE',$diverged->value()['reason_code'],
    'divergence is not silently repaired or persisted');
Assert::notSame($diverged->value()['expected_digest'],$diverged->value()['actual_digest'],
    'bounded divergence exposes only hashes, not raw source bytes');
$rewritten=$inputs;
$refs=$v['refs'];$refs[1]['payload_sha256']=str_repeat('b',64);
$rewritten['input_set']=InputSet::freeze([
    'scope'=>$v['scope'],'data_class'=>'SOURCE_DIFF','refs'=>$refs,
])->value();
Assert::same('DIVERGED',$check($rollup,$rewritten,[])->value()['outcome'],
    'old input hash revision cannot pass replay');
$spoof=$rollup;$spoof['historical_reuse_admitted']=true;
Assert::same('M17_REPLAY_CONTRACT_INVALID',$check($spoof,$inputs,[])->reasonCode(),
    'forged authority on expected hash is refused before checking inputs');
$spoof=$rollup;$spoof['rollup_digest']=str_repeat('0',64);
Assert::same('M17_REPLAY_CONTRACT_INVALID',$check($spoof,$inputs,[])->reasonCode(),
    'wrong expected digest cannot be mistaken for ordinary divergence');
$corrupt=$inputs;$corrupt['input_set']['input_digest']=str_repeat('1',64);
$unreplayable=$check($rollup,$corrupt,[]);
Assert::same('NOT_REPLAYABLE',$unreplayable->value()['outcome'],
    'malformed current metadata cannot produce a fake zero observation match');
Assert::same('FROZEN_METADATA_REDERIVATION_REFUSED',$unreplayable->value()['reason_code'],
    'failed input validation is a distinct replay limitation');
$spoofMeta=$inputs;$spoofMeta['token']='secret';
Assert::same('M17_REPLAY_FROZEN_INPUT_CONTRACT_INVALID',$check($rollup,$spoofMeta,[])->reasonCode(),
    'untrusted extra capabilities cannot join replay context');
