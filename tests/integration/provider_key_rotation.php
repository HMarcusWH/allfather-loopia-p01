<?php

declare(strict_types=1);

/**
 * P11 key rotation against real MariaDB.
 *
 * The tests use synthetic provider secrets only. They prove tenant-wide selection, lifecycle
 * preservation, exact-version decrypt, future-admitted blocking, stale plans, idempotent replay and
 * transaction rollback when a later row in one bounded batch fails integrity.
 */

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Provider\{CredentialRotation,CredentialStore,KeyRing};
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};

require_once __DIR__ . '/../support/ExecutionFixture.php';

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();

$key1 = base64_encode(random_bytes(32));
$key2 = base64_encode(random_bytes(32));
$ring1 = KeyRing::fromEnv(Env::fromArray(['APP_MASTER_KEY' => $key1]));
$ring2 = KeyRing::fromEnv(Env::fromArray([
    'APP_MASTER_KEY_VERSIONS' => '1,2',
    'APP_MASTER_KEY_ACTIVE_VERSION' => '2',
    'APP_MASTER_KEY_V1' => $key1,
    'APP_MASTER_KEY_V2' => $key2,
]));

$makeScope = static function (string $name) use ($db, $clock): array {
    $base = Seed::tenantWorkspace($db, $clock, $name);
    $role = Seed::role(
        $db,
        $clock,
        'p11-' . $name . '-' . substr(Ids::new(), 0, 8),
        ['tenant.administer', 'mission.activate'],
    );
    Seed::membership(
        $db,
        $clock,
        $base['tenant_id'],
        $base['workspace_id'],
        $base['operator_id'],
        $role['role_id'],
    );

    return $base + ['actor_type' => 'OPERATOR', 'actor_id' => $base['operator_id']];
};

$insertCredential = static function (
    array $scope,
    KeyRing $ring,
    string $id,
    string $provider,
    string $status,
    string $secret,
) use ($db, $clock): array {
    $sealed = $ring->seal($secret, $scope['tenant_id'], $provider);
    $db->insert('provider_credentials', [
        'provider_credential_id' => $id,
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'provider' => $provider,
        'label' => 'Synthetic ' . $provider,
        'ciphertext' => $sealed['ciphertext'],
        'nonce_hex' => $sealed['nonce_hex'],
        'auth_tag_hex' => $sealed['auth_tag_hex'],
        'key_version' => $sealed['key_version'],
        'credential_digest' => $sealed['digest'],
        'status' => $status,
        'created_at' => $clock->now(),
        'created_by_operator_id' => $scope['operator_id'],
        'rotated_at' => $status === 'ROTATED' ? $clock->now() : null,
        'revoked_at' => $status === 'REVOKED' ? $clock->now() : null,
    ]);

    return $sealed;
};

// -------------------------------------------------------------------------------- full lifecycle rewrap

$scope = $makeScope('rotation');
$storeV1 = new CredentialStore($db, $clock, $ring1);

$one = $storeV1->save($scope, 'synthetic-openai-one', 'One');
Assert::true($one->isOk(), 'first v1 provider credential saves');
$idOne = $one->value()['provider_credential_id'];
$oldDigestOne = $one->value()['credential_digest'];

$two = $storeV1->save($scope, 'synthetic-openai-two', 'Two', [$idOne]);
Assert::true($two->isOk(), 'second save explicitly rotates the first credential');
$idTwo = $two->value()['provider_credential_id'];
$oldDigestTwo = $two->value()['credential_digest'];

Assert::true($storeV1->revoke($scope, $idTwo)->isOk(), 'second credential can be revoked');

$three = $storeV1->save($scope, 'synthetic-openai-three', 'Three');
Assert::true($three->isOk(), 'third credential becomes the active v1 key');
$idThree = $three->value()['provider_credential_id'];
$oldDigestThree = $three->value()['credential_digest'];

$idAnthropic = Ids::new();
$anthropicSealed = $insertCredential(
    $scope,
    $ring1,
    $idAnthropic,
    'ANTHROPIC',
    'ROTATED',
    'synthetic-anthropic-old',
);

