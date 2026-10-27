<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoricalUseReviewPacket;

$root=dirname(__DIR__,2);
Assert::same('li05f.u03-owner-review-candidate.v1',HistoricalUseReviewPacket::VERSION,
    'U03 review packet remains an explicit non-admitted version');
foreach([
    'database/migrations/270_history_review_bases.sql',
    'database/migrations/280_m17_temporal_memory.sql',
    'database/migrations/290_history_review_policy.sql',
    'ops/history/reuse.php',
    'ops/history/due.php',
    'ops/temporal/run.php',
    'app/Workflow/HistoricalUseAdmission.php',
] as $path){
    Assert::false(is_file($root.'/'.$path),
        'R3 cannot activate source reuse, 270/280/290 DDL, or a runtime job: '.$path);
}
foreach([
    'docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
    'docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
    'docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
] as $path){
    Assert::true(is_file($root.'/'.$path),
        'all twelve families remain candidate-only outside live migrations: '.$path);
}
$source=(string)file_get_contents($root.'/app/Workflow/HistoricalUseReviewPacket.php');
foreach([
    'HistoryReviewBasis::verify',
    'ScheduleHistoryCoverage::VERSION',
    'LI05F_U03_HISTORICAL_USE_NOT_RATIFIED',
    'M05_SOURCE_OWNER_HISTORICAL_PURPOSE_AND_RETENTION',
    'U03_CANONICAL_ADOPTION_AND_EXACT_VECTOR_DIGEST',
    "'provider_disclosure_granted'=>false",
    "'runtime_M17_persistence_granted'=>false",
    "'model_context_granted'=>false",
] as $term){
    Assert::contains($term,$source,
        'R3 packet must name owner/proof gate, never invent rights: '.$term);
}
foreach([
    'curl_exec(', 'file_put_contents(', 'new \PDO(', 'INSERT INTO',
    'UPDATE ', 'DELETE FROM', 'SourceAdmission::evaluate(',
    'ModelExecutor', '->send(', 'mail(', '->persistCandidate(',
] as $danger){
    Assert::false(str_contains($source,$danger),
        'U03 review preparation has no network, SQL writes or model authority: '.$danger);
}
$console=(string)file_get_contents($root.'/public_html/index.php');
Assert::false(str_contains($console,'HistoricalUseReviewPacket'),
    'U03 review packet cannot be offered as a public HTTP source-use endpoint');
