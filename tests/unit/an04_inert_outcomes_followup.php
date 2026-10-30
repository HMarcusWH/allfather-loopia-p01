<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Tests\{Assert,An03MockFinding};
use Allfather\Loopia\Workflow\{HistoryReviewOutcomes,HistoryReviewFollowUp};

$f=An03MockFinding::inputs();
Assert::true($f->isOk(),'AN04 later-outcome baseline reuses frozen AN03 synthetic lineage');
if(!$f->isOk())return;
$finding=HistoricalFindingAdapter::propose($f->value());
Assert::true($finding->isOk(),'original AN03 provisional finding is still unadmitted');
if(!$finding->isOk())return;
$original=$finding->value();
$later=[
    'ref_id'=>'an04_later_source_ref',
    'source_ref_id'=>'an04_later_owner_ref',
    'evidence_id'=>'an04_later_evidence',
    'snapshot_id'=>'an04_later_capture',
    'payload_sha256'=>str_repeat('e',64),
    'observed_at_utc'=>'2026-03-18T00:00:00Z',
    'known_at_utc'=>'2026-03-19T00:00:00Z',
    'polarity'=>'SUPPORT',
];
$input=[
    'finding'=>$original,
    'prior_annotation'=>null,
    'disposition'=>'REPORTED_CONFIRMED_IN_SCOPE',
    'new_evidence'=>[$later],
    'inspected_at_utc'=>'2026-10-29T12:00:00Z',
    'deadline_utc'=>'2026-11-01T12:00:00Z',
    'next_check_code'=>'HUMAN_VALIDATE_SOURCE',
];
$annot=HistoryReviewOutcomes::propose($input);
Assert::true($annot->isOk(),'later-known source is recorded without rewriting old finding');
if(!$annot->isOk())return;
$v=$annot->value();
Assert::true(HistoryReviewOutcomes::verify($v)->isOk(),
    'versioned candidate replays metadata-only digest');
Assert::same('OUTCOME_ANNOTATION_CANDIDATE_NOT_ADJUDICATED',$v['status'],
    'reported confirmation is not a verified commercial outcome');
Assert::same('REPORTED_CONFIRMED_IN_SCOPE',$v['reported_disposition'],
    'reported disposition remains distinguishable from owner authentication');
Assert::same($original['finding_digest'],$v['finding_digest'],
    'original historical review lineage remains unchanged');
Assert::same(false,$v['outcome_adjudicated_by_human'],
    'outcome requires separate domain evidence review');
Assert::same(false,$v['automatic_prompt_or_weight_update'],
    'no self-training from synthetic follow-up evidence');
Assert::same(false,$v['automatic_schedule_update'],
    'no self-adjusting recurrence or service grants');
Assert::same(false,$v['provider_or_fetch_authorized'],
    'a proposed next check cannot fetch or call an LLM');
$again=HistoryReviewOutcomes::propose($input);
Assert::same($v['annotation_digest'],$again->value()['annotation_digest'],
    'same later evidence produces same immutable annotation digest');

$bad=$input;
$bad['new_evidence'][0]['ref_id']='ref_a';
Assert::same('AN04_NEW_EVIDENCE_METADATA_INVALID_OR_BACKDATED',
    HistoryReviewOutcomes::propose($bad)->reasonCode(),
    'original M05 primary evidence ID cannot masquerade as later source');
$bad=$input;
$bad['new_evidence'][0]['known_at_utc']='2026-03-01T00:00:00Z';
Assert::same('AN04_NEW_EVIDENCE_METADATA_INVALID_OR_BACKDATED',
    HistoryReviewOutcomes::propose($bad)->reasonCode(),
    'known-before original cutoff cannot be reintroduced as new knowledge');
$bad=$input;$bad['disposition']='REPORTED_CONTRADICTED';
Assert::same('AN04_OUTCOME_CLAIM_WITHOUT_LATER_EVIDENCE',
    HistoryReviewOutcomes::propose($bad)->reasonCode(),
    'contradiction claim requires later contrary metadata, not mere wording');
$bad=$v;$bad['business_success_confirmed']=true;
$unsigned=$bad;unset($unsigned['annotation_digest']);
$bad['annotation_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN04_ANNOTATION_FORGED_AUTHORITY',
    HistoryReviewOutcomes::verify($bad)->reasonCode(),
    'rehashing a true-business-success flag cannot create adjudication');

$follow=HistoryReviewFollowUp::freeze([
    'annotation'=>$v,
    'followup_kind'=>'REQUEST_SOURCE_OWNER_REVIEW',
    'target_source_ref_ids'=>['an04_later_owner_ref'],
    'requested_at_utc'=>'2026-10-30T12:00:00Z',
    'not_before_utc'=>'2026-10-31T00:00:00Z',
    'deadline_utc'=>'2026-11-05T00:00:00Z',
    'operator_note_code'=>'REQUEST_OWNER_SOURCE_USE_AUTHORIZATION',
]);
Assert::true($follow->isOk(),'follow-up proposal binds reported source outcome digest');
if(!$follow->isOk())return;
Assert::true(HistoryReviewFollowUp::verify($follow->value())->isOk(),
    'follow-up candidate independently verifies');
Assert::same($v['annotation_digest'],$follow->value()['outcome_annotation_digest'],
    'future work is linked to its exact original annotation');
Assert::same(false,$follow->value()['new_capture_authorized'],
    'no source request or acquisition is authorized');
Assert::same('AN04_U03_U07_U10_SOURCE_OR_MODEL_ACTION_NOT_ADOPTED',
    HistoryReviewFollowUp::dispatch($follow->value())->reasonCode(),
    'self-approval cannot turn candidate into a native schedule/runner');

$next=$input;
$next['prior_annotation']=$v;
$next['disposition']='REPORTED_CONTRADICTED';
$next['inspected_at_utc']='2026-10-30T12:00:00Z';
$next['new_evidence'][0]['ref_id']='an04_later_contrary_ref';
$next['new_evidence'][0]['polarity']='CONTRARY';
$next['new_evidence'][0]['known_at_utc']='2026-10-30T10:00:00Z';
$next['new_evidence'][0]['observed_at_utc']='2026-10-30T09:00:00Z';
$successor=HistoryReviewOutcomes::propose($next);
Assert::true($successor->isOk(),'later contrary evidence appends a new provisional annotation');
if($successor->isOk()){
    Assert::same($v['annotation_digest'],$successor->value()['prior_annotation_digest'],
        'old outcome is preserved rather than overwritten');
    Assert::same($original['finding_digest'],$successor->value()['finding_digest'],
        'later outcome never rewrites historical as-of primary evidence');
    Assert::same(false,$successor->value()['m11_reliability_weight_admitted'],
        'contradictory outcome cannot automatically rewrite scoring weights');
}
