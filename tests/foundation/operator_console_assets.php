<?php

declare(strict_types=1);

/**
 * PR5's presentation boundary: a React shell may load only the exact locally staged bundle and
 * gains no governed read merely because a browser can execute it.
 */

use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/allfather-console-assets-' . bin2hex(random_bytes(8));
$stage = $tmp . '/operator-console';
@mkdir($stage . '/assets', 0700, true);

$valid = [
    'version' => 1,
    'entry' => [
        'script' => 'console.A1B2C3D4.js',
        'style' => 'console.E5F6G7H8.css',
    ],
    'assets' => ['assets/logo-lockup.Z9Y8X7W6.svg'],
];

$writeManifest = static function (array $manifest) use ($stage): void {
    file_put_contents($stage . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
};
$restoreFiles = static function () use ($stage, $valid, $writeManifest): void {
    @mkdir($stage . '/assets', 0700, true);
    file_put_contents($stage . '/' . $valid['entry']['script'], "console.log('shell');\n");
    file_put_contents($stage . '/' . $valid['entry']['style'], "body{}\n");
    file_put_contents($stage . '/' . $valid['assets'][0], '<svg xmlns="http://www.w3.org/2000/svg"></svg>' . "\n");
    $writeManifest($valid);
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
};

try {
    $restoreFiles();
    $assets = new ConsoleAssetManifest($stage);
    $loaded = $assets->load();
    Assert::same('/assets/operator-console/console.A1B2C3D4.js', $loaded['script'], 'the runtime resolves only the manifest-declared hashed script');
    Assert::same('/assets/operator-console/console.E5F6G7H8.css', $loaded['style'], 'and the manifest-declared hashed stylesheet');
    Assert::same(['/assets/operator-console/assets/logo-lockup.Z9Y8X7W6.svg'], $loaded['assets'], 'and only reviewed hashed SVG auxiliary assets');

    $writeManifest([...$valid, 'version' => 2]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'an unknown manifest version fails closed');

    $writeManifest([...$valid, 'extra' => true]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'unknown manifest fields fail closed instead of being ignored');

    $writeManifest([...$valid, 'entry' => ['script' => '../console.A1B2C3D4.js', 'style' => $valid['entry']['style']]]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'path traversal is refused');

    $writeManifest([...$valid, 'entry' => ['script' => 'https:console.A1B2C3D4.js', 'style' => $valid['entry']['style']]]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'remote-looking script paths are refused');

    $writeManifest([...$valid, 'entry' => ['script' => 'console.js', 'style' => $valid['entry']['style']]]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'an unhashed script has no runtime fallback');

    $writeManifest([...$valid, 'assets' => ['assets/logo-lockup.Z9Y8X7W6.png']]);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'unsupported auxiliary asset types are refused');

    $restoreFiles();
    unlink($stage . '/' . $valid['entry']['script']);
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'a missing manifest file fails closed instead of being guessed');

    file_put_contents($stage . '/manifest.json', '{not-json');
    Assert::throws(fn () => $assets->load(), RuntimeException::class, 'malformed manifest JSON fails closed');

    $react = Response::operatorConsoleHtml('<!doctype html><div id="root"></div>');
    Assert::same(
        "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        $react->headers['Content-Security-Policy'],
        'the React shell has one exact route-specific same-origin CSP',
    );
    Assert::false(str_contains($react->headers['Content-Security-Policy'], "'unsafe-inline'"), 'the React CSP never permits inline script or style');
    Assert::false(str_contains($react->headers['Content-Security-Policy'], "'unsafe-eval'"), 'and never permits eval');
    Assert::same('nosniff', $react->headers['X-Content-Type-Options'], 'the React response retains nosniff');
    Assert::contains('no-store', $react->headers['Cache-Control'], 'and the authenticated shell is not cached');

    $ssr = Response::html('<p>server rendered</p>');
    Assert::false(str_contains($ssr->headers['Content-Security-Policy'], 'script-src'), 'the existing SSR response remains entirely scriptless');

    $template = (string) file_get_contents($root . '/app/Http/templates/operator-console.php');
    Assert::contains('<script type="module" src="<?= View::e($scriptUrl) ?>"></script>', $template, 'the shell loads one escaped external module script');
    Assert::contains('<link rel="stylesheet" href="<?= View::e($styleUrl) ?>">', $template, 'and one escaped external stylesheet');
    Assert::contains('<div id="root" data-allfather-console></div>', $template, 'the PHP shell emits the mount selector required by the production entry point');
    Assert::false(str_contains($template, 'style='), 'the React document contains no inline style attribute');
    Assert::false(str_contains($template, '<script>'), 'the React document contains no inline script block');
    Assert::false(str_contains($template, 'http://') || str_contains($template, 'https://'), 'the React document names no remote origin');

    $entrySource = (string) file_get_contents($root . '/frontend/console/src/index.jsx');
    Assert::contains('document.querySelector("[data-allfather-console]")', $entrySource, 'the production entry point retains the same mount selector the PHP shell emits');

    $controller = (string) file_get_contents($root . '/app/Http/Controllers/OperatorConsoleController.php');
    foreach (['Database', 'AccessGuard', 'Entitlements', 'Product\\P0', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
        Assert::false(
            str_contains($controller, $forbidden),
            "the PR5 shell controller contains no governed data/authority primitive: {$forbidden}",
        );
    }
    Assert::contains("return Response::redirect('/login');", $controller, 'direct controller invocation still refuses an anonymous shell');
    Assert::contains('catch (\\RuntimeException)', $controller, 'asset-manifest failure is converted to a safe response');
    Assert::false(str_contains($controller, 'glob('), 'the controller never guesses a fallback asset by globbing');

    $index = (string) file_get_contents($root . '/public_html/index.php');
    Assert::contains("\$kernel->route('GET', '/console/app', \$operatorConsole->shell(...));", $index, 'the React shell is registered as one exact GET route');
    $kernelSource = (string) file_get_contents($root . '/app/Http/Kernel.php');
    Assert::false(str_contains($kernelSource, "'/console/app'"), 'the React shell is not added to the Kernel public-path allowlist');
    Assert::contains('/public_html/assets/operator-console/', (string) file_get_contents($root . '/.gitignore'), 'generated staged assets are ignored rather than treated as source');

    $runbook = (string) file_get_contents($root . '/docs/DEPLOYMENT_RUNBOOK.md');
    $stageCommand = strpos($runbook, 'npm run stage');
    $applicationDeploy = strpos($runbook, '#### Deploy the application tree');
    $assetPublish = strpos($runbook, '#### Publish Operator Console assets manifest-last');
    $boundaryVerification = strpos($runbook, '**Do not symlink a separate directory into the document root.**');
    Assert::true(
        is_int($stageCommand)
        && is_int($applicationDeploy)
        && is_int($assetPublish)
        && is_int($boundaryVerification)
        && $stageCommand < $applicationDeploy
        && $applicationDeploy < $assetPublish
        && $assetPublish < $boundaryVerification,
        'the runbook orders staging, application deployment and manifest-last asset publication explicitly',
    );
    Assert::contains(
        'test -f public_html/assets/operator-console/manifest.json',
        $runbook,
        'the deployment runbook refuses to proceed without the staged Operator Console manifest',
    );

    $applicationSection = substr($runbook, $applicationDeploy, $assetPublish - $applicationDeploy);
    Assert::contains('rsync -av --delete', $applicationSection, 'the general application deployment retains its closed-tree delete behavior');
    Assert::contains(
        "--exclude 'frontend/console/node_modules'",
        $applicationSection,
        'the application rsync excludes the frontend dependency tree produced by npm ci',
    );
    Assert::contains(
        "--exclude 'frontend/console/dist'",
        $applicationSection,
        'the application rsync excludes the private frontend build output',
    );
    Assert::contains(
        "--exclude 'public_html/assets/operator-console'",
        $applicationSection,
        'the application rsync cannot delete a still-referenced Operator Console content hash',
    );

    $assetSection = substr($runbook, $assetPublish, $boundaryVerification - $assetPublish);
    Assert::false(str_contains($assetSection, '--delete'), 'the Operator Console asset publish never deletes previous content hashes');
    Assert::contains("--exclude 'manifest.json'", $assetSection, 'hashed assets are copied before the live manifest');
    Assert::contains('manifest.json.next', $assetSection, 'the new manifest is uploaded under a temporary same-directory name');
    Assert::contains(
        'mv ~/<domain>/public_html/assets/operator-console/manifest.json.next',
        $assetSection,
        'the live manifest is switched only by a final remote rename',
    );
    $assetCopy = strpos($assetSection, "--exclude 'manifest.json'");
    $manifestNext = strpos($assetSection, 'manifest.json.next');
    $manifestMove = strpos($assetSection, 'mv ~/<domain>/public_html/assets/operator-console/manifest.json.next');
    Assert::true(
        is_int($assetCopy)
        && is_int($manifestNext)
        && is_int($manifestMove)
        && $assetCopy < $manifestNext
        && $manifestNext < $manifestMove,
        'new hashes arrive before the temporary manifest and the manifest rename is last',
    );
    Assert::contains(
        'Old content-hashed assets are deliberately retained during the deployment.',
        $assetSection,
        'the runbook makes old-hash retention an explicit release invariant rather than an accident',
    );
} finally {
    $removeTree($tmp);
}
