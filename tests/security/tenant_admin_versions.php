<?php

declare(strict_types=1);

/** A vocabulary upgrade is not a membership upgrade, including on onboarding replay. */
use Allfather\Loopia\Auth\{AccountService,Authenticator};
use Allfather\Loopia\Substrate\M02\{AccessGuard,Permissions,Seed as Vocabulary};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'role-versions');
$other = Seed::tenantWorkspace($db, $clock, 'other-tenant');
$workspaceKey = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$vocabulary = new Vocabulary($db, $clock);
$guard = new AccessGuard($db, $clock);
$delta = ['runtime_host_binding.administer', 'valkyrie.register', 'valkyrie.administer_contract'];
$legacyPermissions = ['tenant.read','tenant.administer','workspace.read','workspace.create','workspace.administer','identity.read','identity.register','identity.suspend_revoke','role.assign','role.revoke'];
Assert::same($legacyPermissions, Permissions::ROLES['ROLE_TENANT_ADMIN']['permissions'], 'legacy v1 definition is unchanged');

// Model an installed v1 role and membership before the new vocabulary exists.
$legacy = Seed::role($db, $clock, 'ROLE_TENANT_ADMIN', $legacyPermissions);
$db->run('UPDATE roles SET display_name=? WHERE role_id=?', ['Tenant Administrator', $legacy['role_id']]);
$membership = Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $legacy['role_id']);
$beforeRole = $db->one('SELECT * FROM roles WHERE role_id=?', [$legacy['role_id']]);
$beforePermissions = $db->all('SELECT * FROM role_permissions WHERE role_id=? ORDER BY permission_key', [$legacy['role_id']]);
$beforeMembership = $db->one('SELECT * FROM workspace_memberships WHERE membership_id=?', [$membership]);
$seeded = $vocabulary->ensureVocabulary();
Assert::same(14, $seeded['roles'], 'adds other13 v1 roles plus exactly one successor');
Assert::same($beforeRole, $db->one('SELECT * FROM roles WHERE role_id=?', [$legacy['role_id']]), 'existing role bytes preserved');
Assert::same($beforePermissions, $db->all('SELECT * FROM role_permissions WHERE role_id=? ORDER BY permission_key', [$legacy['role_id']]), 'v1 permission rows preserved');
Assert::same($beforeMembership, $db->one('SELECT * FROM workspace_memberships WHERE membership_id=?', [$membership]), 'existing membership including ID/version/window preserved');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM workspace_memberships'), 'provisioning assigns no memberships');
Assert::same(['permissions'=>0,'roles'=>0], $vocabulary->ensureVocabulary(), 'repeat seed is a no-op');
Assert::same($legacy['role_id'], $vocabulary->roleIdFor('ROLE_TENANT_ADMIN'), 'default resolver remains v1');
$v2 = $vocabulary->roleIdFor('ROLE_TENANT_ADMIN', 2);
Assert::true(is_string($v2) && $v2 !== $legacy['role_id'], 'v2 is a distinct explicit role identity');
$expectedV2=[...$legacyPermissions,...$delta]; sort($expectedV2,SORT_STRING);
$actualV2=array_column($db->all('SELECT permission_key FROM role_permissions WHERE role_id=?',[$v2]),'permission_key'); sort($actualV2,SORT_STRING);
Assert::same($expectedV2,$actualV2,'v2 adds exactly the three spec-defined setup permissions');
$check = static fn(string $permission, ?string $workspace = null): array => $guard->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],$workspace ?? $scope['workspace_id'],$permission,'SETUP');
foreach ($delta as $permission) Assert::same('DENY', $check($permission)['decision'], 'existing v1 holder remains denied: '.$permission);

