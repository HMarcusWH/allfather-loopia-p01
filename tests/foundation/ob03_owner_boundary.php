<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\{
    ObservationFeatureSet,SourceOriginAssertionReader,ObservationCoverageReceipt,
    ObservationFeatureCandidateStore,ObservationFeatures,SourceOriginGroups,ObservationCoverage
};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
Assert::same('ob03.observation-feature-set.v1',ObservationFeatureSet::VERSION,
    'new versioned feature composition');
Assert::same('ob03.current-owner-source-reference.v1',SourceOriginAssertionReader::VERSION,
    'source origin readback is a new owner-scoped version');
Assert::same('ob03.coverage-receipt.v1',ObservationCoverageReceipt::VERSION,
    'real schedule coverage wrapper has explicit version');
Assert::same('ob03.disposable-feature-origin-store.v1',ObservationFeatureCandidateStore::VERSION,
    'schema265 candidate store version');
Assert::same('li04.observation-features.pure.v1',ObservationFeatures::VERSION,
    'original LI04 legacy precursor unchanged');
Assert::same('li04.byte-repeat-groups.pure.v1',SourceOriginGroups::VERSION,
    'original byte grouping unchanged');
Assert::same('li04.observation-coverage.pure.v1',ObservationCoverage::VERSION,
    'original scheduler slot arithmetic unchanged');

foreach(['ObservationFeatureSet.php','SourceOriginAssertionReader.php',
    'ObservationCoverageReceipt.php','ObservationFeatureCandidateStore.php'] as $path){
    $body=(string)file_get_contents($root.'/app/Product/P01/'.$path);
    foreach(['CurlHttpsTransport','OpenAIResponsesTransport',
        'ModelExecutor','curl_exec(','fsockopen(','shell_exec(','proc_open(',
        'new \\PDO(','mail(','new EvidenceStore('] as $forbidden){
        Assert::false(str_contains($body,$forbidden),
            'OB03 never constructs provider/egress/action transport: '.$path);
    }
}
$source=(string)file_get_contents($root.'/app/Product/P01/SourceOriginAssertionReader.php');
Assert::contains('ObservationHistory::MAX_PAGE_SIZE',$source,
    'actual origin readback uses existing owner current history window');
Assert::contains('CompetitorScope::bind',$source,
    'P01/M05 evidence hash/coherence owner contract reused');
$coverage=(string)file_get_contents($root.'/app/Product/P01/ObservationCoverageReceipt.php');
Assert::contains('ScheduleHistoryCoverage $owner',$coverage,
    'real scheduled receipt calls current authenticated owner rather than trusting a bare array');
Assert::contains('NOT_OWNER_VERIFIED',$coverage,
    'synthetic slot counts do not mint owner coverage');
$sql=(string)file_get_contents($root.'/docs/longitudinal/proposals/265_observation_features_CANDIDATE.sql');
foreach(['p01_observation_features','p01_source_origin_assertions',
    'OB03_FEATURE_APPEND_ONLY','OB03_ORIGIN_APPEND_ONLY',
    'TEST_ONLY_NOT_ADMITTED'] as $needle){
    Assert::contains($needle,$sql,'family265 is a candidate-only two-table append-only proposal');
}
Assert::false(is_file($root.'/database/migrations/265_observation_features_and_origins.sql'),
    'U02/U03-gated schema265 never entered runtime migrations');
$cap=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$row=array_column($cap['build_packages'],null,'id')['OB-03'];
Assert::same('BUILT_INERT',$row['build_status'],'source-only real implementation completed but inert');
foreach(['native_permission','customer_visibility','production_activation',
    'live_migration','external_effects'] as $key){
    Assert::same(false,$row[$key],'new source build cannot grant native authority: '.$key);
}
$doc=(string)file_get_contents($root.'/docs/OB03_ORIGIN_COVERAGE_FEATURES_ADR.md');
foreach(['U02','U03','M05','OB-02','CR-02','UNKNOWN',
    'NO_CHANGE','RT-01','APPEND_ONLY'] as $word){
    Assert::contains($word,$doc,'documentation preserves provenance and owner limits: '.$word);
}
