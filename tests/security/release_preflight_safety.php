<?php

declare(strict_types=1);

/**
 * What the release preflight may read, and what it may bless.
 *
 * This command is handed the path to the private configuration a release will run with — the
 * database password and `APP_MASTER_KEY` — and its answer is the thing an operator acts on when
 * they decide to copy a release to a host. Two failure shapes follow from that, and both are
 * asserted here rather than reviewed.
 *
 *   IT MUST NOT READ A CONFIGURATION FILE THAT IS PUBLICLY SERVED. A `.env` beneath any
 *   `public_html/` is a published secret. A preflight that loaded one, validated the key inside it
 *   and answered READY would be certifying a release whose credentials Apache hands to anybody.
 *
 *   IT MUST NOT ANSWER READY ABOUT A TREE IT COULD NOT IDENTIFY. The release-ref comparison is the
 *   one check that catches an operator rsyncing the wrong checkout, and a check that quietly opts
 *   out on a layout it does not recognise is worse than no check: it reports a clean preflight for
 *   the tree it declined to look at.
 *
 * It prints no secret and takes no external action; those properties are asserted at the end.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$raw = (string) file_get_contents($root . '/ops/deploy/preflight.php');

/** The command's EXECUTABLE source. Its prose names things this file forbids in code. */
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

Assert::contains("if (PHP_SAPI !== 'cli') {", $code, 'the preflight refuses any web SAPI');
Assert::true(
    strpos($code, "PHP_SAPI !== 'cli'") < strpos($code, 'require'),
    'and refuses BEFORE it loads the application — this command reads private configuration',
);

// ============================================================ staging is the only mode

// A production mode would be the first half of a deployment path nobody has granted, and the
// pinned canonical identity vocabulary defines no production environment class to deploy into.
Assert::contains(
    "if (\$mode !== 'staging') {",
    $code,
    'staging is the only accepted mode',
);
Assert::false(str_contains($code, "'production' =>"), 'there is no production branch');
Assert::false(str_contains($code, "\$mode === 'production'"), 'and no production mode is recognised');

// ============================================================ a served configuration file is refused

// `--env=public_html/assets/staging.env` passed every other check this command makes: the layout
// check permits `assets/`, and the private-file checks look for a few fixed names directly under
// `public_html/`. The command would then load the file, validate the master key and the database
// password inside it, and answer READY_FOR_INERT_STAGING_COPY about a release whose credentials are
// served as a static asset.
Assert::contains('$envReal = realpath($envPath);', $code, 'the configuration path is resolved');
Assert::contains(
    "\$servedRoot = realpath(\$root . '/public_html');",
    $code,
    'and so is this release\'s served root',
);
Assert::contains(
    'str_starts_with($envReal, $servedRoot . DIRECTORY_SEPARATOR)',
    $code,
    'and the comparison is of RESOLVED paths, so a symlink or a `..` cannot walk into the served tree',
);
// AND ANY `public_html` SEGMENT, because on shared hosting every site in the account is
// `~/<domain>/public_html` and this command can resolve only its own.
Assert::contains(
    "in_array('public_html', explode(DIRECTORY_SEPARATOR, \$envReal), true)",
    $code,
    'a configuration file beneath any public_html is refused, not only this release\'s',
);
// IT IS A REFUSAL, NOT A RECORDED PROBLEM. A NOT_READY verdict would be a report about a secret
// that is currently being served, and it would be a report this command produced by reading it.
foreach ([
    'refusing to read a configuration file inside the served directory',
    'refusing to read a configuration file beneath a public_html directory',
] as $refusal) {
    Assert::contains($refusal, $code, "it exits rather than noting a problem: {$refusal}");
}
Assert::true(
    strpos($code, 'refusing to read a configuration file inside the served directory')
        < strpos($code, 'Env::load($envPath)'),
    'and it refuses BEFORE the file is loaded — the point is not to read it',
);

// ============================================================ a bare origin, or nothing

// `https://staging.example.test/prefix` has a valid scheme and host, no query, no fragment and no
// trailing slash, so every other check here passed it — while every path in the application is
// appended to it verbatim. The smoke client already refuses one; a preflight that did not was the
// looser of the two gates.
Assert::contains("if ((\$parts['path'] ?? '') !== '') {", $code, 'a base URL carrying a path is refused');
Assert::contains(
    'APP_BASE_URL must be a bare origin',
    $code,
    'and named as what it is, so the operator knows what to change',
);

// ============================================================ the tree is identified, or said not to be

