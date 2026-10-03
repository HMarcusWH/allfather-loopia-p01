<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The three P03 audits — post-outcome scans over what the lead-qualification path has already done.
 *
 * Ported from canonical `packages/allfather-control-substrate/src/p03/assurance.ts` @
 * 1c40306c22e15b2ad3049e8d32abff47450c8921 (P08). Parity is held by
 * `tests/conformance/p03_assurance.php` against vectors the frozen canonical implementation produced
 * from identical declared fixtures.
 *
 *   CATALOGUE_FRESHNESS       Is the customer catalogue still ACTIVE, its card review still approved
 *                             and unexpired, its latest upload inside the freshness window and its
 *                             terms review inside the recheck window?
 *   SIGNAL_PAIRING_FRESHNESS  Is the signal a fit hypothesis cites still RELEASED and young enough?
 *   BUYER_INTENT_INVARIANT    Does every priority-outreach queue item cite a buying-trigger signal
 *                             class observed as FACT? The PRIMARY gate is the M08 buyer-intent rule;
 *                             this is defence in depth that catches anything that bypassed it.
 *
 * These are NOT release authority and NOT a substitute for M08. They ride the existing M11 plane
 * unchanged — audit_runs → audit_check_records → audit_findings, and a failing finding opens an
 * incident through the certified M11 intake. They READ the audited rows and never write to them: an
 * audit that repaired what it found would leave no evidence the fault existed. No new assurance store
 * is introduced.
 *
 * Deliberate narrowing at the INPUT boundary: a non-canonical `as_of` or a negative/non-integer
 * window is refused before any row is written. Canonical `Date.parse` turns a malformed `asOf` into
 * NaN, and every time-based comparison against NaN is false: expiry, capture age, terms-review age
 * and signal age all stop firing, while status- and missing-row reasons still do — a fail-open
 * recorded in docs/UPSTREAM_FINDINGS.md (UF-003) rather than reproduced here.
 *
 * STORED instants are read the way canonical `Date.parse` reads the ES date-time-string format
 * (self::ms), pinned by parity vectors; the engine- and host-dependent remainder (legacy non-ISO
 * strings, offset-less date-times read in host-local time) is recorded upstream (UF-005). Otherwise
 * valid inputs follow canonical semantics, including canonical's tenant-only catalogue scope (UF-004).
 * The one incident-plane difference is the pre-existing M11 projection's OPENED timeline entry
 * (SERVICE + first related ref, where canonical triage writes SYSTEM + none), recorded in
 * docs/P03_ASSURANCE_PORT.md §2 and pinned by the conformance suite.
 */
final class Assurance
{
    /** The categories this port owns, in canonical order. */
    public const CATEGORIES = ['CATALOGUE_FRESHNESS', 'SIGNAL_PAIRING_FRESHNESS', 'BUYER_INTENT_INVARIANT'];

    /** The default buyer-intent trigger set (canonical D6) — the audit mirrors the M08 rule's classes. */
    public const BUYER_INTENT_TRIGGER_CLASSES = [
        'PRICING_CHANGE_SIGNAL',
        'PRODUCT_LAUNCH_SIGNAL',
        'HIRING_OR_EXPANSION_SIGNAL',
        'REGULATORY_OR_COMPLIANCE_SIGNAL',
    ];

