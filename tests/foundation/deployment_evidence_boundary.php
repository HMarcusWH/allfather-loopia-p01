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

/**
 * One record. `target` is supplied automatically for a HOST environment, because a host record that
 * does not name its host is invalid — pass `'target' => null` to build one deliberately without it.
 */
$record = static function (array $overrides = []): array {
    $built = [
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
    if (array_key_exists('target', $overrides) && $overrides['target'] === null) {
        unset($built['target']);

        return $built;
    }
    if (!array_key_exists('target', $overrides)
        && in_array($built['environment'], DeploymentEvidence::HOST_ENVIRONMENTS, true)
    ) {
        $built['target'] = 'staging.allfatherbi.com';
    }

    return $built;
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

// A HISTORIC PAIR DOES NOT QUALIFY THE RELEASE THAT REPLACED IT. Deploy A and probe A: the host is
// qualified for A. Deploy B over it and probe nothing: a rule asking "does SOME release have both"
// still finds the A pair and keeps the claim true for a deployment running B, which nobody
// qualified. The claim is about the host as it stands, so it names the release standing on it.
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-A', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T00:00:00.000Z']),
        $record(['record_id' => 'PROBE-A', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T01:00:00.000Z']),
        $record(['record_id' => 'DEPLOY-B', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('b'), 'recorded_at' => '2026-09-05T00:00:00.000Z']),
    ]),
    'deploying a NEWER release over a qualified one withdraws loopia_capability_validated',
);
Assert::equalsList(
    ['loopia_capability_validated', 'staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-A', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T00:00:00.000Z']),
        $record(['record_id' => 'PROBE-A', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T01:00:00.000Z']),
        $record(['record_id' => 'DEPLOY-B', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('b'), 'recorded_at' => '2026-09-05T00:00:00.000Z']),
        $record(['record_id' => 'PROBE-B', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
            'release_ref' => $sha('b'), 'recorded_at' => '2026-09-05T01:00:00.000Z']),
    ]),
    'and probing the new release qualifies it again',
);
// AMBIGUITY FAILS CLOSED. Two different releases deployed at the same instant leaves no answer to
// "which one is running", and a registry that cannot say that must not be the thing that says the
// host is qualified.
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-A', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T00:00:00.000Z']),
        $record(['record_id' => 'PROBE-A', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
            'release_ref' => $sha('a'), 'recorded_at' => '2026-09-01T01:00:00.000Z']),
        $record(['record_id' => 'DEPLOY-C', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
            'release_ref' => $sha('c'), 'recorded_at' => '2026-09-01T00:00:00.000Z']),
        $record(['record_id' => 'PROBE-C', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
            'release_ref' => $sha('c'), 'recorded_at' => '2026-09-01T01:00:00.000Z']),
    ]),
    'two releases deployed at the same instant qualify neither — ambiguity fails closed',
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
// THE ENVIRONMENT RULE IS ASYMMETRIC, and that is the fail-closed direction: CI cannot activate
// anything on a host it has never touched, but a withdrawal is honoured from anywhere. Discarding a
// stand-down because it was filed under the wrong environment would leave the claim standing on a
// technicality.
Assert::equalsList(
    [],
    $supports([[...$activation, 'environment' => 'ci']]),
    'live read cannot be activated by a record from CI',
);
Assert::equalsList(
    [],
    $supports([$activation, [...$standDown, 'environment' => 'ci']]),
    'but a stand-down withdraws it from any environment',
);
Assert::equalsList(
    [],
    $supports([[...$activation, 'result' => 'FAIL']]),
    'nor by an activation that FAILED',
);