// In a linked worktree or a submodule, `.git` is a TEXT FILE reading `gitdir: <path>`. Without
// following it this check answered NOT_AVAILABLE and skipped the comparison — so an operator could
// name the intended SHA in both `--release-ref` and `APP_RELEASE_REF` while rsyncing a checkout at
// some other commit, and preflight still said READY.
Assert::contains("str_starts_with(\$pointer, 'gitdir: ')", $code, 'a .git FILE is followed to the real git directory');
Assert::contains("if (is_dir(\$gitPath)) {", $code, 'an ordinary clone still resolves through the directory');
// A worktree keeps HEAD in its own gitdir, but refs and `packed-refs` live in the COMMON directory.
Assert::contains("\$commonFile = \$resolvedGitDir . '/commondir';", $code, 'and the common directory is followed too');
Assert::contains(
    "foreach ([\$gitDir . '/' . \$ref, \$commonDir . '/' . \$ref] as \$candidate) {",
    $code,
    'a branch ref is looked for in the worktree and then in the common directory',
);
Assert::contains(
    "\$packed = @file_get_contents(\$commonDir . '/packed-refs');",
    $code,
    'and packed-refs is read from the common directory, where a worktree has none of its own',
);
// STILL ADVISORY. `.git` is not part of a deployment, and this command is expected to run both from
// a checkout and on a host. NOT_AVAILABLE is honest; refusing would make it unusable on the host.
Assert::contains("\$gitHead = 'NOT_AVAILABLE';", $code, 'an unidentifiable tree is reported as such');
Assert::contains(
    "if (\$gitHead !== 'NOT_AVAILABLE' && \$gitHead !== \$releaseRef) {",
    $code,
    'and only a resolved head is compared',
);

// ============================================================ it prints no secret and does nothing

// `APP_MASTER_KEY` is validated by DECODING it; the value, its length and its prefix never appear.
Assert::contains("'db_password' => \$env->has('DB_PASSWORD') ? 'SET' : 'MISSING'", $code, 'the password is reported as a state');
Assert::false(str_contains($code, "\$env->get('DB_PASSWORD')"), 'and its value is never read');
Assert::false(str_contains($code, "\$env->get('APP_MASTER_KEY')") && str_contains($code, "'app_master_key' => \$env->get"), 'nor the master key rendered');
Assert::contains(
    'KeyRing::fromEnv($env)',
    $code,
    'preflight validates the same legacy-or-versioned keyring grammar as runtime',
);
Assert::false(
    str_contains($code, 'SecretBox::fromMasterKey'),
    'preflight has no legacy-only master-key validation path',
);
foreach (['curl_init(', 'fsockopen(', 'file_put_contents(', 'rename(', 'unlink(', 'Database::'] as $absent) {
    Assert::false(str_contains($code, $absent), "the preflight performs no {$absent} — it is not a deployer");
}
foreach ([
    "'live_read' => false",
    "'scheduler_handlers' => false",
    "'external_action' => false",
] as $stated) {
    Assert::contains($stated, $code, "the report states {$stated}");
}

// ============================================================ no password, no readiness

// `DB_PASSWORD` was reported as SET/MISSING at the bottom of the output and checked nowhere, so a
// staging configuration with no password — absent, or present and blank, which `Env::has()` treats
// alike — exited 0 with READY_FOR_INERT_STAGING_COPY. The deployment would then connect with an
// empty password and be refused by Loopia's MariaDB: a failure to meet before the copy, not after.
Assert::contains(
    "foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD'] as \$key) {",
    $code,
    'the database password is a readiness check, not only a reported state',
);
// AND STILL NEVER READ. Presence only; the report says SET or MISSING and nothing else.
Assert::false(str_contains($code, "\$env->get('DB_PASSWORD')"), 'and its value is still never read');

// ============================================================ the digest of what is being copied

// `APP_RELEASE_REF` is a line an operator maintains by hand. Preflight prints a digest of the code
// itself so that after the copy `ops/rollback/verify-stand-down.php --expect-fingerprint=` can
// answer what is RUNNING rather than what `.env` says should be.
Assert::contains(
    "'source_fingerprint' => SourceFingerprint::compute(\$root),",
    $code,
    'preflight prints a digest of the tree it is validating',
);
Assert::contains(
    "'source_fingerprint_covers' => SourceFingerprint::DIRECTORIES,",
    $code,
    'and says which directories it covers, so the two ends of the comparison cannot disagree silently',
);
