<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$violations = [];

foreach (['app', 'ops', 'public_html', 'worker'] as $top) {
    $dir = $root . '/' . $top;
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($walk as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'curl_close') {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $violations[] = $relative . ':' . $token[2];
            }
        }
    }
}

sort($violations, SORT_STRING);
Assert::same(
    [],
    $violations,
    'deployable PHP contains no curl_close() call — it is a no-op on PHP 8+ and deprecated in PHP 8.5',
);
