<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryM17V2CandidateBridge};

$f=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$base=[
    'scope'=>$f['scope'],'period'=>$f['period'],'baseline_period'=>$f['baseline_period'],
    'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$f['knowledge_cutoff_utc'],
    'rows'=>$f['rows'],'max_references'=>8,
    'coverage'=>['state'=>'KNOWN','plan_version_id'=>'synthetic.only',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>0,'failed'=>0,'skipped'=>0,'unreported'=>0],
];
$r=['calendar'=>PointInTimeHistoricalBasis::CALENDAR,'legacy_input'=>$base,
    'previous_basis_digest'=>null,'review_at_utc'=>'2026-03-16T00:00:00Z'];
$basis=PointInTimeHistoricalBasis::freeze($r)->value();
$valid=HistoryM17V2CandidateBridge::compose($basis,'SOURCE_DIFF','MONTH');
Assert::true($valid->isOk(),'offline bridge fixture valid before negative tests');
if(!$valid->isOk())return;
$out=$valid->value();
foreach(['historical_source_use_admitted','p01_p02_cross_namespace_admitted',
    'profile_owner_admitted','runtime_persistence_authorized',
    'model_or_customer_output_authorized','production_authorized'] as $key){
    $fake=$out;$fake[$key]=true;
    $unsigned=$fake;unset($unsigned['bridge_digest']);
    $fake['bridge_digest']=Digest::canonicalDigest($unsigned);
    Assert::same('TM01_BRIDGE_AUTHORITY_FORGED',
        HistoryM17V2CandidateBridge::verify($fake)->reasonCode(),
        "self-hashed {$key} cannot grant control");
}
$bad=$basis;$bad['historical_reuse_admitted']=true;
Assert::same('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION',
    HistoryM17V2CandidateBridge::compose($bad,'SOURCE_DIFF','MONTH')->reasonCode(),
    'untrusted human-readable grant never legitimizes source use');
$bad=$basis;$bad['model_context_authorized']=true;
Assert::same('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION',
    HistoryM17V2CandidateBridge::compose($bad,'SOURCE_DIFF','MONTH')->reasonCode(),
    'provider entitlement not self-assertable by basis');
$bad=$basis;$bad['selection_integrity']='INSUFFICIENT_BASIS';
Assert::same('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION',
    HistoryM17V2CandidateBridge::compose($bad,'SOURCE_DIFF','MONTH')->reasonCode(),
    'invalid or insufficient basis cannot become M17 context');
Assert::same('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION',
    HistoryM17V2CandidateBridge::compose($basis,'M13_SCHEDULER','MONTH')->reasonCode(),
    'unadopted classes unavailable');
$forged=$out;$forged['candidate_v2']['run_identity']['subject_kind']='P02_ENTITY_TWIN';
$unsigned=$forged['candidate_v2'];unset($unsigned['candidate_digest']);
$forged['candidate_v2']['candidate_digest']=Digest::canonicalDigest($unsigned);
$unsigned=$forged;unset($unsigned['bridge_digest']);
$forged['bridge_digest']=Digest::canonicalDigest($unsigned);
Assert::same('TM01_BRIDGE_CONTRACT_INVALID',
    HistoryM17V2CandidateBridge::verify($forged)->reasonCode(),
    'P01 identity cannot be relabelled as a P02 twin with rehashing');
foreach(['app/Workflow/HistoryM17V2CandidateBridge.php',
         'app/Substrate/M17/P01WindowProfileV2.php'] as $file){
    $code=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
    foreach(['curl_exec(', 'new PDO(', 'file_get_contents(', 'releaseP01(',
        'public function execute(', 'class ModelExecutor'] as $prohibited){
        Assert::false(str_contains($code,$prohibited),
            'new TM01 algorithm never introduces side effects or source payload reads');
    }
}
