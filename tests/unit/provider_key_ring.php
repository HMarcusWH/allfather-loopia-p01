<?php

declare(strict_types=1);

use Allfather\Loopia\Provider\{KeyRing,SecretBox};
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\Assert;

$key1 = base64_encode(random_bytes(32));
$key2 = base64_encode(random_bytes(32));
$tenant = '00000000-0000-4000-8000-000000000001';
$otherTenant = '00000000-0000-4000-8000-000000000002';

$fails = static function (array $values): ?string {
    try {
        KeyRing::fromEnv(Env::fromArray($values));

        return null;
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
};

// Legacy remains version 1.
$legacy = KeyRing::fromEnv(Env::fromArray(['APP_MASTER_KEY' => $key1]));
Assert::same(1, $legacy->activeVersion(), 'legacy APP_MASTER_KEY is version 1');
Assert::equalsList([1], $legacy->versions(), 'legacy mode exposes one key version');
Assert::same('legacy', $legacy->mode(), 'legacy mode is reported as metadata');

$legacyWithBlankTemplateFields = KeyRing::fromEnv(Env::fromArray([
    'APP_MASTER_KEY' => $key1,
    'APP_MASTER_KEY_VERSIONS' => '',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '',
]));
Assert::same(
    'legacy',
    $legacyWithBlankTemplateFields->mode(),
    'blank versioned placeholders beside APP_MASTER_KEY do not create an ambiguous mode',
);

$sealedLegacy = $legacy->seal('sk-example-legacy', $tenant, 'OPENAI');
Assert::same(1, $sealedLegacy['key_version'], 'legacy writes version 1');
$openedLegacy = $legacy->open(
    1,
    $sealedLegacy['ciphertext'],
    $sealedLegacy['nonce_hex'],
    $sealedLegacy['auth_tag_hex'],
    $tenant,
    'OPENAI',
);
Assert::true($openedLegacy->isOk(), 'legacy ciphertext opens under exact version 1');
Assert::same('sk-example-legacy', $openedLegacy->value(), 'legacy plaintext round-trips');
$fingerprintLegacy = $legacy->fingerprint(1, 'sk-example-legacy');
Assert::true($fingerprintLegacy->isOk(), 'legacy fingerprint resolves');
Assert::same($sealedLegacy['digest'], $fingerprintLegacy->value(), 'stored digest is the keyed fingerprint');

// Versioned ring reads old and writes only the configured active version.
$versioned = KeyRing::fromEnv(Env::fromArray([
    'APP_MASTER_KEY_VERSIONS' => '1,2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V1' => $key1,
    'APP_MASTER_KEY_V2' => $key2,
]));
Assert::same(2, $versioned->activeVersion(), 'versioned ring has one explicit active writer');
Assert::equalsList([1, 2], $versioned->versions(), 'versioned ring exposes the closed declared set');
Assert::same('versioned', $versioned->mode(), 'versioned mode is reported as metadata');

$oldBox = SecretBox::fromMasterKey($key1, 1);
$oldSealed = $oldBox->seal('sk-example-old', $tenant, 'OPENAI');
$oldOpened = $versioned->open(
    1,
    $oldSealed['ciphertext'],
    $oldSealed['nonce_hex'],
    $oldSealed['auth_tag_hex'],
    $tenant,
    'OPENAI',
);
Assert::true($oldOpened->isOk(), 'old version remains decryptable while its key is retained');
Assert::same('sk-example-old', $oldOpened->value(), 'old version plaintext is preserved');

$newSealed = $versioned->seal('sk-example-new', $tenant, 'OPENAI');
Assert::same(2, $newSealed['key_version'], 'new writes use only the active version');
Assert::true(
    $versioned->open(
        2,
        $newSealed['ciphertext'],
        $newSealed['nonce_hex'],
        $newSealed['auth_tag_hex'],
        $tenant,
        'OPENAI',
    )->isOk(),
    'active-version ciphertext opens under that exact version',
);

// No try-all fallback.
$unknown = $versioned->open(
    3,
    $oldSealed['ciphertext'],
    $oldSealed['nonce_hex'],
    $oldSealed['auth_tag_hex'],
    $tenant,
    'OPENAI',
);
Assert::false($unknown->isOk(), 'unknown key version refuses');
Assert::same('CREDENTIAL_KEY_VERSION_UNAVAILABLE', $unknown->reasonCode(), 'unknown version is named exactly');

$wrongVersion = $versioned->open(
    2,
    $oldSealed['ciphertext'],
    $oldSealed['nonce_hex'],
    $oldSealed['auth_tag_hex'],
    $tenant,
    'OPENAI',
);
Assert::false($wrongVersion->isOk(), 'a row labelled with the wrong available version still refuses');
Assert::same('CREDENTIAL_DECRYPT_FAILED', $wrongVersion->reasonCode(), 'wrong version is not silently retried');

Assert::false(
    $versioned->open(
        1,
        $oldSealed['ciphertext'],
        $oldSealed['nonce_hex'],
        $oldSealed['auth_tag_hex'],
        $otherTenant,
        'OPENAI',
    )->isOk(),
    'tenant AAD mismatch refuses',
);
Assert::false(
    $versioned->open(
        1,
        $oldSealed['ciphertext'],
        $oldSealed['nonce_hex'],
        $oldSealed['auth_tag_hex'],
        $tenant,
        'ANTHROPIC',
    )->isOk(),
    'provider AAD mismatch refuses',
);

Assert::false(
    $versioned->open(
        1,
        base64_encode('tampered ciphertext'),
        $oldSealed['nonce_hex'],
        $oldSealed['auth_tag_hex'],
        $tenant,
        'OPENAI',
    )->isOk(),
    'ciphertext tamper refuses',
);
Assert::false(
    $versioned->open(
        1,
        $oldSealed['ciphertext'],
        str_repeat('00', 12),
        $oldSealed['auth_tag_hex'],
        $tenant,
        'OPENAI',
    )->isOk(),
    'nonce tamper refuses',
);
Assert::false(
    $versioned->open(
        1,
        $oldSealed['ciphertext'],
        $oldSealed['nonce_hex'],
        str_repeat('00', 16),
        $tenant,
        'OPENAI',
    )->isOk(),
    'authentication-tag tamper refuses',
);

// Closed configuration grammar.
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY' => $key1,
        'APP_MASTER_KEY_VERSIONS' => '1,2',
        'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
        'APP_MASTER_KEY_V1' => $key1,
        'APP_MASTER_KEY_V2' => $key2,
    ]), 'ambiguous'),
    'legacy and versioned modes cannot coexist',
);
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY_VERSIONS' => '1,2',
        'APP_MASTER_KEY_V1' => $key1,
        'APP_MASTER_KEY_V2' => $key2,
    ]), 'ACTIVE_VERSION'),
    'versioned mode requires an active version',
);
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY_VERSIONS' => '1,1',
        'APP_MASTER_KEY_ACTIVE_VERSION' => '1',
        'APP_MASTER_KEY_V1' => $key1,
    ]), 'duplicate'),
    'duplicate declared versions refuse',
);
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY_VERSIONS' => '1,2',
        'APP_MASTER_KEY_ACTIVE_VERSION' => '3',
        'APP_MASTER_KEY_V1' => $key1,
        'APP_MASTER_KEY_V2' => $key2,
    ]), 'not declared'),
    'active version must belong to the closed declared set',
);
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY_VERSIONS' => '1,2',
        'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
        'APP_MASTER_KEY_V1' => $key1,
        'APP_MASTER_KEY_V2' => $key2,
        'APP_MASTER_KEY_V3' => base64_encode(random_bytes(32)),
    ]), 'undeclared'),
    'stray APP_MASTER_KEY_V<n> refuses instead of becoming an accidental recovery key',
);
Assert::true(
    str_contains((string) $fails([
        'APP_MASTER_KEY_VERSIONS' => '1,2',
        'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
        'APP_MASTER_KEY_V1' => $key1,
        'APP_MASTER_KEY_V2' => base64_encode(random_bytes(31)),
    ]), '32 bytes'),
    'malformed versioned key material refuses at configuration time',
);
Assert::true(
    $fails([]) !== null,
    'missing master-key configuration refuses when a KeyRing is requested',
);

try {
    SecretBox::fromMasterKey($key1, 0);
    Assert::fail('SecretBox accepted a non-positive key version');
} catch (RuntimeException) {
    Assert::pass();
}

// Env exposes names, not a raw secret-value enumeration surface.
$env = Env::fromArray([
    'APP_MASTER_KEY_VERSIONS' => '1,2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V1' => $key1,
    'APP_MASTER_KEY_V2' => $key2,
]);
Assert::equalsList(
    ['APP_MASTER_KEY_ACTIVE_VERSION', 'APP_MASTER_KEY_V1', 'APP_MASTER_KEY_V2', 'APP_MASTER_KEY_VERSIONS'],
    $env->keysWithPrefix('APP_MASTER_KEY_'),
    'P11 can inspect only key names under the master-key prefix',
);
