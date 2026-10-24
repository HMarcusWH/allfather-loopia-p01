<?php

declare(strict_types=1);

namespace Allfather\Loopia\Beta;

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;

/**
 * Explicit, scoped TEST customer READ upgrade. Never changes the globally
 * shared LOOPIA_SME_BETA tier, any other tenant, or any execution permission.
 * The operator reviews a plan digest before a single atomic mutation.
 */
final class Plus46ReadUpgrade
{
    public const TENANT_KEY='plus46-studio-ab-test';
    public const WORKSPACE_KEY='plus46-profile-test';
    public const TARGET_EMAIL='46@hmwh.se';
    public const TIER_KEY='LOOPIA_SME_BETA_READS_V2';
    public const ROLE_KEY='ROLE_ENTITY_TWIN_READER';
    public const TIER_PERMISSIONS=[
        'battlecard.read','competition_monitoring.read','customer_catalogue.read',
        'entity_twin.read','marketing_asset.read','priority_outreach_queue.read',
        'prospect.read','role_briefing.read',
    ];
    private const ROLE_PERMISSIONS=['entity_twin.read','workspace.read'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /** @return Outcome */
    public function plan(): Outcome
    {
        $tenant=$this->db->one(
            'SELECT tenant_id,tenant_type,status FROM tenants WHERE tenant_key=?',
            [self::TENANT_KEY],
        );
        if ($tenant===null || $tenant['status']!=='ACTIVE' || $tenant['tenant_type']!=='TEST') {
            return Outcome::refuse('PLUS46_TEST_TENANT_REQUIRED');
        }
        $tenantId=(string)$tenant['tenant_id'];
        $workspace=$this->db->one(
            'SELECT workspace_id,status,workspace_type,environment_class,external_side_effects_allowed
             FROM workspaces WHERE tenant_id=? AND workspace_key=?',
            [$tenantId,self::WORKSPACE_KEY],
        );
        if ($workspace===null || $workspace['status']!=='ACTIVE'
            || $workspace['environment_class']!=='TEST'
            || (int)$workspace['external_side_effects_allowed']!==0) {
            return Outcome::refuse('PLUS46_ISOLATED_WORKSPACE_REQUIRED');
        }
        $holder=$this->db->one(
            "SELECT u.platform_user_id,u.operator_id,u.status AS account_status,
                    o.status AS operator_status
             FROM platform_users u JOIN operators o
               ON o.operator_id=u.operator_id AND o.tenant_id=u.tenant_id
             WHERE u.email=? AND u.tenant_id=?",
            [self::TARGET_EMAIL,$tenantId],
        );
        if ($holder===null || $holder['account_status']!=='ACTIVE'
            || $holder['operator_status']!=='ACTIVE') {
            return Outcome::refuse('PLUS46_EXISTING_ACTIVE_LOGIN_REQUIRED');
        }

        $role=$this->db->one(
            "SELECT role_id,status FROM roles WHERE role_key=? AND role_version=1",
            [self::ROLE_KEY],
        );
        if ($role===null || $role['status']!=='ACTIVE') {
            return Outcome::refuse('PLUS46_READ_ROLE_VOCABULARY_NOT_PROVISIONED');
        }
        $rolePermissions=array_column($this->db->all(
            'SELECT permission_key FROM role_permissions WHERE role_id=? ORDER BY permission_key',
            [$role['role_id']],
        ),'permission_key');
        if ($rolePermissions!==self::ROLE_PERMISSIONS) {
            return Outcome::refuse('PLUS46_READ_ROLE_DEFINITION_MISMATCH');
        }
        // Only the original account's existing active Tenant Administrator
        // identity may receive the read extension.
        $hasAdmin=$this->db->one(
            "SELECT 1 AS ok FROM workspace_memberships m
             JOIN roles r ON r.role_id=m.role_id AND r.status='ACTIVE'
             JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_key='tenant.administer'
             WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_type='OPERATOR'
               AND m.principal_id=? AND m.status='ACTIVE'
               AND m.valid_from<=? AND (m.valid_until IS NULL OR m.valid_until>?)
             LIMIT 1",
            [$tenantId,$workspace['workspace_id'],$holder['operator_id'],
             $this->clock->now(),$this->clock->now()],
        );
        if ($hasAdmin===null) return Outcome::refuse('PLUS46_OWNER_ADMIN_ROLE_REQUIRED');

        $memberships=$this->db->all(
            "SELECT membership_id,status,valid_from,valid_until FROM workspace_memberships
             WHERE tenant_id=? AND workspace_id=? AND principal_id=? AND principal_type='OPERATOR'
               AND role_id=?",
            [$tenantId,$workspace['workspace_id'],$holder['operator_id'],$role['role_id']],
        );
        if (count($memberships)>1) return Outcome::refuse('PLUS46_READ_ROLE_MEMBERSHIP_AMBIGUOUS');
        $assigned=count($memberships)===1;
        if ($assigned && ($memberships[0]['status']!=='ACTIVE'
            || $memberships[0]['valid_from']>$this->clock->now()
            || ($memberships[0]['valid_until']!==null && $memberships[0]['valid_until']<=$this->clock->now()))) {
            return Outcome::refuse('PLUS46_READ_ROLE_MEMBERSHIP_NOT_CURRENT');
        }

        $live=$this->db->all(
            "SELECT ts.tenant_subscription_id,ts.status,st.tier_key
             FROM tenant_subscriptions ts
             JOIN subscription_tiers st ON st.subscription_tier_id=ts.subscription_tier_id
             WHERE ts.tenant_id=? AND ts.status IN ('TRIAL','ACTIVE')",
            [$tenantId],
        );
        if (count($live)!==1 || $live[0]['status']!=='TRIAL'
            || !in_array($live[0]['tier_key'],[BetaBundle::TIER_KEY,self::TIER_KEY],true)) {
            return Outcome::refuse('PLUS46_EXPECTED_SINGLE_READ_ONLY_TRIAL_REQUIRED');
        }
        // The original six-read commercial contract is immutable: refuse
        // migration if somebody has widened/repriced or disabled that tier.
        if ($live[0]['tier_key']===BetaBundle::TIER_KEY) {
            $original=$this->db->one(
                "SELECT st.subscription_tier_id,st.status,st.monthly_fee_minor_units,
                        st.currency,st.billing_period FROM tenant_subscriptions ts
                 JOIN subscription_tiers st ON st.subscription_tier_id=ts.subscription_tier_id
                 WHERE ts.tenant_subscription_id=? AND ts.tenant_id=?",
                [$live[0]['tenant_subscription_id'],$tenantId],
            );
            if ($original===null || $original['status']!=='ACTIVE'
                || (int)$original['monthly_fee_minor_units']!==0
                || $original['currency']!=='SEK'||$original['billing_period']!=='MONTHLY') {
                return Outcome::refuse('PLUS46_BASE_TIER_CONTRACT_CHANGED');
            }
            $basePermissions=array_column($this->db->all(
                'SELECT permission_key FROM subscription_tier_permissions
                 WHERE subscription_tier_id=? ORDER BY permission_key',
                [$original['subscription_tier_id']],
            ),'permission_key');
            if ($basePermissions!==BetaBundle::PERMISSIONS) {
                return Outcome::refuse('PLUS46_BASE_TIER_PERMISSIONS_CHANGED');
            }
        }
        $v2=$this->db->all(
            'SELECT subscription_tier_id,status,monthly_fee_minor_units,currency,billing_period
             FROM subscription_tiers WHERE tier_key=?',
            [self::TIER_KEY],
        );
        if (count($v2)>1) return Outcome::refuse('PLUS46_READS_V2_TIER_AMBIGUOUS');
        if ($v2!==[]) {
            $t=$v2[0];
            $permissions=array_column($this->db->all(
                'SELECT permission_key FROM subscription_tier_permissions
                 WHERE subscription_tier_id=? ORDER BY permission_key',
                [$t['subscription_tier_id']],
            ),'permission_key');
            if ($t['status']!=='ACTIVE'||(int)$t['monthly_fee_minor_units']!==0
                || $t['currency']!=='SEK'||$t['billing_period']!=='MONTHLY'
                || $permissions!==self::TIER_PERMISSIONS) {
                return Outcome::refuse('PLUS46_READS_V2_TIER_DEFINITION_CONFLICT');
            }
        }
        $observed=[
            'tenant_id'=>$tenantId,
            'workspace_id'=>(string)$workspace['workspace_id'],
            'platform_user_id'=>(string)$holder['platform_user_id'],
            'operator_id'=>(string)$holder['operator_id'],
            'reader_role_id'=>(string)$role['role_id'],
            'reader_membership_id'=>$assigned?(string)$memberships[0]['membership_id']:null,
            'live_subscription_id'=>(string)$live[0]['tenant_subscription_id'],
            'live_tier_key'=>(string)$live[0]['tier_key'],
            'v2_tier_id'=>$v2===[]?null:(string)$v2[0]['subscription_tier_id'],
        ];
        $complete=$assigned && $live[0]['tier_key']===self::TIER_KEY;
        return Outcome::ok([
            'action'=>$complete?'REUSE':'CREATE_READ_ROLE_AND_OR_SWITCH_TO_V2_TRIAL',
            'scope'=>['tenant_key'=>self::TENANT_KEY,'workspace_key'=>self::WORKSPACE_KEY,'email'=>self::TARGET_EMAIL],
            'observed'=>$observed,
            'tier_permissions'=>self::TIER_PERMISSIONS,
            'reader_permissions'=>self::ROLE_PERMISSIONS,
            'plan_digest'=>Digest::canonicalDigest(['operation'=>'PLUS46_READS_V2','observed'=>$observed]),
            'no_new_execution_or_external_authority'=>true,
            'owner_approval_not_implied'=>true,
        ]);
    }

    /** Only the exact owner-reviewed snapshot may become a scoped TEST mutation. */
    public function apply(string $digest): Outcome
    {
        if (preg_match('/^[a-f0-9]{64}$/D',$digest)!==1) {
            return Outcome::refuse('PLUS46_REVIEWED_PLAN_DIGEST_REQUIRED');
        }
        return $this->db->transaction(function (Database $tx) use ($digest): Outcome {
            $t=$tx->one('SELECT tenant_id FROM tenants WHERE tenant_key=? FOR UPDATE',[self::TENANT_KEY]);
            if ($t===null) return Outcome::refuse('PLUS46_TENANT_NOT_FOUND');
            $plan=$this->plan();
            if (!$plan->isOk()) return $plan;
            $p=$plan->value();
            if (!hash_equals($p['plan_digest'],$digest)) {
                return Outcome::refuse('PLUS46_APPROVED_PLAN_CHANGED');
            }
            if ($p['action']==='REUSE') return Outcome::ok(['changed'=>false,'plan_digest'=>$digest]);
            $observed=$p['observed'];
            $entitlements=new Entitlements($tx,$this->clock);
            $tierId=$observed['v2_tier_id'];
            if ($tierId===null) {
                $defined=$entitlements->defineTier(
                    self::TIER_KEY,'Loopia SME TEST Customer Read Beta V2',0,
                    self::TIER_PERMISSIONS,'SEK','MONTHLY',
                );
                if (!$defined->isOk()) return $defined;
                $tierId=(string)$defined->value()['subscription_tier_id'];
            }
            if ($observed['live_tier_key']!==self::TIER_KEY) {
                $changed=$tx->run(
                    "UPDATE tenant_subscriptions SET status='CANCELLED'
                     WHERE tenant_subscription_id=? AND tenant_id=? AND status='TRIAL'",
                    [$observed['live_subscription_id'],$observed['tenant_id']],
                );
                if ($changed!==1) return Outcome::refuse('PLUS46_TRIAL_CHANGED');
                $sub=$entitlements->subscribe($observed['tenant_id'],$tierId,'TRIAL');
                if (!$sub->isOk()) return $sub;
            }
            if ($observed['reader_membership_id']===null) {
                $auth=new Authenticator($tx,$this->clock);
                $account=new AccountService($tx,$this->clock,$auth);
                $membership=$account->addRoleMembership(
                    $observed['tenant_id'],self::WORKSPACE_KEY,
                    $observed['operator_id'],self::ROLE_KEY,1,
                );
                if (!$membership->isOk()) return $membership;
            }
            $after=$this->plan();
            if (!$after->isOk() || $after->value()['action']!=='REUSE') {
                return Outcome::refuse('PLUS46_READ_UPGRADE_POSTCHECK_FAILED');
            }
            return Outcome::ok(['changed'=>true,'plan_digest'=>$digest,
                'new_live_tier'=>self::TIER_KEY,'reader_role'=>self::ROLE_KEY]);
        });
    }
}