    private const REQUESTERS = ['OPERATOR', 'SERVICE', 'SYSTEM'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /**
     * Seed and activate the three P03 profiles for one scope. Idempotent per (scope, profile_key):
     * a second call returns the profiles that already exist rather than minting rivals, and never
     * reactivates one an operator suspended or revoked.
     *
     * @return array<string, string> category => audit_profile_id
     */
    public function seedProfiles(string $tenantId, string $workspaceId): array
    {
        // Canonical enforces one ACTIVE profile per (scope, key) with a partial UNIQUE index that
        // MariaDB cannot express (migration 010), so this check-then-insert is the enforcement and it
        // is serialized on the tenant row, as the other idempotent per-tenant creators are. Without
        // the lock two concurrent seeds both read "none" and both insert.
        return $this->db->transaction(function (Database $tx) use ($tenantId, $workspaceId): array {
            $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id = ? FOR UPDATE', [$tenantId]);
            $ids = [];
            foreach (self::CATEGORIES as $category) {
                $profileKey = 'p03.' . strtolower($category);
                $existing = $tx->scalar(
                    'SELECT audit_profile_id FROM audit_profiles
                     WHERE tenant_id = ? AND workspace_id = ? AND profile_key = ?
                     ORDER BY created_at, audit_profile_id LIMIT 1',
                    [$tenantId, $workspaceId, $profileKey]
                );
                $ids[$category] = $existing !== null
                    ? (string) $existing
                    : $this->m11->createAuditProfile(
                        $tenantId,
                        $workspaceId,
                        $profileKey,
                        $category,
                        'P03 ' . strtolower(str_replace('_', ' ', $category)),
                    );
            }

            return $ids;
        });
    }

    // ================================================================ CATALOGUE_FRESHNESS

    /**
     * @param array{
     *     audit_profile_id: string, customer_product_catalogue_id: string,
     *     freshness_window_ms?: ?int, as_of?: ?string,
     *     requested_by_actor_type?: ?string, requested_by_actor_id?: ?string
     * } $input
     */
    public function runCatalogueFreshnessAudit(array $input): Outcome
    {
        $checked = $this->checkInput($input, $input['freshness_window_ms'] ?? null, false);
        if ($checked instanceof Outcome) {
            return $checked;
        }
        $profile = $this->activeProfile($input['audit_profile_id'], 'CATALOGUE_FRESHNESS');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $target = $this->db->one(
            'SELECT c.tenant_id, c.catalogue_status, c.source_ref_id, c.source_license_card_id,
                    slc.review_status, slc.review_expires_at, slc.error_after_ms, slc.recheck_terms_after_ms
             FROM customer_product_catalogues c
             JOIN source_license_cards slc ON slc.source_license_card_id = c.source_license_card_id
             WHERE c.customer_product_catalogue_id = ?',
            [$input['customer_product_catalogue_id']]
        );
        if ($target === null) {
            return Outcome::refuse('TARGET_NOT_FOUND', [], 'PROVENANCE');
        }
        // Canonical compares TENANT only here, because a catalogue's workspace is nullable (a
        // catalogue may be tenant-level). Its sibling below compares the workspace too because ITS
        // target column is NOT NULL. Recorded upstream: a workspace-OWNED catalogue is therefore
        // auditable from a sibling workspace's profile. Parity is kept until canonical decides.
        if ((string) $target['tenant_id'] !== $profile['tenant_id']) {
            return Outcome::refuse('TARGET_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        $asOfMs = $checked['as_of_ms'];
        $reasonCodes = [];
        if ((string) $target['catalogue_status'] !== 'ACTIVE') {
            $reasonCodes[] = 'CATALOGUE_NOT_ACTIVE';
        }
        $reviewStatus = (string) $target['review_status'];
        if ($reviewStatus === 'expired') {
            $reasonCodes[] = 'CATALOGUE_REVIEW_EXPIRED';
        } elseif ($reviewStatus !== 'approved') {
            $reasonCodes[] = 'CATALOGUE_REVIEW_NOT_APPROVED';
        }
        if ($target['review_expires_at'] !== null && (string) $target['review_expires_at'] !== '') {
            $expiryMs = self::ms((string) $target['review_expires_at']);
            if ($expiryMs !== null && $expiryMs < $asOfMs) {
                $reasonCodes[] = 'CATALOGUE_REVIEW_PAST_EXPIRY';
            }
        }

        // A caller window wins over the card's error window; with neither, capture age is not judged.
        // "Latest" is canonical's SQLite BINARY string order, not this column's utf8mb4_unicode_ci
        // order: the two agree on canonical's own fixed-width `…Z` instants but not on mixed forms
        // (`…12:00:00Z` vs `…12:00+02:00`), and the row chosen decides the verdict.
        $errorWindowMs = $input['freshness_window_ms'] ?? ($target['error_after_ms'] === null ? null : (int) $target['error_after_ms']);
        if ($errorWindowMs !== null) {
            $latest = $this->db->scalar(
                "SELECT captured_at FROM evidence_objects
                 WHERE source_ref_id = ? AND evidence_type = 'UPLOADED_SOURCE_CAPTURE'
                 ORDER BY captured_at COLLATE utf8mb4_bin DESC LIMIT 1",
                [(string) $target['source_ref_id']]
            );
            if ($latest === null) {
                $reasonCodes[] = 'NO_CAPTURE_FOR_FRESHNESS_WINDOW';
            } else {
                $capturedMs = self::ms((string) $latest);
                if ($capturedMs !== null && ($asOfMs - $capturedMs) > $errorWindowMs) {
                    $reasonCodes[] = 'CATALOGUE_CAPTURE_STALE';
                }
            }
        }
        if ($target['recheck_terms_after_ms'] !== null) {
            $reviewedAt = $this->db->scalar(
                'SELECT reviewed_at FROM source_terms_reviews WHERE source_license_card_id = ?
                 ORDER BY reviewed_at COLLATE utf8mb4_bin DESC LIMIT 1',
                [(string) $target['source_license_card_id']]
            );
            $reviewedMs = $reviewedAt === null ? null : self::ms((string) $reviewedAt);
            if ($reviewedMs === null || ($asOfMs - $reviewedMs) > (int) $target['recheck_terms_after_ms']) {
                $reasonCodes[] = 'CATALOGUE_TERMS_RECHECK_OVERDUE';
            }
        }

        $stale = $reasonCodes !== [];
        $judgement = $this->judgement($input, $checked, isset($input['freshness_window_ms'])
            ? 'caller capture window ' . $errorWindowMs . ' ms'
            : ($errorWindowMs === null ? 'no capture window' : 'card capture window ' . $errorWindowMs . ' ms'));

        return $this->record($profile, $checked, [
            'judgement' => $judgement,
            'audit_category' => 'CATALOGUE_FRESHNESS',
            'target_object_refs' => [$input['customer_product_catalogue_id']],
            'check_key' => 'P03_CATALOGUE_FRESHNESS',
            'failed' => $stale,
            'finding_type' => $stale ? 'EVIDENCE_OR_AUTHORITY_GAP' : 'CONTROL_PASS',
            'severity' => 'S3',
            'reason_codes' => $reasonCodes,
            'safe_check_summary' => $stale
                ? 'Catalogue freshness audit: stale (' . implode(', ', $reasonCodes) . ').'
                : 'Catalogue freshness audit: catalogue ACTIVE, review approved, capture within freshness.',
            'safe_finding_summary' => $stale
                ? 'Catalogue authority/freshness gap: a stale catalogue must not back a fit hypothesis or queue admission.'
                : 'Catalogue review and latest capture are fresh.',
            'incident' => [
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'related_object_refs' => [$input['customer_product_catalogue_id']],
                'safe_summary' => 'P03 catalogue freshness: a stale catalogue must not back a hypothesis/queue admission.',
            ],
        ], ['failed' => $stale, 'reason_codes' => $reasonCodes]);
    }

    // ================================================================ SIGNAL_PAIRING_FRESHNESS

    /**
     * @param array{
     *     audit_profile_id: string, fit_hypothesis_id: string, signal_pairing_freshness_window_ms: int,
     *     as_of?: ?string, requested_by_actor_type?: ?string, requested_by_actor_id?: ?string
     * } $input
     */
    public function runSignalPairingFreshnessAudit(array $input): Outcome
    {
        $checked = $this->checkInput($input, $input['signal_pairing_freshness_window_ms'] ?? null, true);
        if ($checked instanceof Outcome) {
            return $checked;
        }
        $profile = $this->activeProfile($input['audit_profile_id'], 'SIGNAL_PAIRING_FRESHNESS');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $target = $this->db->one(
            'SELECT fh.tenant_id, fh.workspace_id, fh.company_signal_id, cs.status AS signal_status,
                    cs.created_at AS signal_created_at
             FROM fit_hypotheses fh
             JOIN company_signals cs ON cs.company_signal_id = fh.company_signal_id
             WHERE fh.fit_hypothesis_id = ?',
            [$input['fit_hypothesis_id']]
        );
        if ($target === null) {
            return Outcome::refuse('TARGET_NOT_FOUND', [], 'PROVENANCE');
        }
        // `fit_hypotheses.workspace_id` is NOT NULL, so tenant agreement alone does not put the
        // profile and its target in one scope: a sibling workspace must not audit what it cannot see.
        if ((string) $target['tenant_id'] !== $profile['tenant_id']
            || (string) $target['workspace_id'] !== $profile['workspace_id']
        ) {
            return Outcome::refuse('TARGET_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        $reasonCodes = [];
        if ((string) $target['signal_status'] !== 'RELEASED') {
            $reasonCodes[] = 'SIGNAL_NO_LONGER_RELEASED';
        }
        $createdMs = self::ms((string) $target['signal_created_at']);
        if ($createdMs !== null && ($checked['as_of_ms'] - $createdMs) > (int) $input['signal_pairing_freshness_window_ms']) {
            $reasonCodes[] = 'SIGNAL_PAIRING_STALE';
        }

        $stale = $reasonCodes !== [];
        $judgement = $this->judgement($input, $checked, 'signal window ' . (int) $input['signal_pairing_freshness_window_ms'] . ' ms');

        return $this->record($profile, $checked, [
            'judgement' => $judgement,
            'audit_category' => 'SIGNAL_PAIRING_FRESHNESS',
            'target_object_refs' => [$input['fit_hypothesis_id']],
            'check_key' => 'P03_SIGNAL_PAIRING_FRESHNESS',
            'failed' => $stale,
            'finding_type' => $stale ? 'EVIDENCE_OR_AUTHORITY_GAP' : 'CONTROL_PASS',
            'severity' => 'S3',
            'reason_codes' => $reasonCodes,
            'safe_check_summary' => $stale
                ? 'Signal-pairing freshness audit: stale (' . implode(', ', $reasonCodes) . ').'
                : 'Signal-pairing freshness audit: the cited signal is RELEASED and within the freshness window.',
            'safe_finding_summary' => $stale
                ? 'Signal-pairing freshness gap: a stale signal pairing must not back a queue admission.'
                : 'The cited signal is fresh.',
            'incident' => [
                'trigger_module' => 'M05',
                'incident_class' => 'PROVENANCE_OR_IP',
                'related_object_refs' => [$input['fit_hypothesis_id'], (string) $target['company_signal_id']],
                'safe_summary' => 'P03 signal-pairing freshness: a stale pairing must not back a queue admission.',
            ],
        ], ['failed' => $stale, 'reason_codes' => $reasonCodes]);
    }

    // ================================================================ BUYER_INTENT_INVARIANT

    /**
     * Scan every priority-outreach queue item in the profile's scope — whatever its queue status —
     * and verify its triggering signal is a buying-trigger class observed as FACT.
     *
     * @param array{
     *     audit_profile_id: string, as_of?: ?string,
     *     requested_by_actor_type?: ?string, requested_by_actor_id?: ?string
     * } $input
     */
    public function runBuyerIntentInvariantAudit(array $input): Outcome
    {
        $checked = $this->checkInput($input, null, false);
        if ($checked instanceof Outcome) {
            return $checked;
        }
        $profile = $this->activeProfile($input['audit_profile_id'], 'BUYER_INTENT_INVARIANT');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $rows = $this->db->all(
            'SELECT poqi.priority_outreach_queue_item_id AS item_id, cs.signal_class, cs.interpretation_kind
             FROM priority_outreach_queue_items poqi
             JOIN company_signals cs ON cs.company_signal_id = poqi.triggering_company_signal_id
             WHERE poqi.tenant_id = ? AND poqi.workspace_id = ?
             ORDER BY poqi.created_at, poqi.priority_outreach_queue_item_id',
            [$profile['tenant_id'], $profile['workspace_id']]
        );
        $violating = [];
        foreach ($rows as $row) {
            $isTrigger = in_array((string) $row['signal_class'], self::BUYER_INTENT_TRIGGER_CLASSES, true)
                && (string) $row['interpretation_kind'] === 'FACT_OBSERVED';
            if (!$isTrigger) {
                $violating[] = (string) $row['item_id'];
            }
        }
        $violated = $violating !== [];
        $reasonCodes = $violated ? ['BUYER_INTENT_OVERCLAIM_PERSISTED'] : [];

        return $this->record($profile, $checked, [
            'audit_category' => 'BUYER_INTENT_INVARIANT',
            'target_object_refs' => $violating,
            'check_key' => 'P03_BUYER_INTENT_INVARIANT',
            'failed' => $violated,
            'finding_type' => $violated ? 'OVERCLAIM_OR_UI_MISREPRESENTATION' : 'CONTROL_PASS',
            'severity' => 'S2',
            'reason_codes' => $reasonCodes,
            'safe_check_summary' => $violated
                ? sprintf('Buyer-intent invariant audit: %d queue item(s) lack a valid buying-trigger citation.', count($violating))
                : 'Buyer-intent invariant audit: every queue item cites a valid buying trigger.',
            'safe_finding_summary' => $violated
                ? 'A priority outreach queue item asserts buyer intent without a buying-trigger (class + FACT_OBSERVED) citation.'
                : "Every queue item's triggering signal is a buying-trigger FACT.",
            'incident' => [
                'trigger_module' => 'M08',
                'incident_class' => 'DISCLOSURE_OR_OVERCLAIM',
                'related_object_refs' => $violating,
                'safe_summary' => 'P03 buyer-intent invariant: a queue item asserts buyer intent without a valid trigger.',
            ],
        ], ['violation_count' => count($violating), 'violating_queue_item_ids' => $violating]);
    }

    // ================================================================ operator sweep

    /**
     * Run every P03 audit over every target in one scope, for an operator or a later bounded job.
     *
     * The sweep is a convenience over the three audits, not a new authority: it resolves the scope's
     * ACTIVE profiles (it never seeds or reactivates one), audits each tenant-level or this-workspace
     * catalogue and each live fit hypothesis in the workspace, and runs the buyer-intent scan once.
     * Each audit records its own run/check/finding and, when it fails, opens its own incident — as
     * canonical does for every failing audit call, so a target that is still failing opens a new
     * incident on every sweep (canonical has no de-duplication here; any is an upstream decision).
     *
     * Target selection is projection-owned: a RETIRED catalogue is swept only while a live fit
     * hypothesis in this workspace still cites one of its products — otherwise it backs nothing and
     * would re-open the same CATALOGUE_NOT_ACTIVE incident forever.
     *
     * Bounds and honesty: the number of per-target audits (catalogues + hypotheses together) must not
     * exceed `max_targets`, checked before any audit runs. The buyer-intent scan is ONE audit over
     * every queue item in the scope. Sweeps of one scope are serialized; a second concurrent sweep is
     * refused. An audit that throws is reported as ERRORED and the sweep continues. `complete` is
     * true only when every category had an ACTIVE profile and every audit COMPLETED.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, signal_pairing_freshness_window_ms: int,
     *     freshness_window_ms?: ?int, as_of?: ?string,
     *     requested_by_actor_type?: ?string, requested_by_actor_id?: ?string, max_targets?: ?int
     * } $input
     */
    public function sweep(array $input): Outcome
    {
        $checked = $this->checkInput($input, $input['signal_pairing_freshness_window_ms'] ?? null, true);
        if ($checked instanceof Outcome) {
            return $checked;
        }
        if (isset($input['freshness_window_ms']) && (!is_int($input['freshness_window_ms']) || $input['freshness_window_ms'] < 0)) {
            return Outcome::refuse('FRESHNESS_WINDOW_INVALID', [], 'POLICY');
        }
        $maxTargets = $input['max_targets'] ?? 200;
        if (!is_int($maxTargets) || $maxTargets < 1 || $maxTargets > 1000) {
            return Outcome::refuse('SWEEP_TARGET_BOUND_INVALID', [], 'POLICY');
        }
        foreach (['tenant_id', 'workspace_id'] as $key) {
            if (!is_string($input[$key] ?? null) || !Ids::isValid($input[$key])) {
                return Outcome::refuse('SWEEP_SCOPE_INVALID', [], 'SCOPE');
            }
        }

        // One sweep per scope at a time. Without this, two operators sweeping together audit every
        // target twice and open every incident twice.
        $lock = 'p03-sweep:' . substr(hash('sha256', $input['tenant_id'] . '/' . $input['workspace_id']), 0, 40);
        if ((int) $this->db->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return Outcome::refuse('SWEEP_IN_PROGRESS', [], 'POLICY');
        }
        try {
            return $this->sweepLocked($input, $checked, $maxTargets);
        } finally {
            $this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array{now: string, as_of: string, as_of_ms: int, requested_by_actor_type: string, requested_by_actor_id: ?string} $checked
     */
    private function sweepLocked(array $input, array $checked, int $maxTargets): Outcome
    {
        $profiles = [];
        $skipped = [];
        foreach (self::CATEGORIES as $category) {
            $profile = $this->db->one(
                "SELECT audit_profile_id, status FROM audit_profiles
                 WHERE tenant_id = ? AND workspace_id = ? AND audit_category = ?
                 ORDER BY (status = 'ACTIVE') DESC, created_at, audit_profile_id LIMIT 1",
                [$input['tenant_id'], $input['workspace_id'], $category]
            );
            if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
                // A category that cannot be audited is named with WHY, never folded into "clean".
                $skipped[] = ['audit_category' => $category, 'profile_status' => $profile === null ? 'MISSING' : (string) $profile['status']];
                $profiles[$category] = null;
                continue;
            }
            $profiles[$category] = (string) $profile['audit_profile_id'];
        }
        // A caller-supplied as_of is passed to every audit (canonical semantics: judged at as_of,
        // recorded at now). Without one, each audit judges at its own now — the instant it records as
        // requested_at — rather than at the sweep's start, which a long sweep would leave behind.
        $requester = [
            'record_judgement' => true,
            'as_of' => $input['as_of'] ?? null,
            'requested_by_actor_type' => $checked['requested_by_actor_type'],
            'requested_by_actor_id' => $checked['requested_by_actor_id'],
        ];

        $catalogues = $this->liveCatalogues($input['tenant_id'], $input['workspace_id'], $maxTargets + 1);
        $hypotheses = $this->liveHypotheses($input['tenant_id'], $input['workspace_id'], $maxTargets + 1);
        if (count($catalogues) + count($hypotheses) > $maxTargets) {
            // Refuse before any audit runs: a sweep that silently audited a prefix would report a
            // clean scope it never finished looking at.
            return Outcome::refuse('SWEEP_TARGETS_EXCEED_BOUND', ['max_targets' => $maxTargets], 'POLICY');
        }

        $results = [];
        foreach ([
            'CATALOGUE_FRESHNESS' => $catalogues,
            'SIGNAL_PAIRING_FRESHNESS' => $hypotheses,
            'BUYER_INTENT_INVARIANT' => [['scope' => 'workspace']],
        ] as $category => $targets) {
            if ($profiles[$category] === null) {
                continue;
            }
            foreach ($targets as $row) {
                $target = is_string($row) ? $row : 'workspace';
                try {
                    $outcome = match ($category) {
                        'CATALOGUE_FRESHNESS' => $this->runCatalogueFreshnessAudit([
                            'audit_profile_id' => $profiles[$category],
                            'customer_product_catalogue_id' => $target,
                            'freshness_window_ms' => $input['freshness_window_ms'] ?? null,
                            ...$requester,
                        ]),
                        'SIGNAL_PAIRING_FRESHNESS' => $this->runSignalPairingFreshnessAudit([
                            'audit_profile_id' => $profiles[$category],
                            'fit_hypothesis_id' => $target,
                            'signal_pairing_freshness_window_ms' => $input['signal_pairing_freshness_window_ms'],
                            ...$requester,
                        ]),
                        default => $this->runBuyerIntentInvariantAudit([
                            'audit_profile_id' => $profiles[$category],
                            ...$requester,
                        ]),
                    };
                } catch (\Throwable) {
                    // Each audit is its own transaction, so an earlier audit's run and incident are
                    // already committed. Report this one and keep going: aborting would leave the
                    // operator with a partial result and no summary, and a retry would re-open every
                    // incident the first attempt already opened. No error text leaves this method.
                    $results[] = ['audit_category' => $category, 'target' => $target, 'outcome' => 'ERRORED', 'reason_code' => 'AUDIT_ERRORED',
                        'finding_type' => null, 'failed' => null, 'audit_run_id' => null, 'incident_case_id' => null];
                    continue;
                }
                $value = $outcome->isOk() ? $outcome->value() : null;
                $results[] = [
                    'audit_category' => $category,
                    'target' => $target,
                    'outcome' => $outcome->isOk() ? 'COMPLETED' : 'REFUSED',
                    'reason_code' => $outcome->isOk() ? null : $outcome->reasonCode(),
                    'finding_type' => $value['finding_type'] ?? null,
                    'failed' => $value['failed'] ?? null,
                    'audit_run_id' => $value['audit_run_id'] ?? null,
                    'incident_case_id' => $value['incident_case_id'] ?? null,
                ];
            }
        }
        $count = static fn (callable $test): int => count(array_filter($results, $test));
        $refused = $count(static fn (array $r): bool => $r['outcome'] === 'REFUSED');
        $errored = $count(static fn (array $r): bool => $r['outcome'] === 'ERRORED');

        return Outcome::ok([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'as_of' => $input['as_of'] ?? null,
            'signal_pairing_freshness_window_ms' => $input['signal_pairing_freshness_window_ms'],
            'freshness_window_ms' => $input['freshness_window_ms'] ?? null,
            'complete' => $skipped === [] && $refused === 0 && $errored === 0,
            'skipped_categories' => $skipped,
            'missing_profiles' => array_column($skipped, 'audit_category'),
            'audits' => $results,
            'failed_count' => $count(static fn (array $r): bool => $r['failed'] === true),
            'refused_count' => $refused,
            'errored_count' => $errored,
        ]);
    }

    /**
     * The catalogues a sweep audits — and status reports on — in a scope: tenant-level ones (no
     * workspace) and this workspace's own. Another workspace's catalogue is not swept from here even
     * though the single-target audit would accept it. A RETIRED catalogue counts only while a live
     * fit hypothesis in this workspace still cites one of its products: otherwise it backs nothing,
     * and auditing it would re-open the same CATALOGUE_NOT_ACTIVE incident on every sweep.
     *
     * @return list<string>
     */
    private function liveCatalogues(string $tenantId, string $workspaceId, ?int $limit): array
    {
        return array_map('strval', array_column($this->db->all(
            "SELECT c.customer_product_catalogue_id FROM customer_product_catalogues c
             WHERE c.tenant_id = ? AND (c.workspace_id IS NULL OR c.workspace_id = ?)
               AND (c.catalogue_status <> 'RETIRED' OR EXISTS (
                    SELECT 1 FROM customer_products p
                    JOIN fit_hypotheses fh ON fh.customer_product_id = p.customer_product_id
                    WHERE p.customer_product_catalogue_id = c.customer_product_catalogue_id
                      AND fh.tenant_id = c.tenant_id AND fh.workspace_id = ?
                      AND fh.status NOT IN ('REJECTED', 'SUPERSEDED')))
             ORDER BY c.created_at, c.customer_product_catalogue_id" . ($limit === null ? '' : ' LIMIT ' . $limit),
            [$tenantId, $workspaceId, $workspaceId]
        ), 'customer_product_catalogue_id'));
    }

    /**
     * The live (not REJECTED or SUPERSEDED) fit hypotheses of one workspace.
     *
     * @return list<string>
     */
    private function liveHypotheses(string $tenantId, string $workspaceId, ?int $limit): array
    {
        return array_map('strval', array_column($this->db->all(
            "SELECT fit_hypothesis_id FROM fit_hypotheses
             WHERE tenant_id = ? AND workspace_id = ? AND status NOT IN ('REJECTED', 'SUPERSEDED')
             ORDER BY created_at, fit_hypothesis_id" . ($limit === null ? '' : ' LIMIT ' . $limit),
            [$tenantId, $workspaceId]
        ), 'fit_hypothesis_id'));
    }

    // ================================================================ operator entrypoints

    /**
     * Seed the scope's P03 profiles for an operator holding `tenant.administer` there. The ALLOW is
     * retained as an access record, because audit_profiles carries no actor and the question "who
     * configured P03 assurance here?" must have an answer.
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     */
    public function operatorSeedProfiles(array $scope): Outcome
    {
        $denied = $this->authorize($scope, 'tenant.administer', 'P03_AUDIT_PROFILE', true);
        if ($denied !== null) {
            return $denied;
        }

        return Outcome::ok(['audit_profile_ids' => $this->seedProfiles($scope['tenant_id'], $scope['workspace_id'])]);
    }

    /**
     * Sweep one scope for an operator holding `incident.manage` there — a sweep OPENS incidents, so
     * the permission that manages incidents is the one asked for. The run is recorded as OPERATOR,
     * with the operator's identity, never as an anonymous service, and the ALLOW is retained.
     *
     * The operator path judges each audit at its own NOW and each catalogue under its card's OWN
     * capture window: no M11 row has a field for the instant or window a verdict was computed with
     * (canonical persists neither — UF-005), so a caller-chosen `as_of` or a looser catalogue window
     * would write PASS evidence nobody could tell apart from a real-time one. Both are refused. The
     * signal-pairing window has no default in canonical, so the operator supplies it, bounded to
     * 1..365 days; like the instant and the card window, it is written into each sweep-run audit's
     * run summary.
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     * @param array{signal_pairing_freshness_window_ms: int, max_targets?: ?int} $input
     */
    public function operatorSweep(array $scope, array $input): Outcome
    {
        $denied = $this->authorize($scope, 'incident.manage', 'P03_AUDIT_SWEEP', true);
        if ($denied !== null) {
            return $denied;
        }
        if (($input['as_of'] ?? null) !== null) {
            return Outcome::refuse('SWEEP_AS_OF_NOT_ACCEPTED', [], 'POLICY');
        }
        if (($input['freshness_window_ms'] ?? null) !== null) {
            return Outcome::refuse('SWEEP_CATALOGUE_WINDOW_NOT_ACCEPTED', [], 'POLICY');
        }
        // Canonical has no default signal-pairing window, so the operator names one — bounded to a
        // year so a "window" cannot quietly disable the age check, and recorded on every run.
        $signalWindow = $input['signal_pairing_freshness_window_ms'] ?? null;
        if (!is_int($signalWindow) || $signalWindow < 86_400_000 || $signalWindow > 365 * 86_400_000) {
            return Outcome::refuse('SWEEP_SIGNAL_WINDOW_OUT_OF_RANGE', [], 'POLICY');
        }

        return $this->sweep([
            'signal_pairing_freshness_window_ms' => $signalWindow,
            'max_targets' => $input['max_targets'] ?? null,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'requested_by_actor_type' => 'OPERATOR',
            'requested_by_actor_id' => $scope['operator_id'],
        ]);
    }

    /**
     * The scope's P03 audit posture, read-only, for an operator holding `incident.read` there.
     *
     * Posture is per TARGET, over the targets a sweep would audit NOW (the same selection as the
     * sweep): a rejected hypothesis or an uncited RETIRED catalogue is not swept any more, so its old
     * failure must not keep the scope FAILING, and the single newest run is just whichever target was
     * audited last. For each per-target category this reports every live target's LATEST recorded
     * result (chosen in SQL, so the cost follows the number of targets, not the number of sweeps),
     * lists the failing ones and counts the live targets never audited. Posture: FAILING if any live
     * target's latest result failed; else INCOMPLETE if any live target was never audited; else
     * PASSING; NO_TARGETS when the scope has none. The buyer-intent scan is one scope-wide run, so its
     * latest run is its posture (NOT_AUDITED before the first). A sweep audit that ERRORED or was
     * REFUSED records no run; the sweep's own summary says so. Reading writes nothing.
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     */
    public function operatorStatus(array $scope): Outcome
    {
        $denied = $this->authorize($scope, 'incident.read', 'P03_AUDIT_STATUS');
        if ($denied !== null) {
            return $denied;
        }
        $shape = static fn (array $run): array => [
            'audit_run_id' => (string) $run['audit_run_id'],
            'target_object_refs' => json_decode((string) $run['target_object_refs'], true),
            'status' => (string) $run['status'],
            'completed_at' => $run['completed_at'],
            'summary' => $run['summary'],
            'finding_type' => (string) $run['finding_type'],
            'reason_codes' => json_decode((string) $run['reason_codes'], true),
        ];
        $live = [
            'CATALOGUE_FRESHNESS' => $this->liveCatalogues($scope['tenant_id'], $scope['workspace_id'], null),
            'SIGNAL_PAIRING_FRESHNESS' => $this->liveHypotheses($scope['tenant_id'], $scope['workspace_id'], null),
        ];
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $profile = $this->db->one(
                "SELECT audit_profile_id, status FROM audit_profiles
                 WHERE tenant_id = ? AND workspace_id = ? AND audit_category = ?
                 ORDER BY (status = 'ACTIVE') DESC, created_at, audit_profile_id LIMIT 1",
                [$scope['tenant_id'], $scope['workspace_id'], $category]
            );
            $profileId = $profile === null ? null : (string) $profile['audit_profile_id'];
            $newest = $profileId === null ? null : $this->db->one(
                'SELECT r.audit_run_id, r.target_object_refs, r.status, r.completed_at, r.summary, f.finding_type, f.reason_codes
                 FROM audit_runs r JOIN audit_findings f ON f.audit_run_id = r.audit_run_id
                 WHERE r.audit_profile_id = ? ORDER BY r.requested_at DESC, r.audit_run_id DESC LIMIT 1',
                [$profileId]
            );
            if ($category === 'BUYER_INTENT_INVARIANT') {
                // Its refs are its violators, not its target: the whole history is one target, the scope.
                $latest = $newest === null ? [] : [$shape($newest)];
                $failing = array_values(array_filter($latest, static fn (array $r): bool => $r['finding_type'] !== 'CONTROL_PASS'));
                $posture = $latest === [] ? 'NOT_AUDITED' : ($failing === [] ? 'PASSING' : 'FAILING');
                $liveCount = 1;
            } else {
                $latest = [];
                if ($profileId !== null) {
                    // The newest run per target, one row per target ever audited by this profile. A
                    // per-target run's refs are one id ('["<uuid>"]', 40 characters), so the window
                    // partitions on a bounded CHAR rather than the LONGTEXT column, and only rows short
                    // enough that the cast cannot truncate take part.
                    $rows = $this->db->all(
                        'SELECT x.audit_run_id, x.target_object_refs, x.status, x.completed_at, x.summary, f.finding_type, f.reason_codes
                         FROM (SELECT r.audit_run_id, r.target_object_refs, r.status, r.completed_at, r.summary,
                                      ROW_NUMBER() OVER (PARTITION BY CAST(r.target_object_refs AS CHAR(191))
                                                         ORDER BY r.requested_at DESC, r.audit_run_id DESC) AS rn
                               FROM audit_runs r
                               WHERE r.audit_profile_id = ? AND CHAR_LENGTH(r.target_object_refs) <= 191) x
                         JOIN audit_findings f ON f.audit_run_id = x.audit_run_id
                         WHERE x.rn = 1',
                        [$profileId]
                    );
                    $byTarget = [];
                    foreach ($rows as $row) {
                        $refs = json_decode((string) $row['target_object_refs'], true);
                        if (is_array($refs) && count($refs) === 1 && is_string($refs[0])) {
                            $byTarget[$refs[0]] = $row;
                        }
                    }
                    foreach ($live[$category] as $target) {
                        if (isset($byTarget[$target])) {
                            $latest[] = $shape($byTarget[$target]);
                        }
                    }
                }
                $failing = array_values(array_filter($latest, static fn (array $r): bool => $r['finding_type'] !== 'CONTROL_PASS'));
                $liveCount = count($live[$category]);
                $posture = match (true) {
                    $liveCount === 0 => 'NO_TARGETS',
                    $failing !== [] => 'FAILING',
                    count($latest) < $liveCount => 'INCOMPLETE',
                    default => 'PASSING',
                };
            }
            $categories[$category] = [
                'audit_profile_id' => $profileId,
                'profile_status' => $profile === null ? 'MISSING' : (string) $profile['status'],
                'posture' => $posture,
                'live_targets' => $liveCount,
                'targets_audited' => count($latest),
                'targets_never_audited' => $liveCount - count($latest),
                'failing_targets' => $failing,
                'latest_run' => $newest === null ? null : $shape($newest),
            ];
        }

        return Outcome::ok(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'categories' => $categories]);
    }

    // ================================================================ helpers

    /** @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope */
    private function authorize(array $scope, string $permission, string $resourceType, bool $retainAllow = false): ?Outcome
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!isset($scope[$key]) || !is_string($scope[$key]) || !Ids::isValid($scope[$key])) {
                return Outcome::refuse('OPERATOR_SCOPE_INVALID', [], 'SCOPE');
            }
        }
        $access = (new AccessGuard($this->db, $this->clock))->evaluate(
            'OPERATOR',
            $scope['operator_id'],
            $scope['tenant_id'],
            $scope['workspace_id'],
            $permission,
            $resourceType,
            null,
            null,
            $retainAllow,
        );

        return $access['decision'] === 'ALLOW'
            ? null
            : Outcome::refuse('OPERATOR_NOT_AUTHORIZED', ['access_reason_codes' => $access['reason_codes']], 'AUTHORITY', 'S2');
    }

    /**
     * What a SWEEP-run audit records about how it was judged, appended to its run summary: canonical
     * persists neither the instant nor the window a verdict used (UF-005), so evidence written by the
     * projection's sweep says both. The values are the ones the audit actually used, never a caller's
     * label. A directly called audit records canonical's summary unchanged (parity).
     *
     * @param array<string, mixed> $input
     * @param array{as_of: string} $checked
     */
    private function judgement(array $input, array $checked, string $window): ?string
    {
        if (($input['record_judgement'] ?? false) !== true) {
            return null;
        }

        return sprintf('Judged as of %s (%s) under %s.', $checked['as_of'], ($input['as_of'] ?? null) === null ? 'its own now' : 'caller-supplied', $window);
    }

    /**
     * Validate the caller-supplied knobs before anything is read or written.
     *
     * @param array<string, mixed> $input
     * @return array{now: string, as_of: string, as_of_ms: int, requested_by_actor_type: string, requested_by_actor_id: ?string}|Outcome
     */
    private function checkInput(array $input, mixed $window, bool $windowRequired): array|Outcome
    {
        if ($window === null) {
            if ($windowRequired) {
                return Outcome::refuse('FRESHNESS_WINDOW_INVALID', [], 'POLICY');
            }
        } elseif (!is_int($window) || $window < 0) {
            return Outcome::refuse('FRESHNESS_WINDOW_INVALID', [], 'POLICY');
        }
        // One instant per audit, as canonical's single `now`: the default as_of AND the
        // requested_at/evaluated_at/created_at the audit records.
        $now = $this->clock->now();
        $asOf = $input['as_of'] ?? null;
        if ($asOf === null) {
            $asOf = $now;
        } elseif (!is_string($asOf) || !Clock::isCanonical($asOf)) {
            return Outcome::refuse('AS_OF_NOT_CANONICAL', [], 'POLICY');
        }
        $actorType = $input['requested_by_actor_type'] ?? 'SERVICE';
        if (!in_array($actorType, self::REQUESTERS, true)) {
            return Outcome::refuse('REQUESTER_TYPE_INVALID', [], 'POLICY');
        }
        $actorId = $input['requested_by_actor_id'] ?? null;
        if ($actorId !== null && (!is_string($actorId) || !Ids::isValid($actorId))) {
            return Outcome::refuse('REQUESTER_ID_INVALID', [], 'POLICY');
        }

        return [
            'now' => $now,
            'as_of' => $asOf,
            'as_of_ms' => (int) self::ms($asOf),
            'requested_by_actor_type' => $actorType,
            'requested_by_actor_id' => $actorId,
        ];
    }

    /** @return array{audit_profile_id: string, tenant_id: string, workspace_id: string}|Outcome */
    private function activeProfile(string $auditProfileId, string $category): array|Outcome
    {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status, audit_category FROM audit_profiles WHERE audit_profile_id = ?',
            [$auditProfileId]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('AUDIT_PROFILE_INACTIVE', [], 'PROVENANCE');
        }
        // The profile's category is authority for what it may conclude: a finding citing an audit
        // that never asked the question it answers would be evidence of nothing.
        if ((string) $profile['audit_category'] !== $category) {
            return Outcome::refuse('AUDIT_PROFILE_WRONG_CATEGORY', [
                'audit_category' => (string) $profile['audit_category'],
            ], 'POLICY');
        }

        return [
            'audit_profile_id' => $auditProfileId,
            'tenant_id' => (string) $profile['tenant_id'],
            'workspace_id' => (string) $profile['workspace_id'],
        ];
    }

