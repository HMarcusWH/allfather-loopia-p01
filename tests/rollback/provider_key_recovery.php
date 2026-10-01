<?php

declare(strict_types=1);

/**
 * P10/P11 recovery cross-check.
 *
 * P10's native dump contains provider_credentials rows but no application master key. This test
 * models the row state a pre-rewrap P10 restore produces under the SAME P11 release: v1 ciphertext
 * must open with retained v1 recovery material and must refuse under a v2-only ring.
 */

use Allfather\Loopia\Core\{Ids};
use Allfather\Loopia\Provider\{CredentialRotation,CredentialStore,KeyRing};
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'p11-recovery');
$role = Seed::role(
    $db,
    $clock,
    'p11-recovery-' . substr(Ids::new(), 0, 8),
    ['tenant.administer'],
);
Seed::membership(
    $db,
    $clock,
    $scope['tenant_id'],
    $scope['workspace_id'],
    $scope['operator_id'],
    $role['role_id'],
);
$scope += ['actor_type' => 'OPERATOR', 'actor_id' => $scope['operator_id']];

$key1 = base64_encode(random_bytes(32));
$key2 = base64_encode(random_bytes(32));
$v1 = KeyRing::fromEnv(Env::fromArray(['APP_MASTER_KEY' => $key1]));
$dualV2 = KeyRing::fromEnv(Env::fromArray([
    'APP_MASTER_KEY_VERSIONS' => '1,2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V1' => $key1,
    'APP_MASTER_KEY_V2' => $key2,
]));
$v2Only = KeyRing::fromEnv(Env::fromArray([
    'APP_MASTER_KEY_VERSIONS' => '2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V2' => $key2,
]));

$storeV1 = new CredentialStore($db, $clock, $v1);
$saved = $storeV1->save($scope, 'synthetic-recovery-provider-key', 'Recovery fixture');
Assert::true($saved->isOk(), 'pre-rewrap P11 release stores a v1 credential');
$credentialId = $saved->value()['provider_credential_id'];

// These are the exact encrypted row bytes a pre-rewrap P10 native dump would restore.
$preRewrapRow = $db->one(
    'SELECT ciphertext,nonce_hex,auth_tag_hex,key_version,credential_digest
     FROM provider_credentials WHERE provider_credential_id=?',
    [$credentialId],
);
Assert::same(1, (int) $preRewrapRow['key_version'], 'backup-side row is version 1');

$rotation = new CredentialRotation($db, $clock, $dualV2);
$plan = $rotation->plan($scope);
Assert::true($plan->isOk(), 'live database can plan v1 -> active v2 rewrap');
$applied = $rotation->apply($scope, $plan->value()['plan_digest']);
Assert::true($applied->isOk(), 'live database rewraps after the pre-rewrap backup exists');

$liveV2Row = $db->one(
    'SELECT ciphertext,nonce_hex,auth_tag_hex,key_version,credential_digest
     FROM provider_credentials WHERE provider_credential_id=?',
    [$credentialId],
);
Assert::same(2, (int) $liveV2Row['key_version'], 'live row now uses v2');
Assert::false(
    hash_equals($preRewrapRow['credential_digest'], $liveV2Row['credential_digest']),
    'master-key rewrap changes the keyed digest',
);

// Simulate the row state after restoring the PRE-rewrap P10 dump into its disposable target.
// Same tenant/credential identities are restored by a database restore; only the encrypted row bytes
// are reset here because this test exercises key recovery, not P10's separate native import proof.
$db->run(
    'UPDATE provider_credentials
     SET ciphertext=?,nonce_hex=?,auth_tag_hex=?,key_version=?,credential_digest=?
     WHERE provider_credential_id=?',
    [
        $preRewrapRow['ciphertext'],
        $preRewrapRow['nonce_hex'],
        $preRewrapRow['auth_tag_hex'],
        $preRewrapRow['key_version'],
        $preRewrapRow['credential_digest'],
        $credentialId,
    ],
);

$restored = $db->one(
    'SELECT ciphertext,nonce_hex,auth_tag_hex,key_version,credential_digest
     FROM provider_credentials WHERE provider_credential_id=?',
    [$credentialId],
);
Assert::same(1, (int) $restored['key_version'], 'restored pre-rewrap row still declares v1');

$withRecovery = $dualV2->open(
    1,
    $restored['ciphertext'],
    $restored['nonce_hex'],
    $restored['auth_tag_hex'],
    $scope['tenant_id'],
    'OPENAI',
);
Assert::true($withRecovery->isOk(), 'retained v1 recovery material opens the restored v1 row');
Assert::same('synthetic-recovery-provider-key', $withRecovery->value(), 'recovery yields the original provider secret');

$withoutRecovery = $v2Only->open(
    1,
    $restored['ciphertext'],
    $restored['nonce_hex'],
    $restored['auth_tag_hex'],
    $scope['tenant_id'],
    'OPENAI',
);
Assert::false($withoutRecovery->isOk(), 'v2-only ring cannot open a restored v1 backup row');
Assert::same('CREDENTIAL_KEY_VERSION_UNAVAILABLE', $withoutRecovery->reasonCode(), 'missing historical key fails by exact version, not by try-all fallback');

// Return the test database to the current live-v2 state before the file exits.
$db->run(
    'UPDATE provider_credentials
     SET ciphertext=?,nonce_hex=?,auth_tag_hex=?,key_version=?,credential_digest=?
     WHERE provider_credential_id=?',
    [
        $liveV2Row['ciphertext'],
        $liveV2Row['nonce_hex'],
        $liveV2Row['auth_tag_hex'],
        $liveV2Row['key_version'],
        $liveV2Row['credential_digest'],
        $credentialId,
    ],
);
