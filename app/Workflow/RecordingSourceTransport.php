<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport};

/** Wraps the existing guarded transport. It adds no URL, DNS, redirect or authority decision. */
final class RecordingSourceTransport implements FetchTransport
{
    public ?array $payload = null;
    public ?string $failureCode = null;
    public function __construct(private readonly FetchTransport $inner) {}
    public function send(FetchRequest $request): FetchResponse
    {
        $response = $this->inner->send($request);
        if ($response->status >= 300 && $response->status < 400) { return $response; }
        if ($response->status !== 200) {
            $this->failureCode = 'MONITOR_HTTP_STATUS_NOT_CONTENT';
            throw new \RuntimeException($this->failureCode);
        }
        $type = strtolower(explode(';', $response->contentType ?? '')[0]);
        if (!in_array($type, ['text/plain', 'text/html', 'application/xhtml+xml'], true)
            || $response->body === '' || !mb_check_encoding($response->body, 'UTF-8')) {
            $this->failureCode = 'MONITOR_SOURCE_CONTENT_UNSUPPORTED';
            throw new \RuntimeException($this->failureCode);
        }
        $this->payload = ['url' => $request->url, 'body' => $response->body];
        return $response;
    }
}
