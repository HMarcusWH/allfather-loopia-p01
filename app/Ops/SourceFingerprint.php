<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * A digest of the code a deployment is actually running.
 *
 * WHY THIS EXISTS. `APP_RELEASE_REF` is a line in `.env` that an operator maintains by hand. It says
 * which release somebody INTENDED to deploy, and a rollback is two separate steps — restore the
 * code, update the configuration. If the restore fails or is skipped while the configuration step
 * succeeds, `APP_RELEASE_REF` equals the release you asked for and the old code is still serving.
 * Every other signal an operator has looks healthy: a valid console manifest from the previous
 * build, an unchanged scheduler posture, a ledger that verifies. So a verifier that read
 * `APP_RELEASE_REF` and called that proof of the running code was reporting the operator's
 * intention back to them under the name of a measurement.
 *
 * This is a measurement. It hashes the bytes on disk.
 *
 * WHAT IT COVERS, and why exactly these four directories: they are what the web server serves and
 * what the runtime loads. `storage/` is excluded because it is runtime state that changes on every
 * request and would make the digest differ from itself a second later; `.env` because it is
 * per-deployment configuration, not code, and must never be hashed into a value that travels in a
 * report. `tests/`, `frontend/` and `docs/` are excluded because the deployment profile decides
 * whether they are copied at all — a digest that changed with the profile could not be compared
 * between the checkout it was computed on and the host it is checked against.
 *
 * WHAT IT IS NOT. It is not a signature and not a tamper seal: anyone who can change the files can
 * recompute it. It answers one question — is the code here the code that was preflighted — and an
 * attacker with write access to the tree was never in the threat model this answers.
 */
final class SourceFingerprint
{
    /**
     * The directories that constitute the running deployment.
     *
     * @var list<string>
     */
    public const DIRECTORIES = ['app', 'database', 'public_html', 'worker'];

    /** The digest of a tree that has none of those directories — refused rather than reported. */
    public const NOT_AVAILABLE = 'NOT_AVAILABLE';

    /**
     * The digest of the tree at `$root`, or `NOT_AVAILABLE` when it holds no application at all.
     *
     * DETERMINISTIC, so two machines agree. Paths are collected relative to the root and sorted as
     * byte strings before anything is hashed — directory iteration order is a property of the
     * filesystem, and a digest that depended on it would differ between the checkout and the host
     * for two trees that are identical.
     */
    public static function compute(string $root): string
    {
        $inventory = self::inventory($root);
        if ($inventory === []) {
            return self::NOT_AVAILABLE;
        }

        $context = hash_init('sha256');
        foreach ($inventory as $relative => $digest) {
            // NUL and newline as separators, neither of which a path may contain, so no pair of
            // different inventories can serialize to the same byte string.
            hash_update($context, $relative . "\0" . $digest . "\n");
        }

        return hash_final($context);
    }

    /**
     * Every file that counts, as `relative path => sha256`, sorted by path.
     *
     * A symlink is followed for its content like any other file. It is not treated as a special
     * case because the deployment layout forbids one in the served tree and
     * `tests/foundation/deployment_boundary.php` is what enforces that; this function measures, it
     * does not police.
     *
     * @return array<string, string>
     */
    public static function inventory(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        /** @var array<string, string> $files */
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            $base = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($base)) {
                // A profile that does not carry this directory is not an error here. The digest
                // simply describes what is present, and a mismatch is what the comparison reports.
                continue;
            }

            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($walk as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $relative = substr($path, strlen($root) + 1);
                $digest = @hash_file('sha256', $path);
                if (!is_string($digest)) {
                    // An unreadable file is recorded as unreadable rather than skipped. Skipping it
                    // would let a file that cannot be read produce the same digest as one that is
                    // not there, and those are different deployments.
                    $digest = 'unreadable';
                }
                $files[$relative] = $digest;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
