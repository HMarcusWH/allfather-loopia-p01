<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M02;

/**
 * The ONE tenant/workspace scope-compatibility law.
 *
 * Ported from `packages/allfather-control-substrate/src/m02/scope.ts` (TF7-06)
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Several upstream authority joins had verified the tenant and then ignored a non-null workspace,
 * which inside a single tenant let a W1-scoped source, catalogue or mission be consumed into W2
 * lineage — and the resulting object then carried W2 provenance even though part of its ancestry
 * was W1-only.
 *
 * The law has three clauses and no exceptions:
 *  1. A foreign tenant is ALWAYS refused. Missing and foreign must be INDISTINGUISHABLE to the
 *     caller, so callers map both onto the same closed refusal — never a "not found" that confirms
 *     the object exists somewhere else.
 *  2. A non-null object workspace requires an EXACT workspace match.
 *  3. A null object workspace means tenant-wide, and is lawful ONLY for object classes whose
 *     doctrine says so. Every call states that explicitly; there is no permissive default, because
 *     "NULL matches everything" is how cross-workspace leakage gets reintroduced.
 *
 * This class has no dependencies and no side effects on purpose: it is a predicate, so it can be
 * applied at every authority join without dragging a dependency along.
 */
final class Scope
{
    public const FOREIGN_TENANT = 'FOREIGN_TENANT';
    public const FOREIGN_WORKSPACE = 'FOREIGN_WORKSPACE';
    public const TENANT_WIDE_SCOPE_NOT_PERMITTED = 'TENANT_WIDE_SCOPE_NOT_PERMITTED';

    /**
     * @return array{compatible: true}|array{compatible: false, reason_code: string}
     */
    public static function evaluate(
        ?string $objectTenantId,
        ?string $objectWorkspaceId,
        string $requestedTenantId,
        ?string $requestedWorkspaceId,
        bool $tenantWideObjectPermitted,
    ): array {
        // A null object tenant is not tenant-wide authority anywhere in this build: every scoped
        // object class carries a NOT NULL tenant. Treat it as foreign rather than inventing a
        // wildcard.
        if ($objectTenantId === null || $objectTenantId !== $requestedTenantId) {
            return ['compatible' => false, 'reason_code' => self::FOREIGN_TENANT];
        }

        if ($objectWorkspaceId === null) {
            return $tenantWideObjectPermitted
                ? ['compatible' => true]
                : ['compatible' => false, 'reason_code' => self::TENANT_WIDE_SCOPE_NOT_PERMITTED];
        }

        if ($requestedWorkspaceId === null || $objectWorkspaceId !== $requestedWorkspaceId) {
            return ['compatible' => false, 'reason_code' => self::FOREIGN_WORKSPACE];
        }

        return ['compatible' => true];
    }

    public static function isCompatible(
        ?string $objectTenantId,
        ?string $objectWorkspaceId,
        string $requestedTenantId,
        ?string $requestedWorkspaceId,
        bool $tenantWideObjectPermitted = false,
    ): bool {
        return self::evaluate(
            $objectTenantId,
            $objectWorkspaceId,
            $requestedTenantId,
            $requestedWorkspaceId,
            $tenantWideObjectPermitted,
        )['compatible'];
    }
}