$beforeStatus = array_column(
    $db->all(
        'SELECT provider_credential_id,status,key_version,credential_digest,provider
         FROM provider_credentials WHERE tenant_id=? ORDER BY provider_credential_id',
        [$scope['tenant_id']],
    ),
    null,
    'provider_credential_id',
);

$rotation = new CredentialRotation($db, $clock, $ring2);
$status = $rotation->status($scope);
Assert::true($status->isOk(), 'rotation status is inspectable');
Assert::same(2, $status->value()['active_key_version'], 'status names the configured active writer');
Assert::same(4, $status->value()['pending_rewrap_count'], 'every old-version ciphertext row is pending');
Assert::false($status->value()['blocked_by_execution_authority'], 'history with no admitted grant does not block');

$plan = $rotation->plan($scope);
Assert::true($plan->isOk(), 'tenant-wide rewrap plan is produced');
Assert::same(4, $plan->value()['candidate_count'], 'the bounded plan includes ACTIVE, ROTATED and REVOKED rows');
Assert::same(['1' => 4], $plan->value()['from_version_counts'], 'all planned rows state their source version');
Assert::same(2, $plan->value()['target_key_version'], 'the plan target is the configured active version');
Assert::false($plan->value()['grants_execution'], 'a rotation plan grants no execution');

$applied = $rotation->apply($scope, $plan->value()['plan_digest']);
Assert::true($applied->isOk(), 'exact inspected plan applies');
Assert::same(4, $applied->value()['rotated_count'], 'all four selected rows rewrap atomically');
Assert::false($applied->value()['already_applied'], 'first application is not a replay');

$afterRows = $db->all(
    'SELECT provider_credential_id,status,key_version,credential_digest,provider
     FROM provider_credentials WHERE tenant_id=? ORDER BY provider_credential_id',
    [$scope['tenant_id']],
);
foreach ($afterRows as $row) {
    $id = (string) $row['provider_credential_id'];
    Assert::same(2, (int) $row['key_version'], "rewrap moves {$id} to version 2");
    Assert::same(
        $beforeStatus[$id]['status'],
        $row['status'],
        "rewrap preserves lifecycle status for {$id}",
    );
    Assert::same(
        $beforeStatus[$id]['provider'],
        $row['provider'],
        "rewrap preserves provider AAD identity for {$id}",
    );
    Assert::false(
        hash_equals($beforeStatus[$id]['credential_digest'], $row['credential_digest']),
        "rewrap changes the master-keyed credential digest for {$id}",
    );
}
Assert::same('ROTATED', $db->scalar('SELECT status FROM provider_credentials WHERE provider_credential_id=?', [$idOne]), 'rotated history stays rotated');
Assert::same('REVOKED', $db->scalar('SELECT status FROM provider_credentials WHERE provider_credential_id=?', [$idTwo]), 'revoked history stays revoked');
Assert::same('ACTIVE', $db->scalar('SELECT status FROM provider_credentials WHERE provider_credential_id=?', [$idThree]), 'active key stays active');

$storeV2 = new CredentialStore($db, $clock, $ring2);
$used = $storeV2->withCredential(
    $scope['tenant_id'],
    $idThree,
    static fn (string $secret): string => hash('sha256', $secret),
);
Assert::true($used->isOk(), 'the active credential opens after rewrap');
Assert::same(hash('sha256', 'synthetic-openai-three'), $used->value(), 'rewrap preserves provider-secret plaintext');

$anthropic = $db->one(
    'SELECT * FROM provider_credentials WHERE provider_credential_id=?',
    [$idAnthropic],
);
$anthropicOpened = $ring2->open(
    2,
    $anthropic['ciphertext'],
    $anthropic['nonce_hex'],
    $anthropic['auth_tag_hex'],
    $scope['tenant_id'],
    'ANTHROPIC',
);
Assert::true($anthropicOpened->isOk(), 'provider-generic rewrap preserves non-OpenAI AAD');
Assert::same('synthetic-anthropic-old', $anthropicOpened->value(), 'non-OpenAI plaintext survives rewrap');

