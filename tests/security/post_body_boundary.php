<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Controllers\AccountController;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Controllers\WorkspaceController;
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
$scope = Seed::tenantWorkspace($db, $clock, 'post-body');
$role = Seed::role($db, $clock, 'ROLE_BODY_TEST', ['workspace.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$user = Seed::platformUser($db, $clock, $scope['tenant_id'], $scope['operator_id'], 'body@example.test');
$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');
$auth = new Authenticator($db, $clock);
$kernel = new Kernel($db, $clock, $auth, $view);
$sessions = new SessionController($auth, $view);
$workspaces = new WorkspaceController($db, $auth, $view);
$accounts = new AccountController(new AccountService($db, $clock, $auth), $view);
$kernel->route('POST', '/login', $sessions->login(...));
$kernel->route('POST', '/logout', $sessions->logout(...));
$kernel->route('POST', '/console/workspace', $workspaces->select(...));
$kernel->route('POST', '/account/password', $accounts->changePassword(...));
$request = static fn (string $path, array $form, array $query, array $cookies): Request => Request::fromGlobals(
    ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '192.0.2.42'], $query, $form, $cookies,
);
$preAuth = Csrf::issue();
$credentialFields = ['email' => $user['email'], 'password' => $user['password']];
$response = $kernel->handle($request('/login', ['csrf_token' => $preAuth], $credentialFields, [Csrf::COOKIE_NAME => $preAuth]));
Assert::same(401, $response->status, 'valid CSRF does not admit query-only login credentials');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM platform_sessions'), 'query-only login creates no session');

$login = $kernel->handle($request('/login', ['csrf_token' => $preAuth] + $credentialFields, ['password' => 'query must not win'], [Csrf::COOKIE_NAME => $preAuth]));
Assert::same(303, $login->status, 'body credentials still authenticate');
preg_match('/af_session=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $match);
$token = $match[1] ?? '';
$resolved = $auth->resolveSession($token);
Assert::true($resolved->isOk(), 'native login has a usable session');
if (!$resolved->isOk()) {
    return;
}
$session = $resolved->value();
preg_match('/' . preg_quote(Csrf::COOKIE_NAME, '/') . '=([^;]+)/', implode('\n', $login->cookies), $csrfMatch);
$csrf = $csrfMatch[1] ?? '';
$cookies = ['af_session' => $token, Csrf::COOKIE_NAME => $csrf];
Assert::true(Csrf::verify($csrf, $session['csrf_token_hash']), 'real login issued a valid session CSRF token');

$queryWorkspace = $kernel->handle($request('/console/workspace', ['csrf_token' => $csrf], ['workspace_id' => $scope['workspace_id']], $cookies));
Assert::same(403, $queryWorkspace->status, 'query-only workspace selection refuses');
Assert::same(null, $db->scalar('SELECT active_workspace_id FROM platform_sessions WHERE session_id = ?', [$session['session_id']]), 'refused selection leaves scope unchanged');
$bodyWorkspace = $kernel->handle($request('/console/workspace', ['csrf_token' => $csrf, 'workspace_id' => $scope['workspace_id']], [], $cookies));
Assert::same(303, $bodyWorkspace->status, 'body workspace selection succeeds');

$newPassword = 'a different correct horse battery staple';
$passwordFields = ['current_password' => $user['password'], 'new_password' => $newPassword, 'confirm_password' => $newPassword];
$beforeHash = $db->scalar('SELECT password_hash FROM platform_users WHERE platform_user_id = ?', [$user['platform_user_id']]);
$queryPassword = $kernel->handle($request('/account/password', ['csrf_token' => $csrf], $passwordFields, $cookies));
Assert::same(400, $queryPassword->status, 'query-only password replacement refuses even with valid CSRF');
Assert::true(hash_equals($beforeHash, $db->scalar('SELECT password_hash FROM platform_users WHERE platform_user_id = ?', [$user['platform_user_id']])), 'query-only password leaves the hash unchanged');
Assert::true($auth->resolveSession($token)->isOk(), 'query refusal does not revoke the session');

$bodyPassword = $kernel->handle($request('/account/password', ['csrf_token' => $csrf] + $passwordFields, [], $cookies));
Assert::same(303, $bodyPassword->status, 'body password replacement succeeds');
Assert::true($auth->resolveSession($token)->isRefusal(), 'successful password change revokes the old session');
$freshLogin = $auth->authenticate($user['email'], $newPassword, '192.0.2.43');
Assert::true($freshLogin->isOk(), 'the new body-supplied password works');
if ($freshLogin->isOk()) {
    $newSession = $freshLogin->value();
    $logout = $kernel->handle($request('/logout', ['csrf_token' => $newSession['csrf_token']], [], [
        'af_session' => $newSession['session_token'], Csrf::COOKIE_NAME => $newSession['csrf_token'],
    ]));
    Assert::same(303, $logout->status, 'native logout still succeeds');
    Assert::true($auth->resolveSession($newSession['session_token'])->isRefusal(), 'logout revokes the session');
}
