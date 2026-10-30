<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/Va03MockRecovery.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Ops\{
    BackupManifest,HistoryRecoveryManifest,HistoryRecoveryPreflight,
};
use Allfather\Loopia\Tests\{Assert,Va03MockRecovery};

$root=dirname(__DIR__,2);
$r=Va03MockRecovery::manifest($root);
Assert::true($r->isOk(),'complete family260/265/270–350 fixture manifest exists');
if(!$r->isOk())return;
$m=$r->value();
Assert::true(HistoryRecoveryManifest::verify($m)->isOk(),
    'exact bounded candidate manifest self-verifies');
Assert::same(12,$m['schema_family_count'],
    'all twelve candidate families including 260,265,340,345,350 indexed');
Assert::same(4,$m['private_artifact_count'],
    'M05, analyst artifact, attempt-before-cursor and request log references bound');
Assert::same('FULL_CANDIDATE_INDEX_NOT_RESTORE_PROOF',$m['status'],
    'source manifest cannot assert a real restore');
Assert::same(false,$m['original_p10_package_authenticated'],
    'P10 v1 backup remains an independently verified owner');
Assert::same(false,$m['logical_independent_restore_proved'],
    'source fixture is not logical restore qualification');
Assert::same(false,$m['physical_independent_restore_proved'],
    'source fixture is not physical restoration');
Assert::true(BackupManifest::inspectUnadoptedHistoryIndex($m,$root)->isOk(),
    'P10 keeps v1 semantics and checks the complete offline candidate file set');
Assert::same('PROPOSAL_FILE_HASHES_VERIFIED_OFFLINE',
    HistoryRecoveryManifest::verifyProposalFiles($m,$root)->value()['status'],
    'actual proposal SQL hashes match exact local sources without installing schemas');
Assert::same(false,$m['p10']['p10_v1_package_proved'],
    'placeholder cannot masquerade as a P10 package proof');
$changed=$m;$changed['restore_authorized']=true;
$unsigned=$changed;unset($unsigned['manifest_digest']);
$changed['manifest_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA03_RECOVERY_MANIFEST_REHASH_OR_AUTHORITY_FORGED',
    HistoryRecoveryManifest::verify($changed)->reasonCode(),
    'rehashing restore=true cannot forge operator permission');
$changed=$m;
$changed['families'][0]['source_sha256']=str_repeat('a',64);
$unsigned=$changed;unset($unsigned['manifest_digest']);
$changed['manifest_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA03_PROPOSED_SCHEMA_BYTES_CHANGED',
    HistoryRecoveryManifest::verifyProposalFiles($changed,$root)->reasonCode(),
    'modified candidate proposal bytes are refused even when self-hashed');
$changed=$m;
$changed['source']['knowledge_cutoff_utc']='2026-11-01T00:00:00Z';
$unsigned=$changed;unset($unsigned['manifest_digest']);
$changed['manifest_digest']=Digest::canonicalDigest($unsigned);
Assert::same('VA03_RECOVERY_MANIFEST_REHASH_OR_AUTHORITY_FORGED',
    HistoryRecoveryManifest::verify($changed)->reasonCode(),
    'cutoff after manifest creation cannot be backdated');

$p=Va03MockRecovery::preflight($m);
$pf=HistoryRecoveryPreflight::inspect($p);
Assert::true($pf->isOk(),'read-only metadata preflight can inspect fixture inputs');
if(!$pf->isOk())return;
$v=$pf->value();
Assert::true(HistoryRecoveryPreflight::verify($v,$p)->isOk(),
    'preflight is reconstructed from its full metadata input');
Assert::same('NO_RESTORE_NOT_HOST_VERIFIED',$v['status'],
    'even complete family inventory does not restore production');
Assert::same(false,$v['r5_pair_consistency_proven'],
    'two listed provider snapshot references are not a paired snapshot');
Assert::same(false,$v['populated_postmigration_recovery_proved'],
    'pre-migration snapshot cannot prove populated migrated restore');
Assert::same(false,$v['p11_rewrap_proved'],'P11 is a separate credential cutover');
Assert::same(false,$v['sql_user_password_rotation_proved'],
    'environment password substitution is not SQL ALTER USER');

$bad=$p;$bad['r5_backup_pair']['provider_restore_tested']=true;
Assert::same('VA03_R5_INVENTORY_NOT_RESTORE_EVIDENCE',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'provider inventory cannot claim actual volume restoration');
$bad=$p;$bad['credential_readiness']['sql_account_password_rotated']=true;
Assert::same('VA03_RECOVERY_CREDENTIAL_CUTOVERS_NOT_PROVEN',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'a caller flag cannot claim SQL account rotation');
$bad=$p;$bad['recovery_evidence'][2]['status']='PASS';
Assert::same('VA03_RESTORE_EVIDENCE_CANNOT_BE_INVENTED',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'unobserved populated postmigration recovery cannot become PASS');
$bad=$v;$bad['restore_executed']=true;
$copy=$bad;unset($copy['preflight_digest']);
$bad['preflight_digest']=Digest::canonicalDigest($copy);
Assert::same('VA03_PREFLIGHT_REHASH_OR_RESTORE_GRANT_FORGED',
    HistoryRecoveryPreflight::verify($bad,$p)->reasonCode(),
    'rehash of false host authority does not confer restoration');
Assert::same('VA03_RESTORE_REQUIRES_SEPARATE_P10_P11_OWNER_APPROVAL',
    HistoryRecoveryPreflight::requestRestore(['owner'=>'APPROVED'])->reasonCode(),
    'restore permission is never minted by candidate source');
