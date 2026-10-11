<?php

declare(strict_types=1);

/**
 * Create ONE new login for an ALREADY governed TEST operator, without minting a second operator.
 * Unlike onboard-beta-user.php this preserves the exact existing two role memberships.
 * A separate globally unused email is mandatory; passwords enter via stdin only.
 *
 * plan|verify --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *             --email=... --display-name='...'
 * create      same flags --plan-digest=<sha256> --ack=BIND_TEST_LOGIN_AND_BETA_TRIAL --password-stdin
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Auth\{AccountService,Authenticator,PasswordPolicy};
use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Product\P01\Entitlements;

$fail=static function (string $reason,int $code=2): never {
    fwrite(STDERR,'bind-existing-test-operator: '.$reason.PHP_EOL); exit($code);
};
$command=$argv[1]??'';
if (!in_array($command,['plan','create','verify'],true)) $fail('expected plan|create|verify');
$args=BetaCli::parseArgs([$argv[0],...array_slice($argv,2)],
    ['env','tenant-id','workspace-id','operator-id','email','display-name','plan-digest','ack','password-stdin'],$fail);
foreach (['env','tenant-id','workspace-id','operator-id','email','display-name'] as $required) {
    if (($args[$required]??'')==='' || ($args[$required]??'')==='1') $fail('--'.$required.' required');
}
if ($command!=='create' && (isset($args['plan-digest']) || isset($args['ack']) || isset($args['password-stdin']))) {
    $fail('read-only commands reject all write/password flags');
}
if ($command==='create' && (($args['ack']??'')!=='BIND_TEST_LOGIN_AND_BETA_TRIAL'
    || ($args['password-stdin']??'')!=='1'
    || !preg_match('/^[a-f0-9]{64}$/D',$args['plan-digest']??''))) $fail('create needs exact digest, approval and private stdin');
$config=BetaCli::configuration(dirname(__DIR__),['env'=>$args['env']],$fail);
if (!in_array($config['environment'],['testing','staging'],true)) $fail('TEST runtime required',4);
$db=BetaCli::connect($config['env'],$fail);$clock=new Clock();
$auth=new Authenticator($db,$clock);$account=new AccountService($db,$clock,$auth);
$beta=new BetaBundle($db,new Entitlements($db,$clock));
$tenant=$args['tenant-id'];$workspace=$args['workspace-id'];$operator=$args['operator-id'];
$email=AccountService::normalizeEmailAddress($args['email']);
if ($email==='' || !str_contains($email,'@')) $fail('email invalid');
$plan=static function () use ($db,$tenant,$workspace,$operator,$email,$args,$beta): array {
    $scope=$db->one('SELECT t.tenant_type,t.status AS tenant_status,w.workspace_type,w.environment_class,
        w.status AS workspace_status,w.external_side_effects_allowed,w.active_v1_capability_ceiling,
        o.status AS operator_status,o.display_name AS operator_name
        FROM tenants t JOIN workspaces w ON w.tenant_id=t.tenant_id
        JOIN operators o ON o.tenant_id=t.tenant_id AND o.operator_id=?
        WHERE t.tenant_id=? AND w.workspace_id=?',[$operator,$tenant,$workspace]);
    if ($scope===null || $scope['tenant_type']!=='TEST' || $scope['workspace_type']!=='TEST'
        || $scope['environment_class']!=='TEST' || $scope['tenant_status']!=='ACTIVE'
        || $scope['workspace_status']!=='ACTIVE' || $scope['operator_status']!=='ACTIVE'
        || (int)$scope['external_side_effects_allowed']!==0
        || $scope['active_v1_capability_ceiling']!=='RELEASE_INTELLIGENCE') {
        throw new RuntimeException('TEST_SCOPE_OR_OPERATOR_INVALID');
    }
    $rows=$db->all("SELECT m.membership_id,r.role_key,r.role_version,m.status,m.valid_from,m.valid_until FROM workspace_memberships m
        JOIN roles r ON r.role_id=m.role_id WHERE m.tenant_id=? AND m.workspace_id=?
        AND m.principal_id=? AND m.principal_type='OPERATOR'",[$tenant,$workspace,$operator]);
    $roleKeys=array_column($rows,'role_key'); sort($roleKeys,SORT_STRING);
    if ($roleKeys!==['ROLE_TENANT_ADMIN','ROLE_WORKSPACE_OPERATOR'])
        throw new RuntimeException('EXACT_TWO_APPROVED_ROLES_REQUIRED');
    $now=gmdate('Y-m-d\TH:i:s.000\Z');
    foreach ($rows as $row) if ($row['status']!=='ACTIVE' || (int)$row['role_version']!==1
        || $row['valid_from']>$now || ($row['valid_until']!==null && $row['valid_until']<=$now))
        throw new RuntimeException('NONACTIVE_OR_EXPIRED_MEMBERSHIP');
    $existing=$db->one('SELECT platform_user_id,email,status FROM platform_users WHERE operator_id=?',[$operator]);
    $other=$db->one('SELECT platform_user_id,operator_id FROM platform_users WHERE email=?',[$email]);
    $live=$db->all("SELECT ts.tenant_subscription_id,st.tier_key,ts.status FROM tenant_subscriptions ts
        JOIN subscription_tiers st ON st.subscription_tier_id=ts.subscription_tier_id
        WHERE ts.tenant_id=? AND ts.status IN ('TRIAL','ACTIVE')",[$tenant]);
    $tier=$beta->inspectTier();
    $blocked=[];
    if (!$tier->isOk() || $tier->value()['subscription_tier_id']===null) $blocked[]='BETA_TIER_NOT_AVAILABLE';
    if ($existing!==null && ($existing['email']!==$email || $existing['status']!=='ACTIVE')) $blocked[]='OPERATOR_LOGIN_CONFLICT';
    if ($other!==null && ($existing===null || $other['platform_user_id']!==$existing['platform_user_id'])) $blocked[]='EMAIL_GLOBALLY_USED';
    if (count($live)>1 || ($live!==[] && $live[0]['tier_key']!==BetaBundle::TIER_KEY)) $blocked[]='EXISTING_SUBSCRIPTION_CONFLICT';
    $facts=['tenant_id'=>$tenant,'workspace_id'=>$workspace,'operator_id'=>$operator,
        'email'=>$email,'display_name'=>$args['display-name'],'existing_login_id'=>$existing['platform_user_id']??null,
        'memberships'=>$rows,'live_subscriptions'=>$live,'tier_key'=>BetaBundle::TIER_KEY,
        'would_create_login'=>$existing===null,'would_create_trial'=>$live===[],'blocked_by'=>$blocked];
    return [...$facts,'plan_digest'=>Digest::canonicalDigest($facts)];
};
try { $before=$plan(); } catch(Throwable $e) { $fail('plan refused: '.$e->getMessage(),4); }
if ($command!=='create') BetaCli::emit(['status'=>$command==='plan'?'DRY_RUN_OK':'INSPECTED',...$before,
    'created_operator'=>false,'new_roles_granted'=>false,'external_effects_authorized'=>false]);
if ($before['blocked_by']!==[]) $fail('plan blocked: '.implode(',',$before['blocked_by']),5);
if (!hash_equals($before['plan_digest'],$args['plan-digest'])) $fail('plan digest changed',5);
if (!$before['would_create_login'] && !$before['would_create_trial']) {
    BetaCli::emit(['status'=>'ALREADY_PROVISIONED','operator_id'=>$operator,'changed'=>false]);
}
// The password is never printed, passed through argv, or stored in a temporary file.
$password=$before['would_create_login'] ? BetaCli::readPassword(true,'bind existing operator',$fail):null;
if ($password!==null && PasswordPolicy::validate($password)!==[]) $fail('password policy refused',5);
try {
$out=$db->transaction(static function () use ($db,$plan,$args,$account,$beta,$tenant,$workspace,$operator,$email,$password): Outcome {
    $scope=$db->one('SELECT workspace_id FROM workspaces WHERE tenant_id=? AND workspace_id=? FOR UPDATE',[$tenant,$workspace]);
    if ($scope===null) return Outcome::refuse('WORKSPACE_GONE');
    $again=$plan();
    if ($again['blocked_by']!==[] || !hash_equals($again['plan_digest'],$args['plan-digest'])) return Outcome::refuse('PLAN_CHANGED');
    $subscription=$again['would_create_trial']?$beta->subscribeTenant($tenant,false):Outcome::ok(['created'=>false]);
    if (!$subscription->isOk()) return $subscription;
    $login=$again['would_create_login']?$account->createLogin($operator,$email,$args['display-name'],(string)$password,true)
        :Outcome::ok(['created'=>false]);
    if (!$login->isOk()) return $login;
    return Outcome::ok(['login_created'=>$again['would_create_login'],
        'trial_created'=>$again['would_create_trial'], 'platform_user_id'=>$login->value()['platform_user_id']??null]);
});
} catch(Throwable $e) { $fail('atomic binding error (rolled back)',5); }
if (!$out->isOk()) $fail('atomic binding refused: '.$out->reasonCode(),5);
BetaCli::emit(['status'=>'BOUND','result'=>$out->value(),'operator_id'=>$operator,
    'must_change_password'=>true,'new_operator_created'=>false,'new_roles_granted'=>false]);
