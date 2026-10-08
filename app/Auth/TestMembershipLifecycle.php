<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\{AccessGuard,Permissions};

/**
 * P09 TEST-only exception: bounded, explicitly reviewed operator memberships.
 *
 * This is NOT an alternate beta-onboarding path, production authority, or an owner
 * authorization decision. The private CLI additionally requires APP_ENV=testing/staging.
 * Plans bind actual roles/permissions/scope/window and the prior membership state.
 * Digest equality is concurrency/staleness protection, NEVER evidence of owner approval.
 */
final class TestMembershipLifecycle
{
    public const MAX_WINDOW_SECONDS = 86400;

    /** No custom role, arbitrary role successor, system identity or approval seat. */
    private const ALLOWED = [
        'ROLE_TENANT_ADMIN' => [2],
        'ROLE_WORKSPACE_OPERATOR' => [1],
        'ROLE_INTELLIGENCE_DISTRIBUTOR' => [1],
        'ROLE_COMPETITION_MONITOR' => [1],
        'ROLE_ENTITY_TWIN_CURATOR' => [1],
        'ROLE_LEAD_QUALIFIER' => [1],
    ];

    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly string $appEnvironment) {}

    /** Validate a finite 24h TEST window independently of any database. */
    public static function validateWindow(string $from, string $until, string $now): Outcome
    {
        $start = Clock::parse($from);
        $end = Clock::parse($until);
        $current = Clock::parse($now);
        if ($start === null || $end === null || $current === null) {
            return Outcome::refuse('P09_TEST_WINDOW_NOT_CANONICAL');
        }
        $duration = (float) $end->format('U.u') - (float) $start->format('U.u');
        $before = (float) $start->format('U.u') - (float) $current->format('U.u');
        $remaining = (float) $end->format('U.u') - (float) $current->format('U.u');
        if ($duration <= 0 || $duration > self::MAX_WINDOW_SECONDS ||
            $before < -self::MAX_WINDOW_SECONDS || $before > self::MAX_WINDOW_SECONDS ||
            $remaining <= 0) {
            return Outcome::refuse('P09_TEST_WINDOW_OUT_OF_BOUNDS');
        }
        return Outcome::ok(['valid_from' => $from, 'valid_until' => $until]);
    }

    /** @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope */
    public function planAssign(
        array $scope,
        string $targetOperatorId,
        string $roleKey,
        int $roleVersion,
        string $validFrom,
        string $validUntil,
    ): Outcome {
        $context = $this->context($scope, 'role.assign');
        if (!$context->isOk()) return $context;
        if (!Ids::isValid($targetOperatorId) || $targetOperatorId === $scope['operator_id']) {
            return Outcome::refuse('P09_TEST_TARGET_INVALID_OR_SELF');
        }
        $window = self::validateWindow($validFrom, $validUntil, $this->clock->now());
        if (!$window->isOk()) return $window;
        $role = $this->role($roleKey, $roleVersion);
        if (!$role->isOk()) return $role;

        $target = $this->db->one(
            'SELECT status FROM operators WHERE operator_id=? AND tenant_id=?',
            [$targetOperatorId, $scope['tenant_id']]
        );
        if ($target === null || (string) $target['status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_TEST_TARGET_NOT_ACTIVE_IN_TENANT');
        }

        // The legacy unbounded path, withdrawn rows, and duplicate membership rows
        // are never silently converted, extended or resurrected.
        $existing = $this->db->all(
            "SELECT membership_id, status, valid_from, valid_until FROM workspace_memberships
             WHERE tenant_id=? AND workspace_id=? AND principal_type='OPERATOR'
               AND principal_id=? AND role_id=? ORDER BY membership_id",
            [$scope['tenant_id'], $scope['workspace_id'], $targetOperatorId, $role->value()['role_id']]
        );
        if ($existing !== []) {
            return Outcome::refuse('P09_TEST_MEMBERSHIP_ALREADY_EXISTS', [
                'membership_count' => count($existing),
            ]);
        }

        $definition = [
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'assigner_operator_id' => $scope['operator_id'],
            'target_operator_id' => $targetOperatorId,
            'role_key' => $roleKey,
            'role_id' => $role->value()['role_id'],
            'role_version' => $roleVersion,
            'permissions' => $role->value()['permissions'],
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'existing' => 'ABSENT',
        ];
        return Outcome::ok([
            'action' => 'CREATE_BOUNDED_TEST_MEMBERSHIP',
            'definition' => $definition,
            'plan_digest' => Digest::canonicalDigest(['operation' => 'P09_TEST_ASSIGN', 'definition' => $definition]),
            'max_window_seconds' => self::MAX_WINDOW_SECONDS,
            'grants_model_execution' => false,
            'admits_schedule' => false,
            'owner_authorization_verified' => false,
        ]);
    }

    /** Only an independently reviewed, exact plan should ever be applied. */
    public function assign(
        array $scope,
        string $targetOperatorId,
        string $roleKey,
        int $roleVersion,
        string $validFrom,
        string $validUntil,
        string $reviewedPlanDigest,
    ): Outcome {
        if (!self::digestValid($reviewedPlanDigest)) {
            return Outcome::refuse('P09_TEST_PLAN_DIGEST_REQUIRED');
        }
        return $this->db->transaction(function (Database $tx) use (
            $scope, $targetOperatorId, $roleKey, $roleVersion, $validFrom, $validUntil, $reviewedPlanDigest
        ): Outcome {
            // One tenant lock serializes competing bounded membership operations.
            $tenant = $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id'] ?? '']);
            if ($tenant === null) return Outcome::refuse('P09_TEST_SCOPE_NOT_FOUND');
            $plan = $this->planAssign($scope, $targetOperatorId, $roleKey, $roleVersion, $validFrom, $validUntil);
            if (!$plan->isOk()) return $plan;
            $p = $plan->value();
            if (!hash_equals($p['plan_digest'], $reviewedPlanDigest)) {
                return Outcome::refuse('P09_TEST_PLAN_CHANGED');
            }
            $d = $p['definition'];
            $id = Ids::new();
            $tx->insert('workspace_memberships', [
                'membership_id' => $id,
                'tenant_id' => $d['tenant_id'],
                'workspace_id' => $d['workspace_id'],
                'principal_type' => 'OPERATOR',
                'principal_id' => $d['target_operator_id'],
                'role_id' => $d['role_id'],
                'role_version' => $d['role_version'],
                'status' => 'ACTIVE',
                'valid_from' => $d['valid_from'],
                'valid_until' => $d['valid_until'],
                'created_at' => $this->clock->now(),
            ]);
            // Retained ALLOW evaluation identifies the assigning human and target.
            $guard = new AccessGuard($tx, $this->clock);
            $record = $guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'],
                $scope['workspace_id'], 'role.assign', 'P09_TEST_MEMBERSHIP', $id, null, true);
            if ($record['decision'] !== 'ALLOW') {
                return Outcome::refuse('P09_TEST_ASSIGNER_AUTHORITY_CHANGED');
            }
            return Outcome::ok([
                'membership_id' => $id, 'status' => 'ACTIVE',
                'role_key' => $d['role_key'], 'role_version' => $d['role_version'],
                'target_operator_id' => $d['target_operator_id'],
                'valid_from' => $d['valid_from'], 'valid_until' => $d['valid_until'],
                'plan_digest' => $p['plan_digest'], 'grants_model_execution' => false,
                'admits_schedule' => false,
            ]);
        });
    }

    /** Bind a single finite operator seat by ID before revoking it. */
    public function planRevoke(array $scope, string $membershipId): Outcome
    {
        $context = $this->context($scope, 'role.revoke');
        if (!$context->isOk()) return $context;
        if (!Ids::isValid($membershipId)) return Outcome::refuse('P09_TEST_MEMBERSHIP_ID_INVALID');
        $row = $this->db->one(
            "SELECT m.membership_id, m.principal_id, m.role_id, m.role_version,
                    m.status, m.valid_from, m.valid_until, r.role_key,
                    r.role_version AS defined_role_version
             FROM workspace_memberships m JOIN roles r ON r.role_id=m.role_id
             WHERE m.membership_id=? AND m.tenant_id=? AND m.workspace_id=?
               AND m.principal_type='OPERATOR'",
            [$membershipId, $scope['tenant_id'], $scope['workspace_id']]
        );
        if ($row === null || $row['valid_until'] === null) {
            return Outcome::refuse('P09_TEST_MEMBERSHIP_NOT_BOUNDED_IN_SCOPE');
        }
        // Revocation removes rights; never make stand-down depend on the
        // role still being ACTIVE or its permission set remaining pristine.
        // Only a previously adopted key/version and exact role binding qualify.
        if (!in_array((int) $row['role_version'], self::ALLOWED[(string) $row['role_key']] ?? [], true)
            || (int) $row['defined_role_version'] !== (int) $row['role_version']) {
            return Outcome::refuse('P09_TEST_ROLE_NOT_ADOPTED');
        }
        if ((string) $row['status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_TEST_MEMBERSHIP_NOT_ACTIVE', ['status' => (string) $row['status']]);
        }
        $definition = [
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'revoker_operator_id' => $scope['operator_id'],
            'membership_id' => $membershipId,
            'target_operator_id' => (string) $row['principal_id'],
            'role_id' => (string) $row['role_id'],
            'role_key' => (string) $row['role_key'],
            'role_version' => (int) $row['role_version'],
            'valid_from' => (string) $row['valid_from'],
            'valid_until' => (string) $row['valid_until'],
            'current_status' => (string) $row['status'],
            'next_status' => 'REVOKED',
        ];
        return Outcome::ok([
            'action' => 'REVOKE_BOUNDED_TEST_MEMBERSHIP',
            'definition' => $definition,
            'plan_digest' => Digest::canonicalDigest(['operation' => 'P09_TEST_REVOKE', 'definition' => $definition]),
            'owner_authorization_verified' => false,
        ]);
    }

    public function revoke(array $scope, string $membershipId, string $reviewedPlanDigest): Outcome
    {
        if (!self::digestValid($reviewedPlanDigest)) return Outcome::refuse('P09_TEST_PLAN_DIGEST_REQUIRED');
        return $this->db->transaction(function (Database $tx) use ($scope, $membershipId, $reviewedPlanDigest): Outcome {
            $tenant = $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id'] ?? '']);
            if ($tenant === null) return Outcome::refuse('P09_TEST_SCOPE_NOT_FOUND');
            $plan = $this->planRevoke($scope, $membershipId);
            if (!$plan->isOk()) return $plan;
            if (!hash_equals((string) $plan->value()['plan_digest'], $reviewedPlanDigest)) {
                return Outcome::refuse('P09_TEST_PLAN_CHANGED');
            }
            // Record the revoker's authority BEFORE withdrawing a possible last
            // self-held membership; otherwise legitimate self stand-down fails.
            $guard = new AccessGuard($tx, $this->clock);
            $record = $guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'],
                $scope['workspace_id'], 'role.revoke', 'P09_TEST_MEMBERSHIP', $membershipId, null, true);
            if ($record['decision'] !== 'ALLOW') {
                return Outcome::refuse('P09_TEST_REVOKER_AUTHORITY_CHANGED');
            }
            $changed = $tx->run(
                "UPDATE workspace_memberships SET status='REVOKED'
                 WHERE membership_id=? AND tenant_id=? AND workspace_id=?
                   AND principal_type='OPERATOR' AND status='ACTIVE' AND valid_until IS NOT NULL",
                [$membershipId, $scope['tenant_id'], $scope['workspace_id']]
            );
            if ($changed !== 1) return Outcome::refuse('P09_TEST_MEMBERSHIP_STATE_CHANGED');
            return Outcome::ok([
                'membership_id' => $membershipId, 'status' => 'REVOKED',
                'plan_digest' => $reviewedPlanDigest,
                'grants_model_execution' => false, 'admits_schedule' => false,
            ]);
        });
    }

    private function context(array $scope, string $permission): Outcome
    {
        if (!in_array($this->appEnvironment, ['staging', 'testing'], true)) {
            return Outcome::refuse('P09_TEST_ENVIRONMENT_REQUIRED');
        }
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!isset($scope[$key]) || !is_string($scope[$key]) || !Ids::isValid($scope[$key])) {
                return Outcome::refuse('P09_TEST_SCOPE_INVALID');
            }
        }
        $row = $this->db->one(
            'SELECT t.tenant_type, t.status AS tenant_status,
                    w.workspace_type, w.environment_class, w.external_side_effects_allowed,
                    w.status AS workspace_status
             FROM workspaces w JOIN tenants t ON t.tenant_id=w.tenant_id
             WHERE w.tenant_id=? AND w.workspace_id=?',
            [$scope['tenant_id'], $scope['workspace_id']]
        );
        if ($row === null ||
            (string) $row['tenant_type'] !== 'TEST' ||
            (string) $row['tenant_status'] !== 'ACTIVE' ||
            (string) $row['workspace_type'] !== 'TEST' ||
            (string) $row['environment_class'] !== 'TEST' ||
            (int) $row['external_side_effects_allowed'] !== 0 ||
            (string) $row['workspace_status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_TEST_SCOPE_NOT_ISOLATED');
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach (['tenant.administer', $permission] as $key) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'],
                $scope['workspace_id'], $key, 'P09_TEST_MEMBERSHIP')['decision'] !== 'ALLOW') {
                return Outcome::refuse('P09_TEST_MEMBERSHIP_ADMIN_DENIED');
            }
        }
        return Outcome::ok(true);
    }

    private function role(string $roleKey, int $version): Outcome
    {
        if (!in_array($version, self::ALLOWED[$roleKey] ?? [], true)) {
            return Outcome::refuse('P09_TEST_ROLE_NOT_ADOPTED');
        }
        $expected = null;
        foreach (Permissions::roleVersionDefinitions() as $definition) {
            if ($definition['role_key'] === $roleKey && $definition['role_version'] === $version) {
                $expected = $definition;
                break;
            }
        }
        if ($expected === null) return Outcome::refuse('P09_TEST_ROLE_NOT_ADOPTED');
        $role = $this->db->one(
            "SELECT role_id, display_name FROM roles
             WHERE role_key=? AND role_version=? AND status='ACTIVE'",
            [$roleKey, $version]
        );
        if ($role === null || $role['display_name'] !== $expected['display_name']) {
            return Outcome::refuse('P09_TEST_ROLE_VOCABULARY_CONFLICT');
        }
        $actual = array_column($this->db->all(
            'SELECT permission_key FROM role_permissions WHERE role_id=? ORDER BY permission_key',
            [$role['role_id']]
        ), 'permission_key');
        $permissions = $expected['permissions'];
        sort($actual, SORT_STRING);
        sort($permissions, SORT_STRING);
        if ($actual !== $permissions) return Outcome::refuse('P09_TEST_ROLE_VOCABULARY_CONFLICT');
        return Outcome::ok(['role_id' => (string) $role['role_id'], 'permissions' => $permissions]);
    }

    private static function digestValid(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }
}
