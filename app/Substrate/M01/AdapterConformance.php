<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M01;

use Allfather\Loopia\Core\Digest;

/**
 * Adapter identity verification, replayed at EVERY M01 boundary.
 *
 * A persisted `status = 'READY'` is not trusted by itself: it is a claim the row makes about
 * itself, and a row can be edited. What is verified here is that the adapter's stored identity
 * payloads are still exactly the ones its identity digest was computed over.
 *
 * CHARACTERIZED DIVERGENCE FROM CANONICAL. Upstream also replays the Harness Port conformance law
 * (`checkAdapterConformance`) over the descriptor, manifest and profile, and compares the adapter
 * label the law derives. That law lives in the harness package, which is the host adapter runtime —
 * outside this projection's boundary and deliberately not ported. So this class verifies the half
 * that does not depend on the harness:
 *
 *   1. all three identity payloads are in canonical form, so a payload rewritten after
 *      registration is detectable rather than silently accepted; and
 *   2. the host-bound identity digest recomputes from those exact payloads.
 *
 * It therefore proves the adapter identity has not DRIFTED. It does NOT prove the adapter conforms
 * to a harness descriptor contract — no such contract exists here, because no harness adapter can
 * be registered in this projection. Claiming otherwise would be the untrue half of the check.
 */
final class AdapterConformance
{
    /**
     * @param array{
     *     runtime_host_instance_id: string,
     *     adapter_family: string,
     *     adapter_name: string,
     *     adapter_version: string,
     *     descriptor_identity: string,
     *     capability_manifest_identity: string,
     *     conformance_profile_identity: string,
     *     identity_digest: string
     * } $identity
     */
    public static function verify(array $identity): bool
    {
        return self::explain($identity)['conformant'];
    }

    /**
     * @param array<string, string> $identity
     * @return array{conformant: bool, reason?: string}
     */
    public static function explain(array $identity): array
    {
        foreach (['descriptor_identity', 'capability_manifest_identity', 'conformance_profile_identity'] as $field) {
            $raw = $identity[$field];
            try {
                $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['conformant' => false, 'reason' => "{$field} is not valid JSON"];
            }
            // The project's own canonical form, which is stricter and better defined than a
            // round-trip through the encoder: key order, number form and escaping are all fixed.
            if (Digest::canonicalize($parsed) !== $raw) {
                return ['conformant' => false, 'reason' => "{$field} is not in canonical form"];
            }
        }

        $expected = self::identityDigest($identity);

        return $identity['identity_digest'] === $expected
            ? ['conformant' => true]
            : ['conformant' => false, 'reason' => 'adapter identity digest does not match its own payloads'];
    }

    /**
     * The host-bound identity digest.
     *
     * The host instance is part of it, so the same adapter registered against a different host is a
     * different identity — a registration cannot be lifted from one host to another.
     *
     * @param array<string, string> $identity
     */
    public static function identityDigest(array $identity): string
    {
        return Digest::canonicalDigest([
            'runtimeHostInstanceId' => $identity['runtime_host_instance_id'],
            'adapterFamily' => $identity['adapter_family'],
            'adapterName' => $identity['adapter_name'],
            'adapterVersion' => $identity['adapter_version'],
            'descriptorIdentity' => $identity['descriptor_identity'],
            'capabilityManifestIdentity' => $identity['capability_manifest_identity'],
            'conformanceProfileIdentity' => $identity['conformance_profile_identity'],
        ]);
    }
}
