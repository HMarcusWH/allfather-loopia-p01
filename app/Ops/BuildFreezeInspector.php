<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Digest,Outcome};

/**
 * RT-02: complete read-only source inventory and build-freeze PREPARATION.
 *
 * GitHub CI must bind final exact SHA/tree externally: embedding one's own
 * final Git SHA in a tracked file is self-referential and cannot be verified.
 * This code has no git mutation, DB, network, provider or production action.
 */
final class BuildFreezeInspector
{
    public const VERSION='rt02.source-freeze-inventory.v1';
    public const PACKAGE_COUNT=29;
    public const CONTRACT_CARRIER_COUNT=64;
    public const PRIMARY_CONTRACT_COUNT=14;

    public const CRITICAL_PATHS=[
        'app/Ops/BuildFirstRegistry.php',
        'app/Ops/BuildFirstContracts.php',
        'app/Ops/BuildFirstReceipt.php',
        'app/Workflow/HistoryReviewBasis.php',
        'app/Workflow/PointInTimeHistoricalBasis.php',
        'app/Workflow/HistoryReviewRightsGateway.php',
        'app/Workflow/HistoryReviewService.php',
        'app/Workflow/HistoryReviewQueue.php',
        'app/Workflow/HistoryReviewOutcomes.php',
        'app/Workflow/NativeReleaseProposal.php',
        'app/Workflow/HistoryReviewFollowUp.php',
        'app/Workflow/HistoryDependencyIndex.php',
        'app/Workflow/HistoryReviewFactory.php',
        'app/Ops/HistorySchemaPlan.php',
        'app/Ops/HistorySchemaFixtureInstaller.php',
        'app/Ops/HistoryRecoveryManifest.php',
        'app/Ops/HistoryCredentialCutover.php',
        'app/Ops/BackupManifest.php',
        'app/Ops/RuntimeInvariantProbe.php',
        'app/Product/P04/InternalAttention.php',
        'app/Product/P04/InternalDigest.php',
        'app/Http/ReadAdapters/HistoryReviewAdapter.php',
        'app/Http/Controllers/HistoryReviewController.php',
        'app/Workflow/HistoryReviewCommandCandidate.php',
        'app/Substrate/M17/MemoryStore.php',
        'app/Substrate/M17/Replay.php',
        'app/Substrate/M11/Assurance.php',
        'app/Substrate/M10/DecisionLedger.php',
        'app/PromptOps/HistoryReviewCandidateChecks.php',
        'tests/support/An03MockFinding.php',
        'tests/support/Aq02MockAttention.php',
        'tests/support/LongitudinalBuildHarness.php',
        'tests/fixtures/longitudinal/contracts/candidate_examples.v1.json',
        'docs/longitudinal/build-first/CAPABILITY_MATRIX.json',
        'docs/longitudinal/build-first/CONTRACT_REGISTRY.json',
        'docs/longitudinal/build-first/SCHEMA_FAMILY_REGISTRY.json',
        'docs/longitudinal/build-first/EVALUATION_FREEZE_MANIFEST.json',
        'docs/longitudinal/BUILD_FIRST_DECISION_MATRIX.json',
        'docs/longitudinal/proposals/260_350_schema_contracts/INSTALL_MANIFEST.json',
        'docs/BUILD_FREEZE_MANIFEST.json',
        'docs/BUILD_LICENSE_SBOM_EVIDENCE.json',
        'docs/LONGITUDINAL_RELEASE_ACCEPTANCE.md',
        'docs/BUILD_FIRST_IMPLEMENTATION_RECEIPT.md',
        'upstream.lock.json',
        'composer.json',
        'frontend/console/package-lock.json',
        'frontend/console/src/views/p01/history-review-workbench.jsx',
        'frontend/console/src/data/history-review-workbench-view-model.js',
        'ops/history/shadow-report.php',
        'ops/road-test/export-history-review-evidence.php',
        'ops/history/package-source.sh',
    ];

