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
Assert::same('INSUFFICIENT_BASIS',$basis['selection_integrity'],
    'native P01 capture does not fabricate scheduled-stream completeness');
Assert::same('UNKNOWN',$basis['coverage']['state'],
    'a couple snapshots cannot prove full capture cadence coverage');
Assert::same('CALLER_SUPPLIED_UNVERIFIED',$basis['coverage']['basis'],
    'unknown coverage does not claim adopted source plan');
Assert::same(2,$basis['selected_count'],
    'both independently captured identities retained; '.json_encode([
        'input_count'=>$basis['input_count'],
        'cutoff'=>$cutoff,
        'excluded'=>$basis['excluded_counts_by_reason'],
        'selected_clocks'=>array_map(static fn(array $ref):array =>
            [$ref['observed_at'],$ref['known_at']],$basis['references']),
        'snapshot_clocks'=>array_column($snapshots,'captured_at'),
    ],JSON_THROW_ON_ERROR));
foreach($basis['references'] as $i=>$ref){
    Assert::same($snapshots[$i]['source_snapshot_id'],$ref['snapshot_id'],
        'reference points to actual P01 owner snapshot');
    Assert::same($snapshots[$i]['evidence_id'],$ref['evidence_id'],
        'reference points to actual M05 owner evidence');
    Assert::same($snapshots[$i]['content_hash'],$ref['payload_sha256'],
        'content digest tied to verified actual private bytes');
    Assert::same('UNKNOWN',$ref['semantic_polarity'],
        'capture frequency does not create a commercial inference');
    Assert::same('UNKNOWN',$ref['origin_group'],
        'identical capture origin cannot be counted as independent events');
    Assert::same('NOT_ASSESSED',$ref['rights_disposition'],
        'M05 verification is not historical-purpose U03 admission');
}
$display=json_encode($preview,JSON_THROW_ON_ERROR);
foreach([$f['before_body'],$f['after_body'],$f['capture_url'],
         'provider_credential_id','observation_payloads'] as $secret){
    Assert::false(str_contains($display,$secret),
        'source body, URL and provider details remain private: '.$secret);
}
$repeated=$value($reader->preview($actor,$f['competitor_id'],$question,
    $period,$cutoff),'same candidate preflight replay');
Assert::same($basis['manifest_digest'],$repeated['history_basis']['manifest_digest'],
    'unchanged owner evidence/cutoff yields identical frozen metadata digest');
Assert::same($preview['proof_manifest_digest'],$repeated['proof_manifest_digest'],
    'original policy, byte and known-at proof identities are reproducible');

$earlier=gmdate('Y-m-d\TH:i:s\Z',strtotime($clock->now())-3600);
$before=$value($reader->preview($actor,$f['competitor_id'],$question,
    $period,$earlier),'earlier as-of knowledge cutoff');
Assert::same(1,$before['history_basis']['selected_count'],
    'later native capture excluded from previous as-of basis');
Assert::same(1,$before['history_basis']['excluded_counts_by_reason']['NOT_YET_KNOWN'],
    'late knowledge is counted as an exclusion, not rewritten into earlier evidence');
Assert::notSame($basis['manifest_digest'],$before['history_basis']['manifest_digest'],
    'later knowledge becomes a successor digest, not mutation');

// Even genuine source bytes cannot bypass U03 or coverage to activate M17.
Assert::same('LI07C_BASIS_NOT_ADMISSIBLE_FOR_CANDIDATE',
    HistoryM17CandidateBridge::compose($basis,'SOURCE_DIFF','MONTH')->reasonCode(),
    'full source-backed persistence must remain gated until rights and coverage are adopted');

// Exercise immutable candidate-only 270 replay using actual owner-derived refs.
$proposal=dirname(__DIR__,2).
    '/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql';
