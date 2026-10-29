<?php

declare(strict_types=1);

use Allfather\Loopia\Core\{Digest,Ids};
use Allfather\Loopia\Product\P01\{Entitlements,ObservationHistory,SourceOriginAssertionReader};
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryReviewRightsGateway};

$db=Harness::freshDb();
if($db===null){ Assert::skip(Harness::unavailableReason()); return; }
$clock=Harness::clock('2026-10-29T08:00:00.000Z');
$scope=Seed::tenantWorkspace($db,$clock,'hs02-current-owner');
$foreign=Seed::tenantWorkspace($db,$clock,'hs02-foreign');
$role=Seed::role($db,$clock,'hs02-current-owner-read',
    ['competition_monitoring.read','evidence.read']);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],
    $scope['operator_id'],$role['role_id']);
$tiers=new Entitlements($db,$clock);
$tier=$tiers->defineTier('hs02-fixture-only','HS02 Candidate Owner Reader',0,
    ['competition_monitoring.read']);
Assert::true($tier->isOk(),'source-owner fixture tier registered');
if(!$tier->isOk())return;
Assert::true($tiers->subscribe($scope['tenant_id'],$tier->value()['subscription_tier_id'])->isOk(),
    'read entitlement uses existing P01 tier vocabulary');
$actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
$other=['tenant_id'=>$foreign['tenant_id'],'workspace_id'=>$foreign['workspace_id'],
    'actor_type'=>'OPERATOR','actor_id'=>$foreign['operator_id']];
$mission=Seed::governedToolChain($db,$clock,$scope);
$chain=Seed::p01SignalChain($db,$clock,$scope,$mission['mission_id'],null);
$competitorId=$chain['competitor_id'];
$history=new ObservationHistory($db,$clock,new AccessGuard($db,$clock),$tiers);
$owner=new SourceOriginAssertionReader($db,$history);
$gateway=new HistoryReviewRightsGateway($owner);
$snapIds=[$chain['after_snapshot_id'],$chain['before_snapshot_id']];
$proof=$owner->inspect($actor,$competitorId,$snapIds);
Assert::true($proof->isOk(),'original M02/P01/M05 history reference owner is valid in synthetic fixture');
if(!$proof->isOk())return;
$sourceRows=$proof->value()['references'];
$question=Ids::new();
$rows=[];
foreach($sourceRows as $s){
    $capture=$s['captured_at'];
    $utc=substr($capture,0,19).'Z';
    $rows[]=[
        'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
        'competitor_id'=>$competitorId,'question_id'=>$question,
        'ref_id'=>$s['source_snapshot_id'],
        'source_ref_id'=>$s['source_ref_id'],
        'evidence_id'=>$s['evidence_id'],
        'snapshot_id'=>$s['source_snapshot_id'],
        'assessment_id'=>null,
        // Synthetic unknown source bytes: this digest is NOT owner-authenticated
        // by the reference reader and is never licensed for model use.
        'payload_sha256'=>hash('sha256','hs02.synthetic.'.$s['source_snapshot_id']),
        'event_at'=>null,
        'observed_at'=>$utc,'recorded_at'=>$utc,'known_at'=>$utc,
        'analyzed_at'=>null,'semantic_polarity'=>'UNKNOWN',
        'origin_group'=>'UNKNOWN',
        'reported_disposition'=>'UNASSESSED','provenance'=>'M05_CAPTURE_METADATA',
    ];
}
$period=['start_inclusive_utc'=>'2026-10-01T00:00:00Z',
    'end_exclusive_utc'=>'2026-11-01T00:00:00Z'];
$request=[
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>[
        'scope'=>['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
            'competitor_id'=>$competitorId,'question_id'=>$question],
        'period'=>$period,'baseline_period'=>[
            'start_inclusive_utc'=>'2026-09-01T00:00:00Z',
            'end_exclusive_utc'=>'2026-10-01T00:00:00Z'],
        'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>'2026-10-29T08:00:00Z',
        'rows'=>$rows,'max_references'=>8,
        'coverage'=>['state'=>'KNOWN','plan_version_id'=>'HS02_TEST_ONLY_NOT_OWNER',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,'failed'=>0,
            'skipped'=>0,'unreported'=>0],
    ],
    'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-10-29T08:00:00Z',
];
$built=PointInTimeHistoricalBasis::freeze($request);
Assert::true($built->isOk(),'synthetic basis valid as metadata, not source-use permission'
    .($built->isOk()?'':': '.$built->reasonCode()));
