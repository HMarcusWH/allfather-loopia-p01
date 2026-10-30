<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/An03FixtureReleaseOwner.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\{Assert,An03MockFinding,An03FixtureReleaseOwner};
use Allfather\Loopia\Workflow\{HistoryReviewRelease,NativeReleaseProposal};

$input=An03MockFinding::inputs();
Assert::true($input->isOk(),'AN03 fixture input has the old immutable reviewer chain');
if(!$input->isOk())return;
$from=\Allfather\Loopia\Product\P01\HistoricalFindingAdapter::propose($input->value());
Assert::true($from->isOk(),'two-source HS01/AN02 candidate is intact');
if(!$from->isOk())return;
$proposal=HistoryReviewRelease::prepareOwnerProposal($from->value());
Assert::true($proposal->isOk(),'new native-owner proposal can be prepared from the actual finding');
if(!$proposal->isOk())return;
$p=$proposal->value();
Assert::true(NativeReleaseProposal::verify($p)->isOk(),'owner proposal verifies exact native lineage');
Assert::same('CANDIDATE_U03_U06_U07_NOT_ADOPTED',$p['status'],'proposal is not authorization');
Assert::same($from->value()['finding_digest'],$p['finding_digest'],'proposal binds exact finding');
Assert::same($from->value()['primary_references'],$p['primary_references'],'no primary M05 references dropped');
Assert::same(['ref_a'],$p['claim']['support_ref_ids'],'support refs remain a separate claim map');
Assert::same(['ref_b'],$p['claim']['contrary_ref_ids'],'contrary evidence remains visible');
Assert::same(null,$p['human_factual_decision_digest'],'unreviewed candidate has no human decision');
Assert::same(null,$p['native_m10_record_id'],'synthetic proposal has no M10 record');
Assert::same(false,$p['released'],'proposal preparation cannot release intelligence');
Assert::same('AN03_NATIVE_OWNER_PORT_NOT_ADOPTED',
    HistoryReviewRelease::exerciseFixtureOwnerPath($p,new An03FixtureReleaseOwner())->reasonCode(),
    'CLI fixture must still have an explicit test-only admission marker');
if(!defined('ALLFATHER_AN03_FIXTURE_OWNER_TEST'))define('ALLFATHER_AN03_FIXTURE_OWNER_TEST',true);
$port=new An03FixtureReleaseOwner();
$r=HistoryReviewRelease::exerciseFixtureOwnerPath($p,$port);
Assert::true($r->isOk(),'all eight owner-facing stages execute under explicit fixed fixture');
if(!$r->isOk())return;
Assert::same(HistoryReviewRelease::FIXTURE_OWNER_STAGES,$port->stageCalls,
    'M05 / factual human / M04 / M07 / M08 / M10 / delivery human / M16 ordering');
Assert::same('FIXTURE_OWNER_SEQUENCE_PASS_NO_LIVE_RELEASE',$r->value()['status'],
    'positive fixture result must not claim product release');
Assert::same('CONTRACT_BLOCKED_U03_U06_U07',$r->value()['native_owner_adoption'],
    'native contract remains unadopted despite positive synthetic composition');
Assert::same(false,$r->value()['native_m10_record_written'],'no production M10 data was written');
Assert::same(false,$r->value()['customer_visibility_authorized'],'no customer visibility');
Assert::false($r->value()['human_factual_decision_digest']===$r->value()['human_delivery_decision_digest'],
    'factual and delivery reviews are independently purpose-bound');
$again=HistoryReviewRelease::exerciseFixtureOwnerPath($p,new An03FixtureReleaseOwner());
Assert::same($r->value()['receipt_digest'],$again->value()['receipt_digest'],
    'identical fixture owner history is exact no-write deterministic replay');
$mutated=$p;
$mutated['claim']['contrary_ref_ids']=[];
$unsigned=$mutated;unset($unsigned['proposal_digest']);
$mutated['proposal_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_OWNER_PROPOSAL_REHASH_OR_AUTHORITY_FORGERY',
    NativeReleaseProposal::verify($mutated)->reasonCode(),
    'rehashed proposal cannot erase contrary evidence');
$mutated=$p;$mutated['released']=true;
$unsigned=$mutated;unset($unsigned['proposal_digest']);
$mutated['proposal_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_OWNER_PROPOSAL_REHASH_OR_AUTHORITY_FORGERY',
    NativeReleaseProposal::verify($mutated)->reasonCode(),
    'self-rehashed release flag is never accepted');
$mutated=$p;$mutated['scope']['tenant_id']='other-tenant';
Assert::same('AN03_OWNER_PROPOSAL_REHASH_OR_AUTHORITY_FORGERY',
    NativeReleaseProposal::verify($mutated)->reasonCode(),
    'foreign tenant rejected even before a fixture owner read');
foreach([
    ['M05_SOURCE_USE','wrong_scope'],
    ['M05_SOURCE_USE','wrong_basis'],
    ['M05_SOURCE_USE','drop_contrary'],
    ['M04_PROPOSAL','wrong_prior'],
    ['HUMAN_FACTUAL','human_actor'],
    ['HUMAN_DELIVERY','human_purpose'],
    ['M10_CANDIDATE','forge_m10'],
] as [$stage,$mutation]){
    $bad=HistoryReviewRelease::exerciseFixtureOwnerPath(
        $p,new An03FixtureReleaseOwner(null,$stage,$mutation));
    Assert::false($bad->isOk(),
        'rehashed/malformed owner stage must refuse '.$stage.'/'.$mutation);
}
$blocked=new An03FixtureReleaseOwner('M05_SOURCE_USE');
Assert::same('AN03_FIXTURE_OWNER_M05_SOURCE_USE_HOLD',
    HistoryReviewRelease::exerciseFixtureOwnerPath($p,$blocked)->reasonCode(),
    'withheld source rights never advance to factual review');
Assert::same(['M05_SOURCE_USE'],$blocked->stageCalls,
    'a denial halts the sequence before downstream owner reads');
Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
    HistoryReviewRelease::release($from->value(),['fixture_receipt'=>$r->value()])->reasonCode(),
    'even a complete fixture cannot turn the real release API on');
