<?php

declare(strict_types=1);

/**
 * The pre-secret canary is worthless by construction, and this is what keeps it that way.
 *
 * The canary exists to answer one question before `APP_MASTER_KEY` and the database password are
 * placed on Loopia: can the PHP running for one site in this account read another site's private
 * files? A tool that answered it by planting something real would be answering it by taking the
 * risk it was built to avoid — so the content is generated in the file, from the CSPRNG, and there
 * is no flag, no argument and no environment read that could substitute anything else.
 *
 * The second property is that PROBE MODE NEVER PRINTS WHAT IT FOUND. A verdict of READABLE against
 * a sibling's directory is the whole finding; the bytes add nothing and would make this the first
 * tool anybody reached for the day they wanted to read a file they should not. The emitted payload
 * is a closed set of keys, asserted here, and the content variable is not among them.
 */

use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$path = $root . '/ops/loopia-probe/isolation-canary.php';
$raw = (string) file_get_contents($path);

/** The canary's EXECUTABLE source. */
$code = implode('', array_map(
    static function (array|string $token): string {
        if (is_string($token)) {
            return $token;
        }

        return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
    },
    token_get_all($raw),
));

// ============================================================ CLI only, before anything loads

// This command takes a filesystem path from its caller and reads it. Reachable over the web it
// would be an arbitrary file-existence oracle against the whole host.
Assert::contains("if (PHP_SAPI !== 'cli') {", $code, 'the canary refuses any web SAPI');
Assert::true(
    strpos($code, "PHP_SAPI !== 'cli'") < strpos($code, 'require'),
    'and refuses BEFORE it loads the application — the bootstrap would already have read .env',
);

// ============================================================ the content cannot be a secret

Assert::contains(
    "const CANARY_MARKER = 'ALLFATHER_LOOPIA_NON_SECRET_CANARY_';",
    $code,
    'the canary marker is a fixed, non-secret literal',
);
Assert::contains(
    '$content = CANARY_MARKER . bin2hex(random_bytes(16)) . PHP_EOL;',
    $code,
    'and the content is that marker plus CSPRNG entropy, composed here',
);

// THE ONLY TWO ARGUMENTS. A `--content`, `--value` or `--from-file` flag is what would turn this
// into a tool that writes whatever it is handed, and `parseArgs` refuses an unknown flag rather
// than ignoring it — so the closed list IS the guarantee.
Assert::contains(
    "BetaCli::parseArgs(\$argv, ['mode', 'dir'], \$fail)",
    $code,
    'the canary accepts exactly --mode and --dir',
);
foreach (['content', 'value', 'from-file', 'text', 'payload', 'env'] as $absent) {
    Assert::false(
        str_contains($code, "'{$absent}'"),
        "there is no --{$absent} flag through which real content could arrive",
    );
}
// And it reads no configuration at all, so there is nothing for it to copy.
foreach (['Env::', 'Bootstrap::', 'Database::', 'getenv('] as $absent) {
    Assert::false(str_contains($code, $absent), "the canary reads no {$absent} — it has no secret to leak");
}
// Secret flag names are refused by name by the shared parser, not merely unknown.
Assert::true(
    in_array('password', BetaCli::REFUSED_SECRET_FLAGS, true),
    'the shared parser refuses a password flag by name, and this command uses it',
);

// ============================================================ probe mode prints no content

// The emitted payload is a closed set, and `$content` is not in it. A tool that returned the bytes
// would be the first thing reached for the day somebody wanted to read a file they should not.
Assert::false(
    str_contains($code, "'content' =>") || str_contains($code, "'canary_content' =>")
    || str_contains($code, "'bytes' =>"),
    'no content key is ever emitted',
);
Assert::contains(
    "\$emit('probe', \$resolved, 'READABLE', ['marker_matched' => str_starts_with(\$content, CANARY_MARKER)]);",
    $code,
    'a READABLE verdict reports only whether the marker matched, never the value',
);
// EVERY use of the read value inside an emitted result is that one comparison. The value reaches
// `is_string()` and `str_starts_with()` and nothing else — a report that carried it would make this
// the first tool anybody reached for the day they wanted to read a file they should not.
preg_match_all('/\$emit\([^;]*\$content[^;]*;/', $code, $emitUses);
Assert::same(1, count($emitUses[0]), 'the read value appears in exactly one emitted result');
foreach ($emitUses[0] as $use) {
    Assert::contains(
        'str_starts_with($content, CANARY_MARKER)',
        $use,
        'and only as the marker comparison, never as a value',
    );
}
Assert::false(str_contains($code, 'echo $content'), 'the content is never echoed');
Assert::false(str_contains($code, 'substr($content'), 'nor a prefix of it');
Assert::false(str_contains($code, 'strlen($content'), 'nor its length');

// THE VERDICT VOCABULARY IS CLOSED, and each value means one thing. NOT_FOUND and NOT_READABLE are
// both passes when probing a sibling — the boundary may hold by hiding the file or by refusing the
// read, and which one Loopia does is interesting rather than load-bearing.
foreach (['READABLE', 'NOT_READABLE', 'NOT_FOUND', 'CANARY_PLACED', 'CANARY_REMOVED'] as $verdict) {
    Assert::contains("'{$verdict}'", $code, "the verdict vocabulary includes {$verdict}");
}

// ============================================================ it stays out of the served tree

