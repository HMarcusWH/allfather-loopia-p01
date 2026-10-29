<?php

declare(strict_types=1);

/** AN-02 320 disposable source: retained analyst → critic → human HOLD. */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewFactory,HistoryReviewService,
    HistoryReviewJournal,HistoryM17V2CandidateBridge,PointInTimeHistoricalBasis
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$sqlPath=$root.'/docs/longitudinal/proposals/320_history_review_runs_CANDIDATE.sql';
Assert::true(is_file($sqlPath),'family320 is proposed SQL, not an applied native migration');
$sql=Migrator::splitStatements((string)file_get_contents($sqlPath));
Assert::same(9,count($sql),'three isolated journal tables and six append-only triggers');
$tables=['history_review_artifact_refs','history_review_events','history_review_runs'];
$installed=false;
try{
    foreach($sql as $stmt)$db->pdo()->exec($stmt);
    $installed=true;
    if(!defined('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED'))
        define('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-29T12:05:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'an02-owner');
    $foreign=Seed::tenantWorkspace($db,$clock,'an02-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AN02 synthetic history review',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE',
        'created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $v=json_decode((string)file_get_contents(
        dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$row){
        foreach($scope as $key=>$value)$row[$key]=$value;
    }
    unset($row);
    $legacy=[
        'scope'=>$scope,'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>[
            'state'=>'KNOWN','plan_version_id'=>'an02.synthetic.disposable',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0,
        ],
    ];
    $basis=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>$legacy,
        'previous_basis_digest'=>null,
        'review_at_utc'=>'2026-03-15T00:00:00Z',
    ]);
    Assert::true($basis->isOk(),'source-scoped exact point-in-time basis built');
    if(!$basis->isOk())return;
    $bridge=HistoryM17V2CandidateBridge::compose(
        $basis->value(),'SOURCE_DIFF','MONTH');
    Assert::true($bridge->isOk(),'M17 derived memory bound to original primary refs');
    if(!$bridge->isOk())return;
    $memory=$bridge->value()['candidate_v2'];
    $makeRequest=static function(string $review,string $attempt,string $suffix)
        use($scope,$basis):array {
        $o=HistoryReviewAdmission::freeze([
            'version'=>HistoryReviewAdmission::VERSION,
            'scope'=>$scope,
            'review_id'=>$review,
            'basis_digest'=>$basis->value()['basis_digest'],
            'question_digest'=>hash('sha256','an02.test.question'),
            'prompt_bundle_digest'=>hash('sha256','an02.test.prompt.v1'),
            'model_policy_digest'=>hash('sha256','an02.no.model'),
            'credential_ref_digest'=>hash('sha256','an02.no.real.key'),
            'parent_grant_digest'=>hash('sha256','an02.no.real.grant'),
            'app_fingerprint'=>hash('sha256','an02.local.execution'),
            'request_digest'=>hash('sha256','an02.test.request.'.$suffix),
            'attempt_id'=>$attempt,
            'purpose'=>HistoryReviewAdmission::PURPOSE,
            'mode'=>HistoryReviewAdmission::MODE,
            'requested_at_utc'=>'2026-10-29T12:00:00Z',
            'valid_from_utc'=>'2026-10-29T11:00:00Z',
            'valid_until_utc'=>'2026-10-29T14:00:00Z',
            'parent_max_attempts'=>8,'review_max_attempts'=>4,
            'max_input_tokens'=>4096,'max_output_tokens'=>1024,
            'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
        ]);
        Assert::true($o->isOk(),'AN01 metadata-only request remains exact');
        if(!$o->isOk())throw new RuntimeException('AN02_REQUEST_UNAVAILABLE');
        return $o->value();
    };
    $store=HistoryReviewJournal::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'CLI and explicitly disposable family320 factory exists');
    if(!$store->isOk())return;
    $store=$store->value();
    $r=$makeRequest('an02_demo_review_1','an02_demo_attempt_1','one');
    $start=HistoryReviewService::freezeRun($r,$basis->value(),$memory);
    Assert::true($start->isOk(),'new historical run frozen before an artifact');
    $created=$store->startRun($start->value());
    Assert::true($created->isOk(),'run identity persisted before any cursor');
    if(!$created->isOk())return;
    $runId=$created->value()['history_review_run_id'];
    Assert::same('EXACT_RUN_REPLAY_NO_WRITE',
        $store->startRun($start->value())->value()['action'],
        'same frozen run is immutable idempotent');
    Assert::same('REQUEST_INSPECTION_REQUIRED',
        $store->replayCandidate($scope,$runId)->value()['state'],
        'initial replay awaits synthetic request inspection');
    $inspect=$store->appendStage($scope,$runId,
        'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:01Z');
    Assert::true($inspect->isOk(),'explicit local request inspection acknowledged');
    Assert::same('ANALYST_READY',$inspect->value()['state'],
        'synthetic inspection cannot authorize provider execution');
    $analyst=HistoryReviewFactory::mockAnalyst($r,$basis->value(),$memory);
    Assert::true($analyst->isOk(),'local analyst outputs support and alternatives');
    if(!$analyst->isOk())return;
    $step2=$store->appendStage($scope,$runId,
        'ANALYST',$analyst->value(),'2026-10-29T12:00:02Z');
    Assert::true($step2->isOk(),'analyst candidate retained before journal cursor advances');
    Assert::same('CRITIC_REQUIRED',$step2->value()['state'],
        'separate challenge role is mandatory');
    Assert::same(1,(int)$db->scalar(
        "SELECT COUNT(*) FROM history_review_artifact_refs
         WHERE history_review_run_id=?",[$runId]),
        'analyst artifact exists at exactly the retained cursor');
    $critic=HistoryReviewFactory::mockCritic($analyst->value());
    Assert::true($critic->isOk(),'mock critic preserves contrary evidence');
    if(!$critic->isOk())return;
    $step3=$store->appendStage($scope,$runId,
        'CRITIC',$critic->value(),'2026-10-29T12:00:03Z');
    Assert::true($step3->isOk(),'mock critic challenge retained before human-HOLD');
    Assert::same('HUMAN_REVIEW_REQUIRED',$step3->value()['state'],
        'critic is neither factual nor routing human reviewer');
    Assert::same(false,$step3->value()['human_review_approved'],
        'human review remains an explicit later decision');
    $replayed=$store->replayCandidate($scope,$runId);
    Assert::true($replayed->isOk(),'journal replays all three event checksums and artifacts');
    Assert::same('HUMAN_REVIEW_REQUIRED',$replayed->value()['state'],
        'human-HOLD is actual stored final state');
    Assert::same(3,$replayed->value()['event_count'],
        'inspection, analyst, challenge exactly three transitions');
    Assert::same(2,(int)$db->scalar(
        'SELECT COUNT(*) FROM history_review_artifact_refs WHERE history_review_run_id=?',
        [$runId]),'both role outputs retained, not just cursor metadata');
    Assert::same(false,$replayed->value()['human_factual_release_approved'],
        'first human boundary not crossed');
    Assert::same(false,$replayed->value()['human_delivery_approved'],
        'second human delivery boundary not crossed');
    Assert::same('EXACT_STAGE_REPLAY_NO_WRITE',
        $store->appendStage($scope,$runId,'CRITIC',
            $critic->value(),'2026-10-29T12:00:03Z')->value()['action'],
        'crash retry with identical critic artifact does not rewrite');
    $altered=$critic->value();
    $altered['invented_customer_approval']=true;
    Assert::same('AN02_SAME_STAGE_REPLAY_PAYLOAD_CONFLICT',
        $store->appendStage($scope,$runId,'CRITIC',
            $altered,'2026-10-29T12:00:03Z')->reasonCode(),
        'same digest with altered payload cannot impersonate exact replay');
    Assert::same('AN02_TERMINAL_REVIEW_HOLD_NOT_ADVANCEABLE',
        $store->appendStage($scope,$runId,
            'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:04Z')->reasonCode(),
        'a human-HOLD cannot auto-complete into a customer release');

    $foreignScope=$scope;$foreignScope['tenant_id']=$foreign['tenant_id'];
    $foreignScope['workspace_id']=$foreign['workspace_id'];
    Assert::same('AN02_RUN_NOT_IN_OWNER_SCOPE',
        $store->replayCandidate($foreignScope,$runId)->reasonCode(),
        'tenant/workspace mismatch is indistinguishable from missing history');
    $badQuestion=$scope;$badQuestion['question_id']='another_question';
    Assert::same('AN02_RUN_NOT_IN_OWNER_SCOPE',
        $store->appendStage($badQuestion,$runId,'INSPECT_SYNTHETIC',
            null,'2026-10-29T12:00:04Z')->reasonCode(),
        'wrong question cannot read or mutate the history journal');

    // A quiet-no-new-insight result must remain distinct from an assertion
    // that a positive business change was discovered.
    $r2=$makeRequest('an02_demo_review_2','an02_demo_attempt_2','two');
    $run2=HistoryReviewService::freezeRun($r2,$basis->value(),$memory)->value();
    $id2=$store->startRun($run2)->value()['history_review_run_id'];
    Assert::true($store->appendStage($scope,$id2,'INSPECT_SYNTHETIC',
        null,'2026-10-29T12:00:01Z')->isOk(),
        'no-new-insight run receives the same explicit inspect step');
    $quiet=HistoryReviewFactory::mockAnalyst($r2,$basis->value(),$memory,'NO_NEW_INSIGHT');
    Assert::true($quiet->isOk(),'mock analyst does not need a fabricated strategic insight');
    Assert::true($store->appendStage($scope,$id2,'ANALYST',
        $quiet->value(),'2026-10-29T12:00:02Z')->isOk(),
        'candidate deliberately proposes no new insight');
    $noNewCritic=HistoryReviewFactory::mockCritic($quiet->value(),'NO_NEW_INSIGHT');
    Assert::true($noNewCritic->isOk(),'critic may accept the absence of new insight');
    Assert::same('NO_NEW_INSIGHT',
        $store->appendStage($scope,$id2,'CRITIC',
            $noNewCritic->value(),'2026-10-29T12:00:03Z')->value()['state'],
        'NO_NEW_INSIGHT is its own terminal state, not a fake finding');

    // Unknown hypothetical provider outcome: NO second paid call.
    $r3=$makeRequest('an02_demo_review_3','an02_demo_attempt_3','three');
    $run3=HistoryReviewService::freezeRun($r3,$basis->value(),$memory)->value();
    $id3=$store->startRun($run3)->value()['history_review_run_id'];
    $unknown=HistoryReviewFactory::mockUnknownTransport(
        $r3,'2026-10-29T12:03:00Z');
    Assert::true($unknown->isOk(),'AN01 unknown outcome is a typed candidate receipt');
    Assert::same('UNKNOWN_OUTCOME_HOLD',$store->appendStage(
        $scope,$id3,'UNKNOWN_OUTCOME',$unknown->value(),
        '2026-10-29T12:03:00Z')->value()['state'],
        'no new provider spend or retry from uncertain receipt');
    Assert::same('UNKNOWN_OUTCOME_HOLD',
        $store->replayCandidate($scope,$id3)->value()['state'],
        'recoverable unknown-outcome receipt survives readback');

    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_review_runs'),
        'three independent review identities, not MonitorService records');
    Assert::same(7,(int)$db->scalar('SELECT COUNT(*) FROM history_review_events'),
        '3 human-HOLD + 3 no-insight + 1 unknown, no duplicate events');
    Assert::same(5,(int)$db->scalar('SELECT COUNT(*) FROM history_review_artifact_refs'),
        'two analyst+critic pairs and one unknown receipt retained');
    foreach($tables as $table){
        try{$db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('AN02 appendix delete bypassed 320 triggers');}
        catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '320%'"),
        'family320 never appears in applied runtime migrations');
}finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table)$db->pdo()->exec(
            'DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
