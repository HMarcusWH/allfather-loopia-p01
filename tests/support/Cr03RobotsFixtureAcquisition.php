<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Ingestion\RobotsAcquisitionPort;

/**
 * CLI test-only byte supply. Zero HTTP/DNS calls by construction.
 * Not PSR-4 autoloaded from the runtime's app/ prefix and never a live grant.
 */
final class Cr03RobotsFixtureAcquisition implements RobotsAcquisitionPort
{
    public int $readbacks = 0;
    public int $networkAttempts = 0;

    /** @param array<string,mixed> $source */
    public function __construct(private readonly array $source)
    {
    }

    public function obtain(array $scope): Outcome
    {
        if (PHP_SAPI !== 'cli') {
            return Outcome::refuse('CR03_FIXTURE_NOT_CLI');
        }
        foreach (['tenant_id', 'workspace_id', 'source_ref_id', 'source_card_id', 'robots_url'] as $key) {
            if (!isset($scope[$key]) || !isset($this->source[$key])
                || $scope[$key] !== $this->source[$key]) {
                return Outcome::refuse('CR03_FIXTURE_SOURCE_SCOPE_MISMATCH');
            }
        }
        if (($scope['native_execution_admitted'] ?? null) !== false
            || ($scope['owner_source_use'] ?? null) !== 'NOT_ADMITTED'
            || ($scope['purpose'] ?? null) !== 'OFFLINE_ROBOTS_INTERPRETATION') {
            return Outcome::refuse('CR03_FIXTURE_NOT_NATIVE_ADMISSION');
        }
        $this->readbacks++;
        return Outcome::ok($this->source);
    }
}
