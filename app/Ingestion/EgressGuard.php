<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Digest;

/**
 * The governed live read-only egress guard.
 *
 * Ported from `packages/allfather-control-substrate/src/ingestion/egress-guard.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d (through CHG-091/CHG-097 SSRF hardening and the
 * CHG-128 ING-T03 resolve-connect pin).
 *
 * This is the security-critical egress boundary: the projection's ONLY path to the outside world.
 * Every control here is MANDATORY and fail-closed. A read-only GET/HEAD is Class A OBSERVE — not
 * an external side effect — so `external_side_effects_allowed` stays 0; this guard never writes,
 * posts, or mutates.
 *
 * The network is reached ONLY through an injected transport, so unit tests and M11 replay pass a
 * deterministic fake and never touch the real network.
 *
 * **Do not reimplement this logic anywhere else.** Upstream deliberately collapsed a duplicated
 * P01 copy into one file because copying the guard is how CHG-091-class SSRF holes reopen. The
 * same rule applies here: one guard, one battery, every consumer through it.
 */
final class EgressGuard
{
    // The closed refusal vocabulary. These codes are conformance surface and are preserved exactly.
    public const REFUSED_METHOD_NOT_READ_ONLY = 'REFUSED_METHOD_NOT_READ_ONLY';
    public const REFUSED_SCHEME_NOT_HTTPS = 'REFUSED_SCHEME_NOT_HTTPS';
    public const REFUSED_MALFORMED_URL = 'REFUSED_MALFORMED_URL';
    public const REFUSED_HOST_NOT_ALLOWLISTED = 'REFUSED_HOST_NOT_ALLOWLISTED';
    public const REFUSED_BLOCKED_IP = 'REFUSED_BLOCKED_IP';
    public const REFUSED_UNRESOLVABLE_HOST = 'REFUSED_UNRESOLVABLE_HOST';
    public const REFUSED_REDIRECT_CROSSED_BOUNDARY = 'REFUSED_REDIRECT_CROSSED_BOUNDARY';
    public const REFUSED_TOO_MANY_REDIRECTS = 'REFUSED_TOO_MANY_REDIRECTS';
    public const REFUSED_RESPONSE_TOO_LARGE = 'REFUSED_RESPONSE_TOO_LARGE';
    public const REFUSED_CONTENT_TYPE_NOT_ALLOWED = 'REFUSED_CONTENT_TYPE_NOT_ALLOWED';

    public const FAILURE_TIMEOUT = 'TIMEOUT';
    public const FAILURE_TRANSPORT_ERROR = 'TRANSPORT_ERROR';

    private const READ_ONLY_METHODS = ['GET', 'HEAD'];

    public const DEFAULT_ALLOWED_CONTENT_TYPES = [
        'text/html',
        'text/plain',
        'application/json',
        'application/xml',
        'text/xml',
        'application/rss+xml',
        'application/atom+xml',
    ];

    public const DEFAULT_TIMEOUT_MS = 10000;
    public const DEFAULT_MAX_BYTES = 5000000;
    public const DEFAULT_MAX_REDIRECTS = 1;

