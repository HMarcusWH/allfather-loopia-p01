<?php

declare(strict_types=1);

use Allfather\Loopia\Workflow\{HistoryReviewBasis,HistoryM17CandidateBridge};
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\MemoryStore;

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),true,64,JSON_THROW_ON_ERROR);
$input=['scope'=>$v['scope'],'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage']];
$basis=HistoryReviewBasis::freeze($input);
Assert::true($basis->isOk(),'reuse exact LI05A normalized point-in-time basis');
$joined=HistoryM17CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
Assert::true($joined->isOk(),'derive M17 MONTH rollup from a complete March metadata basis');
if($joined->isOk()) {
    $v=$joined->value();
    Assert::same(2,$v['lineage_count'],'two March metadata references survive the bridge');
    Assert::same('NOT_ADMITTED',$v['candidate_state'],'bridge does not admit evidence');
    Assert::same(false,$v['historical_reuse_admitted'],'M05/historical source use stays unratified');
    Assert::same(false,$v['runtime_persistence_authorized'],'no G21 runtime right minted');
    Assert::same(false,$v['model_or_customer_output_authorized'],'no model/release permission minted');
    Assert::same($basis->value()['manifest_digest'],$v['source_basis_digest'],'exact source cutoff digest retained');
    $m=MemoryStore::preview($v['frozen_m17_inputs']);
    Assert::true($m->isOk(),'existing M17 derivation preview accepts frozen bridge inputs');
    Assert::same($v['m17_derivation_identity_digest'],$m->value()['derivation_identity_digest'],
        'bridge identity uses existing digest, not a new key law');
    $lineage=$m->value()['rollup']['lineage'];
    Assert::same(2,$lineage['selected_count'],'all primary refs retained inside target M17 period');
    Assert::same(false,$v['model_or_customer_output_authorized'],'metadata cannot trigger downstream execution');
    Assert::same('LI07C_U03_AND_RUNTIME_GRANTS_NOT_ADOPTED',
        HistoryM17CandidateBridge::activate($v)->reasonCode(),
        'no bridge method activates a model or persistence writer');
}
$wrong=HistoryM17CandidateBridge::compose($basis->value(),'SOURCE_DIFF','DAY');
Assert::same('LI07C_PERIOD_WINDOW_MISMATCH',$wrong->reasonCode(),
    'a month basis cannot silently become a one-day memory with dropped references');
$late=$input;
$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
$successor=HistoryReviewBasis::freeze($late);
$second=HistoryM17CandidateBridge::compose($successor->value(),'SOURCE_DIFF','MONTH');
Assert::true($second->isOk(),'late-known evidence becomes a new independent M17 candidate');
Assert::same(3,$second->value()['lineage_count'],'late-known contrary input retained');
Assert::notSame($joined->value()['m17_derivation_identity_digest'],
    $second->value()['m17_derivation_identity_digest'],
    'new period input produces new immutable derivation identity');
$untrusted=$basis->value();
$untrusted['historical_reuse_admitted']=true;
Assert::same('LI07C_BASIS_NOT_ADMISSIBLE_FOR_CANDIDATE',
    HistoryM17CandidateBridge::compose($untrusted,'SOURCE_DIFF','MONTH')->reasonCode(),
    'caller cannot upgrade source-use flag to pass bridge');
$incomplete=$basis->value();
$incomplete['selection_integrity']='INSUFFICIENT_BASIS';
Assert::same('LI07C_BASIS_NOT_ADMISSIBLE_FOR_CANDIDATE',
    HistoryM17CandidateBridge::compose($incomplete,'SOURCE_DIFF','MONTH')->reasonCode(),
    'missing/capped contrary evidence does not produce complete-looking memory');
