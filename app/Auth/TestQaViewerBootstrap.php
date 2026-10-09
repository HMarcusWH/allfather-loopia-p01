<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\Permissions;

/**
 * P09 exact-owner-signed QA VIEWER exception for an existing unbound TEST operator.
 *
 * No actor may assert that a digest is owner approval. Applying the one-hour
 * canonical read-only DemoViewer-v1 membership requires an Ed25519 signature from the
 * independently enrolled owner verification key. The private CLI, not this
 * library, controls the trust anchor and prevents caller-selected keys.
 *
 * Each operator's retained membership history prevents reusing that recipient, including REVOKED/EXPIRED rows. A different operator needs a different owner-signed plan. This is not a production
 * or beta onboarding mechanism; it never creates a user or a credential.
 */
final class TestQaViewerBootstrap
{
    public const MAX_WINDOW_SECONDS = 3600;
    public const SIGNATURE_DOMAIN = "P09_TEST_QA_VIEWER_V2\n";

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $appEnvironment,
    ) {}

    public static function signatureMessage(string $digest): string
    {
        return self::SIGNATURE_DOMAIN . $digest . "\n";
    }

    /** @param array{tenant_id:string,workspace_id:string} $scope */
    public function plan(array $scope, string $operatorId, string $validFrom, string $validUntil): Outcome
    {
        if (!in_array($this->appEnvironment, ['staging', 'testing'], true)) {
            return Outcome::refuse('P09_BOOTSTRAP_ENVIRONMENT_REQUIRED');
        }
        foreach (['tenant_id', 'workspace_id'] as $key) {
            if (!isset($scope[$key]) || !is_string($scope[$key]) || !Ids::isValid($scope[$key])) {
                return Outcome::refuse('P09_BOOTSTRAP_SCOPE_INVALID');
            }
        }
        if (!Ids::isValid($operatorId)) return Outcome::refuse('P09_BOOTSTRAP_OPERATOR_INVALID');

        $row = $this->db->one(
            'SELECT t.tenant_type, t.status AS tenant_status, w.workspace_type,
                    w.environment_class, w.external_side_effects_allowed,
                    w.status AS workspace_status
             FROM workspaces w JOIN tenants t ON t.tenant_id=w.tenant_id
             WHERE w.tenant_id=? AND w.workspace_id=?',
            [$scope['tenant_id'], $scope['workspace_id']]
        );
        if ($row === null || $row['tenant_type'] !== 'TEST' ||
            $row['tenant_status'] !== 'ACTIVE' || $row['workspace_type'] !== 'TEST' ||
            $row['environment_class'] !== 'TEST' ||
            (int) $row['external_side_effects_allowed'] !== 0 ||
            $row['workspace_status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_BOOTSTRAP_NOT_ISOLATED_TEST');
        }

        $window = TestMembershipLifecycle::validateWindow($validFrom, $validUntil, $this->clock->now());
        if (!$window->isOk()) return Outcome::refuse('P09_BOOTSTRAP_WINDOW_INVALID');
        $start = Clock::parse($validFrom);
        $end = Clock::parse($validUntil);
        $now = Clock::parse($this->clock->now());
        if ($start === null || $end === null || $now === null) {
            return Outcome::refuse('P09_BOOTSTRAP_WINDOW_INVALID');
        }
        // Integer milliseconds; never round a +1ms overrun back to exactly 1h.
        $toMs = static fn (\DateTimeImmutable $d): int =>
            ((int) $d->format('U') * 1000) + (int) $d->format('v');
        $offsetMs = $toMs($start) - $toMs($now);
        $durationMs = $toMs($end) - $toMs($start);
        if (abs($offsetMs) > 300000 || $durationMs <= 0 ||
            $durationMs > self::MAX_WINDOW_SECONDS * 1000) {
            return Outcome::refuse('P09_BOOTSTRAP_WINDOW_INVALID');
        }

        $operator = $this->db->one(
            'SELECT status FROM operators WHERE operator_id=? AND tenant_id=?',
            [$operatorId, $scope['tenant_id']]
        );
        if ($operator === null || $operator['status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_BOOTSTRAP_OPERATOR_NOT_ACTIVE');
        }
        // Never attach a QA viewer to a historical credential, retired
        // login, or operator with any pre-existing (including foreign) seat.
        if ((int) $this->db->scalar(
            'SELECT COUNT(*) FROM platform_users WHERE operator_id=?', [$operatorId]
        ) !== 0 || (int) $this->db->scalar(
            "SELECT COUNT(*) FROM workspace_memberships
             WHERE principal_type='OPERATOR' AND principal_id=?", [$operatorId]
        ) !== 0) {
            return Outcome::refuse('P09_BOOTSTRAP_OPERATOR_NOT_EMPTY');
        }

        // No TenantAdmin bootstrap at all: only the recipient's immutable
        // empty membership/login history is required. Each further recipient
        // demands a different owner-signed, exact plan.
        $expected = null;
        foreach (Permissions::roleVersionDefinitions() as $definition) {
            if ($definition['role_key'] === 'ROLE_DEMO_VIEWER' && $definition['role_version'] === 1) {
                $expected = $definition;
                break;
            }
        }
        if ($expected === null) return Outcome::refuse('P09_BOOTSTRAP_ROLE_UNAVAILABLE');
        $role = $this->db->one(
            "SELECT role_id,display_name FROM roles
             WHERE role_key='ROLE_DEMO_VIEWER' AND role_version=1 AND status='ACTIVE'"
        );
        if ($role === null || $role['display_name'] !== $expected['display_name']) {
            return Outcome::refuse('P09_BOOTSTRAP_ROLE_DRIFT');
        }
        $actual = array_column($this->db->all(
            'SELECT permission_key FROM role_permissions WHERE role_id=? ORDER BY permission_key',
            [$role['role_id']]
        ), 'permission_key');
        $allowed = $expected['permissions'];
        sort($allowed, SORT_STRING);
        sort($actual, SORT_STRING);
        // This exception is read-only, not a temporary administrator.
        // Validate the complete canonical permission set AND a pinned
        // independent allowlist so that a future canonical role successor
        // cannot silently add any write/delegation/execution authority.
        $readOnly = ['decision.read', 'internal_release.read', 'workspace.read'];
        sort($readOnly, SORT_STRING);
        if ($actual !== $allowed || $allowed !== $readOnly) {
            return Outcome::refuse('P09_BOOTSTRAP_ROLE_DRIFT');
        }

        $definition = [
            // Bind owner authorization to the exact TEST runtime class so a
            // signature made in testing is not replayable against staging.
            'app_environment' => $this->appEnvironment,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'operator_id' => $operatorId,
            'role_key' => 'ROLE_DEMO_VIEWER',
            'role_version' => 1,
            'role_id' => (string) $role['role_id'],
            'permissions' => $allowed,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'existing_operator_memberships' => 0,
        ];
        return Outcome::ok([
            'action' => 'SIGNED_BOUNDED_TEST_QA_VIEWER',
            'definition' => $definition,
            'plan_digest' => Digest::canonicalDigest(['operation' => 'P09_TEST_QA_VIEWER_V2', 'definition' => $definition]),
            'signature_domain' => 'P09_TEST_QA_VIEWER_V2',
            'max_window_seconds' => self::MAX_WINDOW_SECONDS,
            'owner_signature_verified' => false,
            'creates_login' => false,
            'grants_model_execution' => false,
            'admits_schedule' => false,
        ]);
    }

    /** Apply only after independent approval of the EXACT plan digest. */
    public function apply(
        array $scope,
        string $operatorId,
        string $validFrom,
        string $validUntil,
        string $reviewedPlanDigest,
        string $signatureB64,
        string $trustedOwnerPublicKeyB64,
    ): Outcome {
        if (preg_match('/^[a-f0-9]{64}$/D', $reviewedPlanDigest) !== 1) {
            return Outcome::refuse('P09_BOOTSTRAP_PLAN_DIGEST_INVALID');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return Outcome::refuse('P09_BOOTSTRAP_SIGNATURE_ENGINE_UNAVAILABLE');
        }
        $signature = base64_decode(trim($signatureB64), true);
        $verifyKey = base64_decode(trim($trustedOwnerPublicKeyB64), true);
        if ($signature === false || $verifyKey === false ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            strlen($verifyKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !sodium_crypto_sign_verify_detached(
                $signature, self::signatureMessage($reviewedPlanDigest), $verifyKey
            )) {
            return Outcome::refuse('P09_BOOTSTRAP_OWNER_SIGNATURE_INVALID');
        }

        return $this->db->transaction(function (Database $tx) use (
            $scope, $operatorId, $validFrom, $validUntil, $reviewedPlanDigest, $signature
        ): Outcome {
            $tenant = $tx->one(
                'SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',
                [$scope['tenant_id'] ?? '']
            );
            if ($tenant === null) return Outcome::refuse('P09_BOOTSTRAP_SCOPE_NOT_FOUND');
            $plan = $this->plan($scope, $operatorId, $validFrom, $validUntil);
            if (!$plan->isOk()) return $plan;
            $p = $plan->value();
            if (!hash_equals($p['plan_digest'], $reviewedPlanDigest)) {
                return Outcome::refuse('P09_BOOTSTRAP_PLAN_CHANGED');
            }
            $d = $p['definition'];
            $id = Ids::new();
            $tx->insert('workspace_memberships', [
                'membership_id' => $id,
                'tenant_id' => $d['tenant_id'],
                'workspace_id' => $d['workspace_id'],
                'principal_type' => 'OPERATOR',
                'principal_id' => $d['operator_id'],
                'role_id' => $d['role_id'],
                'role_version' => 1,
                'status' => 'ACTIVE',
                'valid_from' => $d['valid_from'],
                'valid_until' => $d['valid_until'],
                'created_at' => $this->clock->now(),
            ]);
            return Outcome::ok([
                'membership_id' => $id,
                'target_operator_id' => $operatorId,
                'role_key' => 'ROLE_DEMO_VIEWER',
                'role_version' => 1,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'plan_digest' => $reviewedPlanDigest,
                'owner_signature_sha256' => hash('sha256', $signature),
                'owner_signature_verified' => true,
                'creates_login' => false,
                'grants_model_execution' => false,
                'admits_schedule' => false,
            ]);
        });
    }
}
