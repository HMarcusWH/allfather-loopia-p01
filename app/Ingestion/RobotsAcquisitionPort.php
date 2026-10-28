<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Outcome;

/**
 * CR-03 typed acquisition seam. Input is one exact owner-scoped robots URI.
 * A policy decision is NOT an M06/M01 source-read authorization. The live
 * adapter remains deliberately unimplemented until separate owner review.
 */
interface RobotsAcquisitionPort
{
    /** @param array<string,mixed> $scope AcquisitionScope.v1
     *  @return Outcome<array<string,mixed>> RobotsPolicyBytes.v1
     */
    public function obtain(array $scope): Outcome;
}
