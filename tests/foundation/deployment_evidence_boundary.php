<?php

declare(strict_types=1);

/**
 * The deployment evidence boundary.
 *
 * `lock_invariants.php` used to hold all ten claim-boundary booleans with one rule: every one of
 * them must be false, forever. That was right while nothing had been deployed and wrong the moment
 * real staging work began, because it forced the same treatment on two different kinds of
 * statement — an owner's authority act, and an observation about the world.
 *
 * This file holds the second kind. Five operational states MAY become true, and each is true only
 * while `deployment-evidence.json` carries a record that supports it. The implication asserted here
 * runs in one direction on purpose:
 *
 *     claim asserted true  =>  evidence supports it
 *
 * and never the reverse. Recording a successful deployment does not flip a flag; a human still
 * edits the lock and somebody still reviews that diff. What cannot happen is the flag moving with
 * nothing behind it.
 *
 * The rest of this file is the validator's refusals, exercised one at a time. A schema that is
 * closed only in its docblock is a schema that is open, and the specific way this one would come
 * open is somebody adding a field to a record because it seemed useful — so an unknown field is
 * asserted to be refused rather than ignored.
 */

use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 512, JSON_THROW_ON_ERROR);

$expected = [
    'projection_id' => (string) $lock['projection_id'],
    'canonical_pin' => (string) $lock['projection_pinned_head'],
];

// ============================================================ the registry this repository carries

$registry = DeploymentEvidence::loadFile($root . '/deployment-evidence.json', $expected);
Assert::same([], $registry->problems(), 'the committed deployment evidence registry validates');
Assert::true($registry->isValid(), 'and is therefore usable to decide a claim');

// THE ONE-WAY IMPLICATION. This is the assertion the whole model exists for, and it is written
// against the lock as it actually is rather than against a list of expected values — so the day one
// of these legitimately becomes true, this test keeps working and starts checking something.
Assert::same(
    [],
    $registry->unsupportedClaims((array) $lock['claim_boundary']),
    'no evidence-backed claim is asserted true without a record supporting it',
);

// The evidence file is not in the served tree. `deployment_boundary.php` already pins the served
// directory to a closed set, so a copy inside it would fail there first; this says the rule out
// loud in the file that owns the registry.
Assert::false(
    is_file($root . '/public_html/deployment-evidence.json'),
    'the evidence registry is never inside the served directory',
);

// ============================================================ the two classes of claim are disjoint

$overlap = array_intersect(
    DeploymentEvidence::EVIDENCE_BACKED_CLAIMS,
    DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS,
);
Assert::same([], array_values($overlap), 'no claim is both an owner act and an evidence-backed state');
Assert::same(
    9,
    count(DeploymentEvidence::EVIDENCE_BACKED_CLAIMS) + count(DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS),
    'the two classes together cover the nine claim-boundary booleans that are not no_external_charge_executed',
);
foreach ([...DeploymentEvidence::EVIDENCE_BACKED_CLAIMS, ...DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS] as $claim) {
    Assert::true(
        array_key_exists($claim, (array) $lock['claim_boundary']),
        "the lock actually carries the claim this model classifies: {$claim}",
    );
}

// ============================================================ a registry builder for the refusals

$record = static function (array $overrides = []): array {
    return [
        'record_id' => 'REC-0001',
        'kind' => 'CONFORMANCE',
        'result' => 'PASS',
        'release_ref' => str_repeat('a', 40),
        'environment' => 'ci',
        'recorded_at' => '2026-09-11T00:00:00.000Z',
        'evidence_ref' => 'evidence/conformance-0001.json',
        'evidence_sha256' => str_repeat('b', 64),
        ...$overrides,
    ];
};
$registryJson = static function (array $records, array $overrides = []) use ($expected): string {
    return (string) json_encode([
        'schema_version' => DeploymentEvidence::SCHEMA_VERSION,
        'projection_id' => $expected['projection_id'],
        'canonical_pin' => $expected['canonical_pin'],
        'records' => $records,
        ...$overrides,
    ], JSON_THROW_ON_ERROR);
};
$parse = static fn (string $json): DeploymentEvidence => DeploymentEvidence::parse($json, $expected);

Assert::true($parse($registryJson([$record()]))->isValid(), 'a well-formed record validates');

// ============================================================ what the schema refuses

