<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M06;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Db\Database;

/**
 * Stored decision and gate-evidence integrity.
 *
 * Reading `tool_authorization_decisions.decision = 'ALLOW'` as a flat fact is not evidence. An
 * ALLOW row whose digest no longer matches its own content, or which cites a gate check belonging
 * to another request — or a FAILED one — would otherwise authorize a dispatch exactly as a sound
 * one does.
 *
 * This verifies the INTEGRITY OF THE STORED EVIDENCE. It deliberately does NOT re-execute the
 * historical domain gates against current external state; M01's final-state authority law does
 * that separately for the mutable descriptor surface, and claiming more here would be untrue.
 */
final class DecisionIntegrity
{
    public const HASH_ALGORITHM = 'sha256';

    /**
     * The canonical projection every writer digests and every verifier recomputes.
     *
     * ONE definition. A second copy would drift, and a digest that cannot be recomputed from
     * persisted rows cannot be verified at all.
     *
     * @param list<string> $passedGateCheckIds
     */
    public static function decisionDigest(
        string $toolRequestId,
        string $governedToolOperationId,
        string $validatedActionClass,
        string $normalizedInputDigest,
        array $passedGateCheckIds,
        string $decision,
    ): string {
        return Digest::canonicalDigest([
            'toolRequestId' => $toolRequestId,
            'governedToolOperationId' => $governedToolOperationId,
            'validatedActionClass' => $validatedActionClass,
            'normalizedInputDigest' => $normalizedInputDigest,
            'passedGateCheckIds' => array_values($passedGateCheckIds),
            'decision' => $decision,
        ]);
    }

    /**
     * @param list<string> $requiredGateTypes
     * @return array{ok: bool, reason?: string}
     */
    public static function verify(
        Database $db,
        string $toolRequestId,
        string $toolAuthorizationDecisionId,
        string $expectedValidatedActionClass,
        array $requiredGateTypes,
    ): array {
        $decision = $db->one(
            'SELECT d.tool_request_id, d.decision, d.validated_action_class, d.passed_gate_check_ids,
                    d.failed_or_holding_gate_check_ids, d.decision_digest, d.hash_algorithm,
                    r.governed_tool_operation_id, r.normalized_input_digest
             FROM tool_authorization_decisions d
             JOIN tool_requests r ON r.tool_request_id = d.tool_request_id
             WHERE d.tool_authorization_decision_id = ?',
            [$toolAuthorizationDecisionId]
        );
        if ($decision === null) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_NOT_FOUND'];
        }
        if ((string) $decision['tool_request_id'] !== $toolRequestId) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_REQUEST_MISMATCH'];
        }
        if ((string) $decision['decision'] !== 'ALLOW') {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_NOT_ALLOW'];
        }
        if ((string) $decision['validated_action_class'] !== $expectedValidatedActionClass) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_ACTION_CLASS_MISMATCH'];
        }
        if ((string) $decision['hash_algorithm'] !== self::HASH_ALGORITHM) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_HASH_ALGORITHM_UNSUPPORTED'];
        }

        $passed = self::parseIdArray($decision['passed_gate_check_ids']);
        $failed = self::parseIdArray($decision['failed_or_holding_gate_check_ids']);
        if ($passed === null || $failed === null) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_GATE_EVIDENCE_MALFORMED'];
        }
        // An ALLOW that also cites failing or holding gates is self-contradictory evidence.
        if ($failed !== []) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_HOLDING_GATES_PRESENT'];
        }
        if (count(array_unique($passed)) !== count($passed)) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_GATE_CHECK_DUPLICATE'];
        }

        // Recomputed from the SAME canonical projection the writer used, over values read back
        // from the database — so a row edited after the fact no longer digests to its stored value.
        $recomputed = self::decisionDigest(
            (string) $decision['tool_request_id'],
            (string) $decision['governed_tool_operation_id'],
            (string) $decision['validated_action_class'],
            (string) $decision['normalized_input_digest'],
            $passed,
            (string) $decision['decision'],
        );
        if ($recomputed !== (string) $decision['decision_digest']) {
            return ['ok' => false, 'reason' => 'AUTHORIZATION_DECISION_DIGEST_MISMATCH'];
        }

        $observed = [];
        foreach ($passed as $gateCheckId) {
            $gate = $db->one(
                'SELECT tool_request_id, gate_type, decision FROM tool_gate_checks
                 WHERE tool_gate_check_id = ?',
                [$gateCheckId]
            );
            if ($gate === null) {
                return ['ok' => false, 'reason' => 'AUTHORIZATION_GATE_CHECK_NOT_FOUND'];
            }
            if ((string) $gate['tool_request_id'] !== $toolRequestId) {
                return ['ok' => false, 'reason' => 'AUTHORIZATION_GATE_CHECK_FOREIGN_REQUEST'];
            }
            if ((string) $gate['decision'] !== 'PASS') {
                return ['ok' => false, 'reason' => 'AUTHORIZATION_GATE_CHECK_NOT_PASSED'];
            }
            $observed[(string) $gate['gate_type']] = true;
        }
        foreach ($requiredGateTypes as $required) {
            if (!isset($observed[$required])) {
                return ['ok' => false, 'reason' => 'AUTHORIZATION_REQUIRED_GATE_TYPE_MISSING'];
            }
        }

        return ['ok' => true];
    }

    /** @return list<string>|null */
    private static function parseIdArray(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            $parsed = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($parsed) || !array_is_list($parsed)) {
            return null;
        }
        foreach ($parsed as $entry) {
            if (!is_string($entry)) {
                return null;
            }
        }

        return $parsed;
    }
}
