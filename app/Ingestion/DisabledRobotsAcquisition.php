<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Outcome;

/**
 * Default production port for robots acquisition: no DNS, HTTP, file or
 * provider operations. A page source card never grants /robots.txt access.
 *
 * A real implementation would first need independently authenticated exact
 * robots-URL/source-card/purpose admission through the EXISTING M06/M01 and
 * SourcePolicy/EgressGuard owners. Merely changing a fixture boolean must
 * never activate the network.
 */
final class DisabledRobotsAcquisition implements RobotsAcquisitionPort
{
    public function obtain(array $scope): Outcome
    {
        return Outcome::refuse('CR03_DISTINCT_ROBOTS_REQUEST_NOT_ADMITTED', [], 'AUTHORITY');
    }
}
