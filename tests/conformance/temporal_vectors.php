<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M17\DynamicMeasures;
use Allfather\Loopia\Substrate\M17\InputSet;
use Allfather\Loopia\Substrate\M17\ProfileResolver;
use Allfather\Loopia\Substrate\M17\WindowEngine;
use Allfather\Loopia\Tests\Assert;

$vector=json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/temporal_contract_vectors.json'),true,64,JSON_THROW_ON_ERROR);
$original=$vector['input_set'];
$sets=InputSet::freeze($original);
Assert::true($sets->isOk(),'typed synthetic input set accepted');
if ($sets->isOk()) {
    $x=$sets->value();
    Assert::same('li06a.offline-input-set.v1',$x['version'],'v1 typed input-set contract');
    Assert::same(['ref_one','ref_two','ref_three','ref_four'],array_column($x['refs'],'ref_id'),
        'normalized event-time order and safe tie ordering');
    Assert::same(false,$x['historical_reuse_admitted'],'no reuse permission created by identity hash');
    Assert::true(InputSet::verify($x)->isOk(),'candidate payload/digest replays exactly');
    $before=$x;unset($before['input_digest']);
    Assert::same(Digest::canonicalDigest($before),$x['input_digest'],
        'input digest uses existing canonical JSON law');
    // Deliberately shuffle the caller's input order; output is invariant.
    for ($i=0; $i<35; $i++) {
        $copy=$original;
        $copy['refs']=array_merge(array_slice($original['refs'],$i % 4),array_slice($original['refs'],0,$i % 4));
        Assert::same($x['input_digest'],InputSet::freeze($copy)->value()['input_digest'],
            'stable digest independent of caller order permutation');
    }
    $mutated=$x;$mutated['refs'][0]['payload_sha256']=str_repeat('9',64);
    Assert::same('M17_INPUT_SET_REPLAY_MISMATCH',InputSet::verify($mutated)->reasonCode(),
        'changing a historical reference without matching digest is rejected');
    $mutated=$x;$mutated['historical_reuse_admitted']=true;
    Assert::same('M17_INPUT_SET_SHAPE_INVALID',InputSet::verify($mutated)->reasonCode(),
        'no upstream admission bit can be self-certified');
}
$invalid=$original;
$invalid['refs'][1]['ref_id']=$invalid['refs'][0]['ref_id'];
Assert::same('M17_INPUT_DUPLICATE_REF',InputSet::freeze($invalid)->reasonCode(),
    'duplicate observation IDs cannot be counted twice');
$invalid=$original;$invalid['refs'][0]['ref_type']='COMPANY_SIGNAL';
Assert::same('M17_INPUT_REF_INVALID',InputSet::freeze($invalid)->reasonCode(),
    'wrong class cannot enter a source-diff candidate');
$invalid=$original;$invalid['refs'][0]['body']='untrusted source text';
Assert::same('M17_INPUT_REF_INVALID',InputSet::freeze($invalid)->reasonCode(),
    'source body cannot become derived memory input');
$invalid=$original;$invalid['refs'][0]['magnitude_milli']=1.25;
Assert::same('M17_INPUT_REF_INVALID',InputSet::freeze($invalid)->reasonCode(),
    'noninteger structural magnitude refused (numeric law pending U04)');
$invalid=$original;$invalid['refs'][0]['observed_at_utc']='2026-03-29T11:00:00+01:00';
Assert::same('M17_INPUT_REF_INVALID',InputSet::freeze($invalid)->reasonCode(),
    'timestamp offsets not normalized silently');
$invalid=$original;$invalid['refs']=array_fill(0,InputSet::MAX_REFS+1,$invalid['refs'][0]);
Assert::same('M17_INPUT_SET_INVALID',InputSet::freeze($invalid)->reasonCode(),
    'source candidate count refuses overflow before any aggregate');
$invalid=$original;$invalid['scope']['tenant_id']='tenant_foreign';
$foreign=InputSet::freeze($invalid);
Assert::true($foreign->isOk(), 'a different correctly declared scope is a separate input set');
Assert::notSame($sets->value()['input_digest'],$foreign->value()['input_digest'],
    'tenant-local digest identity never aliases another tenant');

