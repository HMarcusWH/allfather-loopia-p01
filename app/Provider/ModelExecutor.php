<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Workflow\ContextEnvelope;
use Allfather\Loopia\PromptOps\{ProductPromptPack,CandidateChecks};

/** One governed attempt; no automatic retry, release, evidence promotion or direct provider path. */
final class ModelExecutor
{
    public function __construct(private readonly Database $db,private readonly Clock $clock,private readonly Gateway $gateway,private readonly CredentialStore $credentials,private readonly ModelTransport $transport,private readonly ContextEnvelope $context) {}

    public function execute(array $command,PreparedModelRequest $request,string $credentialId): array
    {
        $p=$request->provenance();$digest=$request->digest();
        $refuse=static fn(string $code):array=>['kind'=>'MODEL_REFUSED','reason_code'=>$code,'request_digest'=>$digest];
        $prompt=ProductPromptPack::resolve($p['role']);
        if(!$prompt->isOk()||Digest::canonicalDigest($prompt->value())!==Digest::canonicalDigest($request->resolvedPrompt()))return $refuse('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE');
        if($this->transport->isLive()&&$p['activation_status']!=='ACTIVE')return $refuse('MODEL_PROMPT_INACTIVE');
        foreach(['tenant_id','workspace_id','mission_id'] as $key)if(($command[$key]??null)!==$p[$key])return $refuse('MODEL_REQUEST_SCOPE_MISMATCH');
        if(($command['calling_actor_type']??null)!==($p['context']['scope']['actor_type']??null)||($command['calling_actor_id']??null)!==($p['context']['scope']['actor_id']??null))return $refuse('MODEL_CONTEXT_ACTOR_MISMATCH');
        $attemptId=null;$callId=null;$result=null;$response=null;
        $dispatch=$this->gateway->requestModelDispatch($command,$request,function(string $toolRequestId) use($p,$request,$credentialId,$command,&$attemptId,&$callId,&$result,&$response):array {
            $current=$this->context->revalidate($p['context']);
            if(!$current->isOk())return ['kind'=>'FAILED','reason_code'=>$current->reasonCode()];
            if($p['role']==='MONITOR_ROUTING_CRITIC'){$routing=$this->context->validateRoutingPackage($p['context'],$p['stage_inputs']['routing_package'],$p['stage_inputs']['prior_candidate']['candidate']);if(!$routing->isOk())return ['kind'=>'FAILED','reason_code'=>$routing->reasonCode()];}
            $reserved=$this->reserve($p,$request,$toolRequestId);
            if(!$reserved->isOk())return ['kind'=>'FAILED','reason_code'=>$reserved->reasonCode()];
            $attemptId=$reserved->value();
            try {
                $used=$this->credentials->withCredential($p['tenant_id'],$credentialId,fn(string $key):ModelResponse=>$this->transport->send($request,$key));
                if(!$used->isOk()) { $result=['kind'=>'FAILED','reason_code'=>'MODEL_CREDENTIAL_NOT_AVAILABLE','telemetry_outcome'=>'REFUSED_NO_CREDENTIAL']; }
                else {
                    $response=$used->value();
                    $result=self::interpret($response,$request);
                    if(($result['kind']??'')==='GENERATED') {
                        $candidateCheck=CandidateChecks::validate($result['candidate'],$request->resolvedPrompt(),$p['context']);
                        if(!$candidateCheck->isOk())$result=['kind'=>'FAILED','reason_code'=>'MODEL_CANDIDATE_REFERENCE_INVALID','telemetry_outcome'=>'FAILED'];
                    }
                }
            } catch(\Throwable) { $result=['kind'=>'FAILED','reason_code'=>'MODEL_ADAPTER_EXCEPTION','telemetry_outcome'=>'FAILED']; }
            $callId=Ids::new();
            // Metering only: never raw prompt, body, response, credential or provider error message.
            $this->db->insert('provider_call_records',['provider_call_id'=>$callId,'schema_version'=>'1','tenant_id'=>$p['tenant_id'],'workspace_id'=>$p['workspace_id'],'provider'=>'OPENAI','model'=>$p['model'],'request_class'=>$p['request_class'],'input_tokens'=>$result['input_tokens']??null,'output_tokens'=>$result['output_tokens']??null,'latency_ms'=>$response?->durationMs??0,'outcome'=>$result['telemetry_outcome'],'failure_code'=>$result['reason_code']??null,'correlation_id'=>$command['correlation_id'],'called_at'=>$this->clock->now()]);
            return $result;
        });
        if(($dispatch['kind']??'')==='IDEMPOTENT_REPLAY')return ['kind'=>'MODEL_REPLAY','request_digest'=>$digest,'dispatch'=>$dispatch];
        $succeeded=($dispatch['outcome_status']??'')==='SUCCEEDED' && ($result['kind']??'')==='GENERATED';
        $reason=$result['reason_code']??$dispatch['m01_reason_code']??($dispatch['reason_codes'][0]??'MODEL_DISPATCH_REFUSED');
        if(!$succeeded && isset($dispatch['m01_reason_code']))$reason=$dispatch['m01_reason_code'];
        if($succeeded){$freshContext=$this->context->revalidate($p['context']);if(!$freshContext->isOk()){$succeeded=false;$reason=$freshContext->reasonCode();}}
        if($succeeded&&$p['role']==='MONITOR_ROUTING_CRITIC'){$routing=$this->context->validateRoutingPackage($p['context'],$p['stage_inputs']['routing_package'],$p['stage_inputs']['prior_candidate']['candidate']);if(!$routing->isOk()){$succeeded=false;$reason=$routing->reasonCode();}}
        if($attemptId!==null) {
            try {
                $this->db->insert('provider_model_results',['model_result_id'=>Ids::new(),'model_attempt_id'=>$attemptId,'provider_call_id'=>$callId,'tool_request_id'=>$dispatch['tool_request_id'],'outcome'=>$succeeded?'CANDIDATE':'REFUSED','candidate_digest'=>$succeeded?$result['result_digest']:null,'provider_request_id'=>self::safeRequestId($response?->requestId),'failure_code'=>$succeeded?null:$reason,'recorded_at'=>$this->clock->now()]);
            } catch(\Throwable) { return $refuse('MODEL_PROVENANCE_FINALIZATION_FAILED')+['dispatch'=>$dispatch]; }
        }
        if(!$succeeded)return $refuse($reason)+['provider_call_id'=>$callId,'dispatch'=>$dispatch];
        return ['kind'=>'MODEL_CANDIDATE','candidate'=>$result['candidate'],'candidate_digest'=>$result['result_digest'],'request_digest'=>$digest,'provider_call_id'=>$callId,'execution_mode'=>$this->transport->isLive()?'LIVE':'FIXTURE','human_review_required'=>true,'dispatch'=>$dispatch];
    }

