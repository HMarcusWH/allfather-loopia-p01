<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * What a stood-down deployment must look like, as a decision separate from the command that
 * observes it. Every unsafe observation becomes a finding; no CLI flag can waive one.
 */
final class StandDownPosture
{
    public const PLACEHOLDER_FINGERPRINT = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /**
     * @param array{
     *     expected_release: string,
     *     running_release: ?string,
     *     console_manifest_present: bool,
     *     console_manifest_valid: bool,
     *     console_unexpected_entries: list<string>,
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
     *     failing_stream_ids: list<string>,
     *     failing_monitor_run_ids?: list<string>,
     *     admitted_execution_grants?: int,
     *     admitted_monitor_schedules?: int,
     *     maintenance_state?: string,
     *     maintenance_operation_state?: string,
     *     maintenance_operation_kind?: ?string
     * } $observed
     * @return list<string>
     */
    public static function findings(array $observed): array
    {
        $findings = [];

        if ($observed['running_release'] === null) {
            $findings[] = 'APP_RELEASE_REF is not set — the deployment cannot say which release it is running';
        } elseif ($observed['running_release'] !== $observed['expected_release']) {
            $findings[] = 'APP_RELEASE_REF is not the release the rollback was meant to restore';
        }

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

        if (!$observed['console_manifest_present']) {
            $findings[] = 'the Operator Console manifest is absent — /console/app answers 503 until it is restored';
        } elseif (!$observed['console_manifest_valid']) {
            $findings[] = 'the Operator Console manifest does not name an entry script';
        }
        foreach ($observed['console_unexpected_entries'] as $entry) {
            $findings[] = "the Operator Console public stage contains an unsafe unmanifested entry: {$entry}";
        }

        if (!$observed['handler_registry_empty']) {
            $findings[] = 'a composition root no longer constructs the scheduler with an EMPTY handler registry';
        }
        if (!$observed['endpoint_post_only']) {
            $findings[] = 'public_html/scheduler.php is no longer POST only — a GET that mutates is a GET a crawler performs';
        }
        if ($observed['credential_configured']) {
            $findings[] = 'a scheduler credential is configured — a stood-down deployment leaves the tick unreachable';
        }
        if ($observed['unauthenticated_call_authorized']) {
            $findings[] = 'the scheduler endpoint authorizes a caller presenting no token';
        }

        if ($observed['live_read_claimed']) {
            $findings[] = 'claim_boundary.live_read_activated is still asserted — this deployment has not stood down';
        }
        if ($observed['live_read_supported']) {
            $findings[] = 'the evidence registry still shows live read activated — no withdrawal record supersedes it';
        }

        // A current OR future-dated owner-admitted grant is execution-capable state: a NOT_YET_VALID
        // grant can become live later without another owner act. Stand-down must remove both.
        $admitted = (int) ($observed['admitted_execution_grants'] ?? 0);
        if ($admitted > 0) {
            $findings[] = "{$admitted} execution grant(s) are still admitted or future-admitted — suspend or revoke them with ops/monitor/stand-down.php";
        }

        // An admitted schedule makes the next tick compose the MONITOR_STEP handler (P07).
        $schedules = (int) ($observed['admitted_monitor_schedules'] ?? 0);
        if ($schedules > 0) {
            $findings[] = "{$schedules} monitor schedule(s) are still admitted or future-admitted — suspend or revoke them with ops/monitor/stand-down.php";
        }

        if (($observed['maintenance_state'] ?? 'OPEN') === 'INVALID') {
            $findings[] = 'the deployment maintenance marker is invalid — runtime fails closed until an operator repairs it';
        }
        $operationState = (string) ($observed['maintenance_operation_state'] ?? 'OPEN');
        if ($operationState === 'INVALID') {
            $findings[] = 'the deployment maintenance-operation marker is invalid — runtime fails closed until an operator repairs it';
        } elseif ($operationState !== 'OPEN') {
            $kind = $observed['maintenance_operation_kind'] ?? null;
            $suffix = is_string($kind) && $kind !== '' ? " ({$kind})" : '';
            $findings[] = "a deployment maintenance operation is still active{$suffix} — complete or explicitly recover it before declaring stand-down";
        }

        foreach ($observed['evidence_problems'] as $problem) {
            $findings[] = 'deployment evidence: ' . $problem;
        }
        foreach ($observed['unsupported_claims'] as $claim) {
            $findings[] = "claim_boundary.{$claim} is asserted with no evidence supporting it";
        }
        foreach ($observed['failing_stream_ids'] as $streamId) {
            $findings[] = "M10 stream {$streamId} does not verify";
        }
        foreach (($observed['failing_monitor_run_ids'] ?? []) as $runId) {
            $findings[] = "Monitor run {$runId} does not replay with integrity PASS";
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    public static function stoodDown(string $release, string $fingerprint = self::PLACEHOLDER_FINGERPRINT): array
    {
        return [
            'expected_release' => $release,
            'running_release' => $release,
            'expected_fingerprint' => $fingerprint,
            'deployed_fingerprint' => $fingerprint,
            'console_manifest_present' => true,
            'console_manifest_valid' => true,
            'console_unexpected_entries' => [],
            'handler_registry_empty' => true,
            'endpoint_post_only' => true,
            'credential_configured' => false,
            'unauthenticated_call_authorized' => false,
            'live_read_claimed' => false,
            'live_read_supported' => false,
            'evidence_problems' => [],
            'unsupported_claims' => [],
            'failing_stream_ids' => [],
            'failing_monitor_run_ids' => [],
            'admitted_execution_grants' => 0,
            'admitted_monitor_schedules' => 0,
            'maintenance_state' => 'OPEN',
            'maintenance_operation_state' => 'OPEN',
            'maintenance_operation_kind' => null,
        ];
    }
}
