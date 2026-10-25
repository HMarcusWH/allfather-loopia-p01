<?php

declare(strict_types=1);

/**
 * The egress guard battery.
 *
 * This is the characterization pin for the SSRF screen. It records the guard's CURRENT answers —
 * including the ones the owning architecture deliberately left open (IPv4 documentation and
 * benchmarking ranges are ACCEPTED) — so that the open policy question stays visible instead of
 * dissolving into whatever the branches happen to do. A test that asserted the "obviously safer"
 * answer would silently make a policy change this port has no authority to make.
 *
 * Upstream battery: `packages/allfather-control-substrate/src/p01/fetch-guard.test.ts`
 * (running against `src/ingestion/egress-guard.ts` through the shim).
 */

use Allfather\Loopia\Ingestion\EgressGuard;
use Allfather\Loopia\Ingestion\FetchResponse;
use Allfather\Loopia\Ingestion\FixedHostResolver;
use Allfather\Loopia\Ingestion\FixtureTransport;
use Allfather\Loopia\Ingestion\TransportTimeout;
use Allfather\Loopia\Tests\Assert;

// ============================================================ isBlockedIp — IPv4

$blockedV4 = [
    '0.0.0.0' => 'unspecified 0.0.0.0/8',
    '0.1.2.3' => '0.0.0.0/8',
    '10.0.0.1' => 'RFC1918 10/8',
    '10.255.255.255' => 'RFC1918 10/8 upper',
    '127.0.0.1' => 'loopback',
    '127.255.255.254' => 'loopback range',
    '169.254.1.1' => 'link-local',
    '169.254.169.254' => 'CLOUD METADATA — the canonical SSRF target',
    '172.16.0.1' => 'RFC1918 172.16/12 lower',
    '172.31.255.255' => 'RFC1918 172.16/12 upper',
    '192.168.0.1' => 'RFC1918 192.168/16',
    '100.64.0.1' => 'CGNAT 100.64/10 lower',
    '100.127.255.255' => 'CGNAT 100.64/10 upper',
    '224.0.0.1' => 'multicast',
    '239.255.255.255' => 'multicast upper',
    '240.0.0.1' => 'reserved',
    '255.255.255.255' => 'broadcast',
];
foreach ($blockedV4 as $ip => $why) {
    Assert::same(true, EgressGuard::isBlockedIp($ip), "IPv4 BLOCKED {$ip} ({$why})");
}

$allowedV4 = [
    '8.8.8.8' => 'ordinary public',
    '1.1.1.1' => 'ordinary public',
    '172.15.255.255' => 'just BELOW the RFC1918 172.16/12 range',
    '172.32.0.1' => 'just ABOVE the RFC1918 172.16/12 range',
    '100.63.255.255' => 'just below CGNAT',
    '100.128.0.1' => 'just above CGNAT',
    '223.255.255.255' => 'just below multicast',
    '11.0.0.1' => 'just above 10/8',
    '126.255.255.255' => 'just below loopback',
    '128.0.0.1' => 'just above loopback',
];
foreach ($allowedV4 as $ip => $why) {
    Assert::same(false, EgressGuard::isBlockedIp($ip), "IPv4 allowed {$ip} ({$why})");
}

// CHARACTERIZED, NOT ENDORSED. The owning architecture enumerates SSRF categories rather than all
// IANA special-purpose ranges, so these are ACCEPTED as the code stands. This assertion records
// that answer; changing it is a policy decision for the owning architecture and security review.
foreach ([
    '192.0.2.1' => 'TEST-NET-1 documentation',
    '198.51.100.1' => 'TEST-NET-2 documentation',
    '203.0.113.1' => 'TEST-NET-3 documentation',
    '198.18.0.1' => 'benchmarking 198.18/15',
    '192.0.0.1' => 'IETF protocol assignments',
    '192.88.99.1' => '6to4 relay anycast',
] as $ip => $why) {
    Assert::same(
        false,
        EgressGuard::isBlockedIp($ip),
        "IPv4 CHARACTERIZED-ACCEPTED {$ip} ({$why}) — open policy question, not an endorsement",
    );
}

