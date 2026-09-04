<?php

declare(strict_types=1);

/**
 * Structural proof that no external-action surface exists in this repository.
 *
 * This is not a style check. The projection's central claim is that D/E action classes are
 * UNREACHABLE from this codebase — not merely unused, not merely gated behind a flag. A grep-level
 * tripwire is the cheapest way to keep that true across future edits, because the way this
 * boundary actually breaks is somebody adding one convenient `mail()` call.
 *
 * The egress guard's cURL transport is the single allowed network leaf; it performs GET/HEAD only
 * and is asserted separately in `tests/security/`.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);

/** @return list<string> */
$phpFiles = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
};

$sources = [
    ...$phpFiles($root . '/app'),
    ...$phpFiles($root . '/public'),
    ...$phpFiles($root . '/worker'),
];

// Functions that would give this codebase a real external side effect.
$forbidden = [
    'mail(' => 'PHP mail() — email send is P07-B, which is NOT authorized',
    'imap_' => 'IMAP — external mail transport',
    'ftp_' => 'FTP — external file transport',
    'fsockopen(' => 'raw socket — bypasses the governed egress guard',
    'pfsockopen(' => 'raw persistent socket — bypasses the governed egress guard',
    'stream_socket_client(' => 'raw socket client — bypasses the governed egress guard',
    'shell_exec(' => 'shell execution',
    'proc_open(' => 'process execution',
    'passthru(' => 'process execution',
    'popen(' => 'process execution',
];

foreach ($forbidden as $needle => $why) {
    $hits = [];
    foreach ($sources as $file) {
        $contents = (string) file_get_contents($file);
        // Strip comments so the prohibition can be *documented* in prose without tripping itself.
        $stripped = implode('', array_map(
            static function (array|string $token): string {
                if (is_string($token)) {
                    return $token;
                }

                return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
            },
            token_get_all($contents),
        ));
        if (str_contains($stripped, $needle)) {
            $hits[] = str_replace($root . '/', '', $file);
        }
    }
    Assert::same([], $hits, "no external-action surface: {$needle} ({$why})");
}

// `curl_exec` is permitted only in the one reviewed transport leaf.
$curlUsers = [];
foreach ($sources as $file) {
    if (str_contains((string) file_get_contents($file), 'curl_exec(')) {
        $curlUsers[] = str_replace($root . '/', '', $file);
    }
}
Assert::true(
    count($curlUsers) <= 1,
    'curl_exec appears in at most one file (the reviewed transport leaf); found: ' . implode(', ', $curlUsers),
);
foreach ($curlUsers as $file) {
    Assert::true(
        str_contains($file, 'Ingestion/CurlHttpsTransport.php') || str_contains($file, 'Provider/'),
        "curl_exec lives only in a reviewed leaf, not in {$file}",
    );
}
