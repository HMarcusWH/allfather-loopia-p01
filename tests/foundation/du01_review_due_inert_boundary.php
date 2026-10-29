<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryReviewPolicy,HistoryReviewDue};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$rows=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='DU-01'));
Assert::same(1,count($rows),'one DU01 package in build manifest');
Assert::same('BUILT_INERT',$rows[0]['build_status'],
    'DU01 policy/due code is source-only, not job authority');
foreach(['native_permission','live_migration','external_effects',
         'customer_visibility','production_activation'] as $flag){
    Assert::same(false,$rows[0][$flag],'DU01 cannot silently promote '.$flag);
}
Assert::same('du01.history-review-policy.v2',HistoryReviewPolicy::VERSION,
    'new v2 policy is versioned separately from M17 v1');
Assert::same('du01.review-due-candidate.v2',HistoryReviewDue::VERSION,
    'new quiet due result version does not overwrite old v1');
Assert::true(is_file($root.'/docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql'),
    'review policy remains candidate-only family 290');
Assert::false(is_file($root.'/database/migrations/290_history_review_policy.sql'),
    'no live policy schema migration allowed');
$source=(string)file_get_contents($root.'/app/Workflow/HistoryReviewDueStore.php');
Assert::true(str_contains($source,'recordDueV2Candidate'),
    'v2 journal uses existing 290 append-only owner');
Assert::true(str_contains($source,'forDisposableTest'),
    'v2 journal cannot add an active factory');
Assert::true(str_contains($source,'recordDueCandidate('),
    'v1 due candidate remains separately replayable');