if(!$built->isOk())return;
$basis=$built->value();
Assert::same('COMPLETE_WITHIN_INPUT',$basis['selection_integrity'],
    'metadata completeness conditional on caller-supplied coverage, not owner coverage');
$selected= array_column($basis['selected_references'],'snapshot_id');
$selector=[
    'competitor_id'=>$competitorId,
    'purpose'=>'INTERNAL_DETERMINISTIC_REUSE',
    'stage'=>'BEFORE_BASIS_REUSE',
    'review_at_utc'=>$basis['review_at_utc'],
    'basis_digest'=>$basis['basis_digest'],
    'source_snapshot_ids'=>$selected,
];
$first=$gateway->inspect($actor,$basis,$selector);
Assert::true($first->isOk(),'genuine owner path verifies current source references'
    .($first->isOk()?'':': '.$first->reasonCode()));
if(!$first->isOk())return;
Assert::same(HistoryReviewRightsGateway::HOLD,$first->value()['decision'],
    'real permitted owner read still cannot create U03 historical-use approval');
Assert::same('CURRENT_M02_P01_M05_PASS',
    $first->value()['current_reference_scope_check'],
    'no second handcrafted ACL path impersonates M05');
Assert::same(false,$first->value()['can_use_for_historical_analysis'],
    'even internal deterministic reuse remains unadmitted without U03');
Assert::true(HistoryReviewRightsGateway::verifyCandidate($first->value())->isOk(),
    'owner scoped HOLD receipt hash verifies without granting authority');

$purposeDigests=[];
foreach(HistoryReviewRightsGateway::PURPOSES as $purpose){
    $a=$selector;$a['purpose']=$purpose;
    $result=$gateway->inspect($actor,$basis,$a);
    Assert::true($result->isOk(),'distinct purpose has explicit current-owner evaluation');
    Assert::same(HistoryReviewRightsGateway::HOLD,$result->value()['decision'],
        'no source-use purpose becomes ALLOW');
    Assert::same(false,$result->value()['can_send_to_external_provider'],
        'provider disclosure remains disabled');
    $purposeDigests[]=$result->value()['decision_digest'];
}
Assert::same(3,count(array_unique($purposeDigests)),
    'three purpose-specific decision receipts cannot substitute for each other');
foreach(HistoryReviewRightsGateway::STAGES as $stage){
    $a=$selector;$a['stage']=$stage;
    Assert::same(HistoryReviewRightsGateway::HOLD,
        $gateway->inspect($actor,$basis,$a)->value()['decision'],
        'current source owner rechecked at each declared stage, still hold');
}
$wrong=$selector;$wrong['purpose']='PUBLISH_CUSTOMER';
Assert::same('HS02_SELECTOR_INVALID',$gateway->inspect($actor,$basis,$wrong)->reasonCode(),
    'unsupported external effect refused before any owner read');
$wrong=$selector;$wrong['u03_granted']=true;
Assert::same('HS02_SELECTOR_INVALID',$gateway->inspect($actor,$basis,$wrong)->reasonCode(),
    'caller cannot insert a fake source-use approval flag');
$wrong=$selector;$wrong['source_snapshot_ids']=[$selected[0]];
Assert::same('HS02_SOURCE_REFERENCE_SET_MISMATCH',
    $gateway->inspect($actor,$basis,$wrong)->reasonCode(),
    'caller cannot drop contrary or inconvenient selected source refs');
Assert::same('HS02_BASIS_SCOPE_OR_CUTOFF_MISMATCH',
    $gateway->inspect($other,$basis,$selector)->reasonCode(),
    'foreign tenant cannot replay another tenant historical context');
$fake=$basis;$fake['historical_reuse_admitted']=true;
Assert::same('HS02_BASIS_NOT_VERIFIED',
    $gateway->inspect($actor,$fake,$selector)->reasonCode(),
    'self-granted history basis refused before owner read');
$db->run("UPDATE source_license_cards SET review_status='blocked'
         WHERE source_license_card_id=?",[$chain['source_license_card_id']]);
Assert::same('HS02_CURRENT_SOURCE_OWNER_REFUSED',
    $gateway->inspect($actor,$basis,$selector)->reasonCode(),
    'owner source-card revocation closes subsequent read/release stages');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'HS02 uses no provider dispatch');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE \'270_%\''),
    'U03 candidate review installs no live historical migration');
