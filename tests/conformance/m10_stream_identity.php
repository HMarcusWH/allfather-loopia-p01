<?php

declare(strict_types=1);

/**
 * M10 ACTIVE-STREAM IDENTITY — a scope is (tenant, workspace, environment).
 *
 * Ported from `createDecisionLedgerStream` / `ensureActiveDecisionLedgerStream` in
 * `packages/allfather-control-substrate/src/m10/ledger.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * The defect this suite exists to prevent returning: the PHP port scoped an ACTIVE stream by
 * (tenant, workspace) alone, so a caller asking for the DEMO ledger was handed the TEST one. Two
 * governed histories collapsed into a single chain, and nothing said so — the DEMO records simply
 * appended to the TEST head and verified cleanly, because a chain has no opinion about which
 * environment its records came from.
 *
 * Three separate things had to be wrong together for that to happen, and each is pinned here:
 *
 *  1. the ACTIVE lookup omitted `environment_class`;
 *  2. the default stream key was `m10.{tenant}.{workspace}` — with tenant and workspace already
 *     inside the key, the real UNIQUE index on (tenant, workspace, stream_key) allowed exactly one
 *     stream per scope FOR ALL TIME, so a second environment was structurally impossible even
 *     after fixing (1);
 *  3. the genesis anchor digested the random stream id, so a TEST anchor and a DEMO anchor for the
 *     same scope were merely different rather than differing BECAUSE the environments differ —
 *     and neither could be reconstructed by a verifier holding only the stream's identity.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$ledger = new DecisionLedger($db, $clock);
$scope = Seed::tenantWorkspace($db, $clock, 'stream-identity');
$tenantId = (string) $scope['tenant_id'];
$workspaceId = (string) $scope['workspace_id'];

// ================================================================ A / B / C

Assert::case('A');
$testStream = $ledger->ensureActiveStream($tenantId, $workspaceId, 'TEST');
Assert::same(
    $testStream,
    $ledger->ensureActiveStream($tenantId, $workspaceId, 'TEST'),
    'ensureActiveStream is idempotent for a scope — the same TEST call returns the same stream',
);

Assert::case('B');
$demoStream = $ledger->ensureActiveStream($tenantId, $workspaceId, 'DEMO');
Assert::notSame(
    $testStream,
    $demoStream,
    'a DEMO caller gets a DIFFERENT stream — TEST and DEMO are two governed histories',
);
Assert::same(
    $demoStream,
    $ledger->ensureActiveStream($tenantId, $workspaceId, 'DEMO'),
    'and DEMO is idempotent for the scope too',
);

Assert::case('C');
Assert::same(
    2,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'",
        [$tenantId, $workspaceId]
    ),
    'both remain ACTIVE — one per environment, simultaneously',
);

// ================================================================ D / E

Assert::case('D');
Assert::same(
    'TEST',
    (string) $db->scalar(
        'SELECT environment_class FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$testStream]
    ),
    'the TEST stream records environment_class = TEST',
);

Assert::case('E');
Assert::same(
    'DEMO',
    (string) $db->scalar(
        'SELECT environment_class FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$demoStream]
    ),
    'the DEMO stream records environment_class = DEMO',
);

// ================================================================ F / G

Assert::case('F');
Assert::same(
    'ledger:test',
    (string) $db->scalar(
        'SELECT stream_key FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$testStream]
    ),
    'the TEST default key is the canonical ledger:<environment lowercased>',
);

Assert::case('G');
Assert::same(
    'ledger:demo',
    (string) $db->scalar(
        'SELECT stream_key FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$demoStream]
    ),
    'and the DEMO default key is the canonical one for DEMO',
);
Assert::equalsList(
    ['ledger:local_dev', 'ledger:test', 'ledger:demo'],
    array_map(
        static fn (string $e): string => DecisionLedger::defaultStreamKey($e),
        ['LOCAL_DEV', 'TEST', 'DEMO']
    ),
    'every member of the canonical environment vocabulary derives its canonical key',
);

// ================================================================ H / I / J — the genesis anchor
//
// The vectors below were produced by running the CANONICAL TypeScript encoder
// (`packages/allfather-substrate-core/src/digest.ts`) over the canonical field shape, not by
// recording what this port happens to emit. A test that characterizes PHP against PHP proves the
// port is self-consistent, which is not the claim being made.

Assert::case('H');
$FIXED_TENANT = '11111111-1111-4111-8111-111111111111';
$FIXED_WORKSPACE = '22222222-2222-4222-8222-222222222222';
$CANONICAL_GENESIS = [
    'TEST' => '86d270094ba4dbba9b865506db479ef37276c7ed9001b204bcf95a914f378ea8',
    'DEMO' => '8c71fe72115fc5bde9fb2ebfff7a65cee95d4df52871e89947d46aaec5bbf627',
    'LOCAL_DEV' => 'b4cfe332504924ccfb5e36a0d94024af445557ead3100cf757b318ea0dec6f8b',
];
foreach ($CANONICAL_GENESIS as $environmentClass => $expected) {
    Assert::same(
        $expected,
        DecisionLedger::genesisAnchor(
            $FIXED_TENANT,
            $FIXED_WORKSPACE,
            $environmentClass,
            DecisionLedger::defaultStreamKey($environmentClass),
        ),
        "the {$environmentClass} genesis anchor equals the digest the CANONICAL encoder produces",
    );
}

// And the shape is the canonical one, expressed through the project's own canonical serializer
// rather than a second encoder written for this test. If the field set ever drifts, this fails
// alongside the fixed vectors above instead of silently agreeing with a changed implementation.
Assert::same(
    $CANONICAL_GENESIS['TEST'],
    Digest::canonicalDigest([
        'kind' => 'GENESIS',
        'streamKey' => 'ledger:test',
        'tenantId' => $FIXED_TENANT,
        'workspaceId' => $FIXED_WORKSPACE,
        'environmentClass' => 'TEST',
    ]),
    'and the canonical field shape is exactly {kind, streamKey, tenantId, workspaceId, environmentClass}',
);

// The random stream id is NOT in it. This is what makes the anchor reconstructible.
Assert::notSame(
    $CANONICAL_GENESIS['TEST'],
    Digest::canonicalDigest([
        'kind' => 'GENESIS',
        'streamKey' => 'ledger:test',
        'tenantId' => $FIXED_TENANT,
        'workspaceId' => $FIXED_WORKSPACE,
        'environmentClass' => 'TEST',
        'ledgerStreamId' => '33333333-3333-4333-8333-333333333333',
    ]),
    'adding the stream id changes the digest — so its absence is a fact, not an accident',
);

Assert::case('I');
Assert::notSame(
    (string) $db->scalar(
        'SELECT genesis_or_initial_anchor_hash FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$testStream]
    ),
    (string) $db->scalar(
        'SELECT genesis_or_initial_anchor_hash FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$demoStream]
    ),
    'the TEST and DEMO anchors differ — and they differ BECAUSE the environment is digested',
);

Assert::case('J');
// Reconstructed independently from the stream's own logical identity, with no access to the row's
// generated id. A verifier can do this; that is the point of a deterministic anchor.
$storedTestAnchor = (string) $db->scalar(
    'SELECT genesis_or_initial_anchor_hash FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
    [$testStream]
);
Assert::same(
    DecisionLedger::genesisAnchor($tenantId, $workspaceId, 'TEST', 'ledger:test'),
    $storedTestAnchor,
    'the same logical identity reconstructs the stored anchor exactly',
);
Assert::same(
    $storedTestAnchor,
    (string) $db->scalar(
        'SELECT current_head_chain_hash FROM decision_ledger_streams
         WHERE decision_ledger_stream_id = ? AND head_sequence_number = 0',
        [$testStream]
    ),
    'and it seeds the head, so record 1 chains onto a value a verifier can derive',
);

// ================================================================ K / L

Assert::case('K');
Assert::same(
    $demoStream,
    $ledger->ensureActiveStream($tenantId, $workspaceId, 'DEMO'),
    'a caller asking for DEMO never receives TEST',
);
Assert::same(
    'DEMO',
    (string) $db->scalar(
        'SELECT environment_class FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$ledger->ensureActiveStream($tenantId, $workspaceId, 'DEMO')]
    ),
    'proved by the environment on the stream it got, not by the id alone',
);

Assert::case('L');
Assert::same(
    $testStream,
    $ledger->ensureActiveStream($tenantId, $workspaceId, 'TEST'),
    'a caller asking for TEST never receives DEMO',
);
Assert::same(
    'TEST',
    (string) $db->scalar(
        'SELECT environment_class FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$ledger->ensureActiveStream($tenantId, $workspaceId, 'TEST')]
    ),
    'proved the same way',
);

// A third environment for the same scope is equally distinct.
$localStream = $ledger->ensureActiveStream($tenantId, $workspaceId, 'LOCAL_DEV');
Assert::same(
    3,
    count(array_unique([$testStream, $demoStream, $localStream])),
    'all three environment classes hold distinct ACTIVE streams for one tenant and workspace',
);

// ================================================================ the environment is REQUIRED
//
// It used to default to 'TEST'. A default here is not a convenience: a caller that forgot to pass
// the workspace's environment wrote DEMO history into the TEST ledger and nothing reported it.
$ensureSignature = new ReflectionMethod(DecisionLedger::class, 'ensureActiveStream');
Assert::same(
    3,
    $ensureSignature->getNumberOfRequiredParameters(),
    'ensureActiveStream requires the environment — there is none it may assume for a caller',
);
Assert::same(
    'environmentClass',
    $ensureSignature->getParameters()[2]->getName(),
    'and it is the third parameter',
);
