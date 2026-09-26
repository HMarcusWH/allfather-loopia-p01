<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;

/**
 * P01 change detection — comparing two captured snapshots of one monitored source.
 *
 * It records two things, and the distinction between them is the whole point of this module:
 *
 *   THE CHANGE IS A FACT. The two snapshots' canonical content hashes differ. That is observed,
 *   not inferred.
 *
 *   THE SIGNIFICANCE IS A STRUCTURAL CLASSIFICATION. It says how MUCH of the text changed. It does
 *   NOT say whether the change matters to anyone. Whether a change is relevant to a customer is a
 *   later judgement, made by the review and arbiter planes with a human in the loop — and letting a
 *   distance metric stand in for it is exactly how a measurement becomes a claim nobody made.
 *
 * This is a Class B ANALYZE act: it dispatches to no host and performs no fetch. It derives purely
 * from already-captured, governed snapshots.
 *
 * The derived evidence object is created through M05, never written directly. A
 * `CHANGE_DETECTION_REFERENCE` cites the two snapshots and is never canonical fact on its own.
 */
final class ChangeDetection
{
    /**
     * The structural distance at or above which a change is MATERIAL.
     *
     * A default, not a law: callers may raise or lower it per source. What it can never do is
     * decide relevance — a MATERIAL classification means "much of the page changed", not "the
     * customer should hear about this".
     */
    private const DEFAULT_MATERIAL_THRESHOLD = 0.25;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceSpine $evidence,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, monitored_source_id: string,
     *     before_snapshot_id: string, after_snapshot_id: string,
     *     before_text?: ?string, after_text?: ?string, material_change_threshold?: float
     * } $input
     */
    public function detect(array $input): Outcome
    {
        if ($input['before_snapshot_id'] === $input['after_snapshot_id']) {
            return Outcome::refuse('SNAPSHOTS_IDENTICAL', [], 'POLICY');
        }

        $before = $this->readSnapshot($input['before_snapshot_id']);
        if ($before === null) {
            return Outcome::refuse('BEFORE_SNAPSHOT_NOT_FOUND', [], 'PROVENANCE');
        }
        $after = $this->readSnapshot($input['after_snapshot_id']);
        if ($after === null) {
            return Outcome::refuse('AFTER_SNAPSHOT_NOT_FOUND', [], 'PROVENANCE');
        }

        // Both snapshots must belong to the NAMED monitored source, in the caller's scope. Without
        // this, a diff could pair two unrelated pages and report a change that describes nothing.
        $inScope = (string) $before['monitored_source_id'] === $input['monitored_source_id']
            && (string) $after['monitored_source_id'] === $input['monitored_source_id']
            && (string) $before['tenant_id'] === $input['tenant_id']
            && (string) $after['tenant_id'] === $input['tenant_id']
            && (string) $before['workspace_id'] === $input['workspace_id']
            && (string) $after['workspace_id'] === $input['workspace_id'];
        if (!$inScope) {
            return Outcome::refuse('SNAPSHOT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Forward-only: a diff always goes older to newer. A reversed pair would report the
        // inverse change as though it had happened.
        if ((int) $before['snapshot_sequence'] >= (int) $after['snapshot_sequence']) {
            return Outcome::refuse('SNAPSHOTS_NOT_FORWARD_ORDERED', [], 'POLICY');
        }

        $threshold = $input['material_change_threshold'] ?? self::DEFAULT_MATERIAL_THRESHOLD;
        if ((!is_int($threshold) && !is_float($threshold)) || !is_finite((float) $threshold) || $threshold < 0 || $threshold > 1) {
            return Outcome::refuse('INVALID_MATERIAL_CHANGE_THRESHOLD', [], 'POLICY');
        }
        $beforeProof = TextProvenance::read($before['text_provenance_json'], (string) $before['content_hash']);
        $afterProof = TextProvenance::read($after['text_provenance_json'], (string) $after['content_hash']);
        if (($before['text_provenance_json'] !== null && $beforeProof === null)
            || ($after['text_provenance_json'] !== null && $afterProof === null)) {
            return Outcome::refuse('SNAPSHOT_TEXT_PROVENANCE_INVALID', [], 'PROVENANCE');
        }
        foreach ([['before_text', $before, $beforeProof], ['after_text', $after, $afterProof]] as [$key, $snapshot, $proof]) {
            if (!array_key_exists($key, $input) || $input[$key] === null) { continue; }
            if (!is_string($input[$key])) { return Outcome::refuse('SNAPSHOT_TEXT_MISMATCH', [], 'PROVENANCE'); }
            if ($proof === null) { return Outcome::refuse('SNAPSHOT_TEXT_PROVENANCE_UNAVAILABLE', [], 'PROVENANCE'); }
            if (Digest::canonicalDigest(TextProvenance::capture((string) $snapshot['content_hash'], $input[$key])) !== Digest::canonicalDigest($proof)) {
                return Outcome::refuse('SNAPSHOT_TEXT_MISMATCH', [], 'PROVENANCE');
            }
        }
        $changeDetected = (string) $before['content_hash'] !== (string) $after['content_hash'];
        $classified = TextProvenance::classify((string) $before['content_hash'], (string) $after['content_hash'], $beforeProof, $afterProof, (float) $threshold);
        $diffDigest = TextProvenance::digest(['beforeSnapshotId' => $input['before_snapshot_id'],
            'afterSnapshotId' => $input['after_snapshot_id'], 'beforeContentHash' => (string) $before['content_hash'],
            'afterContentHash' => (string) $after['content_hash'], 'before' => $beforeProof, 'after' => $afterProof,
            'threshold' => $threshold, ...$classified]);
        $existing = $this->db->one('SELECT source_diff_id, change_significance, diff_digest, classifier_version
            FROM source_diffs WHERE monitored_source_id = ? AND before_snapshot_id = ? AND after_snapshot_id = ?',
            [$input['monitored_source_id'], $input['before_snapshot_id'], $input['after_snapshot_id']]);
        if ($existing !== null) {
            if ($existing['classifier_version'] !== TextProvenance::CLASSIFIER_VERSION || $existing['diff_digest'] !== $diffDigest) {
                return Outcome::refuse('DIFF_INPUT_CONFLICT', [], 'PROVENANCE');
            }
            return Outcome::ok(['outcome' => 'ALREADY_DETECTED', 'source_diff_id' => (string) $existing['source_diff_id'],
                'change_significance' => (string) $existing['change_significance']]);
        }

        $source = $this->db->one(
            'SELECT ms.source_ref_id, sr.lawful_or_permitted_use_scope, sr.content_sensitivity_class
             FROM monitored_sources ms
             JOIN source_references sr ON sr.source_ref_id = ms.source_ref_id
             WHERE ms.monitored_source_id = ?',
            [$input['monitored_source_id']]
        );
        if ($source === null) {
            return Outcome::refuse('MONITORED_SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }


        return $this->db->transaction(function (Database $tx) use (
            $input, $source, $diffDigest, $classified, $changeDetected, $threshold
        ): Outcome {
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'source_ref_id' => (string) $source['source_ref_id'],
                'evidence_type' => 'CHANGE_DETECTION_REFERENCE',
                // A SAFE reference, never the content: the diff is identified by its digest, and
                // the snapshots it cites are where the bytes actually live.
                'captured_content_reference_safe' => sprintf(
                    'diff://%s/%s',
                    $input['monitored_source_id'],
                    substr($diffDigest, 0, 16),
                ),
                'content_hash' => $diffDigest,
                'capture_method' => 'DERIVED_DIFF',
                'permitted_use_scope' => $source['lawful_or_permitted_use_scope'] ?? 'P01_PUBLIC_SOURCE',
                'sensitivity_class' => (string) $source['content_sensitivity_class'],
            ]);
            if ($captured->isRefusal()) {
                // The M05 refusal is the answer, and its SPECIFIC reason is carried through rather
                // than flattened: an inadmissible source and a scope mismatch are different events.
                return Outcome::refuse('CHANGE_EVIDENCE_REJECTED', [
                    'evidence_reason_code' => $captured->reasonCode(),
                ], 'EVIDENCE');
            }

            $sourceDiffId = Ids::new();
            $tx->insert('source_diffs', [
                'source_diff_id' => $sourceDiffId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'monitored_source_id' => $input['monitored_source_id'],
                'before_snapshot_id' => $input['before_snapshot_id'],
                'after_snapshot_id' => $input['after_snapshot_id'],
                'change_evidence_id' => $captured->value()['evidence_id'],
                'diff_digest' => $diffDigest,
                'hash_algorithm' => 'sha256',
                'change_significance' => $classified['significance'],
                'classifier_version' => TextProvenance::CLASSIFIER_VERSION,
                'material_change_threshold' => $threshold,
                // Derived COUNTS only. The raw text of neither capture is ever persisted here — the
                // snapshots are the record of the bytes, and duplicating them into a summary would
                // put source content somewhere the licence checks do not reach.
                'diff_summary_safe' => $classified['summary'],
                'detected_at' => $this->clock->now(),
            ]);

            return Outcome::ok([
                'outcome' => 'DETECTED',
                'source_diff_id' => $sourceDiffId,
                'change_evidence_id' => $captured->value()['evidence_id'],
                'change_significance' => $classified['significance'],
                'change_detected' => $changeDetected,
                'diff_digest' => $diffDigest,
            ]);
        });
    }

    /**
     * Deterministic structural distance over normalized line sets — Jaccard distance in [0, 1].
     *
     * Pure and dependency-free, so the same inputs always yield the same band. That is what makes a
     * significance classification REPLAYABLE: an audit re-running this months later must get the
     * same answer, or the classification was never evidence of anything.
     */
    public static function structuralChangeRatio(string $beforeText, string $afterText): float
    {
        return self::jaccardDistance(self::normalizeLines($beforeText), self::normalizeLines($afterText));
    }

    /** @return list<string> */
    private static function normalizeLines(string $text): array
    {
        $lines = array_filter(
            array_map(trim(...), explode("\n", $text)),
            static fn (string $line): bool => $line !== '',
        );

        return array_values(array_unique($lines));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private static function jaccardDistance(array $before, array $after): float
    {
        if ($before === [] && $after === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($before, $after));
        $union = count($before) + count($after) - $intersection;
        if ($union === 0) {
            return 0.0;
        }

        return 1.0 - ($intersection / $union);
    }

    /** @return array<string, mixed>|null */
    private function readSnapshot(string $snapshotId): ?array
    {
        return $this->db->one(
            'SELECT monitored_source_id, tenant_id, workspace_id, content_hash, snapshot_sequence, text_provenance_json
             FROM source_snapshots WHERE source_snapshot_id = ?',
            [$snapshotId]
        );
    }
}
