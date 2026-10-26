<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\ProfileResolver;
use Allfather\Loopia\Substrate\M17\Vocabulary;
use Allfather\Loopia\Substrate\M17\WindowEngine;
use Allfather\Loopia\Tests\Assert;

$v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/temporal_contract_vectors.json'), true, 64, JSON_THROW_ON_ERROR);
$scope=$v['scope'];
$at='2026-06-01T00:00:00Z';
$input=static fn(string $class,array $candidates=[],array $floors=[]):array=>[
    'scope'=>$scope,'data_class'=>$class,'evaluation_time_utc'=>$at,
    'candidates'=>$candidates,'floors'=>$floors,
];
$candidate=static function (string $name,string $level,array $changes,?string $reason=null,?string $expires=null) use($scope):array {
    return ['profile_id'=>$name,'level'=>$level,'scope'=>$scope,'data_class'=>'SOURCE_DIFF',
        'status'=>'ACTIVE','expires_at_utc'=>$expires,'reason_code'=>$reason,'changes'=>$changes];
};
$default=ProfileResolver::resolve($input('SOURCE_DIFF'));
Assert::true($default->isOk(),'default source diff resolves deterministically');
Assert::same($v['source_diff_profile_defaults'],array_intersect_key($default->value()['resolved'],
    $v['source_diff_profile_defaults']),'source diff 14/90 conservative profile');
$company=ProfileResolver::resolve($input('COMPANY_SIGNAL'));
Assert::same($v['company_signal_profile_defaults'],array_intersect_key($company->value()['resolved'],
    $v['company_signal_profile_defaults']),'company signal 30/180 calendar default');
Assert::same('OFFLINE_NOT_ADMITTED',$default->value()['status'],'pure profile never owner-admitted');
Assert::same(false,$default->value()['resolved']['release_eligible'],'M17 has no release action');
Assert::same(false,$default->value()['resolved']['customer_output_eligible'],'M17 has no customer path');
Assert::true(ProfileResolver::verify($default->value())->isOk(),'default profile digest internally replayable');
$wide=$candidate('widen','WORKSPACE',['day_until_age_days'=>7,
    'retention_expectation_days'=>200,'minimum_lineage'=>'REPRESENTATIVE_REFS_PLUS_COUNTS',
    'aggressiveness'=>'HIGH']);
$w=ProfileResolver::resolve($input('SOURCE_DIFF',[$wide]))->value();
Assert::same(3,count($w['rejected_widenings']),'shortening fine resolution/retention/lineage refused');
Assert::same(14,$w['resolved']['day_until_age_days'],'widening fine threshold is rejected');
Assert::same(365,$w['resolved']['retention_expectation_days'],'widening retention is rejected');
Assert::same('EVERY_INPUT_REF',$w['resolved']['minimum_lineage'],'full lineage survives proposed widening');
Assert::same('PROFILE_WIDENING_REJECTED',$w['rejected_widenings'][0]['reason_code'],
    'widening refusal carries exact code');
$goodA=$candidate('tenant-A','TENANT',['day_until_age_days'=>30,
    'week_until_age_days'=>120,'retention_expectation_days'=>600,'aggressiveness'=>'MEDIUM']);
$goodB=$candidate('tenant-B','TENANT',['day_until_age_days'=>45,
    'retention_expectation_days'=>700,'aggressiveness'=>'LOW']);
$good=ProfileResolver::resolve($input('SOURCE_DIFF',[$goodB,$wide,$goodA]));
Assert::true($good->isOk(),'narrow-only profiles can be folded under default');
if ($good->isOk()) {
    $r=$good->value();
    Assert::same(45,$r['resolved']['day_until_age_days'],'narrowest same-level fine hold wins');
    Assert::same(120,$r['resolved']['week_until_age_days'],'week hold extends, never shortens');
    Assert::same(700,$r['resolved']['retention_expectation_days'],'longest retention wins');
    Assert::same('LOW',$r['resolved']['aggressiveness'],'least aggressive candidate wins');
    Assert::true(count($r['same_level_conflicts'])>=2,'same-level conflict records explicit fields');
    Assert::same(false,$r['owner_input_verified'],'candidate profiles are not authoritative M13 inputs');
    Assert::same($r['resolution_digest'],ProfileResolver::resolve($input('SOURCE_DIFF',[
        $goodA,$goodB,$wide]))->value()['resolution_digest'],
        'profile candidate order cannot modify digest or narrowest-wins outcome');
}
$floor=static fn (string $id,string $effect,?int $minimum=null):array => [
    'floor'=>$id,'effect'=>$effect,'basis_ref_safe'=>'synthetic_policy_ref',
    'minimum_retention_days'=>$minimum,
];
$held=ProfileResolver::resolve($input('SOURCE_DIFF',[$goodA],[
    $floor('LEGAL_HOLD','FORCE_EVENT_LEVEL'),$floor('PRIVACY_RETENTION','FORCE_RETENTION_FLOOR',900),
    $floor('EVIDENCE_LINEAGE_MINIMUM','REQUIRE_FULL_LINEAGE'),
]));
Assert::true($held->isOk(),'valid law-floor metadata accepted as offline descriptions');
if ($held->isOk()) {
    $r=$held->value();
    Assert::same(true,$r['event_level_hold'],'audit/legal floor retains event level');
    Assert::same(900,$r['resolved']['retention_expectation_days'],'privacy retention floor applied LAST');
    Assert::same('LOCKED_LAW',$r['field_provenance']['retention_expectation_days'],
        'retention provenance records non-overridable floor');
    Assert::same(3,count($r['applied_floor_candidates']),'all candidate law floors explicitly recorded');
    $window=WindowEngine::choose(['data_class'=>'SOURCE_DIFF','observation_at_utc'=>'2026-01-01T00:00:00Z',
        'evaluation_time_utc'=>$at,'profile_candidate'=>$r,'volatility_level'=>'LOW']);
    Assert::same('M17_HOLD_REQUIRES_UNIMPLEMENTED_EVENT_WINDOW',$window->reasonCode(),
        'legal event-level hold blocks using a lossy DAY/WEEK/MONTH window');
}
Assert::same('M17_PROFILE_FLOOR_EFFECT_INVALID',ProfileResolver::resolve($input('SOURCE_DIFF',[],[
    $floor('CUSTOMER_OUTPUT_CEILING','FORCE_RETENTION_FLOOR',365)
]))->reasonCode(),'miscast customer floor cannot rewrite retention');
$unsupported=ProfileResolver::resolve($input('UNRECOGNIZED_FUTURE_CLASS'));
Assert::true($unsupported->isOk(),'unknown class produces conservative non-executable posture');
Assert::same('CONSERVATIVE_DEFAULT_NOT_EXECUTABLE',$unsupported->value()['status'],
    'unknown class does not accidentally inherit source-diff runtime');
