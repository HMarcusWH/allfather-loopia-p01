<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\PointInTimeHistoricalBasis;

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$v['scope'],'period'=>$v['period'],'baseline_period'=>$v['baseline_period'],
    'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage'],
];
$r=['calendar'=>PointInTimeHistoricalBasis::CALENDAR,'legacy_input'=>$input,
    'previous_basis_digest'=>null,'review_at_utc'=>'2026-03-16T00:00:00Z'];
$m=PointInTimeHistoricalBasis::freeze($r)->value();
foreach(['historical_reuse_admitted','model_context_authorized','provider_disclosure_authorized',
         'native_persistence_authorized','customer_release_authorized',
         'coverage_owner_verified','origin_independence_verified'] as $flag){
    $forged=$m;$forged[$flag]=true;
    $unsigned=$forged;unset($unsigned['basis_digest']);
    $forged['basis_digest']=Digest::canonicalDigest($unsigned);
    Assert::same('HS01_BASIS_CONTRACT_INVALID',PointInTimeHistoricalBasis::verify($forged)->reasonCode(),
        "forged {$flag} cannot be approved by self-hashing");
}
$forged=$r;$forged['legacy_input']['rows'][0]['allow_new_use']=true;
Assert::same('LI05_ROW_CONTRACT_INVALID',PointInTimeHistoricalBasis::freeze($forged)->reasonCode(),
    'untrusted source-row grant refused');
$forged=$r;$forged['model_grant']='APPROVED';
Assert::same('HS01_REQUEST_CONTRACT_INVALID',PointInTimeHistoricalBasis::freeze($forged)->reasonCode(),
    'request cannot mint a model grant');
$forged=$m;$forged['selected_references'][0]['rights_disposition']='AUTHORIZED';
$payload=$forged;unset($payload['basis_digest']);
$forged['basis_digest']=Digest::canonicalDigest($payload);
Assert::same('HS01_BASIS_REFERENCE_INVALID',PointInTimeHistoricalBasis::verify($forged)->reasonCode(),
    'reference rights cannot be forged even with matching digest');
$source=(string)file_get_contents(dirname(__DIR__,2).'/app/Workflow/PointInTimeHistoricalBasis.php');
foreach (['curl_exec(', 'file_get_contents(', 'new PDO(', 'new ModelExecutor(', 'releaseP01(',
          'public function dispatch(', 'CREATE TABLE'] as $forbidden){
    Assert::false(str_contains($source,$forbidden),'candidate selector never gains transport, SQL or release methods');
}
$preview=PointInTimeHistoricalBasis::preview($m)->value();
foreach(['selected_references','excluded_references','scope','source_input_digest'] as $hidden){
    Assert::false(array_key_exists($hidden,$preview),'preview suppresses private linkage '.$hidden);
}
