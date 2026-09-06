<?php

declare(strict_types=1);

/**
 * The cURL transport's resolve-connect pin, proved against a real local socket.
 *
 * This is the test that matters most for the Loopia adaptation. The guard resolves and vets an
 * address; the transport must connect to THAT address and never re-resolve. If cURL resolved the
 * hostname again, an attacker controlling DNS could answer public on the first lookup and internal
 * on the second — the DNS-rebinding TOCTOU the whole design exists to close.
 *
 * The proof is structural rather than a mock: a real listener is started on loopback, and the
 * transport is asked for a hostname that resolves nowhere useful while being PINNED to that
 * listener. If the pin works, the listener receives the request and the Host header carries the
 * hostname, not the IP. If the pin were ignored, cURL would fail to resolve and nothing would
 * arrive.
 */

use Allfather\Loopia\Ingestion\CurlHttpsTransport;
use Allfather\Loopia\Ingestion\FetchRequest;
use Allfather\Loopia\Tests\Assert;

// --- structural guarantees, readable without a socket -----------------------------------------------

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Ingestion/CurlHttpsTransport.php');

Assert::contains('CURLOPT_RESOLVE', $source, 'the transport pins the vetted address with CURLOPT_RESOLVE');
Assert::contains('CURLOPT_SSL_VERIFYPEER => true', $source, 'TLS peer verification is hardcoded on');
Assert::contains('CURLOPT_SSL_VERIFYHOST => 2', $source, 'TLS host verification is hardcoded on');
Assert::contains('CURLOPT_FOLLOWLOCATION => false', $source, 'the transport never follows a redirect itself');
Assert::contains("CURLOPT_PROXY => ''", $source, 'no ambient proxy can move the socket off the pin');

// There must be NO path that disables verification. This is the assertion that keeps a future
// "just for debugging" flag from existing.
Assert::true(
    !str_contains($source, 'CURLOPT_SSL_VERIFYPEER => false')
    && !str_contains($source, 'CURLOPT_SSL_VERIFYPEER => 0')
    && !preg_match('/VERIFYPEER\s*=>\s*\$/', $source),
    'TLS verification is NOT configurable — no option, public or internal, can turn it off',
);
Assert::true(
    !preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*(true|1|\$)/', $source),
    'redirect following is not configurable either',
);

$transport = new CurlHttpsTransport();

// --- fail-closed input handling -------------------------------------------------------------------------

$refusals = [
    ['POST', 'https://example.com/', '93.184.216.34', 'HTTPS_TRANSPORT_METHOD_NOT_READ_ONLY', 'a write method'],
    ['GET', 'https://example.com/', '', 'HTTPS_TRANSPORT_MISSING_PINNED_IP', 'a missing pin'],
    ['GET', 'https://example.com/', 'not-an-ip', 'HTTPS_TRANSPORT_MALFORMED_PINNED_IP', 'a malformed pin'],
    ['GET', 'http://example.com/', '93.184.216.34', 'HTTPS_TRANSPORT_SCHEME_NOT_HTTPS', 'a non-https scheme'],
    ['GET', 'not a url', '93.184.216.34', 'HTTPS_TRANSPORT_MALFORMED_URL', 'a malformed URL'],
    // An IP-literal authority that disagrees with the pin: connecting to one address while
    // demanding a certificate for another is not a state worth having.
    ['GET', 'https://1.2.3.4/', '93.184.216.34', 'HTTPS_TRANSPORT_PIN_HOST_MISMATCH', 'a literal authority ≠ pin'],
    ['GET', 'https://[2001:4860::1]/', '93.184.216.34', 'HTTPS_TRANSPORT_PIN_HOST_MISMATCH', 'an IPv6 literal ≠ pin'],
];

foreach ($refusals as [$method, $url, $pin, $expected, $why]) {
    $error = Assert::throws(
        static fn () => $transport->send(new FetchRequest($method, $url, 5000, $pin === '' ? null : $pin)),
        \RuntimeException::class,
        "the transport refuses {$why}",
    );
    if ($error !== null) {
        Assert::same($expected, $error->getMessage(), "and by the canonical code: {$why}");
    }
}