// Malformed input fails CLOSED. An octal-looking octet is refused rather than interpreted, because
// resolvers disagree about whether 0177.0.0.1 is 127.0.0.1.
foreach ([
    '' => 'empty',
    '   ' => 'whitespace',
    '1.2.3' => 'too few octets',
    '1.2.3.4.5' => 'too many octets',
    '1.2.3.256' => 'octet out of range',
    '1.2.3.-1' => 'negative octet',
    'not-an-ip' => 'not numeric',
    '0177.0.0.1' => 'octal-looking octet (ambiguous across resolvers)',
    '1.2.3.04' => 'leading-zero octet',
    '1.2.3.4 ' => 'trailing space handled by trim, then valid',
] as $ip => $why) {
    $expected = $ip === '1.2.3.4 ';
    Assert::same(!$expected, EgressGuard::isBlockedIp($ip), "IPv4 malformed fails closed: '{$ip}' ({$why})");
}

// ============================================================ isBlockedIp — IPv6

$blockedV6 = [
    '::' => 'unspecified',
    '::1' => 'loopback',
    '::ffff:127.0.0.1' => 'IPv4-mapped dotted loopback',
    '::ffff:169.254.169.254' => 'IPv4-mapped dotted CLOUD METADATA',
    '::ffff:10.0.0.1' => 'IPv4-mapped dotted RFC1918',
    '::ffff:7f00:1' => 'IPv4-mapped HEX loopback (the CHG-091 hole)',
    '::ffff:a9fe:a9fe' => 'IPv4-mapped HEX cloud metadata (the CHG-091 hole)',
    '::ffff:c0a8:1' => 'IPv4-mapped HEX 192.168.0.1',
    'fc00::1' => 'ULA fc00::/7',
    'fd12:3456::1' => 'ULA fd00::/8',
    'fe80::1' => 'link-local',
    'ff02::1' => 'multicast',
    '64:ff9b::1' => 'NAT64',
    '100::1' => 'discard prefix',
    '2002:7f00:1::' => '6to4 carrying 127.0.0.1 (the CHG-097 hole)',
    '2002:a00:1::' => '6to4 carrying 10.0.0.1',
    '2001:0:1:2::' => 'Teredo 2001:0000::/32 (the CHG-097 hole)',
    '2001::1' => 'Teredo compressed form',
    '2001:0000:4136:e378::' => 'Teredo full form',
    '1000::1' => 'outside 2000::/3',
    '4000::1' => 'outside 2000::/3 (above)',
    'gggg::1' => 'unparseable head',
];
foreach ($blockedV6 as $ip => $why) {
    Assert::same(true, EgressGuard::isBlockedIp($ip), "IPv6 BLOCKED {$ip} ({$why})");
}

$allowedV6 = [
    '2001:4860:4860::8888' => 'Google DNS — allocated 2001::/16 space, NOT Teredo',
    '2606:4700:4700::1111' => 'Cloudflare',
    '2000::1' => '2000::/3 lower bound',
    '3fff:ffff::1' => '2000::/3 upper bound',
    '2003::1' => 'ordinary global unicast',
];
foreach ($allowedV6 as $ip => $why) {
    Assert::same(false, EgressGuard::isBlockedIp($ip), "IPv6 allowed {$ip} ({$why})");
}

// The IPv6 rule is STRICTER than the contract requires — a deliberate fail-closed default.
Assert::same(
    false,
    EgressGuard::isBlockedIp('2001:db8::1'),
    'IPv6 CHARACTERIZED-ACCEPTED 2001:db8::1 (documentation prefix inside 2000::/3)',
);

// ============================================================ effectiveHttpsOrigin