// A WITHDRAWAL THAT FAILED IS NOT A WITHDRAWAL. This is the one place where reading only the latest
// record would be wrong in the dangerous direction: it would report live read OFF at exactly the
// moment somebody has just discovered they could not turn it off.
Assert::equalsList(
    ['live_read_activated'],
    $supports([$activation, [...$standDown, 'result' => 'FAIL']]),
    'a FAILED stand-down leaves live read activated — the withdrawal did not happen',
);
// And a failed RE-activation of something already on changes nothing either: a failed attempt is
// not a state transition in either direction.
Assert::equalsList(
    ['live_read_activated'],
    $supports([
        $activation,
        [...$activation, 'record_id' => 'LR-ON-2', 'result' => 'FAIL', 'recorded_at' => '2026-09-11T12:00:00.000Z'],
    ]),
    'and a failed re-activation does not turn off what is already on',
);
// The history is folded in TIMESTAMP order, not file order. A stand-down listed first but timestamped
// later still wins.
Assert::equalsList(
    [],
    $supports([$standDown, $activation]),
    'ordering is by recorded_at, whatever order the records appear in the file',
);
// ONE TIMELINE, NOT ONE PER RELEASE — and this is the case that makes it necessary rather than
// merely simpler. Standing live read down means removing the wiring in a reviewed change and
// DEPLOYING that release, so the stand-down record necessarily names a NEWER release than the
// activation it withdraws. A per-release rule would file the two in separate buckets and leave the
// old activation standing forever: the claim would be impossible to withdraw by the exact procedure
// `ops/rollback/README.md` prescribes.
Assert::equalsList(
    [],
    $supports([
        $activation,
        [...$standDown, 'release_ref' => $sha('c')],
    ]),
    'a stand-down on the NEXT release withdraws the activation on the previous one',
);
Assert::equalsList(
    ['live_read_activated'],
    $supports([
        [...$standDown, 'release_ref' => $sha('c'), 'recorded_at' => '2026-09-11T08:00:00.000Z'],
        $activation,
    ]),
    'and an activation after a stand-down turns it back on',
);

// A HOST RECORD MUST NAME ITS HOST, and the pair must be the SAME host.
//
// Release alone was the whole identity, so the same SHA deployed to two hosts made a
// `STAGING_DEPLOYMENT` PASS for one and a `LOOPIA_PROBE_001` PASS for the other satisfy the
// same-release rule — qualifying a deployment whose actual host had never been probed. With only
// `environment` added, a staging deployment could still have been paired with a PRODUCTION probe.
// The scope is the triple: environment, target, release.
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
                 'target' => 'staging.allfatherbi.com', 'release_ref' => $sha('a')]),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
                 'target' => 'other.allfatherbi.com', 'release_ref' => $sha('a')]),
    ]),
    'a deployment to one host and a probe of ANOTHER qualify neither — same release, different machine',
);
Assert::equalsList(
    ['staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
                 'target' => 'host.allfatherbi.com', 'release_ref' => $sha('a')]),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'production',
                 'target' => 'host.allfatherbi.com', 'release_ref' => $sha('a')]),
    ]),
    'nor a staging deployment paired with a PRODUCTION probe of the same host and release',
);
Assert::equalsList(
    ['loopia_capability_validated', 'staging_deployed'],
    $supports([
        $record(['record_id' => 'DEPLOY-1', 'kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging',
                 'target' => 'host.allfatherbi.com', 'release_ref' => $sha('a')]),
        $record(['record_id' => 'PROBE-1', 'kind' => 'LOOPIA_PROBE_001', 'environment' => 'staging',
                 'target' => 'host.allfatherbi.com', 'release_ref' => $sha('a')]),
    ]),
    'the same environment, host and release on both is what qualifies it',
);

