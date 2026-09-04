<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * A transport deadline. Distinguished from a general transport error because the guard maps it to
 * the canonical `TIMEOUT` failure reason, which the source-policy cadence logic treats differently
 * from a hard failure.
 */
final class TransportTimeout extends \RuntimeException
{
}
