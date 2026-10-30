<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewOutcomes,HistoryReviewFollowUp,
    HistoryReviewOutcomeStore,HistoryReviewAssessmentSuccessor
};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$items=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='AN-04'));
Assert::same(1,count($items),'AN04 one frozen package in plan');
Assert::same('BUILT_INERT',$items[0]['build_status'],
    'proposed later outcomes are not active execution corrections');
foreach([
    'native_permission','external_effects','live_migration',
    'customer_visibility','production_activation',
] as $key){
    Assert::same(false,$items[0][$key],
        'outcome candidate cannot authorize '.$key);
}
Assert::same('an04.provisional-history-outcome.v2',
    HistoryReviewOutcomes::VERSION,'report is not verified business outcome');
Assert::same('an04.inert-follow-up-condition.v2',
    HistoryReviewFollowUp::VERSION,'future work is an unadmitted proposal');
Assert::same('an04.disposable-longitudinal-outcomes.v1',
    HistoryReviewOutcomeStore::VERSION,
    'append-only family345 is a test-only metadata store');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/345_history_review_outcomes_CANDIDATE.sql'),
    '345 proposal exists under docs only');
Assert::false(is_file(
    $root.'/database/migrations/345_history_review_outcomes.sql'),
    'no family345 SQL added to native automatic migrations');
Assert::false(is_file($root.'/ops/history/outcomes.php'),
    'no real outcome writer/followup runner installed');
Assert::same('an04.assessment-successor-candidate.v1',
    HistoryReviewAssessmentSuccessor::VERSION,
    'later assessment successor is a distinct typed object, not an M10 correction');
