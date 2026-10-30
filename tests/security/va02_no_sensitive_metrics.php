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

$root=dirname(__DIR__,2);
foreach([
    'app/Substrate/M17/Assurance.php',
    'app/Workflow/HistoryReviewAssurance.php',
] as $path){
    $s=(string)file_get_contents($root.'/'.$path);
    foreach(['curl_exec(', 'new ModelExecutor(', 'mail(',
             'new DecisionLedger(', '->send(', 'DELETE FROM',
             'new SourceCaptureStage(', 'new SchedulerRunner('] as $bad){
        Assert::false(str_contains($s,$bad),
            'VA02 cannot acquire live execution or mutation: '.$path);
    }
}
if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST'))define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
$fixture=Aq02MockAttention::input();
Assert::true($fixture->isOk(),'local-only exact P01 provenance exists');
if(!$fixture->isOk())return;
$attention=InternalAttention::composeFixture(
    $fixture->value(),new Aq02FixtureAttentionOwner());
Assert::true($attention->isOk(),'only synthetic P04 observation');
if(!$attention->isOk())return;
$v=M17Assurance::inspect(
    $fixture->value()['native_release_proposal'],
    $fixture->value()['outcome_annotation'],$attention->value(),
    '2026-10-30T12:00:00Z',
);
Assert::true($v->isOk(),'source observation has a genuine positive fixture');
if(!$v->isOk())return;
$bad=$v->value();$bad['recovery_or_host_proof_verified']=true;
$unsigned=$bad;unset($unsigned['observation_digest']);
$bad['observation_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA02_PROVENANCE_FORGED_AUTHORITY',
    M17Assurance::verify($bad)->reasonCode(),
    'self-rehashed host proof is refused');
$encoded=json_encode($v->value(),JSON_THROW_ON_ERROR);
foreach(['raw_prompt','api_key','password','source_body','provider_response'] as $field){
    Assert::false(str_contains($encoded,$field),
        'safe metric output cannot contain plaintext field '.$field);
}