// AN UNTARGETED HOST RECORD SUPPORTS NOTHING, and is reported as a problem in its own right. Both,
// deliberately: the validator catches it at the diff, and the claim reducer refuses it again at the
// point a claim is computed, so no read path can let an anonymous host record speak for a host.
foreach (['STAGING_DEPLOYMENT', 'LOOPIA_PROBE_001', 'ROAD_TEST_001'] as $hostKind) {
    Assert::equalsList(
        [],
        $supports([$record(['kind' => $hostKind, 'environment' => 'staging', 'target' => null])]),
        "an untargeted {$hostKind} record supports nothing — it names no host to have a claim about",
    );
}
$untargeted = $parse($registryJson([
    $record(['kind' => 'STAGING_DEPLOYMENT', 'environment' => 'staging', 'target' => null]),
]));
Assert::true(
    $untargeted->problems() !== [],
    'and the registry reports it as a problem rather than accepting it silently',
);
Assert::true(
    str_contains(implode(' ', $untargeted->problems()), 'target is required'),
    'naming the missing field, so the diff reviewer knows what to add',
);
// `target` stays OPTIONAL where there is no host to name — CI has none, and a required-but-invented
// target would be a fiction in the evidence file.
Assert::equalsList(
    [],
    $parse($registryJson([$record(['kind' => 'CONFORMANCE', 'environment' => 'ci', 'target' => null])]))->problems(),
    'a ci record needs no target — there is no host for it to name',
);

// EXECUTION IS NOT SUCCESS. This is the one claim a FAIL supports, and it is deliberate: a road
// test that went badly is still a road test that was executed, and collapsing the two is how a
// failure becomes a green flag.
Assert::equalsList(
    ['road_test_001_executed'],
    $supports([$record(['kind' => 'ROAD_TEST_001', 'result' => 'FAIL', 'environment' => 'staging'])]),
    'a FAILED road test still makes road_test_001_executed true',
);
// AND IT MUST BE A HOST RECORD. ROAD_TEST_001 is by definition a real, controlled, post-staging
// execution against a live source. A record filed from `ci` or `testing` describes something that is
// not a road test at all, and accepting one would let the projection claim it had road-tested
// without ever leaving CI.
foreach (['ci', 'testing', 'development'] as $notAHost) {
    Assert::equalsList(
        [],
        $supports([$record(['kind' => 'ROAD_TEST_001', 'result' => 'PASS', 'environment' => $notAHost])]),
        "a ROAD_TEST_001 recorded from {$notAHost} supports nothing — that is not a road test",
    );
}

// STAND_DOWN IS THE THIRD THING, and it supports nothing here either — a road test that was
// deliberately withdrawn is not a road test that was executed. "Execution is not success" widens
// what counts as executed; it does not make every record count.
Assert::equalsList(
    [],
    $supports([$record(['kind' => 'ROAD_TEST_001', 'result' => 'STAND_DOWN', 'environment' => 'staging'])]),
    'a road test that was STOOD DOWN was not executed',
);
// The same rule, stated once over the whole vocabulary: no claim anywhere is supported by a
// STAND_DOWN record alone.
foreach (DeploymentEvidence::KINDS as $kind) {
    Assert::equalsList(
        [],
        $supports([$record(['kind' => $kind, 'result' => 'STAND_DOWN', 'environment' => 'staging'])]),
        "a STAND_DOWN {$kind} record supports nothing",
    );
}
Assert::equalsList(
    [],
    $supports([$record(['kind' => 'ROLLBACK_DRILL', 'environment' => 'staging'])]),
    'a rollback drill supports none of the five on its own — it is filed, not inferred from',
);

// ============================================================ nothing may write the governed files

