<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Provider\StructuredOutput;

/** Explicit immutable content versions; lookup is NEVER an execution approval. */
final class PromptBundleRegistry
{
    public const OBSERVATION_V2_PACK = '2026-10-25.monitor.observation.v2';
    public const OBSERVATION_V2_SCHEMA = 'loopia.monitor.P01_SIGNAL_CLASSIFIER.output.v2';
    public const OBSERVATION_ROLE = 'P01_SIGNAL_CLASSIFIER';

    public static function resolve(string $role, string $packVersion): Outcome
    {
        if ($packVersion === ProductPromptPack::PACK_VERSION) {
            return ProductPromptPack::resolve($role); // exact legacy bytes
        }
        if ($packVersion !== self::OBSERVATION_V2_PACK || $role !== self::OBSERVATION_ROLE) {
            return Outcome::refuse('PROMPT_BUNDLE_VERSION_NOT_REGISTERED');
        }
        try {
            $raw = file_get_contents(__DIR__ . '/bundles/monitor-observation-v2.json');
            if (!is_string($raw)) return Outcome::failure('PROMPT_V2_BUNDLE_UNAVAILABLE');
            $data = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!($data instanceof \stdClass)
                || ($data->pack_version ?? null) !== self::OBSERVATION_V2_PACK
                || ($data->activation_status ?? null) !== 'INACTIVE'
                || ($data->evaluation_status ?? null) !== 'NOT_EVALUATED'
                || ($data->model_evaluated ?? null) !== false
                || ($data->canonical_role_extension ?? null) !== false
                || !is_array($data->prompts ?? null) || count($data->prompts) !== 1) {
                return Outcome::refuse('PROMPT_V2_BUNDLE_STATE_INVALID');
            }
            $p = $data->prompts[0];
            if (!($p instanceof \stdClass)
                || ($p->role ?? null) !== self::OBSERVATION_ROLE
                || ($p->prompt_id ?? null) !== 'loopia.monitor.P01_SIGNAL_CLASSIFIER'
                || ($p->schema_version ?? null) !== self::OBSERVATION_V2_SCHEMA
                || ($p->output_contract_id ?? null) !== 'loopia.monitor.P01_SIGNAL_CLASSIFIER.internal-observation-v2'
                || !is_string($p->instructions ?? null)
                || !is_string($p->prompt_sha256 ?? null)
                || !is_string($p->schema_sha256 ?? null)) {
                return Outcome::refuse('PROMPT_V2_BUNDLE_CONTRACT_INVALID');
            }
            $schema = json_decode(json_encode($p->output_schema ?? null, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || !StructuredOutput::schemaSupported($schema)
                || ($schema['type'] ?? null) !== 'object') {
                return Outcome::refuse('PROMPT_V2_SCHEMA_UNSUPPORTED');
            }
            $content = [
                'role'=>$p->role, 'prompt_id'=>$p->prompt_id,
                'version'=>self::OBSERVATION_V2_PACK,
                'prompt_version_id'=>$p->prompt_id.'@'.self::OBSERVATION_V2_PACK,
                'binding_id'=>'loopia.monitor.'.$role.'@'.self::OBSERVATION_V2_PACK,
                'schema_version'=>$p->schema_version, 'output_schema_id'=>$p->schema_version,
                'output_contract_id'=>$p->output_contract_id, 'prompt_sha256'=>$p->prompt_sha256,
                'schema_sha256'=>$p->schema_sha256, 'instructions'=>$p->instructions,
                'output_schema'=>$p->output_schema, 'pack_version'=>self::OBSERVATION_V2_PACK,
                'candidate_validator_version'=>'observation-candidate-v2-proposal',
                'contract_status'=>'PROPOSED_U01_NOT_RATIFIED',
                'record_source'=>'deployment-authored-immutable-bundle',
                'evaluation_status'=>'NOT_EVALUATED', 'model_evaluated'=>false,
                'approval_status'=>'DRAFT', 'activation_status'=>'INACTIVE',
                'execution_authorized'=>false, 'live_llm_calls_enabled'=>false,
                'external_action_authorized'=>false, 'customer_output_authorized'=>false,
            ];
            if (!ProductPromptPack::verifyHashes($content)) {
                return Outcome::refuse('PROMPT_V2_BUNDLE_DIGEST_MISMATCH');
            }
            return Outcome::ok($content);
        } catch (\Throwable) {
            return Outcome::failure('PROMPT_V2_BUNDLE_INVALID');
        }
    }
}