// Same API used by onboarding: no explicit version cannot select latest/newest.
$replay = $accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN');
Assert::true($replay->isOk(), 'onboarding membership replay succeeds');
Assert::same(false, $replay->value()['created'], 'replay reuses v1');
Assert::same($membership, $replay->value()['membership_id'], 'same membership identity');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM workspace_memberships'), 'replay adds no v2 membership');
Assert::same('ROLE_NOT_DEFINED', $accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN',3)->reasonCode(), 'unknown version does not fall back');
Assert::same('ROLE_VERSION_INVALID', $accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN',0)->reasonCode(), 'invalid version refuses');

// Exercise the actual CLI preflight and apply, which must choose the same v1 as the API.
Assert::true((new \Allfather\Loopia\Beta\BetaBundle($db,new \Allfather\Loopia\Product\P01\Entitlements($db,$clock)))->ensureTier()->isOk(),'synthetic beta tier declared');
Assert::true($accounts->createLogin($scope['operator_id'],'role-version@example.test','Role test','synthetic role version password',false)->isOk(),'existing synthetic account');
$envPath = Harness::childEnvFile();
$runCli = static function (array $arguments): array {
    $process = proc_open([PHP_BINARY, ...$arguments], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('synthetic CLI unavailable');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), json_decode($stdout,true), $stderr];
};
try {
    $args=[dirname(__DIR__,2).'/ops/onboard-beta-user.php','--env='.$envPath,
        '--tenant-key='.$db->scalar('SELECT tenant_key FROM tenants WHERE tenant_id=?',[$scope['tenant_id']]),
        '--workspace-key='.$workspaceKey,'--email=role-version@example.test','--display-name=Role test','--roles=ROLE_TENANT_ADMIN'];
    [$code]=$runCli([...$args,'--dry-run']);
    Assert::same(0,$code,'actual onboarding dry-run resolves legacy version1');
    [$code]=$runCli($args);
    Assert::same(0,$code,'actual onboarding repeat succeeds without a password or version upgrade');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM workspace_memberships'),'actual CLI adds no version2 membership');
    Assert::same($beforeMembership,$db->one('SELECT * FROM workspace_memberships WHERE membership_id=?',[$membership]),'actual CLI preserves existing v1 membership bytes');
    [$code,$report]=$runCli([dirname(__DIR__,2).'/ops/provision-role-vocabulary.php','--env='.$envPath]);
    Assert::true($code!==0,'vocabulary CLI requires explicit apply');
    [$code,$report]=$runCli([dirname(__DIR__,2).'/ops/provision-role-vocabulary.php','--env='.$envPath,'--apply']);
    Assert::same(0,$code,'vocabulary-only CLI can safely repeat');
    Assert::same(['permissions'=>0,'roles'=>0],$report['created']??null,'CLI repeat creates no definitions');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM workspace_memberships'),'vocabulary-only CLI assigns nothing');
} finally { unlink($envPath); }

$assigned = $accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN',2);
Assert::true($assigned->isOk(), 'separate explicit version2 assignment uses supported API');
$v2Membership = $assigned->value()['membership_id'];
Assert::same(2,(int)$db->scalar('SELECT role_version FROM workspace_memberships WHERE membership_id=?',[$v2Membership]),'membership pins version2');
foreach ($delta as $permission) Assert::same('ALLOW', $check($permission)['decision'], 'explicit v2 grants only scoped setup permission: '.$permission);
Assert::same('DENY',$check('approval.decide_exact_artifact')['decision'],'setup admin does not gain action approval');
Assert::same('DENY',$check('mission.activate')['decision'],'workspace operator remains separate');
foreach (Permissions::EXCLUDED as $permission) Assert::same('DENY',$check($permission)['decision'],'excluded remains denied: '.$permission);
$otherWorkspace = Seed::tenantWorkspace($db,$clock,'same-tenant-workspace');
// Use a second workspace in the SAME tenant, with no matching membership.
$db->run('UPDATE workspaces SET tenant_id=? WHERE workspace_id=?',[$scope['tenant_id'],$otherWorkspace['workspace_id']]);
foreach ($delta as $permission) Assert::same('DENY',$check($permission,$otherWorkspace['workspace_id'])['decision'],'v2 grants no sibling workspace authority');
Assert::same('DENY',$guard->evaluate('OPERATOR',$scope['operator_id'],$other['tenant_id'],$other['workspace_id'],$delta[0],'SETUP')['decision'],'cross-tenant remains refused');

$db->run("UPDATE workspace_memberships SET status='REVOKED' WHERE membership_id=?",[$v2Membership]);
Assert::same('MEMBERSHIP_NOT_ACTIVE',$accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN',2)->reasonCode(),'revoked v2 cannot be regranted by replay');
foreach ($delta as $permission) Assert::same('DENY',$check($permission)['decision'],'revoked v2 denies setup');
$db->run("UPDATE workspace_memberships SET status='ACTIVE', valid_until=? WHERE membership_id=?",[$clock->now(),$v2Membership]);
Assert::same('MEMBERSHIP_NOT_CURRENT',$accounts->addRoleMembership($scope['tenant_id'],$workspaceKey,$scope['operator_id'],'ROLE_TENANT_ADMIN',2)->reasonCode(),'expired v2 cannot be extended by replay');
foreach ($delta as $permission) Assert::same('DENY',$check($permission)['decision'],'expired v2 denies setup');
Assert::same($beforeMembership,$db->one('SELECT * FROM workspace_memberships WHERE membership_id=?',[$membership]),'all explicit v2 lifecycle acts leave v1 unchanged');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM monitor_execution_grants'),'no native execution admission');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM missions'),'no mission provisioned by role seeding/assignment');

// Conflicting immutable definitions are detected, never silently repaired/regranted.
$db->run('DELETE FROM role_permissions WHERE role_id=? AND permission_key=?',[$v2,$delta[0]]);
$caught=false;
try { $vocabulary->ensureVocabulary(); } catch (LogicException) { $caught=true; }
Assert::true($caught,'changed v2 permission set refuses reseed');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND permission_key=?',[$v2,$delta[0]]),'refusal did not regrant removed permission');
Assert::same($beforePermissions,$db->all('SELECT * FROM role_permissions WHERE role_id=? ORDER BY permission_key',[$legacy['role_id']]),'conflict does not alter v1');

// Neither reseeding nor the ordinary workspace-operator role substitutes for an explicit v2 seat.
$db->insert('role_permissions',['role_id'=>$v2,'permission_key'=>$delta[0]]);
$db->run("UPDATE roles SET status='SUSPENDED' WHERE role_id=?",[$v2]);
Assert::same(['permissions'=>0,'roles'=>0],$vocabulary->ensureVocabulary(),'inactive exact definition is retained');
Assert::same('SUSPENDED',$db->scalar('SELECT status FROM roles WHERE role_id=?',[$v2]),'reseed does not reactivate a role');
$otherKey=(string)$db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?',[$other['workspace_id']]);
Assert::true($accounts->addRoleMembership($other['tenant_id'],$otherKey,$other['operator_id'],'ROLE_WORKSPACE_OPERATOR')->isOk(),'separate workspace operator assigned');
foreach ($delta as $permission) Assert::same('DENY',$guard->evaluate('OPERATOR',$other['operator_id'],$other['tenant_id'],$other['workspace_id'],$permission,'SETUP')['decision'],'workspace operator alone lacks setup admin');
