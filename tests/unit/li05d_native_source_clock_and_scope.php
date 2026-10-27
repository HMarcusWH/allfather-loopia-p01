<?php

declare(strict_types=1);

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\NativeCaptureHistoryCandidate;

// Validate malformed input before accessing any actual DB, source rights,
// content store or scheduler. No external I/O or source data is required.
$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
$reader=new NativeCaptureHistoryCandidate(
    $db,$clock,new EvidenceStore(sys_get_temp_dir().'/li05d-unit-unused-'.Ids::new()));
$actor=[
    'tenant_id'=>Ids::new(),'workspace_id'=>Ids::new(),
    'actor_type'=>'OPERATOR','actor_id'=>Ids::new(),
];
$competitor=Ids::new();$question=Ids::new();
$period=['start_inclusive_utc'=>'2026-10-01T00:00:00Z',
    'end_exclusive_utc'=>'2026-11-01T00:00:00Z'];
$cutoff='2026-10-27T00:00:00Z';

$bad=$actor;$bad['tenant_id']='';
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($bad,$competitor,$question,$period,$cutoff)->reasonCode(),
    'blank tenant cannot fetch owner captures');
$bad=$actor;$bad['actor_type']='MODEL';
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($bad,$competitor,$question,$period,$cutoff)->reasonCode(),
    'model principal cannot invent historical actor scope');
$bad=$actor;$bad['permission_granted']=true;
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($bad,$competitor,$question,$period,$cutoff)->reasonCode(),
    'unknown claimed permission is never accepted as source rights');
$bad=$period;$bad['end_exclusive_utc']=$period['start_inclusive_utc'];
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($actor,$competitor,$question,$bad,$cutoff)->reasonCode(),
    'zero-width lookback window refused');
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($actor,$competitor,$question,$period,$cutoff,33)->reasonCode(),
    'reference input cap cannot be widened without owner review');
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($actor,$competitor,$question,$period,$cutoff,0)->reasonCode(),
    'zero reference capacity refused');
Assert::same('LI05D_NATIVE_BASIS_SELECTOR_INVALID',
    $reader->preview($actor,$competitor,$question,$period,'2026-10-27')->reasonCode(),
    'noncanonical date cannot create point-in-time source claim');

$floor=new ReflectionMethod(NativeCaptureHistoryCandidate::class,'floorUtcSecond');
$ceil=new ReflectionMethod(NativeCaptureHistoryCandidate::class,'ceilUtcSecond');
Assert::same('2026-10-26T18:00:00Z',
    $floor->invoke(null,'2026-10-26T18:00:00.999Z'),
    'observed-at remains a conservative physical-capture floor');
Assert::same('2026-10-26T18:00:01Z',
    $ceil->invoke(null,'2026-10-26T18:00:00.001Z'),
    'known-at is rounded UP, not silently backdated');
Assert::same('2026-10-26T18:00:00Z',
    $ceil->invoke(null,'2026-10-26T18:00:00.000Z'),
    'exact whole-second known time remains stable');
Assert::same('2026-10-27T00:00:00Z',
    $ceil->invoke(null,'2026-10-26T23:59:59.999Z'),
    'known-at ceiling handles day rollover without earlier inclusion');
Assert::same(null,$ceil->invoke(null,'2026-10-26T25:00:00.000Z'),
    'invalid claimed UTC timestamp cannot establish historical knowledge');
Assert::same(null,$floor->invoke(null,'garbage'),
    'malformed clock cannot be converted into a P01 event');