    /**
     * The SSRF screen over a caller-resolved address: is this literal in a range an allowlisted
     * hostname must NEVER be allowed to reach?
     *
     * WHAT THE OWNING LAW ASKS FOR. Architecture §12 ING-T01 states the threat as "fetch reaches
     * internal/loopback/metadata/private targets" and the mitigation as a DENYLIST over
     * caller-resolved IPs, applied pre-connect, per hop. Every SSRF-sensitive category is refused:
     * unspecified, loopback, RFC1918, link-local including cloud metadata, CGNAT, multicast,
     * reserved and broadcast, ULA, IPv6 link-local / multicast / discard, NAT64, the IPv4-tunnelling
     * transition families, and the IPv4-mapped IPv6 spelling of every one of them.
     *
     * WHAT IT DOES NOT CLAIM. This is not a proof that an accepted address is globally routable.
     * Ranges that are not globally reachable but are not among the categories ING-T01 enumerates
     * are ACCEPTED as this code stands: IPv4 documentation (192.0.2/24, 198.51.100/24,
     * 203.0.113/24), benchmarking (198.18/15), IETF protocol assignments (192.0.0/24), 6to4 relay
     * anycast (192.88.99/24), and the IPv6 equivalents inside 2000::/3 (2001:db8::/32
     * documentation, 2001:2::/48 benchmarking).
     *
     * That describes behavior. It is NOT a finding that those ranges are harmless — several are
     * IANA-forwardable inside an administrative domain, so "not globally reachable" does not imply
     * "cannot reach something internal". Whether the guard should refuse every IANA
     * special-purpose destination is an OPEN POLICY QUESTION for the owning architecture and
     * security review; widening the block list is a policy change, and this port is not authority
     * to make one. The test battery characterizes the current answers so the question stays
     * visible instead of dissolving into whatever the branches happen to do.
     *
     * The two families reach the contract by different mechanisms: IPv4 enumerates the ranges it
     * refuses; IPv6 accepts only 2000::/3 minus the transition families. The IPv6 rule is STRICTER
     * than the contract requires — a deliberate fail-closed default that costs nothing, because
     * what it over-blocks is unallocated or special-purpose anyway.
     */
    public static function isBlockedIp(string $ipRaw): bool
    {
        $ip = strtolower(trim($ipRaw));
        if ($ip === '') {
            return true;
        }

        // IPv4-mapped IPv6 in dotted form (::ffff:a.b.c.d) -> check the embedded IPv4.
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $ip, $m) === 1) {
            return self::isBlockedIpv4($m[1]);
        }
        if (str_contains($ip, ':')) {
            return self::isBlockedIpv6($ip);
        }

        return self::isBlockedIpv4($ip);
    }

    private static function isBlockedIpv4(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return true; // not a clean dotted-quad -> fail closed
        }
        foreach ($parts as $part) {
            // CHARACTERIZED DIVERGENCE FROM CANONICAL — deliberately STRICTER, fail-closed.
            //
            // Canonical parses each octet with JavaScript `Number()`, which accepts spellings that
            // a C connect path reads differently:
            //
            //   "0177.0.0.1"  Number("0177") = 177  -> canonical screens 177.0.0.1 and ALLOWS
            //                 inet_aton("0177...")  = 127.0.0.1  (octal) -> LOOPBACK
            //   "1.2.3.04"    Number("04")   = 4    -> canonical ALLOWS
            //   "1.2.3.+4"    Number("+4")   = 4    -> canonical ALLOWS
            //   "1..2.3"      Number("")     = 0    -> canonical reads [1,0,2,3] and ALLOWS
            //
            // So a value canonical screens as one address can be connected to as another. This is
            // not reachable from a conforming resolver — `dns_get_record` and `inet_ntop` both
            // emit canonical dotted-quads — which is why it has not bitten upstream. It is still
            // the wrong answer to give, and "unknown spelling" is exactly the state the
            // fail-closed rule covers.
            //
            // This port therefore requires a canonical decimal octet and refuses every other
            // spelling, rather than picking one of two interpretations. The four divergent inputs
            // are pinned in tests/security/egress_guard.php, and the finding is recorded for
            // upstream in docs/UPSTREAM_FINDINGS.md — it is reported, not silently fixed here,
            // because the canonical implementation is not this repository's to change.
            if (preg_match('/^(0|[1-9][0-9]{0,2})$/', $part) !== 1) {
                return true;
            }
        }
        $octets = array_map('intval', $parts);
        foreach ($octets as $octet) {
            if ($octet < 0 || $octet > 255) {
                return true;
            }
        }
        [$a, $b] = $octets;

        // 0.0.0.0/8 unspecified; 10/8, 172.16/12, 192.168/16 private; 127/8 loopback;
        // 169.254/16 link-local (incl. 169.254.169.254 cloud metadata); 100.64/10 CGNAT;
        // 224-239 multicast; 240+ reserved / 255.255.255.255 broadcast.
        if ($a === 0 || $a === 10 || $a === 127) {
            return true;
        }
        if ($a === 169 && $b === 254) {
            return true;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return true;
        }
        if ($a === 192 && $b === 168) {
            return true;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return true;
        }
        if ($a >= 224) {
            return true;
        }

        return false;
    }

    private static function isBlockedIpv6(string $ip): bool
    {
        if ($ip === '::' || $ip === '::1') {
            return true; // unspecified / loopback
        }

        // IPv4-mapped / -compatible IPv6 carrying an embedded IPv4 in HEX form
        // (::ffff:7f00:1 = 127.0.0.1, ::ffff:a9fe:a9fe = 169.254.169.254) — classify by the
        // embedded IPv4. Without this the hex form fell to the head="" branch and FAILED OPEN
        // (CHG-091): an attacker-resolved AAAA in mapped-hex form reached loopback/metadata.
        if (preg_match('/^::(?:ffff:)?([0-9a-f]{1,4}):([0-9a-f]{1,4})$/', $ip, $m) === 1) {
            $hi = hexdec($m[1]);
            $lo = hexdec($m[2]);

            return self::isBlockedIpv4(sprintf(
                '%d.%d.%d.%d',
                ($hi >> 8) & 0xff,
                $hi & 0xff,
                ($lo >> 8) & 0xff,
                $lo & 0xff,
            ));
        }

        // Accept ONLY 2000::/3 — the IANA global-unicast block, leading hextet 0x2000-0x3fff — and
        // fail closed on everything else: a "::"-leading compressed/special address (head=""), the
        // unspecified/IPv4-compatible families, fc00::/7 ULA, fe80::/10 link-local, ff00::/8
        // multicast, 64:ff9b::/96 NAT64, 100::/64 discard, and all reserved. CHG-091 replaced a
        // range-denylist that ALLOWED any unrecognized head — including the "::"-leading SSRF
        // cases above — with this allow-only rule.
        $head = explode(':', $ip)[0] ?? '';
        if ($head === '') {
            return true; // a "::"-leading compressed address is never global-unicast -> blocked
        }
        if (preg_match('/^[0-9a-f]{1,4}$/', $head) !== 1) {
            return true; // unparseable -> fail closed
        }
        $group = (int) hexdec($head);
        if (!($group >= 0x2000 && $group <= 0x3fff)) {
            return true; // outside 2000::/3 global-unicast -> blocked
        }

        // CHG-097: 6to4 (2002::/16) and Teredo (2001:0000::/32) sit INSIDE 2000::/3 but are
        // IPv4-tunnelling transition families — they carry an embedded IPv4 that can be
        // loopback/private/metadata (2002:7f00:1:: -> 127.0.0.1, or a Teredo client-IPv4 field) and
        // are never a legitimate public fetch target. The 2000::/3 range test alone FAILED OPEN for
        // them. The Teredo pattern matches its 2001:0:… / 2001:0000:… / compressed 2001::… forms
        // (second hextet 0) WITHOUT touching the rest of 2001::/16, which is allocated space such
        // as 2001:4860 (Google).
        if ($group === 0x2002) {
            return true; // 6to4
        }
        if (preg_match('/^2001:(?:0{1,4})?:/', $ip) === 1) {
            return true; // Teredo 2001:0000::/32
        }

        return false;
    }

    /**
     * The `hostname:effectivePort` of an https URL — the origin a request is contained to.
     *
     * One derivation rather than one per caller: the policy compiler turns a source card into a
     * stored scope, this guard compares every hop against the authorized origin, and the transport
     * derives the origin of the request it was handed. An explicit `:443` and an implicit one are
     * the same origin and are treated as one.
     *
     * `null` means "no origin could be derived" — an unparseable or non-https URL — and every
     * caller reads that as refuse-or-unconstrained by its own rule, NEVER as a pass.
     */
    public static function effectiveHttpsOrigin(string $url): ?string
    {
        $parsed = self::parseUrl($url);
        if ($parsed === null || $parsed['scheme'] !== 'https' || $parsed['host'] === '') {
            return null;
        }

        return $parsed['host'] . ':' . ($parsed['port'] ?? '443');
    }

    /**
     * Pure policy evaluation for one request hop. Re-run for every redirect target.
     *
     * @param list<string> $allowedHosts
     * @param list<string> $resolvedIps IPs the host resolves to, injected by the caller's resolver
     * @return array{decision: 'ALLOW', host: string}|array{decision: 'REFUSE', reason_code: string}
     */
    public static function evaluateFetchPolicy(
        string $method,
        string $url,
        array $allowedHosts,
        array $resolvedIps,
        ?string $allowedOrigin = null,
    ): array {
        if (!in_array(strtoupper($method), self::READ_ONLY_METHODS, true)) {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_METHOD_NOT_READ_ONLY];
        }

        $parsed = self::parseUrl($url);
        if ($parsed === null) {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_MALFORMED_URL];
        }
        if ($parsed['scheme'] !== 'https') {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_SCHEME_NOT_HTTPS];
        }

        $host = $parsed['host'];
        $allowlist = array_map(strtolower(...), $allowedHosts);
        if (!in_array($host, $allowlist, true)) {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_HOST_NOT_ALLOWLISTED];
        }

        // Same origin as the card, when the caller supplied one. Reported with the existing
        // allowlist reason rather than a new code: the refusal vocabulary is closed, and the
        // redirect-hop translation in guardedFetch already maps this reason to
        // REFUSED_REDIRECT_CROSSED_BOUNDARY — which is exactly what a port change on a redirect is.
        if ($allowedOrigin !== null && self::effectiveHttpsOrigin($url) !== $allowedOrigin) {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_HOST_NOT_ALLOWLISTED];
        }

        if ($resolvedIps === []) {
            return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_UNRESOLVABLE_HOST];
        }
        foreach ($resolvedIps as $ip) {
            if (self::isBlockedIp($ip)) {
                return ['decision' => 'REFUSE', 'reason_code' => self::REFUSED_BLOCKED_IP];
            }
        }

        return ['decision' => 'ALLOW', 'host' => $host];
    }

    /**
     * The governed fetch. Applies the full egress policy on every hop (including redirect
     * targets), bounds I/O, and reaches the network only through the injected transport.
     * Fail-closed throughout.
     *
     * @param list<string>      $allowedHosts
     * @param list<string>|null $allowedContentTypes
     * @return array{outcome: 'FETCHED', status: int, content_type: ?string, body: string, content_hash: string, final_url: string}
     *        |array{outcome: 'REFUSED', reason_code: string}
     *        |array{outcome: 'FETCH_FAILED', reason_code: string}
     */
    public static function guardedFetch(
        string $url,
        array $allowedHosts,
        FetchTransport $transport,
        HostResolver $resolver,
        string $method = 'GET',
        ?int $timeoutMs = null,
        ?int $maxResponseBytes = null,
        ?int $maxRedirects = null,
        ?array $allowedContentTypes = null,
        ?string $allowedOrigin = null,
    ): array {
        $timeoutMs ??= self::DEFAULT_TIMEOUT_MS;
        $maxBytes = $maxResponseBytes ?? self::DEFAULT_MAX_BYTES;
        $maxRedirects ??= self::DEFAULT_MAX_REDIRECTS;
        $allowedContentTypes ??= self::DEFAULT_ALLOWED_CONTENT_TYPES;

        $currentUrl = $url;
        $redirectsFollowed = 0;

        // Bounded redirect loop. Each hop is fully re-evaluated by the same policy.
        for (;;) {
            $resolvedIps = [];
            $parsed = self::parseUrl($currentUrl);
            if ($parsed !== null) {
                $resolvedIps = $resolver->resolve($parsed['host']);
            }

            $policy = self::evaluateFetchPolicy(
                $method,
                $currentUrl,
                $allowedHosts,
                $resolvedIps,
                $allowedOrigin,
            );

            if ($policy['decision'] === 'REFUSE') {
                // On a redirect hop, an off-allowlist or blocked target is a crossed boundary.
                if ($redirectsFollowed > 0 && in_array(
                    $policy['reason_code'],
                    [self::REFUSED_HOST_NOT_ALLOWLISTED, self::REFUSED_BLOCKED_IP],
                    true,
                )) {
                    return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_REDIRECT_CROSSED_BOUNDARY];
                }

                return ['outcome' => 'REFUSED', 'reason_code' => $policy['reason_code']];
            }

            try {
                // ING-T03: hand the vetted resolved IP to the transport as a PIN so it connects to
                // exactly that address and never re-resolves — the resolve-connect pin that closes
                // the DNS-rebinding TOCTOU. `$resolvedIps[0]` is safe to pin: the ALLOW above
                // proved EVERY resolved IP is non-blocked, so this hop only pins a vetted address.
                $response = $transport->send(new FetchRequest(
                    method: $method,
                    url: $currentUrl,
                    timeoutMs: $timeoutMs,
                    pinnedIp: $resolvedIps[0],
                    maxResponseBytes: $maxBytes,
                ));
            } catch (TransportTimeout) {
                return ['outcome' => 'FETCH_FAILED', 'reason_code' => self::FAILURE_TIMEOUT];
            } catch (\Throwable) {
                return ['outcome' => 'FETCH_FAILED', 'reason_code' => self::FAILURE_TRANSPORT_ERROR];
            }

            if ($response->status >= 300 && $response->status < 400) {
                if ($redirectsFollowed >= $maxRedirects) {
                    return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_TOO_MANY_REDIRECTS];
                }
                if ($response->locationHeader === null || $response->locationHeader === '') {
                    return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_REDIRECT_CROSSED_BOUNDARY];
                }
                $nextUrl = self::resolveRelative($response->locationHeader, $currentUrl);
                if ($nextUrl === null) {
                    return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_REDIRECT_CROSSED_BOUNDARY];
                }
                $currentUrl = $nextUrl;
                $redirectsFollowed++;
                continue;
            }

            if (strlen($response->body) > $maxBytes) {
                return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_RESPONSE_TOO_LARGE];
            }

            $contentTypeValue = strtolower(trim(explode(';', $response->contentType ?? '')[0]));
            $allowed = array_map(strtolower(...), $allowedContentTypes);
            if (!in_array($contentTypeValue, $allowed, true)) {
                return ['outcome' => 'REFUSED', 'reason_code' => self::REFUSED_CONTENT_TYPE_NOT_ALLOWED];
            }

            return [
                'outcome' => 'FETCHED',
                'status' => $response->status,
                'content_type' => $response->contentType,
                'body' => $response->body,
                // The canonical content hash binds the URL to the body, so a body cannot be
                // re-attributed to a different source later.
                'content_hash' => Digest::canonicalDigest(['url' => $currentUrl, 'body' => $response->body]),
                'final_url' => $currentUrl,
            ];
        }
    }

    /**
     * Parse a URL into its parts, WITHOUT deciding whether it is acceptable.
     *
     * The ordering matters for conformance. Canonical `new URL()` parses `file:///etc/passwd`
     * successfully (hostname ""), so the SCHEME check fires first and the refusal is
     * `REFUSED_SCHEME_NOT_HTTPS`. A parser that rejected the missing host first would return
     * `REFUSED_MALFORMED_URL` for the same input — a different code for the same decision, and
     * reason codes are conformance surface. So this returns null only for genuinely unparseable
     * input, and the caller applies scheme then host in the canonical order.
     *
     * Credentials in the authority (`https://user:pass@host/`) are NOT refused here, because
     * canonical does not refuse them: `URL.hostname` simply ignores them and the transport builds
     * its request from the host alone, so nothing is ever forwarded. This port keeps that
     * behavior and enforces the no-forwarding rule where canonical enforces it — at the transport
     * leaf, which strips the credentials before the request is built. Refusing here instead would
     * be a stricter policy than the owning architecture set, and this port is not authority for
     * that change.
     *
     * @return array{scheme: string, host: string, port: ?string, path: string}|null
     */
    private static function parseUrl(string $url): ?array
    {
        if (trim($url) !== $url || $url === '') {
            return null;
        }
        $parts = @parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }
        // parse_url leaves brackets off an IPv6 host, but any that survive must come off before
        // the address inside can be classified.
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        return [
            'scheme' => strtolower($parts['scheme']),
            'host' => $host,
            'port' => isset($parts['port']) ? (string) $parts['port'] : null,
            'path' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
        ];
    }

    /** Resolve a possibly-relative Location header against the current URL. */
    private static function resolveRelative(string $location, string $base): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $location) === 1) {
            // Absolute URL of some scheme. Return it as-is: a non-https scheme is then refused by
            // the policy on the next hop with REFUSED_SCHEME_NOT_HTTPS rather than here, keeping
            // one place that decides scheme.
            return $location;
        }

        $baseParts = self::parseUrl($base);
        if ($baseParts === null) {
            return null;
        }
        $authority = $baseParts['host'] . ($baseParts['port'] !== null ? ':' . $baseParts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $baseParts['scheme'] . '://' . $authority . $location;
        }

        $basePath = explode('?', $baseParts['path'])[0];
        $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ($dir === '') {
            $dir = '/';
        }

        return $baseParts['scheme'] . '://' . $authority . $dir . $location;
    }
}
