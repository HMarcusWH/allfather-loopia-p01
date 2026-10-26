<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\{Lineage,RollupEngine};
use Allfather\Loopia\Tests\{Assert,Li07aFixture};

require_once dirname(__DIR__) . '/support/Li07aFixture.php';
$v=Li07aFixture::data();
$before=RollupEngine::derive(Li07aFixture::inputs())->value();
$late=[...$v['refs'],$v['late_ref']];
$after=RollupEngine::derive(Li07aFixture::inputs($late))->value();
Assert::notSame($before['rollup_digest'],$after['rollup_digest'],'extra in-period ref generates a new candidate digest');
Assert::same(['ref_a','ref_b','ref_c'],array_column($before['lineage']['selected_refs'],'ref_id'),
    'old candidate bytes remain independent of successor');
$c=Lineage::proposeLateSuccessor($before,$after);
Assert::true($c->isOk(),'same-period new ref creates an explicit successor proposal');
if($c->isOk()){
  $q=$c->value();
  Assert::same('OFFLINE_SUCCESSOR_PROPOSAL_NOT_ADMITTED',$q['status'],'U04 identity is not assumed');
  Assert::same(['ref_late'],$q['new_selected_ref_ids'],'only genuinely new selected refs reported');
  Assert::same($before['rollup_digest'],$q['supersedes_rollup_digest'],'old digest references immutable predecessor');
  Assert::same($after['rollup_digest'],$q['successor_rollup_digest'],'new digest references successor');
  Assert::same(true,$q['old_rollup_unchanged'],'proposal does not mutate old summary');
  Assert::same(false,$q['canonical_U04_identity_adopted'],'M17 uniqueness not ratified');
  Assert::same(false,$q['historical_reuse_admitted'],'successor does not create source use authority');
}
Assert::same('M17_SUCCESSOR_NO_NEW_SELECTED_INPUT',Lineage::proposeLateSuccessor($before,$before)->reasonCode(),
    'same input idempotence cannot create a successor');
$lost=RollupEngine::derive(Li07aFixture::inputs([$v['refs'][0],$v['refs'][1],$v['refs'][3],$v['late_ref']]))->value();
Assert::same('M17_SUCCESSOR_WOULD_DROP_OR_REWRITE_INPUT',Lineage::proposeLateSuccessor($before,$lost)->reasonCode(),
    'dropping disconfirming observation cannot be silently called a successor');
$outlierOmitted=[$v['refs'][0],$v['refs'][1],$v['refs'][2],$v['late_ref']];
$withoutOutside=RollupEngine::derive(Li07aFixture::inputs($outlierOmitted))->value();
Assert::same('M17_SUCCESSOR_WOULD_DROP_OR_RECLASSIFY_OUTSIDE_REF',
    Lineage::proposeLateSuccessor($before,$withoutOutside)->reasonCode(),
    'predecessor out-of-window reference identity is not silently dropped from lineage');
$changed=$late;
$changed[1]['payload_sha256']=str_repeat('b',64);
$mutated=RollupEngine::derive(Li07aFixture::inputs($changed))->value();
Assert::same('M17_SUCCESSOR_WOULD_DROP_OR_REWRITE_INPUT',Lineage::proposeLateSuccessor($before,$mutated)->reasonCode(),
    'rewriting old ref hash fails even with extra late data');
$other=$v['scope'];$other['workspace_id']='workspace_other';
$foreign=RollupEngine::derive(Li07aFixture::inputs($late,$other))->value();
Assert::same('M17_SUCCESSOR_SCOPE_PERIOD_OR_PROFILE_MISMATCH',Lineage::proposeLateSuccessor($before,$foreign)->reasonCode(),
    'tenant/workspace isolation controls successors');
$week=RollupEngine::derive(Li07aFixture::inputs($late,null,'WEEK'))->value();
Assert::same('M17_SUCCESSOR_SCOPE_PERIOD_OR_PROFILE_MISMATCH',Lineage::proposeLateSuccessor($before,$week)->reasonCode(),
    'a different resolution window cannot supersede a DAY candidate as same period');
