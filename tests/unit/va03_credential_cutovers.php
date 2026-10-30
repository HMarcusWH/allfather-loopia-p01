<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Ops\HistoryCredentialCutover;
use Allfather\Loopia\Tests\Assert;

$input=[
    'source_key_version'=>'v1_fixture',
    'target_key_version'=>'v2_fixture',
    'sql_principal_fingerprint'=>hash('sha256','VA03-FIXTURE-SQL-ACCOUNT'),
    'backup_access_fingerprint'=>hash('sha256','VA03-FIXTURE-BACKUP-ACCESS'),
    'age_recipient_fingerprint'=>hash('sha256','VA03-FIXTURE-AGE-RECIPIENT'),
    'source_release_commit'=>str_repeat('a',40),
    'proposed_at_utc'=>'2026-10-30T12:04:00Z',
];
$out=HistoryCredentialCutover::inspect($input);
Assert::true($out->isOk(),'four separately governed credential cutovers have an explicit dry-run plan');
if(!$out->isOk())return;
$v=$out->value();
Assert::true(HistoryCredentialCutover::verify($v,$input)->isOk(),
    'dry run can be independently reconstructed without secret material');
Assert::same(4,count($v['stages']),'P11, initialized SQL user, backup access and age off-host recovery remain distinct');
Assert::same('DRY_RUN_OWNER_CUTOVERS_NOT_EXECUTED',$v['status'],'planning does not rotate any key');
Assert::same(false,$v['sql_account_password_changed'],
    'changing an environment password is not an SQL account rotation');
Assert::same(false,$v['p11_ciphertext_rewrapped'],
    'P11 version selection does not prove every retained ciphertext rewrapped');
Assert::same(false,$v['backup_access_rotated'],
    'Railway or backup-access rotation remains separately authorized');
Assert::same(false,$v['off_host_age_identity_decrypt_proved'],
    'public age recipient does not prove recovery identity exists');
Assert::same(false,$v['secret_value_included'],
    'this plan stores fingerprints, never secret bytes');
foreach($v['stages'] as $stage){
    Assert::same(false,$stage['executed'],'none of the dry-run owner steps executed');
    Assert::same(false,$stage['verified'],'none of the owner cutovers is verified');
}
$changed=$v;
$changed['sql_account_password_changed']=true;
$unsigned=$changed;unset($unsigned['plan_digest']);
$changed['plan_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA03_CREDENTIAL_CUTOVER_FORGERY_REFUSED',
    HistoryCredentialCutover::verify($changed,$input)->reasonCode(),
    'rehashed SQL rotation claim cannot pass reconstruction');
$bad=$input;$bad['target_key_version']=$bad['source_key_version'];
Assert::same('VA03_CREDENTIAL_DRYRUN_IDENTITY_INVALID',
    HistoryCredentialCutover::inspect($bad)->reasonCode(),
    'same source/target P11 key version is not a rotation plan');
Assert::same('VA03_P11_SQL_BACKUP_OPERATORS_NOT_ADMITTED',
    HistoryCredentialCutover::execute(['operator_approved'=>true])->reasonCode(),
    'no runtime P11, SQL-user or backup-access rotation entrypoint');
