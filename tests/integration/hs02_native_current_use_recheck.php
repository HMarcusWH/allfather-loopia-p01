<?php

declare(strict_types=1);

/**
 * LI-05D genuine owner-native P01/M05 capture -> metadata-only historical
 * basis -> disposable 270 readback. No provider calls, external HTTP or U03 grant.
 */
require_once __DIR__.'/../support/MonitorModelFixture.php';

use Allfather\Loopia\Core\{Ids,Outcome};
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Ingestion\{
    FetchRequest,FetchResponse,FetchTransport,FixedHostResolver
};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\FixtureModelTransport;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Tests\{Assert,Harness,MonitorModelFixture,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewBasisStore,HistoryM17CandidateBridge,
    MissionConfiguration,MonitorFactory,NativeCaptureHistoryCandidate
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-10-26T18:00:00.000Z');
$f=MonitorModelFixture::create($db,$clock,true);
$scope=$f['scope'];
$actor=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'actor_type'=>'OPERATOR','actor_id'=>$scope['operator_id']];
$value=static function(Outcome $out,string $reason):mixed {
    Assert::true($out->isOk(),$reason.($out->isOk()?'':': '.$out->reasonCode()));
    if(!$out->isOk())throw new RuntimeException($reason.': '.$out->reasonCode());
    return $out->value();
};

// This is exactly the existing current P01 monitor authority chain;
// no newly invented owner or real network permission is introduced.
$role=Seed::role($db,$clock,'li05d-capture-and-read',[
    'mission.read','mission.create','mission.activate','mission.transition',
    'competition_monitoring.read','evidence.read','tool.invoke',
    'company_signal.review','distribution.route','distribution.configure_targets',
]);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],
    $scope['operator_id'],$role['role_id']);
$mission=$f['context_selection']['mission_id'];
$db->run('UPDATE missions SET mission_type=? WHERE mission_id=?',
    ['COMPETITION_MONITORING',$mission]);
$f['context_selection']['purpose']=$db->scalar(
    'SELECT objective FROM missions WHERE mission_id=?',[$mission]);
$f['context_selection']['limits']['max_model_calls']=4;
$policies=new PolicySets($db,$clock);
$ledger=new DecisionLedger($db,$clock);
$p01=$value($policies->seed(PolicySets::P01_COMPETITION_RELEVANCE,
    $scope['tenant_id'],$scope['workspace_id']),'P01 policy');
$p04=$value($policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION,
    $scope['tenant_id'],$scope['workspace_id']),'P04 policy');
(new ReleaseOutput($db,$clock,$ledger))->seedVisibilityProfiles($scope['tenant_id']);
$value((new Distribution($db,$clock,$ledger))->configureRoleRedactionPolicy(
    $scope+['target_org_role'=>'SALES','redaction_rules'=>[]]),'target configured');
$definition=[
    'mission_id'=>$mission,'monitored_source_id'=>$f['monitored_source_id'],
    'capture_url'=>$f['capture_url'],'capture_governance'=>$f['capture_governance'],
    'model_governance'=>[
        'author'=>$f['author_governance'],'critic'=>$f['critic_governance'],
    ],
    'release_governance'=>[
        'mission_id'=>$mission,
        'producing_valkyrie_id'=>$f['author_governance']['valkyrie_id'],
        'capability_contract_id'=>$f['author_governance']['capability_contract_id'],
    ],
    'context_selection'=>$f['context_selection'],
    'credential_id'=>$f['credential_id'],'target_org_role'=>'SALES',
    'p01_policy_id'=>$p01['arbiter_policy_set_id'],
    'p04_policy_id'=>$p04['arbiter_policy_set_id'],
];
$value((new MissionConfiguration($db,$clock))->configure(
    $scope,$definition,'FIXTURE'),'existing monitor configuration');
$source=new class([$f['before_body'],$f['after_body']]) implements FetchTransport{
    public int $calls=0;
    public function __construct(private array $pages){}
    public function send(FetchRequest $request):FetchResponse{
        return new FetchResponse(200,'text/plain; charset=utf-8',null,
            $this->pages[$this->calls++]??'Unexpected extra capture');
    }
};
$model=new FixtureModelTransport(MonitorModelFixture::response(...));
$service=MonitorFactory::forFixture(
    $db,$clock,$f['evidence_store'],$f['credential_store'],$source,
    new FixedHostResolver(['sources.example'=>['93.184.216.34']]),$model,
);
$baseline=$value($service->startConfigured($scope,$mission,'li05d-base'),
    'baseline configured');
$first=$value($service->advance($scope,$baseline['monitor_run_id']),
    'native baseline capture');
Assert::same('BASELINE',$first['stage'],'baseline does not invoke a model');
$clock->advanceDays(1);
$changed=$value($service->startConfigured($scope,$mission,'li05d-changed'),
    'later capture configured');
$after=$value($service->advance($scope,$changed['monitor_run_id']),
    'native second capture');
