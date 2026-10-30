<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/An03FixtureReleaseOwner.php';

use Allfather\Loopia\Tests\{Assert,An03MockFinding,An03FixtureReleaseOwner};
use Allfather\Loopia\Workflow\{HistoryReviewRelease,NativeReleaseProposal};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;

if(!defined('ALLFATHER_AN03_FIXTURE_OWNER_TEST'))define('ALLFATHER_AN03_FIXTURE_OWNER_TEST',true);
$input=An03MockFinding::inputs();
Assert::true($input->isOk(),'fixture-only historical input');
if(!$input->isOk())return;
$f=HistoricalFindingAdapter::propose($input->value());
Assert::true($f->isOk(),'source hashes and critic chain verified');
if(!$f->isOk())return;
$p=NativeReleaseProposal::fromFinding($f->value());
Assert::true($p->isOk(),'source-bound release candidate prepared');
if(!$p->isOk())return;
$pass=HistoryReviewRelease::exerciseFixtureOwnerPath($p->value(),new An03FixtureReleaseOwner());
Assert::true($pass->isOk(),'synthetic owner path completes without actual rights');
if(!$pass->isOk())return;
foreach([
    'source_rights_authenticated','actual_human_decisions_authenticated',
    'native_m10_record_written','customer_visibility_authorized',
    'p02_mutation_authorized','p04_distribution_authorized',
] as $field){
    Assert::same(false,$pass->value()[$field],'no native grant through fixture '.$field);
}
foreach(['M05_SOURCE_USE','HUMAN_FACTUAL','M07_REVIEW','M08_ADMISSIBILITY',
         'M10_CANDIDATE','HUMAN_DELIVERY','M16_CANDIDATE'] as $stage){
    $out=HistoryReviewRelease::exerciseFixtureOwnerPath($p->value(),new An03FixtureReleaseOwner($stage));
    Assert::false($out->isOk(),'withheld '.(string)$stage.' cannot produce synthetic PASS');
}
foreach(['app/Workflow/NativeReleaseProposal.php',
         'app/Workflow/HistoryReviewRelease.php',
         'app/Workflow/HistoricalFindingReleaseOwnerPort.php'] as $file){
    $source=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
    foreach(['curl_exec(', 'new ModelExecutor(', 'releaseP01(',
             'new DecisionLedger(', '->send(', 'DELETE FROM', 'mail('] as $forbidden){
        Assert::false(str_contains($source,$forbidden),
            'owner adapter cannot do live source/provider/M10/customer effects: '.$forbidden);
    }
}
Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
    HistoryReviewRelease::release($f->value(),$pass->value())->reasonCode(),
    'synthetic receipt is never a native M10 authorization');
