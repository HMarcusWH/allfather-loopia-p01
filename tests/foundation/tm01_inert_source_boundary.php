<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\P01WindowProfileV2;
use Allfather\Loopia\Workflow\HistoryM17V2CandidateBridge;

$root=dirname(__DIR__,2);
$d=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$entries=array_values(array_filter($d['build_packages'],static fn(array $p):bool=>($p['id']??null)==='TM-01'));
Assert::same(1,count($entries),'exactly one TM-01 package');
Assert::same('BUILT_INERT',$entries[0]['build_status'],'TM-01 source-only candidate status');
foreach(['native_permission','customer_visibility','production_activation',
    'live_migration','external_effects'] as $flag){
    Assert::same(false,$entries[0][$flag],'TM-01 no new effect: '.$flag);
}
Assert::same('tm01.p01-window-profile.v2',P01WindowProfileV2::VERSION,'new versioned M17 seam');
Assert::same('tm01.hs01-to-p01-window-profile-v2.v1',HistoryM17V2CandidateBridge::VERSION,
    'new versioned source bridge');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'candidate family 280 never installed by runtime');
foreach([
    'app/Substrate/M17/Vocabulary.php'=>'li06a.offline.vocabulary.v1',
    'app/Substrate/M17/InputSet.php'=>'li06a.offline-input-set.v1',
    'app/Substrate/M17/WindowEngine.php'=>'li06a.offline-window-engine.v1',
    'app/Substrate/M17/ProfileResolver.php'=>'li06a.offline-profile-resolver.v1',
] as $path=>$version){
    $s=(string)file_get_contents($root.'/'.$path);
    Assert::true(str_contains($s,$version),'v1 M17 version preserved: '.$path);
}
foreach(['app/Workflow/HistoryM17V2CandidateBridge.php',
    'app/Substrate/M17/P01WindowProfileV2.php'] as $file){
    $s=(string)file_get_contents($root.'/'.$file);
    Assert::false(str_contains($s,'DB_PASSWORD'),'no secrets in M17 candidates');
    Assert::false(str_contains($s,'curl_exec('),'no direct network transport');
    Assert::false(str_contains($s,'public function persistCandidate('),'not a live M17 writer');
}
