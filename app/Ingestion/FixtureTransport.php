<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * A deterministic transport over a fixed response table.
 *
 * This is what makes M11 replay side-effect free (INV-015): a replayed capture reaches this class,
 * not the network. It also records every request it received, so a test can assert what the guard
 * DID and DID NOT attempt — including that a refused hop never reached a transport at all.
 */
final class FixtureTransport implements FetchTransport
{
    /** @var list<FetchRequest> */
    public array $requests = [];

    /** @param array<string, FetchResponse|\Throwable> $responses keyed by URL */
    public function __construct(private readonly array $responses)
    {
    }

    public function send(FetchRequest $request): FetchResponse
    {
        $this->requests[] = $request;

        $response = $this->responses[$request->url] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response === null) {
            throw new \RuntimeException('FIXTURE_TRANSPORT_NO_RESPONSE_FOR: ' . $request->url);
        }

        return $response;
    }

    /** @return list<string> the URLs this transport was actually asked to fetch */
    public function attemptedUrls(): array
    {
        return array_map(static fn (FetchRequest $r): string => $r->url, $this->requests);
    }

    /** @return list<?string> the IP pinned on each attempt */
    public function pinnedIps(): array
    {
        return array_map(static fn (FetchRequest $r): ?string => $r->pinnedIp, $this->requests);
    }
}
