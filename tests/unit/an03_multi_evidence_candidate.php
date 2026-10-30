<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Tests\{Assert,An03MockFinding};
use Allfather\Loopia\Workflow\{
    HistoryDependencyIndex,HistoryReviewFactory,HistoryReviewRelease,
    HistoryReviewService,HistoryReviewCandidateReadModel
};

$f=An03MockFinding::inputs();
Assert::true($f->isOk(),'native synthetic HS01→AN01→TM02→AN02 basis and human-HOLD chain');
if(!$f->isOk())return;
$input=$f->value();
$out=HistoricalFindingAdapter::propose($input);
Assert::true($out->isOk(),'two primary references retained as one provisional multi-evidence candidate');
if(!$out->isOk())return;
$c=$out->value();
Assert::true(HistoricalFindingAdapter::verify($c)->isOk(),
    'candidate self-hash, exact refs, IV01 graph and authority holds verify');
Assert::same('PROVISIONAL_MULTI_REFERENCE_HUMAN_REVIEW_HOLD',$c['status'],
    'a mock critic cannot yield an M10 released finding');
Assert::same(2,$c['selected_primary_ref_count'],
    'whole basis retained, not one arbitrary source diff');
Assert::same(1,$c['support_ref_count'],
    'one true supporting metadata ref does NOT imply two corroborators');
Assert::same(1,$c['contrary_ref_count'],
    'contrary evidence remains load bearing');
Assert::same(['ref_a'],$c['claim_support_ref_ids'],
    'exact supporting ref citation retained');
Assert::same(['ref_b'],$c['contrary_ref_ids'],
    'the critic cannot erase contrary data');
Assert::same(null,$c['independent_publisher_count'],
    'two captures cannot prove independent publication');
Assert::same(null,$c['separate_real_world_event_count'],
    'two hashes cannot prove two real-world causal events');
Assert::same('NOT_ASSESSED',$c['claimed_business_materiality'],
    'source structure is not a certified commercial finding');
Assert::same($input['basis']['basis_digest'],$c['basis_digest'],
    'multi-evidence finding binds exact HS01 candidate basis');
Assert::same($input['review_run']['run_digest'],$c['review_run_digest'],
    'finding requires the exact immutable AN02 run');
Assert::same($input['critic']['candidate_digest'],$c['critic_candidate_digest'],
    'review source is critic artifact, not an independently human-signed verdict');
Assert::same('CANDIDATE_NOT_OWNER_VERIFIED',$c['dependency_graph']['status'],
    'IV01 graph is a provisional dependency, not M05/M10 fact');
Assert::same(5,$c['dependency_graph']['node_count'],
    'two CAPTURE nodes plus BASIS, ANALYSIS and FINDING nodes');
Assert::same(4,$c['dependency_graph']['link_count'],
    'both distinct captures depend on the exact basis');
Assert::same('HOLD_U03_U06_U07_NATIVE_MULTI_EVIDENCE_ROUTE_NOT_ADOPTED',
    HistoryReviewRelease::inspect($c)->value()['disposition'],
    'native source/use/review release owners remain unadopted');
Assert::same('AN03_U07_MULTI_EVIDENCE_NATIVE_P01_ROUTE_NOT_ADOPTED',
    HistoricalFindingAdapter::toCompanySignal($c)->reasonCode(),
    'single-diff CompanySignals must never fake the source of history');
Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
    HistoryReviewRelease::release($c,['m10'=>'APPROVE'])->reasonCode(),
    'caller claim cannot authorize M10 or customer output');
foreach([
    'historical_source_use_admitted','human_factual_release_approved',
    'human_delivery_approved','m07_relevance_review_admitted',
    'm08_policy_admission_granted','m10_current_reliance_verified',
    'm10_release_authorized','m16_internal_artifact_created',
    'p02_twin_update_authorized','p04_distribution_authorized',
    'customer_visibility_authorized',
] as $flag){
    Assert::same(false,$c[$flag],'candidate cannot grant '.$flag);
}
$repeat=HistoricalFindingAdapter::propose($input);
Assert::same($c['finding_digest'],$repeat->value()['finding_digest'],
    'identical source and review order yields identical finding digest');

