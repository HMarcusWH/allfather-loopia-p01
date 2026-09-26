<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

/** Transport result in memory. Never serialize raw headers, provider bodies or errors to telemetry. */
final class ModelResponse
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $body,
        public readonly ?string $requestId = null,
        public readonly ?string $transportFailure = null,
        public readonly int $durationMs = 0,
    ) {}
}
