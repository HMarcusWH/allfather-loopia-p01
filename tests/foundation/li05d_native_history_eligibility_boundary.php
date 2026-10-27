<?php

declare(strict_types=1);

use Allfather\Loopia\Workflow\NativeCaptureHistoryCandidate;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
Assert::same('li05d.owner-native-history-preflight.v1',
    NativeCaptureHistoryCandidate::VERSION,
    'owner-native preflight contract is versioned without adoption');

$source=(string)file_get_contents(
    $root.'/app/Workflow/NativeCaptureHistoryCandidate.php');
foreach([
    'AccessGuard','HistoricalCaptureProbe','HistoryReviewBasis::freeze',
    "'LI05E_SCHEDULE_OWNER_READER_REQUIRED'",
    "'U03_historical_use_admitted'=>false",
    "'U06_model_context_authorized'=>false",
    "'M17_input_set_authorized'=>false",
    "'scheduler_action_authorized'=>false",
    "'source_bytes_returned'=>false",
    "'origin_group'=>'UNKNOWN'",
    "'semantic_polarity'=>'UNKNOWN'",
] as $required){
    Assert::contains($required,$source,'owner-source law preserved: '.$required);
}
foreach([
    'curl_exec(', 'INSERT INTO','UPDATE ', 'DELETE FROM',
    'ModelExecutor','->send(', '->post(', 'mail(', 'SourceAdmission::evaluate(',
    'new \PDO(', '->persistCandidate(', '->recordDueCandidate(',
    '->recordCandidate(', 'file_put_contents(',
] as $forbidden){
    Assert::false(str_contains($source,$forbidden),
        'native historical preview has no writer/network/provider/scheduler: '.$forbidden);
}
foreach([
    'database/migrations/270_history_review_bases.sql',
    'database/migrations/280_m17_temporal_memory.sql',
    'database/migrations/290_history_review_policy.sql',
    'ops/history/preview.php',
    'ops/history/due.php',
    'ops/temporal/run.php',
] as $forbidden){
    Assert::false(is_file($root.'/'.$forbidden),
        'source-backed preflight does not activate candidate migration/runtime: '.$forbidden);
}
$http=(string)file_get_contents($root.'/public_html/index.php');
Assert::false(str_contains($http,'NativeCaptureHistoryCandidate'),
    'no unauthorised HTTP route reads source-based history or raw bytes');
