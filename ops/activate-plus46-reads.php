<?php

declare(strict_types=1);

/**
 * Explicit TEST-only upgrade for exactly the existing 46@hmwh.se Plus 46
 * customer. No web route or automatic deploy hook may call this CLI.
 *
 *   php ops/provision-role-vocabulary.php --apply --env=/srv/allfather/.env
 *   php ops/activate-plus46-reads.php plan --env=/srv/allfather/.env
 *   php ops/activate-plus46-reads.php apply --env=/srv/allfather/.env \
 *        --plan-digest=<owner-reviewed SHA256> --ack=PLUS46_TEST_READ_V2
 *
 * Preserve the complete printed plan for separate owner review.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__.'/../app/autoload.php';

use Allfather\Loopia\Beta\Plus46ReadUpgrade;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;

$fail=static function (string $reason,int $code=2): never {
    fwrite(STDERR,'activate-plus46-reads: '.$reason.PHP_EOL);
    exit($code);
};
$command=$argv[1]??'';
if (!in_array($command,['plan','apply'],true)) $fail('command must be plan or apply');
$args=BetaCli::parseArgs([$argv[0],...array_slice($argv,2)],
    ['env','plan-digest','ack'],$fail);
if (!isset($args['env'])) $fail('explicit --env required');
$cfg=BetaCli::configuration(dirname(__DIR__),['env'=>$args['env']],$fail);
if ($cfg['environment']!=='staging') $fail('staging only; no production/Loopia effects',4);

$db=BetaCli::connect($cfg['env'],$fail);
$owner=new Plus46ReadUpgrade($db,new Clock());
try {
    if ($command==='plan') {
        $out=$owner->plan();
    } else {
        if (($args['ack']??'')!=='PLUS46_TEST_READ_V2') $fail('exact TEST acknowledgement required',4);
        if (!isset($args['plan-digest'])) $fail('owner-reviewed plan digest required',4);
        $out=$owner->apply((string)$args['plan-digest']);
    }
} catch (Throwable) {
    $fail('atomic scoped read upgrade failed; no partial changes committed',5);
}
if (!$out->isOk()) $fail('governed refusal: '.$out->reasonCode(),5);
BetaCli::emit([
    'status'=>$command==='plan'?'DRY_RUN_OK':'APPLIED',
    'operation'=>'PLUS46_TEST_READ_V2',
    'result'=>$out->value(),
    'external_actions_authorized'=>false,
    'model_calls_authorized'=>false,
    'data_reimported'=>false,
]);