$refusals = [
    'unknown record field' => [$record(['surprise' => 'yes'])],
    'unknown kind' => [$record(['kind' => 'DEPLOYED'])],
    'unknown result' => [$record(['result' => 'OK'])],
    'unknown environment' => [$record(['environment' => 'loopia'])],
    'abbreviated release ref' => [$record(['release_ref' => '4a59d3e'])],
    'uppercase release ref' => [$record(['release_ref' => strtoupper(str_repeat('a', 40))])],
    'non-canonical timestamp' => [$record(['recorded_at' => '2026-09-11T00:00:00Z'])],
    'impossible timestamp' => [$record(['recorded_at' => '2026-13-45T00:00:00.000Z'])],
    'short digest' => [$record(['evidence_sha256' => str_repeat('b', 63)])],
    'uppercase digest' => [$record(['evidence_sha256' => strtoupper(str_repeat('b', 64))])],
    'absolute evidence ref' => [$record(['evidence_ref' => '/etc/passwd'])],
    'traversing evidence ref' => [$record(['evidence_ref' => 'evidence/../../etc/passwd'])],
    'remote evidence ref' => [$record(['evidence_ref' => 'https://example.test/evidence.json'])],
    'empty evidence ref' => [$record(['evidence_ref' => ''])],
    'malformed record id' => [$record(['record_id' => 'rec 1'])],
    'duplicate record id' => [$record(), $record(['kind' => 'STAGING_SMOKE'])],
    'uppercase target' => [$record(['target' => 'Staging.AllfatherBI.com'])],
    'multi-line note' => [$record(['note' => "first\nsecond"])],
    'record is a list' => [['CONFORMANCE', 'PASS']],
];
foreach ($refusals as $why => $records) {
    Assert::false($parse($registryJson($records))->isValid(), "the registry refuses: {$why}");
}

foreach (DeploymentEvidence::REQUIRED_RECORD_FIELDS as $field) {
    $missing = $record();
    unset($missing[$field]);
    Assert::false($parse($registryJson([$missing]))->isValid(), "a record missing {$field} is refused");
}

// Identity is part of the schema: a registry that speaks for another projection, or for another
// canonical pin, supports nothing here. Evidence collected under one pin says nothing about a
// projection re-pinned to another, and inheriting it silently is the quietest possible overclaim.
Assert::false(
    $parse($registryJson([], ['projection_id' => 'LOOPIA_P01']))->isValid(),
    'a registry for another projection is refused',
);
Assert::false(
    $parse($registryJson([], ['canonical_pin' => str_repeat('0', 40)]))->isValid(),
    'a registry pinned to another canonical head is refused',
);
Assert::false(
    $parse($registryJson([], ['schema_version' => '2.0']))->isValid(),
    'an unknown registry schema version is refused rather than guessed at',
);
Assert::false(
    $parse('{"schema_version":"1.0","projection_id":"LOOPIA_SME_MVP","canonical_pin":"'
        . $expected['canonical_pin'] . '","records":[],"extra":1}')->isValid(),
    'an unknown top-level field is refused',
);
Assert::false($parse('{not json')->isValid(), 'malformed JSON is refused');
Assert::false($parse('[]')->isValid(), 'a top-level array is refused');
Assert::false(
    $parse('{"schema_version":"1.0","projection_id":"LOOPIA_SME_MVP","canonical_pin":"'
        . $expected['canonical_pin'] . '","records":{"first":{}}}')->isValid(),
    'records must be an array, not a keyed object',
);
Assert::false(
    DeploymentEvidence::loadFile($root . '/deployment-evidence.json.nope', $expected)->isValid(),
    'a MISSING registry is a problem, never an empty one — deleting the file cannot grant a claim',
);

// AN INVALID REGISTRY SUPPORTS NOTHING. Otherwise a malformed file would be the thing that grants a
// claim, which inverts the point of validating it.
$invalid = $parse($registryJson([$record(['kind' => 'DEPLOYED'])]));
Assert::false($invalid->isValid(), 'the registry is invalid');
Assert::equalsList(
    [],
    array_keys(array_filter($invalid->supportedClaims())),
    'and supports no claim at all while it stays that way',
);

// ============================================================ what evidence actually supports

// SORTED, so the assertions read as sets rather than as an accident of declaration order in
// `supportedClaims()`.
$supports = static function (array $records) use ($parse, $registryJson): array {
    $claims = array_keys(array_filter($parse($registryJson($records))->supportedClaims()));
    sort($claims, SORT_STRING);

    return $claims;
};

$sha = static fn (string $seed): string => str_repeat($seed, 40);

Assert::equalsList(
    ['conformance_complete'],
    $supports([$record(['kind' => 'CONFORMANCE', 'result' => 'PASS'])]),
    'a CONFORMANCE PASS supports conformance_complete and nothing else',
);
Assert::equalsList(
    [],
    $supports([$record(['kind' => 'CONFORMANCE', 'result' => 'FAIL'])]),
    'a CONFORMANCE FAIL supports nothing',
);

