<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * What a stood-down deployment must look like, as a decision separate from the command that
 * observes it.
 *
 * WHY THIS IS NOT INSIDE `ops/rollback/verify-stand-down.php`. That file is an entry point: it
 * opens a database, reads a lock, walks a ledger. None of that is testable without a host, and the
 * consequence showed up as a real defect — an earlier revision COMPUTED the scheduler posture and
 * the live-read state, printed both in its JSON, and let neither reach its findings list. A
 * deployment with a configured scheduler credential and an endpoint that had lost its POST-only
 * guard was reported `STAND_DOWN_VERIFIED`, with both facts visible in the very output that said
 * so. A verifier whose fields contradict its own verdict is worse than no verifier, because the
 * verdict is the part people quote.
 *
 * So the observing stays in the command and the DECIDING lives here, where every combination can be
 * exercised without a host. The rule is then one thing in one place rather than a sequence of `if`
 * statements somebody has to notice are missing.
 *
 * WHAT "STOOD DOWN" MEANS, and each clause is a finding rather than a field:
 *
 *   the release that is live is the one the rollback restored;
 *   the Operator Console manifest matches it and is loadable;
 *   the scheduler handler registry is empty, the endpoint is POST only, no credential is
 *     configured, and an unauthenticated call is refused;
 *   live read is neither claimed by the lock nor supported by the registry;
 *   the evidence registry is valid and asserts nothing it cannot support;
 *   every governed ledger stream still verifies.
 *
 * A DEPLOYMENT THAT HAS LEGITIMATELY ACTIVATED SOMETHING IS NOT A DEPLOYMENT THAT HAS STOOD DOWN,
 * which is why none of these is configurable. The day the scheduler is genuinely activated, this
 * rule changes in a reviewed commit — not through a flag an operator can pass to make a red
 * verifier green.
 */
