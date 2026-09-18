<?php

declare(strict_types=1);

/**
 * Gate E.2 must be a MECHANICALLY ENFORCED check, not a transcript of status codes for an operator
 * to read. A real-host run against staging.allfatherbi.com (2026-09-18) answered 503 — neither 403
 * nor 404 — for every private path, and a loose `curl` loop that only prints codes is exactly the
 * artifact a skim reads as "some numbers, looks fine" instead of catching that.
 *
 * `ops/deploy/verify-e2-private-path-refusal.sh` is executed here, not merely grepped: its
 * `--self-test` mode exercises the classifier against network-free cases and exits non-zero if any
 * disagrees, so this assertion actually RUNS the logic Gate E.2 depends on rather than trusting that
 * the source text still says the right thing.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$script = $root . '/ops/deploy/verify-e2-private-path-refusal.sh';

Assert::true(is_file($script), 'the Gate E.2 script exists');

$syntaxOutput = [];
$syntaxExit = -1;
exec('bash -n ' . escapeshellarg($script) . ' 2>&1', $syntaxOutput, $syntaxExit);
Assert::same(0, $syntaxExit, 'the script is syntactically valid bash: ' . implode("\n", $syntaxOutput));

$selfTestOutput = [];
$selfTestExit = -1;
exec('bash ' . escapeshellarg($script) . ' --self-test 2>&1', $selfTestOutput, $selfTestExit);
Assert::same(
    0,
    $selfTestExit,
    "the self-test classifier passes all cases with no network access:\n" . implode("\n", $selfTestOutput),
);

// A no-argument invocation refuses rather than probing an unspecified host.
$noArgsOutput = [];
$noArgsExit = -1;
exec('bash ' . escapeshellarg($script) . ' 2>&1', $noArgsOutput, $noArgsExit);
Assert::notSame(0, $noArgsExit, 'invoking the script with no arguments is refused, not silently accepted');

// An http:// base URL is refused rather than upgraded — the same posture `ops/staging-smoke.php`
// documents for its own client, checked here by actually invoking the refusal rather than reading it.
$httpOutput = [];
$httpExit = -1;
exec('bash ' . escapeshellarg($script) . ' --base-url=http://insecure.example 2>&1', $httpOutput, $httpExit);
Assert::notSame(0, $httpExit, 'a plain-HTTP --base-url is refused');
Assert::true(
    (bool) preg_grep('/https/i', $httpOutput),
    'and the refusal names the requirement rather than failing silently',
);

// ---------------------------------------------------------------- the source itself, structurally

$code = (string) file_get_contents($script);
// Comments are stripped for the flag checks below, the same way `no_external_action_surface.php`
// strips PHP comments — this file's own header PROSE says "no `-L`/`--location`", and a bare
// substring check against the raw text would trip on the very sentence documenting the absence.
$codeNoComments = (string) preg_replace('/^\s*#.*$/m', '', $code);

Assert::contains('set -euo pipefail', $code, 'the script fails loudly on any unexpected error');
Assert::contains(
    '--max-redirs 0',
    $code,
    'a redirect is inspected, never followed — explicit even though no -L flag exists to do so',
);
Assert::false(str_contains($codeNoComments, ' -L '), 'and no executable line carries --location/-L');
Assert::false(str_contains($codeNoComments, '--location'), 'confirmed by name as well as by short flag');

// THE HOST IS A PARSED COMPONENT, NEVER A STRING MATCH. An earlier revision anchored a regex on the
// raw Location (`^https://roadblock\.loopia\.com(/.*)?$`), and REJECTED the actual observed
// redirect — `https://roadblock.loopia.com?id=430057&host=staging.allfatherbi.com` has a `?`
// immediately after the host, not a `/`, so the anchor's path alternative never matched. That
// pattern must never come back.
Assert::false(
    str_contains($code, 'ROADBLOCK_HOST_PATTERN'),
    'the string-anchored regex host check is gone — it rejected the real observed redirect',
);
Assert::false(
    str_contains($code, '=~ $ROADBLOCK'),
    'no bash regex match against the raw Location remains anywhere',
);
Assert::contains(
    "ROADBLOCK_HOST='roadblock.loopia.com'",
    $code,
    'the one accepted host is a named constant, compared as a component, not embedded in a pattern',
);
Assert::contains(
    'parse_url($argv[1] ?? "")',
    $code,
    'the Location is parsed into components by a tested URL parser, not by hand-rolled regex',
);
Assert::contains('$scheme === "https"', $code, 'scheme is checked as an exact parsed component');
Assert::contains(
    '$host === $argv[2]',
    $code,
    'host is checked as an exact parsed component (equality), never a substring or prefix of the Location',
);
Assert::contains(
    '$port === null || $port === 443',
    $code,
    'port is checked too: absent (default) or exactly 443 — a non-default port is a different endpoint',
);

Assert::contains(
    'if [[ "$status" == "403" || "$status" == "404" ]]; then',
    $code,
    '403 and 404 are the only unconditionally accepted outcomes',
);
Assert::contains(
    'if [[ "$path" == "/.env" && "$status" == "302" ]] && is_exact_roadblock_redirect "$location"; then',
    $code,
    'the redirect exception requires the exact path, exactly 302, AND the parsed-component host check together',
);
Assert::contains('echo FAIL', $code, 'every other combination falls through to FAIL');

// THE ACTUAL OBSERVED REDIRECT must be a self-test PASS case, verbatim, so this exact regression
// cannot recur unnoticed.
Assert::contains(
    "check 302 'https://roadblock.loopia.com?id=430057&host=staging.allfatherbi.com' /.env PASS",
    $code,
    'the real-host redirect (query string, no path) is exercised as a PASS case',
);
foreach ([
    "check 302 'https://roadblock.loopia.com.evil.example/' /.env FAIL" => 'a suffixed look-alike host',
    "check 302 'https://roadblock.loopia.com@evil.example/' /.env FAIL" => 'userinfo placed before the real (different) host',
    "check 302 'https://evil.example/?next=https://roadblock.loopia.com' /.env FAIL" => 'the right host named only in a query string of a different origin',
    "check 302 '//roadblock.loopia.com/' /.env FAIL" => 'a scheme-relative Location, which parses to no scheme at all',
    "check 302 'https://roadblock.loopia.com:444/' /.env FAIL" => 'a non-default port on the right host and scheme',
] as $needle => $why) {
    Assert::contains($needle, $code, "self-test covers: {$why}");
}

Assert::contains(
    "! \"\$base_url\" =~ ^https://",
    $code,
    '--base-url is refused unless it is https://, before any request is made',
);

foreach ([
    '/.env', '/app/autoload.php', '/database/migrations/001_identity_scope.sql', '/storage/request.log',
    '/worker/tick.php', '/ops/migrate.php', '/frontend/console/package.json', '/tests/run.php',
    '/docs/SECURITY_BOUNDARY.md', '/composer.json', '/upstream.lock.json', '/deployment-evidence.json',
    '/BOOTSTRAP.md', '/PROJECT_BOUNDARY.md', '/UPSTREAM_AUTHORITY.md', '/README.md',
] as $probed) {
    Assert::contains($probed, $code, "the probe list carries {$probed}, kept aligned with ops/staging-smoke.php's list");
}

// Both runbooks and the probe README defer to this one script rather than each carrying their own
// copy of the private-path list, which is what let the list drift out of sync with the incident in
// the first place.
foreach ([
    $root . '/docs/BETA_RUNBOOK.md',
    $root . '/docs/DEPLOYMENT_RUNBOOK.md',
    $root . '/ops/loopia-probe/README.md',
] as $doc) {
    Assert::contains(
        'ops/deploy/verify-e2-private-path-refusal.sh',
        (string) file_get_contents($doc),
        basename($doc) . ' points operators at the enforced script rather than a loose curl loop',
    );
}
