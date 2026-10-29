<?php

declare(strict_types=1);

/** AN-02: inert local mock preview, NEVER a paid historical-review runner. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\{HistoryReviewFactory,HistoryReviewService};

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AN02_REAL_HISTORICAL_REVIEW_NOT_ADMITTED\n");exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>HistoryReviewService::VERSION,
        'status'=>'U03_U06_NOT_ADOPTED_LOCAL_ONLY',
        'candidate_schema_version'=>HistoryReviewService::EVENT_VERSION,
        'mock_requires'=>'ALLFATHER_AN02_SYNTHETIC_TEST=1',
        'runtime_factory_available'=>false,
        'native_historical_provider_purpose'=>false,
        'source_reuse_admitted'=>false,
        'external_model_calls'=>0,
        'human_release_approved'=>false,
        'customer_release_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_AN02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AN02_SYNTHETIC_TEST_MODE_REQUIRED\n");exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An02MockReview.php';
    $out=\Allfather\Loopia\Tests\An02MockReview::simulate();
    if(!$out->isOk())throw new \RuntimeException('FIXTURE_REFUSED');
    echo json_encode($out->value(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AN02_SYNTHETIC_REVIEW_PREVIEW_UNAVAILABLE\n");exit(4);
}
