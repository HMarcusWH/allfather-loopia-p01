<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * A resolver with a fixed answer table.
 *
 * Used by tests, by fixture capture, and by M11 replay — a replay that resolved names for real
 * would depend on the state of the internet at replay time, which is exactly the non-determinism
 * replay exists to eliminate.
 */
final class FixedHostResolver implements HostResolver
{
    /** @param array<string, list<string>> $table */
    public function __construct(private readonly array $table)
    {
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        return $this->table[strtolower($host)] ?? [];
    }
}
