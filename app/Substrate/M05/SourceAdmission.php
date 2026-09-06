<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M05;

use Allfather\Loopia\Substrate\M02\Scope;

/**
 * The ONE source-admissibility law (TF7-05).
 *
 * Ported from `packages/allfather-control-substrate/src/m05/spine.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Owned by M05 because M05 is the single evidence-capture authority. Producers may add STRONGER
 * restrictions; none can bypass this minimum, because it runs inside the capture command itself
 * rather than in each producer's preamble.
 *
 * The four clauses:
 *  - **STATUS** — only `ACTIVE_FOR_CAPTURE` admits capture. `REGISTERED` (not yet admitted),
 *    `RESTRICTED`, `REVOKED` and `QUARANTINED` all refuse, and the status is re-read AT CAPTURE
 *    TIME, so a source revoked after a plan was made stops admitting immediately.
 *  - **SCOPE** — the shared tenant/workspace law. A NULL source workspace IS lawful tenant-wide
 *    authority for this object class (the column is nullable by design); a non-null one must match.
 *  - **USE SCOPE** — a source that declares a lawful/permitted use scope BINDS the evidence to it.
 *    The producer does not choose the label freely; canonical parent state decides it. A source
 *    declaring no scope cannot derive one — the producer must state it, or there is nothing to
 *    record about how the evidence may lawfully be used.
 *  - **SENSITIVITY** — evidence may never be classified BELOW its source. `PROHIBITED_IN_V1` is
 *    refused outright.
 */
final class SourceAdmission
{
    public const EVIDENCE_SOURCE_REFERENCE_MISSING = 'EVIDENCE_SOURCE_REFERENCE_MISSING';
    public const EVIDENCE_SCOPE_MISMATCH = 'EVIDENCE_SCOPE_MISMATCH';
    public const EVIDENCE_PROHIBITED_SENSITIVITY = 'EVIDENCE_PROHIBITED_SENSITIVITY';
    public const EVIDENCE_SOURCE_NOT_ADMISSIBLE = 'EVIDENCE_SOURCE_NOT_ADMISSIBLE';
    public const EVIDENCE_USE_SCOPE_INCOMPATIBLE = 'EVIDENCE_USE_SCOPE_INCOMPATIBLE';
    public const EVIDENCE_SENSITIVITY_DOWNGRADE = 'EVIDENCE_SENSITIVITY_DOWNGRADE';
    public const EVIDENCE_HOST_PROVENANCE_INVALID = 'EVIDENCE_HOST_PROVENANCE_INVALID';
    public const EVIDENCE_HOST_OUTCOME_NOT_SUCCEEDED = 'EVIDENCE_HOST_OUTCOME_NOT_SUCCEEDED';
    public const EVIDENCE_HOST_OUTCOME_NOT_CAPTURE_CANDIDATE = 'EVIDENCE_HOST_OUTCOME_NOT_CAPTURE_CANDIDATE';
    public const EVIDENCE_CONTENT_NOT_BOUND_TO_EXECUTION = 'EVIDENCE_CONTENT_NOT_BOUND_TO_EXECUTION';
    public const EVIDENCE_RAW_ARTIFACT_NOT_BOUND = 'EVIDENCE_RAW_ARTIFACT_NOT_BOUND';

    /** Ordered least- to most-sensitive. Evidence may never be classified below its source. */
    public const SENSITIVITY_ORDER = [
        'PUBLIC_OR_NON_SENSITIVE',
        'INTERNAL_DEMO',
        'RESTRICTED_REVIEW',
        'PROHIBITED_IN_V1',
    ];

    /** An unknown class sorts as RESTRICTED_REVIEW — fail closed, NEVER as "public". */
    public static function sensitivityRank(string $value): int
    {
        $index = array_search($value, self::SENSITIVITY_ORDER, true);

        return $index === false
            ? (int) array_search('RESTRICTED_REVIEW', self::SENSITIVITY_ORDER, true)
            : $index;
    }

    /**
     * @param array<string, mixed> $source a `source_references` row
     * @param ?string $permittedUseScope null means "derive from the source" — never "unconstrained"
     * @return array{admitted: true}|array{admitted: false, reason_code: string}
     */
    public static function evaluate(
        array $source,
        string $tenantId,
        string $workspaceId,
        ?string $permittedUseScope,
        string $sensitivityClass,
    ): array {
        $scope = Scope::evaluate(
            $source['tenant_id'] === null ? null : (string) $source['tenant_id'],
            $source['workspace_id'] === null ? null : (string) $source['workspace_id'],
            $tenantId,
            $workspaceId,
            // A source_references row may legitimately be tenant-wide (workspace_id is nullable).
            tenantWideObjectPermitted: true,
        );
        if (!$scope['compatible']) {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_SCOPE_MISMATCH];
        }

        if ((string) $source['content_sensitivity_class'] === 'PROHIBITED_IN_V1') {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_PROHIBITED_SENSITIVITY];
        }

        if ((string) $source['status'] !== 'ACTIVE_FOR_CAPTURE') {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_SOURCE_NOT_ADMISSIBLE];
        }

        $sourceScope = $source['lawful_or_permitted_use_scope'] === null
            ? null
            : (string) $source['lawful_or_permitted_use_scope'];

        // A producer may RESTATE the source's scope (it must then match exactly) but may not
        // invent a different one.
        if ($permittedUseScope !== null && $sourceScope !== null && $sourceScope !== $permittedUseScope) {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_USE_SCOPE_INCOMPATIBLE];
        }
        if ($permittedUseScope === null && $sourceScope === null) {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_USE_SCOPE_INCOMPATIBLE];
        }

        if (self::sensitivityRank($sensitivityClass) < self::sensitivityRank((string) $source['content_sensitivity_class'])) {
            return ['admitted' => false, 'reason_code' => self::EVIDENCE_SENSITIVITY_DOWNGRADE];
        }

        return ['admitted' => true];
    }

    /** The RESOLVED use scope: the source's declared authority when it has one, else the producer's. */
    public static function resolveUseScope(array $source, ?string $permittedUseScope): string
    {
        $sourceScope = $source['lawful_or_permitted_use_scope'] ?? null;

        return $sourceScope !== null ? (string) $sourceScope : (string) $permittedUseScope;
    }
}
