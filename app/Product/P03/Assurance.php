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
 * Deliberate narrowing at the INPUT boundary only: a non-canonical `as_of` or a negative/non-integer
 * window is refused before any row is written. Canonical `Date.parse` turns a malformed `asOf` into
 * NaN, every comparison against NaN is false, and the audit then passes everything — a fail-open
 * recorded in docs/UPSTREAM_FINDINGS.md rather than reproduced here. Valid inputs follow canonical
 * semantics exactly, including canonical's tenant-only catalogue scope (also recorded upstream).
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
        $ids = [];
        foreach (self::CATEGORIES as $category) {
            $profileKey = 'p03.' . strtolower($category);
            $existing = $this->db->scalar(
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
        $errorWindowMs = $input['freshness_window_ms'] ?? ($target['error_after_ms'] === null ? null : (int) $target['error_after_ms']);
        if ($errorWindowMs !== null) {
            $latest = $this->db->scalar(
                "SELECT captured_at FROM evidence_objects
                 WHERE source_ref_id = ? AND evidence_type = 'UPLOADED_SOURCE_CAPTURE'
                 ORDER BY captured_at DESC LIMIT 1",
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
                 ORDER BY reviewed_at DESC LIMIT 1',
                [(string) $target['source_license_card_id']]
            );
            $reviewedMs = $reviewedAt === null ? null : self::ms((string) $reviewedAt);
            if ($reviewedMs === null || ($asOfMs - $reviewedMs) > (int) $target['recheck_terms_after_ms']) {
                $reasonCodes[] = 'CATALOGUE_TERMS_RECHECK_OVERDUE';
            }
        }

        $stale = $reasonCodes !== [];

        return $this->record($profile, $checked, [
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

        return $this->record($profile, $checked, [
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
     * ACTIVE profiles (it never seeds or reactivates one), audits each tenant catalogue visible to the
     * workspace and each fit hypothesis in the workspace, and runs the buyer-intent scan once. Each
     * audit records its own run/check/finding and opens its own incident. A missing profile is
     * reported, not invented.
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

        $profiles = [];
        foreach (self::CATEGORIES as $category) {
            $profiles[$category] = $this->db->scalar(
                "SELECT audit_profile_id FROM audit_profiles
                 WHERE tenant_id = ? AND workspace_id = ? AND audit_category = ? AND status = 'ACTIVE'
                 ORDER BY created_at, audit_profile_id LIMIT 1",
                [$input['tenant_id'], $input['workspace_id'], $category]
            );
        }
        $requester = [
            'as_of' => $checked['as_of'],
            'requested_by_actor_type' => $checked['requested_by_actor_type'],
            'requested_by_actor_id' => $checked['requested_by_actor_id'],
        ];

        // Tenant-level catalogues (no workspace) and this workspace's own. Another workspace's
        // catalogue is not swept from here even though the single-target audit would accept it.
        $catalogues = $this->db->all(
            'SELECT customer_product_catalogue_id FROM customer_product_catalogues
             WHERE tenant_id = ? AND (workspace_id IS NULL OR workspace_id = ?)
             ORDER BY created_at, customer_product_catalogue_id LIMIT ' . ($maxTargets + 1),
            [$input['tenant_id'], $input['workspace_id']]
        );
        $hypotheses = $this->db->all(
            "SELECT fit_hypothesis_id FROM fit_hypotheses
             WHERE tenant_id = ? AND workspace_id = ? AND status NOT IN ('REJECTED', 'SUPERSEDED')
             ORDER BY created_at, fit_hypothesis_id LIMIT " . ($maxTargets + 1),
            [$input['tenant_id'], $input['workspace_id']]
        );
        if (count($catalogues) > $maxTargets || count($hypotheses) > $maxTargets) {
            // Refuse before any audit runs: a sweep that silently audited a prefix would report a
            // clean scope it never finished looking at.
            return Outcome::refuse('SWEEP_TARGETS_EXCEED_BOUND', ['max_targets' => $maxTargets], 'POLICY');
        }

        $results = [];
        $missing = [];
        foreach ([
            'CATALOGUE_FRESHNESS' => $catalogues,
            'SIGNAL_PAIRING_FRESHNESS' => $hypotheses,
            'BUYER_INTENT_INVARIANT' => [['scope' => 'workspace']],
        ] as $category => $targets) {
            if ($profiles[$category] === null) {
                $missing[] = $category;
                continue;
            }
            foreach ($targets as $row) {
                $outcome = match ($category) {
                    'CATALOGUE_FRESHNESS' => $this->runCatalogueFreshnessAudit([
                        'audit_profile_id' => (string) $profiles[$category],
                        'customer_product_catalogue_id' => (string) $row['customer_product_catalogue_id'],
                        'freshness_window_ms' => $input['freshness_window_ms'] ?? null,
                        ...$requester,
                    ]),
                    'SIGNAL_PAIRING_FRESHNESS' => $this->runSignalPairingFreshnessAudit([
                        'audit_profile_id' => (string) $profiles[$category],
                        'fit_hypothesis_id' => (string) $row['fit_hypothesis_id'],
                        'signal_pairing_freshness_window_ms' => $input['signal_pairing_freshness_window_ms'],
                        ...$requester,
                    ]),
                    default => $this->runBuyerIntentInvariantAudit([
                        'audit_profile_id' => (string) $profiles[$category],
                        ...$requester,
                    ]),
                };
                $value = $outcome->isOk() ? $outcome->value() : null;
                $results[] = [
                    'audit_category' => $category,
                    'target' => (string) ($row['customer_product_catalogue_id'] ?? $row['fit_hypothesis_id'] ?? 'workspace'),
                    'outcome' => $outcome->isOk() ? 'COMPLETED' : 'REFUSED',
                    'reason_code' => $outcome->isOk() ? null : $outcome->reasonCode(),
                    'finding_type' => $value['finding_type'] ?? null,
                    'failed' => $value['failed'] ?? null,
                    'audit_run_id' => $value['audit_run_id'] ?? null,
                    'incident_case_id' => $value['incident_case_id'] ?? null,
                ];
            }
        }

        return Outcome::ok([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'as_of' => $checked['as_of'],
            'missing_profiles' => $missing,
            'audits' => $results,
            'failed_count' => count(array_filter($results, static fn (array $r): bool => $r['failed'] === true)),
            'refused_count' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'REFUSED')),
        ]);
    }

    // ================================================================ operator entrypoints

    /**
     * Seed the scope's P03 profiles for an operator holding `tenant.administer` there.
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     */
    public function operatorSeedProfiles(array $scope): Outcome
    {
        $denied = $this->authorize($scope, 'tenant.administer', 'P03_AUDIT_PROFILE');
        if ($denied !== null) {
            return $denied;
        }

        return Outcome::ok(['audit_profile_ids' => $this->seedProfiles($scope['tenant_id'], $scope['workspace_id'])]);
    }

    /**
     * Sweep one scope for an operator holding `incident.manage` there — a sweep OPENS incidents, so
     * the permission that manages incidents is the one asked for. The run is recorded as OPERATOR,
     * with the operator's identity, never as an anonymous service.
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     * @param array{signal_pairing_freshness_window_ms: int, freshness_window_ms?: ?int, as_of?: ?string, max_targets?: ?int} $input
     */
    public function operatorSweep(array $scope, array $input): Outcome
    {
        $denied = $this->authorize($scope, 'incident.manage', 'P03_AUDIT_SWEEP');
        if ($denied !== null) {
            return $denied;
        }

        return $this->sweep([
            ...$input,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'requested_by_actor_type' => 'OPERATOR',
            'requested_by_actor_id' => $scope['operator_id'],
        ]);
    }

    /**
     * The scope's P03 audit posture, read-only, for an operator holding `incident.read` there: each
     * category's profile and the latest run's outcome. Reading writes nothing (an ALLOW is not
     * retained as an access record).
     *
     * @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope
     */
    public function operatorStatus(array $scope): Outcome
    {
        $denied = $this->authorize($scope, 'incident.read', 'P03_AUDIT_STATUS');
        if ($denied !== null) {
            return $denied;
        }
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $profile = $this->db->one(
                "SELECT audit_profile_id, status FROM audit_profiles
                 WHERE tenant_id = ? AND workspace_id = ? AND audit_category = ?
                 ORDER BY (status = 'ACTIVE') DESC, created_at, audit_profile_id LIMIT 1",
                [$scope['tenant_id'], $scope['workspace_id'], $category]
            );
            $latest = $profile === null ? null : $this->db->one(
                'SELECT r.audit_run_id, r.status, r.completed_at, f.finding_type, f.reason_codes
                 FROM audit_runs r JOIN audit_findings f ON f.audit_run_id = r.audit_run_id
                 WHERE r.audit_profile_id = ? ORDER BY r.requested_at DESC, r.audit_run_id DESC LIMIT 1',
                [(string) $profile['audit_profile_id']]
            );
            $categories[$category] = [
                'audit_profile_id' => $profile === null ? null : (string) $profile['audit_profile_id'],
                'profile_status' => $profile === null ? 'MISSING' : (string) $profile['status'],
                'latest_run' => $latest === null ? null : [
                    'audit_run_id' => (string) $latest['audit_run_id'],
                    'status' => (string) $latest['status'],
                    'completed_at' => $latest['completed_at'],
                    'finding_type' => (string) $latest['finding_type'],
                    'reason_codes' => json_decode((string) $latest['reason_codes'], true),
                ],
            ];
        }

        return Outcome::ok(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'categories' => $categories]);
    }

    // ================================================================ helpers

    /** @param array{tenant_id: string, workspace_id: string, operator_id: string} $scope */
    private function authorize(array $scope, string $permission, string $resourceType): ?Outcome
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
        );

        return $access['decision'] === 'ALLOW'
            ? null
            : Outcome::refuse('OPERATOR_NOT_AUTHORIZED', ['access_reason_codes' => $access['reason_codes']], 'AUTHORITY', 'S2');
    }

    /**
     * Validate the caller-supplied knobs before anything is read or written.
     *
     * @param array<string, mixed> $input
     * @return array{as_of: string, as_of_ms: int, requested_by_actor_type: string, requested_by_actor_id: ?string}|Outcome
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
        $asOf = $input['as_of'] ?? null;
        if ($asOf === null) {
            $asOf = $this->clock->now();
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
     * @param array{as_of: string, as_of_ms: int, requested_by_actor_type: string, requested_by_actor_id: ?string} $checked
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $extra
     */
    private function record(array $profile, array $checked, array $spec, array $extra): Outcome
    {
        $now = $this->clock->now();

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
                [$spec['check_key'] . ($failed ? ' FAIL.' : ' PASS.'), $now, $auditRunId]
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
     * Milliseconds since the epoch, or null when the instant cannot be read (canonical treats an
     * unreadable stored instant as NaN, and a NaN comparison never fires — except the terms recheck,
     * which canonical deliberately fails closed on).
     */
    private static function ms(string $iso): ?int
    {
        $parsed = Clock::parse($iso);
        if ($parsed === null) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,3})?)?(Z|[+-]\d{2}:\d{2})$/', $iso) !== 1) {
                return null;
            }
            try {
                $parsed = new \DateTimeImmutable($iso);
            } catch (\Exception) {
                return null;
            }
        }

        return (int) $parsed->format('Uv');
    }
}
