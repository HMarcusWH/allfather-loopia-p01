<?php

declare(strict_types=1);

use Allfather\Loopia\Core\{Clock,Digest};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewFactory,HistoryReviewService,HistoryReviewJournal,
    ExecutionGrantStore,ExecutionAdmission
};
use Allfather\Loopia\PromptOps\{PromptBundleRegistry,HistoryReviewCandidateChecks};

$fake=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
Assert::same('AN02_U03_U06_RUNTIME_REVIEW_NOT_ADOPTED',
    HistoryReviewJournal::forRuntime($fake,new Clock(),[
        'review_approved'=>true,'model_budget'=>1000,
    ])->reasonCode(),
    'caller-signed tokens cannot instantiate a live historical journal');
$disposable=HistoryReviewJournal::forDisposableTest($fake,new Clock());
if(defined('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED')
    && constant('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED')===true
    && PHP_SAPI==='cli'){
    Assert::true($disposable->isOk(),
        'disposable marker from earlier integration never grants a runtime factory');
}else{
    Assert::same('AN02_FAMILY320_PRODUCTION_NOT_ADOPTED',
        $disposable->reasonCode(),
        'without explicitly disposable schema the journal refuses');
}
Assert::same('AN02_U03_U06_PROVIDER_AND_HUMAN_RUNTIME_NOT_ADOPTED',
    HistoryReviewFactory::forRuntime([
        'owner_decision'=>'APPROVE','credential_ref'=>'synthetic',
    ])->reasonCode(),
    'self-reported owner rights cannot activate historical analyst');
Assert::false(in_array('HISTORICAL_REVIEW_MANUAL_UNADOPTED',
    ExecutionGrantStore::PURPOSES,true),
    'historical source candidate cannot silently borrow existing model grants');
Assert::same('PROMPT_BUNDLE_VERSION_NOT_REGISTERED',
    PromptBundleRegistry::resolve('HISTORICAL_ANALYST',
        HistoryReviewFactory::ROLE_BUNDLE)->reasonCode(),
    'inactive analyst role file is deliberately not registered for provider dispatch');

$root=dirname(__DIR__,2);
foreach([
    'app/Workflow/HistoryReviewService.php',
    'app/Workflow/HistoryReviewJournal.php',
    'app/Workflow/HistoryReviewFactory.php',
    'app/PromptOps/HistoryReviewCandidateChecks.php',
] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach(['curl_exec(', 'new ModelExecutor(', '->send(',
        'new SourceCaptureStage(', 'new MonitorService(', 'releaseP01(',
        'mail(', 'unlink(', 'DELETE FROM', 'new HttpClient('] as $needle){
        Assert::false(str_contains($source,$needle),
            'AN02 mock workflow never acquires external source/model/release authority');
    }
}
foreach([
    'ops/history/review-candidate.php',
    'ops/history/replay-candidate.php',
] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    Assert::true(str_contains($source,"PHP_SAPI !== 'cli'"),
        'AN02 candidate CLI is not an HTTP endpoint');
    Assert::true(strpos($source,"PHP_SAPI !== 'cli'")<strpos($source,'autoload.php'),
        'refuse HTTP before importing any app or fixture source');
}
$roles=json_decode((string)file_get_contents(
    $root.'/app/PromptOps/bundles/history-review-v1.json'),
    true,64,JSON_THROW_ON_ERROR);
Assert::same('INACTIVE_NOT_REGISTERED_WITH_PROMPT_BUNDLE_REGISTRY',
    $roles['activation_status'],
    'an authored critic prompt is not a production PromptOps adoption');
Assert::same(false,$roles['authorization']['human_factual_release_approved'],
    'analyst/critic cannot impersonate factual product human review');
Assert::same(false,$roles['authorization']['human_delivery_approved'],
    'analyst/critic cannot impersonate routing/delivery human review');
Assert::same(false,$roles['authorization']['customer_release'],
    'no model-generated customer signal is permitted');
require_once $root.'/tests/support/An02MockReview.php';
$sim=\Allfather\Loopia\Tests\An02MockReview::simulate();
Assert::true($sim->isOk(),'local deterministic code-only simulation is callable');
if($sim->isOk()){
    Assert::same('HUMAN_REVIEW_REQUIRED',$sim->value()['final_status'],
        'mock critic never produces a real human-approved release');
    Assert::same(0,$sim->value()['external_model_calls'],
        'local mode never contacts a provider');
    Assert::same(false,$sim->value()['source_bytes_exported'],
        'mock metadata-only context never leaks original source');
}
