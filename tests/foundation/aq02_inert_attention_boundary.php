<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Product\P04\{
    AttentionPolicy,InternalAttention,InternalDigest,InternalAttentionStore,
};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$packages=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='AQ-02'));
Assert::same(1,count($packages),'AQ02 one package in frozen capability register');
Assert::same('BUILT_INERT',$packages[0]['build_status'],
    'candidate source is not a deployed internal delivery program');
foreach([
    'native_permission','customer_visibility','production_activation',
    'live_migration','external_effects',
] as $flag){
    Assert::same(false,$packages[0][$flag],
        'AQ02 cannot acquire '.$flag);
}
Assert::same('aq02.inert-attention-policy.v1',AttentionPolicy::VERSION,
    'named attention policy has a distinct candidate version');
Assert::same('aq02.internal-attention-candidate.v1',InternalAttention::VERSION,
    'a recommendation is distinct from P04 release');
Assert::same('aq02.inert-internal-digest.v1',InternalDigest::VERSION,
    'digest is a bounded private candidate');
Assert::same('aq02.disposable-internal-attention-store.v1',
    InternalAttentionStore::VERSION,
    'append-only 340 is not native M10 or a notification ledger');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/340_internal_attention_CANDIDATE.sql'),
    'disposable attention SQL exists outside runtime');
Assert::false(is_file($root.'/database/migrations/340_internal_attention.sql'),
    'no family340 migration auto-applies in staging');
Assert::false(is_file($root.'/ops/history/attention.php'),
    'no native internal alert/notification command admitted');
Assert::true(is_file($root.'/tests/support/Aq02FixtureAttentionOwner.php'),
    'fixture lives outside production autoload');
$release=(string)file_get_contents($root.'/app/Product/P04/Release.php');
Assert::false(str_contains($release,'InternalAttention'),
    'existing owner-native P04 release code was not rewritten for candidate alerting');