// A literal authority that AGREES with the pin is fine, in either IPv6 spelling.
foreach ([
    ['https://[::1]/', '::1'],
    ['https://[0:0:0:0:0:0:0:1]/', '::1'],
    ['https://127.0.0.1/', '127.0.0.1'],
] as [$url, $pin]) {
    $error = null;
    try {
        $transport->send(new FetchRequest('GET', $url, 250, $pin));
    } catch (\Throwable $thrown) {
        $error = $thrown;
    }
    // It will fail to CONNECT (nothing is listening with a valid certificate), but it must not
    // fail with PIN_HOST_MISMATCH — the addresses agree, however they are spelled.
    Assert::true(
        $error === null || !str_contains($error->getMessage(), 'PIN_HOST_MISMATCH'),
        "a literal authority equal to the pin is accepted regardless of spelling: {$url}",
    );
}

// --- THE pin, proved against a real listener ------------------------------------------------------------

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    Assert::skip('could not bind a loopback listener: ' . $errstr);
} else {
    $name = stream_socket_get_name($server, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);

    // A hostname that must NOT resolve anywhere. RFC 2606 reserves .invalid precisely so it never
    // will. If the pin is ignored, cURL resolves this and fails with COULDNT_RESOLVE_HOST; if the
    // pin works, the connection lands on the loopback listener above.
    $host = 'pinned-target.invalid';

    // THE TRANSPORT ITSELF is driven here — not a hand-built cURL handle. An earlier version of
    // this test constructed its own handle with CURLOPT_RESOLVE and therefore proved only that
    // cURL supports pinning, passing even with the pin removed from the transport. Mutation
    // testing caught it. What must be proved is that CurlHttpsTransport uses it.
    //
    // `send()` blocks, so the listener is not polled concurrently. It does not need to be: the
    // kernel completes the TCP handshake and QUEUES the connection in the listen backlog, and the
    // ClientHello bytes are buffered with it. Accepting afterwards reads exactly what arrived.
    $transportError = null;
    try {
        $transport->send(new FetchRequest('GET', "https://{$host}:{$port}/probe", 2000, '127.0.0.1'));
    } catch (\Throwable $thrown) {
        // A TLS failure is EXPECTED — the listener presents no certificate. Irrelevant to what is
        // being measured, and notably NOT a resolution failure.
        $transportError = $thrown;
    }

    stream_set_blocking($server, false);
    $client = @stream_socket_accept($server, 0);
    $connected = $client !== false;
    $firstBytes = '';
    if ($client !== false) {
        stream_set_blocking($client, false);
        $bytes = @fread($client, 8);
        $firstBytes = $bytes === false ? '' : $bytes;
        @fclose($client);
    }
    fclose($server);

    // THE decisive assertion. `pinned-target.invalid` has no DNS record by construction, so a
    // socket arriving on this loopback listener can ONLY have come from CURLOPT_RESOLVE inside the
    // transport. Had the transport let cURL resolve the hostname, it would have failed with
    // CURLE_COULDNT_RESOLVE_HOST and nothing would have arrived.
    Assert::same(
        true,
        $connected,
        'CurlHttpsTransport REACHED the listener for a hostname that cannot be resolved — '
        . 'CURLOPT_RESOLVE is the only route, so the transport really does pin the vetted address',
    );

    Assert::true(
        $firstBytes !== '' && bin2hex($firstBytes[0]) === '16',
        'and the transport spoke TLS on arrival (record type 0x16 = handshake) — the pin does not '
        . 'downgrade the connection to plaintext',
    );

    // The failure must be a TLS failure, never a resolution one: a resolution failure would mean
    // the transport had ignored its own pin.
    Assert::true(
        $transportError !== null
        && !str_contains($transportError->getMessage(), 'HTTPS_TRANSPORT_ERROR_' . CURLE_COULDNT_RESOLVE_HOST),
        'the transport did NOT fall back to resolving the hostname itself',
    );
}
