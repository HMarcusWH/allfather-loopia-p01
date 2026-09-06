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
 *  2. **M10 owns release (INV-012).** `INTERNAL_INFORMATIONAL_RELEASE` — the record the console
 *     and the release-readiness projection treat as PROOF of release — is minted in exactly one
 *     place, `appendInternalReleaseDecisionRecord`, which loads and validates the real governed
 *     records itself and then performs the `SUBMITTED -> RELEASED` mutation in the same
 *     transaction. The public `append()` refuses that record type outright: there is no token,
 *     object or string a caller outside this class can supply to reach it.
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
     * The record type only the validated release path may mint.
     *
     * Upstream guards it with a module-private `Symbol` that is never re-exported from the
     * package barrel, so a consumer importing `appendDecisionRecord` cannot produce one. PHP has
     * no module-private value, so the port takes the stronger equivalent: the release record type
     * is not reachable through any public entry point at all. `append()` refuses it, and the only
     * caller that passes it to `appendRecord()` is the validating wrapper below, which is a
     * private method of this same class.
     */
    private const RELEASE_RECORD_TYPE = 'INTERNAL_INFORMATIONAL_RELEASE';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    // ---------------------------------------------------------------- stream lifecycle

    /**
     * The canonical default stream key for an environment.
     *
     * Upstream: `input.streamKey ?? \`ledger:${input.environmentClass.toLowerCase()}\``. Tenant and
     * workspace already scope the ROW — they are columns, and `(tenant, workspace, stream_key)` is
     * unique. An earlier revision of this port derived the key as `m10.{tenant}.{workspace}`, which
     * looked harmless and was not: with tenant and workspace already inside the key, that unique
     * index permitted exactly ONE stream per scope for all time, so a DEMO stream could not exist
     * beside a TEST one even once the ACTIVE lookup was fixed. The key is a governed name, not a
     * uniqueness device.
     */
    public static function defaultStreamKey(string $environmentClass): string
    {
        return 'ledger:' . strtolower($environmentClass);
    }

    /**
     * The canonical genesis anchor for a stream identity.
     *
     * Upstream digests exactly these five fields. Two properties follow, and both are load-bearing:
     *
     *  1. **It is derived from LOGICAL identity, not from the row.** The randomly generated
     *     `decision_ledger_stream_id` is deliberately absent, so anyone holding the same tenant,
     *     workspace, environment and key can reconstruct the anchor and verify a chain from its
     *     own identity. An earlier revision of this port digested the random id instead, which made
     *     the anchor unreproducible — a verifier had to be TOLD the answer it was checking.
     *  2. **The environment is part of it.** A TEST anchor and a DEMO anchor for the same scope
     *     differ, so the two histories cannot be mistaken for one another even by digest.
     */
    public static function genesisAnchor(
        string $tenantId,
        string $workspaceId,
        string $environmentClass,
        string $streamKey,
    ): string {
        return Digest::canonicalDigest([
            'kind' => 'GENESIS',
            'streamKey' => $streamKey,
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
            'environmentClass' => $environmentClass,
        ]);
    }

    /**
     * Create an ACTIVE ledger stream for a scope.
     *
     * **A scope is (tenant, workspace, environment).** Not (tenant, workspace). A TEST ledger and a
     * DEMO ledger for the same tenant and workspace are two distinct governed histories, and the
     * canonical partial unique index says so: `UNIQUE (tenant_id, workspace_id, environment_class)
     * WHERE status = 'ACTIVE'`.
     *
     * The pre-check below is a courtesy that returns a named refusal instead of an exception. It is
     * NOT the enforcement: `ux_decision_ledger_streams_active_scope` enforces it in the database,
     * and a concurrent creator that slips past the pre-check is rejected there.
     */
    public function createStream(
        string $tenantId,
        string $workspaceId,
        string $environmentClass,
        ?string $streamKey = null,
    ): Outcome {
        $key = $streamKey ?? self::defaultStreamKey($environmentClass);
        $existing = $this->findActiveStream($tenantId, $workspaceId, $environmentClass);
        if ($existing !== null) {
            // At most one ACTIVE stream per scope — competing heads would be competing histories.
            return Outcome::refuse('LEDGER_ACTIVE_STREAM_ALREADY_EXISTS', [
                'decision_ledger_stream_id' => $existing,
                'environment_class' => $environmentClass,
            ]);
        }

        $streamId = Ids::new();
        $genesis = self::genesisAnchor($tenantId, $workspaceId, $environmentClass, $key);
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

        return Outcome::ok([
            'decision_ledger_stream_id' => $streamId,
            'genesis_hash' => $genesis,
            'stream_key' => $key,
            'environment_class' => $environmentClass,
        ]);
    }

    /**
     * The ACTIVE stream for a scope, or null. The environment is part of the scope.
     *
     * `$latestCommitted` selects a LOCKING read, and it exists for exactly one caller: the race
     * recovery below. The session runs at REPEATABLE READ, so an ordinary read inside a transaction
     * is served from the snapshot taken at that transaction's first read — which means a caller
     * that lost a race would re-read its own "there is no stream" answer forever and conclude the
     * conflict was something else. InnoDB serves LOCKING reads from the latest committed version
     * instead, so the recovery actually sees the winner. It is a shared lock rather than an
     * exclusive one because this reads an id; and if the winner has not committed yet, blocking
     * until it does is the correct answer rather than a race to guess.
     */
    public function findActiveStream(
        string $tenantId,
        string $workspaceId,
        string $environmentClass,
        bool $latestCommitted = false,
    ): ?string {
        $sql = 'SELECT decision_ledger_stream_id FROM decision_ledger_streams
                WHERE tenant_id = ? AND workspace_id = ? AND environment_class = ? AND status = ?';
        $row = $this->db->one(
            $latestCommitted ? $sql . ' LOCK IN SHARE MODE' : $sql,
            [$tenantId, $workspaceId, $environmentClass, 'ACTIVE']
        );

        return $row === null ? null : (string) $row['decision_ledger_stream_id'];
    }

    /**
     * The ACTIVE stream for a scope, creating one if none exists. Idempotent per scope.
     *
     * `$environmentClass` is REQUIRED. It used to default to `'TEST'`, which is the kind of default
     * that reads as a convenience and behaves as a bug: a caller that forgot to pass the workspace's
     * environment silently wrote DEMO history into the TEST ledger, and nothing anywhere said so.
     * There is no environment this module may assume on a caller's behalf.
     *
     * Race convergence follows upstream exactly: find, create, and on a losing race find again and
     * return what the winner made. Two callers asking for the same scope get the same stream — one
     * history, one head — and the loser raises nothing. Any OTHER failure is rethrown, because a
     * swallowed insert error on a governed ledger is indistinguishable from a successful create.
     */
    public function ensureActiveStream(string $tenantId, string $workspaceId, string $environmentClass): string
    {
        $existing = $this->findActiveStream($tenantId, $workspaceId, $environmentClass);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $created = $this->createStream($tenantId, $workspaceId, $environmentClass);
            if (!$created->isRefusal()) {
                return (string) $created->value()['decision_ledger_stream_id'];
            }
            // The pre-check lost to a concurrent creator between the find and the insert.
            $raced = $this->findActiveStream($tenantId, $workspaceId, $environmentClass, true);
            if ($raced !== null) {
                return $raced;
            }

            throw new LedgerStreamConflict((string) $created->reasonCode());
        } catch (\PDOException $error) {
            // The database rejected the insert. If that was the active-scope guard, a winner exists
            // and this caller converges on it; anything else is a real failure and is rethrown.
            $raced = $this->findActiveStream($tenantId, $workspaceId, $environmentClass, true);
            if ($raced !== null) {
                return $raced;
            }

            throw $error;
        }
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
    ): Outcome {
        // Record-type guard. The release record is not reachable from here under ANY argument:
        // there is no token, object or string a caller can add to unlock it. A caller that wants a
        // release calls appendInternalReleaseDecisionRecord(), which loads the governed records and
        // validates them before minting anything.
        if ($recordType === self::RELEASE_RECORD_TYPE) {
            return Outcome::refuse('LEDGER_RELEASE_RECORD_REQUIRES_RELEASE_PATH');
        }

        return $this->appendRecord(
            $ledgerStreamId,
            $recordType,
            $materialityClass,
            $primaryOutcomeStatus,
            $safeDecisionSummary,
            $inputReferences,
            $fields,
        );
    }

    /**
     * The append itself, with no record-type restriction.
     *
     * Private on purpose: this is the only code path that can write the release record type, and
     * it has exactly two callers inside this class — `append()`, which has already refused that
     * type, and the validated release wrapper.
     *
     * Nests via savepoint when a caller already holds a transaction, which is how the release
     * wrapper gets upstream's `ambientTransaction: true` behaviour: the append shares the release
     * unit's transaction rather than committing independently of the status flip.
     *
     * @param list<array<string, mixed>> $inputReferences
     * @param array<string, mixed>       $fields
     */
    private function appendRecord(
        string $ledgerStreamId,
        string $recordType,
        string $materialityClass,
        string $primaryOutcomeStatus,
        string $safeDecisionSummary,
        array $inputReferences,
        array $fields = [],
    ): Outcome {
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
     * The ONE place that releases a proposal.
     *
     * Ported from `appendInternalReleaseDecisionRecord` in `src/m10/ledger.ts`. The caller supplies
     * a release IDENTITY — the M08 verdict — and nothing else. It does not supply basis references,
     * because a caller that can name its own basis can name a fabricated one, and the record the
     * console treats as proof of release would then prove only that a caller asserted something.
     * M10 loads the governed records itself and builds the references from what it read.
     *
     * Validation order is canonical and load-bearing:
     *
     *  1. the verdict exists, is `ALLOW_INFORMATIONAL_RELEASE` or `ALLOW_WITH_CONDITIONS`, AND is
     *     routed to `M10_INTERNAL_RELEASE_RECORDING` — an ALLOW row routed elsewhere is an ALLOW
     *     for a different stage, and honouring it would bypass the path that was actually chosen;
     *  2. no M08 invalidation blocks progression on it;
     *  3. for `ALLOW_WITH_CONDITIONS`, no attached condition is still OPEN/UNSATISFIED/FAILED;
     *  4. the proposal exists and is still SUBMITTED;
     *  5. the verdict and the proposal are the same tenant AND workspace;
     *  6. the proposal's submission digest still matches the one the verdict was issued against.
     *
     * Everything that follows is one transaction: the record, its references, the head advance,
     * the guarded status flip and the transition row commit together or not at all. A split would
     * leave a durable release record against a still-SUBMITTED proposal, and the retry would mint a
     * SECOND release record for the same decision.
     *
     * @param array{recorded_by_service_identity_id?: string, correlation_id?: string} $options
     */
    public function appendInternalReleaseDecisionRecord(
        string $arbiterVerdictId,
        array $options = [],
    ): Outcome {
        $verdict = $this->db->one(
            'SELECT v.verdict, v.permitted_next_stage, v.proposal_id, v.proposal_submission_digest,
                    v.basis_hash, v.tenant_id, v.workspace_id, v.mission_id, v.evidence_bundle_id,
                    v.evidence_bundle_hash, v.hmwh_result_id
             FROM arbiter_verdicts v WHERE v.arbiter_verdict_id = ?',
            [$arbiterVerdictId]
        );
        // Verdict value AND routed stage, exactly as the sibling M09 approval gate checks both. A
        // malformed or imported ALLOW_* row that is NOT routed to M10 internal-release recording
        // must not flip a proposal to RELEASED.
        if (
            $verdict === null
            || !in_array((string) $verdict['verdict'], ['ALLOW_INFORMATIONAL_RELEASE', 'ALLOW_WITH_CONDITIONS'], true)
            || (string) $verdict['permitted_next_stage'] !== 'M10_INTERNAL_RELEASE_RECORDING'
        ) {
            return Outcome::refuse('LEDGER_INTERNAL_RELEASE_WITHOUT_VALID_VERDICT', [
                'arbiter_verdict_id' => $arbiterVerdictId,
                'verdict' => $verdict === null ? null : (string) $verdict['verdict'],
                'permitted_next_stage' => $verdict === null ? null : (string) $verdict['permitted_next_stage'],
            ]);
        }

        // A verdict M08 has explicitly invalidated must not drive release progression, even though
        // the ALLOW_* row itself is immutable and still reads ALLOW.
        $invalidation = $this->db->one(
            "SELECT 1 AS hit FROM arbiter_invalidations
             WHERE affected_object_type = 'VERDICT' AND affected_object_id = ?
               AND blocks_pending_progression = 1 LIMIT 1",
            [$arbiterVerdictId]
        );
        if ($invalidation !== null) {
            return Outcome::refuse('LEDGER_RELEASE_VERDICT_INVALIDATED', [
                'arbiter_verdict_id' => $arbiterVerdictId,
            ]);
        }

        // ALLOW_WITH_CONDITIONS releases only once every attached condition is satisfied or
        // superseded. An OPEN/UNSATISFIED/FAILED condition means the required satisfaction
        // reference does not exist yet.
        if ((string) $verdict['verdict'] === 'ALLOW_WITH_CONDITIONS') {
            $openCondition = $this->db->one(
                "SELECT 1 AS hit FROM arbiter_conditions
                 WHERE arbiter_verdict_id = ? AND status IN ('OPEN', 'UNSATISFIED', 'FAILED') LIMIT 1",
                [$arbiterVerdictId]
            );
            if ($openCondition !== null) {
                return Outcome::refuse('LEDGER_RELEASE_CONDITIONS_UNSATISFIED', [
                    'arbiter_verdict_id' => $arbiterVerdictId,
                ]);
            }
        }

        $proposalId = (string) $verdict['proposal_id'];
        $proposal = $this->db->one(
            'SELECT p.status AS status, p.submission_digest AS submission_digest,
                    p.tenant_id AS tenant_id, p.workspace_id AS workspace_id,
                    w.environment_class AS environment_class
             FROM proposal_envelopes p JOIN workspaces w ON w.workspace_id = p.workspace_id
             WHERE p.proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null || (string) $proposal['status'] !== 'SUBMITTED') {
            return Outcome::refuse('LEDGER_PROPOSAL_NOT_RELEASABLE', [
                'proposal_id' => $proposalId,
                'status' => $proposal === null ? null : (string) $proposal['status'],
            ]);
        }
        // A cross-scope verdict must never release another scope's proposal, nor leave its M10
        // evidence in the wrong tenant's ledger.
        if (
            (string) $proposal['tenant_id'] !== (string) $verdict['tenant_id']
            || (string) $proposal['workspace_id'] !== (string) $verdict['workspace_id']
        ) {
            return Outcome::refuse('LEDGER_RELEASE_VERDICT_SCOPE_MISMATCH', [
                'proposal_id' => $proposalId,
                'arbiter_verdict_id' => $arbiterVerdictId,
            ]);
        }
        // The proposal must still be the one the verdict was issued against. A revised submission
        // produces a new digest, and the old verdict no longer speaks for it.
        if ((string) $proposal['submission_digest'] !== (string) $verdict['proposal_submission_digest']) {
            return Outcome::refuse('LEDGER_UPSTREAM_DIGEST_MISMATCH', [
                'proposal_id' => $proposalId,
                'arbiter_verdict_id' => $arbiterVerdictId,
            ]);
        }

        // References built from the records M10 just READ, never from caller input.
        $references = [
            [
                'owner_module_id' => 'M08',
                'input_object_type' => 'arbiter_verdict',
                'input_object_id' => $arbiterVerdictId,
                'input_object_version_or_digest' => (string) $verdict['basis_hash'],
                'reference_role' => 'VERDICT_BASIS',
                'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
            ],
            [
                'owner_module_id' => 'M04',
                'input_object_type' => 'proposal_envelope',
                'input_object_id' => $proposalId,
                'input_object_version_or_digest' => (string) $verdict['proposal_submission_digest'],
                'reference_role' => 'PROPOSAL_BASIS',
                'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
            ],
        ];
        if ($verdict['evidence_bundle_id'] !== null) {
            $references[] = [
                'owner_module_id' => 'M05',
                'input_object_type' => 'evidence_bundle',
                'input_object_id' => (string) $verdict['evidence_bundle_id'],
                'input_object_version_or_digest' => $verdict['evidence_bundle_hash'] === null
                    ? null
                    : (string) $verdict['evidence_bundle_hash'],
                'reference_role' => 'EVIDENCE_BASIS',
                'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
            ];
        }
        if ($verdict['hmwh_result_id'] !== null) {
            $references[] = [
                'owner_module_id' => 'M07',
                'input_object_type' => 'hmwh_result',
                'input_object_id' => (string) $verdict['hmwh_result_id'],
                'input_object_version_or_digest' => null,
                'reference_role' => 'REVIEW_BASIS',
                'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
            ];
        }

        try {
            return $this->db->transaction(function (Database $tx) use (
                $arbiterVerdictId, $verdict, $proposal, $proposalId, $references, $options
            ): Outcome {
                $ledgerStreamId = $this->ensureActiveStream(
                    (string) $verdict['tenant_id'],
                    (string) $verdict['workspace_id'],
                    (string) $proposal['environment_class'],
                );

                $appended = $this->appendRecord(
                    $ledgerStreamId,
                    self::RELEASE_RECORD_TYPE,
                    'GOVERNANCE_MATERIAL',
                    'RELEASED_INTERNAL',
                    'Internal informational release recorded after M08 ALLOW verdict.',
                    $references,
                    [
                        'reason_codes' => ['M10_INTERNAL_RELEASE_RECORDED'],
                        'mission_id' => (string) $verdict['mission_id'],
                        'proposal_id' => $proposalId,
                        'proposal_submission_digest' => (string) $verdict['proposal_submission_digest'],
                        'correlation_id' => $options['correlation_id'] ?? null,
                        'recorded_by_service_identity_id' => $options['recorded_by_service_identity_id'] ?? null,
                    ],
                );
                if ($appended->isRefusal()) {
                    // Roll the unit back rather than returning: a returned refusal would COMMIT
                    // whatever the append already wrote.
                    throw new ReleaseRollback($appended->reasonCode());
                }
                $decisionRecordId = (string) $appended->value()['decision_record_id'];

                // M10-owned mutation. Guarded on the still-SUBMITTED row so a concurrent racer that
                // already released it advances no second time.
                $flipped = $tx->run(
                    "UPDATE proposal_envelopes SET status = 'RELEASED'
                     WHERE proposal_id = ? AND status = 'SUBMITTED'",
                    [$proposalId]
                );
                if ($flipped !== 1) {
                    throw new ReleaseRollback('LEDGER_PROPOSAL_NOT_RELEASABLE');
                }

                $tx->insert('proposal_status_transitions', [
                    'transition_id' => Ids::new(),
                    'schema_version' => '1',
                    'proposal_id' => $proposalId,
                    'from_status' => 'SUBMITTED',
                    'to_status' => 'RELEASED',
                    'reason_code' => 'M10_DECISION_RECORD_APPENDED',
                    'initiated_by_actor_type' => 'M10',
                    'initiated_by_actor_id' => $decisionRecordId,
                    'occurred_at' => $this->clock->now(),
                ]);

                return Outcome::ok([
                    'decision_record_id' => $decisionRecordId,
                    'ledger_stream_id' => $ledgerStreamId,
                    'proposal_id' => $proposalId,
                    'record_sequence_number' => (int) $appended->value()['record_sequence_number'],
                    'chain_hash' => (string) $appended->value()['chain_hash'],
                ]);
            });
        } catch (ReleaseRollback $rollback) {
            return Outcome::refuse($rollback->reasonCode, [
                'arbiter_verdict_id' => $arbiterVerdictId,
                'proposal_id' => $proposalId,
            ]);
        } catch (LedgerSequenceConflict $conflict) {
            // A lost head race rolled the whole unit back, proposal status included.
            return Outcome::refuse('LEDGER_SEQUENCE_CONFLICT', [
                'arbiter_verdict_id' => $arbiterVerdictId,
                'proposal_id' => $proposalId,
            ]);
        }
    }

    /**
     * The release record already recorded ON THIS VERDICT, if any.
     *
     * Matched through the record's own `VERDICT_BASIS` reference, not merely by proposal: a release
     * decided on a DIFFERENT verdict is a different decision, and adopting it would let a second
     * verdict inherit a release it never caused.
     *
     * @return array{decision_record_id: string, ledger_stream_id: string, proposal_id: string, record_sequence_number: int}|null
     */
    public function existingReleaseRecordForVerdict(string $arbiterVerdictId): ?array
    {
        $row = $this->db->one(
            'SELECT r.decision_record_id, r.ledger_stream_id, r.proposal_id, r.record_sequence_number
             FROM decision_records r
             JOIN decision_input_references ref
               ON ref.decision_record_id = r.decision_record_id
              AND ref.reference_role = ?
              AND ref.input_object_id = ?
             WHERE r.record_type = ?
             ORDER BY r.record_sequence_number ASC LIMIT 1',
            ['VERDICT_BASIS', $arbiterVerdictId, self::RELEASE_RECORD_TYPE]
        );

        return $row === null ? null : [
            'decision_record_id' => (string) $row['decision_record_id'],
            'ledger_stream_id' => (string) $row['ledger_stream_id'],
            'proposal_id' => (string) $row['proposal_id'],
            'record_sequence_number' => (int) $row['record_sequence_number'],
        ];
    }

    /**
     * Release, or resume a release M10 already committed for this verdict.
     *
     * The product projection commits in its OWN transaction after M10's — upstream keeps that
     * boundary too, and merging them would put product schema inside the substrate's release unit.
     * The cost of that boundary is a window: M10 can commit and the product projection can then
     * fail, leaving a real, correct release with an incomplete product view.
     *
     * A retry must be able to finish the product half. Calling the release path again would be
     * refused — the proposal is no longer SUBMITTED, which is exactly right — so the retry asks for
     * the decision that was already made instead. It mints nothing: the outcome is flagged
     * `RESUMED`, the record id is the ORIGINAL one, and the ledger head does not move.
     *
     * A rejection for any other reason stays a rejection. Only `LEDGER_PROPOSAL_NOT_RELEASABLE`
     * with a release record already standing on THIS verdict resumes.
     *
     * @param array{recorded_by_service_identity_id?: string, correlation_id?: string} $options
     */
    public function releaseOrResume(string $arbiterVerdictId, array $options = []): Outcome
    {
        $released = $this->appendInternalReleaseDecisionRecord($arbiterVerdictId, $options);
        if (!$released->isRefusal()) {
            return Outcome::ok($released->value() + ['outcome' => 'RELEASED']);
        }
        if ($released->reasonCode() !== 'LEDGER_PROPOSAL_NOT_RELEASABLE') {
            return $released;
        }

        $existing = $this->existingReleaseRecordForVerdict($arbiterVerdictId);
        if ($existing === null) {
            return $released;
        }

        return Outcome::ok($existing + ['outcome' => 'RESUMED']);
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
    /**
     * Verify ONE record's payload digest, independently of the chain.
     *
     * A record's payload digest is a property of that record alone: it recomputes from the record's
     * own fields and its input references. The CHAIN links records to each other, and
     * `verifyStream()` checks that; this checks whether a single record still says what it was
     * digested as saying.
     *
     * That distinction matters to M11 replay, which asks about specific records rather than about
     * a whole stream — and to an operator reading a finding, for whom "this record was altered" and
     * "the chain around it is broken" call for different responses.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function verifyRecordPayload(string $decisionRecordId): array
    {
        $record = $this->db->one(
            'SELECT * FROM decision_records WHERE decision_record_id = ?',
            [$decisionRecordId]
        );
        if ($record === null) {
            return ['ok' => false, 'reason' => 'LEDGER_RECORD_NOT_FOUND'];
        }

        $recomputed = self::computeRecordPayloadDigest(
            self::payloadProjection($record),
            $this->recordReferences($decisionRecordId),
        );

        return hash_equals((string) $record['record_payload_digest'], $recomputed)
            ? ['ok' => true]
            : ['ok' => false, 'reason' => 'LEDGER_PAYLOAD_DIGEST_MISMATCH'];
    }

    /**
     * The canonical field projection a record's payload digest is computed over.
     *
     * ONE definition, shared by the writer, the stream verifier and the single-record verifier. A
     * second copy would drift, and a digest that two readers compute differently verifies nothing.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function payloadProjection(array $record): array
    {
        return [
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
            // Reliance is part of the digest AND is never edited in place: supersession writes a
            // separate row and `currentReliance()` derives from it. So a superseded record still
            // recomputes to its ORIGINAL digest and stays verifiable as history.
            'current_reliance_status' => (string) $record['current_reliance_status'],
            'record_sequence_number' => (int) $record['record_sequence_number'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recordReferences(string $decisionRecordId): array
    {
        return $this->db->all(
            'SELECT owner_module_id, input_object_type, input_object_id,
                    input_object_version_or_digest, reference_role, verification_status_at_record_time
             FROM decision_input_references WHERE decision_record_id = ?',
            [$decisionRecordId]
        );
    }

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

            $recomputedPayload = self::computeRecordPayloadDigest(
                self::payloadProjection($record),
                $this->recordReferences($recordId),
            );

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