Assert::same('example.com:443', EgressGuard::effectiveHttpsOrigin('https://example.com/a'), 'default port is 443');
Assert::same('example.com:443', EgressGuard::effectiveHttpsOrigin('https://example.com:443/a'), 'explicit 443 is the same origin');
Assert::same('example.com:8443', EgressGuard::effectiveHttpsOrigin('https://example.com:8443/a'), 'a non-default port is part of the origin');
Assert::same('example.com:443', EgressGuard::effectiveHttpsOrigin('https://EXAMPLE.COM/a'), 'the host is lowercased');
Assert::same(null, EgressGuard::effectiveHttpsOrigin('http://example.com/'), 'a non-https URL yields NO origin');
Assert::same(null, EgressGuard::effectiveHttpsOrigin('not a url'), 'an unparseable URL yields NO origin');
// Canonical `URL.hostname` ignores credentials, so the origin IS derived. The no-forwarding rule
// lives at the transport, which strips them before building the request.
Assert::same(
    'example.com:443',
    EgressGuard::effectiveHttpsOrigin('https://user:pass@example.com/'),
    'a credentialed URL still yields its origin — canonical ignores the credentials',
);

// ============================================================ evaluateFetchPolicy

$hosts = ['example.com'];
$goodIps = ['93.184.216.34'];

$allow = EgressGuard::evaluateFetchPolicy('GET', 'https://example.com/page', $hosts, $goodIps);
Assert::same('ALLOW', $allow['decision'], 'a well-formed in-scope GET is allowed');

$policyRefusals = [
    ['POST', 'https://example.com/page', $hosts, $goodIps, null, EgressGuard::REFUSED_METHOD_NOT_READ_ONLY, 'POST is not read-only'],
    ['PUT', 'https://example.com/page', $hosts, $goodIps, null, EgressGuard::REFUSED_METHOD_NOT_READ_ONLY, 'PUT is not read-only'],
    ['DELETE', 'https://example.com/page', $hosts, $goodIps, null, EgressGuard::REFUSED_METHOD_NOT_READ_ONLY, 'DELETE is not read-only'],
    ['GET', 'http://example.com/page', $hosts, $goodIps, null, EgressGuard::REFUSED_SCHEME_NOT_HTTPS, 'http is refused'],
    ['GET', 'file:///etc/passwd', ['', 'localhost'], $goodIps, null, EgressGuard::REFUSED_SCHEME_NOT_HTTPS, 'file:// is refused'],
    ['GET', 'gopher://example.com/', $hosts, $goodIps, null, EgressGuard::REFUSED_SCHEME_NOT_HTTPS, 'gopher:// is refused'],
    ['GET', 'not a url', $hosts, $goodIps, null, EgressGuard::REFUSED_MALFORMED_URL, 'a malformed URL is refused'],
    ['GET', 'https://evil.test/page', $hosts, $goodIps, null, EgressGuard::REFUSED_HOST_NOT_ALLOWLISTED, 'an off-allowlist host is refused'],
    ['GET', 'https://example.com/page', $hosts, [], null, EgressGuard::REFUSED_UNRESOLVABLE_HOST, 'an unresolvable host is refused'],
    ['GET', 'https://example.com/page', $hosts, ['127.0.0.1'], null, EgressGuard::REFUSED_BLOCKED_IP, 'a loopback resolution is refused'],
    ['GET', 'https://example.com/page', $hosts, ['169.254.169.254'], null, EgressGuard::REFUSED_BLOCKED_IP, 'a metadata resolution is refused'],
    ['GET', 'https://example.com:8443/p', $hosts, $goodIps, 'example.com:443', EgressGuard::REFUSED_HOST_NOT_ALLOWLISTED, 'a port change leaves the authorized origin'],
];
foreach ($policyRefusals as [$method, $url, $allowed, $ips, $origin, $expected, $why]) {
    $decision = EgressGuard::evaluateFetchPolicy($method, $url, $allowed, $ips, $origin);
    Assert::same('REFUSE', $decision['decision'], "policy refuses: {$why}");
    Assert::same($expected, $decision['reason_code'] ?? null, "and by the canonical code: {$why}");
}

// A credentialed URL is ALLOWED at policy level, matching canonical. The credentials never reach
// the network: `CurlHttpsTransport` rebuilds the request URL without them. Refusing here would be
// a stricter policy than the owning architecture set, which this port has no authority to make.
Assert::same(
    'ALLOW',
    EgressGuard::evaluateFetchPolicy('GET', 'https://user:pass@example.com/p', $hosts, $goodIps)['decision'],
    'a credentialed URL is allowed at policy level (canonical behavior); the transport strips it',
);

