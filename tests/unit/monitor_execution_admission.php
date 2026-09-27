<?php
declare(strict_types=1);

/**
 * The parts of admission that need no database: the owner decision document contract and the
 * closed model policy identity.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\OwnerDecisionRegistry;

$dir = sys_get_temp_dir() . '/allfather-decision-unit-' . Ids::new();
mkdir($dir, 0700, true);
$registry = new OwnerDecisionRegistry($dir);
$write = static function (string $name, array|string $document) use ($dir): string {
    $bytes = is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR);
    file_put_contents($dir . '/' . $name, $bytes);
    return hash('sha256', $bytes);
};
$good = [
    'schema_version' => '1.0', 'kind' => 'OWNER_EXECUTION_DECISION', 'decision_id' => 'UNIT-1', 'decided_by' => 'Unit owner',
    'decided_at' => '2026-01-01T00:00:00Z', 'canonical_authority_ref' => 'unit', 'tenant_id' => 't', 'workspace_id' => 'w',
    'purposes' => ['MODEL_EVALUATION'], 'authorizes' => ['prompt_approvals' => [['purpose' => 'MODEL_EVALUATION', 'role' => 'R', 'prompt_sha256' => 'p', 'schema_sha256' => 's', 'model_policy_digest' => 'm']], 'execution_grants' => [str_repeat('a', 64)]],
    'disclosure_scope' => 'd', 'retention_scope' => 'r',
];
$sha = $write('good.json', $good);
$resolved = $registry->resolve('docs/decisions/good.json', $sha);
Assert::true($resolved->isOk(), 'a well-formed decision resolves by its exact bytes');
Assert::true(OwnerDecisionRegistry::authorizesGrant($resolved->value(), 't', 'w', 'MODEL_EVALUATION', str_repeat('a', 64)), 'it authorizes exactly the digest it lists');
Assert::false(OwnerDecisionRegistry::authorizesGrant($resolved->value(), 't', 'w', 'MANUAL_MONITOR', str_repeat('a', 64)), 'not for a purpose it does not name');
Assert::false(OwnerDecisionRegistry::authorizesGrant($resolved->value(), 't', 'other', 'MODEL_EVALUATION', str_repeat('a', 64)), 'not in another workspace');
Assert::false(OwnerDecisionRegistry::authorizesGrant($resolved->value(), 't', 'w', 'MODEL_EVALUATION', str_repeat('b', 64)), 'not for another digest');
Assert::true(OwnerDecisionRegistry::authorizesPrompt($resolved->value(), 't', 'w', ['purpose' => 'MODEL_EVALUATION', 'role' => 'R', 'prompt_sha256' => 'p', 'schema_sha256' => 's', 'model_policy_digest' => 'm']), 'it authorizes exactly the prompt identity it lists');
Assert::false(OwnerDecisionRegistry::authorizesPrompt($resolved->value(), 't', 'w', ['purpose' => 'MODEL_EVALUATION', 'role' => 'R', 'prompt_sha256' => 'p2', 'schema_sha256' => 's', 'model_policy_digest' => 'm']), 'a different prompt hash is not authorized');
Assert::same('OWNER_DECISION_CHANGED', $registry->resolve('docs/decisions/good.json', str_repeat('0', 64))->reasonCode(), 'other bytes than the reviewed ones refuse');
foreach (['good.json', 'docs/decisions/../good.json', 'docs/decisions/sub/good.json', '/docs/decisions/good.json', 'docs/decisions/.hidden.json'] as $ref) {
    Assert::same('OWNER_DECISION_REFERENCE_INVALID', $registry->resolve($ref, $sha)->reasonCode(), "reference shape refused: {$ref}");
}
Assert::same('OWNER_DECISION_NOT_FOUND', $registry->resolve('docs/decisions/missing.json', $sha)->reasonCode(), 'a missing decision refuses');
$outside = sys_get_temp_dir() . '/allfather-decision-outside-' . Ids::new() . '.json';
file_put_contents($outside, json_encode($good));
symlink($outside, $dir . '/linked.json');
Assert::same('OWNER_DECISION_NOT_FOUND', $registry->resolve('docs/decisions/linked.json', hash_file('sha256', $outside))->reasonCode(), 'a symlink out of the directory is not a decision');
foreach ([
    'extra key' => $good + ['authorizes_everything' => true],
    'wrong kind' => ['kind' => 'OWNER_GO'] + $good,
    'unknown purpose' => ['purposes' => ['PRODUCTION_LAUNCH']] + $good,
    'empty purposes' => ['purposes' => []] + $good,
    'no canonical reference' => ['canonical_authority_ref' => ' '] + $good,
    'bad digest' => ['authorizes' => ['prompt_approvals' => [], 'execution_grants' => ['*']]] + $good,
] as $case => $document) {
    $s = $write('bad.json', $document);
    Assert::same('OWNER_DECISION_INVALID', $registry->resolve('docs/decisions/bad.json', $s)->reasonCode(), "malformed decision refused: {$case}");
}
Assert::same('OWNER_DECISION_INVALID', $registry->resolve('docs/decisions/bad.json', $write('bad.json', 'not json'))->reasonCode(), 'non-JSON refused');

Assert::same(64, strlen(ModelPolicy::digest()), 'the model policy has one identity');
Assert::same('gpt-4.1-nano', ModelPolicy::definition()['model'], 'the bounded adapter still supports one model');
Assert::same([], ModelPolicy::definition()['tools'], 'no provider tools');
Assert::same(0, ModelPolicy::definition()['automatic_retries'], 'no automatic retries');
Assert::same(false, ModelPolicy::definition()['store'], 'store=false is requested (provider retention is reviewed separately)');
