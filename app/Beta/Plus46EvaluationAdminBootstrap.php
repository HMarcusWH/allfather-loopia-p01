<?php

declare(strict_types=1);

namespace Allfather\Loopia\Beta;

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M02\Permissions;

/**
 * A one-account, owner-reviewed TEST bootstrap for M02 TenantAdmin v2.
 *
 * This is NOT the general P09 assignment API, which still refuses self-grants.
 * The separately authenticated/root-controlled staging operator explicitly
 * reviews a SHA256 plan and acknowledges this fixed bootstrap operation.
 * No model grant, prompt approval, source permission, billing or M10 release
 * can be created by this class. Membership expires within 24 hours.
 */
final class Plus46EvaluationAdminBootstrap
{
    public const TENANT_KEY = 'plus46-studio-ab-test';
    public const WORKSPACE_KEY = 'plus46-profile-test';
    public const EMAIL = '46@hmwh.se';
    public const ROLE_KEY = 'ROLE_TENANT_ADMIN';
    public const ROLE_VERSION = 2;
    public const MAX_WINDOW_SECONDS = 86400;
    public const MIN_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $releaseRef,
        private readonly string $sourceFingerprint,
    ) {}

    /** The requested expiry is fixed across plan/apply; now() never enters the plan digest. */
    public function plan(string $validUntil): Outcome
    {
        $now = $this->clock->now();
        if (!$this->expiryAcceptable($validUntil, $now)) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_WINDOW_INVALID');
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $this->releaseRef) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->sourceFingerprint) !== 1) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_SOURCE_IDENTITY_INVALID');
        }

        $tenant = $this->db->one(
            'SELECT tenant_id,tenant_type,status FROM tenants WHERE tenant_key=?',
            [self::TENANT_KEY],
        );
        if ($tenant === null || $tenant['tenant_type'] !== 'TEST' || $tenant['status'] !== 'ACTIVE') {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_TEST_TENANT_REQUIRED');
        }
        $tenantId = (string)$tenant['tenant_id'];
        $workspace = $this->db->one(
            'SELECT workspace_id,workspace_type,environment_class,status,external_side_effects_allowed
             FROM workspaces WHERE tenant_id=? AND workspace_key=?',
            [$tenantId, self::WORKSPACE_KEY],
        );
        if ($workspace === null || $workspace['workspace_type'] !== 'TEST'
            || $workspace['environment_class'] !== 'TEST'
            || $workspace['status'] !== 'ACTIVE'
            || (int)$workspace['external_side_effects_allowed'] !== 0) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_INERT_TEST_WORKSPACE_REQUIRED');
        }
        $workspaceId = (string)$workspace['workspace_id'];

        // Exactly the already-bound customer account, never a new credential,
        // second operator, generic address or caller-selected user.
        $accounts = $this->db->all(
            'SELECT u.platform_user_id,u.operator_id,u.status AS account_status,
                    o.status AS operator_status
             FROM platform_users u
             JOIN operators o ON o.operator_id=u.operator_id AND o.tenant_id=u.tenant_id
             WHERE u.email=? AND u.tenant_id=?',
            [self::EMAIL, $tenantId],
        );
        if (count($accounts) !== 1
            || $accounts[0]['account_status'] !== 'ACTIVE'
            || $accounts[0]['operator_status'] !== 'ACTIVE') {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_EXISTING_ACTIVE_ACCOUNT_REQUIRED');
        }
        $holder = $accounts[0];
        $operatorId = (string)$holder['operator_id'];

        // Require CURRENT TenantAdmin v1 and independently check its exact
        // M02 tenant-admin/role-assign authority; owner acknowledgement is
        // not a replacement for the access guard.
        $legacy = $this->db->one(
            "SELECT r.role_id FROM workspace_memberships m
             JOIN roles r ON r.role_id=m.role_id
             WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_type='OPERATOR'
               AND m.principal_id=? AND m.status='ACTIVE' AND m.role_version=1
               AND m.valid_from<=? AND (m.valid_until IS NULL OR m.valid_until>?)
               AND r.role_key=? AND r.role_version=1 AND r.status='ACTIVE'",
            [$tenantId,$workspaceId,$operatorId,$now,$now,self::ROLE_KEY],
        );
        if ($legacy === null) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_ACTIVE_V1_REQUIRED');
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach (['tenant.administer','role.assign'] as $permission) {
            if ($guard->evaluate('OPERATOR',$operatorId,$tenantId,$workspaceId,
                $permission,'PLUS46_TEST_EVAL_ADMIN_BOOTSTRAP')['decision'] !== 'ALLOW') {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_EXISTING_OWNER_AUTHORITY_REQUIRED');
            }
        }

        $definitions = array_values(array_filter(
            Permissions::roleVersionDefinitions(),
            static fn (array $r): bool => $r['role_key'] === self::ROLE_KEY
                && $r['role_version'] === self::ROLE_VERSION,
        ));
        if (count($definitions) !== 1) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_CANONICAL_ROLE_MISSING');
        }
        $roles = $this->db->all(
            'SELECT role_id,display_name,status FROM roles WHERE role_key=? AND role_version=?',
            [self::ROLE_KEY,self::ROLE_VERSION],
        );
        if (count($roles) !== 1 || $roles[0]['status'] !== 'ACTIVE'
            || $roles[0]['display_name'] !== $definitions[0]['display_name']) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_ROLE_DEFINITION_INVALID');
        }
        $roleId = (string)$roles[0]['role_id'];
        $actualPermissions = array_column($this->db->all(
            'SELECT permission_key FROM role_permissions WHERE role_id=? ORDER BY permission_key',
            [$roleId],
        ), 'permission_key');
        $expectedPermissions = $definitions[0]['permissions'];
        sort($expectedPermissions,SORT_STRING);
        if ($actualPermissions !== $expectedPermissions) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_ROLE_PERMISSIONS_CONFLICT');
        }
        // We never broaden the canonical v2 definition or remove any role.
        $delta = array_values(array_diff(
            $definitions[0]['permissions'],
            Permissions::ROLES[self::ROLE_KEY]['permissions'],
        ));
        sort($delta,SORT_STRING);
        if ($delta !== ['runtime_host_binding.administer','valkyrie.administer_contract','valkyrie.register']) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_UNEXPECTED_PERMISSION_DELTA');
        }

        $membershipSnapshot = $this->db->all(
            "SELECT m.membership_id,m.role_id,m.role_version,m.status,m.valid_from,m.valid_until,
                    r.role_key
             FROM workspace_memberships m
             JOIN roles r ON r.role_id=m.role_id
             WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_type='OPERATOR'
               AND m.principal_id=? ORDER BY m.membership_id",
            [$tenantId,$workspaceId,$operatorId],
        );
        $v2 = array_values(array_filter($membershipSnapshot,
            static fn (array $m): bool => $m['role_id'] === $roleId));
        if (count($v2) > 1) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_MULTIPLE_V2_MEMBERSHIPS');
        }
        $action = 'ASSIGN_ONE_BOUNDED_TEST_MEMBERSHIP';
        if (count($v2) === 1) {
            if ($v2[0]['status'] !== 'ACTIVE'
                || $v2[0]['role_version'] != self::ROLE_VERSION
                || $v2[0]['valid_from'] > $now
                || $v2[0]['valid_until'] !== $validUntil
                || $v2[0]['valid_until'] <= $now) {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_V2_MEMBERSHIP_CONFLICT');
            }
            $action = 'REUSE';
        }

        $observed = [
            'tenant_id'=>$tenantId,
            'workspace_id'=>$workspaceId,
            'operator_id'=>$operatorId,
            'platform_user_id'=>(string)$holder['platform_user_id'],
            'canonical_v1_role_id'=>(string)$legacy['role_id'],
            'canonical_v2_role_id'=>$roleId,
            'memberships'=>$membershipSnapshot,
            'requested_valid_until'=>$validUntil,
            'release_ref'=>$this->releaseRef,
            'source_fingerprint'=>$this->sourceFingerprint,
        ];
        return Outcome::ok([
            'operation'=>'PLUS46_BOUNDED_TEST_ADMIN_V2',
            'action'=>$action,
            'scope'=>['tenant_key'=>self::TENANT_KEY,'workspace_key'=>self::WORKSPACE_KEY,
                'email'=>self::EMAIL],
            'observed'=>$observed,
            'only_added_permissions'=>$delta,
            'role_version'=>self::ROLE_VERSION,
            'expires_at'=>$validUntil,
            'plan_digest'=>Digest::canonicalDigest(['operation'=>'PLUS46_BOUNDED_TEST_ADMIN_V2',
                'observed'=>$observed,'action'=>$action]),
            'no_model_grant_or_prompt_approval'=>true,
            'no_source_capture_or_external_action'=>true,
            'owner_approval_not_implied'=>true,
        ]);
    }

    /** One locked, digest-checked, root-operator-initiated special bootstrap. */
    public function apply(string $validUntil,string $reviewedDigest): Outcome
    {
        if (preg_match('/^[a-f0-9]{64}$/D',$reviewedDigest) !== 1) {
            return Outcome::refuse('PLUS46_EVAL_ADMIN_REVIEWED_DIGEST_REQUIRED');
        }
        return $this->db->transaction(function (Database $tx) use ($validUntil,$reviewedDigest): Outcome {
            $tenant=$tx->one('SELECT tenant_id FROM tenants WHERE tenant_key=? FOR UPDATE',
                [self::TENANT_KEY]);
            if ($tenant === null) return Outcome::refuse('PLUS46_EVAL_ADMIN_TENANT_NOT_FOUND');
            $plan=$this->plan($validUntil);
            if (!$plan->isOk()) return $plan;
            $p=$plan->value();
            if (!hash_equals($p['plan_digest'],$reviewedDigest)) {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_REVIEWED_PLAN_CHANGED');
            }
            if ($p['action']==='REUSE') {
                return Outcome::ok(['changed'=>false,'expires_at'=>$validUntil,
                    'plan_digest'=>$reviewedDigest]);
            }
            $scope=$p['observed'];
            $account=new AccountService($tx,$this->clock,new Authenticator($tx,$this->clock));
            $result=$account->addRoleMembership(
                $scope['tenant_id'],self::WORKSPACE_KEY,
                $scope['operator_id'],self::ROLE_KEY,self::ROLE_VERSION,
            );
            if (!$result->isOk() || $result->value()['created'] !== true) {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_ROLE_BINDING_REFUSED');
            }
            // The existing owner API creates a membership with no expiry.
            // Narrow it BEFORE commit, while the tenant lock is still held.
            $changed=$tx->run(
                "UPDATE workspace_memberships SET valid_until=?
                 WHERE membership_id=? AND tenant_id=? AND workspace_id=?
                   AND principal_type='OPERATOR' AND principal_id=?
                   AND role_id=? AND role_version=2 AND status='ACTIVE'
                   AND valid_until IS NULL",
                [$validUntil,$result->value()['membership_id'],$scope['tenant_id'],
                 $scope['workspace_id'],$scope['operator_id'],$scope['canonical_v2_role_id']],
            );
            if ($changed !== 1) {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_EXPIRY_NOT_BOUND');
            }
            $post=$this->plan($validUntil);
            if (!$post->isOk() || $post->value()['action'] !== 'REUSE') {
                return Outcome::refuse('PLUS46_EVAL_ADMIN_POSTCONDITION_FAILED');
            }
            return Outcome::ok([
                'changed'=>true,
                'membership_id'=>(string)$result->value()['membership_id'],
                'role_key'=>self::ROLE_KEY,'role_version'=>self::ROLE_VERSION,
                'expires_at'=>$validUntil,'plan_digest'=>$reviewedDigest,
                'execution_grants_created'=>0,'provider_calls'=>0,
            ]);
        });
    }

    private function expiryAcceptable(string $until,string $now): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$until) !== 1) {
            return false;
        }
        try {
            $future=new \DateTimeImmutable($until);
            $current=new \DateTimeImmutable($now);
            $gap=$future->getTimestamp()-$current->getTimestamp();
            return $gap>=self::MIN_WINDOW_SECONDS && $gap<=self::MAX_WINDOW_SECONDS;
        } catch (\Throwable) {
            return false;
        }
    }
}
