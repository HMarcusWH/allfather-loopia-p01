<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

/**
 * A lost race on the ledger head.
 *
 * Thrown rather than returned so the enclosing transaction rolls back: returning a refusal from
 * inside the transaction would COMMIT the record rows while leaving the head unadvanced, producing
 * exactly the fork the compare-and-swap exists to prevent.
 */
final class LedgerSequenceConflict extends \RuntimeException
{
}
