<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

/**
 * The capability token that authorizes minting an `INTERNAL_INFORMATIONAL_RELEASE` record.
 *
 * Upstream uses a module-private `Symbol`, which PHP has no equivalent for. The nearest faithful
 * construction is this: a private constructor and a single factory. A caller cannot forge the
 * token by passing a matching string or array, because there is no matching value — only code
 * holding a reference to this class can produce one, and only `DecisionLedger::appendInternalRelease`
 * calls it.
 *
 * The point is not secrecy. It is that the record the console and the release-readiness projection
 * treat as PROOF of release cannot be fabricated by a caller that skipped the validation the
 * release path performs.
 */
final class ReleasePathCapability
{
    private function __construct()
    {
    }

    public static function grant(): self
    {
        return new self();
    }
}