Assert::same('CAPTURED',$after['stage'],'second observation is retained before analysis');
Assert::same(2,$source->calls,'exactly two local fixture source fetches');
Assert::same(0,$model->calls,'never invoked the model during native source-backed preflight');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'source preflight spends no model attempts');

$snapshots=$db->all(
    'SELECT s.source_snapshot_id,s.evidence_id,s.content_hash,s.captured_at
     FROM source_snapshots s JOIN monitored_sources m
       ON m.monitored_source_id=s.monitored_source_id
     WHERE s.tenant_id=? AND s.workspace_id=? AND m.competitor_id=?
     ORDER BY s.captured_at,s.source_snapshot_id',
    [$scope['tenant_id'],$scope['workspace_id'],$f['competitor_id']],
);
Assert::same(2,count($snapshots),'both real P01 snapshots exist with original M05 evidence');
Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM monitor_capture_payloads'),
    'both original private payload-to-artifact bindings are durable');

$reader=new NativeCaptureHistoryCandidate($db,$clock,$f['evidence_store']);
$period=[
    'start_inclusive_utc'=>'2026-10-01T00:00:00Z',
    'end_exclusive_utc'=>'2026-11-01T00:00:00Z',
];
$question=Ids::new();
$captureSecondCutoff=substr($clock->now(),0,19).'Z';
$captureSecond=$value($reader->preview($actor,$f['competitor_id'],$question,
    $period,$captureSecondCutoff),'as-of cutoff before fractional binding completed');
Assert::same(1,$captureSecond['history_basis']['selected_count'],
    'source recorded at a fractional millisecond must not be backdated');
Assert::same(1,
    $captureSecond['history_basis']['excluded_counts_by_reason']['NOT_YET_KNOWN'],
    'subsecond source binding is explicitly excluded at the earlier whole-second cutoff');
$clock->advanceMs(1000);
$cutoff=substr($clock->now(),0,19).'Z';
$preview=$value($reader->preview($actor,$f['competitor_id'],$question,
    $period,$cutoff),'owner-backed snapshot proof after the binding time');
Assert::same('OWNER_NATIVE_CAPTURE_METADATA_VERIFIED_U03_HOLD',
    $preview['status'],'actual M05 proof never becomes a use grant');
Assert::same(2,$preview['verified_capture_count'],
    'both current-rights/source/provenance/retained-byte checks passed');
Assert::same(false,$preview['source_bytes_returned'],'no captured source text is projected');
foreach(['U03_historical_use_admitted','U06_model_context_authorized',
         'M17_input_set_authorized','scheduler_action_authorized',
         'customer_output_authorized','source_coverage_proven'] as $field){
    Assert::same(false,$preview[$field],'native source proof grants nothing: '.$field);
}
$basis=$preview['history_basis'];

// HS-02 builds a stricter point-in-time candidate over the original owner
// reference IDs. This is still NOT an authorized historical-use manifest.
$hsRows=[];
foreach($basis['references'] as $ref){
    $hsRows[]=[
        'tenant_id'=>$actor['tenant_id'],
        'workspace_id'=>$actor['workspace_id'],
        'competitor_id'=>$f['competitor_id'],
        'question_id'=>$question,
        'ref_id'=>$ref['ref_id'],
        'source_ref_id'=>$ref['source_ref_id'],
        'evidence_id'=>$ref['evidence_id'],
        'snapshot_id'=>$ref['snapshot_id'],
        'assessment_id'=>$ref['assessment_id'],
        'payload_sha256'=>$ref['payload_sha256'],
        'event_at'=>$ref['event_at'],
        'observed_at'=>$ref['observed_at'],
        'recorded_at'=>$ref['recorded_at'],
        'known_at'=>$ref['known_at'],
        'analyzed_at'=>$ref['analyzed_at'],
        'semantic_polarity'=>$ref['semantic_polarity'],
        'origin_group'=>$ref['origin_group'],
        'provenance'=>$ref['provenance'],
        'reported_disposition'=>'UNASSESSED',
    ];
}
$hsRequest=[
    'calendar'=>\Allfather\Loopia\Workflow\PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>[
        'scope'=>$basis['scope'],
        'period'=>$basis['period'],
        'baseline_period'=>null,
        'baseline_null_reason'=>'NOT_REQUESTED',
        'knowledge_cutoff_utc'=>$cutoff,
        'rows'=>$hsRows,
        'max_references'=>20,
        'coverage'=>['state'=>'UNKNOWN'],
    ],
    'previous_basis_digest'=>null,
    'review_at_utc'=>$cutoff,
];
$hs=$value(\Allfather\Loopia\Workflow\PointInTimeHistoricalBasis::freeze($hsRequest),
    'HS02 typed HS01 input composed over actual source-bound private M05 evidence');
Assert::same('INSUFFICIENT_BASIS',$hs['selection_integrity'],
    'missing authenticated schedule coverage remains insufficient, not no-change');
Assert::same(2,$hs['selected_count'],'both verified M05 capture refs retained');