    public static function inspect(string $root):Outcome
    {
        $root=realpath($root);
        if($root===false||!is_dir($root)){
            return Outcome::refuse('RT02_SOURCE_ROOT_MISSING');
        }
        try{
            $basis=BuildFirstRegistry::loadAndValidate($root);
            $contracts=BuildFirstContracts::loadAndValidate($root);
            $manifest=self::readJson($root.'/docs/BUILD_FREEZE_MANIFEST.json');
        }catch(\Throwable){
            return Outcome::refuse('RT02_BUILD_CONTRACT_OR_FROZEN_INPUT_INVALID');
        }
        if(($manifest['schema_version']??null)!=='rt02.build-freeze-preparation.v1'
            || ($manifest['status']??null)!=='CANDIDATE_SOURCE_FREEZE_NOT_CI_ATTESTED'
            || ($manifest['owner_authority_granted']??null)!==false
            || ($manifest['runtime_or_customer_released']??null)!==false
            || ($manifest['source_anchor']['repository']??null)!=='HMarcusWH/allfather-loopia-p01'
            || ($manifest['source_anchor']['integrated_rt01_commit']??null)
                !=='f55f18b5ce1b4875663ecbe7283ea3b4cb4e33b8'
            || ($manifest['source_anchor']['integrated_rt01_tree']??null)
                !=='8c02041c6753a9c675b01b9602f24b852c580209'
            || ($manifest['source_anchor']['rt02_final_git_identity']??null)
                !=='BIND_WITH_HOSTED_TEST_MERGE_AND_POST_MERGE_RECEIPT'
            || ($manifest['artifact_allowlist']??null)!==[
                'app/','database/','docs/','frontend/','ops/',
                'public_html/','storage/README.md','storage/.htaccess',
                'tests/','worker/','.github/',
                '.dockerignore','.env.example','.gitignore','.htaccess',
                'BOOTSTRAP.md','Dockerfile','PROJECT_BOUNDARY.md',
                'README.md','UPSTREAM_AUTHORITY.md','composer.json',
                'deployment-evidence.json','upstream.lock.json',
            ]
            || ($manifest['verification_phase']??null)!=='V01_V11_NOT_RUN'
            || ($manifest['license_review']??null)!=='NOT_INDEPENDENTLY_ATTESTED'){
            return Outcome::refuse('RT02_FREEZE_PREPARATION_SHAPE_OR_AUTHORITY_INVALID');
        }
        $packages=$basis['capabilities']['build_packages'];
        if(!is_array($packages)||count($packages)!==self::PACKAGE_COUNT
            || ($manifest['frozen_build_spec']['expected_package_ids']??null)
                !==array_column($packages,'id')
            || ($manifest['frozen_build_spec']['requires_package_count']??null)
                !==self::PACKAGE_COUNT){
            return Outcome::refuse('RT02_PACKAGE_COUNT_INCOMPLETE');
        }
        $packageList=[];$ids=[];
        foreach($packages as $package){
            if(!is_array($package)
                || !is_string($package['id']??null)
                || isset($ids[$package['id']])
                || ($package['build_status']??null)!=='BUILT_INERT'
                || !is_string($package['source_basis']??null)
                || $package['source_basis']==='REUSE_EXISTING_CODE_AFTER_PER_PACKAGE_AUDIT'
                    && $package['id']==='RT-02'
                || ($package['native_permission']??null)!==false
                || ($package['external_effects']??null)!==false
                || ($package['live_migration']??null)!==false
                || ($package['customer_visibility']??null)!==false
                || ($package['production_activation']??null)!==false){
                return Outcome::refuse('RT02_REQUIRED_BUILD_PACKAGE_NOT_IMPLEMENTED');
            }
            $ids[$package['id']]=true;
            $packageList[]=[
                'id'=>$package['id'],
                'source_status'=>'BUILT_INERT_NOT_OWNER_ADMITTED',
                'source_basis_digest'=>hash('sha256',$package['source_basis']),
            ];
        }
        if(($packageList[count($packageList)-1]['id']??null)!=='RT-02'){
            return Outcome::refuse('RT02_FINAL_PACKAGE_ORDER_INVALID');
        }
        if(!is_array($contracts['index']??null)
            || count($contracts['index'])!==self::CONTRACT_CARRIER_COUNT
            || !is_array($contracts['primary_contracts']??null)
            || count($contracts['primary_contracts'])!==self::PRIMARY_CONTRACT_COUNT){
            return Outcome::refuse('RT02_CONTRACT_REGISTRY_COUNT_MISMATCH');
        }
        $unadopted=[];
        foreach($basis['decisions']['decisions'] as $gate){
            if(!is_array($gate)
                || !is_string($gate['id']??null)
                || ($gate['semantic_adopted']??null)!==false
                || ($gate['php_adopted']??null)!==false
                || ($gate['execution_authorized']??null)!==false
                || ($gate['customer_output_authorized']??null)!==false){
                return Outcome::refuse('RT02_OWNER_GATE_CHANGED_REFREEZE_REQUIRED');
            }
            $unadopted[]=$gate['id'];
        }
        if($unadopted!==BuildFirstRegistry::GATES){
            return Outcome::refuse('RT02_OWNER_GATE_COVERAGE_INCOMPLETE');
        }
        $schema=HistorySchemaPlan::inspect($root);
        if(!$schema->isOk()
            || $schema->value()['family_count']!==12
            || $schema->value()['table_count']!==38
            || $schema->value()['trigger_count']!==76){
            return Outcome::refuse('RT02_SCHEMA_FAMILY_MISMATCH');
        }
        $checked=[];
        foreach(self::CRITICAL_PATHS as $path){
            $absolute=$root.'/'.$path;
            if(!is_file($absolute)||is_link($absolute)){
                return Outcome::refuse('RT02_REQUIRED_SOURCE_OR_CLI_MISSING');
            }
            $digest=hash_file('sha256',$absolute);
            if(!is_string($digest)){
                return Outcome::refuse('RT02_REQUIRED_SOURCE_UNREADABLE');
            }
            $checked[$path]=$digest;
        }
        ksort($checked,SORT_STRING);
        $composer=self::readJson($root.'/composer.json');
        $npm=self::readJson($root.'/frontend/console/package-lock.json');
        if(($composer['license']??null)!=='proprietary'
            || ($composer['private']??null)!==true
            || ($npm['lockfileVersion']??null)!==3
            || !is_array($npm['packages']??null)){
            return Outcome::refuse('RT02_LICENSE_OR_PACKAGE_LOCK_SOURCE_INVALID');
        }
        $result=[
            'version'=>self::VERSION,
            'status'=>'SOURCE_INVENTORY_COMPLETE_CI_AND_OWNER_SEPARATE',
            'source_anchor'=>$manifest['source_anchor'],
            'package_count'=>count($packageList),
            'package_index'=>$packageList,
            'plan_sha256'=>$basis['decisions']['execution_manifest_sha256'],
            'contract_carriers'=>count($contracts['index']),
            'primary_typed_carriers'=>count($contracts['primary_contracts']),
            'generic_carrier_producer_wiring'=>'NOT_A_LIVE_PRODUCER_CONSUMER_ATTESTATION',
            'schema_plan_digest'=>$schema->value()['plan_digest'],
            'schema_families'=>$schema->value()['family_count'],
            'candidate_tables'=>$schema->value()['table_count'],
            'candidate_append_only_triggers'=>$schema->value()['trigger_count'],
            'legacy_900_blob_sha'=>$schema->value()['immutable_900_git_blob_sha1'],
            'unadopted_gates'=>$unadopted,
            'checked_source_hashes'=>$checked,
            'source_allowlist_digest'=>Digest::canonicalDigest($checked),
            'npm_lock_sha256'=>hash_file('sha256',$root.'/frontend/console/package-lock.json'),
            'frontend_lock_package_count'=>count($npm['packages']),
            'composer_require_digest'=>Digest::canonicalDigest($composer['require']??[]),
            'composer_license_declared'=>'proprietary',
            'third_party_license_audit'=>'NOT_INDEPENDENTLY_ATTESTED',
            'public_archive_allowlist'=>$manifest['artifact_allowlist'],
            'public_archive_created_here'=>false,
            'git_final_sha_or_tree_measured_here'=>false,
            'host_migrations_or_recovery_qualified'=>false,
            'provider_source_or_paid_model_authorized'=>false,
            'real_human_release_approved'=>false,
            'customer_visibility_authorized'=>false,
            'verification_campaign'=>'V01_V11_NOT_RUN',
        ];
        $result['inventory_digest']=Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    /** Refuse self-hashed source receipts not reconstructed from actual files. */
    public static function verify(string $root,array $candidate):Outcome
    {
        $expected=self::inspect($root);
        if(!$expected->isOk()||$expected->value()!==$candidate){
            return Outcome::refuse('RT02_FREEZE_SOURCE_RECEIPT_FORGED_OR_STALE');
        }
        return Outcome::ok([
            'integrity'=>'EXACT_CURRENT_SOURCE_FILE_INDEX_ONLY',
            'hosted_ci_attested_here'=>false,
            'real_owner_authorized'=>false,
        ]);
    }

    private static function readJson(string $path):array
    {
        if(is_link($path)||!is_file($path))throw new \DomainException('RT02_JSON_SOURCE_MISSING');
        $data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new \DomainException('RT02_JSON_SOURCE_INVALID');
        return $data;
    }
}
