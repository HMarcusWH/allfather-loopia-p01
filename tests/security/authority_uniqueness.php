<?php

declare(strict_types=1);

/**
 * The partial UNIQUE indexes a race could otherwise use to widen authority.
 *
 * WHAT THIS IS ABOUT. Canonical declares 25 partial UNIQUE indexes. Migration 010 translated all of
 * them the same way — document the rule, install a non-unique lookup index, enforce the predicate
 * in the application — and migration 140 established why that is not always enough: application
 * enforcement of a uniqueness predicate is a read followed by a write, and two callers can both
 * pass the read before either does the write. "At most one" was then a claim about load, not a fact
 * about the database.
 *
 * Migration 160 enforces the six where the answer to ONE question is yes: if two rows exist where
 * the predicate permits one, can a caller end up with MORE AUTHORITY than the governed state
 * grants? A contract IS an agent's permitted action classes. A visibility profile IS an audience
 * ceiling. A live subscription IS what a tenant's surfaces may show. A policy set IS the rules an
 * admission is judged by. Two of any of them means the answer depends on which row is read first,
 * and nothing picks deliberately.
 *
 * EACH INDEX IS PROVED THREE WAYS, because a unique index has three failure modes and testing one
 * proves little:
 *
 *   1. a second row inside the predicate collides;
 *   2. rows OUTSIDE the predicate coexist freely — an index that rejected those would break the
 *      supersession history every one of these tables keeps;
 *   3. an UPDATE that moves a row INTO the predicate collides too. This is the half a hand-rolled
 *      application check almost always forgets: it guards the insert and not the reactivation.
 *
 * The rows are written straight into storage on purpose. The application paths refuse most of this
 * now, so a fixture built through the modules would prove the application check and leave the
 * database claim untested — which is the exact gap this migration exists to close.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$now = $clock->now();

/** Does this write collide? Returns the driver error code, or 0 when it succeeded. */
$collides = static function (callable $write) use ($db): int {
    try {
        $write($db);

        return 0;
    } catch (\PDOException $error) {
        return (int) ($error->errorInfo[1] ?? -1);
    }
};

// ---------------------------------------------------------------- capability_contracts

Assert::case('one ACTIVE capability contract per Valkyrie');

$valkyrieId = (new \Allfather\Loopia\Substrate\M03\Registry($db, $clock))->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Uniqueness Probe',
    'COMPETITION_MONITOR_VALKYRIE', 'Probe agent.', $scope['operator_id'], 'ACTIVE'
);
$contractRow = static fn (string $status, int $version): array => [
    'capability_contract_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'valkyrie_id' => $valkyrieId,
    'contract_version' => $version,
    'role_class' => 'COMPETITION_MONITOR_VALKYRIE',
    'purpose_summary' => 'probe',
    'allowed_action_classes' => json_encode(['OBSERVE'], JSON_THROW_ON_ERROR),
    'maximum_autonomy_statement' => 'Bounded.',
    'evidence_obligation_summary' => 'Cites evidence.',
    'escalation_obligation_summary' => 'Refuses on unknown authority.',
    'human_approval_or_execution_authority' => 0,
    'status' => $status,
    'valid_from' => $now,
    'created_at' => $now,
];

