<?php

declare(strict_types=1);

/**
 * Pre-secret cross-site isolation canary.
 *
 * THE QUESTION IT ANSWERS, and the order matters more than the mechanism: on Loopia, can the PHP
 * running for one site in this account read the private files of another site in the same account?
 *
 * That question has to be answered BEFORE `APP_MASTER_KEY` and the database password are placed on
 * the host, because the cost of answering it afterwards is that the answer arrives too late to act
 * on. A secret that turned out to be readable from a sibling site is a secret that has already been
 * exposed for however long it took to find out.
 *
 * So this tool writes files that are worthless. A canary is the literal string
 * `ALLFATHER_LOOPIA_NON_SECRET_CANARY_<random hex>` and nothing else: there is no flag, no argument
 * and no environment read that could put a real credential in one. A reader who obtains a canary
 * learns that the boundary is broken and learns nothing else, which is the entire design.
 *
 * Usage:
 *   php ops/loopia-probe/isolation-canary.php --mode=create  --dir=<private directory>
 *   php ops/loopia-probe/isolation-canary.php --mode=probe   --dir=<private directory>
 *   php ops/loopia-probe/isolation-canary.php --mode=cleanup --dir=<private directory>
 *
 * `create` is run once per site home, as that site. `probe` is then run as EACH site against EVERY
 * directory, including its own — a probe that only tested the crossing would not distinguish "the
 * boundary holds" from "the canary was never written".
 *
 * The target matrix and what each cell must be is in `ops/loopia-probe/README.md`. The rule that
 * matters: any cross-site private read is a HARD STOP, before any secret goes near the host.
 *
 * PROBE MODE NEVER PRINTS CONTENT. It returns READABLE, NOT_READABLE or NOT_FOUND. Even a
 * worthless file is a file, and a tool that printed what it found would be a tool somebody
 * eventually points at something that is not worthless.
 */

// CLI ONLY, refused BEFORE anything is loaded — including the application. This command takes a
// filesystem path from its caller and reads it; reachable over the web it would be an arbitrary
// file-existence oracle. The guard runs before the autoloader for the same reason the other ops
// commands run theirs there: a bootstrap that loaded first would already have read `.env`.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Ops\BetaCli;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'isolation-canary: ' . $message . PHP_EOL);
    exit($code);
};

/** The canary file name. Fixed, so every site looks for the same thing in every directory. */
const CANARY_FILENAME = 'allfather-loopia-canary.txt';

/** The only content a canary may have. Generated here; there is no way to supply one. */
const CANARY_MARKER = 'ALLFATHER_LOOPIA_NON_SECRET_CANARY_';

$modes = ['create', 'probe', 'cleanup'];

$args = BetaCli::parseArgs($argv, ['mode', 'dir'], $fail);

$mode = $args['mode'] ?? '';
if (!in_array($mode, $modes, true)) {
    $fail('--mode is required and must be one of: ' . implode(', ', $modes));
}
$dir = $args['dir'] ?? '';
if ($dir === '' || $dir === '1') {
    $fail('--dir=<private directory> is required');
}
if (str_contains($dir, "\0")) {
    $fail('--dir contains a null byte');
}

$resolved = realpath($dir);
if ($resolved === false || !is_dir($resolved)) {
    $fail("no such directory: {$dir}", 4);
}

// A CANARY NEVER GOES IN THE SERVED TREE. One placed under `public_html/` would be fetchable over
// HTTP by anybody, so every probe against it would answer READABLE and the run would prove nothing
// while looking like it had proved something.
$root = dirname(__DIR__, 2);
$servedRoot = realpath($root . '/public_html');
if (is_string($servedRoot) && str_starts_with($resolved . DIRECTORY_SEPARATOR, $servedRoot . DIRECTORY_SEPARATOR)) {
    $fail('refusing to place or probe a canary inside the served directory', 3);
}

$path = $resolved . DIRECTORY_SEPARATOR . CANARY_FILENAME;

