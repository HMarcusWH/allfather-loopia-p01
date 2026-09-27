<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;

/** Immutable serialized request. Inspection returns fresh values and never includes a credential. */
final class PreparedModelRequest
{
    private function __construct(private readonly string $body, private readonly string $provenance) {}

    /**
     * The prompt must be exactly what the trusted resolver derives: its inert content, or its
     * selection under an admitted grant. A caller-built or self-promoted array never verifies.
     */
    public static function prepare(array $prompt, array $context, array $options = [], ?PromptSelectionResolver $resolver = null): Outcome
    {
        try {
            $resolver ??= PromptSelectionResolver::inert();
            if (!$resolver->verify($prompt)) return Outcome::refuse('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE');
            foreach (['role','prompt_version_id','binding_id','output_schema_id','prompt_sha256','schema_sha256','instructions','activation_status'] as $key) {
                if (!isset($prompt[$key]) || !is_string($prompt[$key]) || $prompt[$key] === '') return Outcome::refuse('MODEL_PROMPT_CONTRACT_INVALID');
            }
            foreach (['prompt_sha256','schema_sha256'] as $key) if (preg_match('/^[a-f0-9]{64}$/D', $prompt[$key]) !== 1) return Outcome::refuse('MODEL_PROMPT_CONTRACT_INVALID');
            if (!isset($context['envelope'], $context['digest']) || !is_array($context['envelope']) || !hash_equals(Digest::canonicalDigest($context['envelope']), (string) $context['digest'])) return Outcome::refuse('MODEL_CONTEXT_DIGEST_MISMATCH');
            $envelope = $context['envelope'];
            if (array_diff(array_keys($options),['model','max_output_tokens','stage_inputs'])!==[]) return Outcome::refuse('MODEL_OPTION_NOT_ALLOWED');
            foreach (['tenant_id','workspace_id','mission_id','purpose','audience','limits','evidence'] as $key) if (!isset($envelope[$key])) return Outcome::refuse('MODEL_CONTEXT_CONTRACT_INVALID');
            $schema = json_decode(json_encode($prompt['output_schema'] ?? null, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || !StructuredOutput::schemaSupported($schema) || ($schema['type'] ?? null) !== 'object') return Outcome::refuse('MODEL_OUTPUT_SCHEMA_UNSUPPORTED');
            $model = $options['model'] ?? ModelPolicy::MODEL;
            if ($model !== ModelPolicy::MODEL) return Outcome::refuse('MODEL_NOT_APPROVED_FOR_BOUNDED_ADAPTER');
            $classes = ['P01_SIGNAL_CLASSIFIER'=>'SIGNAL_INTERPRETATION','BATTLECARD_GENERATOR'=>'DRAFT_COMPOSITION','MONITOR_REVIEW_CRITIC'=>'UNCERTAINTY_SUMMARY','MONITOR_ROUTING_CRITIC'=>'UNCERTAINTY_SUMMARY'];
            if (!isset($classes[$prompt['role']])) return Outcome::refuse('MODEL_ROLE_NOT_SUPPORTED');
            $inputDocument=$envelope;
            if($prompt['role']==='BATTLECARD_GENERATOR'&&(!is_array($envelope['routing_target']??null)||!in_array($envelope['routing_target']['target_org_role']??null,\Allfather\Loopia\Product\P04\Distribution::TARGET_ORG_ROLES,true)||!is_array($envelope['routing_target']['redaction_profile']??null)))return Outcome::refuse('MODEL_ROUTING_TARGET_NOT_BOUND');
            if ($prompt['role']==='P01_SIGNAL_CLASSIFIER') {
                $observations=array_values(array_filter($envelope['evidence'],static fn(array $e):bool=>($e['basis']??'')==='CAPTURED_SOURCE_OBSERVATION'));
                if (isset($options['stage_inputs']) || count($observations)!==2 || ($observations[0]['binding']['monitored_source_id']??null)!==($observations[1]['binding']['monitored_source_id']??null) || ($observations[0]['binding']['snapshot_sequence']??0)>=($observations[1]['binding']['snapshot_sequence']??0)) return Outcome::refuse('MODEL_CLASSIFIER_REQUIRES_ORDERED_OBSERVATIONS');
            } else {
                $stages=$options['stage_inputs']??[]; $prior=$stages['prior_candidate']??null;
                $priorRole=$prompt['role']==='BATTLECARD_GENERATOR'?'P01_SIGNAL_CLASSIFIER':'BATTLECARD_GENERATOR';
                $requiredStageKeys=$prompt['role']==='MONITOR_ROUTING_CRITIC'?['prior_candidate','routing_package']:['prior_candidate'];
                if (array_diff(array_keys($stages),$requiredStageKeys)!==[] || array_diff($requiredStageKeys,array_keys($stages))!==[] || !is_array($prior) || array_diff(array_keys($prior),['role','context_digest','request_digest','candidate_digest','candidate'])!==[] || ($prior['role']??'')!==$priorRole || ($prior['context_digest']??'')!==$context['digest'] || !is_array($prior['candidate']??null) || preg_match('/^[a-f0-9]{64}$/D',(string)($prior['request_digest']??''))!==1) return Outcome::refuse('MODEL_PRIOR_CANDIDATE_NOT_BOUND');
                if (!hash_equals(Digest::canonicalDigest($prior['candidate']),(string)($prior['candidate_digest']??''))) return Outcome::refuse('MODEL_PRIOR_CANDIDATE_NOT_BOUND');
                $priorPrompt=$resolver->content($priorRole);
                $priorObject=json_decode(json_encode($prior['candidate'],JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR);
                $priorSchema=json_decode(json_encode($priorPrompt->value()['output_schema'],JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
                if (!StructuredOutput::matches($priorObject,$priorSchema) || !\Allfather\Loopia\PromptOps\CandidateChecks::validate($prior['candidate'],$priorPrompt->value(),$context)->isOk()) return Outcome::refuse('MODEL_PRIOR_CANDIDATE_INVALID');
                $inputDocument['prior_candidate']=$prior;
                if($prompt['role']==='MONITOR_ROUTING_CRITIC'){
                    $routing=$stages['routing_package'];$keys=['schema_version','tenant_id','workspace_id','mission_id','role_distribution_briefing_id','target_org_role','briefing_summary_safe','released_briefing_id','source_decision_record_id','redaction_profile','current_redaction_profile'];
                    if(!is_array($routing)||array_diff(array_keys($routing),['envelope','digest'])!==[]||!is_array($routing['envelope']??null)||!is_string($routing['digest']??null)||!hash_equals(Digest::canonicalDigest($routing['envelope']),$routing['digest']))return Outcome::refuse('MODEL_ROUTING_PACKAGE_INVALID');
                    $r=$routing['envelope'];
                    if(array_diff(array_keys($r),$keys)!==[]||array_diff($keys,array_keys($r))!==[])return Outcome::refuse('MODEL_ROUTING_PACKAGE_INVALID');
                    foreach(array_diff($keys,['redaction_profile','current_redaction_profile']) as $key)if(!is_string($r[$key])||$r[$key]===''||strlen($r[$key])>16000)return Outcome::refuse('MODEL_ROUTING_PACKAGE_INVALID');
                    foreach(['tenant_id','workspace_id','mission_id'] as $key)if($r[$key]!==$envelope[$key])return Outcome::refuse('MODEL_ROUTING_PACKAGE_INVALID');
                    if($r['schema_version']!=='monitor-routing-review-v1'||!is_array($r['redaction_profile'])||!is_array($r['current_redaction_profile'])||!in_array($r['target_org_role'],\Allfather\Loopia\Product\P04\Distribution::TARGET_ORG_ROLES,true))return Outcome::refuse('MODEL_ROUTING_PACKAGE_INVALID');
                    $inputDocument['routing_package']=$routing;
                }
            }
            $tokens = $options['max_output_tokens'] ?? ModelPolicy::DEFAULT_OUTPUT_TOKENS;
            if (!is_int($tokens) || $tokens < ModelPolicy::MIN_OUTPUT_TOKENS || $tokens > ModelPolicy::MAX_OUTPUT_TOKENS || $tokens > ($envelope['limits']['max_output_tokens'] ?? 0)) return Outcome::refuse('MODEL_OUTPUT_BUDGET_INVALID');
            if (strlen($prompt['instructions']) > ModelPolicy::MAX_INSTRUCTION_BYTES) return Outcome::refuse('MODEL_INSTRUCTIONS_TOO_LARGE');
            // The entire user-input document is inspected and hashed. Source text remains data,
            // never a role/message/tool definition. No provider tools or arbitrary URLs are enabled.
            $body = [
                'model'=>$model, 'instructions'=>$prompt['instructions'],
                'input'=>Digest::canonicalize($inputDocument), 'max_output_tokens'=>$tokens, 'store'=>ModelPolicy::STORE,
                'text'=>['format'=>['type'=>'json_schema','name'=>'monitor_candidate_v1','strict'=>true,'schema'=>$prompt['output_schema']]],
            ];
            $json = json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (strlen($json) > ModelPolicy::MAX_REQUEST_BYTES) return Outcome::refuse('MODEL_REQUEST_TOO_LARGE');
            $provenance = [
                'schema_version'=>'1', 'provider'=>'OPENAI', 'model'=>$model,
                'request_class'=>$classes[$prompt['role']], 'role'=>$prompt['role'],
                'tenant_id'=>$envelope['tenant_id'], 'workspace_id'=>$envelope['workspace_id'], 'mission_id'=>$envelope['mission_id'],
                'prompt_version_id'=>$prompt['prompt_version_id'],'binding_id'=>$prompt['binding_id'],'output_schema_id'=>$prompt['output_schema_id'],
                'prompt_sha256'=>$prompt['prompt_sha256'],'schema_sha256'=>$prompt['schema_sha256'],
                'context_digest'=>$context['digest'],'activation_status'=>$prompt['activation_status'],
                // Which admitted grant and approval this request runs under — null for inert content.
                // They are inside the inspected digest, so a changed grant is a different request.
                'model_policy_id'=>ModelPolicy::ID,'model_policy_digest'=>ModelPolicy::digest(),
                'prompt_approval_id'=>$prompt['prompt_approval_id']??null,'execution_grant_id'=>$prompt['execution_grant_id']??null,
                'execution_purpose'=>$prompt['execution_purpose']??null,
                'context'=>$context, 'resolved_prompt'=>$prompt,'stage_inputs'=>$options['stage_inputs']??null,
            ];
            return Outcome::ok(new self($json, json_encode($provenance, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
        } catch (\Throwable) { return Outcome::refuse('MODEL_REQUEST_CONTRACT_INVALID'); }
    }

    public function jsonBody(): string { return $this->body; }
    public function provenance(): array { return json_decode($this->provenance, true, 64, JSON_THROW_ON_ERROR); }
    public function resolvedPrompt(): array { return (array)json_decode($this->provenance,false,64,JSON_THROW_ON_ERROR)->resolved_prompt; }
    public function outputSchema(): array { return json_decode($this->body, true, 64, JSON_THROW_ON_ERROR)['text']['format']['schema']; }
    public function digest(): string { return Digest::canonicalDigest(['body_sha256'=>hash('sha256',$this->body),'provenance'=>json_decode($this->provenance, false, 64, JSON_THROW_ON_ERROR)]); }
    public function inspect(): array { return ['request_digest'=>$this->digest(),'endpoint'=>ModelPolicy::ENDPOINT,'method'=>'POST','header_names'=>['Authorization','Content-Type','Accept'],'body'=>json_decode($this->body,false,64,JSON_THROW_ON_ERROR),'provenance'=>json_decode($this->provenance,false,64,JSON_THROW_ON_ERROR)]; }
}