Assert::same(
    4,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM provider_credential_rotation_events WHERE tenant_id=?',
        [$scope['tenant_id']],
    ),
    'one immutable rotation event is written per rewrapped row',
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(DISTINCT rotation_batch_id) FROM provider_credential_rotation_events WHERE tenant_id=?',
        [$scope['tenant_id']],
    ),
    'one bounded apply produces one batch identity',
);

$replayed = $rotation->apply($scope, $plan->value()['plan_digest']);
Assert::true($replayed->isOk(), 'replaying an already committed plan is idempotent success');
Assert::true($replayed->value()['already_applied'], 'replay says it reused the committed batch');
Assert::same(4, $replayed->value()['rotated_count'], 'replay returns the original complete batch');

$emptyPlan = $rotation->plan($scope);
Assert::same(0, $emptyPlan->value()['candidate_count'], 'a fresh plan after rewrap has nothing left to do');

// CredentialStore also verifies stored digest after decrypt.
$currentDigest = (string) $db->scalar(
    'SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=?',
    [$idThree],
);
$db->run(
    'UPDATE provider_credentials SET credential_digest=? WHERE provider_credential_id=?',
    [str_repeat('0', 64), $idThree],
);
$digestMismatch = $storeV2->withCredential($scope['tenant_id'], $idThree, static fn (string $secret): string => $secret);
Assert::same('PROVIDER_CREDENTIAL_DIGEST_MISMATCH', $digestMismatch->reasonCode(), 'stored digest mismatch refuses after authenticated decrypt');
$db->run(
    'UPDATE provider_credentials SET credential_digest=? WHERE provider_credential_id=?',
    [$currentDigest, $idThree],
);

$db->run('UPDATE provider_credentials SET key_version=99 WHERE provider_credential_id=?', [$idThree]);
$unknownVersion = $storeV2->withCredential($scope['tenant_id'], $idThree, static fn (string $secret): string => $secret);
Assert::same('PROVIDER_CREDENTIAL_KEY_VERSION_UNAVAILABLE', $unknownVersion->reasonCode(), 'CredentialStore resolves exactly the row version');
$db->run('UPDATE provider_credentials SET key_version=2 WHERE provider_credential_id=?', [$idThree]);

// Rotation receipts are append-only storage facts.
foreach ([
    "UPDATE provider_credential_rotation_events SET to_key_version=3 WHERE tenant_id=?",
    "DELETE FROM provider_credential_rotation_events WHERE tenant_id=?",
] as $sql) {
    try {
        $db->run($sql, [$scope['tenant_id']]);
        Assert::fail('rotation event ledger accepted mutation: ' . $sql);
    } catch (PDOException) {
        Assert::pass();
    }
}

// -------------------------------------------------------------------------------- tenant-wide future-admitted blocker

$blockedScope = $makeScope('blocked');
$blockedStore = new CredentialStore($db, $clock, $ring1);
$blockedCredential = $blockedStore->save($blockedScope, 'synthetic-blocked-key', 'Blocked')->value();
$blockedId = $blockedCredential['provider_credential_id'];
$blockedDigest = $blockedCredential['credential_digest'];

// Put the execution-capable grant in ANOTHER workspace of the same tenant to prove rotation does
// not scope its safety scan to the CLI workspace used for tenant.administer.
$otherWorkspace = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $otherWorkspace,
    'tenant_id' => $blockedScope['tenant_id'],
    'workspace_key' => 'p11-other-' . substr($otherWorkspace, 0, 8),
    'schema_version' => '1',
    'display_name' => 'P11 other workspace',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $clock->now(),
]);

