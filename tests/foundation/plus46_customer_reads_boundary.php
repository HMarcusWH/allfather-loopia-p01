<?php

declare(strict_types=1);

/**
 * Source-level safety contract for the additive Plus 46 TEST customer reads.
 * MariaDB-backed and authenticated browser acceptance remain separate gates.
 */
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Beta\Plus46ReadUpgrade;
use Allfather\Loopia\Substrate\M02\Permissions;

$root=dirname(__DIR__,2);
$watch=(string)file_get_contents($root.'/app/Product/P01/WatchlistRead.php');
$customer=(string)file_get_contents($root.'/app/Product/P03/CustomerProfileRead.php');
$controller=(string)file_get_contents($root.'/app/Http/Controllers/ConsoleReadController.php');
$routes=(string)file_get_contents($root.'/public_html/index.php');
$cli=(string)file_get_contents($root.'/ops/activate-plus46-reads.php');
$upgrade=(string)file_get_contents($root.'/app/Beta/Plus46ReadUpgrade.php');
$bundle=(string)file_get_contents($root.'/app/Beta/BetaBundle.php');

Assert::equalsList(
    ['workspace.read','entity_twin.read'],
    Permissions::VERSIONED_ROLES[0]['permissions'],
    'new role contains only workspace/first-party entity READ authority',
);
Assert::same('ROLE_ENTITY_TWIN_READER',Permissions::VERSIONED_ROLES[0]['role_key'],
    'reader role is additive; existing tenant-admin definitions are untouched');
Assert::same('LOOPIA_SME_BETA',BetaBundle::TIER_KEY,'original beta tier identifier preserved');
Assert::equalsList(
    ['battlecard.read','competition_monitoring.read','customer_catalogue.read',
     'entity_twin.read','marketing_asset.read','priority_outreach_queue.read',
     'prospect.read','role_briefing.read'],
    Plus46ReadUpgrade::TIER_PERMISSIONS,
    'new V2 subscription contains exactly eight previously grantable reads',
);
foreach (Plus46ReadUpgrade::TIER_PERMISSIONS as $permission) {
    Assert::true(str_ends_with($permission,'.read'),"{$permission} is read-only");
    Assert::true(Permissions::isGrantable($permission),"{$permission} is in the M02 vocabulary");
}
foreach (['tenant.administer','role.assign','tool.invoke','outreach.execute','approval.decide_exact_artifact'] as $permission) {
    Assert::false(in_array($permission,Plus46ReadUpgrade::TIER_PERMISSIONS,true),
        "{$permission} is not a commercial grant");
}
Assert::contains('TENANT_KEY=', $upgrade, 'tenant-scoped upgrader is not generic');
Assert::contains("'plus46-studio-ab-test'", $upgrade, 'upgrader names exact TEST tenant');
Assert::contains("'plus46-profile-test'", $upgrade, 'upgrader names exact TEST workspace');
Assert::contains("'46@hmwh.se'", $upgrade, 'upgrader targets one existing account');
Assert::contains("tenant_type']!=='TEST'",$upgrade,'non-TEST tenant cannot activate upgrade');
Assert::contains("external_side_effects_allowed']!==0",$upgrade,'workspace must remain inert');
Assert::contains('SELECT tenant_id FROM tenants WHERE tenant_key=? FOR UPDATE',$upgrade,
    'upgrade serializes on the existing tenant');
Assert::contains('PLUS46_APPROVED_PLAN_CHANGED',$upgrade,
    'upgrade checks the reviewed canonical digest under lock');
Assert::contains('PLUS46_BASE_TIER_PERMISSIONS_CHANGED',$upgrade,
    'upgrade refuses modification of original six-permission tier');
Assert::contains('PLUS46_READ_ROLE_DEFINITION_MISMATCH',$upgrade,
    'upgrade checks precise role vocabulary');
Assert::contains('PLUS46_READ_UPGRADE_POSTCHECK_FAILED',$upgrade,
    'upgrade verifies actual resulting authority');
Assert::contains("ts.status IN ('TRIAL','ACTIVE')",$upgrade,'exactly one live subscription considered');
Assert::contains("status='CANCELLED'",$upgrade,'old trial is explicitly terminated, not silently overwritten');
Assert::contains("'TRIAL'",$upgrade,'V2 subscription remains a no-cost trial');
Assert::contains("self::ROLE_KEY,1",$upgrade,'only the minimal read role may be assigned');
Assert::contains("if (PHP_SAPI !== 'cli')",$cli,'read-access mutation remains CLI-only');
Assert::contains("PLUS46_TEST_READ_V2",$cli,'mutation requires explicit TEST acknowledgement');
Assert::contains("plan-digest",$cli,'mutation requires reviewed source-state digest');
Assert::contains('if ($cfg[\'environment\']!==\'staging\')',$cli,'no activation in production environments');
Assert::contains("'is_beta_tier' => in_array(",$bundle,'existing beta report recognizes V2 separately');

foreach ([
    [$watch,'competition_monitoring.read',"FROM competitors WHERE tenant_id=? AND workspace_id=?"],
    [$customer,'customer_catalogue.read',"WHERE p.tenant_id=? AND p.workspace_id=?"],
    [$customer,'customer_catalogue.read',"WHERE c.tenant_id=? AND c.workspace_id=?"],
] as [$source,$permission,$scope]) {
    Assert::contains("'{$permission}'",$source,'owner M02 read permission preserved');
    Assert::contains('$this->access->evaluate(',$source,'owner checks M02');
    Assert::contains('$this->entitlements->tenantTierGrants(',$source,'owner checks active commercial tier');
    Assert::contains($scope,$source,'all customer rows are tenant+workspace scoped');
    Assert::false(str_contains($source,'SELECT *'),'private columns are never selected indiscriminately');
}
Assert::contains("status IN ('DRAFT','ACTIVE','PAUSED')",$watch,'draft competitors are visible as drafts');
Assert::contains("'DRAFT_CANDIDATE_ONLY'",$watch,'P01 candidate cannot be promoted by display');
Assert::contains("review_expires_at",$watch,'source readiness accounts for expiry');
Assert::contains("p.status='ACTIVE'",$customer,'declared ICP is live customer profile only');
Assert::contains("MAX(v2.version_number)",$customer,'ICP uses latest append-only version');
Assert::contains("c.catalogue_status='ACTIVE'",$customer,'only active own catalogue is shown');
Assert::contains("'CUSTOMER_PROVIDED_UNVERIFIED'",$customer,'offers never claim independent verification');
Assert::contains("'CUSTOMER_DECLARED_UNVERIFIED'",$customer,'ICP is not interpreted as buyer intent');
Assert::false(str_contains($watch,'file_get_contents('),'P01 read cannot fetch new sources');
Assert::false(str_contains($customer,'file_get_contents('),'P03 read cannot touch raw source files');

foreach ([
    ["/console/api/v1/p01/source-watch",'p01SourceWatch'],
    ["/console/api/v1/p03/declared-icp",'p03DeclaredIcp'],
    ["/console/api/v1/p03/customer-offers",'p03CustomerOffers'],
] as [$url,$method]) {
    Assert::contains("'GET', '{$url}', \$reads->{$method}(...), 'JSON_401'",$routes,
        "{$url} is an explicitly scoped authenticated GET");
    Assert::contains("function {$method}(Request \$request, ?array \$session)",$controller,
        "{$method} is a fixed owner GET handler");
}
Assert::false(str_contains($routes,"'POST', '/console/api/v1/p01/source-watch'"),
    'read model cannot launch capture via API');

