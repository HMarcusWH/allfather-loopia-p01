<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * Resolves a hostname to IP literals.
 *
 * Injected so the guard stays deterministic and testable, and so the resolved addresses are
 * screened against the denylist BEFORE the transport connects.
 */
interface HostResolver
{
    /** @return list<string> IP literals; an empty list means unresolvable, which is a refusal. */
    public function resolve(string $host): array;
}