$futureGrant = Ids::new();
$futureDefinition = [
    'credential_id' => $blockedId,
    'credential_digest' => $blockedDigest,
];
$db->insert('monitor_execution_grants', [
    'execution_grant_id' => $futureGrant,
    'tenant_id' => $blockedScope['tenant_id'],
    'workspace_id' => $otherWorkspace,
    'purpose' => 'MODEL_EVALUATION',
    'mission_id' => null,
    'definition_digest' => null,
    'evaluation_manifest_sha256' => str_repeat('a', 64),
    'principal_operator_id' => $blockedScope['operator_id'],
    'definition_json' => json_encode($futureDefinition, JSON_THROW_ON_ERROR),
    'grant_digest' => str_repeat('b', 64),
    'valid_from' => '2026-01-02T00:00:00.000Z',
    'valid_until' => '2026-01-05T00:00:00.000Z',
    'proposed_by_operator_id' => $blockedScope['operator_id'],
    'created_at' => $clock->now(),
]);
$db->insert('monitor_execution_grant_events', [
    'execution_grant_event_id' => Ids::new(),
    'execution_grant_id' => $futureGrant,
    'sequence_no' => 1,
    'event_kind' => 'ADMITTED',
    'owner_decision_ref' => 'docs/decisions/test-p11-future.json',
    'owner_decision_sha256' => str_repeat('c', 64),
    'reason_code' => 'TEST_P11_FUTURE',
    'recorded_by_operator_id' => $blockedScope['operator_id'],
    'recorded_at' => $clock->now(),
]);

// Expired admitted history names the same credential but must not prevent current recovery work.
$expiredGrant = Ids::new();
$db->insert('monitor_execution_grants', [
    'execution_grant_id' => $expiredGrant,
    'tenant_id' => $blockedScope['tenant_id'],
    'workspace_id' => $blockedScope['workspace_id'],
    'purpose' => 'MODEL_EVALUATION',
    'mission_id' => null,
    'definition_digest' => null,
    'evaluation_manifest_sha256' => str_repeat('d', 64),
    'principal_operator_id' => $blockedScope['operator_id'],
    'definition_json' => json_encode($futureDefinition, JSON_THROW_ON_ERROR),
    'grant_digest' => str_repeat('e', 64),
    'valid_from' => '2025-12-01T00:00:00.000Z',
    'valid_until' => '2025-12-31T23:59:59.999Z',
    'proposed_by_operator_id' => $blockedScope['operator_id'],
    'created_at' => '2025-12-01T00:00:00.000Z',
]);
$db->insert('monitor_execution_grant_events', [
    'execution_grant_event_id' => Ids::new(),
    'execution_grant_id' => $expiredGrant,
    'sequence_no' => 1,
    'event_kind' => 'ADMITTED',
    'owner_decision_ref' => 'docs/decisions/test-p11-expired.json',
    'owner_decision_sha256' => str_repeat('f', 64),
    'reason_code' => 'TEST_P11_EXPIRED',
    'recorded_by_operator_id' => $blockedScope['operator_id'],
    'recorded_at' => '2025-12-01T00:00:00.000Z',
]);

$blockedRotation = new CredentialRotation($db, $clock, $ring2);
$blockedPlan = $blockedRotation->plan($blockedScope);
Assert::true($blockedPlan->value()['blocked_by_execution_authority'], 'future-admitted authority blocks rotation');
Assert::equalsList([$futureGrant], $blockedPlan->value()['blocking_execution_grant_ids'], 'tenant-wide scan names only the still-live future grant');
Assert::same(
    'PROVIDER_ROTATION_EXECUTION_AUTHORITY_ACTIVE',
    $blockedRotation->apply($blockedScope, $blockedPlan->value()['plan_digest'])->reasonCode(),
    'apply refuses while current/future execution authority can wake without another owner act',
);
Assert::same(1, (int) $db->scalar('SELECT key_version FROM provider_credentials WHERE provider_credential_id=?', [$blockedId]), 'blocked rotation changed no ciphertext row');

