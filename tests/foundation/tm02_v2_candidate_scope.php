<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$tm=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='TM-02'));
Assert::same(1,count($tm),'one TM-02 package in machine manifest');
Assert::same('BUILT_INERT',$tm[0]['build_status'],
    'TM02 source candidate cannot be mistaken for native runtime activation');
foreach(['native_permission','customer_visibility','production_activation',
         'live_migration','external_effects'] as $key){
    Assert::same(false,$tm[0][$key],'TM02 is inert: '.$key);
}
Assert::true(is_file($root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql'),
    'original 280 seven table proposal remains outside migrator');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'no live 280 database migration added');
Assert::same('li07b.candidate-memory-store.v1',MemoryStore::VERSION,
    'existing v1 store continues to replay historical identities unchanged');
Assert::same('tm01.p01-window-profile.v2',P01WindowProfileV2::VERSION,
    'new versioned run identity comes from TM01 owner-selected contract');
$store=(string)file_get_contents($root.'/app/Substrate/M17/MemoryStore.php');
foreach(['persistCandidate(', 'persistP01V2Candidate(',
         'replayCandidate(', 'replayP01V2Candidate(',
         'TM02_U03_U04_RUNTIME_FACTORY_NOT_ADOPTED'] as $tag) {
    Assert::true(str_contains($store,$tag),'required v1/v2 method/boundary: '.$tag);
}
foreach(['ops/temporal/memory-candidate.php',
         'ops/temporal/replay-candidate.php'] as $path){
    Assert::true(is_file($root.'/'.$path),'operator candidate command exists: '.$path);
    $source=(string)file_get_contents($root.'/'.$path);
    Assert::true(str_contains($source,"PHP_SAPI !== 'cli'"),
        'candidate CLI refuses browser before import');
}