$firstContract = $contractRow('ACTIVE', 1);
$db->insert('capability_contracts', $firstContract);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('capability_contracts', $contractRow('ACTIVE', 2))),
    'a second ACTIVE contract for one Valkyrie is rejected by the database — its permissions cannot fork',
);
$supersededContract = $contractRow('SUPERSEDED', 3);
$revokedContract = $contractRow('REVOKED', 4);
Assert::same(
    0,
    $collides(static function ($d) use ($supersededContract, $revokedContract): void {
        $d->insert('capability_contracts', $supersededContract);
        $d->insert('capability_contracts', $revokedContract);
    }),
    'while retired contracts coexist freely — an authority history is not a collision',
);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->run(
        "UPDATE capability_contracts SET status = 'ACTIVE' WHERE capability_contract_id = ?",
        [$supersededContract['capability_contract_id']]
    )),
    'and REACTIVATING a retired contract into an occupied seat is rejected too — the half a check forgets',
);
// The application surfaces the same fact as a reason code rather than a driver error.
$m03 = new \Allfather\Loopia\Substrate\M03\Registry($db, $clock);
$refused = $m03->issueContract(
    $valkyrieId, $scope['tenant_id'], $scope['workspace_id'], 'probe.second',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE']
);
Assert::true($refused->isRefusal(), 'and M03 refuses to issue a second ACTIVE contract at all');
Assert::same(
    'VALKYRIE_HAS_ACTIVE_CONTRACT',
    $refused->reasonCode(),
    "by upstream's reason code — a caller gets an answer, not a 1062",
);

// ---------------------------------------------------------------- the M16 audience ceiling

Assert::case('one ACTIVE visibility profile and access rule per audience');

$profileRow = static fn (string $status, string $version): array => [
    'output_visibility_profile_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'audience_class' => 'TENANT_INTERNAL',
    'profile_version' => $version,
    'activation_permitted' => 0,
    'required_permission_key' => 'briefing.read',
    'status' => $status,
    'created_at' => $now,
    'updated_at' => $now,
];
// The tenant seed already installed this tenant's M16 profiles and rules. They are retired first so
// the probe measures the index rather than the fixture.
$db->run(
    "UPDATE output_visibility_profiles SET status = 'SUPERSEDED' WHERE tenant_id = ?",
    [$scope['tenant_id']]
);
$db->run(
    "UPDATE output_access_rules SET status = 'SUPERSEDED' WHERE tenant_id = ?",
    [$scope['tenant_id']]
);
$firstProfile = $profileRow('ACTIVE', 'v1');
$db->insert('output_visibility_profiles', $firstProfile);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('output_visibility_profiles', $profileRow('ACTIVE', 'v2'))),
    'two ACTIVE profiles for one audience class are two ceilings — the wider one would win on read order',
);
$retiredProfile = $profileRow('SUPERSEDED', 'v0');
Assert::same(
    0,
    $collides(static fn ($d) => $d->insert('output_visibility_profiles', $retiredProfile)),
    'a superseded profile coexists',
);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->run(
        "UPDATE output_visibility_profiles SET status = 'ACTIVE' WHERE output_visibility_profile_id = ?",
        [$retiredProfile['output_visibility_profile_id']]
    )),
    'and reactivating it into an occupied audience is rejected',
);

$ruleRow = static fn (string $status, string $version): array => [
    'output_access_rule_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'audience_class' => 'TENANT_INTERNAL',
    'artifact_kind' => 'BATTLECARD_BRIEFING',
    'rule_version' => $version,
    'required_permission_key' => 'briefing.read',
    'status' => $status,
    'created_at' => $now,
    'updated_at' => $now,
];
$db->insert('output_access_rules', $ruleRow('ACTIVE', 'v1'));
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('output_access_rules', $ruleRow('ACTIVE', 'v2'))),
    'and one ACTIVE access rule per (tenant, audience, artifact kind)',
);

// ---------------------------------------------------------------- the entitlement gate

Assert::case('one live subscription per tenant, one ACTIVE definition per tier');

$tierRow = static fn (string $status, string $version): array => [
    'subscription_tier_id' => Ids::new(),
    'schema_version' => '1',
    'tier_key' => 'uniqueness_probe_tier',
    'tier_version' => $version,
    'display_name' => 'Probe tier',
    'billing_period' => 'MONTHLY',
    'status' => $status,
    'created_at' => $now,
];
$firstTier = $tierRow('ACTIVE', 'v1');
$db->insert('subscription_tiers', $firstTier);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('subscription_tiers', $tierRow('ACTIVE', 'v2'))),
    'two ACTIVE definitions of one tier are two answers to "what does this product grant?"',
);
Assert::same(
    0,
    $collides(static fn ($d) => $d->insert('subscription_tiers', $tierRow('RETIRED', 'v0'))),
    'a retired tier definition coexists as history',
);

