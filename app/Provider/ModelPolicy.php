<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\Digest;

/**
 * The one closed model policy the bounded adapter supports.
 *
 * These choices used to be scattered literals — the model name in PreparedModelRequest, the endpoint
 * and byte ceiling in the transport, the token ceiling in two places. A grant or a prompt approval
 * must bind the EXACT policy it was reviewed against, so the policy has one identity and one digest,
 * and changing any value below is a new policy that every approval and grant refuses until it is
 * re-reviewed. There is deliberately no registry of alternatives: adding a model is a reviewed code
 * change, never configuration.
 */
final class ModelPolicy
{
    public const ID = 'loopia.model-policy.bounded-v1';
    public const PROVIDER = 'OPENAI';
    public const MODEL = 'gpt-5.6-luna';
    // Fixed cost-sensitive no-reasoning mode; part of this policy's hash and request.
    public const REASONING_EFFORT = 'none';
    public const ENDPOINT = 'https://api.openai.com/v1/responses';
    public const MAX_OUTPUT_TOKENS = 2048;
    public const MIN_OUTPUT_TOKENS = 16;
    public const DEFAULT_OUTPUT_TOKENS = 1024;
    public const MAX_REQUEST_BYTES = 131072;
    public const MAX_RESPONSE_BYTES = 131072;
    public const MAX_INSTRUCTION_BYTES = 24000;
    public const CONNECT_TIMEOUT_MS = 5000;
    public const TOTAL_TIMEOUT_MS = 20000;
    /** The Responses API field that asks the provider not to store the exchange. It is not, by
     *  itself, the provider-side retention posture: that is an account setting reviewed separately. */
    public const STORE = false;

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'id' => self::ID, 'provider' => self::PROVIDER, 'model' => self::MODEL, 'reasoning_effort' => self::REASONING_EFFORT, 'endpoint' => self::ENDPOINT,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS, 'min_output_tokens' => self::MIN_OUTPUT_TOKENS,
            'max_request_bytes' => self::MAX_REQUEST_BYTES, 'max_response_bytes' => self::MAX_RESPONSE_BYTES,
            'max_instruction_bytes' => self::MAX_INSTRUCTION_BYTES, 'connect_timeout_ms' => self::CONNECT_TIMEOUT_MS,
            'total_timeout_ms' => self::TOTAL_TIMEOUT_MS, 'store' => self::STORE,
            'tools' => [], 'redirects' => 0, 'automatic_retries' => 0,
        ];
    }

    public static function digest(): string
    {
        return Digest::canonicalDigest(self::definition());
    }
}
