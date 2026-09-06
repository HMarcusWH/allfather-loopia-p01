<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

/**
 * The M08 verdict mappings.
 *
 * Ported from `packages/allfather-control-substrate/src/m08/arbiter.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * These tables are the fail-closed core of the decision plane, kept apart from the engine so they
 * can be read and tested as data. Every branch either routes AWAY from release or falls through;
 * **no branch invents an ALLOW**. The `default` arms matter most: an unknown readiness status or an
 * unrecognized council recommendation HOLDs rather than passing, so a state nobody anticipated can
 * never become permission.
 */
final class Verdicts
{
    /** Action classes that are never admissible, whatever any policy says. */
    public const PROHIBITED_ACTION_CLASSES = ['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'];

    public const VERDICT = [
        'ALLOW_INFORMATIONAL_RELEASE', 'ALLOW_PROVISIONAL_ANALYSIS', 'REQUIRE_MORE_EVIDENCE',
        'REQUIRE_REVISION', 'REQUIRE_HUMAN_APPROVAL', 'HOLD', 'BLOCK', 'QUARANTINE',
        'REQUIRE_INCIDENT_EVALUATION',
    ];

    public const PERMITTED_NEXT_STAGE = [
        'M10_INTERNAL_RELEASE_RECORDING', 'M12_PROVISIONAL_DISPLAY', 'M05_ADDITIONAL_EVIDENCE_PATH',
        'M04_REVISION_PATH', 'M09_APPROVAL_REQUEST_CREATION', 'M11_INCIDENT_EVALUATION',
        'NO_PROGRESSION',
    ];

    public const RULE_CATEGORY = [
        'V1_SCOPE', 'M02_ACCESS', 'M03_AUTHORITY', 'M04_MISSION_PROPOSAL', 'M05_EVIDENCE',
        'M07_HMWH', 'M06_TOOL_HISTORY', 'M09_APPROVAL_ROUTE_PRECONDITION', 'PROVENANCE_IP',
        'UNCERTAINTY_DISCLOSURE', 'INCIDENT_QUARANTINE', 'P01_RELEVANCE', 'P03_PROSPECT_ADMISSION',
        'P04_DISTRIBUTION_ADMISSION', 'P07_OUTREACH_EXECUTION_ADMISSION', 'P02_TWIN_STATE_ADMISSION',
        'P02_RELATIONSHIP_STATE_ADMISSION', 'P05_MARKETING_ASSET_ADMISSION', 'SOURCE_CONFIG_ADMISSION',
    ];

    /** The three non-overridable seed categories, always evaluated first, in this order. */
    public const SEED_CATEGORIES = ['V1_SCOPE', 'M05_EVIDENCE', 'M07_HMWH'];

    public const CHECK_TYPE = [
        'PASS_FAIL', 'ENUM_STATUS', 'REQUIRED_REFERENCE', 'PROHIBITION_CHECK', 'CONDITION_CHECK',
    ];

    /**
     * SEED 1 — the V1-scope prohibition.
     *
     * A prohibited action class is never admissible; it BLOCKs first and cannot be overridden by
     * any policy rule, or by the absence of one.
     *
     * @return array<string, mixed>|null null means PASS
     */
    public static function scopeProhibition(string $requestedActionClass): ?array
    {
        if (!in_array($requestedActionClass, self::PROHIBITED_ACTION_CLASSES, true)) {
            return null;
        }

        return [
            'verdict' => 'BLOCK',
            'permitted_next_stage' => 'NO_PROGRESSION',
            'reason_codes' => ['PROHIBITED_CAPABILITY', 'ARBITER_REQUESTED_ROUTE_OUTSIDE_V1'],
            'severity' => 'S3',
            'incident_evaluation_required' => false,
        ];
    }

