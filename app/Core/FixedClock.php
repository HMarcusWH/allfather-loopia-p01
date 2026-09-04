<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * A clock pinned to a fixed instant, optionally advancing by an explicit step.
 * Used by tests and replay so a run is reproducible without touching the wall clock.
 */
final class FixedClock extends Clock
{
    private \DateTimeImmutable $at;

    public function __construct(string $iso = '2026-01-01T00:00:00.000Z', private readonly int $autoAdvanceMs = 0)
    {
        $parsed = Clock::parse($iso);
        if ($parsed === null) {
            throw new \InvalidArgumentException("FixedClock: non-canonical timestamp {$iso}");
        }
        $this->at = $parsed;
    }

    public function now(): string
    {
        $value = Clock::format($this->at);
        if ($this->autoAdvanceMs > 0) {
            $this->advanceMs($this->autoAdvanceMs);
        }

        return $value;
    }

    public function advanceMs(int $ms): void
    {
        $this->at = $this->at->modify("+{$ms} milliseconds");
    }

    public function advanceDays(int $days): void
    {
        $this->at = $this->at->modify("+{$days} days");
    }
}