    private function reserve(array $p,PreparedModelRequest $request,string $toolRequestId): Outcome
    {
        return $this->db->transaction(function(Database $tx) use($p,$request,$toolRequestId):Outcome {
            $mission=$tx->one('SELECT status FROM missions WHERE mission_id=? AND tenant_id=? AND workspace_id=? FOR UPDATE',[$p['mission_id'],$p['tenant_id'],$p['workspace_id']]);
            if($mission===null||$mission['status']!=='ACTIVE')return Outcome::refuse('MODEL_MISSION_NOT_ACTIVE');
            $count=(int)$tx->scalar('SELECT COUNT(*) FROM provider_model_attempts WHERE tenant_id=? AND workspace_id=? AND mission_id=?',[$p['tenant_id'],$p['workspace_id'],$p['mission_id']]);
            if($count >= $p['context']['envelope']['limits']['max_model_calls'])return Outcome::refuse('MODEL_MISSION_BUDGET_EXHAUSTED');
            $id=Ids::new();$tx->insert('provider_model_attempts',['model_attempt_id'=>$id,'tenant_id'=>$p['tenant_id'],'workspace_id'=>$p['workspace_id'],'mission_id'=>$p['mission_id'],'tool_request_id'=>$toolRequestId,'request_digest'=>$request->digest(),'context_digest'=>$p['context_digest'],'prompt_version_id'=>$p['prompt_version_id'],'binding_id'=>$p['binding_id'],'output_schema_id'=>$p['output_schema_id'],'prompt_sha256'=>$p['prompt_sha256'],'schema_sha256'=>$p['schema_sha256'],'execution_mode'=>$this->transport->isLive()?'LIVE':'FIXTURE','reserved_at'=>$this->clock->now()]);
            return Outcome::ok($id);
        });
    }

