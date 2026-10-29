<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob03Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\{ObservationFeatureSet,ObservationCoverageReceipt};
use Allfather\Loopia\Tests\{Assert,Ob03Fixture};

$f=Ob03Fixture::positive('price-one-digit',null,null,null,2);
$assessment=$f['assessment'];$delta=$f['delta'];$captures=$f['captures'];$raw=$f['bounded_input'];
$deny=static function(array $a,array $d,array $c,?array $input,string $code,string $label):void{
    $out=ObservationFeatureSet::compose($a,$d,$c,$input);
    Assert::true(!$out->isOk(),$label.': cannot produce a successful candidate');
    if(!$out->isOk())Assert::same($code,$out->reasonCode(),$label.': exact refusal');
};
$bad=$assessment;$bad['customer_release_authorized']=true;
$deny($bad,$delta,$captures,$raw,'OB03_OB02_METADATA_NOT_ADMITTED',
    'an assessment with forged release permission refused');
$bad=$delta;$bad['customer_release_authorized']=true;
$deny($assessment,$bad,$captures,$raw,'OB03_CR02_DELTA_CONTRACT_INVALID',
    'CR02 cannot mint a customer release');
$bad=$delta;$bad['delta_digest']=str_repeat('0',64);
$deny($assessment,$bad,$captures,$raw,'OB03_CR02_DELTA_DIGEST_MISMATCH',
    'tampered CR02 result hash refused');
$bad=$delta;$bad['source_scope']['tenant_id']='foreign-tenant';
unset($bad['delta_digest']);$bad['delta_digest']=Digest::canonicalDigest($bad);
$deny($assessment,$bad,$captures,$raw,'OB03_CR02_OB02_SOURCE_BINDING_MISMATCH',
    'different tenant cannot link semantic delta to OB02 assessment');
$bad=$delta;$bad['after']['capture_ref_id']='foreign-capture';
unset($bad['delta_digest']);$bad['delta_digest']=Digest::canonicalDigest($bad);
$deny($assessment,$bad,$captures,$raw,'OB03_CR02_OB02_SOURCE_BINDING_MISMATCH',
    'different after snapshot cannot inherit assessment scope');
$bad=$captures;$bad[0]['payload_sha256']=str_repeat('f',64);
$deny($assessment,$delta,$bad,$raw,'OB03_ORIGIN_CAPTURE_BYTE_LINEAGE_MISMATCH',
    'after capture raw SHA must match CR02 original HTML hash');
$bad=$captures;$bad[]=$captures[0];
$deny($assessment,$delta,$bad,$raw,'LI04_ORIGIN_DUPLICATE_CAPTURE',
    'one captured snapshot may not be counted twice');
$bad=$captures;array_shift($bad);
$deny($assessment,$delta,$bad,$raw,'OB03_ORIGIN_AFTER_CAPTURE_REQUIRED',
    'after snapshot must be in exact capture group');
$bad=$captures;$bad[1]['tenant_id']='foreign';
$deny($assessment,$delta,$bad,$raw,'LI04_ORIGIN_SOURCE_SCOPE_OR_ID_INVALID',
    'cross-tenant mirror cannot be used as corroboration');
$bad=$captures;$bad[1]['origin_independent']=true;
$deny($assessment,$delta,$bad,$raw,'LI04_ORIGIN_SOURCE_SCOPE_OR_ID_INVALID',
    'caller cannot smuggle origin authority into byte-grouper');
$bad=$raw;$bad['after_body_sha256']=str_repeat('0',64);
$deny($assessment,$delta,$captures,$bad,'OB03_LI04_RAW_BYTE_PROJECTION_MISMATCH',
    'bounded feature input must match original CR02 source SHA');
$bad=$raw;$bad['provider_grant_id']='fake';
$deny($assessment,$delta,$captures,$bad,'LI04_FEATURE_INPUT_CONTRACT_INVALID',
    'no execution grant/option can be transported into feature extraction');
$bad=$f['result'];$bad['customer_release_authorized']=true;
Assert::false(ObservationFeatureSet::verify($bad),'tampering release status fails feature verifier');
$bad=$f['result'];$bad['feature_digest']=str_repeat('0',64);
Assert::false(ObservationFeatureSet::verify($bad),'stale digest cannot verify');

$badPlan=ObservationCoverageReceipt::synthetic('synthetic-plan',2,[
    ['slot_index'=>0,'outcome'=>'NO_BUSINESS_CHANGE','skipped_slots'=>0],
]);
Assert::same('LI04_COVERAGE_SLOT_INVALID',$badPlan->reasonCode(),
    'missing source cannot be disguised as a business no-change outcome');
Assert::same('LI04_COVERAGE_PLAN_INVALID',
    ObservationCoverageReceipt::synthetic('bad-plan',10001,[])->reasonCode(),
    'unbounded scheduled window refused');
$bad=ObservationCoverageReceipt::synthetic('valid-plan',2,[])->value();
$bad['customer_release_authorized']=true;
Assert::false(ObservationCoverageReceipt::verify($bad),'caller cannot self-promote gap coverage to customer');
