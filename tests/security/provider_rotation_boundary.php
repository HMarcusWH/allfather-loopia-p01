<?php

declare(strict_types=1);

/**
 * Structural P11 safety boundary.
 *
 * Rotation code is allowed to change encrypted provider rows and append its own receipt. It is not
 * allowed to choose arbitrary target keys, try keys until one works, mint/suspend execution
 * authority, write governed repository files or move a secret onto argv/stdin/output.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$keyRing = (string) file_get_contents($root . '/app/Provider/KeyRing.php');
$store = (string) file_get_contents($root . '/app/Provider/CredentialStore.php');
$rotation = (string) file_get_contents($root . '/app/Provider/CredentialRotation.php');
$grantStore = (string) file_get_contents($root . '/app/Workflow/ExecutionGrantStore.php');
$cli = (string) file_get_contents($root . '/ops/provider/rotate-master-key.php');
$migration = (string) file_get_contents($root . '/database/migrations/240_provider_rotation_events.sql');
$env = (string) file_get_contents($root . '/app/Support/Env.php');

Assert::contains('APP_MASTER_KEY_VERSIONS', $keyRing, 'KeyRing owns the versioned configuration grammar');
Assert::contains('APP_MASTER_KEY_ACTIVE_VERSION', $keyRing, 'KeyRing owns the one active write version');
Assert::contains('CREDENTIAL_KEY_VERSION_UNAVAILABLE', $keyRing, 'unknown row version has an exact fail-closed refusal');
Assert::false(
    str_contains($keyRing, 'foreach ($this->boxes') && str_contains($keyRing, '->open('),
    'KeyRing does not iterate through keys trying decryption until one happens to work',
);

Assert::contains('SELECT provider,ciphertext,nonce_hex,auth_tag_hex,key_version,credential_digest', $store, 'dispatch reads exact version and stored keyed digest');
Assert::contains('PROVIDER_CREDENTIAL_DIGEST_MISMATCH', $store, 'successful decrypt is still bound to the stored credential digest');
Assert::contains('WHERE tenant_id=?', $store, 'credential administration stays tenant scoped');
Assert::false(
    str_contains($store, 'monitor_mission_configs WHERE tenant_id=? AND workspace_id=?'),
    'credential describe no longer understates bindings by scanning only one workspace',
);

Assert::contains("WHERE tenant_id=? AND key_version<>?", $rotation, 'rotation selects every old-version row in the tenant');
Assert::false(str_contains($rotation, "status='ACTIVE'"), 'rewrap is not restricted to ACTIVE rows');
Assert::contains("'provider' => (string) \$row['provider']", $rotation, 'plan binds the row provider');
Assert::contains('$provider = (string) $row[\'provider\'];', $rotation, 'rewrap uses the stored provider as AAD');
Assert::contains('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', $rotation, 'rotation takes the shared tenant mutex');
Assert::contains("(\$grant['event_kind'] ?? null) !== 'ADMITTED'", $rotation, 'rotation checks latest admitted lifecycle state');
Assert::contains("\$now >= (string) \$grant['valid_until']", $rotation, 'expired admitted history does not block rotation');
Assert::contains('PROVIDER_ROTATION_EXECUTION_AUTHORITY_ACTIVE', $rotation, 'execution-capable grants block the batch');
Assert::contains('provider_credential_rotation_events', $rotation, 'rotation appends only its dedicated receipt family');
Assert::false(str_contains($rotation, "event_kind' => 'SUSPENDED'"), 'rotation never suspends execution grants automatically');
Assert::false(str_contains($rotation, "event_kind' => 'ADMITTED'"), 'rotation never admits execution grants automatically');
Assert::false(str_contains($rotation, 'OwnerDecisionRegistry'), 'rotation has no owner-decision minting path');
Assert::false(str_contains($rotation, 'CurlHttpsTransport'), 'rotation opens no source network path');
Assert::false(str_contains($rotation, 'OpenAIResponsesTransport'), 'rotation opens no provider network path');
Assert::false(str_contains($rotation, 'deployment-evidence.json'), 'rotation cannot write the governed evidence registry');
Assert::false(str_contains($rotation, 'upstream.lock.json'), 'rotation cannot write the authority lock');

Assert::contains('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', $grantStore, 'grant admission uses the same tenant mutex as credential mutation');
Assert::contains('$this->normalize($scope, $current[\'definition\'])', $grantStore, 'admission revalidates current credential identity while the mutex is held');

Assert::false(str_contains($cli, 'secret-stdin'), 'rotation CLI never accepts a key/secret on stdin');
Assert::false(str_contains($cli, 'STDIN'), 'rotation CLI reads no secret bytes from stdin');
Assert::false(str_contains($cli, 'to-version'), 'operator cannot choose an arbitrary rewrap target version');
Assert::contains('KeyRing::fromEnv', $cli, 'rotation target is the configured active keyring version');
Assert::contains('--plan-digest', $cli, 'apply requires the exact inspected plan digest');

Assert::contains('BEFORE UPDATE ON provider_credential_rotation_events', $migration, 'rotation receipts reject UPDATE');
Assert::contains('BEFORE DELETE ON provider_credential_rotation_events', $migration, 'rotation receipts reject DELETE');
foreach (['plaintext', 'master_key', 'secret_value', 'provider_secret'] as $forbiddenColumn) {
    Assert::false(
        preg_match('/^\s*' . preg_quote($forbiddenColumn, '/') . '\s+/mi', $migration) === 1,
        "rotation receipt has no {$forbiddenColumn} column",
    );
}

Assert::contains('keysWithPrefix', $env, 'Env exposes key names for closed versioned configuration');
Assert::false(str_contains($env, 'allValues'), 'Env still has no raw all-secret-values accessor');

// Production composition has one KeyRing path; direct APP_MASTER_KEY SecretBox construction is only
// allowed in tests/fixtures and KeyRing's legacy adapter.
foreach ([
    'public_html/index.php',
    'ops/evaluation/monitor.php',
    'ops/provider/credential.php',
    'ops/deploy/preflight.php',
    'app/Workflow/MonitorFactory.php',
] as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    Assert::false(
        str_contains($source, "SecretBox::fromMasterKey(\$env->require('APP_MASTER_KEY'))")
        || str_contains($source, "SecretBox::fromMasterKey(\$config['env']->require('APP_MASTER_KEY'))"),
        "{$path} does not bypass the shared KeyRing configuration",
    );
}
