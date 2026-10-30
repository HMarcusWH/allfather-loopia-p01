<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * VA-03 / FullRestoreManifest.vNext candidate-only index.
 * Extends P10's verification vocabulary WITHOUT changing its v1 semantics,
 * performing an import, reading credentials, or promoting live SQL.
 */
final class HistoryRecoveryManifest
{
    public const VERSION='va03.full-restore-manifest.vNext-candidate';
    public const FAMILIES=[
        260=>'260_competitor_assessments_CANDIDATE.sql',
        265=>'265_observation_features_CANDIDATE.sql',
        270=>'270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
        280=>'280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
        290=>'290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
        300=>'300_history_review_queue_jobs_CANDIDATE.sql',
        310=>'310_history_review_execution_CANDIDATE.sql',
        320=>'320_history_review_runs_CANDIDATE.sql',
        330=>'330_historical_finding_bindings_CANDIDATE.sql',
        340=>'340_internal_attention_CANDIDATE.sql',
        345=>'345_history_review_outcomes_CANDIDATE.sql',
        350=>'350_history_dependencies_CANDIDATE.sql',
    ];
    public const ARTIFACT_KINDS=[
        'M05_PRIVATE_CAPTURE','HISTORICAL_ANALYST_ARTIFACT',
        'ATTEMPT_BEFORE_CURSOR','REQUEST_LOG_PREFIX',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';
    private const COMMIT='/^[a-f0-9]{40}$/D';
    private const TOP=[
        'source','p10','scope','history','families','private_artifacts',
        'keys','created_at_utc',
    ];

    /** Closed candidate: a stored hash is never a real host restore receipt. */
    public static function compose(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::TOP)
            || !is_array($input['source'])
            || !Vocabulary::exactKeys($input['source'],[
                'release_commit_sha','source_tree_sha','image_digest',
                'knowledge_cutoff_utc',
            ])
            || !self::sha($input['source']['image_digest']??null)
            || !self::commit($input['source']['release_commit_sha']??null)
            || !self::commit($input['source']['source_tree_sha']??null)
            || !Vocabulary::utc($input['source']['knowledge_cutoff_utc']??null)
            || !Vocabulary::utc($input['created_at_utc']??null)
            || $input['source']['knowledge_cutoff_utc']>$input['created_at_utc']
            || !is_array($input['scope'])
            || !Vocabulary::scope($input['scope'])
            || !is_array($input['p10'])
            || !Vocabulary::exactKeys($input['p10'],[
                'original_manifest_sha256','database_dump_sha256',
                'request_log_prefix_sha256','p10_v1_package_proved',
            ])
            || !self::sha($input['p10']['original_manifest_sha256']??null)
            || !self::sha($input['p10']['database_dump_sha256']??null)
            || !self::sha($input['p10']['request_log_prefix_sha256']??null)
            || $input['p10']['p10_v1_package_proved']!==false
            || !is_array($input['history'])
            || !Vocabulary::exactKeys($input['history'],[
                'basis_digest','dependency_graph_digest','review_run_digest',
                'attempt_reservation_digest','journal_digest',
                'known_at_cutoff_utc','attempt_recorded_before_cursor',
            ])
            || !Vocabulary::utc($input['history']['known_at_cutoff_utc']??null)
            || $input['history']['known_at_cutoff_utc']
                !==$input['source']['knowledge_cutoff_utc']
            || $input['history']['attempt_recorded_before_cursor']!==true
            || !is_array($input['keys'])
            || !Vocabulary::exactKeys($input['keys'],[
                'age_recipient_fingerprint','age_identity_external',
                'p11_active_version','p11_escrow_verified',
                'database_user_rotation_verified','backup_access_rotation_verified',
            ])
            || !self::sha($input['keys']['age_recipient_fingerprint']??null)
            || !Vocabulary::id($input['keys']['p11_active_version']??null)
            || $input['keys']['age_identity_external']!==true
            || $input['keys']['p11_escrow_verified']!==false
            || $input['keys']['database_user_rotation_verified']!==false
            || $input['keys']['backup_access_rotation_verified']!==false){
            return Outcome::refuse('VA03_RECOVERY_INPUT_OR_OWNER_POSTURE_INVALID');
        }
        foreach([
            'basis_digest','dependency_graph_digest','review_run_digest',
            'attempt_reservation_digest','journal_digest',
        ] as $key){
            if(!self::sha($input['history'][$key]??null)){
                return Outcome::refuse('VA03_HISTORY_LINEAGE_IDENTITY_INVALID');
            }
        }
        if(!is_array($input['families'])
            || !array_is_list($input['families'])
            || count($input['families'])!==count(self::FAMILIES)){
            return Outcome::refuse('VA03_SCHEMA_FAMILY_COVERAGE_INCOMPLETE');
        }
        foreach(array_values(self::FAMILIES) as $i=>$name){
            $row=$input['families'][$i];
            $number=array_keys(self::FAMILIES)[$i];
            if(!is_array($row)
                || !Vocabulary::exactKeys($row,[
                    'family','relative_path','source_sha256','bytes',
                    'adopted_in_runtime',
                ])
                || ($row['family']??null)!==$number
                || ($row['relative_path']??null)!==
                    'docs/longitudinal/proposals/'.$name
                || !self::sha($row['source_sha256']??null)
                || !is_int($row['bytes'])
                || $row['bytes']<1 || $row['bytes']>1048576
                || $row['adopted_in_runtime']!==false){
                return Outcome::refuse('VA03_SCHEMA_FAMILY_IDENTITY_INVALID');
            }
        }
        if(!is_array($input['private_artifacts'])
            || !array_is_list($input['private_artifacts'])
            || count($input['private_artifacts'])<4
            || count($input['private_artifacts'])>64){
            return Outcome::refuse('VA03_PRIVATE_ARTIFACT_INDEX_INCOMPLETE');
        }
        $seen=[];$types=[];
        foreach($input['private_artifacts'] as $artifact){
            if(!is_array($artifact)
                || !Vocabulary::exactKeys($artifact,[
                    'kind','reference_id','sha256','bytes','sensitive',
                    'embedded_bytes',
                ])
                || !in_array($artifact['kind']??null,self::ARTIFACT_KINDS,true)
                || !Vocabulary::id($artifact['reference_id']??null)
                || !self::sha($artifact['sha256']??null)
                || !is_int($artifact['bytes']) || $artifact['bytes']<1
                || $artifact['bytes']>10485760
                || $artifact['sensitive']!==true
                || $artifact['embedded_bytes']!==false
                || isset($seen[$artifact['sha256']])){
                return Outcome::refuse('VA03_PRIVATE_ARTIFACT_INVALID_OR_DUPLICATED');
            }
            $seen[$artifact['sha256']]=true;$types[$artifact['kind']]=true;
        }
        if(count($types)!==count(self::ARTIFACT_KINDS)){
            return Outcome::refuse('VA03_PRIVATE_ARTIFACT_KIND_MISSING');
        }
        if(!in_array($input['p10']['request_log_prefix_sha256'],
            array_column($input['private_artifacts'],'sha256'),true)){
            return Outcome::refuse('VA03_REQUEST_LOG_PREFIX_NOT_BOUND');
        }
        $v=[
            'version'=>self::VERSION,
            'status'=>'FULL_CANDIDATE_INDEX_NOT_RESTORE_PROOF',
            ...$input,
            'schema_family_count'=>count(self::FAMILIES),
            'private_artifact_count'=>count($input['private_artifacts']),
            'source_image_identity_claimed_only'=>true,
            'original_p10_package_authenticated'=>false,
            'host_backup_pair_authenticated'=>false,
            'logical_independent_restore_proved'=>false,
            'physical_independent_restore_proved'=>false,
            'populated_live_restore_proved'=>false,
            'live_candidate_schema_installed'=>false,
            'restore_authorized'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $v['manifest_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $candidate):Outcome
    {
        $input=[];
        foreach(self::TOP as $key){
            if(!array_key_exists($key,$candidate)){
                return Outcome::refuse('VA03_RECOVERY_REQUIRED_FIELD_MISSING');
            }
            $input[$key]=$candidate[$key];
        }
        $fresh=self::compose($input);
        if(!$fresh->isOk() || $fresh->value()!==$candidate){
            return Outcome::refuse('VA03_RECOVERY_MANIFEST_REHASH_OR_AUTHORITY_FORGED');
        }
        return Outcome::ok([
            'status'=>'SELF_HASHED_CANDIDATE_ONLY',
            'real_restore_proof'=>false,
        ]);
    }

    /**
     * Offline source read: proposal hashes must match exactly.
     * This is not verification of installed schema, P10 dump or host services.
     */
    public static function verifyProposalFiles(array $manifest,string $repoRoot):Outcome
    {
        if(!self::verify($manifest)->isOk()){
            return Outcome::refuse('VA03_RECOVERY_CANDIDATE_UNVERIFIED');
        }
        $base=realpath($repoRoot);
        if($base===false||!is_dir($base)){
            return Outcome::refuse('VA03_REPOSITORY_ROOT_UNAVAILABLE');
        }
        foreach($manifest['families'] as $row){
            $path=$base.'/'.$row['relative_path'];
            if(!is_file($path)||is_link($path)
                || !str_starts_with((string)realpath($path),$base.'/')){
                return Outcome::refuse('VA03_PROPOSED_SCHEMA_FILE_MISSING');
            }
            $digest=hash_file('sha256',$path);
            $size=filesize($path);
            if($digest!==$row['source_sha256'] || $size!==$row['bytes']){
                return Outcome::refuse('VA03_PROPOSED_SCHEMA_BYTES_CHANGED');
            }
        }
        return Outcome::ok([
            'status'=>'PROPOSAL_FILE_HASHES_VERIFIED_OFFLINE',
            'schema_family_count'=>count(self::FAMILIES),
            'live_migration_verified'=>false,
        ]);
    }

    /**
     * Verify synthetic encrypted-artifact stand-ins under a *private* root.
     * No caller path from the manifest is accepted. File names are SHA-256.
     * Raw bytes stay in the local fixture folder and are never returned.
     */
    public static function verifyPrivateFixtureBytes(array $manifest,string $privateRoot):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST')
            || constant('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST')!==true){
            return Outcome::refuse('VA03_PRIVATE_READ_RUNTIME_NOT_ADOPTED');
        }
        if(!self::verify($manifest)->isOk()){
            return Outcome::refuse('VA03_PRIVATE_MANIFEST_UNVERIFIED');
        }
        // chmod(0600) does not provide Windows ACL isolation. Do not
        // silently claim POSIX privacy when running on another filesystem.
        if(DIRECTORY_SEPARATOR!=='/'){
            return Outcome::refuse('VA03_WINDOWS_ACL_PRIVATE_PROOF_UNSUPPORTED');
        }
        $root=realpath($privateRoot);
        if($root===false||!is_dir($root)||is_link($privateRoot)
            || str_contains($root,'/public_html/')
            || str_ends_with($root,'/public_html')
            || (fileperms($root)&0077)!==0){
            return Outcome::refuse('VA03_PRIVATE_FIXTURE_ROOT_INVALID');
        }
        foreach($manifest['private_artifacts'] as $row){
            $path=$root.'/'.$row['sha256'];
            if(is_link($path)||!is_file($path)
                || realpath($path)!==$path
                || (fileperms($path)&0077)!==0){
                return Outcome::refuse('VA03_PRIVATE_REFERENCED_BLOB_MISSING');
            }
            $digest=hash_file('sha256',$path);
            if($digest!==$row['sha256']
                || filesize($path)!==$row['bytes']){
                return Outcome::refuse('VA03_PRIVATE_BLOB_DIGEST_OR_LENGTH_INVALID');
            }
        }
        return Outcome::ok([
            'status'=>'SYNTHETIC_PRIVATE_BLOBS_MATCH',
            'count'=>count($manifest['private_artifacts']),
            'actual_m05_owner_bytes_authenticated'=>false,
            'restore_proved'=>false,
        ]);
    }

    private static function sha(mixed $value):bool
    {
        return is_string($value)&&preg_match(self::HEX,$value)===1;
    }
    private static function commit(mixed $value):bool
    {
        return is_string($value)&&preg_match(self::COMMIT,$value)===1;
    }
}
