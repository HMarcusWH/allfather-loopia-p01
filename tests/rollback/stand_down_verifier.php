<?php

declare(strict_types=1);

/**
 * The stand-down verifier's rule, exercised one contradiction at a time.
 *
 * THE DEFECT THIS EXISTS TO PREVENT ALREADY HAPPENED. An earlier revision of
 * `ops/rollback/verify-stand-down.php` COMPUTED the scheduler posture and the live-read state,
 * printed both in its JSON, and let neither reach its findings list. A deployment with live read
 * still active, a configured scheduler credential, and an endpoint that had lost its POST-only
 * guard was reported `STAND_DOWN_VERIFIED` — with all three facts visible in the very output that
 * said so.
 *
 * That is the worst shape a verifier can take. Its verdict is the part people quote and paste into
 * an incident thread; its fields are the part nobody reads. So the rule now lives in
 * `StandDownPosture`, away from the database and the filesystem the command needs, and every clause
 * of it is asserted here — in both directions. Each deviation must produce a finding, and the
 * stood-down posture must produce none, because a verifier that fails on everything is as useless
 * as one that passes on everything.
 */

use Allfather\Loopia\Ops\StandDownPosture;
use Allfather\Loopia\Tests\Assert;

$release = str_repeat('a', 40);

// ============================================================ the posture that IS a stand-down

Assert::equalsList(
    [],
    StandDownPosture::findings(StandDownPosture::stoodDown($release)),
    'a deployment that has stood down produces no findings at all',
);

/** One deviation from the stood-down posture, and nothing else. @param array<string, mixed> $deviation */
$with = static fn (array $deviation): array => StandDownPosture::findings(
    [...StandDownPosture::stoodDown($release), ...$deviation]
);

// ============================================================ every contradiction is a finding

// THE THREE THAT WERE SILENTLY DROPPED. Each is asserted by its own name so that removing the check
// fails with a message naming the state it stopped detecting, rather than an off-by-one on a count.
Assert::equalsList(
    ['public_html/scheduler.php is no longer POST only — a GET that mutates is a GET a crawler performs'],
    $with(['endpoint_post_only' => false]),
    'an endpoint that lost its POST-only guard cannot verify',
);
Assert::equalsList(
    ['a scheduler credential is configured — a stood-down deployment leaves the tick unreachable'],
    $with(['credential_configured' => true]),
    'a configured scheduler credential cannot verify — stood down means unreachable, not merely closed',
);
Assert::equalsList(
    ['claim_boundary.live_read_activated is still asserted — this deployment has not stood down'],
    $with(['live_read_claimed' => true]),
    'a lock still asserting live read cannot verify',
);
Assert::equalsList(
    ['the evidence registry still shows live read activated — no withdrawal record supersedes it'],
    $with(['live_read_supported' => true]),
    'and neither can a registry that still supports it',
);

// THE TWO LIVE-READ DIRECTIONS ARE DIFFERENT FAULTS and both must appear when both hold: the lock
// asserting it is a governance edit nobody made; the registry supporting it is a withdrawal that
// never happened. Reporting one would leave the other unfixed.
Assert::same(
    2,
    count($with(['live_read_claimed' => true, 'live_read_supported' => true])),
    'a claim AND its supporting evidence are two findings, not one',
);

// A SUPPORTED CLAIM IS NOT AN UNSUPPORTED ONE. `unsupportedClaims()` catches a claim asserted
// WITHOUT evidence and says nothing about one that is fully supported — which is exactly the state a
// stand-down was performed to end. This is the case that made the original defect invisible: the
// registry was consistent with the lock, so the only check that ran had nothing to say.
$fullySupported = $with(['live_read_claimed' => true, 'live_read_supported' => true]);
foreach ($fullySupported as $finding) {
    Assert::false(
        str_contains($finding, 'with no evidence supporting it'),
        'a fully supported live-read claim is caught by its OWN check, not by the unsupported-claim one',
    );
}

