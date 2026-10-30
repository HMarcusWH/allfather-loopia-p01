<?php

declare(strict_types=1);

/**
 * RT-02: complete offline shadow composition. No DB, source/provider network,
 * owner grant, source retention or customer output.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Ops\{
    BuildFreezeInspector,HistorySchemaPlan,HistoryRecoveryManifest,
    HistoryRecoveryPreflight,HistoryCredentialCutover,
};
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Workflow\{
    HistoryReviewFactory,HistoryReviewRelease,HistoryReviewAssurance,
};
use Allfather\Loopia\Tests\{
    Aq02MockAttention,Aq02FixtureAttentionOwner,LongitudinalBuildHarness,
    Va03MockRecovery,
};

$arg=array_slice($argv,1);
if(count($arg)!==1
    || !in_array($arg[0],['--status','--synthetic-development'],true)){
    fwrite(STDERR,"RT02_SHADOW_REPORT_ARGUMENT_NOT_ADOPTED\n");exit(3);
}
$root=dirname(__DIR__,2);
try{
    $freeze=BuildFreezeInspector::inspect($root);
    if(!$freeze->isOk()){
        fwrite(STDERR,"RT02_SOURCE_INVENTORY_HOLD: ".$freeze->reasonCode()."\n");
        exit(4);
    }
    if($arg[0]==='--status'){
        echo json_encode([
            'version'=>'rt02.inert-shadow-report.v1',
            'status'=>'SOURCE_INVENTORY_ONLY_NO_SOURCE_OR_MODEL_RUN',
            'inventory_digest'=>$freeze->value()['inventory_digest'],
            'package_count'=>$freeze->value()['package_count'],
            'candidate_schema_families'=>$freeze->value()['schema_families'],
            'hosted_ci_qualified_here'=>false,
            'real_owner_adoption'=>false,
            'live_migrations_or_customer_output'=>false,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        exit(0);
    }
    if(getenv('ALLFATHER_RT02_SYNTHETIC_TEST')!=='1'){
        fwrite(STDERR,"RT02_EXPLICIT_FIXED_SYNTHETIC_TEST_REQUIRED\n");exit(3);
    }
    require_once $root.'/tests/support/LongitudinalBuildHarness.php';
    require_once $root.'/tests/support/An03MockFinding.php';
    require_once $root.'/tests/support/Aq02FixtureAttentionOwner.php';
    require_once $root.'/tests/support/Aq02MockAttention.php';
    require_once $root.'/tests/support/Va03MockRecovery.php';
    if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
        define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
    }
    if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
        define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
    }

    $source=LongitudinalBuildHarness::forCase('allowed');
    $observed=$source->observe();
    if(($observed['outcome']??null)!=='FETCHED'
        || count($source->attemptedUrls())!==1
        || $source->modelPosture()['is_live']!==false
        || $source->modelPosture()['calls']!==0){
        throw new \DomainException('RT02_SOURCE_EGRESS_GUARD_FIXTURE_HOLD');
    }
    $redirect=LongitudinalBuildHarness::forCase('redirect_refused');
    if(($redirect->observe()['outcome']??null)!=='REFUSED'
        || count($redirect->attemptedUrls())!==1){
        throw new \DomainException('RT02_EGRESS_REDIRECT_REFUSAL_MISSING');
    }

    $basis=Aq02MockAttention::input();
    if(!$basis->isOk())throw new \DomainException('RT02_HISTORICAL_FIXTURE_INVALID');
    $attention=InternalAttention::composeFixture(
        $basis->value(),new Aq02FixtureAttentionOwner());
    if(!$attention->isOk())throw new \DomainException('RT02_ATTENTION_FIXTURE_INVALID');
    $plan=HistorySchemaPlan::inspect($root);
    if(!$plan->isOk())throw new \DomainException('RT02_SCHEMA_PLAN_MISSING');
    $candidate=HistoryReviewFactory::composeFullCandidate($root,[
        'schema_plan'=>$plan->value(),
        'historical_finding'=>$basis->value()['native_release_proposal']['historical_finding'],
        'native_release_proposal'=>$basis->value()['native_release_proposal'],
        'outcome_annotation'=>$basis->value()['outcome_annotation'],
        'internal_attention'=>$attention->value(),
    ]);
    if(!$candidate->isOk())throw new \DomainException('RT02_TYPED_COMPOSITION_FAILED');
    $inspection=M17Assurance::inspect(
        $basis->value()['native_release_proposal'],
        $basis->value()['outcome_annotation'],$attention->value(),
        '2026-10-30T12:00:00Z');
    if(!$inspection->isOk())throw new \DomainException('RT02_ASSURANCE_FIXTURE_HOLD');
    $assurance=HistoryReviewAssurance::compose([
        'observation'=>$inspection->value(),'queue'=>null,
        'cost'=>[
            'status'=>'UNKNOWN_PROVIDER_OUTCOME','reserved_units'=>null,
            'spent_units'=>null,'unknown_attempts'=>null,
        ],
        'coverage'=>[
            'status'=>'NOT_VERIFIED','planned_slots'=>null,
            'reported_slots'=>null,'oldest_missing_at_utc'=>null,
        ],
        'recovery'=>[
            'status'=>'NOT_VERIFIED_HOST','private_bytes'=>'NOT_VERIFIED',
            'credential_rotation'=>'NOT_VERIFIED','source_image'=>'NOT_VERIFIED',
        ],
        'at_utc'=>'2026-10-30T12:00:00Z',
    ]);
    if(!$assurance->isOk())throw new \DomainException('RT02_ASSURANCE_COMPOSE_INVALID');
    $recovery=Va03MockRecovery::manifest($root);
    if(!$recovery->isOk() || !HistoryRecoveryManifest::verify($recovery->value())->isOk()){
        throw new \DomainException('RT02_RECOVERY_SOURCE_FIXTURE_INVALID');
    }
    $release=HistoryReviewRelease::release(
        $basis->value()['native_release_proposal']['historical_finding']);
    if($release->isOk() || HistoryReviewFactory::forRuntime()->isOk()
        || HistoryRecoveryPreflight::requestRestore()->isOk()
        || HistoryCredentialCutover::execute()->isOk()){
        throw new \DomainException('RT02_OWNER_AUTHORITY_LEAK');
    }
    echo json_encode([
        'version'=>'rt02.inert-shadow-report.v1',
        'status'=>'FULL_SYNTHETIC_COMPOSITION_PASS_NO_OWNER_GRANT',
        'source_fixture_guarded_fetches'=>1,
        'redirect_out_of_scope_refused'=>true,
        'model_transport_calls'=>0,
        'typed_source_module_count'=>$candidate->value()['source_module_count'],
        'schema_plan_digest'=>$plan->value()['plan_digest'],
        'historical_finding_digest'=>$basis->value()['native_release_proposal']['finding_digest'],
        'later_outcome_digest'=>$basis->value()['outcome_annotation']['annotation_digest'],
        'attention_digest'=>$attention->value()['attention_digest'],
        'assurance_report_digest'=>$assurance->value()['report_digest'],
        'recovery_candidate_digest'=>$recovery->value()['manifest_digest'],
        'source_inventory_digest'=>$freeze->value()['inventory_digest'],
        'source_rights_authenticated'=>false,
        'model_execution_authorized'=>false,
        'actual_human_release_approved'=>false,
        'live_database_or_host_restore'=>false,
        'actual_m10_release_written'=>false,
        'actual_p04_distribution_sent'=>false,
        'customer_visibility_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"RT02_SYNTHETIC_COMPOSITION_OR_SOURCE_INTEGRITY_HOLD\n");
    exit(4);
}
