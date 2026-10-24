<?php
declare(strict_types=1);
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Provider\{ModelExecutor,ModelResponse,ModelRequestBuilder,OpenAIResponsesTransport,StructuredOutput};
use Allfather\Loopia\Tests\Assert;

$cases=json_decode(file_get_contents(__DIR__.'/../fixtures/monitor_prompt_offline_cases.json'),true,128,JSON_THROW_ON_ERROR)['cases'];
$sample=$cases[0];$context=$sample['context'];
$context['envelope']+=['mission_id'=>'mission-fixture','purpose'=>'Internal change review','audience'=>'SALES','routing_target'=>['target_org_role'=>'SALES','redaction_profile'=>[]],'limits'=>['max_input_bytes'=>65536,'max_output_tokens'=>2048,'max_model_calls'=>4,'max_observation_age_seconds'=>86400]];
foreach($context['envelope']['evidence'] as $i=>&$item)$item['binding']=['monitored_source_id'=>'monitored-fixture','snapshot_sequence'=>$i+1];unset($item);
$context['digest']=Digest::canonicalDigest($context['envelope']);
$prompt=ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value();
$prepared=ModelRequestBuilder::prepare($prompt,$context);
Assert::true($prepared->isOk(),'reviewed inactive prompt can prepare an inspectable offline request');
if(!$prepared->isOk())return;
$request=$prepared->value();$inspection=$request->inspect();$body=json_decode($request->jsonBody(),true,64,JSON_THROW_ON_ERROR);
Assert::same('https://api.openai.com/v1/responses',$inspection['endpoint'],'endpoint is fixed');
Assert::same(['Authorization','Content-Type','Accept'],$inspection['header_names'],'inspection discloses header names only');
Assert::same(false,$body['store'],'provider storage is explicitly off');
Assert::same('gpt-5.6-luna',$body['model'],'retired model is replaced by the documented supported substitute');
Assert::same(['effort'=>'none'],$body['reasoning'],'fixed no-reasoning budget is part of the approved request');
Assert::same('none',\Allfather\Loopia\Provider\ModelPolicy::definition()['reasoning_effort'],'reasoning effort is included in the policy digest');
Assert::same(true,$body['text']['format']['strict'],'structured output strict mode');
Assert::same($prompt['instructions'],$body['instructions'],'only bundle instructions');
Assert::same(Digest::canonicalize($context['envelope']),$body['input'],'input is exactly canonical scoped context data');
Assert::false(isset($body['tools']),'no provider tools');
$oldDigest=$request->digest();$inspection['body']->model='other';$prompt['instructions']='caller override';$context['envelope']['purpose']='caller mutation';
Assert::same($oldDigest,$request->digest(),'caller inspection and source-array mutations cannot change prepared request');
Assert::same('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE',ModelRequestBuilder::prepare($prompt,$context)->reasonCode(),'self-edited prompt refuses');
$prompt=ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value();$context=$request->provenance()['context'];
foreach(['url'=>'https://other.test','instructions'=>'override','tools'=>[],'timeout'=>1] as $key=>$value)Assert::same('MODEL_OPTION_NOT_ALLOWED',ModelRequestBuilder::prepare($prompt,$context,[$key=>$value])->reasonCode(),'non-allowlisted option refuses: '.$key);
Assert::same('MODEL_NOT_APPROVED_FOR_BOUNDED_ADAPTER',ModelRequestBuilder::prepare($prompt,$context,['model'=>'gpt-other'])->reasonCode(),'model cannot be selected arbitrarily');
Assert::same('MODEL_NOT_APPROVED_FOR_BOUNDED_ADAPTER',ModelRequestBuilder::prepare($prompt,$context,['model'=>'gpt-4.1-nano'])->reasonCode(),'retired model is no longer an executable option');
Assert::same('MODEL_OUTPUT_BUDGET_INVALID',ModelRequestBuilder::prepare($prompt,$context,['max_output_tokens'=>2049])->reasonCode(),'token cap');
$modified=$context;$modified['envelope']['purpose']='changed';
Assert::same('MODEL_CONTEXT_DIGEST_MISMATCH',ModelRequestBuilder::prepare($prompt,$modified)->reasonCode(),'changed context without binding refuses');
$modified=$context;array_shift($modified['envelope']['evidence']);$modified['digest']=Digest::canonicalDigest($modified['envelope']);
Assert::same('MODEL_CLASSIFIER_REQUIRES_ORDERED_OBSERVATIONS',ModelRequestBuilder::prepare($prompt,$modified)->reasonCode(),'baseline cannot be called a change');
$modified=$context;$modified['envelope']['evidence'][1]['binding']['monitored_source_id']='different';$modified['digest']=Digest::canonicalDigest($modified['envelope']);
Assert::same('MODEL_CLASSIFIER_REQUIRES_ORDERED_OBSERVATIONS',ModelRequestBuilder::prepare($prompt,$modified)->reasonCode(),'arbitrary pair cannot be called a change');
Assert::same('MODEL_TRANSPORT_INACTIVE',(new OpenAIResponsesTransport())->send($request,'synthetic-test-only')->transportFailure,'native default refuses before any cURL attempt');
Assert::same('MODEL_PROMPT_INACTIVE',(new OpenAIResponsesTransport(true))->send($request,'synthetic-test-only')->transportFailure,'boolean cannot self-promote prompt activation');

