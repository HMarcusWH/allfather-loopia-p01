<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Outcome;

/**
 * In-memory offline fixture source. This is NOT a provider or source transport:
 * the exact bytes are already loaded from the pinned local test fixture.
 * It never resolves domains, opens sockets, reads credentials or runs a model.
 */
final class HistoricalSequenceFixtureTransport
{
    public int $readbacks = 0;
    public int $networkAttempts = 0;

    /** @param array<string,mixed> $fixture */
    public function __construct(private readonly array $fixture) {}

    /** @return Outcome<array<string,mixed>> */
    public function read(): Outcome
    {
        if (PHP_SAPI !== 'cli') {
            return Outcome::refuse('VA01_FIXTURE_NOT_CLI');
        }
        if (($this->fixture['classification'] ?? null)
            !== 'PUBLIC_SYNTHETIC_FIXTURE_RESPONSES_NOT_MODEL_OR_HUMAN_SCORES') {
            return Outcome::refuse('VA01_FIXTURE_NOT_DEVELOPMENT');
        }
        $this->readbacks++;
        return Outcome::ok($this->fixture);
    }
}
