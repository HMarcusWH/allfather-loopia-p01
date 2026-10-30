<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ops\{
    HistorySchemaPlan,HistorySchemaFixtureInstaller,RuntimeInvariantProbe,
};
use Allfather\Loopia\Workflow\HistoryReviewFactory;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'candidate schema source present');
if(!$plan->isOk())return;
$p=$plan->value();
Assert::same(false,$p['legacy_900_modified_or_rerun'],
    'legacy 900 byte hash is not rewritten by package');
Assert::same(false,$p['new_runtime_owners_enabled'],
    'no feature flags can enable new actors by default');
Assert::same(false,$p['customer_visibility_authorized'],
    'physical DDL is not M10/M16 release permission');
$bad=$p;$bad['new_runtime_owners_enabled']=true;
$copy=$bad;unset($copy['plan_digest']);
$bad['plan_digest']=Digest::canonicalDigest($copy);
Assert::same('RT01_PHYSICAL_SCHEMA_PLAN_STALE_OR_FORGED',
    HistorySchemaPlan::verify($root,$bad)->reasonCode(),
    'self-rehashed allow flag cannot change source-backed plan');
$fake=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
Assert::same('RT01_RUNTIME_OR_PLAN_NOT_DISPOSABLE',
    HistorySchemaFixtureInstaller::install($fake,$root,$p)->reasonCode(),
    'uninitialized/arbitrary DB handle refuses DDL before native call');
Assert::same('AN02_U03_U06_PROVIDER_AND_HUMAN_RUNTIME_NOT_ADOPTED',
    HistoryReviewFactory::forRuntime(['owner_adopted'=>true,'U03'=>'ALLOW'])->reasonCode(),
    'source factory cannot grant real provider/inference use');

foreach([
    'app/Ops/HistorySchemaPlan.php',
    'app/Ops/HistorySchemaFixtureInstaller.php',
    'ops/history/migration-plan.php',
] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', 'mail(', '->send(',
        'new DecisionLedger(', 'releaseP01(', 'ALTER USER',
        'DROP DATABASE', 'railway volume restore',
    ] as $forbidden){
        Assert::false(str_contains($source,$forbidden),
            'schema candidate cannot dispatch or escalate: '.$path.' / '.$forbidden);
    }
}