/**
 * The verdict vocabulary. Closed, and each value means one thing.
 *
 * A SUCCESSFUL PROBE ALWAYS EXITS 0, INCLUDING `READABLE`. This command cannot know whether the
 * directory it was pointed at belongs to the site running it — that is the operator's matrix to
 * fill in, and only they know which pairing they just tested. An exit code that guessed would
 * either fail the run that proves a site can read its OWN canary, which is the control, or pass the
 * crossing that must stop the deployment. A non-zero exit here means the probe could not be
 * performed, never that the boundary held or did not.
 */
$emit = static function (string $mode, string $directory, string $verdict, array $extra = []): never {
    BetaCli::emit([
        'probe' => 'CROSS_SITE_ISOLATION_CANARY',
        'mode' => $mode,
        'directory' => $directory,
        'canary_file' => CANARY_FILENAME,
        'verdict' => $verdict,
        ...$extra,
        // Restated on every run. A canary proves isolation; it grants nothing and authorizes
        // nothing, and reading one from a sibling site is a stop, not a finding to note and move on.
        'interpretation' => 'A READABLE verdict against ANOTHER site\'s private directory is a hard '
            . 'stop: do not place APP_MASTER_KEY or database credentials on this host.',
        'grants_runtime_authority' => false,
        'authorizes_deployment' => false,
        'secret_written' => false,
    ]);
};

if ($mode === 'create') {
    if (is_file($path)) {
        // NOT CLOBBERED. If a canary is already there, a second create would quietly replace the
        // one another site is currently probing for and turn a READABLE into a NOT_FOUND that
        // reads as isolation.
        $fail('a canary already exists in that directory; run --mode=cleanup first', 5);
    }
    if (file_exists($path)) {
        $fail('something that is not a regular file occupies the canary path', 5);
    }
    if (!is_writable($resolved)) {
        $fail("directory is not writable: {$dir}", 4);
    }

    $content = CANARY_MARKER . bin2hex(random_bytes(16)) . PHP_EOL;
    // Written through a same-directory temporary name and renamed, the same discipline the evidence
    // store uses: a probing site must never read a half-written canary and call it NOT_READABLE.
    $tmp = $resolved . DIRECTORY_SEPARATOR . '.canary-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content) === false) {
        $fail('could not write the canary', 4);
    }
    @chmod($tmp, 0o600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        $fail('could not place the canary', 4);
    }

    $emit('create', $resolved, 'CANARY_PLACED', ['mode_bits' => substr(sprintf('%o', fileperms($path)), -4)]);
}

if ($mode === 'cleanup') {
    if (!file_exists($path)) {
        $emit('cleanup', $resolved, 'NOT_FOUND');
    }
    if (!@unlink($path)) {
        $fail('could not remove the canary', 4);
    }

    $emit('cleanup', $resolved, 'CANARY_REMOVED');
}

// ---------------------------------------------------------------------------- probe
//
// THREE OUTCOMES, AND NO CONTENT. `NOT_FOUND` and `NOT_READABLE` are both passes when probing
// another site — the boundary can hold by hiding the file or by refusing the read, and which one
// Loopia does is interesting but not load-bearing. `READABLE` against another site's directory is
// the stop.

if (!file_exists($path)) {
    // `file_exists` returns false under a restrictive open_basedir as well as for an absent file,
    // and the two are deliberately not distinguished: both mean this site could not reach it.
    $emit('probe', $resolved, 'NOT_FOUND');
}
if (!is_readable($path)) {
    $emit('probe', $resolved, 'NOT_READABLE');
}

$content = @file_get_contents($path);
if (!is_string($content)) {
    $emit('probe', $resolved, 'NOT_READABLE');
}

// The MARKER is reported, never the canary. Knowing the file that was read is one of ours separates
// "this site reached a sibling's canary" from "this site read some unrelated file of its own that
// happens to sit at that path".
$emit('probe', $resolved, 'READABLE', ['marker_matched' => str_starts_with($content, CANARY_MARKER)]);
