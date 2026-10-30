<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Core\{Clock,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P04\{
    AttentionReadPort,InternalAttention,InternalAttentionStore,InternalDigest,
};
use Allfather\Loopia\Tests\{Assert,Aq02MockAttention,Aq02FixtureAttentionOwner};

$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
Assert::same('AQ02_U07_U09_RUNTIME_OWNER_NOT_ADOPTED',
    InternalAttentionStore::forRuntime($db,new Clock(),[
        'owner_allow'=>true,'send_allowed'=>true,
    ])->reasonCode(),'caller-supplied owner claim cannot install attention runtime');
if(!defined('ALLFATHER_AQ02_DISPOSABLE_SCHEMA_LOADED')){
    Assert::same('AQ02_FAMILY340_RUNTIME_NOT_ADOPTED',
        InternalAttentionStore::forDisposableTest($db,new Clock())->reasonCode(),
        'without family340 disposable marker there is no store constructor');
}
$mock=Aq02MockAttention::input();
Assert::true($mock->isOk(),'synthetic scope-bound proof inputs exist');
if(!$mock->isOk())return;
if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
    define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
}
$rogue=new class implements AttentionReadPort {
    public function inspectForAttention(
        array $source,array $scope,string $role,string $redactionDigest,
        string $stage,
    ):Outcome {
        return Outcome::ok(['self_asserted_M10_ALLOW'=>true]);
    }
};
Assert::same('AQ02_NATIVE_ATTENTION_OWNER_NOT_ADOPTED',
    InternalAttention::composeFixture($mock->value(),$rogue)->reasonCode(),
    'an arbitrary caller owner implementation cannot mint a fixture read');

$ok=InternalAttention::composeFixture(
    $mock->value(),new Aq02FixtureAttentionOwner());
Assert::true($ok->isOk(),'fixed owner fixture tests the typed boundary');
if(!$ok->isOk())return;
foreach([
    'source_rights_authenticated','m10_reliance_authenticated',
    'role_entitlement_authenticated','real_human_choice_authenticated',
    'sent','scheduler_action_authorized','p04_distribution_released',
    'm16_visibility_activated','customer_visibility_authorized',
    'external_delivery_authorized',
] as $field){
    Assert::same(false,$ok->value()[$field],'no actual grant from synthetic '.$field);
}
foreach([
    'app/Product/P04/AttentionPolicy.php',
    'app/Product/P04/AttentionReadPort.php',
    'app/Product/P04/InternalAttention.php',
    'app/Product/P04/InternalDigest.php',
    'app/Product/P04/InternalAttentionStore.php',
] as $path){
    $src=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', 'new SourceCaptureStage(',
        'new SchedulerRunner(', 'mail(', '->send(',
        'releaseRoleDistribution(', 'releaseP01(', 'new DecisionLedger(',
        'DELETE FROM', 'unlink(',
    ] as $needle){
        Assert::false(str_contains($src,$needle),
            'candidate source cannot send/destroy/create real release: '.$path.' / '.$needle);
    }
}
