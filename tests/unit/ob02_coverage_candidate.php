<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob02Fixture.php';

use Allfather\Loopia\Product\P01\{CompetitorScope,ObservationAssessment};
use Allfather\Loopia\Tests\{Assert,Ob02Fixture};

$v=Ob02Fixture::examples();
Assert::same('ob02.legacy-coverage-development.v1',$v['schema_version'],'OB02 versioned examples');
Assert::same(5,$v['case_count'],'five explicit coverage outcomes');
Assert::same('SYNTHETIC_ONLY_NO_HISTORICAL_ASSESSMENT',$v['classification'],'not a historical model assessment');
$actor=Ob02Fixture::actor();
foreach($v['cases'] as $case){
    $native=Ob02Fixture::native($case['sequence']);
    $history=Ob02Fixture::history($case['sequence'],$case['coverage'],$case['significance']);
    $bound=CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$native,$history);
    Assert::true($bound->isOk(),$case['id'].': native-shaped source identity binds explicitly');
    if(!$bound->isOk())continue;
    Assert::same(CompetitorScope::QUESTION_UNKNOWN,$bound->value()['question_identity'],
        $case['id'].': no invented historic question ID');
    Assert::same(null,$bound->value()['question_id'],$case['id'].': missing question remains missing');
    $record=ObservationAssessment::fromLegacy($bound->value(),$history);
    Assert::true($record->isOk(),$case['id'].': candidate metadata link composed');
    if(!$record->isOk())continue;
    $item=$record->value();
    Assert::same($case['expected'],$item['coverage_class'],$case['id'].': distinct coverage category');
    Assert::same($case['coverage'],$item['comparison_coverage'],$case['id'].': original owner structural status retained');
    Assert::same('NOT_ASSESSED',$item['assessment_status'],$case['id'].': no invented earlier assessment');
    Assert::same('NOT_ASSESSED',$item['business_significance'],$case['id'].': no model materiality inferred');
    Assert::same(null,$item['known_at'],$case['id'].': no backdated knowledge clock');
    Assert::same(null,$item['recorded_at'],$case['id'].': no forged historical write time');
    Assert::same(null,$item['human_annotation_id'],$case['id'].': no invented annotator');
    Assert::same(null,$item['run_id'],$case['id'].': no invented legacy run identity');
    Assert::same('NOT_INSPECTED',$item['bytes_availability'],$case['id'].': metadata != raw-byte verification');
    Assert::same(false,$item['customer_release_authorized'],$case['id'].': no customer output');
    Assert::same(false,$item['source_rights_for_new_use_authorized'],$case['id'].': no native rights grant');
    Assert::same(false,$item['historical_assessment_reconstructed'],$case['id'].': no historical candidate regeneration');
    Assert::same(1+count($case['significance']),$item['reference_count'],$case['id'].': full references not arbitrary one');
    Assert::same('M05_EVIDENCE',$item['references'][0]['ref_kind'],$case['id'].': M05 first provenance');
    Assert::true(ObservationAssessment::verify($item)->isOk(),$case['id'].': immutable record integrity verifies');
    Assert::same($item['lineage_digest'],ObservationAssessment::fromLegacy($bound->value(),$history)->value()['lineage_digest'],
        $case['id'].': deterministic source-only identity no time drift');
}
foreach([
    'REVOKED_SOURCE_RIGHTS'=>'HELD_CURRENT_SOURCE_RIGHTS',
    'MISSING_SOURCE_BYTES'=>'HELD_BYTES_MISSING',
    'REJECTED_CANDIDATE'=>'NO_RECONSTRUCTION_REJECTED',
    'TERMINAL_NO_CHANGE'=>'PRESERVE_TERMINAL_NO_CHANGE',
    'AMBIGUOUS_SOURCE'=>'HELD_UNVERIFIED_SOURCE_REF',
    'INSUFFICIENT_EVIDENCE'=>'HELD_INSUFFICIENT_BASIS',
] as $input=>$expected){
    $r=ObservationAssessment::gap($input);
    Assert::true($r->isOk(),'distinct explicit gap state: '.$input);
    Assert::same($expected,$r->value()['category'],'no gap collapsed into business finding: '.$input);
    Assert::same(false,$r->value()['backfill_record_created'],'gaps never create fabricated model assessments: '.$input);
}
Assert::same('OB02_UNKNOWN_GAP_REASON',ObservationAssessment::gap('MYSTERY_APPROVED')->reasonCode(),
    'unknown historical eligibility cannot default to allow');
