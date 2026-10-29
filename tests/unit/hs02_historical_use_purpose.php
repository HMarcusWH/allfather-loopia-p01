<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/Hs02SyntheticUse.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M17\HistoricalCaptureProbe;
use Allfather\Loopia\Tests\{Assert,Harness,Hs02SyntheticUse};
use Allfather\Loopia\Workflow\{
    HistoricalEvidenceEligibility,HistoryReviewContext,HistoryReviewRightsGateway,
    PointInTimeHistoricalBasis
};

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$v['scope'],'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,
    'coverage'=>['state'=>'KNOWN','plan_version_id'=>'hs02.synthetic.plan',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>0,'failed'=>0,
        'skipped'=>0,'unreported'=>0],
];
$basis=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-16T00:00:00Z',
]);
Assert::true($basis->isOk(),'HS02 reuses exact source-only HS01 frozen candidate');
if(!$basis->isOk())return;
$m=$basis->value();
Assert::same('COMPLETE_WITHIN_INPUT',$m['selection_integrity'],
    'genuine synthetic metadata computation has both eligible rows');
foreach(HistoryReviewRightsGateway::PURPOSES as $purpose){
    $posture=HistoricalEvidenceEligibility::purposePosture($purpose);
    Assert::true($posture->isOk(),'each distinct purpose has explicit named posture');
    Assert::same('HOLD',$posture->value()['decision'],'current U03 purpose cannot grant use');
    Assert::same(false,$posture->value()['historical_source_use_admitted'],
        'source use is never admitted from a purpose string');
    Assert::same(false,$posture->value()['external_provider_byte_disclosure_admitted'],
        'provider disclosure remains separately blocked');
}
Assert::same('HS02_HISTORICAL_USE_PURPOSE_UNKNOWN',
    HistoricalEvidenceEligibility::purposePosture('ALL_PURPOSES')->reasonCode(),
    'unknown/wildcard purpose cannot be promoted');
$context=new HistoryReviewContext();
$p=$context->previewPointInTime($m);
Assert::true($p->isOk(),'bounded HS01 v2 context summary is available without model');
Assert::same($m['basis_digest'],$p->value()['basis_digest'],
    'history preview has exact immutable basis identity');
Assert::same(false,$p->value()['model_context_authorized'],
    'preview does not authenticate model context');
Assert::same('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED',
    $context->revalidate([])->reasonCode(),'existing ModelContextAuthority remains fail closed');
Assert::same('LI05C_HISTORICAL_ROUTING_NOT_ADOPTED',
    $context->validateRoutingPackage([],[],[])->reasonCode(),
    'old routing cannot use a synthetic history preview');
Assert::false(str_contains(json_encode($p->value(),JSON_THROW_ON_ERROR),'source_a'),
    'preview hides individual M05 source and observation IDs');

$probe=(new ReflectionClass(HistoricalCaptureProbe::class))->newInstanceWithoutConstructor();
$gateway=new HistoryReviewRightsGateway($probe,Harness::clock('2026-10-29T08:00:00.000Z'));
$actor=[
    'tenant_id'=>$m['scope']['tenant_id'],'workspace_id'=>$m['scope']['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>'hs02_actor',
];
$wrong=$actor;$wrong['model_grant']=true;
Assert::same('HS02_SOURCE_USE_SELECTOR_INVALID',
    $gateway->inspect($wrong,$m,'DETERMINISTIC_INTERNAL_REVIEW')->reasonCode(),
    'caller-claimed model grant rejected before M05 owner access');
Assert::same('HS02_SOURCE_USE_SELECTOR_INVALID',
    $gateway->inspect($actor,$m,'UNKNOWN_PURPOSE')->reasonCode(),
    'unknown service purpose denied');
Assert::same('HS02_SOURCE_USE_SELECTOR_INVALID',
    $gateway->inspect($actor,$m,'DETERMINISTIC_INTERNAL_REVIEW',
        'PRE_DISPATCH',str_repeat('a',64))->reasonCode(),
    'prior attestation cannot be forged into initial pre-dispatch stage');
Assert::same('HS02_SOURCE_USE_SELECTOR_INVALID',
    $gateway->inspect($actor,$m,'DETERMINISTIC_INTERNAL_REVIEW',
        'POST_RETURN',null)->reasonCode(),
    'post-return without exact original fingerprint must refuse');
$foreign=$actor;$foreign['tenant_id']='foreign_owner';
Assert::same('HS02_BASIS_SCOPE_OR_CAP_INVALID',
    $gateway->inspect($foreign,$m,'DETERMINISTIC_INTERNAL_REVIEW')->reasonCode(),
    'cross-tenant basis refused before touching owner DB');
$forged=$m;$forged['historical_reuse_admitted']=true;
Assert::same('HS02_BASIS_INTEGRITY_INVALID',
    $gateway->inspect($actor,$forged,'DETERMINISTIC_INTERNAL_REVIEW')->reasonCode(),
    'recomputed permission never makes self-hash an authority certificate');
$future=$m;$future['review_at_utc']='2031-01-01T00:00:00Z';
$unsigned=$future;unset($unsigned['basis_digest']);
$future['basis_digest']=Digest::canonicalDigest($unsigned);
Assert::same('HS02_CURRENT_REVIEW_CLOCK_INVALID',
    $gateway->inspect($actor,$future,'DETERMINISTIC_INTERNAL_REVIEW')->reasonCode(),
    'future-as-of review cannot be represented as already executed');

if(!defined('ALLFATHER_HS02_TEST_FIXTURE')) define('ALLFATHER_HS02_TEST_FIXTURE',true);
$synthetic=Hs02SyntheticUse::run($m,'DETERMINISTIC_INTERNAL_REVIEW');
Assert::true($synthetic->isOk(),'non-exportable fixture runs bounded, real deterministic arithmetic');
Assert::same(2,$synthetic->value()['captured_reference_count'],
    'synthetic fixture counts actual frozen reference records');
Assert::same(1,$synthetic->value()['contrary_reference_count'],
    'contrary reference remains itemized rather than erased');
Assert::same(null,$synthetic->value()['real_world_event_count'],
    'two source captures are not independent real-world events');
Assert::same(false,$synthetic->value()['historical_reuse_admitted'],
    'fixture permission does not generalize to real U03 source use');
foreach(['MODEL_ASSISTED_HISTORICAL_ANALYSIS','EXTERNAL_PROVIDER_BYTE_DISCLOSURE'] as $purpose){
    Assert::same('HS02_FIXTURE_DETERMINISTIC_PURPOSE_ONLY',
        Hs02SyntheticUse::run($m,$purpose)->reasonCode(),
        'fixture cannot promote into funded model or byte disclosure');
}
