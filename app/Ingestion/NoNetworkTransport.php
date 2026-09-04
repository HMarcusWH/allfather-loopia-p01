<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * A transport that refuses every request.
 *
 * This is the DEFAULT transport wherever live read is not activated. Upstream records
 * `LIVE_READ_ACTIVATED=FALSE`, and this projection inherits that: the production cURL transport
 * exists and is reviewed, but nothing wires it until the activation gate is opened by an owner act.
 *
 * Using this rather than simply not calling the guard means the whole ingestion path stays
 * exercisable — the code runs, reaches its network leaf, and is refused there — instead of the
 * inactive state being an untested branch.
 */
final class NoNetworkTransport implements FetchTransport
{
    public function send(FetchRequest $request): FetchResponse
    {
        throw new \RuntimeException('LIVE_READ_NOT_ACTIVATED');
    }
}
