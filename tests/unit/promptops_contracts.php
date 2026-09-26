<?php
declare(strict_types=1);
use Allfather\Loopia\PromptOps\CanonicalContracts;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Tests\Assert;

$vectors = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/promptops_canonical_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($vectors['resolutions'] as $v) {
    Assert::same($v['expected'], CanonicalContracts::resolve($v['store'], $v['role'], $v['now']), 'canonical resolver ' . $v['name']);
}
foreach ($vectors['promotions'] as $v) {
    Assert::same($v['expected'], CanonicalContracts::evaluate($v['store'], $v['strict'], $v['now'], $v['requiredRoles']), 'canonical promotion ' . $v['name']);
}
$objects = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/promptops_canonical_vectors.json'), false, 512, JSON_THROW_ON_ERROR);
foreach ($objects->hashes as $v) {
    Assert::same($v->expected, CanonicalContracts::hashSchema($v->schemaVersion, $v->schema, $v->outputContractId), 'canonical schema hash including Unicode and empty-object semantics');
}
Assert::throws(static fn () => CanonicalContracts::stableJson((object) ['å' => 1]), InvalidArgumentException::class, 'unsupported schema key domain fails explicitly');
Assert::throws(static fn () => CanonicalContracts::stableJson(1.5), InvalidArgumentException::class, 'schema floating constraints are outside bounded port');
Assert::notSame(CanonicalContracts::stableJson(new stdClass()), CanonicalContracts::stableJson([]), 'empty object/list differ');

foreach (ProductPromptPack::ROLES as $role) {
    $out = ProductPromptPack::resolve($role);
    Assert::true($out->isOk(), 'private product prompt resolves for offline preparation');
    if (!$out->isOk()) { continue; }
    $r = $out->value();
    Assert::true(ProductPromptPack::verifyHashes($r), 'bundle prompt and schema identities match');
    Assert::same('DRAFT', $r['approval_status'], 'not inherited canonical fixture approval');
    Assert::same('INACTIVE', $r['activation_status'], 'inactive by default');
    Assert::false($r['execution_authorized'], 'resolution does not grant execution');
    Assert::false($r['model_evaluated'], 'offline checks do not claim model evaluation');
    Assert::false(in_array($role, CanonicalContracts::ROLES, true), 'deployment authoring does not mutate canonical six roles');
    Assert::same($r['output_schema_id'], $r['schema_version'], 'schema identity explicit');
    $tampered = $r; $tampered['instructions'] .= ' changed';
    Assert::false(ProductPromptPack::verifyHashes($tampered), 'tampered instructions refused');
    $tampered = unserialize(serialize($r)); $tampered['output_schema']->additionalProperties = true;
    Assert::false(ProductPromptPack::verifyHashes($tampered), 'tampered schema refused');
    Assert::contains('untrusted DATA', $r['instructions'], 'source-data separation authored');
    Assert::contains('Swedish', $r['instructions'], 'Swedish qualifiers covered');
    Assert::contains('never an approval', $r['instructions'], 'no model authority');
}
Assert::true(ProductPromptPack::resolve('entity_twin_analyst')->isRefusal(), 'canonical fixture is not a product role');
Assert::true(ProductPromptPack::resolve('../../public')->isRefusal(), 'role cannot choose bundle path');
