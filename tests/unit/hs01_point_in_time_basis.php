<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryReviewBasis,PointInTimeHistoricalBasis};

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$v['scope'],'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage'],
];
$request=[
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-16T00:00:00Z',
];
$a=PointInTimeHistoricalBasis::freeze($request);
Assert::true($a->isOk(),'HS-01 genuine synthetic basis freezes after legacy validation');
if (!$a->isOk()) return;
$basis=$a->value();
Assert::same('hs01.point-in-time-historical-basis.v1',$basis['schema_version'],'new version does not rewrite LI-05 v1');
Assert::true(PointInTimeHistoricalBasis::verify($basis)->isOk(),'new manifest verifies hash and shape');
Assert::same('INSUFFICIENT_BASIS',$basis['selection_integrity'],'reported schedule outage leaves a genuine gap');
Assert::same(['ref_a','ref_b'],array_column($basis['selected_references'],'ref_id'),
    'recorded-and-known-before-cutoff inputs selected in stable observation order');
Assert::same(1,$basis['selected_contrary_count'],'disconfirming evidence retained');
Assert::same(1,$basis['excluded_counts_by_reason']['NOT_YET_KNOWN'],'late-known observation is not hindsight evidence');
$lateExcluded=array_values(array_filter($basis['excluded_references'],
    static fn(array $x):bool=>$x['reason']==='NOT_YET_KNOWN'));
Assert::same(null,$lateExcluded[0]['ref_id'] ?? null,
    'future-known private identity is not exported through an as-of basis');
Assert::same('NOT_ASSESSED',$basis['rights_disposition'],'no historical rights inferred');
Assert::same(false,$basis['historical_reuse_admitted'],'metadata history grants no U03 authority');
Assert::same(false,$basis['coverage_owner_verified'],'caller-covered slots are not owner capture receipts');
Assert::same('LATE_KNOWN_INPUT_EXCLUDED',$basis['data_disposition'],'late inputs explicitly categorized');
Assert::same(null,$basis['selected_references'][0]['assessment_id'],'future analysis cannot enter earlier cutoff');
$preview=PointInTimeHistoricalBasis::preview($basis);
Assert::true($preview->isOk(),'bounded operator preview available');
Assert::false(str_contains(json_encode($preview->value(),JSON_THROW_ON_ERROR),'source_a'),
    'preview cannot leak source IDs or unreviewed claims');
Assert::same(false,$preview->value()['source_bytes_or_model_dispatch_available'],
    'preview never enables source access or provider dispatch');

$reversed=$request;
$reversed['legacy_input']['rows']=array_reverse($input['rows']);
Assert::same($basis['basis_digest'],PointInTimeHistoricalBasis::freeze($reversed)->value()['basis_digest'],
    'caller row permutation cannot change frozen input digest');
$cap=$request;
$cap['legacy_input']['max_references']=1;
$limited=PointInTimeHistoricalBasis::freeze($cap);
Assert::true($limited->isOk(),'bounded cap produces candidate');
Assert::same(['ref_b'],array_column($limited->value()['selected_references'],'ref_id'),
    'contrary observation protected ahead of supporting observations when capped');
Assert::same(1,$limited->value()['excluded_counts_by_reason']['SELECTION_CAP'],
    'supporting row omission accounted for');
Assert::same('INSUFFICIENT_BASIS',$limited->value()['selection_integrity'],
    'lost selected evidence cannot be called complete');
Assert::same(['ref_a'],array_column(HistoryReviewBasis::freeze($cap['legacy_input'])->value()['references'],'ref_id'),
    'legacy v1 order and digest semantics remain untouched');

