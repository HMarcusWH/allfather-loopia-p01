<?php

declare(strict_types=1);

/**
 * The web-PHP cross-site isolation blocker is a non-waivable pre-secret gate.
 *
 * The CLI canary deliberately proves only the SSH/CLI identity. An earlier revision of PR8 was
 * honest about that limitation but then let an explicit owner risk acceptance stand in for the
 * missing web-PHP proof. That turns UNVERIFIED into a procedural override and defeats the point of
 * asking the question before APP_MASTER_KEY and DB_PASSWORD are placed on a shared host.
 *
 * This test pins the repository's three normative documents to one rule:
 *
 *   WEB_PHP_ISOLATION_REQUIRED_BEFORE_SECRETS
 *   WEB_PHP_ISOLATION_WAIVER_ALLOWED = false
 *
 * Closure is evidence, not authority: written Loopia assurance or an equivalent host proof of the
 * web-PHP identity. A clean SSH/CLI matrix, a clean HTTP private-path probe, or owner/operator risk
 * acceptance cannot satisfy the blocker.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$beta = (string) file_get_contents($root . '/docs/BETA_RUNBOOK.md');
$host = (string) file_get_contents($root . '/docs/LOOPIA_HOST_MODEL.md');
$security = (string) file_get_contents($root . '/docs/SECURITY_BOUNDARY.md');
$canary = (string) file_get_contents($root . '/ops/loopia-probe/isolation-canary.php');

foreach ([
    'BETA_RUNBOOK.md' => $beta,
    'LOOPIA_HOST_MODEL.md' => $host,
    'SECURITY_BOUNDARY.md' => $security,
] as $name => $document) {
    Assert::contains(
        'LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER',
        $document,
        "{$name} names the web-PHP isolation blocker rather than treating the CLI canary as enough",
    );
    Assert::contains(
        'WEB_PHP_ISOLATION_REQUIRED_BEFORE_SECRETS',
        $document,
        "{$name} makes web-PHP isolation a prerequisite to secret placement",
    );
    Assert::contains(
        'WEB_PHP_ISOLATION_WAIVER_ALLOWED = false',
        $document,
        "{$name} states that the blocker has no owner/operator waiver",
    );
}

Assert::contains(
    'There are exactly two',
    $beta,
    'the operational runbook has exactly two accepted closure routes',
);
Assert::contains(
    'Loopia states it in writing',
    $beta,
    'written provider assurance is one accepted closure route',
);
Assert::contains(
    'A host proof of the web identity',
    $beta,
    'an equivalent web-PHP host proof is the other accepted closure route',
);
Assert::contains(
    'There is no waiver path.',
    $beta,
    'Gate E.3 explicitly refuses a procedural waiver',
);
Assert::contains(
    'Gate E is BLOCKED and Gate F is NOT PERMITTED',
    $beta,
    'an unresolved blocker stops the sequence before secrets are installed',
);

foreach ([
    '**The owner accepts the risk, explicitly**',
    'The third route is a decision',
    'explicit recorded owner decision to accept the risk',
] as $oldEscapeHatch) {
    Assert::false(
        str_contains($beta, $oldEscapeHatch),
        "the previous Gate E owner-waiver path is gone: {$oldEscapeHatch}",
    );
}

// The instrument remains deliberately narrower than the gate. If this ever starts claiming that
// it proves the web identity, the documentation above would be enforcing a conclusion the tool had
// silently started manufacturing itself.
Assert::contains(
    "'identity_measured' => 'SSH_CLI'",
    $canary,
    'the canary still states which identity it actually measured',
);
Assert::contains(
    "'web_php_cross_site_isolation' => 'UNVERIFIED'",
    $canary,
    'and still refuses to infer web-PHP isolation from a CLI run',
);
