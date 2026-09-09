<?php

declare(strict_types=1);

/**
 * The PR5 authenticated React shell through the real Kernel session boundary.
 *
 * This test intentionally creates NO workspace membership and selects NO active workspace: shell
 * presentation requires identity, while governed browser reads will establish their own scope and
 * authority in the later integration phase.
 */

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Controllers\OperatorConsoleController;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id']);
$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');
$auth = new Authenticator($db, $clock);

$tmp = sys_get_temp_dir() . '/allfather-console-shell-' . bin2hex(random_bytes(8));
$stage = $tmp . '/operator-console';
@mkdir($stage . '/assets', 0700, true);
$manifest = [
    'version' => 1,
    'entry' => [
        'script' => 'console.A1B2C3D4.js',
        'style' => 'console.E5F6G7H8.css',
    ],
    'assets' => ['assets/logo-lockup.Z9Y8X7W6.svg'],
];
$writeStage = static function () use ($stage, $manifest): void {
    @mkdir($stage . '/assets', 0700, true);
    file_put_contents($stage . '/' . $manifest['entry']['script'], "console.log('shell');\n");
    file_put_contents($stage . '/' . $manifest['entry']['style'], "body{}\n");
    file_put_contents($stage . '/' . $manifest['assets'][0], '<svg xmlns="http://www.w3.org/2000/svg"></svg>' . "\n");
    file_put_contents($stage . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
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
$writeStage();

try {
    $kernel = new Kernel($db, $clock, $auth, $view);
    $controller = new OperatorConsoleController(new ConsoleAssetManifest($stage), $view);
    $kernel->route('GET', '/console/app', $controller->shell(...));

    $request = static fn (array $cookies = []): Request => Request::fromGlobals(
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/console/app', 'REMOTE_ADDR' => '203.0.113.55'],
        [],
        [],
        $cookies,
    );

    $anonymous = $kernel->handle($request());
    Assert::same(303, $anonymous->status, 'the React shell is protected by the existing Kernel session boundary');
    Assert::same('/login', $anonymous->headers['Location'], 'an unidentified caller is redirected to login');

    // Prove authentication through the real Authenticator, not by fabricating a resolved session.
    $signedIn = $auth->authenticate($user['email'], $user['password'], '203.0.113.55', 'operator-console-test');
    Assert::true($signedIn->isOk(), 'the test operator receives a real platform session');
    $tokens = $signedIn->value();
    $cookies = [
        'af_session' => $tokens['session_token'],
        Csrf::COOKIE_NAME => $tokens['csrf_token'],
    ];

    // No membership and no active workspace were created. PR5 presentation itself grants nothing.
    Assert::same(
        null,
        $db->scalar('SELECT active_workspace_id FROM platform_sessions WHERE session_id = ?', [$tokens['session_id']]),
        'the session has no active workspace before the shell is rendered',
    );

    $shell = $kernel->handle($request($cookies));
    Assert::same(200, $shell->status, 'an authenticated operator can render the React shell without inventing workspace scope');
    Assert::contains('/assets/operator-console/console.A1B2C3D4.js', $shell->body, 'the shell references the validated hashed module script');
    Assert::contains('/assets/operator-console/console.E5F6G7H8.css', $shell->body, 'and the validated hashed stylesheet');
    Assert::contains('<div id="root"></div>', $shell->body, 'the standalone document exposes the React mount only');
    Assert::same(
        "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        $shell->headers['Content-Security-Policy'],
        'the successful shell receives the dedicated strict React CSP',
    );
    Assert::false(str_contains($shell->body, 'csrf_token'), 'the shell embeds no CSRF/session bootstrap material');
    Assert::false(str_contains($shell->body, (string) $scope['tenant_id']), 'the shell embeds no tenant identifier');
    Assert::false(str_contains($shell->body, (string) $scope['workspace_id']), 'the shell embeds no workspace identifier');

    unlink($stage . '/manifest.json');
    $missing = $kernel->handle($request($cookies));
    Assert::same(503, $missing->status, 'a missing staged manifest fails closed');
    Assert::false(str_contains($missing->body, 'console.A1B2C3D4.js'), 'there is no guessed script fallback');
    Assert::false(str_contains($missing->headers['Content-Security-Policy'], 'script-src'), 'the failure response falls back to the scriptless SSR security profile');

    // The stage state is not disclosed before authentication either.
    $anonymousMissing = $kernel->handle($request());
    Assert::same(303, $anonymousMissing->status, 'an anonymous caller learns nothing about missing shell assets');
    Assert::same('/login', $anonymousMissing->headers['Location'], 'and is still sent to login');

    $writeStage();
    $bad = $manifest;
    $bad['entry']['script'] = 'console.js';
    file_put_contents($stage . '/manifest.json', json_encode($bad, JSON_THROW_ON_ERROR));
    $invalid = $kernel->handle($request($cookies));
    Assert::same(503, $invalid->status, 'an unhashed manifest entry also fails closed');
    Assert::false(str_contains($invalid->body, 'console.js'), 'the invalid filename is never reflected or served');
} finally {
    $removeTree($tmp);
}