// A canary under `public_html/` would be fetchable over HTTP by anybody, so every probe against it
// would answer READABLE and the run would prove nothing while looking like it had proved something.
Assert::contains(
    "\$servedRoot = realpath(\$root . '/public_html');",
    $code,
    'the served directory is resolved',
);
Assert::contains(
    "str_starts_with(\$resolved . DIRECTORY_SEPARATOR, \$servedRoot . DIRECTORY_SEPARATOR)",
    $code,
    'and a target inside it is detected by resolved path rather than by string prefix of the input',
);
Assert::contains(
    "\$fail('refusing to place or probe a canary inside the served directory', 3);",
    $code,
    'and refused',
);
Assert::true(
    strpos($code, 'refusing to place or probe a canary inside the served directory')
        < strpos($code, "\$path = \$resolved . DIRECTORY_SEPARATOR . CANARY_FILENAME;"),
    'the refusal comes before the canary path is composed at all',
);

// ============================================================ it deletes only its own file

// `cleanup` unlinks one fixed name inside a directory that has already been resolved and screened.
// There is no glob, no recursion and no pattern — a cleanup that took a pattern would be a delete
// tool with a canary-shaped default.
Assert::contains('CANARY_FILENAME', $code, 'the canary file name is a constant');
Assert::same(1, substr_count($code, '@unlink($path)'), 'cleanup removes exactly that one path');
foreach (['glob(', 'rmdir(', 'scandir(', 'RecursiveDirectoryIterator', 'unlink($dir'] as $absent) {
    Assert::false(str_contains($code, $absent), "cleanup performs no {$absent} traversal");
}
// The temporary file is the only other thing it writes, and it is removed on the failure path too.
Assert::contains('@unlink($tmp);', $code, 'a failed placement removes its own temporary file');

// ============================================================ create does not clobber

// A second create would quietly replace the canary another site is currently probing for, turning a
// READABLE into a NOT_FOUND that reads as isolation.
Assert::contains(
    "\$fail('a canary already exists in that directory; run --mode=cleanup first', 5);",
    $code,
    'an existing canary is not overwritten',
);
Assert::contains('@rename($tmp, $path)', $code, 'the canary is placed by a same-directory rename');
Assert::contains('@chmod($tmp, 0o600);', $code, 'and is owner-readable only, so the test is of the boundary');

// ============================================================ the exit code claims nothing

// This command cannot know whether the directory it was pointed at belongs to the site running it.
// An exit code that guessed would either fail the control — a site reading its OWN canary — or pass
// the crossing that must stop the deployment.
Assert::contains(
    "'interpretation' =>",
    $code,
    'every result restates that a cross-site READABLE is a hard stop',
);
Assert::false(
    str_contains($code, 'BetaCli::emit([') && str_contains($code, '], 1);'),
    'no verdict is turned into a non-zero exit — the matrix is the operator\'s to read',
);
foreach ([
    "'grants_runtime_authority' => false",
    "'authorizes_deployment' => false",
    "'secret_written' => false",
] as $stated) {
    Assert::contains($stated, $code, "every result states {$stated}");
}

// ============================================================ the claim it makes is the narrow one

// WHAT THIS TOOL MEASURES IS THE SSH/CLI IDENTITY, and the whole gate is bounded by that. It runs
// under the CLI SAPI as the SSH user, so it reports that identity's filesystem permissions and
// open_basedir — not the identity or restrictions the WEB PHP process runs under for each site. On
// shared hosting the two routinely differ, and the difference runs both ways. `LOOPIA_HOST_MODEL.md`
// establishes no equivalence, so the scope travels in the OUTPUT rather than only in the docs: the
// one place a reader will look is the JSON they just pasted into a ticket.
preg_match('/\$emit = static function[^{]*\{(.*?)\n\};/s', $code, $emitBody);
Assert::same(2, count($emitBody), 'the emit closure is found');
foreach ([
    "'identity_measured' => 'SSH_CLI'",
    "'sapi' => PHP_SAPI",
    "'web_php_cross_site_isolation' => 'UNVERIFIED'",
] as $scoped) {
    Assert::contains($scoped, $emitBody[1], "every result carries {$scoped}");
}
Assert::contains(
    "'proves' => 'SSH/CLI cross-site filesystem isolation only'",
    $emitBody[1],
    'and states the bounded claim in those words, so a green matrix cannot be quoted as a wider one',
);
// And the interpretation refuses to be read as a clearance. A clean run closes SSH/CLI isolation and
// leaves web PHP isolation open — that second half is the half a reader in a hurry would drop.
Assert::contains('is NOT a clearance', $code, 'a clean run is stated not to be a clearance');
Assert::contains('web PHP isolation ', $code, 'and web PHP isolation is named as what stays open');

// ============================================================ an unreachable directory is a RESULT

// GETTING THIS BACKWARDS MADE GATE E IMPOSSIBLE TO COMPLETE IN EXACTLY THE CASE IT DETECTS. Under a
// restrictive open_basedir a sibling's private directory commonly cannot be resolved at all, so
// `realpath()` returns false BEFORE any read is attempted. Treating that as "no such directory" and
// exiting non-zero turned a host whose isolation was working perfectly into an operational error,
// and left the matrix completable only on a host where isolation had already failed.
Assert::contains(
    "\$emit('probe', \$dir, 'NOT_FOUND', ['directory_resolved' => false]);",
    $code,
    'in probe mode an unresolvable directory is NOT_FOUND, with the resolution failure reported',
);
Assert::contains(
    "if (\$mode !== 'probe') {\n        \$fail(\"no such directory: {\$dir}\", 4);\n    }",
    $code,
    'while create and cleanup still fail hard — those act on a directory the operator believes is theirs',
);
Assert::true(
    strpos($code, "'directory_resolved' => false") < strpos($code, "\$servedRoot = realpath(\$root . '/public_html');"),
    'and the probe answers before the served-tree screen, which needs a resolved path to run at all',
);
