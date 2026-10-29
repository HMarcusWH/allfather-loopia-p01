<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Product\P01\ObservationCoverageReceipt;
use Allfather\Loopia\Tests\{Assert,Harness,ScheduleFixture as S,Seed};
use Allfather\Loopia\Workflow\ScheduleHistoryCoverage;

/**
 * Real native admitted schedule + M02 scoped occurrence/RunJournal/M05
 * proof, but with SYNTHETIC source transport. No U03 historical source use,
 * publisher/origin certification, provider model, or customer release.
 */
$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-03-02T09:00:00.000Z');
$sx=S::create($db,$clock,[
    'Seminar announced for November.',
    'Seminar announced for December.',
    'Seminar announced for December.',
    'Seminar announced for December.',
]);
$scope=$sx['scope'];
$role=Seed::role($db,$clock,'ob03-schedule-coverage-reader',[
    'competition_monitoring.read','evidence.read',
]);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],
    $scope['operator_id'],$role['role_id']);
$actor=[
    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id'],
];
$definition=S::input($sx,$clock);
$definition['valid_from']=substr($definition['valid_from'],0,19).'.000Z';
$admitted=S::admitted($sx,$definition);
$scheduleVersion=$admitted['monitor_schedule_version_id'];
$start=substr($definition['valid_from'],0,19).'Z';
$end=Clock::format(Clock::parse($definition['valid_from'])->modify('+7 days'));
$period=[
    'start_inclusive_utc'=>$start,
    'end_exclusive_utc'=>substr($end,0,19).'Z',
];
$owner=new ScheduleHistoryCoverage($db,$clock,$sx['schedules']);
$cut=static fn():string => substr($clock->now(),0,19).'Z';
$get=static function()use($owner,$actor,$sx,$period,$cut,$scheduleVersion){
    return ObservationCoverageReceipt::fromCurrentOwner(
        $owner,$actor,$sx['competitor_id'],$period,$cut(),$scheduleVersion,
    );
};
$clock->advanceMs(1000);
$before=$get();
Assert::true($before->isOk(),'admitted plan before first due capture returns a derived receipt'
    . ($before->isOk()?'':': '.$before->reasonCode()));
if(!$before->isOk())return;
Assert::same('HOLD_GAPPED_OR_INCOMPLETE',$before->value()['coverage_state'],
    'one admitted but not yet captured slot is a gap, not NO_CHANGE');
Assert::same(1,$before->value()['gap_counts']['EXPECTED_NOT_FETCHED_UNKNOWN'],
    'one currently unaccounted scheduled slot is explicitly unknown');
Assert::same(0,$before->value()['captured_unchanged'],
    'missing a first capture is never a structural unchanged result');
Assert::same('CURRENT_OWNER_SCHEDULE_OCCURRENCE_RUNJOURNAL_VERIFIED',
    $before->value()['authority_basis'],'coverage receipt cites real owner path');
Assert::same(false,$before->value()['u03_historical_reuse_admitted'],
    'current schedule read does not authorize old source reuse');

$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'real admitted first schedule occurrence executes fixture capture');
$clock->advanceMs(1000);
$first=$get();
Assert::true($first->isOk(),'one real native-schedule/M05 baseline receipt');
if(!$first->isOk())return;
Assert::same('COMPLETE_OWNER_CAPTURE_COVERAGE',$first->value()['coverage_state'],
    'the first slot is complete only after actual captured P01/M05 evidence');
Assert::same(1,$first->value()['captured_slots'],'one baseline snapshot counted');
Assert::same(1,$first->value()['baseline_captures'],'first observation is a baseline, not event');
Assert::same(0,$first->value()['missing_or_unknown_slots'],'owner-verified first slot is complete');
Assert::same(null,$first->value()['distinct_real_world_event_count'],
    'completed scheduled slot is not a proved unique event');
Assert::true(ObservationCoverageReceipt::verify($first->value()),
    'receipt canonical hash binds real owner schedule lineage');

$clock->advanceMs(86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'second actual admitted occurrence captures changed source');
$clock->advanceMs(1000);
$changed=$get();
Assert::true($changed->isOk(),'second schedule source result can be read');
Assert::same(1,$changed->value()['captured_changed'],
    'changed structural capture is not automatically commercial finding');
Assert::same(0,$changed->value()['captured_unchanged'],'no artificial NO_CHANGE on changed source');

$clock->advanceMs(86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'third real scheduled observation is captured');
$clock->advanceMs(1000);
$unchanged=$get();
Assert::true($unchanged->isOk(),'third schedule receipt with actual unchanged capture');
Assert::same(1,$unchanged->value()['captured_unchanged'],
    'only actual returned P01/M05 unchanged snapshot counts as unchanged');
Assert::same(3,$unchanged->value()['captured_slots'],
    'baseline+changed+unchanged are all real scheduled captures');

$clock->advanceMs(2*86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'latest-only schedule records an outage skip');
$clock->advanceMs(1000);
$missed=$get();
Assert::true($missed->isOk(),'gapped schedule yields derived partial coverage');
Assert::same('HOLD_GAPPED_OR_INCOMPLETE',$missed->value()['coverage_state'],
    'a missed expected slot cannot be restored by later successful capture');
Assert::same(1,$missed->value()['gap_counts']['EXPLICITLY_SKIPPED'],
    'exact owner skipped occurrence count retained');
Assert::same(1,$missed->value()['captured_unchanged'],
    'outage does not fabricate a second unchanged observation');
Assert::same(false,$missed->value()['customer_release_authorized'],
    'complete or incomplete coverage grants no release');

$foreign=$actor;$foreign['actor_id']=Ids::new();
Assert::same('LI05E_COVERAGE_SCOPE_DENIED',
    ObservationCoverageReceipt::fromCurrentOwner(
        $owner,$foreign,$sx['competitor_id'],$period,$cut(),$scheduleVersion
    )->reasonCode(),'another principal cannot reuse the M02 schedule owner proof');

S::value($sx['schedules']->suspend($scope,$scheduleVersion,'OPERATOR_STAND_DOWN'));
Assert::same('LI05E_SCHEDULE_OWNER_NOT_CURRENT',$get()->reasonCode(),
    'a suspended owner schedule cannot be projected as current coverage');
Assert::same(0,$sx['model']->calls,'native schedule coverage path never calls fixture model');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'source/history/coverage tests never call paid model provider');
