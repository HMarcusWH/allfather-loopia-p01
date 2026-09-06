<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * The canonical closed vocabulary.
 *
 * Ported verbatim from `packages/allfather-substrate-core/src/{action-class,status,error-model}.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * These values are the conformance surface. They are preserved EXACTLY — a value may be adapted
 * syntactically for MariaDB/PHP, never renamed, merged, or replaced by a generic equivalent.
 * A value absent from the canonical list must not be invented here; if a governed path needs one,
 * that is an upstream change, not a projection change.
 */
final class Vocabulary
{
    /**
     * V1 action classification (doc 03 §6, TERM-DEC-012). Every mission output or attempted action
     * is assigned exactly one class.
     */
    public const ACTION_CLASS = [
        'OBSERVE',                       // A — read/inspect/retrieve/capture within scope
        'ANALYZE',                       // B — provisional reasoning/transformation
        'RELEASE_INTELLIGENCE',          // C — distribute approved informational output in scope
        'PREPARE_EXTERNAL_ACTION',       // D — produce exact candidate side effect for approval
        'EXECUTE_CONSEQUENTIAL_ACTION',  // E — perform exact approved side effect
        'FORBIDDEN',                     // X — not authorized/supported/lawful
    ];

    /**
     * The action-class ceiling this projection operates under. D and E are unreachable from this
     * codebase; the values remain in the vocabulary because P07-A's M09 plane must be able to
     * *name* the class it is preparing without ever executing it.
     */
    public const PROJECTION_ACTION_CEILING = ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'];

    /** Classes requiring the full M09 approval + Frozen Action Artifact chain before execution. */
    public const SIDE_EFFECTING_CLASSES = ['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'];

    public const MISSION_STATUS = ['DRAFT', 'ACTIVE', 'PAUSED', 'EXPIRED', 'COMPLETED', 'CANCELLED', 'BLOCKED'];

    public const VALKYRIE_STATUS = ['DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'RETIRED'];

    public const CAPABILITY_CONTRACT_STATUS = ['DRAFT', 'ACTIVE', 'SUPERSEDED', 'SUSPENDED', 'REVOKED', 'EXPIRED'];

    public const TOOL_AUTHORIZATION_DECISION = ['ALLOW', 'DENY', 'REQUIRE_APPROVAL', 'HOLD_FOR_REVIEW', 'QUARANTINE'];

    /**
     * Arbiter verdicts. Note `REQUIRE_INCIDENT_EVALUATION` and `REQUIRE_REVISION` appear in M08's
     * own widened verdict vocabulary (`src/m08/arbiter.ts`) beyond the substrate-core enum; both
     * spellings are carried so a seeded policy set's `available_verdicts` round-trips exactly.
     */
    public const ARBITER_VERDICT = [
        'ALLOW_INFORMATIONAL_RELEASE',
        'ALLOW_PROVISIONAL_ANALYSIS',
        'REQUIRE_MORE_EVIDENCE',
        'REQUIRE_REVISION',
        'REQUIRE_HUMAN_APPROVAL',
        'HOLD',
        'BLOCK',
        'QUARANTINE',
        'OPEN_INCIDENT',
        'REQUIRE_INCIDENT_EVALUATION',
    ];

    /** Verdicts that permit a release route. Anything else is fail-closed. */
    public const RELEASE_PERMITTING_VERDICTS = ['ALLOW_INFORMATIONAL_RELEASE'];

    public const APPROVAL_DECISION = [
        'APPROVE_EXACT_ARTIFACT',
        'DENY',
        'REQUEST_REVISION',
        'EXPIRE',
        'REVOKE_BEFORE_EXECUTION',
    ];

    public const EXECUTION_STATUS = [
        'PENDING',
        'EXECUTING',
        'SUCCEEDED',
        'FAILED',
        'REJECTED_INTEGRITY_FAILURE',
        'REJECTED_AUTHORITY_FAILURE',
        'ROLLED_BACK',
        'REMEDIATION_REQUIRED',
    ];

    /** UNKNOWN must never be treated as healthy. */
    public const HOST_HEALTH_STATE = [
        'HEALTHY', 'DEGRADED', 'UNAVAILABLE', 'MISCONFIGURED', 'UNTRUSTED_BEHAVIOR', 'UNKNOWN',
    ];

    /** Any of these means the material may not be relied on as admitted evidence. */
    public const EVIDENCE_STATE = [
        'MISSING', 'UNAVAILABLE', 'STALE', 'LOW_AUTHORITY', 'CONFLICTED', 'INTEGRITY_FAILED', 'FORBIDDEN_USE',
    ];

    public const PRIMARY_ERROR_CATEGORY = [
        'VALIDATION', 'AUTHENTICATION', 'AUTHORIZATION', 'SCOPE', 'CONTRACT', 'MISSION', 'EVIDENCE',
        'REVIEW', 'POLICY', 'APPROVAL', 'INTEGRITY', 'HOST_ADAPTER', 'DEPENDENCY', 'CONCURRENCY',
        'PROVENANCE', 'SECURITY', 'PRIVACY', 'INTERNAL',
    ];

    /** S0 informational … S5 release-blocking architectural/provenance breach. */
    public const SEVERITY = ['S0', 'S1', 'S2', 'S3', 'S4', 'S5'];

    public const MATERIALITY = ['OPERATIONAL', 'GOVERNANCE_MATERIAL', 'SECURITY_MATERIAL', 'LEDGER_REQUIRED'];

    public const RESPONSE_MODE = [
        'REJECT_REQUEST', 'HOLD_FOR_INPUT', 'DENY_AND_LOG', 'REQUIRE_HUMAN_REVIEW',
        'QUARANTINE_OBJECT', 'SUSPEND_AUTHORITY', 'OPEN_INCIDENT', 'BLOCK_RELEASE',
        'REMEDIATE_THROUGH_GOVERNED_PATH',
    ];

    /** Ordered weakest → strictest. Where several modes apply, the strictest governs. */
    public const RESPONSE_MODE_PRECEDENCE = [
        'REJECT_REQUEST',
        'HOLD_FOR_INPUT',
        'DENY_AND_LOG',
        'REQUIRE_HUMAN_REVIEW',
        'QUARANTINE_OBJECT',
        'REMEDIATE_THROUGH_GOVERNED_PATH',
        'SUSPEND_AUTHORITY',
        'BLOCK_RELEASE',
        'OPEN_INCIDENT',
    ];

    public const FAIL_CLOSED_PRECONDITION = [
        'SCOPE', 'AUTHORITY', 'EVIDENCE', 'POLICY', 'APPROVAL',
        'ARTIFACT_INTEGRITY', 'LEDGER_INTEGRITY', 'HOST_SAFETY',
    ];

    public const FAIL_CLOSED_CATEGORY = [
        'SCOPE' => 'SCOPE',
        'AUTHORITY' => 'AUTHORIZATION',
        'EVIDENCE' => 'EVIDENCE',
        'POLICY' => 'POLICY',
        'APPROVAL' => 'APPROVAL',
        'ARTIFACT_INTEGRITY' => 'INTEGRITY',
        'LEDGER_INTEGRITY' => 'INTEGRITY',
        'HOST_SAFETY' => 'HOST_ADAPTER',
    ];

    /**
     * The interpretation kind every derived claim must carry. Collapsing these is the single most
     * consequential governance failure available to this system: it turns "this company may fit"
     * into "this company wants to buy".
     */
    public const INTERPRETATION_KIND = ['FACT_OBSERVED', 'HYPOTHESIS'];

    public const ACTOR_TYPE = ['OPERATOR', 'SERVICE', 'VALKYRIE', 'SYSTEM'];

    /** @param list<string> $vocabulary */
    public static function isMember(string $value, array $vocabulary): bool
    {
        return in_array($value, $vocabulary, true);
    }

    /**
     * Assert membership where a non-member is a PROGRAMMING error rather than a governed decision
     * — an internal call site passing a value outside the closed vocabulary. This is not a
     * refusal: no reason code exists for it, because no governed path can produce it.
     *
     * @param list<string> $vocabulary
     */
    public static function assertMemberOrThrow(string $value, array $vocabulary): string
    {
        if (!self::isMember($value, $vocabulary)) {
            throw new \LogicException("value '{$value}' is outside the closed vocabulary");
        }

        return $value;
    }

    /**
     * Assert membership, failing closed with the canonical reason code rather than a generic
     * exception. An unrecognized value is never treated as permissive.
     *
     * @param list<string> $vocabulary
     */
    public static function assertMember(string $value, array $vocabulary, string $reasonCode): string
    {
        if (!self::isMember($value, $vocabulary)) {
            throw new GovernedRefusalException(Outcome::refuse($reasonCode, [
                'offending_value' => $value,
            ]));
        }

        return $value;
    }

    /** @param list<string> $modes */
    public static function strictestResponseMode(array $modes): ?string
    {
        $chosen = null;
        $chosenRank = -1;
        foreach ($modes as $mode) {
            $rank = array_search($mode, self::RESPONSE_MODE_PRECEDENCE, true);
            if ($rank !== false && $rank > $chosenRank) {
                $chosenRank = $rank;
                $chosen = $mode;
            }
        }

        return $chosen;
    }

    public static function isSideEffectingClass(string $actionClass): bool
    {
        return in_array($actionClass, self::SIDE_EFFECTING_CLASSES, true);
    }
}
