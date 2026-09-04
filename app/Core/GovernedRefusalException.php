<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * Carries a governed refusal across a call boundary that cannot return an Outcome
 * (a constructor, a validator inside a loop, a repository invariant).
 *
 * The refusal keeps its canonical reason code all the way to the audit record; catching this and
 * rethrowing a generic exception would erase the conformance surface, so handlers must read
 * `outcome()` rather than `getMessage()`.
 */
final class GovernedRefusalException extends \RuntimeException
{
    public function __construct(private readonly Outcome $outcome)
    {
        parent::__construct('GOVERNED_REFUSAL: ' . implode(',', $outcome->reasonCodes));
    }

    public function outcome(): Outcome
    {
        return $this->outcome;
    }
}