$start=WindowEngine::assign(['kind'=>'DAY','observation_at_utc'=>'2026-03-29T10:00:00Z',
    'calendar_config'=>['timezone'=>'Europe/Stockholm','tzdb_version'=>timezone_version_get()]]);
Assert::true($start->isOk(),'reference window is valid');
$input=['input_set'=>$sets->value(),'window'=>$start->value(),'importance_weights'=>null];
$m=DynamicMeasures::measure($input);
Assert::true($m->isOk(),'deterministic measures from typed source refs');
if ($m->isOk()) {
    $value=$m->value();
    foreach ($vector['expected_spring_day_measures'] as $key=>$expected) {
        Assert::same($expected,$value[$key],'typed vector '.$key);
    }
    Assert::same(['SUPPORT'=>1,'CONTRARY'=>1,'UNKNOWN'=>1],$value['polarity_counts'],
        'contrary and unknown labels stay itemized');
    Assert::same('NOT_ASSESSED',$value['commercial_materiality'],
        'source structural change magnitude not business importance');
    Assert::same('UNKNOWN',$value['business_importance_basis'],
        'missing business weights remain unknown, never zero');
    Assert::same(3,$value['density_numerator'],'observation density numerator exact');
    Assert::same(82800,$value['density_denominator_seconds'],
        'Stockholm DST means 23-hour day denominator, not 86400');
    Assert::same($value['measure_digest'],DynamicMeasures::measure($input)->value()['measure_digest'],
        'same metadata yields identical measures digest');
    $input['importance_weights']=['profile_weight_basis_points'=>5000,
        'entity_role_weight_basis_points'=>8000];
    $weighted=DynamicMeasures::measure($input)->value();
    Assert::same(4000,$weighted['business_importance_basis_points'],
        'explicit integer basis-point weight product (NOT accepted importance)');
    Assert::same('CALLER_SUPPLIED_WEIGHTS_UNVERIFIED',$weighted['business_importance_basis'],
        'importance remains explicitly unverified');
    Assert::same(['ref_two'],$weighted['contrary_or_conflict_refs'],
        'weighting cannot erase contrary observation');
}
$input['importance_weights']=['profile_weight_basis_points'=>10001,
    'entity_role_weight_basis_points'=>8000];
Assert::same('M17_IMPORTANCE_WEIGHT_INVALID',DynamicMeasures::measure($input)->reasonCode(),
    'over-10000 weight refused, no float coercion');
$input['importance_weights']=null;
$input['window']['duration_seconds']=86400;
Assert::same('M17_MEASURES_INPUT_INVALID',DynamicMeasures::measure($input)->reasonCode(),
    'rewritten DST duration with stale window hash refused');

foreach ($vector['windows'] as $w) {
    $z=WindowEngine::assign(['kind'=>$w['kind'],'observation_at_utc'=>$w['observation_at_utc'],
        'calendar_config'=>$w['timezone']===null?null:[
            'timezone'=>$w['timezone'],'tzdb_version'=>timezone_version_get(),
        ]]);
    Assert::true($z->isOk(),'vector window constructs '.$w['case']);
    if ($z->isOk()) {
        foreach (['start_inclusive_utc','end_exclusive_utc','duration_seconds'] as $field) {
            Assert::same($w[$field],$z->value()[$field],$w['case'].' canonical boundary '.$field);
        }
    }
}
foreach (['SOURCE_DIFF','COMPANY_SIGNAL'] as $class) {
    $resolution=ProfileResolver::resolve(['scope'=>$vector['scope'],'data_class'=>$class,
        'evaluation_time_utc'=>'2026-06-01T00:00:00Z','candidates'=>[],'floors'=>[]]);
    Assert::true($resolution->isOk(),'deterministic module default for '.$class);
    Assert::same($vector[strtolower($class).'_profile_defaults'] ?? ($class==='SOURCE_DIFF'
        ? $vector['source_diff_profile_defaults'] : $vector['company_signal_profile_defaults']),
        array_intersect_key($resolution->value()['resolved'],
            $class==='SOURCE_DIFF'?$vector['source_diff_profile_defaults']:$vector['company_signal_profile_defaults']),
        'offline profile matches exact source-derived canonical default '.$class);
}
