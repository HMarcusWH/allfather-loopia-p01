<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

/**
 * A stream could neither be found nor created for a scope.
 *
 * Raised only when `ensureActiveStream` exhausts the canonical convergence: no ACTIVE stream
 * existed, creating one was refused, and re-reading found nothing either. That combination means
 * the refusal was NOT a lost race, so returning some other scope's stream — or a null the caller
 * would then have to interpret — would be worse than raising. A governed ledger with no stream is
 * a condition to stop on, not one to work around.
 */
final class LedgerStreamConflict extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
