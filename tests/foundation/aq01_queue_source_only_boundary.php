<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Jobs\{JobMachine,SchedulerRunner,TemporalRollupHandler,HistoryReviewDueHandler};
use Allfather\Loopia\Workflow\{ScheduleDispatcher,HistoryReviewQueue,HistoryReviewQueueStore};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$packages=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='AQ-01'));
Assert::same(1,count($packages),'one AQ01 package in frozen plan');
Assert::same('BUILT_INERT',$packages[0]['build_status'],
    'AQ01 queue source candidate is never a live scheduler');
foreach(['native_permission','live_migration','external_effects',
         'customer_visibility','production_activation'] as $flag){
    Assert::same(false,$packages[0][$flag],
        'AQ01 cannot grant '.$flag);
}
Assert::same(3,ScheduleDispatcher::MAX_WAITING,
    'original three-unread changed-run observer backpressure remains');
Assert::same(50,SchedulerRunner::MAX_JOBS_PER_TICK,
    'existing scheduler job cap unchanged');
Assert::true(isset(JobMachine::TYPE_TRANSITIONS['MONITOR_STEP']),
    'original monitor observer scheduling still has its own closed job type');
Assert::false(isset(JobMachine::TYPE_TRANSITIONS['HISTORY_REVIEW_DUE']),
    'no runtime queue job registration until U05/U08');
Assert::same(64,HistoryReviewQueue::MAX_MEMBERS,
    'bounded queue source candidate count exact');
Assert::same('aq01.inert-history-review-work.v1',HistoryReviewQueue::VERSION,
    'versioned queue candidate identity');
Assert::same('aq01.disposable-work-candidate-store.v1',HistoryReviewQueueStore::VERSION,
    'CLI-only queue candidate store identity');
Assert::same('aq01.inert-due-handler.v1',HistoryReviewDueHandler::VERSION,
    'pure review handler not live cron/worker');
Assert::same('aq01.inert-temporal-rollup-handler.v1',TemporalRollupHandler::VERSION,
    'pure temporal handler not live cron/worker');
Assert::true(is_file($root.'/docs/longitudinal/proposals/300_history_review_queue_jobs_CANDIDATE.sql'),
    'proposed 300 remains outside native DB migrations');
Assert::false(is_file($root.'/database/migrations/300_history_review_queue_jobs.sql'),
    'no 300 family automatically installed');
Assert::false(is_file($root.'/ops/history/due.php'),
    'forbidden operational due task remains absent');