// `no_external_action_surface.php` proves no code path in app/, public_html/, worker/ or ops/ writes
// either file. It cannot prove it of tests/, because the test tree legitimately writes files — and
// `tests/run.php --report-json` takes a caller-chosen path. `dirname('upstream.lock.json')` is `.`
// and `is_dir('.')` is true, so a directory check alone accepts a bare governed filename and
// overwrites the lock with a test report. This is the guard, exercised rather than grepped.
foreach (DeploymentEvidence::GOVERNED_FILES as $governed) {
    Assert::true(
        DeploymentEvidence::isGovernedPath($root, $root . '/' . $governed),
        "an absolute path to {$governed} is governed",
    );
    Assert::true(
        DeploymentEvidence::isGovernedPath($root, $root . '/./' . $governed),
        "and so is the same path written with ./ : {$governed}",
    );
    Assert::true(
        DeploymentEvidence::isGovernedPath($root, $root . '/docs/../' . $governed),
        "and one that traverses back into the root: {$governed}",
    );
    // A governed FILENAME somewhere else is not this repository's governed file. Refusing by
    // basename would be refusing a name rather than a file.
    Assert::false(
        DeploymentEvidence::isGovernedPath($root, sys_get_temp_dir() . '/' . $governed),
        "but the same name elsewhere is not: {$governed}",
    );
}
Assert::false(
    DeploymentEvidence::isGovernedPath($root, $root . '/qualification-10.6.json'),
    'an ordinary report path is not governed',
);
Assert::false(
    DeploymentEvidence::isGovernedPath($root, $root . '/no/such/directory/upstream.lock.json'),
    'a path whose directory does not exist is not governed — nothing can be written there anyway',
);
Assert::false(DeploymentEvidence::isGovernedPath($root, ''), 'an empty path is not governed');

// THE FINAL COMPONENT IS FOLLOWED BY THE WRITE, so resolving only the parent is not enough — and an
// earlier revision did exactly that: a report path of `/tmp/report.json` symlinked to the lock
// compared as `/tmp/report.json`, matched nothing, and was permitted. `file_put_contents()` then
// followed the link and overwrote the governed file.
$linkDir = sys_get_temp_dir() . '/allfather-governed-link-' . bin2hex(random_bytes(6));
@mkdir($linkDir, 0o700, true);
try {
    foreach (DeploymentEvidence::GOVERNED_FILES as $index => $governed) {
        $link = $linkDir . '/report-' . $index . '.json';
        @symlink($root . '/' . $governed, $link);
        Assert::true(
            DeploymentEvidence::isGovernedPath($root, $link),
            "a symlink whose target is {$governed} is governed, wherever the link itself lives",
        );

        // A DANGLING link still creates its target when written to, so it cannot be waved through
        // merely because `realpath` cannot resolve it. The traversal is included because a link is
        // as free to contain `..` as any other path.
        $dangling = $linkDir . '/dangling-' . $index . '.json';
        @symlink($root . '/docs/../' . $governed, $dangling);
        Assert::true(
            DeploymentEvidence::isGovernedPath($root, $dangling),
            "and so is one that reaches {$governed} through a traversal",
        );
    }

    // A link to something harmless stays harmless — the rule follows the target, it does not refuse
    // links.
    $benign = $linkDir . '/benign.json';
    @symlink($root . '/composer.json', $benign);
    Assert::false(
        DeploymentEvidence::isGovernedPath($root, $benign),
        'a symlink to an ungoverned file is not governed — the target decides, not the link',
    );
} finally {
    foreach (glob($linkDir . '/*') ?: [] as $entry) {
        @unlink($entry);
    }
    @rmdir($linkDir);
}
// The relative case is the one the guard exists for, and it is only meaningful when the working
// directory IS the repository root — which is where an operator runs `php tests/run.php` from.
if (realpath(getcwd() ?: '.') === realpath($root)) {
    foreach (DeploymentEvidence::GOVERNED_FILES as $governed) {
        Assert::true(
            DeploymentEvidence::isGovernedPath($root, $governed),
            "a BARE {$governed} resolves against the working directory and is governed",
        );
    }
}

// And the runner asks, before it writes.
$runner = (string) file_get_contents($root . '/tests/run.php');
Assert::contains(
    'DeploymentEvidence::isGovernedPath(dirname(__DIR__), $reportPath)',
    $runner,
    'tests/run.php asks whether the report path is governed',
);
Assert::true(
    strpos($runner, 'isGovernedPath(dirname(__DIR__), $reportPath)') < strpos($runner, 'file_put_contents($reportPath'),
    'and asks BEFORE it writes — a refusal after the write would refuse nothing',
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
