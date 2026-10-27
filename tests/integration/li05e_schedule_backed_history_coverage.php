<?php

declare(strict_types=1);

/**
 * LI-05E: genuine admitted schedule → immutable slot/outcome/run chain →
 * private P01/M05 captures → bounded history basis, WITHOUT U03 admission.
 *
 * Existing ScheduleFixture uses the real scheduler, M02 service identity,
 * M06/P01 tool and P01 Monitor; only the source response is synthetic.
 */
require_once __DIR__.'/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,ScheduleFixture as S,Seed};
use Allfather\Loopia\Workflow\{
    HistoryM17CandidateBridge,NativeCaptureHistoryCandidate,ScheduleHistoryCoverage
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-03-02T09:00:00.000Z');
$sx=S::create($db,$clock,[
    'Seminar in November.','Seminar in December.',
    'Seminar in December.','Seminar in December.','Seminar in December.',
]);
$scope=$sx['scope'];
$readRole=Seed::role($db,$clock,'li05e-history-reader',[
    'competition_monitoring.read','evidence.read',
]);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],
    $scope['operator_id'],$readRole['role_id']);
$actor=[
    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id'],
];
$definition=S::input($sx,$clock);
$admitted=S::admitted($sx,$definition);
$scheduleVersion=$admitted['monitor_schedule_version_id'];
$competitor=$sx['competitor_id'];
$question=\Allfather\Loopia\Core\Ids::new();
$start=substr($definition['valid_from'],0,19).'Z';
$end=Clock::format(Clock::parse($definition['valid_from'])->modify('+7 days'));
$period=[
    'start_inclusive_utc'=>$start,
    'end_exclusive_utc'=>substr($end,0,19).'Z',
];
$ledger=new ScheduleHistoryCoverage($db,$clock,$sx['schedules']);
$native=new NativeCaptureHistoryCandidate(
    $db,$clock,$sx['evidence_store'],$ledger,
);
$cut=static fn():string=>substr($clock->now(),0,19).'Z';
$known=static function()use($native,$actor,$competitor,$question,$period,$cut,$scheduleVersion):array{
    $out=$native->preview($actor,$competitor,$question,$period,$cut(),32,$scheduleVersion);
    Assert::true($out->isOk(),'schedule-backed owner preflight: '.
        ($out->isOk()?'OK':$out->reasonCode()));
    if(!$out->isOk())throw new RuntimeException('LI05E owner preflight refused: '.$out->reasonCode());
    return $out->value();
};
$counts=static function(array $basis):array{
    return [$basis['selected_count'],$basis['coverage']['state'],
        $basis['selection_integrity']];
};

// The plan is admitted, but no slot yet: no invented completeness.
// At exactly the admission event's millisecond it is not *previously* known.
$clock->advanceMs(1000);
$zero=$ledger->inspect($actor,$competitor,$period,$cut(),$scheduleVersion);
$scheduleRead=S::value($sx['schedules']->current($scheduleVersion));
Assert::true($zero->isOk(),'admitted schedule can be read before first occurrence: '.
    ($zero->isOk()?'OK':$zero->reasonCode()).' bounds='.json_encode([
        'valid_from'=>$scheduleRead['valid_from'],
        'valid_until'=>$scheduleRead['valid_until'],
        'period'=>$period,
        'knowledge_cutoff'=>$cut(),
    ],JSON_THROW_ON_ERROR));
Assert::same(['HOLD_GAPPED_OR_INCOMPLETE',1],
    [$zero->value()['status'],$zero->value()['planned_slots']],
    'an overdue first slot without its capture remains incomplete');
Assert::same(1,$zero->value()['summary']['unreported_slots'],
    'an admitted but unexecuted slot is not silently recorded as NO_CHANGE');

// Baseline: one actual SOURCE_CAPTURED event + P01 snapshot + original M05 bytes.
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,count($tick['dispatch']['reserved']),'admitted service reserves first due slot');
Assert::same(1,$tick['advanced'],'scheduled fixture advances first P01 capture');
$clock->advanceMs(1000);
$first=$known();
Assert::same([1,'KNOWN','COMPLETE_WITHIN_INPUT'],
    $counts($first['history_basis']),
    'one authenticated scheduled capture covers one due slot');
Assert::same(true,$first['source_coverage_proven'],'exact owner slots proven');
Assert::same([1,1,0,0],
    [
        $first['schedule_coverage_report']['planned_slots'],
        $first['schedule_coverage_report']['summary']['captured_slots'],
        $first['schedule_coverage_report']['summary']['explicit_skipped_slots'],
        $first['schedule_coverage_report']['summary']['unreported_slots'],
    ],'slot accounting has one baseline and no gaps');
Assert::same(false,$first['U03_historical_use_admitted'],
    'proven capture cadence grants no historical reuse or model authority');
