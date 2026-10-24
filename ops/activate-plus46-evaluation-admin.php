<?php

declare(strict_types=1);

/**
 * Fixed Plus 46 TEST-only, owner-reviewed TenantAdmin v2 bootstrap.
 *
 * php ops/activate-plus46-evaluation-admin.php plan \
 *   --env=/srv/allfather/.env --valid-until=YYYY-MM-DDTHH:MM:SS.000Z
 *
 * php ops/activate-plus46-evaluation-admin.php apply \
 *   --env=/srv/allfather/.env --valid-until=SAME_TIMESTAMP \
 *   --plan-digest=SHA256_FROM_REVIEWED_PLAN --ack=PLUS46_TEST_EVALUATION_ADMIN_V2
 *
 * Requires root-controlled private Railway CLI. General P09 self-grant
 * refusal remains unchanged. No public HTTP path, credential, provider call,
 * product approval, schedule or subscription write exists here.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__.'/../app/autoload.php';

use Allfather\Loopia\Beta\Plus46EvaluationAdminBootstrap;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\SourceFingerprint;

$fail=static function (string $reason,int $code=2): never {
    fwrite(STDERR,'activate-plus46-evaluation-admin: '.$reason.PHP_EOL);
    exit($code);
};
$command=$argv[1]??'';
if (!in_array($command,['plan','apply'],true)) {
    $fail('command must be plan or apply');
}
$args=BetaCli::parseArgs([$argv[0],...array_slice($argv,2)],
    ['env','valid-until','plan-digest','ack'],$fail);
if (!isset($args['env']) || $args['env']==='1') $fail('explicit --env required');
if (!isset($args['valid-until']) || $args['valid-until']==='1') {
    $fail('explicit UTC --valid-until required');
}
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    $fail('a root-controlled private Railway CLI session is required',4);
}
$config=BetaCli::configuration(dirname(__DIR__),['env'=>$args['env']],$fail);
if ($config['environment']!=='staging') $fail('Railway staging only',4);
$release=(string)$config['env']->get('APP_RELEASE_REF','');
if (preg_match('/^[a-f0-9]{40}$/D',$release)!==1) {
    $fail('configured exact deployed Git release SHA is required',4);
}
if ($command==='plan' && (isset($args['plan-digest']) || isset($args['ack']))) {
    $fail('plan does not accept apply approval flags',4);
}
if ($command==='apply') {
    if (($args['ack']??'')!=='PLUS46_TEST_EVALUATION_ADMIN_V2') {
        $fail('owner approval acknowledgement required',4);
    }
    if (!isset($args['plan-digest']) || $args['plan-digest']==='1') {
        $fail('owner-reviewed SHA-256 plan digest required',4);
    }
}

$root=dirname(__DIR__);
$db=BetaCli::connect($config['env'],$fail);
try {
    $owner=new Plus46EvaluationAdminBootstrap(
        $db,new Clock(),$release,SourceFingerprint::compute($root),
    );
    $out=$command==='plan'
        ? $owner->plan((string)$args['valid-until'])
        : $owner->apply((string)$args['valid-until'],(string)$args['plan-digest']);
} catch (\Throwable) {
    $fail('scoped TEST operation failed; no partial change committed',5);
}
if (!$out->isOk()) {
    $fail('governed refusal: '.$out->reasonCode(),5);
}
BetaCli::emit([
    'status'=>$command==='plan'?'DRY_RUN_OK':'APPLIED',
    'operation'=>'PLUS46_BOUNDED_TEST_ADMIN_V2',
    'release_ref'=>$release,
    'result'=>$out->value(),
    'evaluation_carrier_created'=>false,
    'model_calls_authorized'=>false,
    'external_actions_authorized'=>false,
    'subscription_changed'=>false,
]);
