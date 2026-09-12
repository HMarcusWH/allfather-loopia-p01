<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Http\ConsoleAssetManifest;

/**
 * Deterministic digest of the code a deployment is actually running.
 *
 * Runtime storage and configuration are deliberately excluded. The Operator Console is special:
 * the release protocol retains older content-hashed assets so already-served pages do not break.
 * The digest therefore commits to the CURRENT manifest and the files that manifest names, while a
 * separate verifier rejects unsafe unreferenced files. Historical safe hashes do not make two
 * otherwise-identical releases look different.
 */
final class SourceFingerprint
{
    /** @var list<string> */
    public const DIRECTORIES = ['app', 'database', 'public_html', 'worker'];

    public const NOT_AVAILABLE = 'NOT_AVAILABLE';

    private const CONSOLE_DIR = 'public_html/assets/operator-console';
    private const CONSOLE_PREFIX = '/assets/operator-console/';

    public static function compute(string $root): string
    {
        $inventory = self::inventory($root);
        if ($inventory === []) {
            return self::NOT_AVAILABLE;
        }

        $context = hash_init('sha256');
        foreach ($inventory as $relative => $digest) {
            hash_update($context, $relative . "\0" . $digest . "\n");
        }

        return hash_final($context);
    }

    /**
     * @return array<string, string> POSIX-relative path => sha256/unreadable, sorted by path.
     */
    public static function inventory(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        /** @var array<string, string> $files */
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            $base = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($base)) {
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
                $relative = self::relative($root, $file->getPathname());
                if (str_starts_with($relative, self::CONSOLE_DIR . '/')) {
                    // Current console files are added from the validated manifest below. This skips
                    // deliberately retained old hashes without skipping the current release.
                    continue;
                }
                self::addDigest($files, $relative, $file->getPathname());
            }
        }

        self::addCurrentConsole($root, $files);
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Unsafe entries in the public Operator Console stage that are not part of the current manifest.
     * Safe historical hashes are intentionally allowed because manifest-last deployment retains them.
     *
     * @return list<string>
     */
    public static function unexpectedConsoleEntries(string $root): array
    {
        $stage = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'operator-console';
        if (!is_dir($stage) || is_link($stage)) {
            return [];
        }

        try {
            $manifest = (new ConsoleAssetManifest($stage))->load();
        } catch (\Throwable) {
            // Manifest validity has its own stand-down finding. Do not manufacture a second list
            // from a manifest we cannot trust.
            return [];
        }

        $current = ['manifest.json' => true];
        foreach ([$manifest['script'], $manifest['style'], ...$manifest['assets']] as $url) {
            if (str_starts_with($url, self::CONSOLE_PREFIX)) {
                $current[substr($url, strlen(self::CONSOLE_PREFIX))] = true;
            }
        }

        $unsafe = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $relative = self::relative($stage, $entry->getPathname());
            if ($entry->isLink()) {
                $unsafe[] = $relative;
                continue;
            }
            if ($entry->isDir()) {
                if ($relative !== 'assets') {
                    $unsafe[] = $relative . '/';
                }
                continue;
            }
            if (!$entry->isFile() || isset($current[$relative])) {
                continue;
            }

            $safeHistorical = preg_match('/^console\.[A-Z0-9]+\.(?:js|css)$/D', $relative) === 1
                || preg_match('/^assets\/[A-Za-z0-9_-]+\.[A-Z0-9]+\.svg$/D', $relative) === 1;
            if (!$safeHistorical) {
                $unsafe[] = $relative;
            }
        }

        sort($unsafe, SORT_STRING);

        return array_values(array_unique($unsafe));
    }

    /** @param array<string, string> $files */
    private static function addCurrentConsole(string $root, array &$files): void
    {
        $stage = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'operator-console';
        $manifestPath = $stage . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath)) {
            return;
        }

        self::addDigest($files, self::CONSOLE_DIR . '/manifest.json', $manifestPath);

        try {
            $manifest = (new ConsoleAssetManifest($stage))->load();
        } catch (\Throwable) {
            return;
        }

        foreach ([$manifest['script'], $manifest['style'], ...$manifest['assets']] as $url) {
            if (!str_starts_with($url, self::CONSOLE_PREFIX)) {
                continue;
            }
            $relativeConsole = substr($url, strlen(self::CONSOLE_PREFIX));
            $path = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeConsole);
            self::addDigest($files, self::CONSOLE_DIR . '/' . $relativeConsole, $path);
        }
    }

    /** @param array<string, string> $files */
    private static function addDigest(array &$files, string $relative, string $path): void
    {
        $digest = @hash_file('sha256', $path);
        $files[$relative] = is_string($digest) ? $digest : 'unreadable';
    }

    /** Platform-independent key: checkout on Windows and host on Linux must hash identically. */
    private static function relative(string $root, string $path): string
    {
        $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);

        return str_replace('\\', '/', str_replace(DIRECTORY_SEPARATOR, '/', $relative));
    }
}
