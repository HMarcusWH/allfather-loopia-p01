<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$r4=$root.'/ops/qa/m17/r4.php';
$old=$root.'/ops/qa/m17/restore.sh';
$workflow=$root.'/.github/workflows/m17-r4-candidate-restore.yml';
Assert::true(is_file($r4)&&is_file($old)&&is_file($workflow),
    'R4 qualification must include the helper, original harness and isolated workflow');
$code=(string)file_get_contents($r4);
$restore=(string)file_get_contents($old);
$ci=(string)file_get_contents($workflow);
foreach([
    'M17Qa::db()','M17Qa::manifest',
    'HistoryReviewBasis::freeze',
    'HistoryReviewBasisStore::forDisposableTest',
    'HistoryReviewDueStore::forDisposableTest',
    'EvidenceSpine','EvidenceStore',
    'm17_qa_run_identity',
    'R4_RESTORED_M05_PRIVATE_BYTES_FAILED',
    "'U03_historical_use_admitted'=>false",
    "'U05_policy_adopted'=>false",
] as $needle) {
    // The QA server marker lives in the reused M17Qa::db() implementation.
    $haystack=$needle==='m17_qa_run_identity'
        ? (string)file_get_contents($root.'/ops/qa/m17/qa.php') : $code;
    Assert::contains($needle,$haystack,'R4 CI must preserve '.$needle);
}
foreach([
    '--r4-candidate','R4_270_290_M05_SYNTHETIC_POPULATION',
    'R4_EVIDENCE','r4-independent-restore-verify.json',
    'docker kill --signal=KILL',
    'VOLUME_A=','VOLUME_B=',
    'mariadb-dump','--single-transaction',
    'age --decrypt','r4-state.json',
] as $needle){
    Assert::contains($needle,$restore,'R4 uses proven ephemeral encrypted volume restore: '.$needle);
}
Assert::contains("R4=false",$restore,
    'previous 280-only recovery remains the default, no accidental R4 activation');
foreach([
    "mariadb: ['10.6', '10.11']",
    'permissions:',
    'contents: read',
    'pull_request:',
    'workflow_dispatch:',
    'r4-candidate-volume-recovery-',
] as $needle){
    Assert::contains($needle,$ci,'R4 hosted QA must use pinned no-secrets matrix: '.$needle);
}
Assert::false(str_contains($ci,'pull_request_target:'),
    'R4 may never receive elevated fork credentials');
Assert::false(str_contains($ci,'secrets.'),
    'R4 never reads deployment tokens or tenant/source secrets');
foreach([
    'database/migrations/270_history_review_bases.sql',
    'database/migrations/280_m17_temporal_memory.sql',
    'database/migrations/290_history_review_policy.sql',
    'ops/history/due.php',
    'ops/temporal/run.php',
] as $notActivated){
    Assert::false(is_file($root.'/'.$notActivated),
        'R4 does not install candidate schema or create runtime writer: '.$notActivated);
}
