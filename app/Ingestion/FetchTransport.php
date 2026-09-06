<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * The injected network transport.
 *
 * Production supplies an https GET/HEAD honoring the timeout and the resolve-connect pin; tests
 * and M11 replay supply a deterministic fake and never touch the network. It MUST NOT follow
 * redirects itself — the guard owns redirect policy.
 */
interface FetchTransport
{
    /** @throws TransportTimeout on a deadline; any other Throwable is a transport error. */
    public function send(FetchRequest $request): FetchResponse;
}
