<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M17\{InputSet, Lineage, RollupEngine, WindowEngine};
use Allfather\Loopia\Tests\{Assert, Li07aFixture};

require_once dirname(__DIR__) . '/support/Li07aFixture.php';
$v=Li07aFixture::data();
$inputs=Li07aFixture::inputs();
$run=RollupEngine::derive($inputs);
Assert::true($run->isOk(),'closed LI06A frozen input produces offline rollup candidate');
if ($run->isOk()) {
    $value=$run->value();
    Assert::same('li07a.offline-rollup.v1', $value['version'], 'versioned offline candidate');
    Assert::same('ROLLUP_CANDIDATE',$value['kind'],'no persisted M17 memory claimed');
    Assert::same('OFFLINE_NOT_ADMITTED',$value['status'],'no source-use authority inferred');
    Assert::same(false,$value['historical_reuse_admitted'],'historical reuse never admitted');
    Assert::same(false,$value['source_rights_verified'],'M05 not queried');
    Assert::same(false,$value['storage_write_performed'],'no persistence');
    Assert::same(false,$value['release_eligible'],'cannot grant release');
    Assert::same(false,$value['customer_output_eligible'],'cannot grant customer access');
    Assert::same(false,$value['profile_authority_adopted'],'U04 pending');
    Assert::same(true,$value['replayable_metadata_only'],'only reproducible frozen metadata');
    Assert::true(RollupEngine::verify($value)->isOk(),'hash-bound structure valid');
    Assert::same(Digest::canonicalDigest(array_diff_key($value,['rollup_digest'=>null])),
        $value['rollup_digest'],'uses existing canonical digest law');
    Assert::same(3,$value['measures']['reference_count'],'only in-window metadata selected');
    Assert::same($v['expected']['selected_ref_ids'],array_column($value['lineage']['selected_refs'],'ref_id'),
        'full in-window refs retained, including contrary and unknown');
    Assert::same($v['expected']['outside_ref_ids'],$value['lineage']['excluded_outside_window_ref_ids'],
        'out-of-window refs visibly excluded, not silently ignored');
    Assert::same($v['expected']['contradictory_ref_ids'],$value['lineage']['contrary_or_conflict_ref_ids'],
        'both contrary label and conflict flag are itemized');
    Assert::same($v['expected']['diagnostic_ref_ids'],$value['lineage']['high_diagnosticity_ref_ids'],
        'high-diagnosticity witness ref retained');
    Assert::same($v['expected']['distinct_payload_hashes'],$value['measures']['distinct_payload_hashes'],
        'exact payload classes, not independent witness count');
    Assert::same(null,$value['measures']['independent_event_count'],
        'two same-SHA captures are not corroboration');
    Assert::same($v['expected']['magnitude_sum_milli'],$value['measures']['magnitude_sum_milli'],
        'integer sum over known magnitudes');
    Assert::same($v['expected']['known_magnitude_count'],$value['measures']['known_magnitude_count'],
        'null magnitude excluded from mean denominator');
    Assert::same($v['expected']['unknown_magnitude_count'],$value['measures']['unknown_magnitude_count'],
        'unknown magnitude not treated as zero');
    Assert::same($v['expected']['spring_day_duration_seconds'],$value['window_bounds']['duration_seconds'],
        'local Stockholm spring DST day is 23 hours');
    Assert::same('NOT_ASSESSED',$value['caveats']['coverage_disposition'],
        'missing capture coverage cannot be conflated with no change');
    Assert::same('UNKNOWN',$value['caveats']['event_independence_disposition'],
        'common-origin analysis is absent');
    Assert::same('NOT_ASSESSED',$value['caveats']['commercial_materiality'],
        'diff magnitude is not strategic/business importance');
    Assert::false(str_contains($value['narrative_safe'],'COMPANY'), 'template is neutral, no imagined company facts');
    Assert::true(Lineage::verify($value['lineage'])->isOk(), 'lineage hash and closed shape valid');
    $tampered=$value;
    $tampered['lineage']['selected_refs'][1]['polarity']='SUPPORT';
    Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($tampered)->reasonCode(),
        'inner lineage tampering rejects outer rollup even if no outer digest changed');
    $tampered=$value;
    $tampered['historical_reuse_admitted']=true;
    $copy=$tampered; unset($copy['rollup_digest']);
    $tampered['rollup_digest']=Digest::canonicalDigest($copy);
    Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($tampered)->reasonCode(),
        'valid recomputed digest cannot self-authorize reuse');
    $tampered=$value;
    $tampered['measures']['reference_count']=999;
    Assert::same('M17_ROLLUP_SHAPE_INVALID',RollupEngine::verify($tampered)->reasonCode(),
        'measure modification cannot pass original digest');
}
$permute=$v['refs'];
for ($i=0;$i<20;$i++) {
    $rotated=$permute;
    if($i%2===0)$rotated=array_reverse($rotated);
    else { $r=array_shift($rotated);$rotated[]=$r;}
    $input=Li07aFixture::inputs($rotated);
    Assert::same($run->value()['rollup_digest'],RollupEngine::derive($input)->value()['rollup_digest'],
        'caller row order cannot change hash-bound rollup');
    $permute=$rotated;
}
$empty=Li07aFixture::inputs($v['refs'],null,'DAY','2026-06-01T00:00:00Z');
$no=RollupEngine::derive($empty);
Assert::same('INSUFFICIENT_INPUT',$no->value()['status'],'empty window does not invent NO_CHANGE');
Assert::same(null,$no->value()['caveats']['oldest_observed_at_utc'],'empty window has no invented freshness');
$foreign=$inputs;
$foreign['profile_candidate']['scope']['tenant_id']='foreign_looks_local';
Assert::same('M17_ROLLUP_INPUT_INVALID',RollupEngine::derive($foreign)->reasonCode(),
    'forged profile scope breaks integrity checks');
$unknown=$inputs;
$unknown['input_set']['refs'][0]['body']='untrusted source text';
Assert::same('M17_ROLLUP_INPUT_INVALID',RollupEngine::derive($unknown)->reasonCode(),
    'source body cannot enter referential memory');
