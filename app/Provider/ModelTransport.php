<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

interface ModelTransport
{
    public function isLive(): bool;
    public function send(PreparedModelRequest $request, string $credential): ModelResponse;
}
