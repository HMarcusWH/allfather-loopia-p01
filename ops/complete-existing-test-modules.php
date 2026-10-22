<?php

declare(strict_types=1);

/**
 * Complete P01-P05 registrations for an EXISTING TEST tenant/workspace/operator.
 * Private CLI; never provisions identities, roles, subscriptions or execution grants.
 *
 * plan|verify --env=... --tenant-id=... --workspace-id=... --operator-id=...
 * apply       same flags --plan-digest=<sha256> --ack=REGISTER_TEST_MODULES
 *
 * For an already-present module, ANY ambiguous/inactive/changed registration blocks the operation.
 * Never use provision-staging.php --allow-existing merely to fill missing modules: it creates an
 * additional operator and the P02-P05 registrations are not replay-idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\{Clock, Digest, Outcome};
use Allfather\Loopia\Ops\{BetaCli,BootstrapVerification};
use Allfather\Loopia\Product\P01\{Entitlements, Registration as P01Registration};
use Allfather\Loopia\Product\P02\Registration as P02Registration;
use Allfather\Loopia\Product\P03\{Assurance as P03Assurance, Registration as P03Registration};
use Allfather\Loopia\Product\P04\Registration as P04Registration;
use Allfather\Loopia\Product\P05\Registration as P05Registration;
use Allfather\Loopia\Substrate\M03\{Eligibility, Registry as M03Registry};
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

$fail = static function (string $msg, int $status = 2): never {
    fwrite(STDERR, "complete-test-modules: {$msg}\n"); exit($status);
};
$command = $argv[1] ?? '';
if (!in_array($command, ['plan','apply','verify'], true)) $fail('expected plan|apply|verify');
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','plan-digest','ack'], $fail);
foreach (['env','tenant-id','workspace-id','operator-id'] as $field) {
    if (($args[$field] ?? '') === '' || $args[$field] === '1') $fail("--{$field} required");
}
if ($command !== 'apply' && (isset($args['plan-digest']) || isset($args['ack']))) {
    $fail('plan/verify cannot accept execution flags');
}
if ($command === 'apply' && (($args['ack'] ?? '') !== 'REGISTER_TEST_MODULES'
    || !preg_match('/^[a-f0-9]{64}$/D', $args['plan-digest'] ?? ''))) {
    $fail('apply requires exact --plan-digest and --ack=REGISTER_TEST_MODULES');
}
$config = BetaCli::configuration(dirname(__DIR__), ['env'=>$args['env']], $fail);
if (!in_array($config['environment'], ['staging','testing'], true)) $fail('not a TEST runtime', 4);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$tenant = $args['tenant-id']; $workspace = $args['workspace-id']; $operator = $args['operator-id'];
$classes = [
    'P01'=>'COMPETITION_MONITOR_VALKYRIE',
    'P02'=>'ENTITY_TWIN_CURATOR_VALKYRIE',
    'P03'=>'LEAD_QUALIFIER_VALKYRIE',
    'P04'=>'INTELLIGENCE_DISTRIBUTOR_VALKYRIE',
    'P05'=>'MARKETING_CUSTOMIZER_VALKYRIE',
];
$plan = static function () use ($db,$clock,$tenant,$workspace,$operator,$classes): array {
    // Re-evaluate at the time of EACH snapshot, especially after acquiring FOR UPDATE.
    $at=$clock->now();
    $scope = $db->one('SELECT t.tenant_key,t.tenant_type,t.status AS tenant_status,
        w.workspace_key,w.workspace_type,w.environment_class,w.status AS workspace_status,
        w.external_side_effects_allowed,w.active_v1_capability_ceiling,w.created_by_operator_id,
        o.status AS operator_status FROM tenants t JOIN workspaces w ON w.tenant_id=t.tenant_id
        JOIN operators o ON o.tenant_id=t.tenant_id AND o.operator_id=?
        WHERE t.tenant_id=? AND w.workspace_id=?', [$operator,$tenant,$workspace]);
    if ($scope === null || $scope['tenant_type'] !== 'TEST' || $scope['workspace_type'] !== 'TEST'
        || $scope['environment_class'] !== 'TEST' || $scope['tenant_status'] !== 'ACTIVE'
        || $scope['workspace_status'] !== 'ACTIVE' || $scope['operator_status'] !== 'ACTIVE'
        || (int)$scope['external_side_effects_allowed'] !== 0
        || $scope['active_v1_capability_ceiling'] !== 'RELEASE_INTELLIGENCE'
        || $scope['created_by_operator_id'] !== $operator) {
        throw new RuntimeException('TEST_SCOPE_OR_ORIGINAL_OPERATOR_MISMATCH');
    }
    $admin = $db->one("SELECT 1 FROM workspace_memberships m
        JOIN roles r ON r.role_id=m.role_id AND r.status='ACTIVE' AND r.role_version=1
        JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_key='tenant.administer'
        WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_type='OPERATOR'
        AND m.principal_id=? AND m.status='ACTIVE'
        AND m.valid_from <= ? AND (m.valid_until IS NULL OR m.valid_until > ?)
        LIMIT 1", [$tenant,$workspace,$operator,$at,$at]);
    if ($admin === null) throw new RuntimeException('EXISTING_TENANT_ADMIN_PERMISSION_REQUIRED');
    $modules=[];
    foreach ($classes as $key=>$role) {
        $rows=$db->all('SELECT v.valkyrie_id,v.status,v.active_capability_contract_id,
            c.role_class AS contract_role,c.status AS contract_status,c.tenant_id AS contract_tenant,
            c.workspace_id AS contract_workspace,c.valid_from AS contract_valid_from,
             c.valid_until AS contract_valid_until FROM valkyries v
            LEFT JOIN capability_contracts c ON c.capability_contract_id=v.active_capability_contract_id
            WHERE v.tenant_id=? AND v.workspace_id=? AND v.canonical_role_class=?
            ORDER BY v.valkyrie_id',[$tenant,$workspace,$role]);
        if ($rows === []) $modules[$key]=['action'=>'CREATE','role'=>$role];
        elseif (count($rows) === 1 && $rows[0]['status'] === 'ACTIVE'
            && $rows[0]['contract_status'] === 'ACTIVE' && $rows[0]['contract_role'] === $role
            && $rows[0]['contract_tenant'] === $tenant && $rows[0]['contract_workspace'] === $workspace
             && $rows[0]['contract_valid_from'] !== null && $rows[0]['contract_valid_from'] <= $at
             && ($rows[0]['contract_valid_until'] === null || $rows[0]['contract_valid_until'] > $at)) {
            $modules[$key]=['action'=>'REUSE','role'=>$role,'valkyrie_id'=>$rows[0]['valkyrie_id'],
                'contract_id'=>$rows[0]['active_capability_contract_id']];
        } else $modules[$key]=['action'=>'BLOCK','role'=>$role,'reason'=>'AMBIGUOUS_OR_NONACTIVE_REGISTRATION'];
    }
    $fingerprint = ['tenant_id'=>$tenant,'workspace_id'=>$workspace,'operator_id'=>$operator,
        'scope'=>$scope,'modules'=>$modules];
    return ['scope'=>$scope,'modules'=>$modules,'blocked'=>array_keys(array_filter($modules,
        static fn(array $row): bool => $row['action']==='BLOCK')),
        'plan_digest'=>Digest::canonicalDigest($fingerprint)];
};
try { $before=$plan(); } catch (Throwable $e) { $fail($e->getMessage(), 4); }
if ($command==='plan') {
    BetaCli::emit(['status'=>'DRY_RUN_OK', ...$before,
        'writes_performed'=>0,'native_execution_enabled'=>false]);
}
if ($command==='verify') {
    $complete=BootstrapVerification::modulesComplete($before);
    BetaCli::emit(['status'=>$complete?'VERIFIED':'INCOMPLETE', ...$before,
        'writes_performed'=>0,'native_execution_enabled'=>false], $complete?0:5);
}
if ($before['blocked'] !== []) $fail('blocked modules: '.implode(',',$before['blocked']), 5);
if (!hash_equals($before['plan_digest'],$args['plan-digest'])) $fail('plan digest changed', 5);
try {
$operation = $db->transaction(static function () use ($db,$clock,$tenant,$workspace,$operator,$args,$plan): Outcome {
    $lock=$db->one('SELECT workspace_id FROM workspaces WHERE tenant_id=? AND workspace_id=? FOR UPDATE',
        [$tenant,$workspace]);
    if ($lock === null) return Outcome::refuse('WORKSPACE_NOT_FOUND');
    $current=$plan();
    if ($current['blocked']!==[] || !hash_equals($current['plan_digest'],$args['plan-digest'])) {
        return Outcome::refuse('PLAN_CHANGED_OR_BLOCKED');
    }
    $m03=new M03Registry($db,$clock); $elig=new Eligibility($db,$clock);
    $tools=new ToolRegistry($db,$clock); $policies=new PolicySets($db,$clock);
    $ent=new Entitlements($db,$clock); $ledger=new DecisionLedger($db,$clock);
    $scope=['tenant_id'=>$tenant,'workspace_id'=>$workspace,'operator_id'=>$operator];
    $registrars=[
        'P01'=>static fn()=> (new P01Registration($db,$clock,$m03,$elig,$tools,$policies,
            new ReleaseOutput($db,$clock,$ledger),$ent))->registerCompetitionMonitor($scope),
        'P02'=>static fn()=> (new P02Registration($db,$clock,$m03,$elig,$tools,$policies,$ent))
            ->registerEntityTwinCurator($scope),
        'P03'=>static fn()=> (new P03Registration($db,$clock,$m03,$elig,$tools,$policies,$ent,
            new P03Assurance($db,$clock,new M11Assurance($db,$clock,$ledger))))
            ->registerLeadQualification($scope),
        'P04'=>static fn()=> (new P04Registration($db,$clock,$m03,$elig,$tools,$policies,$ent))
            ->registerIntelligenceDistribution($scope),
        'P05'=>static fn()=> (new P05Registration($db,$clock,$m03,$elig,$tools,$policies,$ent))
            ->registerMarketingCustomization($scope),
    ];
    $created=[];
    foreach ($current['modules'] as $key=>$item) {
        if ($item['action']!=='CREATE') continue;
        $outcome=$registrars[$key]();
        if (!$outcome->isOk()) return Outcome::refuse($key.'_REGISTRATION_REFUSED',
            ['reason_code'=>$outcome->reasonCode()]);
        $created[]=$key;
    }
    return Outcome::ok(['created'=>$created]);
});
} catch (Throwable $e) { $fail('atomic registration error (rolled back)', 5); }
if (!$operation->isOk()) $fail('atomic registration refused: '.$operation->reasonCode(), 5);
$after=$plan();
if (!BootstrapVerification::modulesComplete($after)) $fail('postwrite module verification incomplete',6);
BetaCli::emit(['status'=>'APPLIED','created_modules'=>$operation->value()['created'],
    'module_status'=>$after['modules'],'tenant_id'=>$tenant,'workspace_id'=>$workspace,
    'external_effects_authorized'=>false,'subscription_changed'=>false,'roles_granted'=>false]);
