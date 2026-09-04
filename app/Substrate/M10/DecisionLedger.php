<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M10 — the decision ledger.
 *
 * Ported from `packages/allfather-control-substrate/src/m10/ledger.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * This is the hash-chained append-only record of every governed decision. Four properties are
 * load-bearing and are preserved exactly:
 *
 *  1. **The canonical-basis gate (M10-DEC-006).** A record type with mandatory basis roles may not
 *     be appended unless every required role is present AND each is a `VERIFIED_REFERENCE`. A role
 *     present but unverified does NOT satisfy it — otherwise a positive material decision (a
 *     release, a completed action) could be recorded on a basis explicitly never verified.
 *  2. **The release-path capability.** `INTERNAL_INFORMATIONAL_RELEASE` — the record the console
 *     and the release-readiness projection treat as PROOF of release — can only be minted through
 *     the validated release path, never by a caller fabricating it directly.
 *  3. **The chain.** `record_payload_digest` commits to the record fields AND its sorted reference
 *     set, so a record cryptographically binds its exact upstream basis; `chain_hash` chains that
 *     onto the previous head.
 *  4. **Serialized head advance.** The head moves by a compare-and-swap on the observed sequence
 *     number. A racing or stale append flips no rows and is REJECTED — forks are prevented by the
 *     database, not by application timing.
 */
final class DecisionLedger
{
    /**
     * The release-path capability token.
     *
     * Upstream uses a module-private `Symbol`, which PHP has no equivalent for. The nearest
     * faithful construction is a private-constructor class: only code that can reach
     * `ReleasePathCapability::grant()` can mint one, and that method is called from exactly one
     * place — `appendInternalReleaseDecisionRecord`. A caller cannot forge the token by passing a
     * matching string, because there is no matching string.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    // ---------------------------------------------------------------- stream lifecycle

    /**
     * Create an ACTIVE ledger stream for a scope.
     *
     * The genesis anchor is the deterministic predecessor of record 1, so a stream's chain is
     * verifiable from its own identity without a stored magic value.
     */
    public function createStream(
        string $tenantId,
        string $workspaceId,
        string $environmentClass,
        ?string $streamKey = null,
    ): Outcome {
        $key = $streamKey ?? "m10.{$tenantId}.{$workspaceId}";
        $existing = $this->db->one(
            'SELECT decision_ledger_stream_id FROM decision_ledger_streams
             WHERE tenant_id = ? AND workspace_id = ? AND status = ?',
            [$tenantId, $workspaceId, 'ACTIVE']
        );
        if ($existing !== null) {
            // At most one ACTIVE stream per scope — competing heads would be competing histories.
            return Outcome::refuse('LEDGER_ACTIVE_STREAM_ALREADY_EXISTS', [
                'decision_ledger_stream_id' => (string) $existing['decision_ledger_stream_id'],
            ]);
        }

        $streamId = Ids::new();
        $genesis = Digest::canonicalDigest([
            'genesis' => true,
            'ledgerStreamId' => $streamId,
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
        ]);
        $now = $this->clock->now();

        $this->db->insert('decision_ledger_streams', [
            'decision_ledger_stream_id' => $streamId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'environment_class' => $environmentClass,
            'stream_key' => $key,
            'genesis_or_initial_anchor_hash' => $genesis,
            'hash_algorithm' => 'sha256',
            'current_head_record_id' => null,
            'current_head_chain_hash' => $genesis,
            'head_sequence_number' => 0,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'activated_at' => $now,
        ]);

        return Outcome::ok(['decision_ledger_stream_id' => $streamId, 'genesis_hash' => $genesis]);
    }

    public function ensureActiveStream(string $tenantId, string $workspaceId, string $environmentClass = 'TEST'): string
    {
        $existing = $this->db->one(
            'SELECT decision_ledger_stream_id FROM decision_ledger_streams
             WHERE tenant_id = ? AND workspace_id = ? AND status = ?',
            [$tenantId, $workspaceId, 'ACTIVE']
        );
        if ($existing !== null) {
            return (string) $existing['decision_ledger_stream_id'];
        }

        return (string) $this->createStream($tenantId, $workspaceId, $environmentClass)->value()['decision_ledger_stream_id'];
    }

    // ---------------------------------------------------------------- digests

    /**
     * Deterministic reference ordering so append and verify hash the same set regardless of the
     * order the database returns rows in.
     *
     * The sort key folds in EVERY field, not just role/owner/type/id: two references that differ
     * only by digest or verification status must still order deterministically, or an intact
     * ledger could fail verification purely because of row order.
     *
     * @param list<array<string, mixed>> $references
     * @return list<array<string, mixed>>
     */
    public static function sortReferences(array $references): array
    {
        $key = static fn (array $r): string => implode('|', [
            (string) $r['reference_role'],
            (string) $r['owner_module_id'],
            (string) $r['input_object_type'],
            (string) $r['input_object_id'],
            (string) ($r['input_object_version_or_digest'] ?? ''),
            (string) $r['verification_status_at_record_time'],
        ]);
        usort($references, static fn (array $a, array $b): int => strcmp($key($a), $key($b)));

        return array_values($references);
    }

    /**
     * `record_payload_digest = HASH(canonical(record fields + bound reference digests))`.
     *
     * Binding the reference digests means the record cryptographically commits to its exact
     * upstream basis: changing which evidence a release rested on changes the record's identity.
     *
     * @param array<string, mixed>       $record
     * @param list<array<string, mixed>> $references
     */
    public static function computeRecordPayloadDigest(array $record, array $references): string
    {
        return Digest::canonicalDigest([
            'recordType' => $record['record_type'],
            'materialityClass' => $record['materiality_class'],
            'primaryOutcomeStatus' => $record['primary_outcome_status'],
            'missionId' => $record['mission_id'],
            'proposalId' => $record['proposal_id'],
            'proposalSubmissionDigest' => $record['proposal_submission_digest'],
            'correlationId' => $record['correlation_id'],
            'safeDecisionSummary' => $record['safe_decision_summary'],
            'reasonCodes' => array_values($record['reason_codes']),
            'uncertaintyAndLimitationSummary' => $record['uncertainty_and_limitation_summary'],
            'severity' => $record['severity'],
            'incidentEvaluationRequired' => $record['incident_evaluation_required'],
            'supersedesOrCorrectsDecisionRecordId' => $record['supersedes_or_corrects_decision_record_id'],
            'currentRelianceStatus' => $record['current_reliance_status'],
            'recordSequenceNumber' => $record['record_sequence_number'],
            'references' => array_map(
                static fn (array $r): array => [
                    'ownerModuleId' => $r['owner_module_id'],
                    'inputObjectType' => $r['input_object_type'],
                    'inputObjectId' => $r['input_object_id'],
                    'inputObjectVersionOrDigest' => $r['input_object_version_or_digest'],
                    'referenceRole' => $r['reference_role'],
                    'verificationStatusAtRecordTime' => $r['verification_status_at_record_time'],
                ],
                self::sortReferences($references),
            ),
        ]);
    }

    /** `chain_hash = HASH(previous_chain_hash || 0x00 || canonical(payload digest, stream, sequence))`. */
    public static function computeChainHash(
        string $previousChainHash,
        string $recordPayloadDigest,
        string $ledgerStreamId,
        int $recordSequenceNumber,
    ): string {
        return Digest::chainDigest($previousChainHash, [
            'recordPayloadDigest' => $recordPayloadDigest,
            'ledgerStreamId' => $ledgerStreamId,
            'recordSequenceNumber' => $recordSequenceNumber,
        ]);
    }

    // ---------------------------------------------------------------- append

    /**
     * Append a decision record.
     *
     * @param list<array<string, mixed>> $inputReferences each: owner_module_id, input_object_type,
     *        input_object_id, reference_role, and optionally input_object_version_or_digest and
     *        verification_status_at_record_time
     * @param array<string, mixed>       $fields
     */
    public function append(
        string $ledgerStreamId,
        string $recordType,
        string $materialityClass,
        string $primaryOutcomeStatus,
        string $safeDecisionSummary,
        array $inputReferences,
        array $fields = [],
        ?ReleasePathCapability $releasePathCapability = null,
    ): Outcome {
        // Wrapper-owned record-type guard: the release basis can only be minted by the validated
        // release path, never by a direct caller fabricating the record the console and the
        // release-readiness projection treat as proof of release.
        if ($recordType === 'INTERNAL_INFORMATIONAL_RELEASE' && $releasePathCapability === null) {
            return Outcome::refuse('LEDGER_RELEASE_RECORD_REQUIRES_RELEASE_PATH');
        }

        // Canonical-basis gate, evaluated BEFORE the transaction so a rejection moves no head.
        if ($inputReferences === []) {
            return Outcome::refuse('LEDGER_MANDATORY_INPUT_REFERENCE_MISSING', ['missing' => ['ANY']]);
        }
        $missing = self::missingRequiredRoles($recordType, $inputReferences);
        if ($missing !== []) {
            return Outcome::refuse('LEDGER_MANDATORY_INPUT_REFERENCE_MISSING', ['missing' => $missing]);
        }

        return $this->db->transaction(function (Database $tx) use (
            $ledgerStreamId, $recordType, $materialityClass, $primaryOutcomeStatus,
            $safeDecisionSummary, $inputReferences, $fields
        ): Outcome {
            $stream = $tx->one(
                'SELECT tenant_id, workspace_id, status, current_head_record_id, current_head_chain_hash,
                        head_sequence_number
                 FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
                [$ledgerStreamId]
            );
            if ($stream === null) {
                return Outcome::refuse('LEDGER_STREAM_NOT_FOUND');
            }
            if ((string) $stream['status'] !== 'ACTIVE') {
                return Outcome::refuse('LEDGER_STREAM_NOT_ACTIVE');
            }

            $previousChainHash = (string) $stream['current_head_chain_hash'];
            $expectedHead = $fields['expected_head_chain_hash'] ?? null;
            if ($expectedHead !== null && $expectedHead !== $previousChainHash) {
                return Outcome::refuse('LEDGER_HEAD_HASH_MISMATCH');
            }

            $previousSequence = (int) $stream['head_sequence_number'];
            $sequence = $previousSequence + 1;
            $reliance = (string) ($fields['current_reliance_status'] ?? Vocabulary::CURRENT_RELIANCE);
            $reasonCodes = array_values($fields['reason_codes'] ?? []);

            $normalized = array_map(static fn (array $r): array => [
                'owner_module_id' => (string) $r['owner_module_id'],
                'input_object_type' => (string) $r['input_object_type'],
                'input_object_id' => (string) $r['input_object_id'],
                'input_object_version_or_digest' => $r['input_object_version_or_digest'] ?? null,
                'reference_role' => (string) $r['reference_role'],
                'verification_status_at_record_time' => (string) ($r['verification_status_at_record_time'] ?? 'PRESENT_UNVERIFIED'),
            ], $inputReferences);

            $recordFields = [
                'record_type' => $recordType,
                'materiality_class' => $materialityClass,
                'primary_outcome_status' => $primaryOutcomeStatus,
                'mission_id' => $fields['mission_id'] ?? null,
                'proposal_id' => $fields['proposal_id'] ?? null,
                'proposal_submission_digest' => $fields['proposal_submission_digest'] ?? null,
                'correlation_id' => $fields['correlation_id'] ?? null,
                'safe_decision_summary' => $safeDecisionSummary,
                'reason_codes' => $reasonCodes,
                'uncertainty_and_limitation_summary' => $fields['uncertainty_and_limitation_summary'] ?? null,
                'severity' => $fields['severity'] ?? null,
                'incident_evaluation_required' => (bool) ($fields['incident_evaluation_required'] ?? false),
                'supersedes_or_corrects_decision_record_id' => $fields['supersedes_or_corrects_decision_record_id'] ?? null,
                'current_reliance_status' => $reliance,
                'record_sequence_number' => $sequence,
            ];

            $payloadDigest = self::computeRecordPayloadDigest($recordFields, $normalized);
            $chainHash = self::computeChainHash($previousChainHash, $payloadDigest, $ledgerStreamId, $sequence);

            $recordId = Ids::new();
            $now = $this->clock->now();

            $tx->insert('decision_records', [
                'decision_record_id' => $recordId,
                'schema_version' => '1',
                'ledger_stream_id' => $ledgerStreamId,
                'tenant_id' => $stream['tenant_id'],
                'workspace_id' => $stream['workspace_id'],
                'record_sequence_number' => $sequence,
                'record_type' => $recordType,
                'materiality_class' => $materialityClass,
                'primary_outcome_status' => $primaryOutcomeStatus,
                'mission_id' => $recordFields['mission_id'],
                'proposal_id' => $recordFields['proposal_id'],
                'proposal_submission_digest' => $recordFields['proposal_submission_digest'],
                'correlation_id' => $recordFields['correlation_id'],
                'safe_decision_summary' => $safeDecisionSummary,
                'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                'uncertainty_and_limitation_summary' => $recordFields['uncertainty_and_limitation_summary'],
                'severity' => $recordFields['severity'],
                'incident_evaluation_required' => $recordFields['incident_evaluation_required'] ? 1 : 0,
                'supersedes_or_corrects_decision_record_id' => $recordFields['supersedes_or_corrects_decision_record_id'],
                'current_reliance_status' => $reliance,
                'record_payload_digest' => $payloadDigest,
                'hash_algorithm' => 'sha256',
                'recorded_by_service_identity_id' => (string) ($fields['recorded_by_service_identity_id'] ?? $recordId),
                'recorded_at' => $now,
            ]);

            foreach ($normalized as $reference) {
                $tx->insert('decision_input_references', [
                    'decision_input_reference_id' => Ids::new(),
                    'schema_version' => '1',
                    'decision_record_id' => $recordId,
                    'owner_module_id' => $reference['owner_module_id'],
                    'input_object_type' => $reference['input_object_type'],
                    'input_object_id' => $reference['input_object_id'],
                    'input_object_version_or_digest' => $reference['input_object_version_or_digest'],
                    'reference_role' => $reference['reference_role'],
                    'verification_status_at_record_time' => $reference['verification_status_at_record_time'],
                    'created_at' => $now,
                ]);
            }

            $linkId = Ids::new();
            $tx->insert('ledger_integrity_links', [
                'ledger_integrity_link_id' => $linkId,
                'schema_version' => '1',
                'ledger_stream_id' => $ledgerStreamId,
                'decision_record_id' => $recordId,
                'record_sequence_number' => $sequence,
                'previous_decision_record_id' => $stream['current_head_record_id'],
                'previous_chain_hash' => $previousChainHash,
                'record_payload_digest' => $payloadDigest,
                'chain_hash' => $chainHash,
                'hash_algorithm' => 'sha256',
                'created_at' => $now,
            ]);

            // Atomic compare-and-swap on the serialized head: only the writer that OBSERVED this
            // sequence number advances it. A racing or stale append flips no rows, so a fork is
            // prevented by the database rather than by application timing.
            $advanced = $tx->run(
                'UPDATE decision_ledger_streams
                 SET current_head_record_id = ?, current_head_chain_hash = ?, head_sequence_number = ?
                 WHERE decision_ledger_stream_id = ? AND head_sequence_number = ?',
                [$recordId, $chainHash, $sequence, $ledgerStreamId, $previousSequence]
            );
            if ($advanced !== 1) {
                // Returning a refusal inside the transaction would COMMIT the record rows without
                // advancing the head. Throwing rolls the whole append back, which is the only
                // correct outcome for a lost race.
                throw new LedgerSequenceConflict('LEDGER_SEQUENCE_CONFLICT');
            }

            return Outcome::ok([
                'decision_record_id' => $recordId,
                'ledger_integrity_link_id' => $linkId,
                'record_sequence_number' => $sequence,
                'record_payload_digest' => $payloadDigest,
                'chain_hash' => $chainHash,
            ]);
        });
    }

    /**
     * Append the release record. This is the ONLY path that may mint an
     * `INTERNAL_INFORMATIONAL_RELEASE`, and it is what makes that record trustworthy as proof.
     *
     * @param list<array<string, mixed>> $inputReferences
     * @param array<string, mixed>       $fields
     */
    public function appendInternalRelease(
        string $ledgerStreamId,
        string $safeDecisionSummary,
        array $inputReferences,
        array $fields = [],
    ): Outcome {
        return $this->append(
            $ledgerStreamId,
            'INTERNAL_INFORMATIONAL_RELEASE',
            'GOVERNANCE_MATERIAL',
            'RELEASED_INTERNAL',
            $safeDecisionSummary,
            $inputReferences,
            $fields,
            ReleasePathCapability::grant(),
        );
    }

    // ---------------------------------------------------------------- reliance

    /**
     * The current reliance status of a record.
     *
     * **Derived, not stored.** The latest supersession row wins; absent any, the record's own
     * recorded status applies. This is what makes the ledger genuinely append-only: superseding a
     * release writes a NEW row and never edits the original, so the original's payload digest —
     * which commits to its recorded reliance — stays valid forever and the record stays verifiable
     * as history.
     *
     * The ordering is by the SUCCESSOR's monotonic ledger sequence, not by `created_at`: two
     * corrections appended in the same millisecond share a timestamp, leaving `ORDER BY created_at`
     * nondeterministic about which is latest. `record_sequence_number` is unique and strictly
     * increasing within a stream, so the most recent successor is unambiguous. (This is also the
     * total-order discipline upstream records as open debt under TF7-09; the ported selectors carry
     * it rather than inheriting the ambiguity.)
     *
     * `null` means the record does not exist.
     */
    public function currentReliance(string $decisionRecordId): ?string
    {
        $superseded = $this->db->one(
            'SELECT s.new_current_reliance_status AS reliance
             FROM decision_supersession_records s
             JOIN decision_records dr ON dr.decision_record_id = s.successor_decision_record_id
             WHERE s.original_decision_record_id = ?
             ORDER BY dr.record_sequence_number DESC
             LIMIT 1',
            [$decisionRecordId]
        );
        if ($superseded !== null) {
            return (string) $superseded['reliance'];
        }

        $record = $this->db->one(
            'SELECT current_reliance_status FROM decision_records WHERE decision_record_id = ?',
            [$decisionRecordId]
        );

        return $record === null ? null : (string) $record['current_reliance_status'];
    }

    public function isCurrentlyRelied(string $decisionRecordId): bool
    {
        return $this->currentReliance($decisionRecordId) === Vocabulary::CURRENT_RELIANCE;
    }

    /**
     * Supersede or correct a record: withdraw current reliance WITHOUT deleting or editing
     * anything.
     *
     * This is the rollback primitive, and the shape matters. A correction record is APPENDED to
     * the same stream (so the chain covers the correction too), a supersession row records the
     * original's new reliance, and the original stays byte-intact. `DELETE FROM decision_records`
     * would destroy the very evidence that makes a rollback provable.
     *
     * The correction record itself stays ACTIVE: `$newRelianceStatus` describes the ORIGINAL's new
     * reliance and lives on the supersession row only. Storing it on the successor would make
     * `currentReliance(successor)` report the correction as already superseded, so consumers would
     * ignore the very record carrying the correction.
     *
     * @param string $changeType one of SUPERSEDED_BY_NEW_DECISION, CORRECTED_BY_APPEND_ONLY_RECORD,
     *        INVALIDATED_FOR_CURRENT_RELIANCE, ANNOTATED_WITH_LATER_FINDING, REPLAY_COMPARISON_ONLY
     * @param string $newRelianceStatus one of HISTORICAL_ONLY, SUPERSEDED,
     *        INVALIDATED_FOR_CURRENT_RELIANCE, UNDER_REVIEW — never ACTIVE_AS_RECORDED, because a
     *        supersession cannot restore reliance
     */
    public function supersede(
        string $originalDecisionRecordId,
        string $changeType,
        string $authoritySourceModuleId,
        string $authoritySourceObjectId,
        string $safeChangeSummary,
        string $newRelianceStatus = 'SUPERSEDED',
        ?string $correlationId = null,
    ): Outcome {
        $original = $this->db->one(
            'SELECT ledger_stream_id, record_payload_digest, mission_id, proposal_id, proposal_submission_digest
             FROM decision_records WHERE decision_record_id = ?',
            [$originalDecisionRecordId]
        );
        if ($original === null) {
            return Outcome::refuse('DECISION_RECORD_NOT_FOUND');
        }

        return $this->db->transaction(function (Database $tx) use (
            $original, $originalDecisionRecordId, $changeType, $authoritySourceModuleId,
            $authoritySourceObjectId, $safeChangeSummary, $newRelianceStatus, $correlationId
        ): Outcome {
            $appended = $this->append(
                (string) $original['ledger_stream_id'],
                'DECISION_CORRECTION_OR_INVALIDATION',
                'GOVERNANCE_MATERIAL',
                'CORRECTED_OR_INVALIDATED',
                $safeChangeSummary,
                [
                    [
                        'owner_module_id' => 'M10',
                        'input_object_type' => 'decision_record',
                        'input_object_id' => $originalDecisionRecordId,
                        'input_object_version_or_digest' => (string) $original['record_payload_digest'],
                        'reference_role' => 'OTHER',
                        'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                    ],
                    [
                        'owner_module_id' => $authoritySourceModuleId,
                        'input_object_type' => 'authority_source',
                        'input_object_id' => $authoritySourceObjectId,
                        'reference_role' => 'AUTHORITY_BASIS',
                        'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                    ],
                ],
                [
                    'reason_codes' => ["M10_{$changeType}"],
                    'mission_id' => $original['mission_id'],
                    'proposal_id' => $original['proposal_id'],
                    'proposal_submission_digest' => $original['proposal_submission_digest'],
                    'correlation_id' => $correlationId,
                    'supersedes_or_corrects_decision_record_id' => $originalDecisionRecordId,
                ],
            );
            if (!$appended->isOk()) {
                return $appended;
            }

            $supersessionId = Ids::new();
            $tx->insert('decision_supersession_records', [
                'decision_supersession_record_id' => $supersessionId,
                'schema_version' => '1',
                'original_decision_record_id' => $originalDecisionRecordId,
                'successor_decision_record_id' => $appended->value()['decision_record_id'],
                'change_type' => $changeType,
                'authority_source_module_id' => $authoritySourceModuleId,
                'authority_source_object_id' => $authoritySourceObjectId,
                'safe_change_summary' => $safeChangeSummary,
                'new_current_reliance_status' => $newRelianceStatus,
                // CHECK-pinned to 1: the original ALWAYS remains queryable as history.
                'original_remains_historical' => 1,
                'created_at' => $this->clock->now(),
            ]);

            return Outcome::ok([
                'decision_supersession_record_id' => $supersessionId,
                'successor_decision_record_id' => $appended->value()['decision_record_id'],
                'original_decision_record_id' => $originalDecisionRecordId,
                'new_current_reliance_status' => $newRelianceStatus,
            ]);
        });
    }

    // ---------------------------------------------------------------- verification

    /**
     * Verify a stream's chain from genesis.
     *
     * Recomputes every record's payload digest from its stored fields and references, and every
     * chain hash from its predecessor. A tampered field, a swapped reference, a reordered
     * sequence, or a spliced-out record all surface here — which is the whole reason the digest
     * commits to the reference set rather than only to the record's own columns.
     *
     * @return array{status: string, records_verified: int, failures: list<array<string, mixed>>}
     */
    public function verifyStream(string $ledgerStreamId): array
    {
        $stream = $this->db->one(
            'SELECT genesis_or_initial_anchor_hash, current_head_chain_hash, head_sequence_number
             FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
            [$ledgerStreamId]
        );
        if ($stream === null) {
            return ['status' => 'INCOMPLETE', 'records_verified' => 0, 'failures' => [
                ['reason_code' => 'LEDGER_STREAM_NOT_FOUND'],
            ]];
        }

        $records = $this->db->all(
            'SELECT * FROM decision_records WHERE ledger_stream_id = ? ORDER BY record_sequence_number ASC',
            [$ledgerStreamId]
        );

        $previousChainHash = (string) $stream['genesis_or_initial_anchor_hash'];
        $failures = [];
        $verified = 0;
        $expectedSequence = 1;

        foreach ($records as $record) {
            $recordId = (string) $record['decision_record_id'];
            $sequence = (int) $record['record_sequence_number'];

            if ($sequence !== $expectedSequence) {
                $failures[] = [
                    'decision_record_id' => $recordId,
                    'reason_code' => 'LEDGER_SEQUENCE_GAP',
                    'expected' => $expectedSequence,
                    'actual' => $sequence,
                ];
            }
            $expectedSequence = $sequence + 1;

            $references = $this->db->all(
                'SELECT owner_module_id, input_object_type, input_object_id,
                        input_object_version_or_digest, reference_role, verification_status_at_record_time
                 FROM decision_input_references WHERE decision_record_id = ?',
                [$recordId]
            );

            $recomputedPayload = self::computeRecordPayloadDigest([
                'record_type' => (string) $record['record_type'],
                'materiality_class' => (string) $record['materiality_class'],
                'primary_outcome_status' => (string) $record['primary_outcome_status'],
                'mission_id' => $record['mission_id'],
                'proposal_id' => $record['proposal_id'],
                'proposal_submission_digest' => $record['proposal_submission_digest'],
                'correlation_id' => $record['correlation_id'],
                'safe_decision_summary' => (string) $record['safe_decision_summary'],
                'reason_codes' => json_decode((string) $record['reason_codes'], true, 512, JSON_THROW_ON_ERROR),
                'uncertainty_and_limitation_summary' => $record['uncertainty_and_limitation_summary'],
                'severity' => $record['severity'],
                'incident_evaluation_required' => (bool) $record['incident_evaluation_required'],
                'supersedes_or_corrects_decision_record_id' => $record['supersedes_or_corrects_decision_record_id'],
                // Reliance is part of the digest AND is never edited in place: supersession
                // writes a separate row and `currentReliance()` derives from it. So a superseded
                // record still recomputes to its ORIGINAL digest and stays verifiable as history.
                'current_reliance_status' => (string) $record['current_reliance_status'],
                'record_sequence_number' => $sequence,
            ], $references);

            if (!hash_equals((string) $record['record_payload_digest'], $recomputedPayload)) {
                $failures[] = [
                    'decision_record_id' => $recordId,
                    'reason_code' => 'LEDGER_PAYLOAD_DIGEST_MISMATCH',
                ];
            }

            $link = $this->db->one(
                'SELECT previous_chain_hash, chain_hash FROM ledger_integrity_links WHERE decision_record_id = ?',
                [$recordId]
            );
            if ($link === null) {
                $failures[] = ['decision_record_id' => $recordId, 'reason_code' => 'LEDGER_INTEGRITY_LINK_MISSING'];
                continue;
            }
            if ((string) $link['previous_chain_hash'] !== $previousChainHash) {
                $failures[] = ['decision_record_id' => $recordId, 'reason_code' => 'LEDGER_CHAIN_BREAK'];
            }

            $recomputedChain = self::computeChainHash(
                (string) $link['previous_chain_hash'],
                (string) $record['record_payload_digest'],
                $ledgerStreamId,
                $sequence,
            );
            if (!hash_equals((string) $link['chain_hash'], $recomputedChain)) {
                $failures[] = ['decision_record_id' => $recordId, 'reason_code' => 'LEDGER_CHAIN_HASH_MISMATCH'];
            }

            $previousChainHash = (string) $link['chain_hash'];
            $verified++;
        }

        if ($records !== [] && $previousChainHash !== (string) $stream['current_head_chain_hash']) {
            $failures[] = ['reason_code' => 'LEDGER_HEAD_HASH_MISMATCH'];
        }

        return [
            'status' => $failures === [] ? 'PASS' : 'FAIL',
            'records_verified' => $verified,
            'failures' => $failures,
        ];
    }

    /**
     * A mandatory basis role is satisfied ONLY by a VERIFIED_REFERENCE.
     *
     * A required role present with REQUIRED_BUT_MISSING / RESTRICTED_REFERENCE /
     * PRESENT_UNVERIFIED does NOT satisfy it — otherwise a caller could record a positive material
     * decision whose mandatory upstream basis was explicitly never verified.
     *
     * @param list<array<string, mixed>> $references
     * @return list<string>
     */
    private static function missingRequiredRoles(string $recordType, array $references): array
    {
        $required = Vocabulary::REQUIRED_REFERENCE_ROLES[$recordType] ?? [];
        if ($required === []) {
            return [];
        }

        $verified = [];
        foreach ($references as $reference) {
            $status = (string) ($reference['verification_status_at_record_time'] ?? 'PRESENT_UNVERIFIED');
            if ($status === Vocabulary::VERIFIED_REFERENCE) {
                $verified[(string) $reference['reference_role']] = true;
            }
        }

        return array_values(array_filter($required, static fn (string $role): bool => !isset($verified[$role])));
    }
}