Assert::same('EVERY_INPUT_REF',$unsupported->value()['resolved']['minimum_lineage'],
    'unknown class must never drop refs');
Assert::same('NONE',$unsupported->value()['resolved']['aggressiveness'],
    'unknown class forbids lossy processing');
Assert::same('M17_PROFILE_UNSUPPORTED_CLASS_NOT_OVERRIDEABLE',
    ProfileResolver::resolve($input('AUDIT_FINDING',[$candidate('spoof','TENANT',['aggressiveness'=>'LOW'])]))->reasonCode(),
    'inert classes cannot enable profiles by caller input');
$inert=$candidate('not-yet-m13','MODALITY_DEFAULT',['retention_expectation_days'=>800]);
Assert::same('M17_PROFILE_LEVEL_INERT',ProfileResolver::resolve($input('SOURCE_DIFF',[$inert]))->reasonCode(),
    'M13 modality default is not adopted in LI06A');
$foreign=$candidate('foreign','TENANT',['day_until_age_days'=>40]);
$foreign['scope']['tenant_id']='another_tenant';
Assert::same('M17_PROFILE_CROSS_SCOPE_REFUSED',ProfileResolver::resolve($input('SOURCE_DIFF',[$foreign]))->reasonCode(),
    'foreign-scope profiles fail entire operation');
$operator=$candidate('operator','OPERATOR_OVERRIDE',['day_until_age_days'=>46],'REASON_APPROVED',
    '2026-06-01T00:00:00Z');
Assert::same(['operator'],ProfileResolver::resolve($input('SOURCE_DIFF',[$operator]))->value()['ignored_profile_ids'],
    'operator override expires at exact as-of instant');
$operator['expires_at_utc']=null;
Assert::same('M17_PROFILE_OVERRIDE_CONTRACT_INVALID',ProfileResolver::resolve($input('SOURCE_DIFF',[$operator]))->reasonCode(),
    'operator override without expiry refuses');
$operator=$candidate('operator','OPERATOR_OVERRIDE',['day_until_age_days'=>48],null,'2026-06-04T00:00:00Z');
Assert::same('M17_PROFILE_OVERRIDE_CONTRACT_INVALID',ProfileResolver::resolve($input('SOURCE_DIFF',[$operator]))->reasonCode(),
    'operator override without safe reason refuses');
$spoof=$candidate('spoof','TENANT',['customer_output_eligible'=>true]);
Assert::same('M17_PROFILE_CANDIDATE_INVALID',ProfileResolver::resolve($input('SOURCE_DIFF',[$spoof]))->reasonCode(),
    'tenant-supplied customer output authorization cannot be resolved');
$spoof=$candidate('spoof','TENANT',['aggressiveness'=>'UNKNOWN']);
Assert::same('M17_PROFILE_CANDIDATE_INVALID',ProfileResolver::resolve($input('SOURCE_DIFF',[$spoof]))->reasonCode(),
    'free-text aggressiveness refused');
$invalid=$good->value();$invalid['historical_reuse_admitted']=true;
Assert::same('M17_PROFILE_DIGEST_OR_SHAPE_INVALID',ProfileResolver::verify($invalid)->reasonCode(),
    'digest shape refuses attempted retrospective admission');
$invalid=$good->value();$invalid['resolved']['retention_expectation_days']=7;
Assert::same('M17_PROFILE_DIGEST_MISMATCH',ProfileResolver::verify($invalid)->reasonCode(),
    'changing pinned profile without recomputed digest refused');
