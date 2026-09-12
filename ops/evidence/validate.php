<?php

declare(strict_types=1);

/**
 * Validate the deployment evidence registry against the lock it speaks for.
 *
 * Usage:
 *   php ops/evidence/validate.php [--evidence=path] [--lock=path]
 *
 * READ-ONLY, AND STRUCTURALLY SO. This command opens two files and prints a verdict. It writes
 * neither of them, and it cannot: the same tripwire that proves no code path writes
 * `upstream.lock.json` covers `deployment-evidence.json` too, so recording evidence stays a diff a
 * human writes and somebody else reviews. A validator that could also record would make the review
 * optional on exactly the days it matters.
 *
 * WHAT IT ANSWERS, in one pass:
 *
 *   1. Is the registry well-formed against its own closed schema?
 *   2. Does it speak for THIS projection and THIS canonical pin?
 *   3. Which of the five evidence-backed operational claims does it actually support?
 *   4. Does the lock assert any of them WITHOUT that support?
 *
 * Question 4 is the one that fails the build. Evidence without a claim is ordinary — an operator
 * files a deployment record and the flag waits for review. A claim without evidence is the failure
 * this whole model exists to make impossible, and the foundation suite asserts it on every commit
 * so it cannot be introduced by an edit to a JSON file that looks harmless in a diff.
 *
 * THE FOUR AUTHORITY LOCKS ARE NOT ITS BUSINESS. `production_launch_authorized`,
 * `external_action_authorized`, `owner_go_granted` and `customer_visible_output_authorized` are
 * owner acts recorded upstream; no record collected on any host can support one, so this command
 * reports them and has no opinion beyond that.
 */

// CLI ONLY, refused BEFORE anything is loaded. `ops/` sits outside the served tree and the
// deployment-boundary test proves it; this guard is the second layer, for the deployment that
// mapped the wrong directory.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'evidence: ' . $message . PHP_EOL);
    exit($code);
};

$args = BetaCli::parseArgs($argv, ['evidence', 'lock'], $fail);

$root = dirname(__DIR__, 2);
$evidencePath = ($args['evidence'] ?? '') !== '' ? $args['evidence'] : $root . '/deployment-evidence.json';
$lockPath = ($args['lock'] ?? '') !== '' ? $args['lock'] : $root . '/upstream.lock.json';

if (!is_file($lockPath)) {
    $fail("no such lock file: {$lockPath}");
}

try {
    $lock = json_decode((string) file_get_contents($lockPath), true, 64, JSON_THROW_ON_ERROR);
} catch (\JsonException $error) {
    $fail('upstream.lock.json is not valid JSON: ' . $error->getMessage());
}
if (!is_array($lock)) {
    $fail('upstream.lock.json must be a JSON object');
}

$projectionId = (string) ($lock['projection_id'] ?? '');
$canonicalPin = (string) ($lock['projection_pinned_head'] ?? '');
if ($projectionId === '' || $canonicalPin === '') {
    $fail('upstream.lock.json declares no projection_id / projection_pinned_head to validate against');
}

$registry = DeploymentEvidence::loadFile($evidencePath, [
    'projection_id' => $projectionId,
    'canonical_pin' => $canonicalPin,
]);

/** @var array<string, mixed> $claimBoundary */
$claimBoundary = is_array($lock['claim_boundary'] ?? null) ? $lock['claim_boundary'] : [];
$unsupported = $registry->isValid() ? $registry->unsupportedClaims($claimBoundary) : [];

$locks = [];
foreach (DeploymentEvidence::PERMANENT_AUTHORITY_LOCKS as $key) {
    $locks[$key] = ($claimBoundary[$key] ?? null) === true;
}

// A COUNT PER KIND, NOT THE RECORDS THEMSELVES. The registry is in the repository and anybody who
// can run this can read it; what the verdict adds is the summary, and printing the rows back would
// only invite this output to be pasted somewhere as if it were the evidence.
$byKind = array_fill_keys(DeploymentEvidence::KINDS, 0);
foreach ($registry->records() as $record) {
    $byKind[(string) $record['kind']]++;
}

$status = match (true) {
    !$registry->isValid() => 'EVIDENCE_REGISTRY_INVALID',
    $unsupported !== [] => 'CLAIM_WITHOUT_EVIDENCE',
    default => 'EVIDENCE_REGISTRY_VALID',
};

BetaCli::emit(
    [
        'status' => $status,
        'projection_id' => $projectionId,
        'canonical_pin' => $canonicalPin,
        'evidence_records' => count($registry->records()),
        'records_by_kind' => $byKind,
        'problems' => $registry->problems(),
        'supported_claims' => $registry->supportedClaims(),
        'claimed_without_evidence' => $unsupported,
        'permanent_authority_locks' => $locks,
        // Restated on every run, because this command is the one an operator reaches for when they
        // are about to record something, and that is the moment to be reminded what a record is not.
        'grants_runtime_authority' => false,
        'activates_live_read' => false,
        'authorizes_deployment' => false,
    ],
    $status === 'EVIDENCE_REGISTRY_VALID' ? 0 : 1,
);
