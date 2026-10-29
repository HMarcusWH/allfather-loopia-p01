<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoryReviewRightsGateway;

Assert::same(3,count(HistoryReviewRightsGateway::PURPOSES),
    'source reuse, historical model analysis and external bytes are different purposes');
Assert::same(4,count(HistoryReviewRightsGateway::STAGES),
    'before-reuse, before-dispatch, after-return and before-release are distinct');
$fake=[
    'schema_version'=>HistoryReviewRightsGateway::VERSION,
    'decision'=>HistoryReviewRightsGateway::HOLD,
    'purpose'=>'INTERNAL_DETERMINISTIC_REUSE',
    'stage'=>'BEFORE_BASIS_REUSE',
    'scope'=>['tenant_id'=>'t','workspace_id'=>'w','competitor_id'=>'c'],
    'actor_type'=>'OPERATOR','review_at_utc'=>'2026-10-29T08:00:00Z',
    'historical_basis_digest'=>str_repeat('a',64),
    'owner_reference_digest'=>str_repeat('b',64),
    'reference_count'=>1,
    'current_reference_scope_check'=>'CURRENT_M02_P01_M05_PASS',
    'source_license_for_historical_reuse'=>'UNRESOLVED_U03',
    'current_terms_for_model_use'=>'UNRESOLVED_U03_U06',
    'retained_bytes_availability'=>'NOT_PROVEN_BY_REFERENCE_READBACK',
    'original_publisher_identity'=>'UNKNOWN',
    'redaction_projection'=>'NOT_ADOPTED',
    'u03_multi_owner_ratification'=>'PENDING',
    'u06_model_purpose_adoption'=>'PENDING',
    'can_read_original_source_bytes'=>false,
    'can_use_for_historical_analysis'=>false,
    'can_send_to_external_provider'=>false,
    'can_admit_model_result'=>false,
    'can_release_to_customer'=>false,
];
$fake['decision_digest']=Digest::canonicalDigest($fake);
Assert::true(HistoryReviewRightsGateway::verifyCandidate($fake)->isOk(),
    'typed source-only HOLD candidate structurally valid, never authority');
foreach([
    ['decision'=>'ALLOW'],
    ['u03_multi_owner_ratification'=>'ADOPTED'],
    ['source_license_for_historical_reuse'=>'LICENSED'],
    ['can_send_to_external_provider'=>true],
    ['can_use_for_historical_analysis'=>true],
    ['can_release_to_customer'=>true],
] as $mutation){
    $changed=array_replace($fake,$mutation);
    unset($changed['decision_digest']);
    $changed['decision_digest']=Digest::canonicalDigest($changed);
    Assert::same('HS02_CURRENT_USE_CANDIDATE_INVALID',
        HistoryReviewRightsGateway::verifyCandidate($changed)->reasonCode(),
        'rehashing a source-use approval cannot authorize it');
}
$old=$fake;$old['reference_count']=2;
Assert::same('HS02_CURRENT_USE_DIGEST_MISMATCH',
    HistoryReviewRightsGateway::verifyCandidate($old)->reasonCode(),
    'untrusted changed reference count fails immutable digest');
foreach(HistoryReviewRightsGateway::PURPOSES as $purpose){
    $packet=$fake;$packet['purpose']=$purpose;
    unset($packet['decision_digest']);
    $packet['decision_digest']=Digest::canonicalDigest($packet);
    Assert::true(HistoryReviewRightsGateway::verifyCandidate($packet)->isOk(),
        'purpose-specific HOLD is representable without source-use grant');
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/app/Workflow/HistoryReviewRightsGateway.php');
foreach(['curl_exec(', 'file_get_contents(', 'new PDO(', 'releaseP01(',
    'public function authorize(', 'public function execute('] as $prohibited){
    Assert::false(str_contains($source,$prohibited),
        'HS02 gateway cannot open source, query SQL itself or release output');
}
Assert::true(str_contains($source,'originOwner->inspect'),
    'genuine current P01/M05 owner reference reader required');