$later=$request;
$later['legacy_input']['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
$later['review_at_utc']='2026-03-21T00:00:00Z';
$later['previous_basis_digest']=$basis['basis_digest'];
$next=PointInTimeHistoricalBasis::freeze($later);
Assert::true($next->isOk(),'late data produces explicit input-specific successor');
Assert::same(['ref_a','ref_b','ref_late'],
    array_column($next->value()['selected_references'],'ref_id'),
    'later knowledge cutoff admits earlier event captured but not known at first cutoff');
Assert::notSame($basis['basis_digest'],$next->value()['basis_digest'],
    'later input never alters frozen old hash');
Assert::same($basis['basis_digest'],$next->value()['previous_basis_digest'],
    'successor ancestry is recorded without mutating parent');
Assert::true(PointInTimeHistoricalBasis::verify($basis)->isOk(),'old basis still valid after successor');

$additional=$request;
$r=$input['rows'][3];
$r['ref_id']='new_earlier_event';$r['evidence_id']='new_evidence';
$r['snapshot_id']='new_snapshot';$r['source_ref_id']='new_source';
$r['assessment_id']=null;$r['analyzed_at']=null;
$r['observed_at']='2026-03-02T00:00:00Z';
$r['recorded_at']='2026-03-02T00:00:00Z';
$r['known_at']='2026-03-03T00:00:00Z';
$additional['legacy_input']['rows'][]=$r;
$sameCut=PointInTimeHistoricalBasis::freeze($additional);
Assert::true($sameCut->isOk(),'new input within cutoff produces valid successor candidate');
Assert::notSame($basis['basis_digest'],$sameCut->value()['basis_digest'],
    'input-specific successor does not collide with old cutoff');
Assert::same(3,$sameCut->value()['selected_count'],'new known capture changes membership');

$empty=$request;$empty['legacy_input']['rows']=[];
Assert::same('NO_INPUT_DATA',PointInTimeHistoricalBasis::freeze($empty)->value()['data_disposition'],
    'no data distinguished from no business change');
$missing=$request;$missing['legacy_input']['rows'][0]['reported_disposition']='MISSING_BYTES';
Assert::same('MISSING_BYTES_REPORTED',PointInTimeHistoricalBasis::freeze($missing)->value()['data_disposition'],
    'missing bytes distinguished from late data');
$wrong=$request;$wrong['calendar']='Europe/Stockholm';
Assert::same('HS01_REQUEST_CONTRACT_INVALID',PointInTimeHistoricalBasis::freeze($wrong)->reasonCode(),
    'timezone conversions are not guessed');
$wrong=$request;$wrong['review_at_utc']='2026-03-14T00:00:00Z';
Assert::same('HS01_REVIEW_PRECEDES_KNOWLEDGE_CUT',PointInTimeHistoricalBasis::freeze($wrong)->reasonCode(),
    'knowledge cutoff cannot be in the future relative to declared review');
$wrong=$request;$wrong['review_at_utc']='2026-03-15T01:00:00+01:00';
Assert::same('HS01_REQUEST_CONTRACT_INVALID',PointInTimeHistoricalBasis::freeze($wrong)->reasonCode(),
    'local/DST ambiguous timestamps refused rather than silently coerced');
$wrong=$request;$wrong['legacy_input']['rows'][0]['tenant_id']='other';
Assert::same('LI05_ROW_SCOPE_MISMATCH',PointInTimeHistoricalBasis::freeze($wrong)->reasonCode(),
    'foreign-scope input refused using native v1 contract');
$wrong=$basis;$wrong['selected_references'][0]['known_at']='2026-03-20T00:00:00Z';
Assert::same('HS01_BASIS_REFERENCE_INVALID',PointInTimeHistoricalBasis::verify($wrong)->reasonCode(),
    'future-known reference cannot enter saved basis');
$wrong=$basis;$wrong['source_input_digest']=str_repeat('0',64);
Assert::same('HS01_BASIS_DIGEST_MISMATCH',PointInTimeHistoricalBasis::verify($wrong)->reasonCode(),
    'changed input lineage fails digest');
$wrong=$basis;$wrong['provider_disclosure_authorized']=true;
$copy=$wrong;unset($copy['basis_digest']);$wrong['basis_digest']=Digest::canonicalDigest($copy);
Assert::same('HS01_BASIS_CONTRACT_INVALID',PointInTimeHistoricalBasis::verify($wrong)->reasonCode(),
    'self-hashed provider permission cannot be created');
