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

    /**
     * The compiler's fail-closed codes mapped back onto P01's OWN rejection vocabulary, in P01's
     * historical precedence order.
     *
     * This adapter exists so the compiler can be the ONE law without P01 losing the reason codes
     * its callers, audits and conformance transcripts were built on. The order is the law: the
     * first matching code wins, so a refusal names the most fundamental authority that failed
     * rather than whichever leg the compiler happened to evaluate last.
     *
     * Where a compiler code has no historical P01 equivalent (a prohibited use, license or
     * sensitivity) it NARROWS to SOURCE_POLICY_PROHIBITED. Narrowing is the only direction an
     * adapter may move: it may not invent an authority the card never granted, and it may not
     * forgive one the compiler refused.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const P01_REASON_BY_COMPILER_CODE = [
        ['SOURCE_CARD_NOT_APPROVED', 'SOURCE_CARD_NOT_APPROVED'],
        ['SOURCE_REFERENCE_NOT_ACTIVE', 'SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE'],
        ['SOURCE_CARD_REVIEW_EXPIRED', 'SOURCE_CARD_REVIEW_EXPIRED'],
        ['ROBOTS_NOT_CHECKED', 'TERMS_RECHECK_OVERDUE_AT_CAPTURE'],
        ['TERMS_RECHECK_OVERDUE', 'TERMS_RECHECK_OVERDUE_AT_CAPTURE'],
        ['SOURCE_RETENTION_PROHIBITED', 'SOURCE_RETENTION_PROHIBITED'],
        ['SOURCE_USE_PROHIBITED', 'SOURCE_POLICY_PROHIBITED'],
        ['SOURCE_LICENSE_PROHIBITED', 'SOURCE_POLICY_PROHIBITED'],
        ['SOURCE_CONTRACT_REQUIRED_BUT_MISSING', 'SOURCE_POLICY_PROHIBITED'],
        ['SOURCE_SENSITIVITY_PROHIBITED', 'SOURCE_POLICY_PROHIBITED'],
        ['SOURCE_SCOPE_MISMATCH', 'MONITORED_SOURCE_SCOPE_MISMATCH'],
        ['SOURCE_URL_MISSING_OR_INVALID', 'SOURCE_URL_MISSING_OR_INVALID'],
    ];

    /**
     * The CLOSED connector-leg skip list — the only compiler codes a P01 capture may ignore.
     *
     * A P01 capture dispatches through its own governed read tool, not through an M15 connector,
     * so whether this deployment has promoted and activated a connector class says nothing about
     * whether this card authorizes this read. Every other code, including any future compiler
     * reason this projection has never seen, refuses.
     *
     * @var list<string>
     */
    private const P01_OUT_OF_SCOPE_COMPILER_CODES = [
        'CONNECTOR_NOT_ACTIVATED',
        'CONNECTOR_CLASS_NOT_PROMOTED',
        'SOURCE_TYPE_UNSUPPORTED',
    ];

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
        ?callable $beforeFetch = null,
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
        // NO CONNECTOR. A P01 monitored-source capture is not a spine connector dispatch — it
        // rides its own governed read tool — so connector activation is out of P01's scope.
        // Handing the compiler a descriptor row here would make capture depend on a generic ACTIVE
        // M15 connector descriptor that this authority never required.
        $policy = SourcePolicy::compile(
            [
                'source_ref_id' => (string) $row['source_ref_id'],
                'tenant_id' => (string) $row['source_ref_tenant_id'],
                'status' => (string) $row['source_ref_status'],
                'content_sensitivity_class' => (string) $row['content_sensitivity_class'],
            ],
            $card,
            $review,
            null,
            $nowMs,
        );
        // The compile STATUS is deliberately NOT the gate, and that is not a loosening. Compiled
        // with no connector the compiler always blocks on its connector leg, and that leg is not
        // P01's authority to enforce. What IS the gate is the mapped P01 reason: every non-connector
        // code refuses, and any code the map does not know refuses fail-closed.
        $cardRefusal = self::p01ReasonFor($policy['fail_closed_reason_codes']);
        if ($cardRefusal !== null) {
            // A scope mismatch is classified the same way whichever gate raised it, so the two
            // routes to that code are indistinguishable in the audit trail — which is the point:
            // they are the same finding.
            $isScope = $cardRefusal === 'MONITORED_SOURCE_SCOPE_MISMATCH';

            return Outcome::refuse(
                $cardRefusal,
                ['fail_closed_reason_codes' => $policy['fail_closed_reason_codes']],
                $isScope ? 'SCOPE' : 'POLICY',
                $isScope ? 'S4' : 'S2',
            );
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
            return $this->dispatchAndMaterialize($input, $row, $policy, $card, $transport, $resolver, $beforeFetch);
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
        array $card,
        FetchTransport $transport,
        HostResolver $resolver,
        ?callable $beforeFetch,
    ): Outcome {
        // The guard's specific reason is captured out of the closure so a non-succeeded dispatch can
        // report WHY without a second database round trip — and so the reason reaching the audit
        // trail is the guard's own, not a summary of it.
        $guardReason = null;
        $captureProof = null;
        // Exact source-policy slice: no raw text; no-cache/short/minimized retention stays hash-only.
        $retainComparison = (int) ($card['derived_summary_allowed'] ?? 0) === 1
            && in_array($card['caching_policy'] ?? null, ['standard_cache', 'explicit_contract'], true)
            && in_array($card['allowed_use_class'] ?? null, ['derived_summary_allowed', 'customer_visible_allowed'], true)
            && ($card['retention_class'] ?? null) === 'standard';
        $performLiveFetch = function () use ($input, $policy, $transport, $resolver, $beforeFetch, &$guardReason, &$captureProof, $retainComparison): array {
            // Deployment-specific authority gets one last chance to stand the operation down at the
            // actual side-effect boundary. This is deliberately INSIDE the M01 callback and before
            // EgressGuard performs DNS resolution or HTTP. A refusal produces a truthful governed
            // dispatch outcome, spends the already-reserved budget, and sends nothing.
            if ($beforeFetch !== null) {
                $fresh = $beforeFetch();
                if (!$fresh instanceof Outcome || !$fresh->isOk()) {
                    $guardReason = $fresh instanceof Outcome ? $fresh->reasonCode() : 'SOURCE_PRE_DISPATCH_CHECK_INVALID';
                    return ['kind' => 'REFUSED', 'reason_code' => $guardReason];
                }
            }

            // Source/card authority is mutable independently of the execution grant. Re-read it at
            // the SAME side-effect boundary: a card/review/source/competitor revoked after the
            // earlier compile must not still authorize DNS/HTTP. Any policy drift also refuses
            // rather than silently using cadence/egress limits computed from stale authority.
            $current = $this->preDispatchPolicy($input, $policy);
            if (!$current->isOk()) {
                $guardReason = $current->reasonCode();
                return ['kind' => 'REFUSED', 'reason_code' => $guardReason];
            }
            $effectivePolicy = $current->value()['policy'];
            // Retention only narrows across the gap. A newly-permissive card cannot make this
            // already-started capture retain line-level comparison proof it was not allowed to keep
            // when the operation began.
            $retainNow = $retainComparison && $current->value()['retain_comparison_allowed'];

            $result = EgressGuard::guardedFetch(
                $input['capture_url'],
                $effectivePolicy['allowed_hosts'],
                $transport,
                $resolver,
                'GET',
                (int) $effectivePolicy['timeout_ms'],
                (int) $effectivePolicy['max_response_bytes'],
                (int) $effectivePolicy['max_redirects'],
                $effectivePolicy['allowed_content_types'] === [] ? null : $effectivePolicy['allowed_content_types'],
                // The card's ORIGIN — host and port — carried into every redirect hop.
                // `allowed_hosts` is hostname-only by design because it feeds DNS screening, so
                // without this a redirect could reach a different https service on another PORT of
                // the same hostname and still pass the host allowlist.
                EgressGuard::effectiveHttpsOrigin($effectivePolicy['allowed_url_scope']),
                // #65: origin alone cannot authorize another PATH on the same server.
                allowedUrlScope: $effectivePolicy['allowed_url_scope'],
            );
            if ($result['outcome'] === 'FETCHED') {
                $captureProof = ['content_hash' => $result['content_hash'], 'text' => $retainNow
                    ? json_encode(TextProvenance::capture($result['content_hash'], $result['body']), JSON_THROW_ON_ERROR) : null];
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
        if ($captureProof === null || $captureProof['content_hash'] !== $contentHash) {
            return Outcome::refuse('CAPTURE_PROVENANCE_MISMATCH', [], 'PROVENANCE');
        }

        return $this->db->transaction(function (Database $tx) use (
            $input, $row, $dispatch, $contentHash, $captureProof
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
                'text_provenance_json' => $captureProof['text'],
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
     * Read-only current P01 source authority. This is shared by configuration/readiness and the
     * actual pre-dispatch boundary so "pilot ready" and "may fetch now" cannot drift into two rules.
     *
     * @param array{tenant_id:string,workspace_id:string,monitored_source_id:string,capture_url:string} $input
     * @param null|array<string,mixed> $expectedPolicy when supplied, policy drift also refuses
     * @return Outcome<array{policy:array<string,mixed>,retain_comparison_allowed:bool}>
     */
    public static function currentPolicy(Database $db, Clock $clock, array $input, ?array $expectedPolicy = null): Outcome
    {
        $row = $db->one(
            'SELECT ms.tenant_id,ms.workspace_id,ms.source_ref_id,ms.status AS ms_status,
                    ms.source_license_card_id,c.status AS competitor_status,
                    sr.tenant_id AS source_ref_tenant_id,sr.status AS source_ref_status,
                    sr.content_sensitivity_class
             FROM monitored_sources ms
             JOIN competitors c ON c.competitor_id=ms.competitor_id
             JOIN source_references sr ON sr.source_ref_id=ms.source_ref_id
             WHERE ms.monitored_source_id=?',
            [$input['monitored_source_id']]
        );
        if ($row === null) {
            return Outcome::refuse('MONITORED_SOURCE_NOT_FOUND');
        }
        if ($row['tenant_id'] !== $input['tenant_id'] || $row['workspace_id'] !== $input['workspace_id']
            || $row['source_ref_tenant_id'] !== $input['tenant_id']) {
            return Outcome::refuse('MONITORED_SOURCE_SCOPE_MISMATCH');
        }
        if ($row['ms_status'] !== 'ACTIVE') {
            return Outcome::refuse('MONITORED_SOURCE_NOT_ACTIVE');
        }
        if (!in_array($row['competitor_status'], ['ACTIVE','PAUSED'], true)) {
            return Outcome::refuse('COMPETITOR_NOT_LIVE');
        }

        $card = $db->one(
            'SELECT * FROM source_license_cards WHERE source_license_card_id=? AND tenant_id=? AND source_ref_id=?',
            [$row['source_license_card_id'], $input['tenant_id'], $row['source_ref_id']]
        );
        if ($card === null) {
            return Outcome::refuse('MONITORED_SOURCE_SCOPE_MISMATCH');
        }
        $review = $db->one(
            'SELECT robots_policy_checked,terms_policy_checked,reviewed_at,expires_at,terms_snapshot_hash
             FROM source_terms_reviews WHERE source_license_card_id=?
             ORDER BY reviewed_at DESC,source_terms_review_id DESC LIMIT 1',
            [$row['source_license_card_id']]
        );
        $policy = SourcePolicy::compile([
            'source_ref_id' => (string) $row['source_ref_id'],
            'tenant_id' => (string) $row['source_ref_tenant_id'],
            'status' => (string) $row['source_ref_status'],
            'content_sensitivity_class' => (string) $row['content_sensitivity_class'],
        ], $card, $review, null, self::instantMs($clock->now()));

        $refusal = self::p01ReasonFor($policy['fail_closed_reason_codes']);
        if ($refusal !== null) {
            return Outcome::refuse($refusal);
        }
        if ($policy['allowed_url_scope'] === null) {
            return Outcome::refuse('SOURCE_URL_MISSING_OR_INVALID');
        }
        if (!SourcePolicy::urlWithinScope($policy['allowed_url_scope'], $input['capture_url'])) {
            return Outcome::refuse('CAPTURE_URL_OUTSIDE_SOURCE_SCOPE');
        }
        if ($expectedPolicy !== null
            && !hash_equals((string) ($expectedPolicy['policy_digest'] ?? ''), (string) $policy['policy_digest'])) {
            return Outcome::refuse('SOURCE_POLICY_CHANGED_BEFORE_DISPATCH');
        }

        return Outcome::ok([
            'policy' => $policy,
            'retain_comparison_allowed' => (int) ($card['derived_summary_allowed'] ?? 0) === 1
                && in_array($card['caching_policy'] ?? null, ['standard_cache','explicit_contract'], true)
                && in_array($card['allowed_use_class'] ?? null, ['derived_summary_allowed','customer_visible_allowed'], true)
                && ($card['retention_class'] ?? null) === 'standard',
        ]);
    }

    /** Re-read authority at the actual I/O boundary and require the earlier compiled policy to match. */
    private function preDispatchPolicy(array $input, array $expectedPolicy): Outcome
    {
        return self::currentPolicy($this->db, $this->clock, $input, $expectedPolicy);
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

    /**
     * Translate a compiled policy's fail-closed codes into P01's rejection reason, or null when the
     * compile raised nothing P01 is the authority for.
     *
     * FAIL CLOSED is the whole point of the tail: an unmapped code is not a code to ignore, it is a
     * policy violation this projection does not yet have a name for, and a capture must not happen
     * because a reason was unfamiliar.
     *
     * @param list<string> $failClosedReasonCodes
     */
    private static function p01ReasonFor(array $failClosedReasonCodes): ?string
    {
        foreach (self::P01_REASON_BY_COMPILER_CODE as [$compilerCode, $p01Code]) {
            if (in_array($compilerCode, $failClosedReasonCodes, true)) {
                return $p01Code;
            }
        }

        $unexpected = array_filter(
            $failClosedReasonCodes,
            static fn (string $code): bool => !in_array($code, self::P01_OUT_OF_SCOPE_COMPILER_CODES, true),
        );

        return $unexpected === [] ? null : 'SOURCE_POLICY_PROHIBITED';
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
        $parsed = Clock::parse($iso);

        // The governed clock is millisecond-precise. strtotime() returns whole seconds and silently
        // erased the .sss component, which made a declared 0-ms source-policy recheck remain current
        // for the rest of that second and made sub-second capture cadence unenforceable. Parse the
        // canonical instant and preserve its milliseconds explicitly.
        if ($parsed === null) {
            throw new \RuntimeException("uninterpretable governed instant: {$iso}");
        }

        return ((int) $parsed->format('U') * 1000) + (int) $parsed->format('v');
    }

    private static function msToInstant(int $ms): string
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
