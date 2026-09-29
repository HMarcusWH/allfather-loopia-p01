<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Workflow\{ExecutionAdmission,ModelContextAuthority};
use Allfather\Loopia\PromptOps\{CandidateChecks,PromptSelectionResolver};

/** One governed attempt; no automatic retry, release, evidence promotion or direct provider path. */
final class ModelExecutor
{
    private readonly PromptSelectionResolver $prompts;

    /**
     * A live transport runs only with an ExecutionAdmission and a request whose prompt was selected
     * under an admitted grant. Without both it refuses before M06 is asked — nothing is reserved.
     */
    public function __construct(private readonly Database $db,private readonly Clock $clock,private readonly Gateway $gateway,private readonly CredentialStore $credentials,private readonly ModelTransport $transport,private readonly ModelContextAuthority $context,private readonly ModelArtifactStore $artifacts,?PromptSelectionResolver $prompts=null,private readonly ?ExecutionAdmission $admission=null)
    {
        $this->prompts=$prompts??PromptSelectionResolver::inert();
    }

    /** @param array<string,mixed> $admissionFacts the caller's bound facts: run, configuration, source, evaluation manifest. */
    public function execute(array $command,PreparedModelRequest $request,string $credentialId,array $admissionFacts=[]): array
    {
        $p=$request->provenance();$digest=$request->digest();
        $refuse=static fn(string $code):array=>['kind'=>'MODEL_REFUSED','reason_code'=>$code,'request_digest'=>$digest];
        $resolved=$request->resolvedPrompt();
        if(($resolved['role']??null)!==$p['role']||!$this->prompts->verify($resolved))return $refuse('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE');
        if($this->transport->isLive()&&$p['activation_status']!=='ACTIVE')return $refuse('MODEL_PROMPT_INACTIVE');
        foreach(['tenant_id','workspace_id','mission_id'] as $key)if(($command[$key]??null)!==$p[$key])return $refuse('MODEL_REQUEST_SCOPE_MISMATCH');
        if(($command['calling_actor_type']??null)!==($p['context']['scope']['actor_type']??null)||($command['calling_actor_id']??null)!==($p['context']['scope']['actor_id']??null))return $refuse('MODEL_CONTEXT_ACTOR_MISMATCH');
        $grantId=$p['execution_grant_id']??null;
        $facts=null;
        if($this->transport->isLive()||$grantId!==null){
            if($this->admission===null||!is_string($grantId))return $refuse('MODEL_EXECUTION_NOT_ADMITTED');
            $facts=array_merge($admissionFacts,['tenant_id'=>$p['tenant_id'],'workspace_id'=>$p['workspace_id'],'operator_id'=>$command['calling_actor_id'],'execution_grant_id'=>$grantId,'purpose'=>$p['execution_purpose'],'credential_id'=>$credentialId,'prompt_approval_id'=>$p['prompt_approval_id'],'prompt_sha256'=>$p['prompt_sha256'],'schema_sha256'=>$p['schema_sha256'],'request_digest'=>$digest]);
            if(($p['execution_purpose']??null)!=='MODEL_EVALUATION')$facts['mission_id']=$p['mission_id'];
            $admitted=$this->admission->check('MODEL_BEFORE_RESERVE',$facts);
            if(!$admitted->isOk())return $refuse($admitted->reasonCode());
        }
        $attemptId=null;$callId=null;$result=null;$response=null;$candidateArtifact=null;$modelResultId=null;
        $dispatch=$this->gateway->requestModelDispatch($command,$request,function(string $toolRequestId) use($p,$request,$credentialId,$command,$facts,$grantId,&$attemptId,&$callId,&$result,&$response,&$candidateArtifact):array {
            $current=$this->context->revalidate($p['context']);
            if(!$current->isOk())return ['kind'=>'FAILED','reason_code'=>$current->reasonCode()];
            if($p['role']==='MONITOR_ROUTING_CRITIC'){$routing=$this->context->validateRoutingPackage($p['context'],$p['stage_inputs']['routing_package'],$p['stage_inputs']['prior_candidate']['candidate']);if(!$routing->isOk())return ['kind'=>'FAILED','reason_code'=>$routing->reasonCode()];}
            $reserved=$this->reserve($p,$request,$toolRequestId,$facts);
            if(!$reserved->isOk())return ['kind'=>'FAILED','reason_code'=>$reserved->reasonCode()];
            $attemptId=$reserved->value();
            try {
                // Immediately before bytes leave: the grant must still be admitted. The attempt is
                // already reserved and stays spent — an honest receipt of a call that was not made.
                $fresh=$facts===null?null:$this->admission->check('MODEL_BEFORE_DISPATCH',$facts);
                if($fresh!==null&&!$fresh->isOk()){
                    $result=['kind'=>'FAILED','reason_code'=>$fresh->reasonCode(),'telemetry_outcome'=>'FAILED'];
                } else {
                    $used=$this->credentials->withCredential($p['tenant_id'],$credentialId,fn(string $key):ModelResponse=>$this->transport->send($request,$key));
                    if(!$used->isOk()) { $result=['kind'=>'FAILED','reason_code'=>'MODEL_CREDENTIAL_NOT_AVAILABLE','telemetry_outcome'=>'REFUSED_NO_CREDENTIAL']; }
                    else {
                        $response=$used->value();
                        $result=self::interpret($response,$request);
                        if(($result['kind']??'')==='GENERATED') {
                            // Checked against the prompt's immutable CONTENT: the selection was verified above,
                            // and a candidate's references do not depend on which grant permitted the call.
                            $content=$this->prompts->content($p['role']);
                            $candidateCheck=$content->isOk()?CandidateChecks::validate($result['candidate'],$content->value(),$p['context']):$content;
                            if(!$candidateCheck->isOk())$result=['kind'=>'FAILED','reason_code'=>'MODEL_CANDIDATE_REFERENCE_INVALID','telemetry_outcome'=>'FAILED'];
                        }
                    }
                    // After return, before acceptance: revocation during the call cannot unsend the
                    // request, but it does stop the response from becoming an accepted candidate.
                    if($facts!==null&&($result['kind']??'')==='GENERATED'){$after=$this->admission->check('MODEL_AFTER_RETURN',$facts);if(!$after->isOk())$result=['kind'=>'FAILED','reason_code'=>$after->reasonCode(),'telemetry_outcome'=>$result['telemetry_outcome'],'input_tokens'=>$result['input_tokens']??null,'output_tokens'=>$result['output_tokens']??null];}
                    if(($result['kind']??'')==='GENERATED'){
                        $persisted=$this->artifacts->persist($attemptId,$toolRequestId,$request->digest(),$result['candidate'],$result['result_digest']);
                        if(!$persisted->isOk())$result=['kind'=>'FAILED','reason_code'=>'MODEL_CANDIDATE_ARTIFACT_PERSIST_FAILED','telemetry_outcome'=>'FAILED','input_tokens'=>$result['input_tokens']??null,'output_tokens'=>$result['output_tokens']??null];
                        else $candidateArtifact=$persisted->value();
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
        if($succeeded&&$candidateArtifact===null){$succeeded=false;$reason='MODEL_CANDIDATE_ARTIFACT_MISSING';}
        if($attemptId!==null) {
            try {
                $modelResultId=Ids::new();
                $this->db->insert('provider_model_results',['model_result_id'=>$modelResultId,'model_attempt_id'=>$attemptId,'provider_call_id'=>$callId,'tool_request_id'=>$dispatch['tool_request_id'],'outcome'=>$succeeded?'CANDIDATE':'REFUSED','candidate_digest'=>$succeeded?$result['result_digest']:null,'provider_request_id'=>self::safeRequestId($response?->requestId),'failure_code'=>$succeeded?null:$reason,'recorded_at'=>$this->clock->now()]);
            } catch(\Throwable) { return $refuse('MODEL_PROVENANCE_FINALIZATION_FAILED')+['dispatch'=>$dispatch]; }
        }
        if(!$succeeded)return $refuse($reason)+['provider_call_id'=>$callId,'dispatch'=>$dispatch];
        return ['kind'=>'MODEL_CANDIDATE','candidate'=>$result['candidate'],'candidate_digest'=>$result['result_digest'],'request_digest'=>$digest,'provider_call_id'=>$callId,'model_attempt_id'=>$attemptId,'model_result_id'=>$modelResultId,'candidate_artifact_digest'=>$candidateArtifact['artifact_digest'],'execution_mode'=>$this->transport->isLive()?'LIVE':'FIXTURE','human_review_required'=>true,'dispatch'=>$dispatch];
    }

    private function reserve(array $p,PreparedModelRequest $request,string $toolRequestId,?array $facts=null): Outcome
    {
        return $this->db->transaction(function(Database $tx) use($p,$request,$toolRequestId,$facts):Outcome {
            $mission=$tx->one('SELECT status FROM missions WHERE mission_id=? AND tenant_id=? AND workspace_id=? FOR UPDATE',[$p['mission_id'],$p['tenant_id'],$p['workspace_id']]);
            if($mission===null||$mission['status']!=='ACTIVE')return Outcome::refuse('MODEL_MISSION_NOT_ACTIVE');
            // Evaluation attempts are explicitly synthetic and have their own grant budget. They
            // must not consume a product mission's lifetime model-call allowance merely because M06
            // uses an existing mission/assignment as the governance carrier. Historical attempts
            // without a purpose row remain product-budget attempts, preserving v1 semantics.
            $evaluation=($facts['purpose']??null)==='MODEL_EVALUATION';
            $count=$evaluation
                ?(int)$tx->scalar("SELECT COUNT(*) FROM provider_model_attempts a JOIN provider_attempt_purposes pp ON pp.model_attempt_id=a.model_attempt_id WHERE a.tenant_id=? AND a.workspace_id=? AND a.mission_id=? AND pp.execution_purpose='MODEL_EVALUATION'",[$p['tenant_id'],$p['workspace_id'],$p['mission_id']])
                :(int)$tx->scalar("SELECT COUNT(*) FROM provider_model_attempts a LEFT JOIN provider_attempt_purposes pp ON pp.model_attempt_id=a.model_attempt_id WHERE a.tenant_id=? AND a.workspace_id=? AND a.mission_id=? AND (pp.execution_purpose IS NULL OR pp.execution_purpose<>'MODEL_EVALUATION')",[$p['tenant_id'],$p['workspace_id'],$p['mission_id']]);
            if($count >= $p['context']['envelope']['limits']['max_model_calls'])return Outcome::refuse('MODEL_MISSION_BUDGET_EXHAUSTED');
            $id=Ids::new();
            // The grant budget is spent in the same transaction as the mission budget, under the
            // grant row's lock: concurrent work can exhaust neither.
            if($facts!==null){$spent=$this->admission->reserve($tx,$facts['execution_grant_id'],'MODEL_ATTEMPT',$id,$facts['monitor_run_id']??null);if(!$spent->isOk())return $spent;}
            $tx->insert('provider_model_attempts',['model_attempt_id'=>$id,'tenant_id'=>$p['tenant_id'],'workspace_id'=>$p['workspace_id'],'mission_id'=>$p['mission_id'],'tool_request_id'=>$toolRequestId,'request_digest'=>$request->digest(),'context_digest'=>$p['context_digest'],'prompt_version_id'=>$p['prompt_version_id'],'binding_id'=>$p['binding_id'],'output_schema_id'=>$p['output_schema_id'],'prompt_sha256'=>$p['prompt_sha256'],'schema_sha256'=>$p['schema_sha256'],'execution_mode'=>$this->transport->isLive()?'LIVE':'FIXTURE','reserved_at'=>$this->clock->now()]);
            // Why this attempt happened, beside it rather than inside it: a live call against a
            // synthetic evaluation case is not a product mission, and historical rows stay untouched.
            if($facts!==null){
                $evaluation=$facts['purpose']==='MODEL_EVALUATION';
                $tx->insert('provider_attempt_purposes',['model_attempt_id'=>$id,'execution_purpose'=>$facts['purpose'],'execution_grant_id'=>$facts['execution_grant_id'],'evaluation_run_id'=>$evaluation?($facts['evaluation_run_id']??null):null,'monitor_run_id'=>$evaluation?null:($facts['monitor_run_id']??null),'material_provenance'=>$evaluation?'SYNTHETIC_EVALUATION_CASE':'PRODUCT_MISSION','recorded_at'=>$this->clock->now()]);
            }
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