// A CI RUN IS NOT A HOST CLAIM. The runner proves the code; it has never touched Loopia. A record
// filed from CI is legitimate and is refused as evidence for the deployment target — which is why
// `ci` is in the environment vocabulary rather than banned from it.
Assert::equalsList(
    [],
    $supports([$record(['kind' => 'STAGING_DEPLOYMENT', 'environment' => 'ci'])]),
    'a STAGING_DEPLOYMENT recorded from CI does not make staging deployed',
);
Assert::equalsList(
    ['staging_deployed'],
    $supports([$record(['kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging'])]),
    'the same record from the staging host does',
);

// BOTH HALVES, FOR ONE RELEASE. A probe of release X beside a deployment of release Y qualifies
// nothing: the pair is the evidence.
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging', 'release_ref' => $sha('a')]),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging', 'release_ref' => $sha('c')]),
    ]),
    'a deployment and a probe of DIFFERENT releases do not qualify the host',
);
Assert::equalsList(
    ['loopia_capability_validated', 'staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging', 'release_ref' => $sha('a')]),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging', 'release_ref' => $sha('a')]),
    ]),
    'the same release on both is what qualifies it',
);
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging']),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging', 'result' => 'FAIL']),
    ]),
    'a probe that FAILED qualifies nothing',
);

// LIVE READ: LATEST WINS. An activation followed by a stand-down is not activated, and a tie is
// resolved against the claim.
$activation = $record(['record_id' => 'LR-ON', 'kind' => 'LIVE_READ_ACTIVATION', 'environment' => 'staging', 'recorded_at' => '2026-09-11T10:00:00.000Z']);
$standDown = $record(['record_id' => 'LR-OFF', 'kind' => 'LIVE_READ_STAND_DOWN', 'result' => 'STAND_DOWN', 'environment' => 'staging', 'recorded_at' => '2026-09-11T11:00:00.000Z']);
Assert::equalsList(['live_read_activated'], $supports([$activation]), 'an activation record activates live read');
Assert::equalsList([], $supports([$activation, $standDown]), 'a later stand-down withdraws it');
Assert::equalsList(
    ['live_read_activated'],
    $supports([$activation, [...$standDown, 'recorded_at' => '2026-09-11T09:00:00.000Z']]),
    'an EARLIER stand-down does not — ordering is by recorded_at, not by position in the file',
);
Assert::equalsList(
    [],
    $supports([$activation, [...$standDown, 'recorded_at' => '2026-09-11T10:00:00.000Z']]),
    'and an exact tie resolves against the claim',
);
Assert::equalsList(
    [],
    $supports([[...$activation, 'environment' => 'ci']]),
    'live read cannot be activated by a record from CI',
);
Assert::equalsList(
    [],
    $supports([[...$activation, 'result' => 'FAIL']]),
    'nor by an activation that FAILED',
);

// EXECUTION IS NOT SUCCESS. This is the one claim a FAIL supports, and it is deliberate: a road
// test that went badly is still a road test that was executed, and collapsing the two is how a
// failure becomes a green flag.
Assert::equalsList(
    ['road_test_001_executed'],
    $supports([$record(['kind' => 'ROAD_TEST_001', 'result' => 'FAIL', 'environment' => 'staging'])]),
    'a FAILED road test still makes road_test_001_executed true',
);
Assert::equalsList(
    [],
    $supports([$record(['kind' => 'ROLLBACK_DRILL', 'environment' => 'staging'])]),
    'a rollback drill supports none of the five on its own — it is filed, not inferred from',
);

// ============================================================ the model is documented, not implied

// The same rule the capability report is held to: a vocabulary that lives only in code is one the
// launch-facing document will eventually contradict.
$modelDoc = (string) file_get_contents($root . '/docs/DEPLOYMENT_EVIDENCE_MODEL.md');
foreach (DeploymentEvidence::KINDS as $kind) {
    Assert::contains($kind, $modelDoc, "the evidence model document names the {$kind} kind");
}
foreach (DeploymentEvidence::RESULTS as $result) {
    Assert::contains($result, $modelDoc, "and the {$result} result");
}
foreach (DeploymentEvidence::EVIDENCE_BACKED_CLAIMS as $claim) {
    Assert::contains($claim, $modelDoc, "and states what supports {$claim}");
}
foreach (DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS as $claim) {
    Assert::contains($claim, $modelDoc, "and that no evidence supports {$claim}");
}
Assert::contains(
    'Execution is not success',
    $modelDoc,
    'and says out loud that a road test being executed is not a road test passing',
);