// ONE blocked address among several is enough — the screen is over EVERY resolved address.
$mixed = EgressGuard::evaluateFetchPolicy('GET', 'https://example.com/p', $hosts, ['93.184.216.34', '127.0.0.1']);
Assert::same('REFUSE', $mixed['decision'], 'one blocked address among several refuses the whole hop');
Assert::same(EgressGuard::REFUSED_BLOCKED_IP, $mixed['reason_code'], 'refused by name');

// HEAD is read-only too.
Assert::same(
    'ALLOW',
    EgressGuard::evaluateFetchPolicy('HEAD', 'https://example.com/p', $hosts, $goodIps)['decision'],
    'HEAD is a read-only method',
);
Assert::same(
    'ALLOW',
    EgressGuard::evaluateFetchPolicy('get', 'https://example.com/p', $hosts, $goodIps)['decision'],
    'the method comparison is case-insensitive',
);

// ============================================================ guardedFetch

$resolver = new FixedHostResolver([
    'example.com' => ['93.184.216.34'],
    'evil.test' => ['93.184.216.35'],
    'internal.test' => ['127.0.0.1'],
]);

$ok = new FixtureTransport([
    'https://example.com/page' => new FetchResponse(200, 'text/html; charset=utf-8', null, '<html>ok</html>'),
]);
$fetched = EgressGuard::guardedFetch('https://example.com/page', ['example.com'], $ok, $resolver);
Assert::same('FETCHED', $fetched['outcome'], 'a governed fetch succeeds');
Assert::same('<html>ok</html>', $fetched['body'], 'the body is returned');
Assert::same(64, strlen($fetched['content_hash']), 'a content hash is computed');

// THE resolve-connect pin: the transport receives the VETTED address, so it cannot re-resolve.
Assert::same(['93.184.216.34'], $ok->pinnedIps(), 'the vetted IP is PINNED for the transport (ING-T03)');

// The content hash binds URL to body, so a body cannot be re-attributed to another source.
$other = new FixtureTransport([
    'https://example.com/other' => new FetchResponse(200, 'text/html', null, '<html>ok</html>'),
]);
$otherFetched = EgressGuard::guardedFetch('https://example.com/other', ['example.com'], $other, $resolver);
Assert::notSame(
    $fetched['content_hash'],
    $otherFetched['content_hash'],
    'the content hash binds the URL, not just the body',
);

// --- a refused hop never reaches the transport at all ---------------------------------------------

$never = new FixtureTransport([]);
$blocked = EgressGuard::guardedFetch('https://internal.test/p', ['internal.test'], $never, $resolver);
Assert::same('REFUSED', $blocked['outcome'], 'a loopback-resolving host is refused');
Assert::same(EgressGuard::REFUSED_BLOCKED_IP, $blocked['reason_code'], 'refused by name');
Assert::same([], $never->attemptedUrls(), 'and NO request reached the transport — the screen is pre-connect');

// --- redirects -------------------------------------------------------------------------------------

$crossBoundary = new FixtureTransport([
    'https://example.com/start' => new FetchResponse(302, null, 'https://evil.test/landing', ''),
]);
$redirected = EgressGuard::guardedFetch('https://example.com/start', ['example.com'], $crossBoundary, $resolver);
Assert::same('REFUSED', $redirected['outcome'], 'a redirect off the allowlist is refused');
Assert::same(
    EgressGuard::REFUSED_REDIRECT_CROSSED_BOUNDARY,
    $redirected['reason_code'],
    'and reported as a CROSSED BOUNDARY, not a plain allowlist refusal',
);
Assert::same(['https://example.com/start'], $crossBoundary->attemptedUrls(), 'the off-scope target was never fetched');

