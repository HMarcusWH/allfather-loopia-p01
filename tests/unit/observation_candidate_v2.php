<?php
declare(strict_types=1);

use Allfather\Loopia\PromptOps\CandidateChecks;
use Allfather\Loopia\PromptOps\CanonicalContracts;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\PromptOps\PromptBundleRegistry;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;
use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\Assert;

$pack=PromptBundleRegistry::OBSERVATION_V2_PACK;
$proposal=ProductPromptPack::resolveVersion('P01_SIGNAL_CLASSIFIER',$pack);
Assert::true($proposal->isOk(),'U01 inactive v2 prompt is inspectable by exact pack version');
if (!$proposal->isOk()) return;
$v2=$proposal->value();
Assert::same('PROPOSED_U01_NOT_RATIFIED',$v2['contract_status'],'proposal status cannot be mistaken for approval');
Assert::same('INACTIVE',$v2['activation_status'],'future proposal is inert');
Assert::same(false,$v2['execution_authorized'],'proposal grants no execution');
Assert::same('e3f5141e2728d751c860f496307708af1843e78f71ca14b93059e1290641b845',$v2['prompt_sha256'],'v2 prompt immutable SHA');
Assert::same('9a6ff980a81631690bf7c8a2c419f13b6fa719019e920b93aab29c8234f3627e',$v2['schema_sha256'],'v2 schema immutable SHA');
Assert::same($v2['schema_sha256'],PromptSelectionResolver::inert()->contentVersioned('P01_SIGNAL_CLASSIFIER',$pack)->value()['schema_sha256'],'one exact inert versioned resolver');
Assert::false(PromptSelectionResolver::inert()->verify($v2),'v2 cannot masquerade as the active v1 selection');
Assert::same('MODEL_PROPOSED_OBSERVATION_V2_INERT',PreparedModelRequest::prepare($v2,[]) ->reasonCode(),'offline v2 cannot become provider request');
Assert::same('PROMPT_BUNDLE_VERSION_NOT_REGISTERED',ProductPromptPack::resolveVersion('BATTLECARD_GENERATOR',$pack)->reasonCode(),'other roles have no implicit v2 promotion');
Assert::same('PROMPT_BUNDLE_VERSION_NOT_REGISTERED',ProductPromptPack::resolveVersion('P01_SIGNAL_CLASSIFIER','latest')->reasonCode(),'latest role fallback refused');
Assert::same('PROMPT_BUNDLE_VERSION_NOT_REGISTERED',ProductPromptPack::resolveVersion('P01_SIGNAL_CLASSIFIER','2026-10-25.monitor.observation.v3')->reasonCode(),'unknown v3 refused');
Assert::same('5310cb3b488db2a56a322b2a131872c934a3b69bd4edbc4a4822ec42c3ca180f',
    ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value()['prompt_sha256'],'original v1 prompt SHA unchanged');
Assert::same(CanonicalContracts::stableJson(ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value()),
    CanonicalContracts::stableJson(ProductPromptPack::resolveVersion('P01_SIGNAL_CLASSIFIER',ProductPromptPack::PACK_VERSION)->value()),'v1 explicit lookup is exact old content');

$ctx=['envelope'=>[
    'tenant_id'=>'tenant-a','workspace_id'=>'workspace-a','evidence'=>[
        ['evidence_id'=>'src-1','basis'=>'CAPTURED_SOURCE_OBSERVATION','tenant_id'=>'tenant-a','workspace_id'=>'workspace-a'],
        ['evidence_id'=>'src-2','basis'=>'CAPTURED_SOURCE_OBSERVATION','tenant_id'=>'tenant-a','workspace_id'=>'workspace-a'],
        ['evidence_id'=>'owner-1','basis'=>'TENANT_PROVIDED','tenant_id'=>'tenant-a','workspace_id'=>'workspace-a'],
    ],
]];
$observe=static fn(string $description,string $kind='NAV_ORDER',string $id='src-1'): array=>[
    'kind'=>$kind,'description_safe'=>$description,
    'citations'=>[$id],'claim_type'=>'DESCRIPTIVE_OBSERVATION','confidence'=>'HIGH',
];
$quiet=[
    'status'=>'NO_MATERIAL_CHANGE','language'=>'en',
    'observed_changes'=>[$observe('Pricing moves before Studios')],
    'relevance_hypotheses'=>[],'significance'=>'NONE',
    'attention_recommendation'=>'RETAIN_ONLY','limitations'=>[],
    'source_injection_detected'=>false,'signal_class'=>'MARKET_CONTEXT_SIGNAL',
];
$ok=CandidateChecks::validate($quiet,$v2,$ctx);
Assert::true($ok->isOk(),'menu reorder may be described without business materiality finding');
if (!$ok->isOk()) return;
Assert::same(false,$ok->value()['semantic_truth_verified'],'schema validation is not factual verification');
Assert::same(false,$ok->value()['execution_authorized'],'candidate cannot mint execution rights');
Assert::same(false,$ok->value()['release_authorized'],'candidate cannot mint release rights');
Assert::same(false,$ok->value()['notification_authorized'],'RETAIN_ONLY does not trigger notification');
Assert::same(true,$ok->value()['human_review_required'],'human review remains mandatory');

$legacy=ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value();
Assert::same('PROMPT_CANDIDATE_STATUS_INCONSISTENT',
    CandidateChecks::validate($quiet,$legacy,$ctx)->reasonCode(),
    'v1 must STILL refuse described quiet observations under its old rule');
