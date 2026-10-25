<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;

use Allfather\Loopia\Core\Outcome;

/** Deployment-authored offline pack. Resolving content is NEVER approval or dispatch authority. */
final class ProductPromptPack
{
    public const ROLES = ['P01_SIGNAL_CLASSIFIER', 'BATTLECARD_GENERATOR', 'MONITOR_REVIEW_CRITIC', 'MONITOR_ROUTING_CRITIC'];
    public const PACK_VERSION = '2026-09-27.monitor.v1';

    /** Explicit OFFLINE version lookup. Existing execution still resolves only v1. */
    public static function resolveVersion(string $role, string $packVersion): Outcome
    {
        return PromptBundleRegistry::resolve($role, $packVersion);
    }

    public static function resolve(string $role): Outcome
    {
        if (!in_array($role, self::ROLES, true)) { return Outcome::refuse('PROMPT_ROLE_NOT_IN_PRODUCT_PACK'); }
        try {
            $data = json_decode((string) file_get_contents(__DIR__ . '/bundles/' . self::PACK_VERSION . '.json'), false, 64, JSON_THROW_ON_ERROR);
            if ($data->pack_version !== self::PACK_VERSION || $data->activation_status !== 'INACTIVE'
                || $data->evaluation_status !== 'OFFLINE_CONTRACT_TESTED' || $data->model_evaluated !== false) {
                return Outcome::refuse('PROMPT_PACK_STATE_INVALID');
            }
            $matches = array_values(array_filter($data->prompts, static fn ($p) => $p->role === $role));
            if (count($matches) !== 1) { return Outcome::refuse('PROMPT_PACK_ROLE_COUNT_INVALID'); }
            $p = $matches[0];
            $out = [
                'role' => $p->role, 'prompt_id' => $p->prompt_id, 'version' => self::PACK_VERSION,
                'prompt_version_id' => $p->prompt_id . '@' . self::PACK_VERSION,
                'binding_id' => 'loopia.monitor.' . $role . '@' . self::PACK_VERSION,
                'schema_version' => $p->schema_version, 'output_schema_id' => $p->schema_version,
                'output_contract_id' => $p->output_contract_id, 'prompt_sha256' => $p->prompt_sha256,
                'schema_sha256' => $p->schema_sha256, 'instructions' => $p->instructions,
                'output_schema' => $p->output_schema, 'pack_version' => self::PACK_VERSION,
                'record_source' => 'deployment-authored-immutable-bundle',
                'evaluation_status' => 'OFFLINE_CONTRACT_TESTED', 'model_evaluated' => false,
                'approval_status' => 'DRAFT', 'activation_status' => 'INACTIVE',
                'execution_authorized' => false, 'live_llm_calls_enabled' => false,
                'external_action_authorized' => false, 'customer_output_authorized' => false,
            ];
            if (!self::verifyHashes($out)) { return Outcome::refuse('PROMPT_PACK_DIGEST_MISMATCH'); }
            return Outcome::ok($out);
        } catch (\Throwable) { return Outcome::failure('PROMPT_PACK_INVALID'); }
    }

    /** Recompute identities; caller must still compare with resolve(role), not trust a self-hashed prompt. */
    public static function verifyHashes(array $p): bool
    {
        try {
            $prompt = CanonicalContracts::hashPromptVersion([
                'promptId' => $p['prompt_id'], 'role' => $p['role'], 'version' => $p['version'],
                'systemPrompt' => $p['instructions'], 'schemaVersion' => $p['schema_version'],
            ]);
            $schema = CanonicalContracts::hashSchema($p['schema_version'], $p['output_schema'], $p['output_contract_id']);
            return is_string($p['prompt_sha256']) && is_string($p['schema_sha256'])
                && hash_equals($prompt, $p['prompt_sha256']) && hash_equals($schema, $p['schema_sha256']);
        } catch (\Throwable) { return false; }
    }
}
