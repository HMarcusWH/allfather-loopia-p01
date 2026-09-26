<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

/** Explicit offline test double; no DNS, socket, key persistence or fallback. */
final class FixtureModelTransport implements ModelTransport
{
    public int $calls = 0;
    public function __construct(private readonly \Closure $response) {}
    public function isLive(): bool { return false; }
    public function send(PreparedModelRequest $request, string $credential): ModelResponse
    {
        ++$this->calls;
        return ($this->response)($request);
    }
}
