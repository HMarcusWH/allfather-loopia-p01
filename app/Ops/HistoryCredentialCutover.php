<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * VA-03: P11 / SQL user / backup credential are distinct *dry-run* cutovers.
 * No shell, no SQL account mutation, no KeyRing rewrap, no secret echo.
 */
final class HistoryCredentialCutover
{
    public const VERSION='va03.separate-credential-cutovers.v1';
    public const STAGES=[
        'P11_VERSIONED_REWRAP',
        'MARIADB_INITIALIZED_SQL_ACCOUNT_ROTATION',
        'BACKUP_ACCESS_RESTRICTION_AND_ROTATION',
        'AGE_OFF_HOST_RECOVERY_IDENTITY_VERIFICATION',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';

    public static function inspect(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,[
            'source_key_version','target_key_version',
            'sql_principal_fingerprint','backup_access_fingerprint',
            'age_recipient_fingerprint','source_release_commit',
            'proposed_at_utc',
        ])
            || !Vocabulary::id($input['source_key_version']??null)
            || !Vocabulary::id($input['target_key_version']??null)
            || $input['source_key_version']===$input['target_key_version']
            || !Vocabulary::utc($input['proposed_at_utc']??null)
            || !is_string($input['source_release_commit'])
            || preg_match('/^[a-f0-9]{40}$/D',$input['source_release_commit'])!==1){
            return Outcome::refuse('VA03_CREDENTIAL_DRYRUN_IDENTITY_INVALID');
        }
        foreach(['sql_principal_fingerprint','backup_access_fingerprint',
                 'age_recipient_fingerprint'] as $key){
            if(!is_string($input[$key])||preg_match(self::HEX,$input[$key])!==1){
                return Outcome::refuse('VA03_CREDENTIAL_DRYRUN_FINGERPRINT_INVALID');
            }
        }
        $v=[
            'version'=>self::VERSION,
            'status'=>'DRY_RUN_OWNER_CUTOVERS_NOT_EXECUTED',
            'source_key_version'=>$input['source_key_version'],
            'target_key_version'=>$input['target_key_version'],
            'source_release_commit'=>$input['source_release_commit'],
            'proposed_at_utc'=>$input['proposed_at_utc'],
            'stages'=>array_map(
                static fn(string $stage):array=>[
                    'stage'=>$stage,
                    'disposition'=>'REQUIRES_SEPARATE_OPERATOR_APPROVAL',
                    'executed'=>false,
                    'verified'=>false,
                ],self::STAGES,
            ),
            'p11_ciphertext_rewrapped'=>false,
            'sql_account_password_changed'=>false,
            'backup_access_rotated'=>false,
            'off_host_age_identity_decrypt_proved'=>false,
            'env_variable_change_is_not_sql_rotation'=>true,
            'secret_value_included'=>false,
            'runtime_effects'=>false,
        ];
        $v['plan_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $candidate,array $originalInput):Outcome
    {
        $fresh=self::inspect($originalInput);
        if(!$fresh->isOk()
            || !is_string($candidate['plan_digest']??null)
            || $candidate!==$fresh->value()){
            return Outcome::refuse('VA03_CREDENTIAL_CUTOVER_FORGERY_REFUSED');
        }
        return Outcome::ok([
            'status'=>'FIXTURE_DRYRUN_ONLY',
            'sql_user_password_changed'=>false,
            'secrets_exposed'=>false,
        ]);
    }

    public static function execute(array $claims=[]):Outcome
    {
        return Outcome::refuse('VA03_P11_SQL_BACKUP_OPERATORS_NOT_ADMITTED');
    }
}
