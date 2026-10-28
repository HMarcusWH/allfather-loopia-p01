<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob02Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\{CompetitorScope,ObservationAssessment};
use Allfather\Loopia\Tests\{Assert,Ob02Fixture};

$actor=Ob02Fixture::actor();
$native=Ob02Fixture::native(2);
$history=Ob02Fixture::history(2,'STRUCTURALLY_UNCHANGED',['NONE']);
$baseline=CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$native,$history);
Assert::true($baseline->isOk(),'fixture source binds before negative mutations');
if(!$baseline->isOk())return;
$scope=$baseline->value();
$good=ObservationAssessment::fromLegacy($scope,$history);
Assert::true($good->isOk(),'synthetic base candidate positive');
if(!$good->isOk())return;
$record=$good->value();
$bad=$native;$bad['tenant_id']=Ob02Fixture::uuid(55);
Assert::same('OB02_NATIVE_LINEAGE_SCOPE_MISMATCH',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'cross-tenant rows are rejected before candidate compose');
$bad=$native;$bad['workspace_id']=Ob02Fixture::uuid(55);
Assert::same('OB02_NATIVE_LINEAGE_SCOPE_MISMATCH',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'cross-workspace rows refused');
$bad=$native;$bad['competitor_id']=Ob02Fixture::uuid(55);
Assert::same('OB02_NATIVE_LINEAGE_SCOPE_MISMATCH',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'one source cannot be joined to another competitor');
$bad=$native;$bad['evidence_source_ref_id']=Ob02Fixture::uuid(55);
Assert::same('OB02_M05_EVIDENCE_NOT_CURRENT',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'source snapshot evidence must bind the same M05 source reference');
$bad=$native;$bad['evidence_content_hash']=str_repeat('b',64);
Assert::same('OB02_M05_SNAPSHOT_EVIDENCE_HASH_MISMATCH',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'M05 evidence hash must match the cited P01 snapshot canonical content hash');
$bad=$native;$bad['evidence_status']='REVOKED_FOR_USE';
Assert::same('OB02_M05_EVIDENCE_NOT_CURRENT',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$bad,$history)->reasonCode(),
    'revoked evidence must not become an accepted historical link');
$bad=$history;$bad['question_id']=Ob02Fixture::uuid(66);
Assert::same('OB02_QUESTION_IDENTITY_OWNER_PROOF_MISSING',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$native,$bad)->reasonCode(),
    'missing P01 question identity cannot be invented from arbitrary ID');
$bad=$history;$bad['source_snapshot_id']=Ob02Fixture::uuid(90);
Assert::same('OB02_NATIVE_LINEAGE_SCOPE_MISMATCH',
    CompetitorScope::bind($actor,Ob02Fixture::uuid(3),$native,$bad)->reasonCode(),
    'foreign capture ID cannot be cited');

$bad=$history;$bad['coverage_state']='STRUCTURAL_CHANGE_RECORDED';
Assert::same('OB02_COVERAGE_SIGNIFICANCE_INCONSISTENT',
    ObservationAssessment::fromLegacy($scope,$bad)->reasonCode(),
    'a NONE structural diff cannot be rebranded MATERIAL by coverage');
$bad=$history;$bad['coverage_state']='BASELINE';
Assert::same('OB02_COVERAGE_COMPARISON_INCONSISTENT',
    ObservationAssessment::fromLegacy($scope,$bad)->reasonCode(),
    'baseline cannot carry an invented comparison');
$bad=$history;$bad['comparisons']=[];
Assert::same('OB02_COVERAGE_COMPARISON_INCONSISTENT',
    ObservationAssessment::fromLegacy($scope,$bad)->reasonCode(),
    'unchanged status requires exactly one native structural diff');
$bad=$history;$bad['comparisons'][0]['source_diff_id']='foreign-diff';
Assert::same('OB02_COMPARISON_REFERENCE_INVALID',
    ObservationAssessment::fromLegacy($scope,$bad)->reasonCode(),
    'arbitrary diff reference cannot be accepted');

$bad=$record;$bad['customer_release_authorized']=true;
Assert::same('OB02_METADATA_RECORD_NOT_ADMITTED',
    ObservationAssessment::verify($bad)->reasonCode(),'self-promoted customer release rejected');
$bad=$record;$bad['business_significance']='MAJOR';
Assert::same('OB02_METADATA_RECORD_NOT_ADMITTED',
    ObservationAssessment::verify($bad)->reasonCode(),'structural significance never becomes commercial model materiality');
$bad=$record;$bad['known_at']='2021-01-01T00:00:00.000Z';
Assert::same('OB02_METADATA_RECORD_NOT_ADMITTED',
    ObservationAssessment::verify($bad)->reasonCode(),'historical model assessment cannot be backdated');
$bad=$record;$bad['human_annotation_id']=Ob02Fixture::uuid(100);
Assert::same('OB02_METADATA_RECORD_NOT_ADMITTED',
    ObservationAssessment::verify($bad)->reasonCode(),'no fabricated human review attribution');
$bad=$record;$bad['lineage_digest']=str_repeat('f',64);
Assert::same('OB02_METADATA_LINEAGE_DIGEST_MISMATCH',
    ObservationAssessment::verify($bad)->reasonCode(),'metadata tampering detected even if schema stays valid');
