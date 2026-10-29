<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob03Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\{
    ObservationFeatureSet,ObservationCoverageReceipt,ObservationAssessment
};
use Allfather\Loopia\Tests\{Assert,Ob03Fixture};

$catalog = json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/ob03/ob03_cases_v1.json'),true,64,JSON_THROW_ON_ERROR);
Assert::same('ob03.synthetic-feature-coverage.v1',$catalog['schema_version'],
    'OB03 source-pinned development corpus revision');
Assert::same('DEVELOPMENT_ONLY_NOT_M05_OR_U02_U03_ADMITTED',$catalog['classification'],
    'no synthetic fixture claims source owner adoption');
Assert::same(5,count($catalog['cases']),'five synthetic CR02/OB02/LI04 composition cases');

foreach($catalog['cases'] as $case){
    $f=Ob03Fixture::positive($case['cr02_case'],null,null,null,$case['captures']);
    $v=$f['result'];
    Assert::true(ObservationFeatureSet::verify($v),$case['id'].': candidate byte integrity');
    Assert::same(ObservationFeatureSet::VERSION,$v['schema_version'],$case['id'].': feature revision');
    Assert::same($case['captures'],$v['byte_class_summary']['capture_count'],
        $case['id'].': number of captures, not events');
    Assert::same($case['expected_byte_classes'],$v['byte_class_summary']['distinct_exact_payload_count'],
        $case['id'].': byte classes not source-origin groups');
    Assert::same($case['expected_exact_repeats'],$v['byte_class_summary']['exact_byte_repeat_count'],
        $case['id'].': repeated captures retained but do not inflate corroboration');
    Assert::same($case['expected_change'],$v['feature_rows'][0]['change_kind'],
        $case['id'].': actual CR02 typed source change');
    Assert::same($case['expected_crosswalk'],$v['bounded_feature_crosswalk'],
        $case['id'].': independent LI04 token evidence is reconciled conservatively');
    Assert::same($f['assessment']['lineage_digest'],
        $v['source_lineage']['assessment_metadata_digest'],
        $case['id'].': exact OB02 assessment lineage referenced');
    Assert::same($f['delta']['delta_digest'],
        $v['source_lineage']['semantic_delta_digest'],
        $case['id'].': exact CR02 semantic delta lineage referenced');
    Assert::same($f['delta']['after']['raw_sha256'],
        $v['source_lineage']['after_raw_html_sha256'],
        $case['id'].': original HTML bytes SHA separate from P01 canonical content hash');
    Assert::same($f['assessment']['p01_canonical_content_hash'],
        $v['source_lineage']['p01_canonical_hash_not_raw_html'],
        $case['id'].': original P01 hash never silently relabeled raw HTML SHA');
    Assert::same($case['independent_origins'],$v['independent_corroboration_count'],
        $case['id'].': source domains/hashes cannot certify independent origin');
    Assert::same(null,$v['distinct_real_world_event_count'],
        $case['id'].': exact byte count never becomes event count');
    Assert::same(null,$v['independently_supported_event_count'],
        $case['id'].': source origin and event support remain unknown');
    Assert::same(false,$v['independent_origin_established'],
        $case['id'].': publisher independence requires U02 owner attestation');
    Assert::same('NOT_ASSESSED',$v['business_materiality'],
        $case['id'].': structural change is not economic relevance');
    Assert::same(false,$v['current_source_use_admitted'],
        $case['id'].': even copied source bytes cannot bypass U03');
    Assert::same(false,$v['owner_authenticated_origin_assertions'],
        $case['id'].': pure source grouping never produces a current owner assertion');
    Assert::same(false,$v['runtime_persistence_authorized'],
        $case['id'].': no native family265 writer admitted');
    Assert::same(false,$v['customer_release_authorized'],
        $case['id'].': no customer notification or report');
    Assert::same(true,$v['derived_not_primary_evidence'],
        $case['id'].': feature remains M05 derivative not certified new fact');
    foreach($v['feature_rows'] as $row){
        Assert::same(false,$row['source_content_exported'],
            $case['id'].': no raw HTML, snippets or untrusted labels returned');
        Assert::same(false,$row['raw_html_offsets_verified'],
            $case['id'].': DOM path never becomes original byte offset');
    }
    $v2=ObservationFeatureSet::compose($f['assessment'],$f['delta'],$f['captures'],$f['bounded_input']);
    Assert::same($v['feature_digest'],$v2->value()['feature_digest'],
        $case['id'].': deterministic exact repeat');
}