    /**
     * SEED 2 — M05 evidence readiness.
     *
     * Anything other than `SATISFIED_FOR_EVALUATION` fails closed to its specific verdict, so
     * INCOMPLETE / STALE / CONFLICTED / QUARANTINED can never reach an ALLOW.
     *
     * @return array<string, mixed>|null null means PASS
     */
    public static function readinessToVerdict(string $status): ?array
    {
        return match ($status) {
            'SATISFIED_FOR_EVALUATION' => null,
            'QUARANTINED' => [
                'verdict' => 'QUARANTINE',
                'permitted_next_stage' => 'M11_INCIDENT_EVALUATION',
                'reason_codes' => ['ARBITER_EVIDENCE_INTEGRITY_OR_RESTRICTION_FAILURE'],
                'severity' => 'S4',
                'incident_evaluation_required' => true,
            ],
            'FORBIDDEN_USE' => [
                'verdict' => 'BLOCK',
                'permitted_next_stage' => 'NO_PROGRESSION',
                'reason_codes' => ['ARBITER_EVIDENCE_INTEGRITY_OR_RESTRICTION_FAILURE'],
                'severity' => 'S3',
                'incident_evaluation_required' => false,
            ],
            'CONFLICTED' => [
                'verdict' => 'HOLD',
                'permitted_next_stage' => 'M05_ADDITIONAL_EVIDENCE_PATH',
                'reason_codes' => ['ARBITER_EVIDENCE_NOT_READY', 'EVIDENCE_CONFLICT_UNRESOLVED'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
            'STALE_FOR_USE' => [
                'verdict' => 'REQUIRE_MORE_EVIDENCE',
                'permitted_next_stage' => 'M05_ADDITIONAL_EVIDENCE_PATH',
                'reason_codes' => ['ARBITER_EVIDENCE_NOT_READY', 'STALE_INPUT'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
            'INCOMPLETE' => [
                'verdict' => 'REQUIRE_MORE_EVIDENCE',
                'permitted_next_stage' => 'M05_ADDITIONAL_EVIDENCE_PATH',
                'reason_codes' => ['ARBITER_EVIDENCE_NOT_READY', 'INSUFFICIENT_EVIDENCE'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
            // UNKNOWN or any unexpected status fails closed to HOLD. This arm is the whole point:
            // a readiness state nobody anticipated must never become permission.
            default => [
                'verdict' => 'HOLD',
                'permitted_next_stage' => 'NO_PROGRESSION',
                'reason_codes' => ['ARBITER_EVIDENCE_NOT_READY'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
        };
    }

    /**
     * SEED 3 — the M07 council recommendation, where the review path requires one.
     *
     * @return array<string, mixed>|null null means PASS
     */
    public static function hmwhRecommendationToVerdict(string $recommendation): ?array
    {
        return match ($recommendation) {
            'PROCEED_TO_ARBITER', 'PROCEED_WITH_CONDITIONS' => null,
            'BLOCK' => [
                'verdict' => 'BLOCK',
                'permitted_next_stage' => 'NO_PROGRESSION',
                'reason_codes' => ['ARBITER_HMWH_RECOMMENDED_BLOCK'],
                'severity' => 'S3',
                'incident_evaluation_required' => false,
            ],
            'QUARANTINE_OR_INCIDENT_EVALUATION' => [
                'verdict' => 'REQUIRE_INCIDENT_EVALUATION',
                'permitted_next_stage' => 'M11_INCIDENT_EVALUATION',
                'reason_codes' => ['ARBITER_HMWH_RECOMMENDED_QUARANTINE'],
                'severity' => 'S4',
                'incident_evaluation_required' => true,
            ],
            'REQUIRE_MORE_EVIDENCE' => [
                'verdict' => 'REQUIRE_MORE_EVIDENCE',
                'permitted_next_stage' => 'M05_ADDITIONAL_EVIDENCE_PATH',
                'reason_codes' => ['ARBITER_HMWH_REQUIRES_MORE_EVIDENCE'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
            // HOLD, or anything non-supportive, holds progression.
            default => [
                'verdict' => 'HOLD',
                'permitted_next_stage' => 'NO_PROGRESSION',
                'reason_codes' => ['ARBITER_HMWH_RECOMMENDED_HOLD'],
                'severity' => 'S2',
                'incident_evaluation_required' => false,
            ],
        };
    }

    /** A required council review that is MISSING holds — non-overridable. */
    public static function hmwhMissing(): array
    {
        return [
            'verdict' => 'HOLD',
            'permitted_next_stage' => 'NO_PROGRESSION',
            'reason_codes' => ['ARBITER_REQUIRED_HMWH_MISSING', 'MISSING_HMWH_REVIEW'],
            'severity' => 'S2',
            'incident_evaluation_required' => false,
        ];
    }

    /**
     * The terminal admissible verdict, reached only when EVERY check passed.
     *
     * A controlled demo action and a P07 CRM outreach execution both route to a human approval
     * (M09). For P07 that is the mandatory-approval decision: P07-A enables no external effect, the
     * approval is recorded through M09 against a synthetic adapter, and the actual send is P07-B —
     * which this projection does not implement.
     */
    public static function terminalVerdictForType(string $proposalType): array
    {
        if ($proposalType === 'CONTROLLED_DEMO_ACTION' || $proposalType === 'CRM_OUTREACH_EXECUTION') {
            return [
                'verdict' => 'REQUIRE_HUMAN_APPROVAL',
                'permitted_next_stage' => 'M09_APPROVAL_REQUEST_CREATION',
                'reason_codes' => ['ARBITER_HUMAN_APPROVAL_REQUIRED'],
                'severity' => null,
                'incident_evaluation_required' => false,
            ];
        }

        return [
            'verdict' => 'ALLOW_INFORMATIONAL_RELEASE',
            'permitted_next_stage' => 'M10_INTERNAL_RELEASE_RECORDING',
            'reason_codes' => ['ARBITER_ADMISSIBLE_FOR_INTERNAL_RELEASE'],
            'severity' => null,
            'incident_evaluation_required' => false,
        ];
    }

    /** Coerce a stored fail-closed verdict, defaulting to HOLD when the value is unrecognized. */
    public static function coerceVerdict(?string $value, string $fallback = 'HOLD'): string
    {
        return in_array((string) $value, self::VERDICT, true) ? (string) $value : $fallback;
    }

    /** The routing a failure effect implies. Every one routes away from release. */
    public static function failureEffectToResult(string $failureEffect, array $reasonCodes, string $fallback): array
    {
        $verdict = self::coerceVerdict($failureEffect, $fallback);

        return [
            'verdict' => $verdict,
            'permitted_next_stage' => match ($verdict) {
                'REQUIRE_MORE_EVIDENCE' => 'M05_ADDITIONAL_EVIDENCE_PATH',
                'REQUIRE_REVISION' => 'M04_REVISION_PATH',
                'REQUIRE_HUMAN_APPROVAL' => 'M09_APPROVAL_REQUEST_CREATION',
                'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION' => 'M11_INCIDENT_EVALUATION',
                default => 'NO_PROGRESSION',
            },
            'reason_codes' => $reasonCodes,
            'severity' => in_array($verdict, ['QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION'], true) ? 'S4' : 'S2',
            'incident_evaluation_required' => in_array($verdict, ['QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION'], true),
        ];
    }
}
