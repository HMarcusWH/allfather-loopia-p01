<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Outcome;

/**
 * Future funded model-evaluation seam, disabled until separate U01/U06
 * owner-ratified version, M02/M03/M06/M01 execution admission, purpose-bound
 * grant, prompt/schema hash, provider budget and reviewed source policy.
 * A caller-provided JSON "grant" is never proof of any of those.
 */
interface HistoricalSequenceModelExecutionPort
{
    /** @return Outcome<array<string,mixed>> */
    public function dispatch(array $purportedGrant, array $case): Outcome;
}
