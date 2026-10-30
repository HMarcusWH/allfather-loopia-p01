<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Ops\{HistoryRecoveryManifest,HistoryRecoveryPreflight};

/** Fixed fake manifests; synthetic content strings are never real M05 bytes. */
final class Va03MockRecovery
{
    public const CUTOFF='2026-03-15T00:00:00Z';
    public const CREATED='2026-10-30T12:00:00Z';
    public const REF='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const TREE='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function bytes():array
    {
        return [
            'M05_PRIVATE_CAPTURE'=>'VA03-SYNTHETIC-M05-BYTES-NOT-A-REAL-CAPTURE',
            'HISTORICAL_ANALYST_ARTIFACT'=>'VA03-SYNTHETIC-ANALYST-ARTIFACT',
            'ATTEMPT_BEFORE_CURSOR'=>'VA03-ATTEMPT-RESERVED-BEFORE-CURSOR',
            'REQUEST_LOG_PREFIX'=>'VA03-SYNTHETIC-REQUEST-LOG-PREFIX',
        ];
    }

    public static function manifest(string $repoRoot,?array $scope=null):Outcome
    {
        $scope??=[
            'tenant_id'=>'va03_fixture_tenant',
            'workspace_id'=>'va03_fixture_workspace',
            'competitor_id'=>'va03_fixture_competitor',
            'question_id'=>'va03_fixture_question',
        ];
        $familyRows=[];
        foreach(HistoryRecoveryManifest::FAMILIES as $number=>$file){
            $path=rtrim($repoRoot,'/').'/docs/longitudinal/proposals/'.$file;
            if(!is_file($path))return Outcome::refuse('VA03_MOCK_CANDIDATE_SQL_MISSING');
            $familyRows[]=[
                'family'=>$number,
                'relative_path'=>'docs/longitudinal/proposals/'.$file,
                'source_sha256'=>hash_file('sha256',$path),
                'bytes'=>(int)filesize($path),
                'adopted_in_runtime'=>false,
            ];
        }
        $artifacts=[];
        foreach(self::bytes() as $kind=>$bytes){
            $artifacts[]=[
                'kind'=>$kind,
                'reference_id'=>'va03_fixture_'.strtolower($kind),
                'sha256'=>hash('sha256',$bytes),
                'bytes'=>strlen($bytes),
                'sensitive'=>true,
                'embedded_bytes'=>false,
            ];
        }
        $requestDigest=hash('sha256',self::bytes()['REQUEST_LOG_PREFIX']);
        return HistoryRecoveryManifest::compose([
            'source'=>[
                'release_commit_sha'=>self::REF,
                'source_tree_sha'=>self::TREE,
                'image_digest'=>hash('sha256','VA03-SYNTHETIC-CONTAINER-IMAGE'),
                'knowledge_cutoff_utc'=>self::CUTOFF,
            ],
            'p10'=>[
                'original_manifest_sha256'=>hash('sha256','VA03-SYNTHETIC-P10-1'),
                'database_dump_sha256'=>hash('sha256','VA03-SYNTHETIC-SQL-DUMP'),
                'request_log_prefix_sha256'=>$requestDigest,
                'p10_v1_package_proved'=>false,
            ],
            'scope'=>$scope,
            'history'=>[
                'basis_digest'=>hash('sha256','VA03-HISTORY-270'),
                'dependency_graph_digest'=>hash('sha256','VA03-IV01-350'),
                'review_run_digest'=>hash('sha256','VA03-AN02-320'),
                'attempt_reservation_digest'=>hash('sha256','VA03-AN01-310'),
                'journal_digest'=>hash('sha256','VA03-JOURNAL-320'),
                'known_at_cutoff_utc'=>self::CUTOFF,
                'attempt_recorded_before_cursor'=>true,
            ],
            'families'=>$familyRows,
            'private_artifacts'=>$artifacts,
            'keys'=>[
                'age_recipient_fingerprint'=>hash('sha256','VA03-AGE-RECIPIENT-FAKE'),
                'age_identity_external'=>true,
                'p11_active_version'=>'v1_fixture',
                'p11_escrow_verified'=>false,
                'database_user_rotation_verified'=>false,
                'backup_access_rotation_verified'=>false,
            ],
            'created_at_utc'=>self::CREATED,
        ]);
    }

    public static function preflight(array $manifest):array
    {
        return [
            'manifest'=>$manifest,
            'source_release_commit'=>$manifest['source']['release_commit_sha'],
            'source_tree_sha'=>$manifest['source']['source_tree_sha'],
            'observed_at_utc'=>'2026-10-30T12:05:00Z',
            'maintenance'=>[
                'state'=>'QUIESCED_FIXTURE','reason'=>'BACKUP',
                'runtime_lease_quiesced'=>true,
                'new_writer_gates'=>true,
                'authority_stood_down'=>true,
            ],
            'r5_backup_pair'=>[
                'source'=>'R5_READ_ONLY_PROVIDER_INVENTORY',
                'status'=>'METADATA_LISTED_NOT_PAIRED',
                'app_volume_snapshot_digest'=>hash('sha256','VA03-APP-VOLUME-METADATA'),
                'database_volume_snapshot_digest'=>hash('sha256','VA03-DB-VOLUME-METADATA'),
                'same_checkpoint_proven'=>false,
                'provider_restore_tested'=>false,
            ],
            'credential_readiness'=>[
                'p11_active_version'=>$manifest['keys']['p11_active_version'],
                'age_recovery_identity_separate'=>true,
                'age_decrypt_restored'=>false,
                'p11_rewrap_verified'=>false,
                'sql_account_password_rotated'=>false,
                'backup_access_rotated'=>false,
                'secret_material_in_manifest'=>false,
            ],
            'recovery_evidence'=>array_map(
                static fn(string $level):array=>[
                    'level'=>$level,'status'=>'NOT_PERFORMED',
                    'logical_restore_digest'=>null,
                    'physical_restore_digest'=>null,
                    'independent_target_verified'=>false,
                    'private_bytes_verified'=>false,
                ],HistoryRecoveryPreflight::LEVELS,
            ),
        ];
    }
}
