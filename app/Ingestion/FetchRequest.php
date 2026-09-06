<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * One governed request handed to a transport.
 *
 * `pinnedIp` is the ING-T03 resolve-connect pin: the guard has ALREADY resolved and vetted the
 * host, and the transport must connect to exactly this address without re-resolving. A transport
 * that ignores it reopens the DNS-rebinding TOCTOU, so the production transport treats a missing
 * pin as a fatal error rather than falling back to its own resolution.
 */
final class FetchRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $timeoutMs,
        public readonly ?string $pinnedIp = null,
        public readonly ?int $maxResponseBytes = null,
    ) {
    }
}
