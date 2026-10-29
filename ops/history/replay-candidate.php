<?php

declare(strict_types=1);

/** AN-02: typed recovery inspection, no real source bytes or journal mutations. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\HistoryReviewJournal;

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AN02_PRODUCTION_JOURNAL_REPLAY_NOT_ADMITTED\n");exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>HistoryReviewJournal::VERSION,
        'state'=>'DISPOSABLE_ONLY_NO_LIVE_READ',
        'native_migration_installed'=>false,
        'runtime_source_use_admitted'=>false,
        'provider_reconciliation_admitted'=>false,
        'human_release_approved'=>false,
        'customer_release_authorized'=>false,
        'fixture_requires'=>'ALLFATHER_AN02_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_AN02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AN02_SYNTHETIC_TEST_MODE_REQUIRED\n");exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An02MockReview.php';
    $a=\Allfather\Loopia\Tests\An02MockReview::simulate();
    if(!$a->isOk())throw new \RuntimeException('FIXTURE_REFUSED');
    $value=$a->value();
    echo json_encode([
        'version'=>HistoryReviewJournal::VERSION,
        'state'=>$value['final_status'],
        'frozen_run_digest'=>$value['run_digest'],
        'event_count'=>count($value['event_digests']),
        'event_digests'=>$value['event_digests'],
        'replay_source'=>'LOCAL_CODE_GENERATED_SYNTHETIC_ONLY',
        'real_journal_read'=>false,
        'original_provider_or_source_bytes'=>false,
        'customer_release_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AN02_SYNTHETIC_REPLAY_PREVIEW_UNAVAILABLE\n");exit(4);
}
