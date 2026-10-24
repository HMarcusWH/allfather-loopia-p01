<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Beta\Plus46EvaluationAdminBootstrap;
use Allfather\Loopia\Substrate\M02\Permissions;

$root=dirname(__DIR__,2);
$service=(string)file_get_contents($root.'/app/Beta/Plus46EvaluationAdminBootstrap.php');
$cli=(string)file_get_contents($root.'/ops/activate-plus46-evaluation-admin.php');
$p09=(string)file_get_contents($root.'/app/Auth/TestMembershipLifecycle.php');
$v2=array_values(array_filter(Permissions::roleVersionDefinitions(),
    static fn (array $r):bool => $r['role_key']==='ROLE_TENANT_ADMIN' && $r['role_version']===2));
Assert::same(1,count($v2),'canonical TenantAdmin v2 is an existing additive successor');
Assert::equalsList(
    ['tenant.read','tenant.administer','workspace.read','workspace.create','workspace.administer',
     'identity.read','identity.register','identity.suspend_revoke','role.assign','role.revoke'],
    Permissions::ROLES['ROLE_TENANT_ADMIN']['permissions'],
    'legacy TenantAdmin v1 remains unchanged',
);
$delta=array_values(array_diff($v2[0]['permissions'],Permissions::ROLES['ROLE_TENANT_ADMIN']['permissions']));
sort($delta,SORT_STRING);
Assert::equalsList(['runtime_host_binding.administer','valkyrie.administer_contract','valkyrie.register'],
    $delta,'new bootstrap does not add permissions to canonical v2');
Assert::same('plus46-studio-ab-test',Plus46EvaluationAdminBootstrap::TENANT_KEY,
    'only Plus46 TEST tenant may be bootstrapped');
Assert::same('plus46-profile-test',Plus46EvaluationAdminBootstrap::WORKSPACE_KEY,
    'only Plus46 TEST workspace may be bootstrapped');
Assert::same('46@hmwh.se',Plus46EvaluationAdminBootstrap::EMAIL,
    'only the existing TEST account may be bootstrapped');
Assert::same(2,Plus46EvaluationAdminBootstrap::ROLE_VERSION,'only v2 may be selected');
Assert::same(86400,Plus46EvaluationAdminBootstrap::MAX_WINDOW_SECONDS,
    'grant expires within 24 hours');
Assert::same(600,Plus46EvaluationAdminBootstrap::MIN_WINDOW_SECONDS,
    'ultrashort accidental or expired windows are refused');

foreach ([
    "tenant_type'] !== 'TEST'",
    "workspace_type'] !== 'TEST'",
    "environment_class'] !== 'TEST'",
    "external_side_effects_allowed'] !== 0",
    "account_status'] !== 'ACTIVE'",
    "operator_status'] !== 'ACTIVE'",
    "'tenant.administer','role.assign'",
    "'PLUS46_EVAL_ADMIN_ACTIVE_V1_REQUIRED'",
    "'PLUS46_EVAL_ADMIN_ROLE_PERMISSIONS_CONFLICT'",
    "'PLUS46_EVAL_ADMIN_V2_MEMBERSHIP_CONFLICT'",
    "SELECT tenant_id FROM tenants WHERE tenant_key=? FOR UPDATE",
    "PLUS46_EVAL_ADMIN_REVIEWED_PLAN_CHANGED",
    "PLUS46_EVAL_ADMIN_EXPIRY_NOT_BOUND",
    "PLUS46_EVAL_ADMIN_POSTCONDITION_FAILED",
    "role_version=2 AND status='ACTIVE'",
    "'execution_grants_created'=>0",
    "'provider_calls'=>0",
] as $contract) {
    Assert::contains($contract,$service,'fixed TEST role grant preserves '.$contract);
}
foreach ([
    "if (PHP_SAPI !== 'cli')",
    "posix_geteuid() !== 0",
    "'environment']!=='staging'",
    "APP_RELEASE_REF",
    "'plan-digest','ack'",
    "PLUS46_TEST_EVALUATION_ADMIN_V2",
    "SourceFingerprint::compute(",
    "subscription_changed'=>false",
    "model_calls_authorized'=>false",
] as $contract) {
    Assert::contains($contract,$cli,'CLI owner scope retains '.$contract);
}
Assert::contains("P09_TEST_TARGET_INVALID_OR_SELF",$p09,
    'the existing general TEST assignment path continues to forbid self-grants');
Assert::false(str_contains($cli,'curl_'),'private CLI never reaches the provider');
Assert::false(str_contains($service,'curl_'),'role service never reaches external services');
Assert::false(str_contains($service,'UPDATE roles '),'role definitions never mutate');
Assert::false(str_contains($service,'UPDATE tenant_subscriptions'),
    'bootstrap never changes an existing customer subscription');
Assert::false(str_contains($service,'INSERT INTO platform_users'),
    'bootstrap never creates another identity');
