<?php

declare(strict_types=1);

/**
 * `docs/MVP_RELEASE_STATE.md` is the one current-state entrypoint, and it is only worth having while
 * it is current.
 *
 * Before it existed, the source head, the tested tree, the deployed release and the activation state
 * were spread across five documents, and reconciling them was a reader's job. A single file fixes
 * that only until the day it goes stale — so its load-bearing values are read back here against the
 * files that actually govern them. The document cannot change what they say; this test makes it
 * impossible for it to say something else.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$state = (string) file_get_contents($root . '/docs/MVP_RELEASE_STATE.md');
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 64, JSON_THROW_ON_ERROR);
$evidence = json_decode((string) file_get_contents($root . '/deployment-evidence.json'), true, 64, JSON_THROW_ON_ERROR);

/** @var array<string, string> $declared */
$declared = [];
preg_match_all('/^([a-z0-9_]+)\s+= (\S+)$/m', $state, $matches, PREG_SET_ORDER);
foreach ($matches as [, $key, $value]) {
    Assert::false(isset($declared[$key]), "MVP_RELEASE_STATE.md declares {$key} once");
    $declared[$key] = $value;
}

Assert::same(
    $lock['projection_pinned_head'],
    $declared['canonical_pin'] ?? null,
    'the stated canonical pin is the lock\'s pin',
);

// The deployed staging release is the one the NEWEST passing deployment record names — not the
// newest commit, and not the release somebody intends to deploy next.
$deployments = array_values(array_filter(
    $evidence['records'],
    static fn (array $record): bool => $record['kind'] === 'STAGING_DEPLOYMENT' && $record['result'] === 'PASS' && $record['environment'] === 'staging',
));
usort($deployments, static fn (array $a, array $b): int => strcmp($b['recorded_at'], $a['recorded_at']));
Assert::true($deployments !== [], 'the registry carries a passing staging deployment to state');
Assert::same(
    $deployments[0]['release_ref'] ?? null,
    $declared['deployed_staging_release'] ?? null,
    'the stated deployed staging release is the newest passing STAGING_DEPLOYMENT record',
);

Assert::same(
    (string) count($evidence['records']),
    $declared['evidence_records'] ?? null,
    'the stated evidence-record count is the registry\'s',
);
Assert::same(
    (string) count(glob($root . '/database/migrations/*.sql') ?: []),
    $declared['candidate_migrations'] ?? null,
    'the stated candidate migration count is the number of migration files',
);
Assert::true(
    preg_match('/^[0-9a-f]{40}$/', $declared['last_reviewed_application_merge'] ?? '') === 1,
    'the last reviewed application merge is named by its full commit id',
);

// Every claim flag, with the lock's own value. A flag the document forgot or restated wrongly fails.
foreach ($lock['claim_boundary'] as $flag => $value) {
    Assert::same(
        $value ? 'true' : 'false',
        $declared[$flag] ?? null,
        "MVP_RELEASE_STATE.md states {$flag} as the lock stores it",
    );
}

// The four questions stay separate, and each document that used to answer them points here.
foreach (['**Source**', '**Tested**', '**Deployed**', '**Activated**'] as $question) {
    Assert::contains($question, $state, "the current-state record answers {$question} separately");
}
foreach (['README.md', 'docs/LOOPIA_CAPABILITY_REPORT.md', 'docs/BETA_RUNBOOK.md', 'docs/DEPLOYMENT_RUNBOOK.md'] as $document) {
    Assert::contains(
        'MVP_RELEASE_STATE.md',
        (string) file_get_contents($root . '/' . $document),
        "{$document} points to the current-state record rather than carrying its own",
    );
}