    /**
     * ONE run, ONE check, ONE finding — and an incident when it failed — in one transaction.
     *
     * @param array{audit_profile_id: string, tenant_id: string, workspace_id: string} $profile
     * @param array{now: string, as_of: string, as_of_ms: int, requested_by_actor_type: string, requested_by_actor_id: ?string} $checked
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $extra
     */
    private function record(array $profile, array $checked, array $spec, array $extra): Outcome
    {
        $now = $checked['now'];

        return $this->db->transaction(function (Database $tx) use ($profile, $checked, $spec, $extra, $now): Outcome {
            $failed = (bool) $spec['failed'];
            $auditRunId = Ids::new();
            $tx->insert('audit_runs', [
                'audit_run_id' => $auditRunId,
                'schema_version' => '1',
                'audit_profile_id' => $profile['audit_profile_id'],
                'tenant_id' => $profile['tenant_id'],
                'workspace_id' => $profile['workspace_id'],
                'audit_category' => $spec['audit_category'],
                'target_object_refs' => json_encode(array_values($spec['target_object_refs']), JSON_THROW_ON_ERROR),
                'requested_by_actor_type' => $checked['requested_by_actor_type'],
                'requested_by_actor_id' => $checked['requested_by_actor_id'],
                'status' => 'RUNNING',
                'requested_at' => $now,
            ]);

            $checkId = Ids::new();
            $tx->insert('audit_check_records', [
                'audit_check_record_id' => $checkId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'check_key' => $spec['check_key'],
                'decision' => $failed ? 'FAIL' : 'PASS',
                'reason_codes' => json_encode($failed ? $spec['reason_codes'] : [], JSON_THROW_ON_ERROR),
                'severity_if_failed' => $failed ? $spec['severity'] : null,
                'safe_summary' => $spec['safe_check_summary'],
                'evaluated_at' => $now,
            ]);

            $findingId = Ids::new();
            $tx->insert('audit_findings', [
                'audit_finding_id' => $findingId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'finding_type' => $spec['finding_type'],
                'finding_status' => 'OPEN',
                'severity' => $failed ? $spec['severity'] : 'S1',
                'confidence_classification' => 'KNOWN',
                'current_reliance_consequence' => $failed ? 'INCIDENT_EVALUATION_REQUIRED' : 'NONE',
                'safe_finding_summary' => $spec['safe_finding_summary'],
                'reason_codes' => json_encode($failed ? $spec['reason_codes'] : [], JSON_THROW_ON_ERROR),
                'incident_evaluation_required' => $failed ? 1 : 0,
                'decision_ledger_record_required' => $failed ? 1 : 0,
                'created_at' => $now,
            ]);

            $tx->run(
                "UPDATE audit_runs SET status = 'COMPLETED', summary = ?, completed_at = ? WHERE audit_run_id = ?",
                [$spec['check_key'] . ($failed ? ' FAIL.' : ' PASS.') . (($spec['judgement'] ?? null) === null ? '' : ' ' . $spec['judgement']), $now, $auditRunId]
            );

            $incidentCaseId = null;
            if ($failed) {
                $incident = $this->m11->openIncident(
                    $profile['tenant_id'],
                    $profile['workspace_id'],
                    $spec['incident']['trigger_module'],
                    $spec['reason_codes'],
                    $spec['severity'],
                    $spec['incident']['incident_class'],
                    $spec['incident']['safe_summary'],
                    array_values($spec['incident']['related_object_refs']),
                );
                if (!$incident->isOk()) {
                    // A failing audit that could not open its incident is not a quiet finding either:
                    // undo the whole unit and say so, rather than leave a finding nobody must triage.
                    return Outcome::refuse('AUDIT_INCIDENT_NOT_OPENED', [], 'PROVENANCE', 'S4');
                }
                $incidentCaseId = $incident->value()['incident_case_id'];
            }

            return Outcome::ok([
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'audit_finding_id' => $findingId,
                'audit_category' => $spec['audit_category'],
                'finding_type' => $spec['finding_type'],
                'failed' => $failed,
                'incident_case_id' => $incidentCaseId,
                ...$extra,
            ]);
        });
    }