final class StandDownPosture
{
    /**
     * A digest that stands for "the code that was preflighted", for `stoodDown()` only.
     *
     * It is never compared against a real tree: `findings()` only asks whether the expected and
     * deployed digests are equal, so the fixture needs a value, not a true one.
     */
    public const PLACEHOLDER_FINGERPRINT = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /**
     * Every way the observed posture contradicts a stand-down, in a stable order.
     *
     * @param array{
     *     expected_release: string,
     *     running_release: ?string,
     *     console_manifest_present: bool,
     *     console_manifest_valid: bool,
     *     handler_registry_empty: bool,
     *     endpoint_post_only: bool,
     *     credential_configured: bool,
     *     unauthenticated_call_authorized: bool,
     *     live_read_claimed: bool,
     *     live_read_supported: bool,
     *     expected_fingerprint: ?string,
     *     deployed_fingerprint: string,
     *     evidence_problems: list<string>,
     *     unsupported_claims: list<string>,
     *     failing_stream_ids: list<string>
     * } $observed
     * @return list<string>
     */
    public static function findings(array $observed): array
    {
        $findings = [];

        // ---- the release that is actually live
        if ($observed['running_release'] === null) {
            $findings[] = 'APP_RELEASE_REF is not set — the deployment cannot say which release it is running';
        } elseif ($observed['running_release'] !== $observed['expected_release']) {
            $findings[] = 'APP_RELEASE_REF is not the release the rollback was meant to restore';
        }

        // ---- and the CODE that is actually live, which is a different question
        //
        // `APP_RELEASE_REF` is a line an operator maintains by hand. A rollback is two steps —
        // restore the code, update the configuration — and if the restore fails while the
        // configuration step succeeds, that value says exactly the right thing about the wrong code.
        // Every other signal still looks healthy: the previous build's console manifest is valid,
        // the scheduler posture is unchanged, the ledger verifies. So the check above agrees with
        // the operator about something neither of them measured.
        //
        // Absent expectation is a FINDING, not a pass. A verifier that silently accepted
        // configuration-only evidence for the one claim its whole purpose rests on would be the
        // same defect as reporting a posture without letting it reach the verdict.
        if ($observed['expected_fingerprint'] === null) {
            $findings[] = 'the running code was not verified: pass --expect-fingerprint=<the '
                . 'source_fingerprint preflight printed for this release>, because APP_RELEASE_REF '
                . 'is configuration an operator maintains, not a measurement of what is deployed';
        } elseif ($observed['deployed_fingerprint'] === SourceFingerprint::NOT_AVAILABLE) {
            $findings[] = 'the deployed tree holds none of the application directories — there is '
                . 'nothing here to have restored';
        } elseif ($observed['deployed_fingerprint'] !== $observed['expected_fingerprint']) {
            $findings[] = 'the deployed code does not match the release that was preflighted — the '
                . 'restore did not put this release on disk, whatever APP_RELEASE_REF says';
        }

        // ---- the console assets that must match it
        if (!$observed['console_manifest_present']) {
            $findings[] = 'the Operator Console manifest is absent — /console/app answers 503 until it is restored';
        } elseif (!$observed['console_manifest_valid']) {
            $findings[] = 'the Operator Console manifest does not name an entry script';
        }

        // ---- the scheduler, which a rollback can get half right
        //
        // The registry is a property of the restored CODE and the credential a property of the
        // restored CONFIGURATION, so a rollback can restore one and not the other. Both are faults.
        if (!$observed['handler_registry_empty']) {
            $findings[] = 'a composition root no longer constructs the scheduler with an EMPTY handler registry';
        }
        if (!$observed['endpoint_post_only']) {
            $findings[] = 'public_html/scheduler.php is no longer POST only — a GET that mutates is a GET a crawler performs';
        }
        if ($observed['credential_configured']) {
            // Stood down means UNREACHABLE, not merely closed. With neither key set every token is
            // refused; with one set there is a live door to a machine that has nothing to run.
            $findings[] = 'a scheduler credential is configured — a stood-down deployment leaves the tick unreachable';
        }
        if ($observed['unauthenticated_call_authorized']) {
            $findings[] = 'the scheduler endpoint authorizes a caller presenting no token';
        }

        // ---- live read, in both directions
        //
        // Two different faults. The lock still asserting it is a governance edit nobody made; the
        // registry still supporting it is a withdrawal that never happened or was never recorded.
        // Neither is caught by an "asserted without evidence" check, because a fully supported claim
        // is exactly what a stand-down was performed to end.
        if ($observed['live_read_claimed']) {
            $findings[] = 'claim_boundary.live_read_activated is still asserted — this deployment has not stood down';
        }
        if ($observed['live_read_supported']) {
            $findings[] = 'the evidence registry still shows live read activated — no withdrawal record supersedes it';
        }

        // ---- the registry itself
        foreach ($observed['evidence_problems'] as $problem) {
            $findings[] = 'deployment evidence: ' . $problem;
        }
        foreach ($observed['unsupported_claims'] as $claim) {
            $findings[] = "claim_boundary.{$claim} is asserted with no evidence supporting it";
        }

        // ---- and the governed history the rollback claims to have preserved
        foreach ($observed['failing_stream_ids'] as $streamId) {
            $findings[] = "M10 stream {$streamId} does not verify";
        }

        return $findings;
    }

    /**
     * The posture of a deployment that HAS stood down: every observation at its safe value.
     *
     * Exists so a caller — and a test — can state only the deviation it means, instead of
     * restating thirteen fields each time and getting one wrong.
     *
     * `$fingerprint` is the digest of the code that is supposed to be running. It defaults to a
     * placeholder that is both expected AND deployed, so a caller stating an unrelated deviation
     * does not accidentally also assert an unverified release.
     *
     * @return array<string, mixed>
     */
    public static function stoodDown(string $release, string $fingerprint = self::PLACEHOLDER_FINGERPRINT): array
    {
        return [
            'expected_release' => $release,
            'running_release' => $release,
            'expected_fingerprint' => $fingerprint,
            'deployed_fingerprint' => $fingerprint,
            'console_manifest_present' => true,
            'console_manifest_valid' => true,
            'handler_registry_empty' => true,
            'endpoint_post_only' => true,
            'credential_configured' => false,
            'unauthenticated_call_authorized' => false,
            'live_read_claimed' => false,
            'live_read_supported' => false,
            'evidence_problems' => [],
            'unsupported_claims' => [],
            'failing_stream_ids' => [],
        ];
    }
}
