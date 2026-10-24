<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Beta\Plus46ReadUpgrade;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\WatchlistRead;
use Allfather\Loopia\Product\P03\CustomerProfileRead;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M02\Seed as RoleVocabulary;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db=Harness::freshDb();
if ($db===null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock=Harness::clock();
$scope=Seed::tenantWorkspace($db,$clock,'plus46-readable');
$db->run('UPDATE tenants SET tenant_key=? WHERE tenant_id=?',
    [Plus46ReadUpgrade::TENANT_KEY,$scope['tenant_id']]);
$db->run('UPDATE workspaces SET workspace_key=? WHERE workspace_id=?',
    [Plus46ReadUpgrade::WORKSPACE_KEY,$scope['workspace_id']]);
$vocabulary=new RoleVocabulary($db,$clock);
$vocabulary->ensureVocabulary();
$adminId=$vocabulary->roleIdFor('ROLE_TENANT_ADMIN',1);
$p01Id=$vocabulary->roleIdFor('ROLE_COMPETITION_MONITOR',1);
$p03Id=$vocabulary->roleIdFor('ROLE_LEAD_QUALIFIER',1);
Assert::true($adminId!==null&&$p01Id!==null&&$p03Id!==null,'read governance vocabulary available');
if ($adminId===null||$p01Id===null||$p03Id===null) return;
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$adminId);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$p01Id);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$p03Id);
Seed::platformUser($db,$clock,$scope['tenant_id'],$scope['operator_id'],Plus46ReadUpgrade::TARGET_EMAIL);
$ent=new Entitlements($db,$clock);
$beta=new BetaBundle($db,$ent);
Assert::true($beta->ensureTier()->isOk(),'base tier defined');
Assert::true($beta->subscribeTenant($scope['tenant_id'])->isOk(),'tenant on original no-fee beta trial');

$guard=new AccessGuard($db,$clock);
$actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
$ownDenied=$guard->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],
    $scope['workspace_id'],'entity_twin.read','entity_twin');
Assert::same('DENY',$ownDenied['decision'],'base admin/p01/p03 roles cannot read P02 draft');

$watch=new WatchlistRead($db,$guard,$ent,$clock);
$profile=new CustomerProfileRead($db,$guard,$ent,$clock);
Assert::same('DENIED',$watch->listCandidates($actor)['decision'],
    'P01 role is still narrowed by the original beta tier');
Assert::same('DENIED',$profile->listDeclaredIcp($actor)['decision'],
    'P03 role is still narrowed by the original beta tier');

$upgrade=new Plus46ReadUpgrade($db,$clock);
$plan=$upgrade->plan();
Assert::true($plan->isOk(),'exact existing TEST tenant and role state admits a plan');
if (!$plan->isOk()) return;
$planDigest=$plan->value()['plan_digest'];
Assert::same('CREATE_READ_ROLE_AND_OR_SWITCH_TO_V2_TRIAL',$plan->value()['action'],
    'plan shows additive role plus isolated commercial transition');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM subscription_tiers WHERE tier_key=?',
    [Plus46ReadUpgrade::TIER_KEY]),'plan creates no tier');
Assert::same('PLUS46_APPROVED_PLAN_CHANGED',$upgrade->apply(str_repeat('0',64))->reasonCode(),
    'unexpected owner plan digest refuses without mutation');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM subscription_tiers WHERE tier_key=?',
    [Plus46ReadUpgrade::TIER_KEY]),'wrong digest made no tier or role');

$applied=$upgrade->apply($planDigest);
Assert::true($applied->isOk(),'owner-reviewed plan applies atomically');
if (!$applied->isOk()) return;
Assert::same(true,$applied->value()['changed'],'first apply is a state transition');
Assert::same('REUSE',$upgrade->plan()->value()['action'],'completed state is exactly reusable');
Assert::same(false,$upgrade->apply($upgrade->plan()->value()['plan_digest'])->value()['changed'],
    'replay never creates another role or subscription');

$subscriptions=$db->all(
    "SELECT ts.status,st.tier_key,st.monthly_fee_minor_units FROM tenant_subscriptions ts
     JOIN subscription_tiers st ON st.subscription_tier_id=ts.subscription_tier_id
     WHERE ts.tenant_id=? ORDER BY st.tier_key",[$scope['tenant_id']],
);
Assert::same(2,count($subscriptions),'history contains prior trial and current successor only');
$byTier=[];foreach($subscriptions as $row)$byTier[$row['tier_key']]=$row;
Assert::same('CANCELLED',$byTier[BetaBundle::TIER_KEY]['status'],'original trial ended explicitly');
Assert::same('TRIAL',$byTier[Plus46ReadUpgrade::TIER_KEY]['status'],'successor remains a trial');
Assert::same(0,(int)$byTier[Plus46ReadUpgrade::TIER_KEY]['monthly_fee_minor_units'],
    'successor cannot bill');

$reader=$guard->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],
    $scope['workspace_id'],'entity_twin.read','entity_twin');
Assert::same('ALLOW',$reader['decision'],'only reviewed entity-reader role grants P02 READ');
Assert::true($ent->tenantTierGrants($scope['tenant_id'],'competition_monitoring.read'),
    'V2 grants P01 source candidate observation');
Assert::true($ent->tenantTierGrants($scope['tenant_id'],'customer_catalogue.read'),
    'V2 grants P03 customer declarations');
Assert::false($ent->tenantTierGrants($scope['tenant_id'],'outreach.execute'),
    'no external execution entitlement');
Assert::same('VISIBLE',$watch->listCandidates($actor)['decision'],'owner watchlist is now visible');
Assert::same('VISIBLE',$profile->listDeclaredIcp($actor)['decision'],'owner ICP is now visible');
Assert::same('VISIBLE',$profile->listCustomerOffers($actor)['decision'],'owned offer reads now permitted');

$competitorId=Ids::new();
$db->insert('competitors',[
    'competitor_id'=>$competitorId,'schema_version'=>'1',
    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'display_name'=>'Synthetic Test Competitor','competitor_class'=>'DIRECT',
    'status'=>'DRAFT','created_at'=>$clock->now(),
]);
$watchRows=$watch->listCandidates($actor)['rows'];
Assert::same(1,count($watchRows),'new read sees only its own competitor candidate');
Assert::same('DRAFT',$watchRows[0]['status'],'draft is not presented as released intelligence');
Assert::same('DRAFT_CANDIDATE_ONLY',$watchRows[0]['readiness'],'draft remains non-monitored');

$other=Seed::tenantWorkspace($db,$clock,'different-tenant');
$otherActor=['tenant_id'=>$other['tenant_id'],'workspace_id'=>$other['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
Assert::same('DENIED',$watch->listCandidates($otherActor)['decision'],
    'a Plus 46 principal cannot read another tenant using caller scope');
Assert::same('DENIED',$profile->listCustomerOffers($otherActor)['decision'],
    'catalogue access fails closed for cross-tenant scope');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM monitor_execution_grants'),
    'test never issues a model execution grant');