    /**
     * Milliseconds since the epoch for an instant, read the way canonical `Date.parse` reads the ES
     * date-time-string format, or null where canonical yields NaN (and a NaN comparison never fires —
     * except the terms recheck, which canonical deliberately fails closed on).
     *
     *   YYYY | YYYY-MM | YYYY-MM-DD (also ±YYYYYY)          date-only forms are UTC midnight
     *   …THH:mm[:ss[.fraction]][Z|±HH:mm]                    fraction truncated to milliseconds
     *   T24:00[:00[.0…]]                                     the next midnight; any later 24:xx, or any
     *                                                        non-zero fraction digit at 24:00, is NaN
     *   a day past the month's end (2026-02-30)              rolls over, as canonical does
     *   seconds 60, minutes 60, hours 25+, month 13, ±24:00  NaN
     *   beyond ±8.64e15 ms (±100,000,000 days, ES TimeClip)   NaN
     *   year -000000                                          not ES (V8 reads it with its legacy parser)
     *
     * An offset-less date-time is HOST-LOCAL in canonical; it is read as UTC here, which is what
     * canonical does on a UTC host (the parity vectors are generated with TZ=UTC). Strings outside
     * this format — which V8's legacy parser may still accept — are unreadable here. Both are
     * engine/host-dependent canonical behaviour, recorded upstream as UF-005, not reproduced.
     */
    private static function ms(string $value): ?int
    {
        if (preg_match('/^([+-]\\d{6}|\\d{4})(?:-(\\d{2})(?:-(\\d{2}))?)?(?:T(\\d{2}):(\\d{2})(?::(\\d{2})(?:\\.(\\d+))?)?(Z|[+-]\\d{2}:\\d{2})?)?$/D', $value, $m) !== 1
            || $m[1] === '-000000'
        ) {
            return null;
        }
        $part = static fn (int $i): ?string => isset($m[$i]) && $m[$i] !== '' ? $m[$i] : null;
        $month = (int) ($part(2) ?? 1);
        $day = (int) ($part(3) ?? 1);
        $hour = (int) ($part(4) ?? 0);
        $minute = (int) ($part(5) ?? 0);
        $second = (int) ($part(6) ?? 0);
        $millis = $part(7) === null ? 0 : (int) substr(str_pad((string) $part(7), 3, '0'), 0, 3);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $minute > 59 || $second > 59
            || $hour > 24 || ($hour === 24 && ($minute !== 0 || $second !== 0 || trim((string) $part(7), '0') !== ''))
        ) {
            return null;
        }
        $offsetMs = 0;
        $zone = $part(8);
        if ($zone !== null && $zone !== 'Z') {
            $offsetHours = (int) substr($zone, 1, 2);
            $offsetMinutes = (int) substr($zone, 4, 2);
            if ($offsetHours > 23 || $offsetMinutes > 59) {
                return null;
            }
            $offsetMs = ($offsetHours * 60 + $offsetMinutes) * 60_000 * ($zone[0] === '-' ? -1 : 1);
        }
        // Days from the civil date (H. Hinnant's algorithm); adding the day-of-month to the 1st gives
        // canonical's roll-over for a day past the month's end.
        $year = (int) $m[1] - ($month <= 2 ? 1 : 0);
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * (($month + 9) % 12) + 2, 5);
        $days = $era * 146097 + $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear - 719468 + ($day - 1);

        $ms = $days * 86_400_000 + (($hour * 60 + $minute) * 60 + $second) * 1000 + $millis - $offsetMs;

        // ES TimeClip: an instant more than 8.64e15 ms from the epoch is NaN.
        return abs($ms) > 8_640_000_000_000_000 ? null : $ms;
    }
}