// ---- the rest of the posture
Assert::equalsList(
    ['APP_RELEASE_REF is not set — the deployment cannot say which release it is running'],
    $with(['running_release' => null]),
    'a deployment that cannot name its release cannot verify',
);
Assert::equalsList(
    ['APP_RELEASE_REF is not the release the rollback was meant to restore'],
    $with(['running_release' => str_repeat('b', 40)]),
    'nor one running a different release than the rollback restored',
);
Assert::equalsList(
    ['the Operator Console manifest is absent — /console/app answers 503 until it is restored'],
    $with(['console_manifest_present' => false, 'console_manifest_valid' => false]),
    'an absent console manifest cannot verify',
);
Assert::equalsList(
    ['the Operator Console manifest does not name an entry script'],
    $with(['console_manifest_valid' => false]),
    'nor one that is present but unloadable',
);
Assert::equalsList(
    ['a composition root no longer constructs the scheduler with an EMPTY handler registry'],
    $with(['handler_registry_empty' => false]),
    'a wired handler registry cannot verify',
);
Assert::equalsList(
    ['the scheduler endpoint authorizes a caller presenting no token'],
    $with(['unauthenticated_call_authorized' => true]),
    'nor a scheduler door that answers an unauthenticated caller',
);
Assert::equalsList(
    ['deployment evidence: the registry is malformed'],
    $with(['evidence_problems' => ['the registry is malformed']]),
    'an invalid evidence registry cannot verify',
);
Assert::equalsList(
    ['claim_boundary.staging_deployed is asserted with no evidence supporting it'],
    $with(['unsupported_claims' => ['staging_deployed']]),
    'nor a claim asserted without a record behind it',
);

// GOVERNED HISTORY IS THE POINT OF A ROLLBACK. A chain that no longer verifies is the one failure
// that means the restore did not preserve what it claimed to preserve.
Assert::equalsList(
    ['M10 stream 0f9b-stream does not verify'],
    $with(['failing_stream_ids' => ['0f9b-stream']]),
    'a ledger stream that does not verify cannot verify',
);
Assert::same(
    3,
    count($with(['failing_stream_ids' => ['a', 'b', 'c']])),
    'and every failing stream is named, not just the first',
);

// ============================================================ nothing is configurable away

// A deployment that has legitimately activated something is not a deployment that has stood down.
// The day the scheduler is genuinely activated, this rule changes in a reviewed commit — not
// through a flag an operator can pass to turn a red verifier green.
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Ops/StandDownPosture.php');
foreach (['$args', 'getopt', 'Env::', 'allow-', 'expect-scheduler', 'skip-'] as $escapeHatch) {
    Assert::false(
        str_contains($source, $escapeHatch),
        "the rule reads no caller input that could waive a finding: {$escapeHatch}",
    );
}

// And the command decides nothing of its own: it observes, and asks once.
$command = (string) file_get_contents(dirname(__DIR__, 2) . '/ops/rollback/verify-stand-down.php');
Assert::contains(
    '$findings = StandDownPosture::findings($observed);',
    $command,
    'the verifier applies the rule in exactly one place',
);
Assert::same(
    1,
    substr_count($command, 'StandDownPosture::findings('),
    'and only once — a second call site is a second rule',
);
Assert::false(
    str_contains($command, '$findings[] ='),
    'the command appends no finding of its own, so none can bypass the rule',
);

// ============================================================ the manifest check is the runtime's

// A VERIFIER MORE PERMISSIVE THAN THE THING IT VERIFIES reports a rollback as complete when it is
// not. An earlier revision decoded the manifest here and accepted any object with a string at
// `entry.script` — which passes a manifest naming an unhashed or absent script, one whose
// stylesheet key is gone, one with no `assets` list, and one at an unsupported schema version.
// `ConsoleAssetManifest` refuses every one of those at runtime, so `/console/app` would answer 503
// while this command said STAND_DOWN_VERIFIED. Loading it through the runtime class also checks the
// referenced FILES — local, content-hashed, not symlinked, present — which is exactly what a
// partial or stale rollback breaks.
Assert::contains(
    "(new ConsoleAssetManifest(\$root . '/public_html/assets/operator-console'))->load()",
    $command,
    'the restored manifest is validated by the runtime class, not by a weaker restatement of it',
);
Assert::false(
    str_contains($command, "is_string(\$decoded['entry']['script'] ?? null)"),
    'the hand-rolled entry-script check is gone',
);
Assert::false(
    str_contains($command, "json_decode((string) file_get_contents(\$manifestPath)"),
    'and the manifest is not decoded here at all — one validator, in one place',
);
Assert::contains(
    "\$observed['console_manifest_valid'] = \$consoleManifest['valid'];",
    $command,
    'and the result reaches the rule as an observation, like everything else',
);
