<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * VA-03: read-only metadata comparison of P10/R5/host/credential *claims*.
 * No DB connect, no maintenance mutation, no provider API, no restore call.
 * A listed backup object or a green source test is never a restore.
 */
final class HistoryRecoveryPreflight
{
    public const VERSION='va03.readonly-recovery-preflight.v1';
    public const LEVELS=[
        'EXISTING_LIVE_SOURCE',
        'ISOLATED_FULL_CANDIDATE',
        'POPULATED_POST_MIGRATION',
    ];
    private const KEYS=[
        'manifest','source_release_commit','source_tree_sha',
        'observed_at_utc','maintenance','r5_backup_pair',
        'credential_readiness','recovery_evidence',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';

    public static function inspect(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::KEYS)
            || !is_array($input['manifest'])
            || !HistoryRecoveryManifest::verify($input['manifest'])->isOk()
            || ($input['source_release_commit']??null)
                !==$input['manifest']['source']['release_commit_sha']
            || ($input['source_tree_sha']??null)
                !==$input['manifest']['source']['source_tree_sha']
            || !Vocabulary::utc($input['observed_at_utc']??null)
            || $input['observed_at_utc']<$input['manifest']['created_at_utc']){
            return Outcome::refuse('VA03_PREFLIGHT_SOURCE_IDENTITY_OR_CUTOFF_INVALID');
        }
        $maint=$input['maintenance'];
        if(!is_array($maint)
            || !Vocabulary::exactKeys($maint,[
                'state','reason','runtime_lease_quiesced','new_writer_gates',
                'authority_stood_down',
            ])
            || !in_array($maint['state']??null,[
                'QUIESCED_FIXTURE','NOT_QUIESCED',
            ],true)
            || ($maint['state']==='QUIESCED_FIXTURE'
                && ($maint['reason']??null)!=='BACKUP')
            || !is_bool($maint['runtime_lease_quiesced']??null)
            || !is_bool($maint['new_writer_gates']??null)
            || !is_bool($maint['authority_stood_down']??null)){
            return Outcome::refuse('VA03_MAINTENANCE_PROOF_INVALID');
        }
        $quiesced=$maint['state']==='QUIESCED_FIXTURE'
            && $maint['runtime_lease_quiesced']===true
            && $maint['new_writer_gates']===true
            && $maint['authority_stood_down']===true;
        $pair=$input['r5_backup_pair'];
        if(!is_array($pair)
            || !Vocabulary::exactKeys($pair,[
                'source','status','app_volume_snapshot_digest',
                'database_volume_snapshot_digest','same_checkpoint_proven',
                'provider_restore_tested',
            ])
            || $pair['source']!=='R5_READ_ONLY_PROVIDER_INVENTORY'
            || !in_array($pair['status']??null,[
                'METADATA_LISTED_NOT_PAIRED','NO_BACKUPS_OBSERVED',
            ],true)
            || ($pair['app_volume_snapshot_digest']!==null
                && !self::hex($pair['app_volume_snapshot_digest']))
            || ($pair['database_volume_snapshot_digest']!==null
                && !self::hex($pair['database_volume_snapshot_digest']))
            || $pair['same_checkpoint_proven']!==false
            || $pair['provider_restore_tested']!==false){
            return Outcome::refuse('VA03_R5_INVENTORY_NOT_RESTORE_EVIDENCE');
        }
        $keys=$input['credential_readiness'];
        if(!is_array($keys)
            || !Vocabulary::exactKeys($keys,[
                'p11_active_version','age_recovery_identity_separate',
                'age_decrypt_restored','p11_rewrap_verified',
                'sql_account_password_rotated','backup_access_rotated',
                'secret_material_in_manifest',
            ])
            || $keys['p11_active_version']
                !==$input['manifest']['keys']['p11_active_version']
            || $keys['age_recovery_identity_separate']!==true
            || $keys['age_decrypt_restored']!==false
            || $keys['p11_rewrap_verified']!==false
            || $keys['sql_account_password_rotated']!==false
            || $keys['backup_access_rotated']!==false
            || $keys['secret_material_in_manifest']!==false){
            return Outcome::refuse('VA03_RECOVERY_CREDENTIAL_CUTOVERS_NOT_PROVEN');
        }
        $levels=$input['recovery_evidence'];
        if(!is_array($levels)||!array_is_list($levels)
            || count($levels)!==count(self::LEVELS)){
            return Outcome::refuse('VA03_RECOVERY_LEVELS_INCOMPLETE');
        }
        foreach(self::LEVELS as $i=>$level){
            $v=$levels[$i];
            if(!is_array($v)
                || !Vocabulary::exactKeys($v,[
                    'level','status','logical_restore_digest',
                    'physical_restore_digest','independent_target_verified',
                    'private_bytes_verified',
                ])
                || $v['level']!==$level
                || $v['status']!=='NOT_PERFORMED'
                || $v['logical_restore_digest']!==null
                || $v['physical_restore_digest']!==null
                || $v['independent_target_verified']!==false
                || $v['private_bytes_verified']!==false){
                return Outcome::refuse('VA03_RESTORE_EVIDENCE_CANNOT_BE_INVENTED');
            }
        }
        $v=[
            'version'=>self::VERSION,
            'status'=>'NO_RESTORE_NOT_HOST_VERIFIED',
            'manifest_digest'=>$input['manifest']['manifest_digest'],
            'scope'=>$input['manifest']['scope'],
            'source_release_commit'=>$input['source_release_commit'],
            'source_tree_sha'=>$input['source_tree_sha'],
            'observed_at_utc'=>$input['observed_at_utc'],
            'candidate_schema_count'=>$input['manifest']['schema_family_count'],
            'private_artifact_count'=>$input['manifest']['private_artifact_count'],
            'maintenance_fixture_quiescent'=>$quiesced,
            'r5_inventory_status'=>$pair['status'],
            'r5_pair_consistency_proven'=>false,
            'host_logical_restore_proved'=>false,
            'host_physical_restore_proved'=>false,
            'existing_live_recovery_proved'=>false,
            'isolated_candidate_recovery_proved'=>false,
            'populated_postmigration_recovery_proved'=>false,
            'age_identity_escrow_proved'=>false,
            'p11_rewrap_proved'=>false,
            'sql_user_password_rotation_proved'=>false,
            'backup_access_rotation_proved'=>false,
            'new_writer_admission_granted'=>false,
            'restore_executed'=>false,
            'restore_authorized'=>false,
            'main_or_railway_modified'=>false,
            'customer_visibility_authorized'=>false,
            'reason_codes'=>[
                'R5_INVENTORY_ONLY_NO_PAIRED_RESTORE',
                'P10_INDEPENDENT_RESTORE_REQUIRED',
                'P11_SQL_AND_BACKUP_ACCESS_CUTOVERS_SEPARATE',
                'CANDIDATE_SCHEMAS_NOT_RUNTIME_MIGRATED',
                'PRIVATE_M05_BYTES_NOT_HOST_RECOVERED',
            ],
        ];
        if(!$quiesced)$v['reason_codes'][]='MAINTENANCE_OR_WRITER_STANDDOWN_UNVERIFIED';
        sort($v['reason_codes'],SORT_STRING);
        $v['preflight_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $candidate,array $input):Outcome
    {
        $r=self::inspect($input);
        if(!$r->isOk()||!is_string($candidate['preflight_digest']??null)
            || $candidate!==$r->value()){
            return Outcome::refuse('VA03_PREFLIGHT_REHASH_OR_RESTORE_GRANT_FORGED');
        }
        return Outcome::ok([
            'candidate_metadata_checked'=>true,
            'host_restore_proved'=>false,
            'restore_authorized'=>false,
        ]);
    }

    /** Every runtime attempt remains held until separately authorized P10/P11 owners. */
    public static function requestRestore(array $claims=[]):Outcome
    {
        return Outcome::refuse('VA03_RESTORE_REQUIRES_SEPARATE_P10_P11_OWNER_APPROVAL');
    }

    private static function hex(mixed $value):bool
    {
        return is_string($value)&&preg_match(self::HEX,$value)===1;
    }
}