// Refusal: a critic's own approval is only the *start* of human review.
$unbound=$input;array_pop($unbound['review_events']);
Assert::same('AN03_REVIEW_BASIS_OR_ARTIFACT_UNVERIFIED',
    HistoricalFindingAdapter::propose($unbound)->reasonCode(),
    'missing human-HOLD event cannot be an admitted finding');
$unbound=$input;
$unbound['review_events'][1]['prior_event_digest']=str_repeat('f',64);
$unsigned=$unbound['review_events'][1];
unset($unsigned['event_digest']);
$unbound['review_events'][1]['event_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_REVIEW_CHAIN_NOT_A_HUMAN_HOLD',
    HistoricalFindingAdapter::propose($unbound)->reasonCode(),
    'rehashing a broken event predecessor cannot forge a complete chain');
$unbound=$input;$unbound['analyst']['output']['support_ref_ids']=['ref_b'];
$unsigned=$unbound['analyst'];unset($unsigned['candidate_digest']);
$unbound['analyst']['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_MULTI_EVIDENCE_NOT_AN_ADMITTED_REVIEW',
    HistoricalFindingAdapter::propose($unbound)->reasonCode(),
    'self-hashed contrary ref must not masquerade as supporting claim evidence');
$unbound=$input;
$unbound['review_events'][2]['state_to']='BLOCK_HOLD';
$unsigned=$unbound['review_events'][2];unset($unsigned['event_digest']);
$unbound['review_events'][2]['event_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_REVIEW_CHAIN_NOT_A_HUMAN_HOLD',
    HistoricalFindingAdapter::propose($unbound)->reasonCode(),
    'a blocked critic cannot be treated as a human-review-ready finding');
$unbound=$input;
$no=HistoryReviewFactory::mockAnalyst(
    $unbound['review_run'],
    $unbound['basis'],
    ['invented'=>true],
    'NO_NEW_INSIGHT',
);
Assert::false($no->isOk(),
    'a forged non-M17 memory cannot create a valid analyst result');
$bad=$c;$bad['m10_release_authorized']=true;
$unsigned=$bad;unset($unsigned['finding_digest']);
$bad['finding_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_FINDING_FORGED_AUTHORITY',
    HistoricalFindingAdapter::verify($bad)->reasonCode(),
    'rehashed release=true cannot mint an M10 verdict');
$bad=$c;
$bad['primary_references'][0]['cited_as_support']=false;
$unsigned=$bad;unset($unsigned['finding_digest']);
$bad['finding_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_FINDING_LINEAGE_INCOMPLETE',
    HistoricalFindingAdapter::verify($bad)->reasonCode(),
    'a rehashed candidate cannot silently drop supporting lineage');
$bad=$c;
$graph=$c['dependency_graph'];
foreach($graph['nodes'] as &$node){
    if($node['kind']==='CAPTURE'){
        $node['owner_ref_digest']=str_repeat('a',64);
        break;
    }
}
unset($node);
$check=HistoryDependencyIndex::freeze([
    'scope'=>$c['scope'],
    'catalog_cutoff_utc'=>$graph['catalog_cutoff_utc'],
    'nodes'=>$graph['nodes'],'links'=>$graph['links'],
]);
Assert::true($check->isOk(),'fake graph is syntactically valid before lineage comparison');
$bad['dependency_graph']=$check->value();
$unsigned=$bad;unset($unsigned['finding_digest']);
$bad['finding_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN03_FINDING_DEPENDENCY_GRAPH_REWRITTEN',
    HistoricalFindingAdapter::verify($bad)->reasonCode(),
    'syntactically valid graph with altered owner hashes cannot replace real refs');
$recheck=HistoryReviewRelease::inspect($c,'PRE_RELEASE',[
    'version'=>'hs02.historical-use-inspection.v1',
    'decision'=>'ALLOW','basis_digest'=>$c['basis_digest'],
]);
Assert::same('AN03_UNTRUSTED_OR_NONHOLD_OWNER_ASSERTION',
    $recheck->reasonCode(),
    'source-looking ALLOW cannot bypass U03 rights or M10 release');
