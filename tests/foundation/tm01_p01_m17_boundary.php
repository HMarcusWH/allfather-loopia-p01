<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2,Vocabulary};
use Allfather\Loopia\Workflow\HistoryM17V2CandidateBridge;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$rows=array_values(array_filter($matrix['build_packages'],
    static fn(array $entry):bool=>($entry['id']??null)==='TM-01'));
Assert::same(1,count($rows),'exactly one TM01 capability item');
Assert::same('BUILT_INERT',$rows[0]['build_status'],'TM01 is source candidate, not authority');
foreach(['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $flag) {
    Assert::same(false,$rows[0][$flag],'TM01 must not grant '.$flag);
}
Assert::same('tm01.p01-window-profile.v2',P01WindowProfileV2::VERSION,
    'new typed contract has its own version');
Assert::same('li07b.candidate-memory-store.v1',MemoryStore::VERSION,
    'v1 M17 memory store retains original version and hash law');
Assert::same(['SOURCE_DIFF','COMPANY_SIGNAL'],Vocabulary::SUPPORTED_CLASSES,
    'existing closed M17 supported class list unchanged');
Assert::true(is_file($root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql'),
    'M17 persistence family 280 remains candidate only');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'no live runtime M17 schema migration');
$source=(string)file_get_contents($root.'/app/Workflow/HistoryM17V2CandidateBridge.php');
Assert::true(str_contains($source,'TM01_U03_U04_AND_LIVE_M17_OWNER_ADMISSION_PENDING'),
    'no unsafe activate implementation');
Assert::false(str_contains($source,'new CurlHttpsTransport'),
    'new M17 bridge cannot create live acquisition');
