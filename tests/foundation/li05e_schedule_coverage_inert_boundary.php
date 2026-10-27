<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    NativeCaptureHistoryCandidate,ScheduleHistoryCoverage,ScheduleService,
};
use Allfather\Loopia\Product\P01\ObservationCoverage;

$root=dirname(__DIR__,2);
Assert::same('li05e.schedule-owner-coverage.v1',ScheduleHistoryCoverage::VERSION,
    'LI05E owner-native schedule coverage version is explicit');
Assert::same('li04.observation-coverage.pure.v1',ObservationCoverage::VERSION,
    'existing slot arithmetic contract is reused, not silently replaced');
Assert::same('OBSERVE_AND_QUEUE',ScheduleService::OBSERVATION_MODE,
    'no new unattended model purpose or automatic analysis');
foreach([
    'database/migrations/270_history_review_bases.sql',
    'database/migrations/280_m17_temporal_memory.sql',
    'database/migrations/290_history_review_policy.sql',
    'ops/history/coverage.php','ops/history/due.php',
    'ops/temporal/run.php',
] as $forbidden){
    Assert::false(is_file($root.'/'.$forbidden),
        'LI05E cannot activate production DB or background job: '.$forbidden);
}
$reader=(string)file_get_contents($root.'/app/Workflow/ScheduleHistoryCoverage.php');
$native=(string)file_get_contents($root.'/app/Workflow/NativeCaptureHistoryCandidate.php');
foreach([
    'ObservationCoverage::summarize',
    'ScheduleService::runKey',
    'RunJournal',
    'source_snapshots',
    'monitor_capture_payloads',
    'monitor_schedule_occurrences',
    'monitor_schedule_occurrence_outcomes',
    'ScheduleService::digest',
    "'U03_historical_use_admitted'=>false",
    "'model_call_authorized'=>false",
    "'scheduler_action_authorized'=>false",
] as $required){
    Assert::contains($required,$reader,
        'LI05E coverage remains owner/slot/run bound: '.$required);
}
foreach(['new \PDO(', 'curl_exec(', 'file_put_contents(', 'mail(',
    'ModelExecutor','->send(', '->persistCandidate(', '->recordDueCandidate(',
    'INSERT INTO','UPDATE ','DELETE FROM'] as $forbidden){
    Assert::false(str_contains($reader,$forbidden),
        'slot coverage may neither mutate state nor execute services: '.$forbidden);
}
Assert::contains('ScheduleHistoryCoverage $ownerCoverage',$native,
    'native basis accepts only an explicit typed schedule coverage authority');
Assert::contains("'LI05E_CAPTURE_SET_DOES_NOT_MATCH_OWNER_SLOTS'",$native,
    'completed owner slots must equal the native privately verified captures');
Assert::contains("'coverage'=>$basisCoverage",$native,
    'only owner-native schedule evidence changes the LI05A input coverage');
Assert::contains("'U03_historical_use_admitted'=>false",$native,
    'even complete schedule metadata cannot authorize historical use');
Assert::contains("'U06_model_context_authorized'=>false",$native,
    'even complete schedule metadata cannot activate a model context');
Assert::false(str_contains(
    (string)file_get_contents($root.'/public_html/index.php'),
    'ScheduleHistoryCoverage'),
    'LI05E has no public HTTP route');