$ten=Ob03Fixture::positive('navigation-reorder',null,null,null,10);
Assert::same(1,$ten['result']['unique_exact_change_signatures'],
    'ten repeats of one navigation permutation create one exact change signature');
Assert::same(10,$ten['result']['byte_class_summary']['capture_count'],
    'ten capture receipts remain ten capture receipts');
Assert::same(1,$ten['result']['byte_class_summary']['distinct_exact_payload_count'],
    'ten copied pages remain ONE exact byte class');
Assert::same(null,$ten['result']['independent_corroboration_count'],
    'ten copied pages never become ten independent witnesses');

$slots=[
    ['slot_index'=>0,'outcome'=>'CAPTURED_BASELINE','skipped_slots'=>0],
    ['slot_index'=>2,'outcome'=>'CAPTURED_UNCHANGED','skipped_slots'=>1],
    ['slot_index'=>4,'outcome'=>'REFUSED','skipped_slots'=>0],
    ['slot_index'=>5,'outcome'=>'FAILED','skipped_slots'=>0],
    ['slot_index'=>6,'outcome'=>'PENDING','skipped_slots'=>0],
];
$coverage=ObservationCoverageReceipt::synthetic('synthetic-schedule-v1',8,$slots);
Assert::true($coverage->isOk(),'synthetic, explicit missing-slot coverage receipt produced');
if($coverage->isOk()){
    $c=$coverage->value();
    Assert::true(ObservationCoverageReceipt::verify($c),'cover receipt exact byte digest');
    Assert::same('UNKNOWN_NOT_OWNER_VERIFIED',$c['coverage_state'],
        'pure plan is not authenticated owner schedule');
    Assert::same(2,$c['captured_slots'],'baseline+unchanged are two real fixture captures');
    Assert::same(1,$c['captured_unchanged'],'unchanged is observed, not inferred from absence');
    Assert::same(2,$c['gap_counts']['EXPECTED_NOT_FETCHED_UNKNOWN'],
        'two unknown slots are NOT a NO_CHANGE capture');
    Assert::same(1,$c['gap_counts']['EXPLICITLY_SKIPPED'],'actual skip counted separately');
    Assert::same(1,$c['gap_counts']['BLOCKED_OR_REFUSED'],'refusal not a source fact');
    Assert::same(1,$c['gap_counts']['FAILED_CAPTURE'],'failed acquisition not unchanged');
    Assert::same(1,$c['gap_counts']['PENDING_NOT_OBSERVED'],'unexecuted slot not observed');
    Assert::same(6,$c['missing_or_unknown_slots'],'unknown+skipped+failed+refused+pending');
    Assert::same(null,$c['independent_corroboration_count'],'coverage cannot prove origins');
    Assert::same(false,$c['u03_historical_reuse_admitted'],'slot receipts do not grant historical reuse');
    Assert::same(false,$c['model_dispatch_authorized'],'coverage cannot trigger provider');
    Assert::same(false,$c['customer_release_authorized'],'coverage not customer result');
    $again=ObservationCoverageReceipt::synthetic('synthetic-schedule-v1',8,
        [$slots[4],$slots[2],$slots[0],$slots[3],$slots[1]]);
    // Owner slot coverage is order-independent after existing LI04 sorted-slot
    // summarization; lineage includes raw synthetic input order for inspection.
    Assert::same($c['captured_slots'],$again->value()['captured_slots'],
        'slot arithmetic does not change under permutation');
}
$unknown=ObservationCoverageReceipt::synthetic('synthetic-empty-outage',3,[]);
Assert::true($unknown->isOk(),'missing every scheduled slot still creates explicit gap receipt');
Assert::same(3,$unknown->value()['gap_counts']['EXPECTED_NOT_FETCHED_UNKNOWN'],
    'three missing observations are UNKNOWN, not 3 NO_CHANGE');
Assert::same(0,$unknown->value()['captured_unchanged'],'no fake unchanged signal on outage');
Assert::same('UNKNOWN_NOT_OWNER_VERIFIED',$unknown->value()['coverage_state'],
    'no unproved completeness without actual admitted owner schedule');