$toInternal = new FixtureTransport([
    'https://example.com/start' => new FetchResponse(302, null, 'https://internal.test/admin', ''),
]);
$internalRedirect = EgressGuard::guardedFetch('https://example.com/start', ['example.com', 'internal.test'], $toInternal, $resolver);
Assert::same('REFUSED', $internalRedirect['outcome'], 'a redirect to a loopback-resolving host is refused');
Assert::same(
    EgressGuard::REFUSED_REDIRECT_CROSSED_BOUNDARY,
    $internalRedirect['reason_code'],
    'even when the target host IS allowlisted — the IP screen runs on every hop',
);

$chain = new FixtureTransport([
    'https://example.com/a' => new FetchResponse(302, null, '/b', ''),
    'https://example.com/b' => new FetchResponse(302, null, '/c', ''),
]);
$tooMany = EgressGuard::guardedFetch('https://example.com/a', ['example.com'], $chain, $resolver);
Assert::same('REFUSED', $tooMany['outcome'], 'a redirect chain past the limit is refused');
Assert::same(EgressGuard::REFUSED_TOO_MANY_REDIRECTS, $tooMany['reason_code'], 'refused by name');

$oneHop = new FixtureTransport([
    'https://example.com/a' => new FetchResponse(302, null, '/b', ''),
    'https://example.com/b' => new FetchResponse(200, 'text/html', null, '<html>landed</html>'),
]);
$followed = EgressGuard::guardedFetch('https://example.com/a', ['example.com'], $oneHop, $resolver);
Assert::same('FETCHED', $followed['outcome'], 'one in-scope redirect IS followed');
Assert::same('https://example.com/b', $followed['final_url'], 'and the final URL is reported');

$noLocation = new FixtureTransport([
    'https://example.com/a' => new FetchResponse(302, null, null, ''),
]);
$headerless = EgressGuard::guardedFetch('https://example.com/a', ['example.com'], $noLocation, $resolver);
Assert::same('REFUSED', $headerless['outcome'], 'a 3xx with no Location is refused, not treated as a body');
Assert::same(EgressGuard::REFUSED_REDIRECT_CROSSED_BOUNDARY, $headerless['reason_code'], 'refused by name');

// A redirect that changes the PORT leaves the authorized origin.
$portChange = new FixtureTransport([
    'https://example.com/a' => new FetchResponse(302, null, 'https://example.com:8443/b', ''),
]);
$portRedirect = EgressGuard::guardedFetch(
    'https://example.com/a', ['example.com'], $portChange, $resolver,
    allowedOrigin: 'example.com:443',
);
Assert::same('REFUSED', $portRedirect['outcome'], 'a redirect to a different PORT is refused');
Assert::same(
    EgressGuard::REFUSED_REDIRECT_CROSSED_BOUNDARY,
    $portRedirect['reason_code'],
    'the source-card origin scope survives the redirect',
);

// #65: the source-card's FULL path scope, not just its origin, gates each HTTP hop.
$pricingScope = 'https://example.com/pricing';
$pricingOrigin = EgressGuard::effectiveHttpsOrigin($pricingScope);
foreach ([
    'relative outside' => ['/outside', 'https://example.com/outside'],
    'absolute outside' => ['https://example.com/outside', 'https://example.com/outside'],
    'false prefix' => ['/pricing-admin', 'https://example.com/pricing-admin'],
    'another false prefix' => ['/pricing2', 'https://example.com/pricing2'],
    'dot segment' => ['/pricing/../outside', 'https://example.com/pricing/../outside'],
    'encoded separator' => ['/pricing%2foutside', 'https://example.com/pricing%2foutside'],
    'encoded dot' => ['/pricing/%2e%2e/outside', 'https://example.com/pricing/%2e%2e/outside'],
    'double-encoded separator' => ['/pricing/%252foutside', 'https://example.com/pricing/%252foutside'],
    'backslash' => ['/pricing\outside', 'https://example.com/pricing\outside'],
    'other port' => ['https://example.com:8443/pricing/offer', 'https://example.com:8443/pricing/offer'],
    'wrong host' => ['https://evil.test/pricing', 'https://evil.test/pricing'],
] as $label => [$location, $destination]) {
    $fixture = new FixtureTransport([
        $pricingScope => new FetchResponse(302, 'text/html', $location, ''),
        $destination => new FetchResponse(200, 'text/html', null, 'never authorized'),
    ]);
    $result = EgressGuard::guardedFetch(
        $pricingScope, ['example.com'], $fixture, $resolver,
        allowedOrigin: $pricingOrigin, allowedUrlScope: $pricingScope,
    );
    Assert::same('REFUSED', $result['outcome'], "#65 {$label}: redirect is refused");
    Assert::same(EgressGuard::REFUSED_REDIRECT_CROSSED_BOUNDARY, $result['reason_code'], "#65 {$label}: crossed-boundary reason");
    Assert::same([$pricingScope], $fixture->attemptedUrls(), "#65 {$label}: destination was NEVER sent");
}