$subRow = static fn (string $status): array => [
    'tenant_subscription_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'subscription_tier_id' => $firstTier['subscription_tier_id'],
    'status' => $status,
    'started_at' => $now,
];
// Whatever the tenant seed already gave this tenant is retired first, so the probe measures the
// index rather than the fixture.
$db->run("UPDATE tenant_subscriptions SET status = 'CANCELLED' WHERE tenant_id = ?", [$scope['tenant_id']]);
$db->insert('tenant_subscriptions', $subRow('ACTIVE'));
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('tenant_subscriptions', $subRow('TRIAL'))),
    'and TRIAL collides with ACTIVE — canonical\'s predicate is "live", not "ACTIVE"',
);
Assert::same(
    0,
    $collides(static fn ($d) => $d->insert('tenant_subscriptions', $subRow('EXPIRED'))),
    'while an expired subscription is history and collides with nothing',
);

// ---------------------------------------------------------------- the admission policy

Assert::case('one ACTIVE arbiter policy set per scope');

$policyRow = static fn (string $status, string $version): array => [
    'arbiter_policy_set_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'policy_key' => 'UNIQUENESS_PROBE_POLICY',
    'policy_version' => $version,
    'display_name' => 'Probe policy',
    'purpose_summary' => 'Probe',
    'applicable_proposal_types' => json_encode(['COMPANY_SIGNAL_BRIEF'], JSON_THROW_ON_ERROR),
    'applicable_requested_action_classes' => json_encode(['RELEASE_INTELLIGENCE'], JSON_THROW_ON_ERROR),
    'available_verdicts' => json_encode(['ALLOW_INFORMATIONAL_RELEASE', 'HOLD'], JSON_THROW_ON_ERROR),
    'default_fail_closed_verdict' => 'HOLD',
    'requires_decision_ledger_for_material_outcome' => 1,
    'does_not_approve_or_execute' => 1,
    'status' => $status,
    'created_at' => $now,
    'activated_at' => $now,
];
$db->insert('arbiter_policy_sets', $policyRow('ACTIVE', 'v1'));
Assert::same(
    1062,
    $collides(static fn ($d) => $d->insert('arbiter_policy_sets', $policyRow('ACTIVE', 'v2'))),
    'two ACTIVE policy sets for one scope make ALLOW or BLOCK depend on row order',
);
$retiredPolicy = $policyRow('SUPERSEDED', 'v0');
Assert::same(
    0,
    $collides(static fn ($d) => $d->insert('arbiter_policy_sets', $retiredPolicy)),
    'a superseded policy set coexists',
);
Assert::same(
    1062,
    $collides(static fn ($d) => $d->run(
        "UPDATE arbiter_policy_sets SET status = 'ACTIVE' WHERE arbiter_policy_set_id = ?",
        [$retiredPolicy['arbiter_policy_set_id']]
    )),
    'and reactivating it into an occupied scope is rejected',
);

// THE ONE PLACE THIS INDEX IS NARROWER THAN CANONICAL'S, asserted rather than described.
//
// Canonical keys this by COALESCE(tenant_id, '*') so that platform-wide sets, whose scope columns
// are NULL, still collide with each other. MariaDB refuses a string literal inside an indexable
// generated column (error 1901, every spelling), so the sentinel is gone and NULL scopes are not
// defended. That is sound ONLY while nothing writes a NULL-scoped policy set — so that is what is
// checked, by reading the writers rather than by trusting the migration's comment.
$repoRoot = dirname(__DIR__, 2);
$policyWriters = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/app')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string) file_get_contents((string) $file), "insert('arbiter_policy_sets'")) {
        $policyWriters[] = str_replace($repoRoot . '/', '', (string) $file);
    }
}
Assert::equalsList(
    ['app/Substrate/M08/PolicySets.php'],
    $policyWriters,
    'exactly one runtime file writes an arbiter policy set',
);
$seedScope = (new ReflectionMethod(\Allfather\Loopia\Substrate\M08\PolicySets::class, 'seed'))->getParameters();
foreach ([1 => 'tenantId', 2 => 'workspaceId'] as $position => $name) {
    Assert::same($name, $seedScope[$position]->getName(), "and its scope parameter {$position} is {$name}");
    Assert::false(
        $seedScope[$position]->allowsNull(),
        "which is non-nullable — a NULL-scoped policy set, which this index would not catch, cannot be written",
    );
}
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM arbiter_policy_sets WHERE tenant_id IS NULL OR workspace_id IS NULL'
    ),
    'and none exists',
);

