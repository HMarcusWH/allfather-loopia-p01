<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Substrate\M17\MemoryStore;

$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
foreach([
    [],['source_use'=>'ALLOW'],['U03_adopted'=>true],
    ['U04_signature'=>str_repeat('a',64)],['model_budget'=>100000],
] as $forgery){
    Assert::same('TM02_U03_U04_RUNTIME_FACTORY_NOT_ADOPTED',
        MemoryStore::forRuntime($db,$clock,$forgery)->reasonCode(),
        'no self-asserted owner decision can create runtime M17 writer');
}
$candidateFactory=MemoryStore::forDisposableTest($db,$clock);
if(defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')
    && constant('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')===true
    && PHP_SAPI==='cli') {
    Assert::true($candidateFactory->isOk(),
        'an earlier disposable integration test marker is still test-only');
} else {
    Assert::same('M17_U04_NOT_ADOPTED_PERSISTENCE_DISABLED',
        $candidateFactory->reasonCode(),
        'without the explicit test marker the candidate factory refuses');
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/app/Substrate/M17/MemoryStore.php');
foreach(['curl_exec(', 'file_get_contents(', 'ModelExecutor::', 'mail(', 'DELETE FROM',
         'DROP TABLE', 'unlink('] as $forbidden){
    Assert::false(str_contains($source,$forbidden),
        'derived M17 memory writer has no network/provider/source/erasure route');
}
Assert::true(str_contains($source,"PHP_SAPI !== 'cli'"),
    'candidate factory remains unusable through HTTP');
Assert::true(str_contains($source,'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED'),
    'only the disposable integration marker can permit TEST metadata writes');
Assert::true(str_contains($source,'P01WindowProfileV2::freeze'),
    'v2 run key cannot be supplied as arbitrary digest');
Assert::true(str_contains($source,'Replay::check'),
    'stored replay uses the same offline v1 deterministic derivation');
Assert::true(str_contains($source,'TM02_V2_PREDECESSOR_VERSION_MISMATCH'),
    'a v2 successor cannot silently link an old unversioned run');
Assert::true(str_contains($source,'P01_COMPETITOR'),
    'typed P01 subject, not an invented P02 twin');