$gateway=new \Allfather\Loopia\Workflow\HistoryReviewRightsGateway(
    new \Allfather\Loopia\Substrate\M17\HistoricalCaptureProbe(
        $db,$clock,$f['evidence_store']),
    $clock,
);
$beforeAttempts=(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts');
$beforePayloads=(int)$db->scalar('SELECT COUNT(*) FROM monitor_capture_payloads');
$proof=$value($gateway->inspect($actor,$hs,'DETERMINISTIC_INTERNAL_REVIEW',
    'PRE_DISPATCH'),'native current-use owner readback for deterministic candidate');
Assert::same('HOLD_U03_HISTORICAL_USE_NOT_RATIFIED',$proof['decision'],
    'even actual captured private bytes never imply U03 use permission');
Assert::same(2,$proof['owner_native_capture_count'],
    'actual M02/P01/M05 current scope and bytes rechecked for both captures');
Assert::same(true,$proof['retained_bytes_verified_privately'],
    'source bytes verified by pre-existing owner without disclosing them');
Assert::same(false,$proof['m10_current_reliance_checked'],
    'M10 reliance is explicitly pending, not falsely certified');
Assert::same(false,$proof['internal_deterministic_use_authorized'],
    'real native source proof is still not a historical-use grant');
Assert::same(false,$proof['provider_byte_disclosure_authorized'],
    'external provider disclosure remains held');
Assert::same(false,$proof['source_bytes_returned'],
    'no M05 private body leaves gateway');
Assert::same(false,$proof['historical_reuse_admitted'],
    'historical U03 cannot be inferred from current source rights');
$display=json_encode($proof,JSON_THROW_ON_ERROR);
foreach([$f['before_body'],$f['after_body'],$f['capture_url']] as $private){
    Assert::false(str_contains($display,$private),
        'native proof output has no body/URL side-channel');
}
Assert::same($beforeAttempts,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'gateway makes zero funded or fixture model calls');
Assert::same($beforePayloads,(int)$db->scalar('SELECT COUNT(*) FROM monitor_capture_payloads'),
    'gateway never mutates primary M05/P01 payload bindings');
Assert::same(2,$source->calls,'the new gateway did not fetch another page');
Assert::same(0,$model->calls,'no model transport invoked by native rights check');

$follow=$value($gateway->inspect($actor,$hs,'DETERMINISTIC_INTERNAL_REVIEW',
    'POST_RETURN',$proof['current_owner_fingerprint']),
    'native post-return source/rights revalidation');
Assert::same($proof['current_owner_fingerprint'],$follow['current_owner_fingerprint'],
    'unchanged owner state yields the same U03-HOLD fingerprint');
$release=$value($gateway->inspect($actor,$hs,'DETERMINISTIC_INTERNAL_REVIEW',
    'PRE_RELEASE',$proof['current_owner_fingerprint']),
    'native pre-release revalidation stays held');
Assert::same('HOLD_U03_HISTORICAL_USE_NOT_RATIFIED',$release['decision'],
    'post-return/pre-release proof still grants no output');
Assert::same('HS02_OWNER_PROVENANCE_CHANGED_HOLD',
    $gateway->inspect($actor,$hs,'EXTERNAL_PROVIDER_BYTE_DISCLOSURE',
        'POST_RETURN',$proof['current_owner_fingerprint'])->reasonCode(),
    'source proof for internal purpose cannot be substituted for provider disclosure');
$foreign=$actor;$foreign['workspace_id']=Ids::new();
Assert::same('HS02_BASIS_SCOPE_OR_CAP_INVALID',
    $gateway->inspect($foreign,$hs,'DETERMINISTIC_INTERNAL_REVIEW')->reasonCode(),
    'wrong owner scope stops before accessing source record');
$spoof=$hs;$spoof['provider_disclosure_authorized']=true;
Assert::same('HS02_BASIS_INTEGRITY_INVALID',
    $gateway->inspect($actor,$spoof,'EXTERNAL_PROVIDER_BYTE_DISCLOSURE')->reasonCode(),
    'untrusted caller flag cannot authorize source reuse');

// Revocation must be observed by the existing M05 owner on any later stage.
// The rows are synthetic and this is a disposable MariaDB test.
$db->run('UPDATE monitored_sources SET status=? WHERE monitored_source_id=?',
    ['PAUSED',$f['monitored_source_id']]);
Assert::same('HS02_CURRENT_OWNER_SOURCE_HOLD',
    $gateway->inspect($actor,$hs,'DETERMINISTIC_INTERNAL_REVIEW',
        'POST_RETURN',$proof['current_owner_fingerprint'])->reasonCode(),
    'current source-card/monitor change invalidates an old readback');
Assert::same($beforePayloads,(int)$db->scalar('SELECT COUNT(*) FROM monitor_capture_payloads'),
    'revocation cannot cause duplicate source capture or payload mutation');
Assert::same($beforeAttempts,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'rights refusal cannot spend model budget');

