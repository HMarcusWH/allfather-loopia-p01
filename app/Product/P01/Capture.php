<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ingestion\EgressGuard;
use Allfather\Loopia\Ingestion\FetchTransport;
use Allfather\Loopia\Ingestion\HostResolver;
use Allfather\Loopia\Ingestion\SourcePolicy;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M06\Gateway;

/**
 * P01 live source capture — turning an approved, ACTIVE monitored source into a governed snapshot.
 *
 * This is where four planes meet, and the ORDER they meet in is the design:
 *
 *   1. SCOPE and LIFECYCLE, re-checked here even though registration already checked them. A
 *      competitor archived, a source reference revoked, or a card unapproved between registration
 *      and capture must stop the capture — authority is a property of NOW, not of when the
 *      monitored source was set up.
 *   2. THE COMPILED SOURCE POLICY, which decides what may be fetched, from where, and how often.
 *      A blocked compile refuses BEFORE any dispatch or network attempt.
 *   3. THE M06 GATE CHAIN, which decides whether this agent, on this mission, under this tenant's
 *      subscription, may dispatch a read at all.
 *   4. THE EGRESS GUARD, inside M01, which decides whether this specific URL and every redirect
 *      hop may actually be reached.
 *
 * No layer substitutes for another. The policy says the card permits this host; the guard says the
 * host does not resolve to a private address. Those are different questions, and a capture needs
 * both answered.
 *
 * FAIL-CLOSED THROUGHOUT: a refusal at any layer writes NO snapshot, and the specific reason is
 * carried into the audit trail rather than flattened into "capture failed".
 */
