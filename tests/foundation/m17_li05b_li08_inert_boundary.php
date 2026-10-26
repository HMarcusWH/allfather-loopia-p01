<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\{ReviewDue,HistoricalCaptureProbe};

$root=dirname(__DIR__,2);
Assert::same('li08.review-due-candidate.v1',ReviewDue::VERSION,
    'U05 not ratified; offline due candidate version is frozen');
Assert::same('li05b.native-history-preflight-candidate.v1',HistoricalCaptureProbe::VERSION,
    'U03 not ratified; source preflight remains a candidate');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'staging still cannot auto-install a proposed G21 schema');
Assert::false(is_file($root.'/database/migrations/290_m17_review_due.sql'),
    'an offline review-due suggestion cannot become a scheduled job table');
foreach (['ReviewDue.php','HistoricalCaptureProbe.php'] as $name) {
    $source=(string)file_get_contents($root.'/app/Substrate/M17/'.$name);
    Assert::false(str_contains($source,'curl_exec(')
        || str_contains($source,'INSERT INTO')
        || str_contains($source,'DELETE FROM')
        || str_contains($source,'UPDATE ')
        || str_contains($source,'OpenAIResponsesTransport')
        || str_contains($source,'ModelExecutor')
        || str_contains($source,'sendNotification'),
        'detached U03/U05 module does not create network/DB mutation or model authority: '.$name);
}
$due=(string)file_get_contents($root.'/app/Substrate/M17/ReviewDue.php');
foreach(['new_source_fetch_authorized','model_call_authorized','scheduler_action_authorized',
         'customer_output_authorized','U05_policy_adopted'] as $marker) {
    Assert::contains("'".$marker."'",$due,'review-due result contains explicit non-authority: '.$marker);
}
$preflight=(string)file_get_contents($root.'/app/Substrate/M17/HistoricalCaptureProbe.php');
foreach (['AccessGuard','SourcePolicy::compile','tool_execution_outcomes',
          'monitor_capture_payloads','EvidenceStore','U03_historical_use_admitted'] as $marker) {
    Assert::contains($marker,$preflight,'native historical preflight is owner-bound and cannot mint use authority: '.$marker);
}
