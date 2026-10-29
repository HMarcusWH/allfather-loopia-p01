<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryReviewService,HistoryReviewJournal,HistoryReviewFactory};
use Allfather\Loopia\PromptOps\{HistoryReviewCandidateChecks,PromptBundleRegistry};

$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$packages=array_values(array_filter($manifest['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='AN-02'));
Assert::same(1,count($packages),'AN02 package identity exists once');
Assert::same('BUILT_INERT',$packages[0]['build_status'],
    'AN02 source-only workflow is not a real provider execution');
foreach(['native_permission','live_migration','external_effects',
         'customer_visibility','production_activation'] as $field){
    Assert::same(false,$packages[0][$field],
        'AN02 source candidate cannot silently grant '.$field);
}
Assert::same('an02.inert-history-review-run.v1',HistoryReviewService::VERSION,
    'review state machine contract version pinned');
Assert::same('an02.disposable-history-journal.v1',HistoryReviewJournal::VERSION,
    'family320 journal is test-only');
Assert::same('an02.local-synthetic-role-fixture.v1',HistoryReviewFactory::VERSION,
    'local role mock never becomes model provider');
Assert::same('an02.local-historical-analyst.v1',
    HistoryReviewCandidateChecks::ANALYST_VERSION,
    'closed analyst candidate schema pinned');
Assert::same('an02.local-historical-critic.v1',
    HistoryReviewCandidateChecks::CRITIC_VERSION,
    'closed critic candidate schema pinned');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/320_history_review_runs_CANDIDATE.sql'),
    'proposal family320 is not a native DB migration');
Assert::false(is_file($root.'/database/migrations/320_history_review_runs.sql'),
    'no live historical journal auto-migrates');
Assert::false(is_file($root.'/ops/history/review.php'),
    'no unratified production model review runner installed');
Assert::false(is_file($root.'/ops/history/replay.php'),
    'no unadmitted production journal replay endpoint installed');
foreach(['tests/support/An02MockReview.php',
    'app/PromptOps/bundles/history-review-v1.json',
    'ops/history/review-candidate.php',
    'ops/history/replay-candidate.php'] as $path){
    Assert::true(is_file($root.'/'.$path),'AN02 source-only fixture/CLI file: '.$path);
}
$roles=(string)file_get_contents($root.'/app/PromptOps/bundles/history-review-v1.json');
Assert::false(str_contains(
    (string)file_get_contents($root.'/app/PromptOps/PromptBundleRegistry.php'),
    'an02.history-review-v1.inactive',
),'local role bundle not registered with live provider PromptOps');