$installed=false;
try {
    foreach(Migrator::splitStatements((string)file_get_contents($proposal)) as $sql){
        $db->pdo()->exec($sql);
    }
    $installed=true;
    if(!defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',true);
    }
    $store=$value(HistoryReviewBasisStore::forDisposableTest($db,$clock),
        'candidate-only basis store');
    $saved=$value($store->recordCandidate($basis),'stored native-backed unadmitted basis');
    Assert::same('DISPOSABLE_BASIS_RECORDED',$saved['action'],
        'verified capture metadata can be frozen without authorization');
    $replay=$value($store->replayCandidate($basis['scope'],
        $saved['history_review_basis_id']),'replay native-backed scoped graph');
    Assert::same('MATCH_METADATA_ONLY',$replay['integrity'],
        'database readback is integrity-only and does not authorize use');
    Assert::same(false,$replay['historical_reuse_admitted'],
        'disposable persistence cannot upgrade U03 rights');
    Assert::same('EXACT_REPLAY_NO_WRITE',$value($store->recordCandidate($basis),
        'exact basis replay')['action'],
        'verified native replay cannot duplicate immutable source refs');
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations
        WHERE migration_id LIKE '270_history_review_bases%'"),
        'runtime migrator never applied unadopted 270');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $db->pdo()->exec('DROP TABLE IF EXISTS history_review_basis_refs');
        $db->pdo()->exec('DROP TABLE IF EXISTS history_review_bases');
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

// Revalidates current rights each time, rather than trusting historical capture.
$db->run('UPDATE source_license_cards SET review_status=? WHERE source_license_card_id=?',
    ['blocked',$f['card']['source_license_card_id']]);
$revoked=$reader->preview($actor,$f['competitor_id'],$question,$period,$cutoff);
Assert::same('LI05D_NATIVE_CAPTURE_HOLD',$revoked->reasonCode(),
    'current revoked/blocked source prevents historical re-use');
Assert::same('M17_HISTORICAL_CURRENT_RIGHTS_BLOCKED',
    $revoked->detail['reason_code']??null,
    'refusal surfaces precise safe rights HOLD rather than dropping references');
$db->run('UPDATE source_license_cards SET review_status=? WHERE source_license_card_id=?',
    ['approved',$f['card']['source_license_card_id']]);

$db->run('UPDATE evidence_objects SET evidence_status=? WHERE evidence_id=?',
    ['QUARANTINED',$snapshots[0]['evidence_id']]);
$quarantine=$reader->preview($actor,$f['competitor_id'],$question,$period,$cutoff);
Assert::same('LI05D_NATIVE_CAPTURE_HOLD',$quarantine->reasonCode(),
    'quarantined primary M05 evidence refuses entire source-backed basis');
Assert::same('M17_HISTORICAL_REVOKED_OR_QUARANTINED',
    $quarantine->detail['reason_code']??null,
    'quarantine is a distinct operator cause, not a new model permission');
$db->run('UPDATE evidence_objects SET evidence_status=? WHERE evidence_id=?',
    ['CAPTURED',$snapshots[0]['evidence_id']]);

$emptyPrivateStore=new EvidenceStore(sys_get_temp_dir().'/li05d-empty-'.Ids::new());
$missing=(new NativeCaptureHistoryCandidate($db,$clock,$emptyPrivateStore))
    ->preview($actor,$f['competitor_id'],$question,$period,$cutoff);
Assert::same('LI05D_NATIVE_CAPTURE_HOLD',$missing->reasonCode(),
    'metadata without original private bytes never becomes historical evidence');
Assert::same('M17_HISTORICAL_BYTES_NOT_AVAILABLE',
    $missing->detail['reason_code']??null,
    'missing retained bytes is distinguished from revoked source rights');

$bad=$actor;$bad['actor_id']=Ids::new();
Assert::same('LI05D_NATIVE_BASIS_NOT_AUTHORIZED',
    $reader->preview($bad,$f['competitor_id'],$question,$period,$cutoff)->reasonCode(),
    'unassigned principal cannot enumerate historical source identities');
$foreign=Seed::tenantWorkspace($db,$clock,'li05d-other-tenant');
$otherRole=Seed::role($db,$clock,'li05d-other-history',[
    'competition_monitoring.read','evidence.read',
]);
Seed::membership($db,$clock,$foreign['tenant_id'],$foreign['workspace_id'],
    $foreign['operator_id'],$otherRole['role_id']);
$foreignActor=[
    'tenant_id'=>$foreign['tenant_id'],
    'workspace_id'=>$foreign['workspace_id'],
    'actor_type'=>'OPERATOR',
    'actor_id'=>$foreign['operator_id'],
];
Assert::same('LI05D_NATIVE_BASIS_NOT_FOUND',
    $reader->preview($foreignActor,$f['competitor_id'],$question,
        $period,$cutoff)->reasonCode(),
    'foreign tenant receives no source identities or captured-byte information');

Assert::same(2,$source->calls,'historical preflight performs no new network source fetch');
Assert::same(0,$model->calls,'historical preflight cannot trigger fixture model');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts'),
    'no provider budget or attempt created by the native preflight');
