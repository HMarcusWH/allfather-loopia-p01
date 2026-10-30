<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Tests\{Assert,Aq02MockAttention,Aq02FixtureAttentionOwner};
use Allfather\Loopia\Workflow\HistoryReviewAssurance;

if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST'))define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
$f=Aq02MockAttention::input();
Assert::true($f->isOk(),'exact AN03 and AN04 synthetic source lineage available');
if(!$f->isOk())return;
$attention=InternalAttention::composeFixture(
    $f->value(),new Aq02FixtureAttentionOwner(),
);
Assert::true($attention->isOk(),'fixed AQ02 owner fixture produced synthetic attention');
if(!$attention->isOk())return;
$obs=M17Assurance::inspect(
    $f->value()['native_release_proposal'],
    $f->value()['outcome_annotation'],
    $attention->value(),
    '2026-10-30T12:00:00Z',
);
Assert::true($obs->isOk(),'M17 local graph, scope and later observation inspect');
if(!$obs->isOk())return;
Assert::true(M17Assurance::verify($obs->value())->isOk(),
    'local metadata hash and authority flags independently verify');
Assert::same(2,$obs->value()['selected_reference_count'],
    'M11 scorecard keeps complete primary historical source set');
Assert::same(1,$obs->value()['contrary_reference_count'],
    'critic contrary source cannot vanish from assurance');
Assert::same(false,$obs->value()['source_rights_authenticated'],
    'metadata graph is not source owner authority');
$input=[
    'observation'=>$obs->value(),
    'queue'=>null,
    'coverage'=>[
        'status'=>'NOT_VERIFIED','planned_slots'=>null,
        'reported_slots'=>null,'oldest_missing_at_utc'=>null,
    ],
    'cost'=>[
        'status'=>'UNKNOWN_PROVIDER_OUTCOME',
        'reserved_units'=>null,'spent_units'=>null,'unknown_attempts'=>null,
    ],
    'recovery'=>[
        'status'=>'NOT_VERIFIED_HOST',
        'private_bytes'=>'NOT_VERIFIED',
        'credential_rotation'=>'NOT_VERIFIED',
        'source_image'=>'NOT_VERIFIED',
    ],
    'at_utc'=>'2026-10-30T12:00:00Z',
];
$out=HistoryReviewAssurance::compose($input);
Assert::true($out->isOk(),'fixed incident/scorecard contracts report missing proof honestly');
if(!$out->isOk())return;
$v=$out->value();
Assert::true(HistoryReviewAssurance::verify($v)->isOk(),
    'source-only scorecard is deterministic and self-verifying');
Assert::same(null,$v['provider_reserved_units'],
    'unknown reservation is null, never an invented zero');
Assert::same(null,$v['provider_spent_units'],
    'unknown expenditure is null, never an invented zero');
Assert::same(null,$v['coverage_gap_count'],
    'missing P07 report is unknown not zero missing slots');
Assert::same(null,$v['queue_pending_reviews'],
    'unobserved queue cannot be reported as empty');
Assert::same('NOT_VERIFIED_HOST',$v['recovery_status'],
    'source tests do not claim a real Railway restore');
Assert::same('U03_NOT_ADOPTED',$v['rights_status'],
    'source-use owner still controls historical reuse');
Assert::same(false,$v['actual_m11_incident_written'],
    'report does not mutate actual M11 incident ledger');
Assert::true(in_array('PROVIDER_COST_UNKNOWN_HOLD',$v['reason_codes'],true),
    'unknown provider usage produces a visible fixed-code hold');
Assert::true(in_array('HOST_RECOVERY_NOT_VERIFIED',$v['reason_codes'],true),
    'unverified host backup produces fixed-code issue');

$repeat=HistoryReviewAssurance::compose($input);
Assert::same($v['report_digest'],$repeat->value()['report_digest'],
    'identical safe input yields reproducible audit candidate');

$bad=$input;$bad['cost']['spent_units']=0;
Assert::same('VA02_UNKNOWN_PROVIDER_COST_CANNOT_BE_ZERO',
    HistoryReviewAssurance::compose($bad)->reasonCode(),
    'unknown usage cannot be recast as zero cost');
$bad=$input;$bad['coverage']['reported_slots']=0;
Assert::same('VA02_COVERAGE_UNKNOWN_NOT_ZERO',
    HistoryReviewAssurance::compose($bad)->reasonCode(),
    'unobserved slots cannot be called zero reported');
$bad=$input;$bad['recovery']['private_bytes']='FIXTURE_METADATA_ONLY';
Assert::same('VA02_RECOVERY_HOST_PROOF_FORGED',
    HistoryReviewAssurance::compose($bad)->reasonCode(),
    'host NOT_VERIFIED cannot claim private bytes recovered');

$bad=$v;$bad['customer_visibility_authorized']=true;
$unsigned=$bad;unset($unsigned['report_digest']);
$bad['report_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA02_SCORECARD_FORGED_AUTHORITY',
    HistoryReviewAssurance::verify($bad)->reasonCode(),
    'rehashing customer output flag cannot mint permission');
$bad=$obs->value();$bad['source_rights_authenticated']=true;
$unsigned=$bad;unset($unsigned['observation_digest']);
$bad['observation_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA02_PROVENANCE_FORGED_AUTHORITY',
    M17Assurance::verify($bad)->reasonCode(),
    'rehashed source rights are not authentication');
Assert::same('VA02_M11_OWNER_READ_AND_LOG_NOT_ADOPTED',
    HistoryReviewAssurance::forRuntime()->reasonCode(),
    'no live M11 audit collector is installed');