$wire=static fn(array $candidate):string=>json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($candidate,JSON_THROW_ON_ERROR)]]]],'usage'=>['input_tokens'=>10,'output_tokens'=>20]],JSON_THROW_ON_ERROR);
$result=ModelExecutor::interpret(new ModelResponse(200,$wire($sample['candidate'])),$request);
Assert::same('GENERATED',$result['kind'],'strict valid candidate parses');
Assert::same(Digest::canonicalDigest($sample['candidate']),$result['result_digest'],'candidate exact digest retained');
Assert::same(10,$result['input_tokens'],'bounded usage metadata');
$bad=$sample['candidate'];$bad['invented']='not schema';
Assert::same('MODEL_OUTPUT_SCHEMA_INVALID',ModelExecutor::interpret(new ModelResponse(200,$wire($bad)),$request)['reason_code'],'extra output properties fail closed');
$bad=$sample['candidate'];$bad['source_injection_detected']='false';
Assert::same('MODEL_OUTPUT_SCHEMA_INVALID',ModelExecutor::interpret(new ModelResponse(200,$wire($bad)),$request)['reason_code'],'boolean cannot be string');
foreach(['insufficient_quota','credit_balance_exhausted','organization_spend_limit_exceeded','project_spend_limit_exceeded','organization_usage_limit_exceeded'] as $code){
 $r=ModelExecutor::interpret(new ModelResponse(429,json_encode(['error'=>['code'=>$code,'type'=>'billing','message'=>'DO-NOT-LEAK']])), $request);
 Assert::same('MODEL_QUOTA_EXHAUSTED',$r['reason_code'],'billing code terminal: '.$code);
 Assert::false(str_contains(json_encode($r),'DO-NOT-LEAK'),'provider error message is absent');
}
foreach([[401,'{}','MODEL_AUTHENTICATION_REFUSED'],[403,'{}','MODEL_AUTHENTICATION_REFUSED'],[429,'{"error":{"code":"rate_limit_exceeded"}}','MODEL_RATE_LIMITED'],[429,'{"error":{"code":"new_code","type":"insufficient_quota"}}','MODEL_QUOTA_EXHAUSTED'],[500,'{}','MODEL_PROVIDER_UNAVAILABLE'],[400,'{}','MODEL_HTTP_BAD_REQUEST'],[404,'{}','MODEL_HTTP_NOT_FOUND'],[422,'{}','MODEL_HTTP_UNPROCESSABLE'],[409,'{}','MODEL_HTTP_REFUSED'],[200,'not json','MODEL_RESPONSE_INVALID_JSON'],[200,'{"status":"incomplete","output":[]}','MODEL_RESPONSE_NOT_COMPLETED'],[200,'{"status":"completed","output":[]}','MODEL_OUTPUT_CARDINALITY_INVALID'],[200,'{"status":"completed","output":[{"type":"function_call"}]}','MODEL_UNEXPECTED_OUTPUT_ITEM'],[200,'{"status":"completed","output":[{"type":"message","content":[{"type":"refusal","refusal":"secret-message"}]}]}','MODEL_DECLARED_REFUSAL']] as [$status,$json,$reason])Assert::same($reason,ModelExecutor::interpret(new ModelResponse($status,$json),$request)['reason_code'],'fixed response classification: '.$reason);
foreach ([400=>'MODEL_HTTP_BAD_REQUEST',404=>'MODEL_HTTP_NOT_FOUND',422=>'MODEL_HTTP_UNPROCESSABLE'] as $status=>$reason) {
 $r=ModelExecutor::interpret(new ModelResponse($status,json_encode(['error'=>['message'=>'SECRET PROVIDER DIAGNOSTIC','request_id'=>'PRIVATE']],JSON_THROW_ON_ERROR)),$request);
 Assert::same($reason,$r['reason_code'],'fixed status classification for HTTP '.$status);
 Assert::false(str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET PROVIDER DIAGNOSTIC'),'provider messages do not leak for HTTP '.$status);
 Assert::false(str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE'),'provider request metadata does not leak for HTTP '.$status);
}
Assert::same('MODEL_RESPONSE_TOO_LARGE',ModelExecutor::interpret(new ModelResponse(200,str_repeat('x',131073)),$request)['reason_code'],'bounded raw response');
Assert::same('MODEL_NETWORK_OUTCOME_UNKNOWN',ModelExecutor::interpret(new ModelResponse(0,'',null,'MODEL_NETWORK_OUTCOME_UNKNOWN'),$request)['reason_code'],'unknown outcome remains unknown');
foreach([[401,'','MODEL_AUTHENTICATION_REFUSED'],[403,'<html>denied</html>','MODEL_AUTHENTICATION_REFUSED'],[502,'<html>gateway</html>','MODEL_PROVIDER_UNAVAILABLE'],[503,'','MODEL_PROVIDER_UNAVAILABLE'],[429,'not-json','MODEL_RATE_LIMITED'],[400,'<html>bad request</html>','MODEL_HTTP_BAD_REQUEST'],[404,'<html>missing or retired model</html>','MODEL_HTTP_NOT_FOUND'],[422,'<html>bad schema</html>','MODEL_HTTP_UNPROCESSABLE']] as [$status,$raw,$reason])Assert::same($reason,ModelExecutor::interpret(new ModelResponse($status,$raw),$request)['reason_code'],'HTTP error classification does not depend on JSON body: '.$status);

$prior=['role'=>'P01_SIGNAL_CLASSIFIER','context_digest'=>$context['digest'],'request_digest'=>$request->digest(),'candidate_digest'=>$result['result_digest'],'candidate'=>$result['candidate']];
$draftPrompt=ProductPromptPack::resolve('BATTLECARD_GENERATOR')->value();
$missingTarget=$context;unset($missingTarget['envelope']['routing_target']);$missingTarget['digest']=Digest::canonicalDigest($missingTarget['envelope']);
Assert::same('MODEL_ROUTING_TARGET_NOT_BOUND',ModelRequestBuilder::prepare($draftPrompt,$missingTarget)->reasonCode(),'draft receives a selected configured target, not an inferred generic audience');
Assert::same('MODEL_PRIOR_CANDIDATE_NOT_BOUND',ModelRequestBuilder::prepare($draftPrompt,$context)->reasonCode(),'draft requires typed predecessor');
$draft=ModelRequestBuilder::prepare($draftPrompt,$context,['stage_inputs'=>['prior_candidate'=>$prior]]);
Assert::true($draft->isOk(),'typed validated prior candidate is a distinct untrusted input');
$prior['candidate']['limitations'][]='tampered';
Assert::same('MODEL_PRIOR_CANDIDATE_NOT_BOUND',ModelRequestBuilder::prepare($draftPrompt,$context,['stage_inputs'=>['prior_candidate'=>$prior]])->reasonCode(),'predecessor mutation breaks digest');
foreach(['P01_SIGNAL_CLASSIFIER','BATTLECARD_GENERATOR','MONITOR_REVIEW_CRITIC','MONITOR_ROUTING_CRITIC'] as $role){$schema=json_decode(json_encode(ProductPromptPack::resolve($role)->value()['output_schema']),true);Assert::true(StructuredOutput::schemaSupported($schema),'approved schema subset supported: '.$role);}
Assert::false(StructuredOutput::schemaSupported(['type'=>'string','$ref'=>'arbitrary']),'unknown schema keywords refuse');