Assert::same('LI07C_PERIOD_WINDOW_MISMATCH',
    HistoryM17CandidateBridge::compose($first['history_basis'],'SOURCE_DIFF','DAY')->reasonCode(),
    'a complete schedule period cannot be silently narrowed to a day for M17');

// Day 2: a real changed capture is queued for a human, NOT analyzed.
$clock->advanceMs(86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'second P01 slot advanced under service');
$clock->advanceMs(1000);
$second=$known();
Assert::same([2,'KNOWN','COMPLETE_WITHIN_INPUT'],
    $counts($second['history_basis']),
    'baseline + changed capture cover exactly two expected slots');
Assert::same(1,
    $second['schedule_coverage_report']['summary']['structurally_changed_captures'],
    'changed capture counted without business materiality or model inference');
Assert::notSame($first['history_basis']['manifest_digest'],
    $second['history_basis']['manifest_digest'],
    'late known-at input creates a distinct immutable basis');
Assert::same(false,$second['U06_model_context_authorized'],
    'scheduled P01 observation never schedules inference');

// Day 3: unchanged page still counts as a real observation, not a missing slot.
$clock->advanceMs(86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'third observation captured');
$clock->advanceMs(1000);
$third=$known();
Assert::same([3,'KNOWN','COMPLETE_WITHIN_INPUT'],$counts($third['history_basis']),
    'baseline + changed + unchanged are all accounted for');
Assert::same(1,
    $third['schedule_coverage_report']['summary']['structurally_unchanged_captures'],
    'NO_CHANGE is a verified observation, never an absent slot');

// Outage: skip day 4; LATEST_ONLY reserves day 5 and records skipped_slots.
$clock->advanceMs(2*86_400_000);
$tick=$sx['runner']->tick(5,20000,300);
Assert::same(1,$tick['advanced'],'one latest-only occurrence after outage');
$clock->advanceMs(1000);
$gap=$known();
Assert::same('UNKNOWN',$gap['history_basis']['coverage']['state'],
    'one skipped slot prevents a complete-looking historical basis');
Assert::same('INSUFFICIENT_BASIS',$gap['history_basis']['selection_integrity'],
    'gap cannot permit governed reliance');
Assert::same(1,$gap['schedule_coverage_report']['summary']['explicit_skipped_slots'],
    'the skipped slot is explicitly preserved');
Assert::same('HOLD_GAPPED_OR_INCOMPLETE',$gap['schedule_coverage_report']['status'],
    'outage is visible with a distinct HOLD disposition');
Assert::same(false,$gap['source_coverage_proven'],
    'genuine source captures do not compensate for missed slots');

// Pending: reserve but do not execute next slot. No absence -> NO_CHANGE shortcut.
$clock->advanceMs(86_400_000);
$reserved=$sx['dispatcher']->dispatch();
Assert::same(1,count($reserved['reserved']),'next due slot reserved but not yet captured');
$clock->advanceMs(1000);
$pending=$known();
Assert::same('UNKNOWN',$pending['history_basis']['coverage']['state'],
    'a pending job never becomes a complete observation');
Assert::same(1,$pending['schedule_coverage_report']['summary']['pending_slots'],
    'pending is tracked distinctly from an unchanged capture');
Assert::true($pending['schedule_coverage_report']['summary']['not_successfully_observed_slots']>=2,
    'skipped plus pending slots both remain visible');

// Retrospective cutoff ignores future reservations, outcomes and captures.
$earlier=substr(Clock::format(Clock::parse($clock->now())->modify('-3 days')),0,19).'Z';
$asOf=$native->preview($actor,$competitor,$question,$period,$earlier,32,$scheduleVersion);
Assert::true($asOf->isOk(),'owner readback allows earlier historical cutoff');
Assert::true($asOf->value()['history_basis']['selected_count']<
    $pending['history_basis']['selected_count'],
    'later capture never backdates into an earlier observed history');

// The schedule must be current, and the principal must remain in scope.
S::value($sx['schedules']->suspend($scope,$scheduleVersion,'OPERATOR_STAND_DOWN'));
$stopped=$native->preview($actor,$competitor,$question,$period,$cut(),32,$scheduleVersion);
Assert::same('LI05E_SCHEDULE_OWNER_NOT_CURRENT',$stopped->reasonCode(),
    'suspended owner schedule cannot keep asserting completeness');
$foreign=$actor;$foreign['actor_id']=\Allfather\Loopia\Core\Ids::new();
Assert::same('LI05D_NATIVE_BASIS_NOT_AUTHORIZED',
    $native->preview($foreign,$competitor,$question,$period,$cut(),32,$scheduleVersion)->reasonCode(),
    'unregistered principal cannot inspect schedule or historical captures');
Assert::same(0,$sx['model']->calls,
    'scheduler and history coverage never call the fixture model');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'zero model-provider attempts in the entire schedule coverage test');