    /** Pure bounded response parser. Only fixed error codes survive; no provider messages leak. */
    public static function interpret(ModelResponse $response,PreparedModelRequest $request): array
    {
        $failure=static fn(string $code,string $outcome='FAILED'):array=>['kind'=>'FAILED','reason_code'=>$code,'telemetry_outcome'=>$outcome];
        if($response->transportFailure!==null) {
            $code=in_array($response->transportFailure,['MODEL_TIMEOUT','MODEL_NETWORK_OUTCOME_UNKNOWN','MODEL_RESPONSE_TOO_LARGE','MODEL_TRANSPORT_INACTIVE','MODEL_TRANSPORT_UNAVAILABLE','MODEL_PROMPT_INACTIVE','MODEL_CREDENTIAL_INVALID'],true)?$response->transportFailure:'MODEL_TRANSPORT_FAILED';
            return $failure($code,$code==='MODEL_TIMEOUT'?'TIMEOUT':'FAILED');
        }
        if(strlen($response->body)>OpenAIResponsesTransport::MAX_RESPONSE_BYTES)return $failure('MODEL_RESPONSE_TOO_LARGE');
        if($response->httpStatus===401||$response->httpStatus===403)return $failure('MODEL_AUTHENTICATION_REFUSED');
        if($response->httpStatus>=500)return $failure('MODEL_PROVIDER_UNAVAILABLE');
        if($response->httpStatus!==429&&($response->httpStatus<200||$response->httpStatus>=300))return $failure('MODEL_HTTP_REFUSED');
        try { $decoded=json_decode($response->body,false,48,JSON_THROW_ON_ERROR); }
        catch(\Throwable) { return $failure($response->httpStatus===429?'MODEL_RATE_LIMITED':'MODEL_RESPONSE_INVALID_JSON'); }
        if($response->httpStatus===429) {
            $code=$decoded->error->code??null;$type=$decoded->error->type??null;
            return $failure(in_array($code,['insufficient_quota','credit_balance_exhausted','organization_spend_limit_exceeded','project_spend_limit_exceeded','organization_usage_limit_exceeded'],true)||$type==='insufficient_quota'?'MODEL_QUOTA_EXHAUSTED':'MODEL_RATE_LIMITED');
        }
        if(!($decoded instanceof \stdClass)||($decoded->status??'')!=='completed'||!is_array($decoded->output??null))return $failure('MODEL_RESPONSE_NOT_COMPLETED');
        $texts=[];
        foreach($decoded->output as $item) {
            if(($item->type??'')!=='message'||!is_array($item->content??null))return $failure('MODEL_UNEXPECTED_OUTPUT_ITEM');
            foreach($item->content as $part) {
                if(($part->type??'')==='refusal')return $failure('MODEL_DECLARED_REFUSAL');
                if(($part->type??'')!=='output_text'||!is_string($part->text??null))return $failure('MODEL_UNEXPECTED_OUTPUT_ITEM');
                $texts[]=$part->text;
            }
        }
        if(count($texts)!==1)return $failure('MODEL_OUTPUT_CARDINALITY_INVALID');
        try { $candidate=json_decode($texts[0],false,24,JSON_THROW_ON_ERROR); }
        catch(\Throwable) { return $failure('MODEL_OUTPUT_INVALID_JSON'); }
        if(!StructuredOutput::matches($candidate,$request->outputSchema()))return $failure('MODEL_OUTPUT_SCHEMA_INVALID');
        $value=json_decode($texts[0],true,24,JSON_THROW_ON_ERROR);
        $usage=static fn(mixed $v):?int=>is_int($v)&&$v>=0&&$v<=10000000?$v:null;
        return ['kind'=>'GENERATED','candidate'=>$value,'result_digest'=>Digest::canonicalDigest($candidate),'telemetry_outcome'=>'SUCCEEDED','input_tokens'=>$usage($decoded->usage->input_tokens??null),'output_tokens'=>$usage($decoded->usage->output_tokens??null)];
    }
    private static function safeRequestId(?string $value): ?string
    {
        return $value!==null&&preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$value)===1?$value:null;
    }
}
