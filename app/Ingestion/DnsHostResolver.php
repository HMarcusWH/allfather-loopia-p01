<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

/**
 * The production host resolver.
 *
 * Resolves BOTH A and AAAA records, because the guard's screen is only sound if it sees every
 * address the connect could reach. Resolving A alone would let a host with a malicious AAAA record
 * pass the screen and then be connected to over IPv6.
 *
 * A resolver error is an EMPTY result, which the guard reads as `REFUSED_UNRESOLVABLE_HOST` — a
 * refusal, never a pass. That is the fail-closed rule for resolver ambiguity: if we cannot see
 * where a name points, we do not go there.
 */
final class DnsHostResolver implements HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        // An IP literal is its own resolution. The guard still screens it, so a literal pointing
        // at loopback is refused exactly as a resolved one would be.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A hostname that is not a plausible DNS name never reaches the resolver.
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host) !== 1) {
            return [];
        }

        $addresses = [];

        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
