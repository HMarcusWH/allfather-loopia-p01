<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\P01HistoryAdapter;

$invalid=(new P01HistoryAdapter(static fn()=>['decision'=>'DENIED']))->inspect([
    'tenant_id'=>'t-a','workspace_id'=>'w-a','actor_type'=>'OPERATOR','actor_id'=>'op-a',
], 'competitor-a');
Assert::same('M17_P01_OWNER_READ_UNAVAILABLE',$invalid->reasonCode(),
    'owner refusal never converted to input-set authority');
$wrong=(new P01HistoryAdapter(static fn()=>['decision'=>'VISIBLE','required_permission'=>'evidence.read',
    'data'=>['read_only'=>true,'source_rights'=>'OWNER_SAYS_ALLOWED','rows'=>[]]]))->inspect([
        'tenant_id'=>'t-a','workspace_id'=>'w-a','actor_type'=>'OPERATOR','actor_id'=>'op-a',
], 'competitor-a');
Assert::same('M17_P01_OWNER_READ_UNAVAILABLE',$wrong->reasonCode(),
    'unrecognized owner rights string cannot grant a read');
$available=(new P01HistoryAdapter(static fn()=>['decision'=>'VISIBLE','required_permission'=>'evidence.read',
    'data'=>['read_only'=>true,'source_rights'=>'CURRENT_ELIGIBILITY_CHECKED','rows'=>[]]]))->inspect([
        'tenant_id'=>'t-a','workspace_id'=>'w-a','actor_type'=>'OPERATOR','actor_id'=>'op-a',
], 'competitor-a');
Assert::true($available->isOk(),'safe owner metadata can be projected without evidence');
Assert::same(false,$available->value()['input_set_frozen'],
    'owner-safe rows do not possess all historical source payload digests');
Assert::same(false,$available->value()['historical_reuse_admitted'],
    'current P01 read never authorizes historical compression');
Assert::same(false,$available->value()['historical_use_rights_checked'],
    'U03 historical rights remain unresolved');
$root=dirname(__DIR__,2);
foreach (['app/Http/Kernel.php','app/Workflow/MonitorService.php',
          'app/Workflow/ExecutionAdmission.php','app/Workflow/OwnerDecisionRegistry.php'] as $path) {
    $src=(string)file_get_contents($root.'/'.$path);
    Assert::false(str_contains($src,'new MemoryStore('),
        $path.' has no M17 writer wiring');
    Assert::false(str_contains($src,'M17_PERSISTENCE'),
        $path.' cannot grant U04 from text or route metadata');
}