$db->insert('monitor_execution_grant_events', [
    'execution_grant_event_id' => Ids::new(),
    'execution_grant_id' => $futureGrant,
    'sequence_no' => 2,
    'event_kind' => 'SUSPENDED',
    'owner_decision_ref' => null,
    'owner_decision_sha256' => null,
    'reason_code' => 'TEST_P11_STAND_DOWN',
    'recorded_by_operator_id' => $blockedScope['operator_id'],
    'recorded_at' => $clock->now(),
]);
$afterStandDown = $blockedRotation->apply($blockedScope, $blockedPlan->value()['plan_digest']);
Assert::true($afterStandDown->isOk(), 'the exact inspected plan may proceed after future authority is stood down');
Assert::same(2, (int) $db->scalar('SELECT key_version FROM provider_credentials WHERE provider_credential_id=?', [$blockedId]), 'stood-down credential rewraps');

// The immutable historical grant keeps its old digest; rotation never rewrites or re-admits it.
$historicalDefinition = json_decode(
    (string) $db->scalar('SELECT definition_json FROM monitor_execution_grants WHERE execution_grant_id=?', [$futureGrant]),
    true,
    16,
    JSON_THROW_ON_ERROR,
);
$newBlockedDigest = (string) $db->scalar(
    'SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=?',
    [$blockedId],
);
Assert::same($blockedDigest, $historicalDefinition['credential_digest'], 'historical grant retains the old reviewed credential digest');
Assert::false(hash_equals($historicalDefinition['credential_digest'], $newBlockedDigest), 'rewrap makes that immutable grant stale by design');

// -------------------------------------------------------------------------------- stale plan and transactional rollback

$staleScope = $makeScope('stale');
$staleStore = new CredentialStore($db, $clock, $ring1);
$staleCredential = $staleStore->save($staleScope, 'synthetic-stale', 'Stale')->value();
$staleRotation = new CredentialRotation($db, $clock, $ring2);
$stalePlan = $staleRotation->plan($staleScope)->value();
Assert::true($staleStore->revoke($staleScope, $staleCredential['provider_credential_id'])->isOk(), 'lifecycle can change after inspection');
Assert::same(
    'PROVIDER_ROTATION_PLAN_STALE',
    $staleRotation->apply($staleScope, $stalePlan['plan_digest'])->reasonCode(),
    'apply refuses when an inspected row changed before the tenant lock was acquired',
);

$atomicScope = $makeScope('atomic');
$goodId = '00000000-0000-4000-8000-000000000001';
$badId = '00000000-0000-4000-8000-000000000002';
$goodSealed = $insertCredential($atomicScope, $ring1, $goodId, 'OPENAI', 'ROTATED', 'synthetic-atomic-good');
$badSealed = $insertCredential($atomicScope, $ring1, $badId, 'OPENAI', 'REVOKED', 'synthetic-atomic-bad');
$db->run(
    'UPDATE provider_credentials SET credential_digest=? WHERE provider_credential_id=?',
    [str_repeat('9', 64), $badId],
);
$atomicRotation = new CredentialRotation($db, $clock, $ring2);
$atomicPlan = $atomicRotation->plan($atomicScope)->value();
$atomicApply = $atomicRotation->apply($atomicScope, $atomicPlan['plan_digest']);
Assert::same('PROVIDER_ROTATION_OLD_DIGEST_MISMATCH', $atomicApply->reasonCode(), 'integrity failure in a later row refuses the batch');
Assert::same(1, (int) $db->scalar('SELECT key_version FROM provider_credentials WHERE provider_credential_id=?', [$goodId]), 'earlier row update rolled back with the refused batch');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_credential_rotation_events WHERE tenant_id=?', [$atomicScope['tenant_id']]), 'refused batch retained no partial rotation receipts');

// -------------------------------------------------------------------------------- shared tenant mutex under real concurrency

