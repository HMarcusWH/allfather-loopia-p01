<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\SourceFingerprint;
use Allfather\Loopia\Ops\StandDownPosture;
use Allfather\Loopia\Tests\Assert;

$root = sys_get_temp_dir() . '/allfather-fingerprint-' . bin2hex(random_bytes(5));
@mkdir($root . '/app', 0777, true);
@mkdir($root . '/database', 0777, true);
@mkdir($root . '/worker', 0777, true);
@mkdir($root . '/public_html/assets/operator-console/assets', 0777, true);

try {
    file_put_contents($root . '/app/runtime.php', '<?php echo "runtime";');
    file_put_contents($root . '/database/schema.txt', 'schema');
    file_put_contents($root . '/worker/tick.php', '<?php echo "tick";');
    file_put_contents($root . '/public_html/index.php', '<?php echo "index";');

    file_put_contents($root . '/public_html/assets/operator-console/console.ABC123.js', 'current-js');
    file_put_contents($root . '/public_html/assets/operator-console/console.DEF456.css', 'current-css');
    file_put_contents($root . '/public_html/assets/operator-console/assets/logo.GHI789.svg', '<svg></svg>');
    file_put_contents(
        $root . '/public_html/assets/operator-console/manifest.json',
        json_encode([
            'version' => 1,
            'entry' => ['script' => 'console.ABC123.js', 'style' => 'console.DEF456.css'],
            'assets' => ['assets/logo.GHI789.svg'],
        ], JSON_THROW_ON_ERROR)
    );

    $first = SourceFingerprint::compute($root);
    Assert::same(64, strlen($first), 'a prepared runtime tree has a sha256 source fingerprint');

    file_put_contents($root . '/public_html/assets/operator-console/console.OLD999.js', 'old-js');
    file_put_contents($root . '/public_html/assets/operator-console/console.OLD888.css', 'old-css');
    Assert::same(
        $first,
        SourceFingerprint::compute($root),
        'retained safe historical console hashes do not change the release fingerprint',
    );
    Assert::equalsList([], SourceFingerprint::unexpectedConsoleEntries($root), 'safe historical hashes are allowed');

    file_put_contents($root . '/public_html/assets/operator-console/console.ABC123.js', 'changed-current-js');
    Assert::false(SourceFingerprint::compute($root) === $first, 'changing a current console asset changes the fingerprint');
    file_put_contents($root . '/public_html/assets/operator-console/console.ABC123.js', 'current-js');
    Assert::same($first, SourceFingerprint::compute($root), 'restoring the current asset restores the digest');

    file_put_contents($root . '/public_html/assets/operator-console/debug.js', 'debug');
    Assert::same($first, SourceFingerprint::compute($root), 'an unreferenced file cannot redefine release identity');
    Assert::equalsList(['debug.js'], SourceFingerprint::unexpectedConsoleEntries($root), 'unsafe public extras are named');
    Assert::equalsList(
        ['the Operator Console public stage contains an unsafe unmanifested entry: debug.js'],
        StandDownPosture::findings([
            ...StandDownPosture::stoodDown(str_repeat('a', 40)),
            'console_unexpected_entries' => ['debug.js'],
        ]),
        'an unsafe unmanifested public entry makes stand-down fail',
    );

    $inventory = SourceFingerprint::inventory($root);
    foreach (array_keys($inventory) as $path) {
        Assert::false(str_contains($path, '\\'), 'fingerprint paths are POSIX-normalized across operating systems');
    }
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    };
    $remove($root);
}
