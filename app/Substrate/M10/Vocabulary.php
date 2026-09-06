<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

/**
 * M10 decision-ledger vocabulary.
 *
 * Ported verbatim from `packages/allfather-control-substrate/src/m10/ledger.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * These values are stored, hashed into the record payload digest, and read back by every
 * downstream visibility and reliance check, so a rename would break both the chain and the
 * conformance transcript.
 */
final class Vocabulary
{
    public const RECORD_TYPE = [
        'INTERNAL_INFORMATIONAL_RELEASE',
        'PROVISIONAL_ANALYSIS_DISPLAY',
        'PROPOSAL_HELD',
        'PROPOSAL_BLOCKED',
        'PROPOSAL_QUARANTINED',
        'INCIDENT_EVALUATION_ROUTED',
        'TOOL_OPERATION_DENIED_NON_DISPATCH',
        'TOOL_OPERATION_FAILED_OR_UNKNOWN',
        'HARMLESS_DEMO_ACTION_COMPLETED',
        'HARMLESS_DEMO_ACTION_PREVENTED',
        'HARMLESS_DEMO_ACTION_FAILED',
        'HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN',
        'APPROVAL_OR_AUTHORIZATION_REVOKED',
        'REPLAY_TEST_FINDING_RECORDED',
        'AUDIT_FINDING_LINKED',
        'POLICY_OR_PROVENANCE_BLOCK_RECORDED',
        'DECISION_CORRECTION_OR_INVALIDATION',
        'SOURCE_CONFIG_APPROVED',
        'SOURCE_CONFIG_SUPERSEDED',
    ];

    public const MATERIALITY_CLASS = [
        'GOVERNANCE_MATERIAL', 'ACTION_MATERIAL', 'SECURITY_MATERIAL',
        'AUDIT_MATERIAL', 'REPLAY_TEST_MATERIAL',
    ];

    public const PRIMARY_OUTCOME_STATUS = [
        'RELEASED_INTERNAL', 'DISPLAYED_PROVISIONAL', 'HELD', 'BLOCKED', 'QUARANTINED',
        'INCIDENT_ROUTED', 'DENIED_NON_DISPATCH', 'EXECUTED_SUCCESS', 'EXECUTION_PREVENTED',
        'EXECUTION_FAILED', 'OUTCOME_UNKNOWN', 'REVOKED', 'REPLAY_RECORDED',
        'CORRECTED_OR_INVALIDATED',
    ];

    /**
     * Reliance status. `ACTIVE_AS_RECORDED` is the ONLY value that supports current reliance;
     * everything else means the record is history rather than a live basis for a decision.
     */
    public const RELIANCE_STATUS = [
        'ACTIVE_AS_RECORDED', 'HISTORICAL_ONLY', 'SUPERSEDED',
        'INVALIDATED_FOR_CURRENT_RELIANCE', 'UNDER_REVIEW',
    ];

    public const CURRENT_RELIANCE = 'ACTIVE_AS_RECORDED';

    public const REFERENCE_ROLE = [
        'AUTHORITY_BASIS', 'MISSION_BASIS', 'PROPOSAL_BASIS', 'EVIDENCE_BASIS', 'REVIEW_BASIS',
        'VERDICT_BASIS', 'APPROVAL_BASIS', 'ARTIFACT_BASIS', 'AUTHORIZATION_BASIS',
        'TOOL_DECISION_BASIS', 'DISPATCH_BASIS', 'OUTCOME_BASIS', 'CONDITION_SATISFACTION',
        'INCIDENT_OR_AUDIT_BASIS', 'DISPLAY_OR_EXPORT_REFERENCE', 'OTHER',
    ];

    public const OWNER_MODULE_ID = ['M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11', 'M12'];

    public const VERIFICATION_STATUS = [
        'VERIFIED_REFERENCE', 'PRESENT_UNVERIFIED', 'REQUIRED_BUT_MISSING', 'RESTRICTED_REFERENCE',
    ];

    /** Only a VERIFIED_REFERENCE satisfies a mandatory basis role (M10-DEC-006). */
    public const VERIFIED_REFERENCE = 'VERIFIED_REFERENCE';

    /**
     * Type-specific required basis roles (M10-DEC-006).
     *
     * A record of these types may not be appended unless EVERY required role is present among its
     * input references AND each is a VERIFIED_REFERENCE. Other record types still require at least
     * one canonical reference.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_REFERENCE_ROLES = [
        'INTERNAL_INFORMATIONAL_RELEASE' => ['VERDICT_BASIS', 'PROPOSAL_BASIS'],
        'HARMLESS_DEMO_ACTION_COMPLETED' => [
            'VERDICT_BASIS', 'APPROVAL_BASIS', 'ARTIFACT_BASIS', 'AUTHORIZATION_BASIS', 'OUTCOME_BASIS',
        ],
    ];
}
