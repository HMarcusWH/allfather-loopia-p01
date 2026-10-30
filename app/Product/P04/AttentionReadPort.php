<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Outcome;

/**
 * Future P04/M16/M10 owner read seam. Preflight and display must independently
 * recheck current reliance, source rights, role entitlement and redaction.
 * No production implementation is registered as part of AQ-02.
 */
interface AttentionReadPort
{
    public function inspectForAttention(
        array $source,array $scope,string $role,string $redactionDigest,
        string $stage,
    ):Outcome;
}
