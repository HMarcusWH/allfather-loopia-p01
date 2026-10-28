<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Outcome;

/**
 * The only actual historical model port for VA-01. Neither an apparent
 * MODEL_EVALUATION grant nor an owner-like signature can promote the unratified
 * U01/U06 v2 prompt or unfreeze the six private held-out payloads.
 */
final class DisabledHistoricalSequenceModelPort implements HistoricalSequenceModelExecutionPort
{
    public const STATUS = 'FUNDED_EVALUATION_NOT_ADOPTED_U01_U06';
    public int $providerCalls = 0;
    public int $networkCalls = 0;

    public function dispatch(array $purportedGrant, array $case): Outcome
    {
        return Outcome::refuse('VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED', [
            'model_attempted' => false,
            'provider_called' => false,
            'heldout_unblinded' => false,
        ], 'AUTHORITY');
    }

    public function capabilities(): array
    {
        return [
            'adapter_contract' => 'va01.historical-model-execution-port.v1',
            'status' => self::STATUS,
            'requires' => [
                'U01_OWNER_ADOPTION','U06_OWNER_ADOPTION','M02_M03_M06_M01_EXECUTION_ADMISSION',
                'EXACT_MODEL_EVALUATION_GRANT','PROMPT_SCHEMA_APPROVAL',
                'SOURCE_RIGHTS','COST_CEILING','INDEPENDENT_HELDOUT_CUSTODY',
            ],
            'provider_calls' => 0,
            'network_calls' => 0,
            'source_fetch' => false,
            'customer_release' => false,
        ];
    }
}
