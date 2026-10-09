<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\Permissions;

/**
 * P09 login-only follow-up to a separately owner-signed, already-applied
 * DemoViewer-v1 TEST membership. No operator, membership or subscription writes.
 * Two independent owner signatures are required: the ORIGINAL viewer approval
 * (P09_TEST_QA_VIEWER_V2) and a fresh login-specific approval
 * (P09_TEST_QA_LOGIN_V1). Neither a plan digest nor a viewer signature alone
 * can authorize creation of credentials.
 */
final class TestQaViewerLogin
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $appEnvironment,
    ) {}

    public static function signatureMessage(string $digest): string
    {
        return "P09_TEST_QA_LOGIN_V1\n" . $digest . "\n";
    }

    /** @param array{tenant_id:string,workspace_id:string} $scope */
    public function plan(array $scope, string $operatorId, string $membershipId, string $email, string $viewerDigest): Outcome
    {
        if (!in_array($this->appEnvironment, ['staging', 'testing'], true)) {
            return Outcome::refuse('P09_QA_LOGIN_ENVIRONMENT_REQUIRED');
        }
        foreach ([$scope['tenant_id'] ?? '', $scope['workspace_id'] ?? '', $operatorId, $membershipId] as $id) {
            if (!is_string($id) || !Ids::isValid($id)) {
                return Outcome::refuse('P09_QA_LOGIN_SCOPE_INVALID');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $viewerDigest) !== 1) {
            return Outcome::refuse('P09_QA_LOGIN_VIEWER_DIGEST_INVALID');
        }
        // Closed TEST-only namespace; never attach a QA credential to a customer email.
        if (strlen($email) > 254 || preg_match('/^[a-z0-9][a-z0-9._+\-]*@example\.test$/D', $email) !== 1) {
            return Outcome::refuse('P09_QA_LOGIN_EMAIL_NOT_TEST_ONLY');
        }
        $row = $this->db->one(
            "SELECT t.tenant_type, t.status AS tenant_status, w.workspace_type,
                    w.environment_class, w.external_side_effects_allowed,
                    w.status AS workspace_status, o.status AS operator_status,
                    m.membership_id, m.status AS membership_status,
                    m.valid_from, m.valid_until, m.role_id, m.role_version,
                    r.role_key, r.role_version AS defined_role_version, r.display_name, r.status AS role_status
             FROM workspaces w JOIN tenants t ON t.tenant_id=w.tenant_id
             JOIN operators o ON o.tenant_id=t.tenant_id AND o.operator_id=?
             JOIN workspace_memberships m ON m.tenant_id=t.tenant_id
                AND m.workspace_id=w.workspace_id AND m.principal_type='OPERATOR'
                AND m.principal_id=o.operator_id AND m.membership_id=?
             JOIN roles r ON r.role_id=m.role_id
             WHERE w.tenant_id=? AND w.workspace_id=?",
            [$operatorId, $membershipId, $scope['tenant_id'], $scope['workspace_id']]
        );
        if ($row === null || $row['tenant_type'] !== 'TEST' || $row['tenant_status'] !== 'ACTIVE'
            || $row['workspace_type'] !== 'TEST' || $row['environment_class'] !== 'TEST'
            || (int) $row['external_side_effects_allowed'] !== 0 || $row['workspace_status'] !== 'ACTIVE'
            || $row['operator_status'] !== 'ACTIVE') {
            return Outcome::refuse('P09_QA_LOGIN_NOT_ISOLATED_TEST');
        }
        if ($row['membership_status'] !== 'ACTIVE' || $row['role_key'] !== 'ROLE_DEMO_VIEWER'
            || (int) $row['role_version'] !== 1 || (int) $row['defined_role_version'] !== 1
            || $row['role_status'] !== 'ACTIVE' || $row['valid_until'] === null) {
            return Outcome::refuse('P09_QA_LOGIN_VIEWER_MEMBERSHIP_REQUIRED');
        }
        $from = Clock::parse((string) $row['valid_from']);
        $until = Clock::parse((string) $row['valid_until']);
        $now = Clock::parse($this->clock->now());
        if ($from === null || $until === null || $now === null || $from > $now || $now >= $until
            || ((float) $until->format('U.u') - (float) $from->format('U.u')) > 3600) {
            return Outcome::refuse('P09_QA_LOGIN_VIEWER_WINDOW_INACTIVE');
        }
        if ((int) $this->db->scalar(
            "SELECT COUNT(*) FROM workspace_memberships WHERE principal_type='OPERATOR' AND principal_id=?",
            [$operatorId]
        ) !== 1 || (int) $this->db->scalar(
            'SELECT COUNT(*) FROM platform_users WHERE operator_id=?', [$operatorId]
        ) !== 0 || (int) $this->db->scalar(
            'SELECT COUNT(*) FROM platform_users WHERE email=?', [$email]
        ) !== 0) {
            return Outcome::refuse('P09_QA_LOGIN_RECIPIENT_NOT_EMPTY');
        }
        $expected = null;
        foreach (Permissions::roleVersionDefinitions() as $definition) {
            if ($definition['role_key'] === 'ROLE_DEMO_VIEWER' && $definition['role_version'] === 1) {
                $expected = $definition;
                break;
            }
        }
        $perms = array_column($this->db->all(
            'SELECT permission_key FROM role_permissions WHERE role_id=? ORDER BY permission_key',
            [$row['role_id']]
        ), 'permission_key');
        sort($perms, SORT_STRING);
        $pinned = ['decision.read', 'internal_release.read', 'workspace.read'];
        $canonical = $expected['permissions'] ?? [];
        sort($canonical, SORT_STRING);
        if ($expected === null || $row['display_name'] !== $expected['display_name']
            || $perms !== $pinned || $canonical !== $pinned) {
            return Outcome::refuse('P09_QA_LOGIN_VIEWER_ROLE_DRIFT');
        }
        // Reconstruct the ORIGINAL signature-bound definition, which was produced
        // while the operator had zero historical seats. Do not re-run bootstrap plan:
        // it correctly refuses after the signed membership has been applied.
        $definition = [
            'app_environment' => $this->appEnvironment,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'operator_id' => $operatorId,
            'role_key' => 'ROLE_DEMO_VIEWER',
            'role_version' => 1,
            'role_id' => (string) $row['role_id'],
            'permissions' => $pinned,
            'valid_from' => (string) $row['valid_from'],
            'valid_until' => (string) $row['valid_until'],
            'existing_operator_memberships' => 0,
        ];
        $actualDigest = Digest::canonicalDigest(['operation' => 'P09_TEST_QA_VIEWER_V2', 'definition' => $definition]);
        if (!hash_equals($actualDigest, $viewerDigest)) {
            return Outcome::refuse('P09_QA_LOGIN_SIGNED_VIEWER_PLAN_CHANGED');
        }
        $loginDefinition = [
            'app_environment' => $this->appEnvironment,
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'operator_id' => $operatorId, 'membership_id' => $membershipId,
            'email' => $email, 'viewer_plan_digest' => $viewerDigest,
            'role_key' => 'ROLE_DEMO_VIEWER', 'role_version' => 1,
            'permissions' => $pinned, 'valid_until' => (string) $row['valid_until'],
            'must_change_password' => true, 'creates_operator' => false,
            'assigns_role' => false, 'creates_subscription' => false,
        ];
        return Outcome::ok([
            'action' => 'CREATE_TEST_QA_LOGIN_ONLY',
            'definition' => $loginDefinition,
            'login_plan_digest' => Digest::canonicalDigest([
                'operation' => 'P09_TEST_QA_LOGIN_V1', 'definition' => $loginDefinition
            ]),
            'signature_domain' => 'P09_TEST_QA_LOGIN_V1',
            'owner_signature_verified' => false,
            'viewer_signature_verified' => false,
        ]);
    }

    /** @param array{tenant_id:string,workspace_id:string} $scope */
    public function create(
        array $scope, string $operatorId, string $membershipId, string $email,
        string $viewerDigest, string $reviewedLoginDigest,
        string $viewerSignatureB64, string $loginSignatureB64,
        string $trustedOwnerPublicKeyB64, string $password,
    ): Outcome {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return Outcome::refuse('P09_QA_LOGIN_SIGNATURE_ENGINE_UNAVAILABLE');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $reviewedLoginDigest) !== 1) {
            return Outcome::refuse('P09_QA_LOGIN_PLAN_DIGEST_INVALID');
        }
        $viewerSignature = base64_decode(trim($viewerSignatureB64), true);
        $loginSignature = base64_decode(trim($loginSignatureB64), true);
        $publicKey = base64_decode(trim($trustedOwnerPublicKeyB64), true);
        if ($viewerSignature === false || $loginSignature === false || $publicKey === false
            || strlen($viewerSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($loginSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached(
                $viewerSignature, TestQaViewerBootstrap::signatureMessage($viewerDigest), $publicKey
            )) {
            return Outcome::refuse('P09_QA_LOGIN_VIEWER_SIGNATURE_INVALID');
        }
        if (!sodium_crypto_sign_verify_detached(
            $loginSignature, self::signatureMessage($reviewedLoginDigest), $publicKey
        )) {
            return Outcome::refuse('P09_QA_LOGIN_OWNER_SIGNATURE_INVALID');
        }
        return $this->db->transaction(function (Database $tx) use (
            $scope, $operatorId, $membershipId, $email, $viewerDigest, $reviewedLoginDigest, $password
        ): Outcome {
            $tenant = $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id'] ?? '']);
            if ($tenant === null) return Outcome::refuse('P09_QA_LOGIN_SCOPE_NOT_FOUND');
            $checked = $this->plan($scope, $operatorId, $membershipId, $email, $viewerDigest);
            if (!$checked->isOk()) return $checked;
            if (!hash_equals((string) $checked->value()['login_plan_digest'], $reviewedLoginDigest)) {
                return Outcome::refuse('P09_QA_LOGIN_PLAN_CHANGED');
            }
            $accounts = new AccountService($tx, $this->clock, new Authenticator($tx, $this->clock));
            $created = $accounts->createLogin($operatorId, $email, 'P09 QA Viewer', $password, true);
            if (!$created->isOk()) return $created;
            if ($created->value()['created'] !== true) {
                return Outcome::refuse('P09_QA_LOGIN_EXISTING_ACCOUNT_REFUSED');
            }
            return Outcome::ok([
                'platform_user_id' => $created->value()['platform_user_id'],
                'operator_id' => $operatorId, 'membership_id' => $membershipId,
                'viewer_plan_digest' => $viewerDigest,
                'login_plan_digest' => $reviewedLoginDigest,
                'owner_signature_verified' => true, 'viewer_signature_verified' => true,
                'must_change_password' => true, 'creates_subscription' => false,
                'assigns_role' => false, 'creates_operator' => false,
            ]);
        });
    }
}
