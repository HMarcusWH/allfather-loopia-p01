<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\{CompetitorScope,ObservationAssessment,ObservationBackfill,ObservationAssessmentStore,ObservationHistory};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
Assert::same('ob02.legacy-metadata-link.v1',ObservationAssessment::VERSION,'OB02 frozen metadata-only contract');
Assert::same('ob02.cursor-ledger-preview.v1',ObservationBackfill::VERSION,'bounded native owner backfill preview');
Assert::same(20,ObservationAssessment::MAX_PAGE_SIZE,'backfill per-page bound <=20');
Assert::same('NOT_RECORDED_IN_LEGACY_P01',CompetitorScope::QUESTION_UNKNOWN,'no fabricated historical question identity');
foreach (['CompetitorScope.php','ObservationAssessment.php','ObservationBackfill.php','ObservationAssessmentStore.php'] as $file) {
    $s=(string)file_get_contents($root.'/app/Product/P01/'.$file);
    foreach(['CurlHttpsTransport','OpenAIResponsesTransport','ModelExecutor',
        'curl_exec(', 'mail(', 'fsockopen(', 'shell_exec(', 'proc_open('] as $unsafe){
        Assert::false(str_contains($s,$unsafe),'candidate source cannot perform network/provider/action: '.$file);
    }
}
$reader=(string)file_get_contents($root.'/app/Product/P01/ObservationHistory.php');
foreach(['competition_monitoring.read','evidence.read','CURRENT_ELIGIBILITY_CHECKED',
    'source_license_cards','source_references','evidence_objects','previewAssessmentBackfill'] as $needle){
    Assert::contains($needle,$reader,'new preview still inherits owner rights and old read: '.$needle);
}
$operator=(string)file_get_contents($root.'/ops/history/backfill.php');
Assert::contains('OB02_NATIVE_PERSISTENCE_U01_U02_NOT_ADOPTED',$operator,
    'live backfill CLI cannot bypass U01/U02');
Assert::false(str_contains($operator,'Database::connect'),'CLI does not open a database to self-authorize source use');
$sql=(string)file_get_contents($root.'/docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql');
foreach(['p01_observation_assessments','p01_observation_assessment_refs',
    'p01_observation_backfill_ledger','DELIMITER $$','OB02_ASSESSMENT_APPEND_ONLY',
    'TEST_ONLY_NOT_ADMITTED'] as $needle)Assert::contains($needle,$sql,'candidate DDL source recorded: '.$needle);
Assert::false(is_file($root.'/database/migrations/260_competitor_observation_assessments.sql'),
    'unadopted family 260 migration is not installed');
Assert::false(is_file($root.'/database/migrations/260_competitor_assessments.sql'),
    'candidate DDL stays outside normal migrator');
$cap=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$rows=array_column($cap['build_packages'],null,'id');
Assert::same('BUILT_INERT',$rows['OB-02']['build_status'],'source-only candidate not runtime activated');
foreach(['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $k){
    Assert::same(false,$rows['OB-02'][$k],'no owner right from candidate build: '.$k);
}
$docs=(string)file_get_contents($root.'/docs/OB02_ASSESSMENT_HISTORY_ADR.md');
foreach(['U01','U02','CURRENT','NOT_ASSESSED','cursor ledger','RT-01','M05'] as $word){
    Assert::contains($word,$docs,'OB02 receipt records freeze/authority: '.$word);
}