$second = Harness::secondConnection();
if ($second === null) {
    Assert::skip('P11 mutex proof needs a second MariaDB connection');
} else {
    $second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

    $lockScope = $makeScope('mutex');
    $lockStore = new CredentialStore($db, $clock, $ring1);
    $lockCredential = $lockStore->save($lockScope, 'synthetic-mutex-key', 'Mutex')->value();
    $lockRotation = new CredentialRotation($second, $clock, $ring2);
    $lockPlan = (new CredentialRotation($db, $clock, $ring2))->plan($lockScope)->value();

    $db->pdo()->beginTransaction();
    $db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$lockScope['tenant_id']]);
    $blockedApply = $lockRotation->apply($lockScope, $lockPlan['plan_digest']);
    Assert::false($blockedApply->isOk(), 'a concurrent P11 apply cannot pass a held tenant credential mutex');
    Assert::same('PROVIDER_ROTATION_FAILED', $blockedApply->reasonCode(), 'lock timeout is a safe generic rotation failure');
    $db->pdo()->rollBack();

    $afterUnlock = $lockRotation->apply($lockScope, $lockPlan['plan_digest']);
    Assert::true($afterUnlock->isOk(), 'the same exact plan succeeds after the tenant mutex is released');
    Assert::same(
        2,
        (int) $db->scalar(
            'SELECT key_version FROM provider_credentials WHERE provider_credential_id=?',
            [$lockCredential['provider_credential_id']],
        ),
        'mutex-serialized rewrap reaches the active version',
    );

    $secondStore = new CredentialStore($second, $clock, $ring2);
    $db->pdo()->beginTransaction();
    $db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$lockScope['tenant_id']]);
    $blockedRevoke = $secondStore->revoke($lockScope, $lockCredential['provider_credential_id']);
    Assert::false($blockedRevoke->isOk(), 'credential revoke cannot pass a held tenant mutex either');
    Assert::same('PROVIDER_CREDENTIAL_REVOKE_FAILED', $blockedRevoke->reasonCode(), 'revoke lock timeout fails safely');
    $db->pdo()->rollBack();
    Assert::true(
        $secondStore->revoke($lockScope, $lockCredential['provider_credential_id'])->isOk(),
        'credential revoke succeeds once the shared tenant mutex is released',
    );

    // Admission takes the same tenant mutex and revalidates current credential identity under it.
    $raceFx = X::create($db, $clock);
    $raceApprovals = X::approveAll($raceFx, 'MANUAL_MONITOR');
    $raceDefinition = X::manualDefinition($db, $clock, $raceFx, $raceApprovals);
    $raceProposed = X::value($raceFx['grants']->propose($raceFx['scope'], $raceDefinition));
    $raceDecision = X::decision(
        $raceFx,
        ['MANUAL_MONITOR'],
        [],
        [$raceProposed['grant_digest']],
    );

    $evaluationEvidence = static function (array $facts, string $reference): \Allfather\Loopia\Core\Outcome {
        return preg_match('/^evaluation:(?:fixture|[a-f0-9-]{36})$/D', $reference) === 1
            ? \Allfather\Loopia\Core\Outcome::ok(['report_digest' => hash('sha256', $reference)])
            : \Allfather\Loopia\Core\Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED');
    };
    $secondApprovals = new \Allfather\Loopia\PromptOps\PromptApprovalStore(
        $second,
        $clock,
        $raceFx['decisions'],
        $evaluationEvidence,
    );
    $secondGrants = new \Allfather\Loopia\Workflow\ExecutionGrantStore(
        $second,
        $clock,
        $secondApprovals,
        $raceFx['decisions'],
    );

    $db->pdo()->beginTransaction();
    $db->one(
        'SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',
        [$raceFx['scope']['tenant_id']],
    );
    $blockedAdmission = $secondGrants->admit(
        $raceFx['scope'],
        $raceProposed['execution_grant_id'],
        $raceDecision['ref'],
        $raceDecision['sha256'],
    );
    Assert::false($blockedAdmission->isOk(), 'grant admission cannot slip past a held credential tenant mutex');
    $db->pdo()->rollBack();

    $admittedAfterUnlock = $secondGrants->admit(
        $raceFx['scope'],
        $raceProposed['execution_grant_id'],
        $raceDecision['ref'],
        $raceDecision['sha256'],
    );
    Assert::true($admittedAfterUnlock->isOk(), 'grant admission succeeds after the shared tenant mutex is released');
    Assert::same(
        'ADMITTED',
        $secondGrants->status($raceProposed['execution_grant_id']),
        'admission lifecycle event is appended only after the locked currentness recheck',
    );
}