$scopedGood = new FixtureTransport([
    $pricingScope => new FetchResponse(302, 'text/html', '/pricing/subpage?plan=a', ''),
    'https://example.com/pricing/subpage?plan=a' => new FetchResponse(200, 'text/html', null, 'permitted'),
]);
$good = EgressGuard::guardedFetch(
    $pricingScope, ['example.com'], $scopedGood, $resolver,
    allowedOrigin: $pricingOrigin, allowedUrlScope: $pricingScope,
);
Assert::same('FETCHED', $good['outcome'], '#65 legal scoped subpage is usable');
Assert::same('https://example.com/pricing/subpage?plan=a', $good['final_url'], '#65 retains legal final URL');
Assert::same([$pricingScope, 'https://example.com/pricing/subpage?plan=a'], $scopedGood->attemptedUrls(), '#65 exactly two legal sends');

$multi = new FixtureTransport([
    $pricingScope => new FetchResponse(302, 'text/html', '/pricing/first', ''),
    'https://example.com/pricing/first' => new FetchResponse(302, 'text/html', '/outside', ''),
    'https://example.com/outside' => new FetchResponse(200, 'text/html', null, 'not allowed'),
]);
$multiResult = EgressGuard::guardedFetch(
    $pricingScope, ['example.com'], $multi, $resolver,
    maxRedirects: 3, allowedOrigin: $pricingOrigin, allowedUrlScope: $pricingScope,
);
Assert::same('REFUSED', $multiResult['outcome'], '#65 later hop refused');
Assert::same([$pricingScope, 'https://example.com/pricing/first'], $multi->attemptedUrls(), '#65 multi-hop forbidden third URL never sent');

$explicit443 = new FixtureTransport([
    'https://example.com:443/pricing' => new FetchResponse(302, null, '/pricing/subpage', ''),
    'https://example.com:443/pricing/subpage' => new FetchResponse(200, 'text/html', null, 'ok'),
]);
$portAllowed = EgressGuard::guardedFetch(
    'https://example.com:443/pricing', ['example.com'], $explicit443, $resolver,
    allowedOrigin: EgressGuard::effectiveHttpsOrigin('https://example.com/pricing'),
    allowedUrlScope: 'https://example.com/pricing',
);
Assert::same('FETCHED', $portAllowed['outcome'], '#65 explicit :443 is the authorized HTTPS origin');
Assert::same(2, count($explicit443->attemptedUrls()), '#65 explicit-port legal subpath dispatched');

foreach (['https://example.com/pricing/%2e%2e', 'https://example.com/pricing/../outside', 'https://example.com/pricing%2fadmin'] as $badInitial) {
    $neverScope = new FixtureTransport([]);
    $out = EgressGuard::guardedFetch(
        $badInitial, ['example.com'], $neverScope, $resolver,
        allowedOrigin: $pricingOrigin, allowedUrlScope: $pricingScope,
    );
    Assert::same('REFUSED', $out['outcome'], '#65 malformed initial path denied');
    Assert::same([], $neverScope->attemptedUrls(), '#65 malformed initial path never dispatched');
}
$invalidCard = new FixtureTransport([]);
$invalid = EgressGuard::guardedFetch(
    $pricingScope, ['example.com'], $invalidCard, $resolver,
    allowedOrigin: $pricingOrigin, allowedUrlScope: 'https://example.com/pricing/%2f',
);
Assert::same('REFUSED', $invalid['outcome'], '#65 invalid authority scope denies');
Assert::same([], $invalidCard->attemptedUrls(), '#65 invalid authority scope sends nothing');

