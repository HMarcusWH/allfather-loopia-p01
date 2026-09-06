<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * The production https transport — the ONLY non-fixture network leaf in this repository.
 *
 * Ported from `packages/allfather-control-substrate/src/ingestion/https-transport.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d (M15 cp10 / CHG-128; threat ING-T03).
 *
 * The guard owns scheme/method/host/IP vetting, redirect policy, content type and the post-hoc
 * size cap. This leaf performs ONE governed GET/HEAD over the already-vetted address and returns
 * the raw response.
 *
 * Load-bearing security properties, each mapped to its cURL mechanism:
 *
 *  * **ING-T03 resolve-connect pin.** `CURLOPT_RESOLVE` pre-seeds cURL's DNS cache with
 *    `host:port:ip` for the exact address the guard vetted, so cURL connects there and issues NO
 *    query of its own. This is the whole point: resolving, checking the IP, and then letting cURL
 *    resolve the hostname AGAIN would reopen the DNS-rebinding hole the guard exists to close.
 *    SNI and the HTTP Host header still derive from the URL, so TLS identity is verified against
 *    the hostname — not against the pinned address.
 *  * **TLS verification is NON-DISABLEABLE.** `SSL_VERIFYPEER` and `SSL_VERIFYHOST` are hardcoded;
 *    this class exposes no option, public or internal, that can turn either off.
 *  * **GET/HEAD only.** Anything else throws before a handle is created.
 *  * **No redirect following.** `FOLLOWLOCATION` is false; a 3xx is returned verbatim for the
 *    guard to police. cURL following a redirect itself would bypass the per-hop re-vetting.
 *  * **Scheme confinement.** `CURLOPT_PROTOCOLS_STR` (or the legacy bitmask) restricts the handle
 *    to https, so no URL form can reach file://, gopher:// or dict://.
 *  * **No proxy.** `CURLOPT_PROXY` is explicitly emptied. cURL reads `HTTPS_PROXY`/`ALL_PROXY`
 *    from the ENVIRONMENT by default, and a proxied connection goes to the proxy — the pin would
 *    be perfectly computed and completely bypassed.
 *  * **Streaming size cap.** The write callback stops accepting bytes past `maxResponseBytes + 1`
 *    and returns a short count, which aborts the transfer; the guard's post-hoc
 *    REFUSED_RESPONSE_TOO_LARGE still fires on the overflow byte.
 *  * **Total wall-clock deadline.** `CURLOPT_TIMEOUT_MS` bounds the WHOLE transfer, not
 *    inactivity, so a server dribbling one byte at a time cannot hold the request open.
 *  * **No cookies, no credentials, no auth.** No cookie jar is set, so nothing is stored or sent;
 *    `CURLOPT_USERPWD` is never set; `CURLOPT_UNRESTRICTED_AUTH` stays off.
 */
final class CurlHttpsTransport implements FetchTransport
{
    /** A truthful, non-deceptive identifier. robots.txt honoring matches against this name. */
    public const USER_AGENT = 'AllfatherBI-GovernedIngestion/1.0 (+governed-read-only)';

    private const READ_ONLY_METHODS = ['GET', 'HEAD'];
    private const DEFAULT_MAX_BYTES = 5000000;

    public function __construct(private readonly ?string $caBundlePath = null)
    {
    }

    public function send(FetchRequest $request): FetchResponse
    {
        $method = strtoupper($request->method);
        if (!in_array($method, self::READ_ONLY_METHODS, true)) {
            throw new \RuntimeException('HTTPS_TRANSPORT_METHOD_NOT_READ_ONLY');
        }

        // Fail closed: a production transport never resolves on its own, so a missing pin is
        // refused rather than quietly falling back to cURL's resolver.
        $pin = trim((string) $request->pinnedIp);
        if ($pin === '') {
            throw new \RuntimeException('HTTPS_TRANSPORT_MISSING_PINNED_IP');
        }
        if (filter_var($pin, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('HTTPS_TRANSPORT_MALFORMED_PINNED_IP');
        }

        $parts = @parse_url($request->url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('HTTPS_TRANSPORT_MALFORMED_URL');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new \RuntimeException('HTTPS_TRANSPORT_SCHEME_NOT_HTTPS');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        // NO CREDENTIAL FORWARDING. Canonical builds its request from the URL's host and path and
        // never sets an auth option, so a `https://user:pass@host/` source card fetches WITHOUT
        // credentials rather than being refused. cURL would send them, so the request URL is
        // rebuilt from the parts that matter — the credentials are dropped here, at the one place
        // that talks to the network.
        $requestUrl = $request->url;
        if (isset($parts['user']) || isset($parts['pass'])) {
            $requestUrl = sprintf(
                'https://%s%s%s%s',
                $host,
                isset($parts['port']) ? ':' . $port : '',
                $parts['path'] ?? '/',
                isset($parts['query']) ? '?' . $parts['query'] : '',
            );
        }

        // An IP-literal authority is refused whenever its address disagrees with the vetted pin:
        // connecting to one address while demanding a certificate for a different one is not a
        // state worth having. The comparison is canonical, not textual, because one IPv6 address
        // has many spellings and a security decision must not depend on which one arrived.
        $authorityIp = self::canonicalIpLiteral($host);
        if ($authorityIp !== null && $authorityIp !== self::canonicalIpLiteral($pin)) {
            throw new \RuntimeException('HTTPS_TRANSPORT_PIN_HOST_MISMATCH');
        }

        $maxBytes = $request->maxResponseBytes ?? self::DEFAULT_MAX_BYTES;
        $body = '';
        $headers = [];

        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('HTTPS_TRANSPORT_INIT_FAILED');
        }

        $options = [
            CURLOPT_URL => $requestUrl,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => false,

            // ING-T03: connect to the vetted IP; never re-resolve. cURL keeps using the URL's
            // hostname for SNI and the Host header, so TLS identity is still checked against the
            // name the guard allowlisted.
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $pin)],
            // Belt and braces: even with the cache pre-seeded, forbid cURL from reusing a
            // connection opened for a different address.
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,

            // NON-DISABLEABLE TLS verification.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // The guard owns redirect policy. cURL must not follow one itself.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,

            // No ambient proxy can move the socket away from the pinned address.
            CURLOPT_PROXY => '',

            // A total wall-clock bound, not an inactivity timer.
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($request->timeoutMs, 5000),

            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept-Encoding: identity',
                'Connection: close',
            ],

            // No cookies are stored or sent: no COOKIEJAR, no COOKIEFILE, and the cookie engine is
            // explicitly cleared in case a default is inherited.
            CURLOPT_COOKIELIST => 'ALL',
            CURLOPT_UNRESTRICTED_AUTH => false,

            CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$headers): int {
                $length = strlen($header);
                $colon = strpos($header, ':');
                if ($colon !== false) {
                    $name = strtolower(trim(substr($header, 0, $colon)));
                    $value = trim(substr($header, $colon + 1));
                    // Keep the FIRST value for a repeated header. A response that sends two
                    // Location headers must not have the second one silently win.
                    $headers[$name] ??= $value;
                }

                return $length;
            },

            CURLOPT_WRITEFUNCTION => static function ($_handle, string $chunk) use (&$body, $maxBytes): int {
                $room = $maxBytes + 1 - strlen($body);
                if ($room <= 0) {
                    return 0; // a short write aborts the transfer
                }
                if (strlen($chunk) >= $room) {
                    // Cap at maxBytes+1 and stop: the guard's post-hoc check then refuses an
                    // over-cap body, so the overflow byte is observed rather than truncated away.
                    $body .= substr($chunk, 0, $room);

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        // Confine the handle to https so no URL form can reach another scheme.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($this->caBundlePath !== null) {
            // Only ADDS trust. `SSL_VERIFYPEER` above stays true, and there is no code path that
            // sets it false.
            $options[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // A short write from the size cap surfaces as CURLE_WRITE_ERROR. That is the cap doing its
        // job, not a transport failure: return what was read and let the guard refuse it.
        $cappedByUs = $errno === CURLE_WRITE_ERROR && strlen($body) > $maxBytes;

        if ($ok === false && !$cappedByUs) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TransportTimeout('HTTPS_TRANSPORT_TIMEOUT');
            }
            // The message can name a host or a certificate subject; the errno is the whole
            // diagnostic that leaves this class.
            throw new \RuntimeException('HTTPS_TRANSPORT_ERROR_' . $errno);
        }

        return new FetchResponse(
            status: $status,
            contentType: $headers['content-type'] ?? null,
            locationHeader: $headers['location'] ?? null,
            body: $body,
        );
    }

    /**
     * One canonical spelling of an IP literal, or null when the value is not one.
     *
     * Comparing addresses as raw text is wrong for IPv6, where `::1`, `0:0:0:0:0:0:0:1` and `[::1]`
     * are the same host and must never compare unequal in a security decision. `inet_pton`
     * normalizes to packed bytes, which is the one representation with no spelling choices.
     */
    private static function canonicalIpLiteral(string $value): ?string
    {
        $bare = trim(trim($value), '[]');
        $packed = @inet_pton($bare);

        return $packed === false ? null : bin2hex($packed);
    }
}