// ---------------------------------------------------------------- provenance, P02 edition

Assert::case('a P02 status-history row names an actor, never its own subject');

$twins = new \Allfather\Loopia\Product\P02\EntityTwins($db, $clock);
$twinId = $twins->registerTwin(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Provenance Probe AB'
)->value()['entity_twin_id'];

$twinHistory = (array) $db->one(
    'SELECT initiated_by_actor_type, initiated_by_actor_id FROM entity_twin_status_history
     WHERE entity_twin_id = ?',
    [$twinId]
);
// Upstream's appendTwinStatusHistory hardcodes SYSTEM and defaults the id to the string "system".
// The port used the TWIN'S OWN id, so the row answered "who put this here?" with the thing that was
// put there — the same fault the M10 writer column had, and in a file whose two sibling release
// paths already did it correctly.
Assert::same('SYSTEM', (string) $twinHistory['initiated_by_actor_type'], 'the actor TYPE is upstream\'s SYSTEM');
Assert::same('system', (string) $twinHistory['initiated_by_actor_id'], 'and the default actor ID is "system"');
Assert::notSame($twinId, (string) $twinHistory['initiated_by_actor_id'], 'never the twin itself');

$relationships = new \Allfather\Loopia\Product\P02\RelationshipTwins($db, $clock);
$otherTwinId = $twins->registerTwin(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Provenance Probe Two AB'
)->value()['entity_twin_id'];
$edgeId = $relationships->createEdge(
    $scope['tenant_id'], $scope['workspace_id'], $twinId, $otherTwinId, 'COMPETES_WITH'
)->value()['relationship_edge_id'];
$edgeHistory = (array) $db->one(
    'SELECT initiated_by_actor_type, initiated_by_actor_id FROM entity_relationship_status_history
     WHERE relationship_edge_id = ?',
    [$edgeId]
);
Assert::same('SYSTEM', (string) $edgeHistory['initiated_by_actor_type'], 'the edge history says SYSTEM too');
Assert::same('system', (string) $edgeHistory['initiated_by_actor_id'], 'with the same default actor');
Assert::notSame($edgeId, (string) $edgeHistory['initiated_by_actor_id'], 'never the edge itself');

// A SUPPLIED actor is carried through unchanged, which is what makes the default a default rather
// than a hardcoded value wearing one's clothes.
$namedEdgeId = $relationships->createEdge(
    $scope['tenant_id'], $scope['workspace_id'], $otherTwinId, $twinId, 'COMPETES_WITH',
    'SOURCE_TO_TARGET', $scope['operator_id']
)->value()['relationship_edge_id'];
Assert::same(
    $scope['operator_id'],
    (string) $db->scalar(
        'SELECT initiated_by_actor_id FROM entity_relationship_status_history WHERE relationship_edge_id = ?',
        [$namedEdgeId]
    ),
    'and a supplied actor is recorded as itself',
);

// Database-wide: no status-history row in either table names its own subject.
foreach ([
    ['entity_twin_status_history', 'entity_twin_id'],
    ['entity_relationship_status_history', 'relationship_edge_id'],
] as [$table, $subject]) {
    Assert::same(
        0,
        (int) $db->scalar("SELECT COUNT(*) FROM {$table} WHERE initiated_by_actor_id = {$subject}"),
        "no row in {$table} claims its own subject put it there",
    );
}