// A redirect that downgrades the scheme is refused.
$downgrade = new FixtureTransport([
    'https://example.com/a' => new FetchResponse(302, null, 'http://example.com/b', ''),
]);
$downgraded = EgressGuard::guardedFetch('https://example.com/a', ['example.com'], $downgrade, $resolver);
Assert::same('REFUSED', $downgraded['outcome'], 'a redirect downgrading to http is refused');

// ============================================================ size and content type

$tooBig = new FixtureTransport([
    'https://example.com/big' => new FetchResponse(200, 'text/html', null, str_repeat('x', 2048)),
]);
$oversize = EgressGuard::guardedFetch(
    'https://example.com/big', ['example.com'], $tooBig, $resolver, maxResponseBytes: 1024,
);
Assert::same('REFUSED', $oversize['outcome'], 'an over-cap response is refused');
Assert::same(EgressGuard::REFUSED_RESPONSE_TOO_LARGE, $oversize['reason_code'], 'refused by name');

$atLimit = new FixtureTransport([
    'https://example.com/exact' => new FetchResponse(200, 'text/html', null, str_repeat('x', 1024)),
]);
$exact = EgressGuard::guardedFetch(
    'https://example.com/exact', ['example.com'], $atLimit, $resolver, maxResponseBytes: 1024,
);
Assert::same('FETCHED', $exact['outcome'], 'a response exactly at the cap is accepted (the cap is inclusive)');

foreach (['application/pdf', 'image/png', 'application/octet-stream', 'text/csv', ''] as $type) {
    $wrongType = new FixtureTransport([
        'https://example.com/f' => new FetchResponse(200, $type, null, 'data'),
    ]);
    $refused = EgressGuard::guardedFetch('https://example.com/f', ['example.com'], $wrongType, $resolver);
    Assert::same('REFUSED', $refused['outcome'], "content type '{$type}' is refused");
    Assert::same(EgressGuard::REFUSED_CONTENT_TYPE_NOT_ALLOWED, $refused['reason_code'], 'refused by name');
}

foreach (EgressGuard::DEFAULT_ALLOWED_CONTENT_TYPES as $type) {
    $goodType = new FixtureTransport([
        'https://example.com/f' => new FetchResponse(200, $type . '; charset=utf-8', null, 'data'),
    ]);
    $accepted = EgressGuard::guardedFetch('https://example.com/f', ['example.com'], $goodType, $resolver);
    Assert::same('FETCHED', $accepted['outcome'], "content type '{$type}' is accepted, parameters ignored");
}

// ============================================================ transport failures

$timeout = new FixtureTransport([
    'https://example.com/slow' => new TransportTimeout('deadline'),
]);
$timedOut = EgressGuard::guardedFetch('https://example.com/slow', ['example.com'], $timeout, $resolver);
Assert::same('FETCH_FAILED', $timedOut['outcome'], 'a timeout is a FAILURE, not a refusal');
Assert::same(EgressGuard::FAILURE_TIMEOUT, $timedOut['reason_code'], 'and is named TIMEOUT');

$broken = new FixtureTransport([
    'https://example.com/broken' => new \RuntimeException('connection reset'),
]);
$failed = EgressGuard::guardedFetch('https://example.com/broken', ['example.com'], $broken, $resolver);
Assert::same('FETCH_FAILED', $failed['outcome'], 'a transport error is a FAILURE, not a refusal');
Assert::same(EgressGuard::FAILURE_TRANSPORT_ERROR, $failed['reason_code'], 'and is named TRANSPORT_ERROR');

// The refusal/failure distinction is load-bearing: a refusal is the control plane working, a
// failure is a fault in it, and collapsing them would hide both.
Assert::notSame(
    EgressGuard::FAILURE_TIMEOUT,
    EgressGuard::REFUSED_BLOCKED_IP,
    'refusals and failures use disjoint vocabularies',
);
