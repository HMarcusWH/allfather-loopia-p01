<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M17\{InputSet,Lineage,RollupEngine,Replay,WindowEngine};
use Allfather\Loopia\Tests\{Assert,Li07aFixture};

require_once dirname(__DIR__) . '/support/Li07aFixture.php';
$v=Li07aFixture::data();
$inputs=Li07aFixture::inputs();
$run=RollupEngine::derive($inputs)->value();
Assert::same(82800,$run['window_bounds']['duration_seconds'],
    'spring Europe/Stockholm 2026 time interval is 23 hours');
Assert::same(['SUPPORT'=>1,'CONTRARY'=>1,'UNKNOWN'=>1],$run['measures']['polarity_counts'],
    'contradictory evidence survives rollup class histogram');
Assert::same(['ref_b','ref_c'],$run['caveats']['contrary_or_conflict_refs'],
    'explicit caveats retain rare disconfirmation');
Assert::same(null,$run['measures']['business_importance_basis_points'],
    'magnitude averaging does not infer importance');
Assert::same('UNKNOWN',$run['measures']['business_importance_basis'],
    'business importance missing stays unknown');
Assert::same(900,$run['measures']['magnitude_mean_numerator'],
    'typed integer mean numerator');
Assert::same(2,$run['measures']['magnitude_mean_denominator'],
    'typed mean denominator ignores unknown');
Assert::same(90000,$run['measures']['population_variance_numerator'],
    'variance numerator retained exactly');
Assert::same(4,$run['measures']['population_variance_denominator'],
    'variance denominator retained exactly');
Assert::same('CALLER_SUPPLIED_METADATA_NOT_AUTHENTICATED',$run['caveats']['uncertainty_code'],
    'a statistical rollup is not authenticated business evidence');
$replay=Replay::check(['expected_rollup'=>$run,'frozen_inputs'=>$inputs,'reported_unavailable_ref_ids'=>[]]);
Assert::same('MATCH_METADATA_ONLY',$replay->value()['outcome'],'frozen value replay, not external source validation');
for($i=0;$i<35;$i++){
    $refs=$v['refs'];
    if($i%3===0)$refs=array_reverse($refs);
    if($i%3===1){$x=array_shift($refs);$refs[]=$x;}
    if($i%3===2)[$refs[0],$refs[2]]=[$refs[2],$refs[0]];
    $candidate=RollupEngine::derive(Li07aFixture::inputs($refs))->value();
    Assert::same($run['rollup_digest'],$candidate['rollup_digest'],
        'stable ordering and digest of same frozen input reference set');
    Assert::same($run['lineage']['lineage_digest'],$candidate['lineage']['lineage_digest'],
        'full lineage identity stable under caller permutation');
    Assert::same($run['measures']['measure_digest'],$candidate['measures']['measure_digest'],
        'numerical measures stable independent of record order');
}
$fall=Li07aFixture::inputs($v['refs'],null,'DAY','2026-10-25T10:00:00Z');
$fallSummary=RollupEngine::derive($fall)->value();
Assert::same(90000,$fallSummary['window_bounds']['duration_seconds'],
    'Europe/Stockholm fall DST day is 25 hours');
Assert::same('INSUFFICIENT_INPUT',$fallSummary['status'],
    'zero in-period refs does not claim no structural change');
$weights=$inputs;$weights['importance_weights']=['profile_weight_basis_points'=>5000,'entity_role_weight_basis_points'=>3000];
Assert::same('CALLER_SUPPLIED_WEIGHTS_UNVERIFIED',RollupEngine::derive($weights)->value()['measures']['business_importance_basis'],
    'weight-bearing scoring remains unverified and non-admitted');
Assert::same('NOT_ASSESSED',RollupEngine::derive($weights)->value()['caveats']['commercial_materiality'],
    'caller score cannot override business materiality ceiling');
