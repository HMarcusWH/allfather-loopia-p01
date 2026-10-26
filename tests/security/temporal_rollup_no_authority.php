<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M17\{InputSet,RollupEngine,Replay};
use Allfather\Loopia\Tests\{Assert,Li07aFixture};

require_once dirname(__DIR__) . '/support/Li07aFixture.php';
$v=Li07aFixture::data();
$original=Li07aFixture::inputs();
$basis=RollupEngine::derive($original)->value();
$spoof=$basis;
$spoof['release_eligible']=true;
unset($spoof['rollup_digest']);$spoof['rollup_digest']=Digest::canonicalDigest($spoof);
Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($spoof)->reasonCode(),
    're-hashed candidate cannot create release permission');
$spoof=$basis;
$spoof['customer_output_eligible']=true;
unset($spoof['rollup_digest']);$spoof['rollup_digest']=Digest::canonicalDigest($spoof);
Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($spoof)->reasonCode(),
    're-hashed candidate cannot authorize customer visibility');
$spoof=$basis;
$spoof['profile_authority_adopted']=true;
Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($spoof)->reasonCode(),
    'U04 profile authority cannot be self-certified');
$spoof=$basis;
$spoof['storage_write_performed']=true;
Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($spoof)->reasonCode(),
    'no memory write can be asserted by an offline digest');
$spoof=$basis;
$spoof['lineage']['selected_refs'][1]['polarity']='SUPPORT';
$inner=$spoof['lineage'];unset($inner['lineage_digest']);
$spoof['lineage']['lineage_digest']=Digest::canonicalDigest($inner);
$outer=$spoof;unset($outer['rollup_digest']);$spoof['rollup_digest']=Digest::canonicalDigest($outer);
Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($spoof)->reasonCode(),
    'contradiction cannot be hidden with two freshly recomputed hashes');
$foreign=$original;
$scope=$v['scope'];$scope['tenant_id']='tenant_foreign';
$foreign['input_set']=InputSet::freeze([
    'scope'=>$scope,'data_class'=>'SOURCE_DIFF','refs'=>$v['refs'],
])->value();
Assert::same('M17_ROLLUP_PROFILE_OR_SCOPE_NOT_ADMISSIBLE',RollupEngine::derive($foreign)->reasonCode(),
    'input refs cannot cross a verified profile scope');
$bad=$original;
$bad['profile_candidate']['resolved']['minimum_lineage']='REPRESENTATIVE_REFS_PLUS_COUNTS';
$profile=$bad['profile_candidate'];unset($profile['resolution_digest']);
$bad['profile_candidate']['resolution_digest']=Digest::canonicalDigest($profile);
Assert::same('M17_ROLLUP_PROFILE_OR_SCOPE_NOT_ADMISSIBLE',RollupEngine::derive($bad)->reasonCode(),
    'no lossy lineage policy can be used even with valid hash');
$hold=$original;
$hold['profile_candidate']['event_level_hold']=true;
$profile=$hold['profile_candidate'];unset($profile['resolution_digest']);
$hold['profile_candidate']['resolution_digest']=Digest::canonicalDigest($profile);
Assert::same('M17_ROLLUP_PROFILE_OR_SCOPE_NOT_ADMISSIBLE',RollupEngine::derive($hold)->reasonCode(),
    'an event-level legal/audit floor cannot be reduced to period rollup');
$forgedHold=$original;
$forgedHold['profile_candidate']['applied_floor_candidates']=[[ 
    'floor'=>'LEGAL_HOLD', 'effect'=>'FORCE_EVENT_LEVEL',
    'basis_ref_safe'=>'fixture_hold', 'minimum_retention_days'=>null,
]];
// A digest recompute cannot turn an explicit hold into a lossy bucket.
$snapshot=$forgedHold['profile_candidate'];unset($snapshot['resolution_digest']);
$forgedHold['profile_candidate']['resolution_digest']=Digest::canonicalDigest($snapshot);
Assert::same('M17_ROLLUP_HELD_OR_FORBIDDEN_PROFILE',RollupEngine::derive($forgedHold)->reasonCode(),
    'reported FORCE_EVENT_LEVEL floor stays a refusal even when hold flag was forged false');
$forgedForbid=$original;
$forgedForbid['profile_candidate']['applied_floor_candidates']=[[ 
    'floor'=>'SOURCE_USE_CEILING', 'effect'=>'FORBID',
    'basis_ref_safe'=>'fixture_denial', 'minimum_retention_days'=>null,
]];
$snapshot=$forgedForbid['profile_candidate'];unset($snapshot['resolution_digest']);
$forgedForbid['profile_candidate']['resolution_digest']=Digest::canonicalDigest($snapshot);
Assert::same('M17_ROLLUP_HELD_OR_FORBIDDEN_PROFILE',RollupEngine::derive($forgedForbid)->reasonCode(),
    'reported source-use FORBID cannot be bypassed through a hash-bound candidate');
$secret=$original;
$secret['input_set']['refs'][0]['source_body']='untrusted-private-document';
Assert::same('M17_ROLLUP_INPUT_INVALID',RollupEngine::derive($secret)->reasonCode(),
    'source body cannot be smuggled into memory metadata');
$secret=$original;
$secret['api_key']='forbidden';
Assert::same('M17_ROLLUP_INPUT_INVALID',RollupEngine::derive($secret)->reasonCode(),
    'unknown keys cannot become a tool/credential capability');
$missing=Replay::check([
    'expected_rollup'=>$basis,'frozen_inputs'=>null,'reported_unavailable_ref_ids'=>[],
]);
Assert::same('NOT_REPLAYABLE',$missing->value()['outcome'],
    'unavailable input bytes/refs cannot be reconstructed from an aggregate');
Assert::same(false,$missing->value()['historical_reuse_admitted'],
    'no admission on replay limitation');