final class Capture
{
    /**
     * How long a cadence reservation is held.
     *
     * It expires rather than requiring release, so a process that dies mid-capture cannot leave a
     * source permanently un-capturable. Fifteen minutes is far longer than any capture and far
     * shorter than any cadence window worth protecting.
     */
    private const RESERVATION_TTL_MS = 900_000;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Gateway $gateway,
        private readonly EvidenceSpine $evidence,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, monitored_source_id: string,
     *     capture_url: string, idempotency_key: string, correlation_id: string,
     *     payload_class?: string,
     *     governance: array{
     *         mission_id: string, assignment_id: string, valkyrie_id: string,
     *         capability_contract_id: string, governed_tool_id: string,
     *         governed_tool_operation_id: string, calling_actor_type: string, calling_actor_id: string
     *     }
     * } $input
     */
    public function captureMonitoredSource(
        array $input,
        FetchTransport $transport,
        HostResolver $resolver,
    ): Outcome {
        $row = $this->db->one(
            'SELECT ms.tenant_id, ms.workspace_id, ms.source_ref_id, ms.status AS ms_status,
                    lc.source_license_card_id, lc.tenant_id AS card_tenant_id,
                    lc.source_ref_id AS card_source_ref_id,
                    c.status AS competitor_status,
                    sr.tenant_id AS source_ref_tenant_id, sr.status AS source_ref_status,
                    sr.content_sensitivity_class, sr.lawful_or_permitted_use_scope
             FROM monitored_sources ms
             JOIN competitors c ON c.competitor_id = ms.competitor_id
             JOIN source_license_cards lc ON lc.source_license_card_id = ms.source_license_card_id
             JOIN source_references sr ON sr.source_ref_id = ms.source_ref_id
             WHERE ms.monitored_source_id = ?',
            [$input['monitored_source_id']]
        );
        if ($row === null) {
            return Outcome::refuse('MONITORED_SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }

        // Cross-tenant or cross-workspace is a hard scope failure, and so is a card that describes
        // a different reference than the one being captured.
        if ((string) $row['tenant_id'] !== $input['tenant_id']
            || (string) $row['workspace_id'] !== $input['workspace_id']
            || (string) $row['card_tenant_id'] !== $input['tenant_id']
            || (string) $row['source_ref_tenant_id'] !== $input['tenant_id']
            || (string) $row['card_source_ref_id'] !== (string) $row['source_ref_id']
        ) {
            return Outcome::refuse('MONITORED_SOURCE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $row['ms_status'] !== 'ACTIVE') {
            return Outcome::refuse('MONITORED_SOURCE_NOT_ACTIVE', [
                'status' => (string) $row['ms_status'],
            ], 'POLICY');
        }
        // Re-checked at CAPTURE time. Registration checked it too; that check was about whether the
        // source could ever be watched, and this one is about whether it may be read right now.
        if (!in_array((string) $row['competitor_status'], ['ACTIVE', 'PAUSED'], true)) {
            return Outcome::refuse('COMPETITOR_NOT_LIVE', [
                'status' => (string) $row['competitor_status'],
            ], 'POLICY');
        }

        // --- the compiled source policy ---------------------------------------------------------
        $nowMs = self::instantMs($this->clock->now());
        $card = $this->db->one(
            'SELECT * FROM source_license_cards WHERE source_license_card_id = ?',
            [(string) $row['source_license_card_id']]
        );
        $review = $this->db->one(
            'SELECT robots_policy_checked, terms_policy_checked, reviewed_at, expires_at, terms_snapshot_hash
             FROM source_terms_reviews WHERE source_license_card_id = ?
             ORDER BY reviewed_at DESC, source_terms_review_id DESC LIMIT 1',
            [(string) $row['source_license_card_id']]
        );
        // The connector descriptor is a PLATFORM row, not a tenant one: which connector classes
        // this deployment has promoted is a property of the deployment, not of who is asking.
        $connector = $this->db->one(
            "SELECT connector_class, connector_version, status, required_content_types
             FROM ingestion_connector_descriptors
             WHERE connector_class = ? AND status = 'ACTIVE'
             ORDER BY connector_version DESC LIMIT 1",
            [self::connectorClassForCard($card)]
        );
        if ($connector !== null) {
            $connector['required_content_types'] = json_decode(
                (string) ($connector['required_content_types'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR
            );
        }

        $policy = SourcePolicy::compile(
            [
                'source_ref_id' => (string) $row['source_ref_id'],
                'tenant_id' => (string) $row['source_ref_tenant_id'],
                'status' => (string) $row['source_ref_status'],
                'content_sensitivity_class' => (string) $row['content_sensitivity_class'],
            ],
            $card,
            $review,
            $connector,
            $nowMs,
        );
        if ($policy['status'] !== 'COMPILED_ALLOW') {
            // The FIRST fail-closed code is the reason. They are ordered by the compiler in the
            // order the authorities were checked, so the first one names the most fundamental
            // failure rather than whichever happened to be evaluated last.
            return Outcome::refuse($policy['fail_closed_reason_codes'][0], [
                'fail_closed_reason_codes' => $policy['fail_closed_reason_codes'],
            ], 'POLICY');
        }

        // The card's URL is the authority; the capture URL must fall inside it. A capture outside
        // its own card's scope is a capture nobody licensed.
        if ($policy['allowed_url_scope'] === null) {
            return Outcome::refuse('SOURCE_URL_MISSING_OR_INVALID', [], 'POLICY');
        }
        if (!SourcePolicy::urlWithinScope($policy['allowed_url_scope'], $input['capture_url'])) {
            return Outcome::refuse('CAPTURE_URL_OUTSIDE_SOURCE_SCOPE', [], 'POLICY', 'S4');
        }

        // --- the cadence reservation --------------------------------------------------------------
        $reservationId = null;
        if ($policy['minimum_fetch_interval_ms'] > 0) {
            $reserved = $this->reserveCadence(
                $input['tenant_id'],
                $input['workspace_id'],
                $input['monitored_source_id'],
                $input['idempotency_key'],
                (int) $policy['minimum_fetch_interval_ms'],
                $nowMs,
            );
            if ($reserved->isRefusal()) {
                return $reserved;
            }
            $reservationId = $reserved->value()['source_capture_reservation_id'];
        }

        try {
            return $this->dispatchAndMaterialize($input, $row, $policy, $transport, $resolver);
        } finally {
            // Released whatever happened. The TTL is the safety net for a process that dies; the
            // release is what keeps the normal path from waiting for it.
            if ($reservationId !== null) {
                $this->db->run(
                    'DELETE FROM source_capture_reservations WHERE source_capture_reservation_id = ?',
                    [$reservationId]
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $row
     * @param array<string, mixed> $policy
     */
    private function dispatchAndMaterialize(
        array $input,
        array $row,
        array $policy,
        FetchTransport $transport,
        HostResolver $resolver,
    ): Outcome {
        // The guard's specific reason is captured out of the closure so a non-succeeded dispatch can
        // report WHY without a second database round trip — and so the reason reaching the audit
        // trail is the guard's own, not a summary of it.
        $guardReason = null;
        $performLiveFetch = function () use ($input, $policy, $transport, $resolver, &$guardReason): array {
            $result = EgressGuard::guardedFetch(
                $input['capture_url'],
                $policy['allowed_hosts'],
                $transport,
                $resolver,
                'GET',
                (int) $policy['timeout_ms'],
                (int) $policy['max_response_bytes'],
                (int) $policy['max_redirects'],
                $policy['allowed_content_types'] === [] ? null : $policy['allowed_content_types'],
                // The card's ORIGIN — host and port — carried into every redirect hop.
                // `allowed_hosts` is hostname-only by design because it feeds DNS screening, so
                // without this a redirect could reach a different https service on another PORT of
                // the same hostname and still pass the host allowlist.
                EgressGuard::effectiveHttpsOrigin($policy['allowed_url_scope']),
            );
            if ($result['outcome'] === 'FETCHED') {
                return ['kind' => 'FETCHED', 'content_hash' => $result['content_hash']];
            }
            $guardReason = $result['reason_code'];

            return ['kind' => $result['outcome'] === 'REFUSED' ? 'REFUSED' : 'FAILED', 'reason_code' => $result['reason_code']];
        };

        $governance = $input['governance'];
        $dispatch = $this->gateway->requestLiveSourceDispatch([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'mission_id' => $governance['mission_id'],
            'assignment_id' => $governance['assignment_id'],
            'valkyrie_id' => $governance['valkyrie_id'],
            'capability_contract_id' => $governance['capability_contract_id'],
            'governed_tool_id' => $governance['governed_tool_id'],
            'governed_tool_operation_id' => $governance['governed_tool_operation_id'],
            'requested_action_class' => 'OBSERVE',
            // A P01 capture is dispatchable only when the tenant's ACTIVE tier grants
            // `competition_monitoring.read`. The M06 gate composes this with M02, default-deny, so
            // an unsubscribed tenant cannot run captures however complete its governance is.
            'required_entitlement_permission' => 'competition_monitoring.read',
            'normalized_input_ref_safe' => sprintf(
                'capture:%s:%s',
                $input['monitored_source_id'],
                substr(Digest::canonicalDigest(['url' => $input['capture_url']]), 0, 16),
            ),
            'idempotency_key' => $input['idempotency_key'],
            'correlation_id' => $input['correlation_id'],
            'calling_actor_type' => $governance['calling_actor_type'],
            'calling_actor_id' => $governance['calling_actor_id'],
        ], $performLiveFetch);

        if ($dispatch['kind'] !== 'ALLOWED_AND_DISPATCHED') {
            return Outcome::refuse(self::describeNonDispatch($dispatch), [
                'dispatch_kind' => $dispatch['kind'],
                'tool_request_id' => $dispatch['tool_request_id'] ?? $dispatch['prior_tool_request_id'] ?? null,
            ], 'POLICY');
        }
        if ($dispatch['outcome_status'] !== 'SUCCEEDED') {
            // The guard refused, the fetch failed, or M01 refused. The SPECIFIC reason is what an
            // operator needs: an SSRF refusal and a timeout call for different responses.
            return Outcome::refuse($guardReason ?? ($dispatch['m01_reason_code'] ?? 'DISPATCH_NOT_SUCCEEDED'), [
                'tool_request_id' => $dispatch['tool_request_id'],
            ], 'POLICY');
        }

        // --- materialize -------------------------------------------------------------------------
        // The evidence and the snapshot are ONE act. A snapshot without its evidence would be a
        // capture with no provenance; evidence without its snapshot would be provenance for
        // something the monitoring path cannot see.
        $contentHash = (string) $dispatch['result_digest'];

        return $this->db->transaction(function (Database $tx) use (
            $input, $row, $dispatch, $contentHash
        ): Outcome {
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $input['governance']['mission_id'],
                'source_ref_id' => (string) $row['source_ref_id'],
                // The M06/M01 trail is cited, and M05 RE-VERIFIES it: a HOST_RETRIEVAL_CAPTURE
                // whose cited dispatch does not exist, or did not succeed, is refused there. So
                // this capture cannot claim a governed retrieval it did not perform.
                'tool_request_id' => $dispatch['tool_request_id'],
                'tool_authorization_decision_id' => $dispatch['tool_authorization_decision_id'],
                'runtime_host_execution_ref' => $dispatch['runtime_host_execution_ref'],
                'evidence_type' => 'HOST_RETRIEVAL_CAPTURE',
                'captured_content_reference_safe' => $dispatch['result_reference_safe'],
                'content_hash' => $contentHash,
                'capture_method' => 'LIVE_FETCH',
                'permitted_use_scope' => $row['lawful_or_permitted_use_scope'] ?? 'P01_PUBLIC_SOURCE',
                'sensitivity_class' => (string) $row['content_sensitivity_class'],
            ]);
            if ($captured->isRefusal()) {
                return Outcome::refuse('EVIDENCE_' . $captured->reasonCode(), [
                    'tool_request_id' => $dispatch['tool_request_id'],
                ], 'EVIDENCE');
            }

            $sequence = 1 + (int) $tx->scalar(
                'SELECT COALESCE(MAX(snapshot_sequence), 0) FROM source_snapshots WHERE monitored_source_id = ?',
                [$input['monitored_source_id']]
            );
            $snapshotId = Ids::new();
            $tx->insert('source_snapshots', [
                'source_snapshot_id' => $snapshotId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'monitored_source_id' => $input['monitored_source_id'],
                'evidence_id' => $captured->value()['evidence_id'],
                'snapshot_sequence' => $sequence,
                // The snapshot's content hash IS the captured content hash the guard computed over
                // the exact bytes read, bound to the URL. It is not recomputed here, because a
                // second computation over a second reading would describe different bytes.
                'content_hash' => $contentHash,
                'hash_algorithm' => 'sha256',
                'capture_method' => 'LIVE_FETCH',
                'payload_class' => $input['payload_class'] ?? 'html_snapshot',
                'captured_at' => $this->clock->now(),
            ]);

            return Outcome::ok([
                'outcome' => 'CAPTURED',
                'source_snapshot_id' => $snapshotId,
                'evidence_id' => $captured->value()['evidence_id'],
                'content_hash' => $contentHash,
                'snapshot_sequence' => $sequence,
                'tool_request_id' => $dispatch['tool_request_id'],
            ]);
        });
    }

    /**
     * Reserve the cadence slot for one monitored source.
     *
     * Two separate protections, and they answer different questions. The CADENCE check asks "has
     * enough time passed since the last successful capture?" — a licence and courtesy question.
     * The RESERVATION check asks "is another capture of this source already in flight?" — a
     * concurrency question that the cadence check alone cannot answer, because two simultaneous
     * captures would both see the same last-capture time and both proceed.
     */
    private function reserveCadence(
        string $tenantId,
        string $workspaceId,
        string $monitoredSourceId,
        string $idempotencyKey,
        int $minIntervalMs,
        int $nowMs,
    ): Outcome {
        $now = $this->clock->now();
        $expiresAt = self::msToInstant($nowMs + self::RESERVATION_TTL_MS);

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $monitoredSourceId, $idempotencyKey, $minIntervalMs, $nowMs, $now, $expiresAt
        ): Outcome {
            // Expired reservations are cleared first, so a dead process cannot hold a source shut.
            $tx->run(
                'DELETE FROM source_capture_reservations WHERE monitored_source_id = ? AND expires_at <= ?',
                [$monitoredSourceId, $now]
            );

            $last = $tx->scalar(
                'SELECT captured_at FROM source_snapshots WHERE monitored_source_id = ?
                 ORDER BY snapshot_sequence DESC LIMIT 1',
                [$monitoredSourceId]
            );
            if ($last !== null && ($nowMs - self::instantMs((string) $last)) < $minIntervalMs) {
                return Outcome::refuse('SOURCE_FETCH_CADENCE_TOO_SOON', [
                    'minimum_fetch_interval_ms' => $minIntervalMs,
                ], 'POLICY');
            }

            $active = $tx->one(
                'SELECT source_capture_reservation_id FROM source_capture_reservations
                 WHERE monitored_source_id = ?',
                [$monitoredSourceId]
            );
            if ($active !== null) {
                return Outcome::refuse('SOURCE_CAPTURE_ALREADY_IN_PROGRESS', [], 'POLICY');
            }

            $reservationId = Ids::new();
            $tx->insert('source_capture_reservations', [
                'source_capture_reservation_id' => $reservationId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'monitored_source_id' => $monitoredSourceId,
                'idempotency_key' => $idempotencyKey,
                'reserved_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return Outcome::ok(['source_capture_reservation_id' => $reservationId]);
        });
    }

    /** @param array<string, mixed>|null $card */
    private static function connectorClassForCard(?array $card): string
    {
        return match ((string) ($card['source_type'] ?? '')) {
            'website', 'press_release' => 'PUBLIC_WEB_PAGE',
            'rss' => 'RSS_OR_ATOM_FEED',
            'public_api', 'licensed_api' => 'JSON_API',
            'customer_upload', 'manual' => 'TENANT_UPLOADED_ARTIFACT',
            // An unmapped card type resolves to a class no descriptor will match, so the compile
            // blocks on CONNECTOR_NOT_ACTIVATED rather than silently picking a plausible one.
            default => 'UNMAPPED',
        };
    }

    /** A safe, audit-friendly reason for a dispatch that never reached a capturable outcome. */
    private static function describeNonDispatch(array $dispatch): string
    {
        return match ($dispatch['kind']) {
            'DENIED' => 'DISPATCH_DENIED_' . ($dispatch['reason_codes'][0] ?? 'UNSPECIFIED'),
            'HELD' => 'DISPATCH_HELD',
            'REQUIRE_APPROVAL' => 'DISPATCH_REQUIRES_APPROVAL',
            'QUARANTINED' => 'DISPATCH_QUARANTINED',
            'IDEMPOTENT_REPLAY' => 'DISPATCH_IDEMPOTENT_REPLAY',
            'CONFLICT' => 'TOOL_IDEMPOTENCY_CONFLICT',
            default => 'DISPATCH_NOT_ALLOWED',
        };
    }

    private static function instantMs(string $iso): int
    {
        $timestamp = strtotime($iso);

        // A capture clock that cannot be read must not produce a cadence answer. Zero makes every
        // interval comparison read as "long ago", which would be the WRONG direction — so this
        // throws instead: the governed clock produces canonical instants, and one that does not
        // parse is a fault in this projection, not an ordinary condition to absorb.
        if ($timestamp === false) {
            throw new \RuntimeException("uninterpretable governed instant: {$iso}");
        }

        return $timestamp * 1000;
    }

    private static function msToInstant(int $ms): string
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
