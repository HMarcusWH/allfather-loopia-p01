<?php

declare(strict_types=1);

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2};
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryM17V2CandidateBridge};

$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
Assert::same('TM02_U03_U04_RUNTIME_FACTORY_NOT_ADOPTED',
    MemoryStore::forRuntime($db,$clock,[
        'owner_decision'=>'APPROVED_BY_CALLER',
    ])->reasonCode(),
    'unratified source owner cannot construct a production M17 writer');
Assert::same('M17_U04_NOT_ADOPTED_PERSISTENCE_DISABLED',
    MemoryStore::forDisposableTest($db,$clock)->reasonCode(),
    'disposable writer without explicitly installed test schema refuses');

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$v['scope'],'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,
    'coverage'=>[
        'state'=>'KNOWN','plan_version_id'=>'synthetic.tm02',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
        'failed'=>0,'skipped'=>0,'unreported'=>0,
    ],
];
$basis=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-16T00:00:00Z',
]);
Assert::true($basis->isOk(),'TM02 uses a real versioned HS01 metadata contract');
if(!$basis->isOk())return;
$bridge=HistoryM17V2CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
Assert::true($bridge->isOk(),'TM02 consumes the exact TM01 P01 M17 derivation');
if(!$bridge->isOk())return;
$candidate=$bridge->value()['candidate_v2'];
Assert::true(P01WindowProfileV2::verify($candidate)->isOk(),
    'exact v2 run identity verified independently of persistence');
Assert::same('P01_COMPETITOR',$candidate['run_identity']['subject_kind'],
    'v2 run never masquerades as P02 twin');
Assert::same($v['scope']['question_id'],$candidate['run_identity']['question_id'],
    'question identity is part of the run digest');
Assert::same(1,$candidate['contrary_reference_count'],
    'contrary reference cannot vanish during memory derivation');
Assert::same(false,$candidate['native_persistence_authorized'],
    'valid candidate never grants runtime 280 persistence');
Assert::same(false,$bridge->value()['model_or_customer_output_authorized'],
    'M17 memory remains derived statistics not an M10 release');

$late=$input;
$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
$next=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$late,
    'previous_basis_digest'=>$basis->value()['basis_digest'],
    'review_at_utc'=>'2026-03-21T00:00:00Z',
]);
Assert::true($next->isOk(),'late-known source creates new candidate, not overwrite');
$suc=HistoryM17V2CandidateBridge::compose($next->value(),'SOURCE_DIFF','MONTH');
Assert::true($suc->isOk(),'same-period successor has deterministic frozen inputs');
if($suc->isOk()){
    Assert::notSame($candidate['derivation_identity_digest'],
        $suc->value()['m17_v2_run_digest'],
        'late data changes run identity without changing old identity');
    Assert::same($bridge->value()['frozen_m17_inputs']['profile_candidate']['resolution_digest'],
        $suc->value()['frozen_m17_inputs']['profile_candidate']['resolution_digest'],
        'same-period profile is frozen at beginning, no unjustified expansion');
}
