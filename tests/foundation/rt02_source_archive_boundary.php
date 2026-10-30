<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BuildFreezeInspector;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$target=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='RT-02'));
Assert::same(1,count($target),'one RT02 package');
Assert::same('BUILT_INERT',$target[0]['build_status'],
    'final source candidate is distinct from live authority');
foreach(['native_permission','external_effects','live_migration',
         'customer_visibility','production_activation'] as $flag){
    Assert::same(false,$target[0][$flag],
        'final source freeze cannot grant '.$flag);
}
Assert::same('rt02.source-freeze-inventory.v1',BuildFreezeInspector::VERSION,
    'freeze inspector is a versioned source-only contract');
foreach([
    'docs/LONGITUDINAL_RELEASE_ACCEPTANCE.md',
    'docs/BUILD_FREEZE_MANIFEST.json',
    'docs/BUILD_FIRST_IMPLEMENTATION_RECEIPT.md',
    'docs/BUILD_LICENSE_SBOM_EVIDENCE.json',
    'docs/longitudinal/build-first/RT02_COMPLETION_RECEIPT.md',
    'ops/history/shadow-report.php',
    'ops/history/package-source.sh',
    'ops/road-test/export-history-review-evidence.php',
    'tests/fixtures/rt02_source_freeze_examples.json',
] as $path){
    Assert::true(is_file($root.'/'.$path),
        'required final source artifact exists: '.$path);
}
$manifest=json_decode((string)file_get_contents(
    $root.'/docs/BUILD_FREEZE_MANIFEST.json'),true,64,JSON_THROW_ON_ERROR);
Assert::same('CANDIDATE_SOURCE_FREEZE_NOT_CI_ATTESTED',
    $manifest['status'],'static manifest is not independent GitHub CI receipt');
Assert::same('V01_V11_NOT_RUN',$manifest['verification_phase'],
    'neither source CI nor a tar archive runs full verification');
Assert::same(false,$manifest['owner_authority_granted'],
    'no implicit U01–U10 inheritance');
Assert::same(false,$manifest['runtime_or_customer_released'],
    'no silent production/customer activation');
$allow=$manifest['artifact_allowlist'];
Assert::false(in_array('storage/evidence/',$allow,true),
    'private M05 artifacts are not packaged');
Assert::false(in_array('.env',$allow,true),
    'real runtime environment is excluded from archive allowlist');
foreach([
    'ops/history/shadow-report.php',
    'ops/road-test/export-history-review-evidence.php',
] as $script){
    $source=(string)file_get_contents($root.'/'.$script);
    Assert::contains("PHP_SAPI !== 'cli'",$source,
        'operator script refuses a web SAPI');
    Assert::true(strpos($source,"PHP_SAPI !== 'cli'")<
        strpos($source,'autoload.php'),
        'browser refusal precedes secret bootstrap');
}
$pack=(string)file_get_contents($root.'/ops/history/package-source.sh');
Assert::true(str_contains($pack,'git -C "$repo_root" archive'),
    'public source artifact is assembled from Git tracked objects');
Assert::true(str_contains($pack,'--untracked-files=no'),
    'source cannot be packaged from a dirty tracked checkout');
Assert::true(str_contains($pack,'OUTPUT_MUST_BE_OUTSIDE_REPOSITORY'),
    'archive cannot be written into served/repo tree');
Assert::true(str_contains($pack,'OUTPUT_PARENT_NOT_OWNER_ONLY'),
    'archive output directory must not be group/world readable');
Assert::false(is_file($root.'/database/migrations/390_rt02_source_freeze.sql'),
    'build freeze has no new SQL migration');