$emptyQuiet=$quiet; $emptyQuiet['observed_changes']=[];
Assert::true(CandidateChecks::validate($emptyQuiet,$legacy,$ctx)->isOk(),'original empty v1 NO_MATERIAL_CHANGE remains valid');

$price=$quiet;
$price['status']='FINDING'; $price['significance']='MINOR';
$price['attention_recommendation']='REVIEW_LATER';
$price['signal_class']='PRICING_CHANGE_SIGNAL';
$price['observed_changes']=[$observe('Price changed from 449 SEK to 499 SEK','PRICE_OR_TERMS')];
Assert::true(CandidateChecks::validate($price,$v2,$ctx)->isOk(),'one-digit price change is an evidence-linked reviewable CANDIDATE');
$negation=$price;
$negation['observed_changes']=[$observe('Free trial is not available rather than available','PRICE_OR_TERMS','src-2')];
Assert::true(CandidateChecks::validate($negation,$v2,$ctx)->isOk(),'one-word negation can be retained');
$reviewNow=$price; $reviewNow['attention_recommendation']='REVIEW_NOW';
Assert::same(false,CandidateChecks::validate($reviewNow,$v2,$ctx)->value()['notification_authorized'],'REVIEW_NOW is advisory only');

$hypothesis=$price;
$hypothesis['relevance_hypotheses']=[[
  'hypothesis'=>'Could change tenant procurement',
  'basis_citations'=>['src-1'],'tenant_context_refs'=>['owner-1'],
  'uncertainty'=>'Tenant procurement timing has not been independently verified',
]];
Assert::true(CandidateChecks::validate($hypothesis,$v2,$ctx)->isOk(),'hypothesis cites source and tenant context separately');
$badHypothesis=$hypothesis;
$badHypothesis['relevance_hypotheses'][0]['tenant_context_refs']=['src-1'];
Assert::same('PROMPT_CANDIDATE_RELEVANCE_CITATION_INVALID',
    CandidateChecks::validate($badHypothesis,$v2,$ctx)->reasonCode(),'source citation cannot masquerade as tenant context');

$foreign=$quiet; $foreign['observed_changes'][0]['citations']=['foreign-id'];
Assert::same('PROMPT_CANDIDATE_OBSERVATION_CITATION_INVALID',
    CandidateChecks::validate($foreign,$v2,$ctx)->reasonCode(),'foreign/unknown IDs refused');
$wrongBasis=$quiet; $wrongBasis['observed_changes'][0]['citations']=['owner-1'];
Assert::same('PROMPT_CANDIDATE_OBSERVATION_CITATION_INVALID',
    CandidateChecks::validate($wrongBasis,$v2,$ctx)->reasonCode(),'tenant profile cannot counterfeit captured observation');
$foreignContext=$ctx; $foreignContext['envelope']['evidence'][0]['tenant_id']='another-tenant';
Assert::same('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS',
    CandidateChecks::validate($quiet,$v2,$foreignContext)->reasonCode(),'cross-tenant citation basis refused');
$duplicateContext=$ctx; $duplicateContext['envelope']['evidence'][]=$ctx['envelope']['evidence'][0];
Assert::same('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS',
    CandidateChecks::validate($quiet,$v2,$duplicateContext)->reasonCode(),'duplicate evidence identity refused');
$badSchema=$quiet; $badSchema['auto_release']=true;
Assert::same('PROMPT_CANDIDATE_V2_SHAPE_INVALID',
    CandidateChecks::validate($badSchema,$v2,$ctx)->reasonCode(),'unknown action field rejected by closed v2 schema');
$badAttention=$quiet; $badAttention['attention_recommendation']='REVIEW_NOW';
Assert::same('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT',
    CandidateChecks::validate($badAttention,$v2,$ctx)->reasonCode(),'quiet nonmaterial candidate cannot elevate urgency');

$injected=$quiet; $injected['source_injection_detected']=true;
Assert::same('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD',
    CandidateChecks::validate($injected,$v2,$ctx)->reasonCode(),'source injection requires a hold');
$held=$quiet;
$held['status']='HELD';$held['significance']='UNDETERMINED';$held['attention_recommendation']='HOLD';
$held['observed_changes']=[];$held['limitations']=['Source instruction cannot be isolated'];
$held['source_injection_detected']=true;
Assert::true(CandidateChecks::validate($held,$v2,$ctx)->isOk(),'blocked source injection yields inert HELD candidate');
$heldBad=$held;$heldBad['limitations']=[];
Assert::same('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT',
    CandidateChecks::validate($heldBad,$v2,$ctx)->reasonCode(),'HOLD without limitations not accepted');

$tampered=$v2;$tampered['instructions'].=' unsupported addition';
Assert::same('PROMPT_CANDIDATE_UNTRUSTED_BINDING',
    CandidateChecks::validate($quiet,$tampered,$ctx)->reasonCode(),'self edited prompt cannot pass validator');
$wrongVersion=$v2;$wrongVersion['pack_version']=ProductPromptPack::PACK_VERSION;
Assert::same('PROMPT_CANDIDATE_UNTRUSTED_BINDING',
    CandidateChecks::validate($quiet,$wrongVersion,$ctx)->reasonCode(),'v2 cannot claim v1 pack identity');
$synthetic=$ctx;$synthetic['envelope']['evidence'][0]['basis']='SYNTHETIC_EVALUATION';
Assert::same('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS',
    CandidateChecks::validate($quiet,$v2,$synthetic)->reasonCode(),'synthetic-only evidence cannot claim captured-source basis');
