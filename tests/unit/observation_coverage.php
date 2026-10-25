<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\ObservationCoverage;
use Allfather\Loopia\Tests\Assert;

$slots=[
    ['slot_index'=>0,'outcome'=>'CAPTURED_BASELINE','skipped_slots'=>0],
    ['slot_index'=>4,'outcome'=>'CAPTURED_CHANGED','skipped_slots'=>3],
    ['slot_index'=>7,'outcome'=>'REFUSED','skipped_slots'=>2],
    ['slot_index'=>9,'outcome'=>'CAPTURED_UNCHANGED','skipped_slots'=>1],
];
$result=ObservationCoverage::summarize('schedule-version-01',10,$slots);
Assert::true($result->isOk(),'bounded ten-slot coverage is representable');
if (!$result->isOk()) return;
$r=$result->value();
Assert::same(10,$r['planned_slots'],'explicit planned denominator kept');
Assert::same(4,$r['accounted_occurrences'],'four actual occurrence outcomes, not 10 captures');
Assert::same(3,$r['captured_slots'],'three successful captures');
Assert::same(1,$r['baseline_captures'],'baseline is coverage not a new event');
Assert::same(1,$r['structurally_changed_captures'],'only recorded structural changes count');
Assert::same(1,$r['structurally_unchanged_captures'],'identical page contributes coverage not activity');
Assert::same(1,$r['refused_slots'],'refusal never becomes no-change');
Assert::same(6,$r['explicit_skipped_slots'],'backpressure/skipped slots preserved');
Assert::same(0,$r['unreported_slots'],'fully enumerated calendar denominator');
Assert::same(7,$r['not_successfully_observed_slots'],'skips and refusal are missing observations');
Assert::same(['numerator'=>3,'denominator'=>10],$r['successful_capture_rate'],'rate denominator stays the schedule');
Assert::same(['numerator'=>1,'denominator'=>3],$r['structural_change_rate_within_captures'],'structural change uses captured denominator only');
Assert::same(null,$r['distinct_real_world_event_count'],'changed captures are not unique independent events');
Assert::same(null,$r['corroboration_count'],'no independent source claim');
Assert::same('NOT_ASSESSED',$r['business_materiality'],'not an economic assessment');
Assert::same(false,$r['capture_or_analysis_authorized'],'coverage cannot schedule work');

$unsorted=ObservationCoverage::summarize('schedule-version-01',10,[$slots[2],$slots[0],$slots[3],$slots[1]]);
Assert::true($unsorted->isOk(),'unsorted events accepted');
Assert::same($r,$unsorted->value(),'coverage is deterministic under occurrence permutation');

$unknown=ObservationCoverage::summarize('schedule-2',5,[
    ['slot_index'=>0,'outcome'=>'CAPTURED_BASELINE','skipped_slots'=>0],
    ['slot_index'=>3,'outcome'=>'FAILED','skipped_slots'=>1],
]);
Assert::true($unknown->isOk(),'unknown slots are tracked separately');
Assert::same(2,$unknown->value()['unreported_slots'],'unreported gap and trailing slot are not silently skipped');
Assert::same(1,$unknown->value()['explicit_skipped_slots'],'only one skipped slot was evidenced');
Assert::same(1,$unknown->value()['failed_slots'],'failed capture preserved');

$zero=ObservationCoverage::summarize('schedule-empty',0,[]);
Assert::true($zero->isOk(),'empty plan can be represented without division by zero');
Assert::same(null,$zero->value()['successful_capture_rate'],'no fabricated 100% success for empty denominator');
Assert::same(null,$zero->value()['structural_change_rate_within_captures'],'no events gives unknown rate');

$pending=ObservationCoverage::summarize('schedule-pending',1,[['slot_index'=>0,'outcome'=>'PENDING','skipped_slots'=>0]]);
Assert::same(0,$pending->value()['captured_slots'],'queued is not captured');
Assert::same(1,$pending->value()['pending_slots'],'pending state retained');
Assert::same(1,$pending->value()['not_successfully_observed_slots'],'unattempted is not no change');

$duplicate=$slots; $duplicate[]=['slot_index'=>0,'outcome'=>'FAILED','skipped_slots'=>0];
Assert::same('LI04_COVERAGE_DUPLICATE_SLOT',ObservationCoverage::summarize('plan',10,$duplicate)->reasonCode(),'slot cannot be counted twice');
$overlap=$slots;$overlap[1]['skipped_slots']=4;
Assert::same('LI04_COVERAGE_SKIPPED_SLOTS_OVERLAP',ObservationCoverage::summarize('plan',10,$overlap)->reasonCode(),'reported skip cannot cover an already-occupied slot');
$unknownOutcome=$slots;$unknownOutcome[0]['outcome']='NO_BUSINESS_CHANGE';
Assert::same('LI04_COVERAGE_SLOT_INVALID',ObservationCoverage::summarize('plan',10,$unknownOutcome)->reasonCode(),'no business decision can be smuggled into capture outcome');
$badField=$slots;$badField[0]['model_calls']=100;
Assert::same('LI04_COVERAGE_SLOT_INVALID',ObservationCoverage::summarize('plan',10,$badField)->reasonCode(),'slot cannot contain model permissions');
Assert::same('LI04_COVERAGE_PLAN_INVALID',ObservationCoverage::summarize('plan',10001,[])->reasonCode(),'plan capped');
Assert::same('LI04_COVERAGE_PLAN_INVALID',ObservationCoverage::summarize('plan',-1,[])->reasonCode(),'negative schedule refused');
Assert::same('LI04_COVERAGE_OCCURRENCES_INVALID',ObservationCoverage::summarize('plan',0,$slots)->reasonCode(),'captures without a plan denied');
Assert::same('LI04_COVERAGE_PLAN_INVALID',ObservationCoverage::summarize('',2,[])->reasonCode(),'missing versioned schedule identity denied');
