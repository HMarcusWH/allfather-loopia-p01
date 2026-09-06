<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

/**
 * The two P01 audits — the ones that ask whether what the product is saying is still true.
 *
 *   SOURCE_FRESHNESS  Is the authority behind this source still current? An approved card that has
 *                     expired, a terms review that has lapsed, a page nobody has captured in months
 *                     — each is a reason the product's claims about that source are older than they
 *                     look.
 *
 *   DIFF_INTEGRITY    Does the recorded change still reconcile with the snapshots it cites? The
 *                     digest is re-derived PURELY from the cited snapshots' own content hashes, so
 *                     a diff whose stored digest no longer matches is a finding about the record.
 *
 * Both are READ-ONLY over what they audit. Neither refreshes a source, re-derives a digest, or
 * repairs anything: an audit that fixed what it found would leave no evidence the fault ever
 * existed, and the next audit would pass without anyone having looked.
 *
 * A failing audit OPENS AN INCIDENT through the certified M11 intake rather than recording a quiet
 * finding. A finding nobody is required to look at is indistinguishable from no finding.
 */
final class Assurance
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly M11Assurance $m11,
    ) {
    }

    /**
     * Is this source's authority still current?
     *
     * @param array{
     *     audit_profile_id: string, monitored_source_id: string,
     *     freshness_window_ms?: ?int, as_of?: ?string
     * } $input
     */
    public function runSourceFreshnessAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'SOURCE_FRESHNESS');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        // The monitored source AND its card must be in the profile's scope. The card's tenant is
        // carried forward separately because `monitored_sources` is mutable and the card FK is a
        // single column: a row pointing at a foreign-tenant card must not have that card's review
        // state trusted under this profile's scope.
        $target = $this->db->one(
            'SELECT ms.tenant_id, ms.workspace_id, slc.source_license_card_id,
                    slc.tenant_id AS card_tenant_id, slc.review_status, slc.review_expires_at,
                    slc.warn_after_ms, slc.error_after_ms, slc.recheck_terms_after_ms,
                    slc.stale_output_behavior
             FROM monitored_sources ms
             JOIN source_license_cards slc ON slc.source_license_card_id = ms.source_license_card_id
             WHERE ms.monitored_source_id = ?',
            [$input['monitored_source_id']]
        );
        if ($target === null
            || (string) $target['tenant_id'] !== $profile['tenant_id']
            || (string) $target['workspace_id'] !== $profile['workspace_id']
            || (string) $target['card_tenant_id'] !== $profile['tenant_id']
        ) {
            return Outcome::refuse('MONITORED_SOURCE_NOT_FOUND_OR_OUT_OF_SCOPE', [], 'SCOPE', 'S4');
        }

        $asOf = $input['as_of'] ?? $this->clock->now();
        $asOfMs = self::ms($asOf);

        // Each stale condition contributes its OWN reason code, so an operator can see which rule
        // fired rather than only that something did.
        $reasonCodes = [];
        $warnCodes = [];

        $reviewStatus = (string) $target['review_status'];
        if ($reviewStatus === 'expired') {
            $reasonCodes[] = 'SOURCE_REVIEW_STATUS_EXPIRED';
        } elseif ($reviewStatus !== 'approved') {
            // `blocked` and `draft` are different states with the same consequence: the source no
            // longer carries approved authority and must not back released evidence.
            $reasonCodes[] = 'SOURCE_REVIEW_NOT_APPROVED';
        }
        if ($target['review_expires_at'] !== null) {
            $expiryMs = self::ms((string) $target['review_expires_at']);
            if ($expiryMs !== null && $expiryMs < $asOfMs) {
                $reasonCodes[] = 'SOURCE_REVIEW_PAST_EXPIRY';
            }
        }

        // The snapshot age has TWO tiers. A caller-supplied window wins over the card's error
        // window; the card's warn window is an earlier, non-fatal signal that never makes the source
        // stale and never opens an incident on its own.
        $errorWindowMs = $input['freshness_window_ms'] ?? $target['error_after_ms'];
        $warnWindowMs = $target['warn_after_ms'];
        if ($errorWindowMs !== null || $warnWindowMs !== null) {
            $latest = $this->db->scalar(
                'SELECT captured_at FROM source_snapshots WHERE monitored_source_id = ?
                 ORDER BY snapshot_sequence DESC LIMIT 1',
                [$input['monitored_source_id']]
            );
            if ($latest === null) {
                // An ERROR window was set but nothing has ever been captured. A never-captured
                // source cannot be "within freshness" — a warn-only threshold alone is not an error.
                if ($errorWindowMs !== null) {
                    $reasonCodes[] = 'NO_SNAPSHOT_FOR_FRESHNESS_WINDOW';
                }
            } else {
                $capturedMs = self::ms((string) $latest);
                if ($capturedMs !== null) {
                    $ageMs = $asOfMs - $capturedMs;
                    if ($errorWindowMs !== null && $ageMs > (int) $errorWindowMs) {
                        $reasonCodes[] = 'SNAPSHOT_OLDER_THAN_FRESHNESS_WINDOW';
                    } elseif ($warnWindowMs !== null && $ageMs > (int) $warnWindowMs) {
                        $warnCodes[] = 'SNAPSHOT_OLDER_THAN_WARN_WINDOW';
                    }
                }
            }
        }

        // A lapsed or absent terms review is an authority gap: the source's lawful-use basis is no
        // longer current, whatever the card's headline status says.
        if ($target['recheck_terms_after_ms'] !== null) {
            $reviewedAt = $this->db->scalar(
                'SELECT reviewed_at FROM source_terms_reviews WHERE source_license_card_id = ?
                 ORDER BY reviewed_at DESC LIMIT 1',
                [(string) $target['source_license_card_id']]
            );
            $reviewedMs = $reviewedAt === null ? null : self::ms((string) $reviewedAt);
            if ($reviewedMs === null || ($asOfMs - $reviewedMs) > (int) $target['recheck_terms_after_ms']) {
                $reasonCodes[] = 'TERMS_RECHECK_OVERDUE';
            }
        }

        $stale = $reasonCodes !== [];

        return $this->recordAudit(
            $profile,
            'SOURCE_FRESHNESS',
            $input['monitored_source_id'],
            'SOURCE_AUTHORITY_AND_FRESHNESS',
            $stale ? 'FAIL' : 'PASS',
            $reasonCodes,
            $stale ? 'EVIDENCE_OR_AUTHORITY_GAP' : 'CONTROL_PASS',
            $stale ? 'S3' : 'S1',
            'KNOWN',
            $stale
                ? 'The source no longer carries current authority for the claims resting on it.'
                : 'The source card, its terms review and its latest capture are all current.',
            $stale ? 'PROVENANCE_OR_IP' : null,
            [
                'stale' => $stale,
                'warn' => $warnCodes !== [],
                'warn_codes' => $warnCodes,
                // Reported for the CONSUMING plane to enforce. The audit stays fail-closed either
                // way: a stale source opens an incident regardless of what the card asks consumers
                // to do about staleness.
                'stale_output_behavior' => (string) $target['stale_output_behavior'],
            ],
        );
    }

    /**
     * Does this recorded change still reconcile with the snapshots it cites?
     *
     * @param array{audit_profile_id: string, source_diff_id: string} $input
     */
    public function runDiffIntegrityAudit(array $input): Outcome
    {
        $profile = $this->activeProfile($input['audit_profile_id'], 'DIFF_INTEGRITY');
        if ($profile instanceof Outcome) {
            return $profile;
        }

        $diff = $this->db->one(
            'SELECT tenant_id, workspace_id, monitored_source_id, before_snapshot_id,
                    after_snapshot_id, diff_digest
             FROM source_diffs WHERE source_diff_id = ?',
            [$input['source_diff_id']]
        );
        if ($diff === null
            || (string) $diff['tenant_id'] !== $profile['tenant_id']
            || (string) $diff['workspace_id'] !== $profile['workspace_id']
        ) {
            return Outcome::refuse('SOURCE_DIFF_NOT_FOUND_OR_OUT_OF_SCOPE', [], 'SCOPE', 'S4');
        }

        $before = $this->snapshotForAudit((string) $diff['before_snapshot_id']);
        $after = $this->snapshotForAudit((string) $diff['after_snapshot_id']);

        $inScope = fn (?array $s): bool => $s !== null
            && (string) $s['tenant_id'] === (string) $diff['tenant_id']
            && (string) $s['workspace_id'] === (string) $diff['workspace_id']
            && (string) $s['monitored_source_id'] === (string) $diff['monitored_source_id'];

        if ($before === null || $after === null) {
            // A cited snapshot cannot be read: the diff's basis is INCOMPLETE, which is a different
            // finding from one that reconciles wrongly. Missing evidence and wrong evidence call for
            // different responses.
            return $this->recordDiffFinding(
                $profile, $input['source_diff_id'], 'REFERENCE_INCOMPLETENESS', 'MISSING', 'S3',
                ['SNAPSHOT_MISSING_OR_SUPERSEDED'],
                'Diff cites a snapshot that cannot be reconciled (missing or superseded).',
            );
        }
        if (!$inScope($before) || !$inScope($after)) {
            // A diff citing a snapshot from another source, tenant or workspace is a CORRUPT
            // citation. Recomputing against the foreign snapshot's hash would RECONCILE and mask
            // it as a pass — so scope is checked before the digest, not after.
            return $this->recordDiffFinding(
                $profile, $input['source_diff_id'], 'INTEGRITY_FAILURE', 'KNOWN', 'S4',
                ['CITED_SNAPSHOT_OUT_OF_SCOPE'],
                'Diff cites a snapshot from a different source, tenant, or workspace.',
            );
        }

        // Re-derived PURELY from the cited snapshots' own content hashes, using the same projection
        // change detection used. A mismatch means the stored digest no longer describes what it
        // cites — a finding about the RECORD.
        $recomputed = Digest::canonicalDigest([
            'beforeSnapshotId' => (string) $diff['before_snapshot_id'],
            'afterSnapshotId' => (string) $diff['after_snapshot_id'],
            'beforeContentHash' => (string) $before['content_hash'],
            'afterContentHash' => (string) $after['content_hash'],
        ]);
        if ($recomputed === (string) $diff['diff_digest']) {
            return $this->recordDiffFinding(
                $profile, $input['source_diff_id'], 'CONTROL_PASS', 'KNOWN', 'S1', [],
                'Diff digest reconciles against its cited before and after snapshots.',
            );
        }

        return $this->recordDiffFinding(
            $profile, $input['source_diff_id'], 'INTEGRITY_FAILURE', 'KNOWN', 'S4',
            ['DIFF_DIGEST_DOES_NOT_RECONCILE'],
            'Stored diff digest does not reconcile against its cited snapshots.',
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, string>|Outcome */
    private function activeProfile(string $auditProfileId, string $expectedCategory): array|Outcome
    {
        $profile = $this->db->one(
            'SELECT tenant_id, workspace_id, status, audit_category FROM audit_profiles
             WHERE audit_profile_id = ?',
            [$auditProfileId]
        );
        if ($profile === null || (string) $profile['status'] !== 'ACTIVE') {
            return Outcome::refuse('AUDIT_PROFILE_NOT_FOUND_OR_INACTIVE', [], 'PROVENANCE');
        }
        // The profile's CATEGORY is authority for what it may conclude. A freshness profile must
        // not back a diff-integrity finding: the finding would cite an audit that never asked the
        // question it answers.
        if ((string) $profile['audit_category'] !== $expectedCategory) {
            return Outcome::refuse('AUDIT_PROFILE_CATEGORY_NOT_' . $expectedCategory, [
                'audit_category' => (string) $profile['audit_category'],
            ], 'POLICY');
        }

        return [
            'audit_profile_id' => $auditProfileId,
            'tenant_id' => (string) $profile['tenant_id'],
            'workspace_id' => (string) $profile['workspace_id'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function snapshotForAudit(string $snapshotId): ?array
    {
        return $this->db->one(
            'SELECT tenant_id, workspace_id, monitored_source_id, content_hash
             FROM source_snapshots WHERE source_snapshot_id = ?',
            [$snapshotId]
        );
    }

    /**
     * @param array<string, string> $profile
     * @param list<string> $reasonCodes
     */
    private function recordDiffFinding(
        array $profile,
        string $sourceDiffId,
        string $findingType,
        string $confidence,
        string $severity,
        array $reasonCodes,
        string $summary,
    ): Outcome {
        return $this->recordAudit(
            $profile,
            'DIFF_INTEGRITY',
            $sourceDiffId,
            'DIFF_DIGEST_RECONCILIATION',
            $findingType === 'CONTROL_PASS' ? 'PASS' : 'FAIL',
            $reasonCodes,
            $findingType,
            $severity,
            $confidence,
            $summary,
            $findingType === 'CONTROL_PASS' ? null : 'INTEGRITY',
            ['reconciles' => $findingType === 'CONTROL_PASS'],
        );
    }

    /**
     * Write the audit run, its check, and its finding — and open an incident when it failed.
     *
     * ONE run, ONE check, ONE finding per call. The three rows are the audit's evidence, and a
     * failing audit that stopped at the finding would leave nobody required to act on it.
     *
     * @param array<string, string> $profile
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $extra
     */
    private function recordAudit(
        array $profile,
        string $auditCategory,
        string $targetObjectId,
        string $checkKey,
        string $decision,
        array $reasonCodes,
        string $findingType,
        string $severity,
        string $confidence,
        string $summary,
        ?string $incidentClass,
        array $extra,
    ): Outcome {
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $profile, $auditCategory, $targetObjectId, $checkKey, $decision, $reasonCodes,
            $findingType, $severity, $confidence, $summary, $incidentClass, $extra, $now
        ): Outcome {
            $auditRunId = Ids::new();
            $tx->insert('audit_runs', [
                'audit_run_id' => $auditRunId,
                'schema_version' => '1',
                'audit_profile_id' => $profile['audit_profile_id'],
                'tenant_id' => $profile['tenant_id'],
                'workspace_id' => $profile['workspace_id'],
                'audit_category' => $auditCategory,
                'target_object_refs' => json_encode([$targetObjectId], JSON_THROW_ON_ERROR),
                'requested_by_actor_type' => 'SERVICE',
                'status' => 'RUNNING',
                'requested_at' => $now,
            ]);

            $checkId = Ids::new();
            $tx->insert('audit_check_records', [
                'audit_check_record_id' => $checkId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'check_key' => $checkKey,
                'decision' => $decision,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'severity_if_failed' => $decision === 'PASS' ? null : $severity,
                'safe_summary' => $summary,
                'evaluated_at' => $now,
            ]);

            $findingId = Ids::new();
            $failed = $decision !== 'PASS';
            $tx->insert('audit_findings', [
                'audit_finding_id' => $findingId,
                'schema_version' => '1',
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'finding_type' => $findingType,
                'finding_status' => 'OPEN',
                'severity' => $severity,
                'confidence_classification' => $confidence,
                'current_reliance_consequence' => $failed ? 'INCIDENT_EVALUATION_REQUIRED' : 'NONE',
                'safe_finding_summary' => $summary,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'incident_evaluation_required' => $failed ? 1 : 0,
                'decision_ledger_record_required' => $failed ? 1 : 0,
                'created_at' => $now,
            ]);

            $tx->run(
                "UPDATE audit_runs SET status = 'COMPLETED', summary = ?, completed_at = ? WHERE audit_run_id = ?",
                [$summary, $now, $auditRunId]
            );

            // A failing audit opens an incident through the CERTIFIED M11 intake. It is not a
            // separate incident plane, and the audit never repairs what it found — an audit that
            // fixed the fault would leave no evidence the fault ever existed.
            $incidentCaseId = null;
            if ($failed && $incidentClass !== null) {
                $incidentCaseId = $this->m11->openIncident(
                    $profile['tenant_id'],
                    $profile['workspace_id'],
                    'M11',
                    $reasonCodes,
                    $severity,
                    $incidentClass,
                    $summary,
                    [$auditCategory . ':' . $targetObjectId],
                )->value()['incident_case_id'];
            }

            return Outcome::ok([
                'audit_run_id' => $auditRunId,
                'audit_check_record_id' => $checkId,
                'audit_finding_id' => $findingId,
                'finding_type' => $findingType,
                'reason_codes' => $reasonCodes,
                'incident_evaluation_required' => $failed,
                'incident_case_id' => $incidentCaseId,
                ...$extra,
            ]);
        });
    }

    /** Milliseconds since the epoch, or null when the instant cannot be read. */
    private static function ms(string $iso): ?int
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? null : $timestamp * 1000;
    }
}
