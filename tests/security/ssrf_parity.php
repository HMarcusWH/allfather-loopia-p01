<?php

declare(strict_types=1);

/**
 * SSRF-screen parity against the canonical implementation.
 *
 * `tests/fixtures/canonical_ssrf_vectors.json` was produced by executing the canonical
 * `isBlockedIp` from `packages/allfather-control-substrate/src/ingestion/egress-guard.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d over 1926 addresses: every IPv4 /8 boundary and a
 * spread inside each, every /16 of the special ranges, boundary octet values, adversarial
 * malformed input, and the full IPv6 mapped / transition / boundary set.
 *
 * This suite asserts the port reaches the SAME verdict on all of them, with FOUR characterized
 * exceptions where this port is deliberately stricter — see docs/UPSTREAM_FINDINGS.md UF-001.
 * Listing them explicitly is the point: an unlisted divergence fails this test, so the port cannot
 * drift from canonical without someone deciding that it should.
 */

use Allfather\Loopia\Ingestion\EgressGuard;
use Allfather\Loopia\Tests\Assert;

$vectors = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/canonical_ssrf_vectors.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

/**
 * The ONLY permitted divergences, each in the fail-closed direction.
 *
 * Canonical parses octets with JavaScript `Number()`, which reads these spellings as decimal.
 * `inet_aton` reads `0177.0.0.1` as OCTAL — 127.0.0.1, loopback — so canonical would screen an
 * address it believes is public while a C connect path went to loopback. This port refuses every
 * non-canonical octet spelling instead of choosing an interpretation.
 */
$characterizedStricter = [
    '0177.0.0.1' => 'canonical reads 177.0.0.1 and ALLOWS; inet_aton reads 127.0.0.1 (LOOPBACK)',
    '1.2.3.04' => 'leading-zero octet: canonical reads 4, inet_aton reads 4, other stacks may differ',
    '1.2.3.+4' => 'leading-plus octet: canonical reads 4, inet_aton rejects',
    '1..2.3' => 'empty octet: canonical reads 0, inet_aton rejects',
];

$matched = 0;
$unexpected = [];
$divergedAsExpected = [];

foreach ($vectors as $case) {
    $ip = (string) $case['ip'];
    $canonical = (bool) $case['blocked'];
    $ported = EgressGuard::isBlockedIp($ip);

    if ($ported === $canonical) {
        $matched++;
        continue;
    }

    if (isset($characterizedStricter[$ip])) {
        // The divergence must be in the SAFE direction: this port blocks what canonical allows,
        // never the reverse. A divergence that allowed more would be a hole, not a hardening.
        if ($ported === true && $canonical === false) {
            $divergedAsExpected[] = $ip;
        } else {
            $unexpected[] = "{$ip}: characterized divergence is in the UNSAFE direction";
        }
        continue;
    }

    $unexpected[] = sprintf(
        '%s: canonical=%s ported=%s (UNDECLARED divergence)',
        $ip,
        $canonical ? 'BLOCK' : 'allow',
        $ported ? 'BLOCK' : 'allow',
    );
}

Assert::same(1926, count($vectors), 'the full canonical vector set is present');
Assert::same(1922, $matched, 'the port matches canonical on every address except the characterized four');
Assert::same([], $unexpected, 'there is NO undeclared divergence from the canonical SSRF screen');
Assert::same(
    count($characterizedStricter),
    count($divergedAsExpected),
    'every characterized divergence still diverges — a silently-reverted hardening is also drift',
);

// The characterized four, asserted individually so a future reader sees the exact answers.
foreach ($characterizedStricter as $ip => $why) {
    Assert::same(true, EgressGuard::isBlockedIp($ip), "stricter-than-canonical BLOCK for '{$ip}' — {$why}");
}

// And the canonical decimal spelling of the same address is still allowed, so the rule narrows
// only the spelling, not the address space.
Assert::same(false, EgressGuard::isBlockedIp('177.0.0.1'), 'the canonical decimal spelling 177.0.0.1 is still allowed');
Assert::same(true, EgressGuard::isBlockedIp('127.0.0.1'), 'and real loopback is still blocked');
