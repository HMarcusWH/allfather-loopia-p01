<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * The raw response a transport returns. Redirects are NOT followed by the transport — a 3xx comes
 * back verbatim with its Location header so the guard owns redirect policy.
 */
final class FetchResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $contentType,
        public readonly ?string $locationHeader,
        public readonly string $body,
    ) {
    }
}
