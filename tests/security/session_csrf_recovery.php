<?php

declare(strict_types=1);

/**
 * Regression for the live Oct 24 2026 failure: an otherwise valid session
 * receives a pre-auth/stale __Host-af_csrf cookie after an external /login
 * navigation, so the console's sign-out form has no usable CSRF token.
 *
 * GET HTML may rotate a session CSRF nonce only with an active session and
 * optimistic concurrency. Every invalid POST still refuses before logout.
 */
use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Controllers\SessionController;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db=Harness::freshDb();
if ($db===null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock=Harness::clock();
$scope=Seed::tenantWorkspace($db,$clock,'csrf-recovery');
$user=Seed::platformUser($db,$clock,$scope['tenant_id'],$scope['operator_id'],'csrf-recovery@example.test');
$auth=new Authenticator($db,$clock);
$login=$auth->authenticate($user['email'],$user['password'],'192.0.2.199','CsrfRecoveryTest/1.0');
Assert::true($login->isOk(),'test account signs in');
if (!$login->isOk()) return;

$session=$login->value();
$token=$session['session_token'];
$csrf=$session['csrf_token'];
$sessionId=$session['session_id'];
$validCookies=['af_session'=>$token,Csrf::COOKIE_NAME=>$csrf];
$stale=Csrf::issue();
Assert::false(hash_equals($csrf,$stale),'stale token differs from session token');
$badCookies=['af_session'=>$token,Csrf::COOKIE_NAME=>$stale];
$missingCookies=['af_session'=>$token];
$view=new View(dirname(__DIR__,2).'/app/Http/templates');
$kernel=new Kernel($db,$clock,$auth,$view);
$sessionController=new SessionController($auth,$view);
$kernel->route('GET','/login',$sessionController->showLogin(...));
$kernel->route('POST','/logout',$sessionController->logout(...));
$kernel->route('GET','/console',static function (Request $request,?array $principal): Response {
    return Response::html('<form method="post" action="/logout"><input name="csrf_token" value="'
        .htmlspecialchars((string)($principal['csrf_token']??''),ENT_QUOTES,'UTF-8').'"></form>');
});
$kernel->route('GET','/healthz',static fn(Request $request,?array $principal): Response
    =>Response::json(['healthy'=>true]));
$kernel->route('GET','/console/api/v1/test',static fn(Request $request,?array $principal): Response
    =>Response::json(['visible'=>true]),'JSON_401');
$request=static fn(string $method,string $path,array $form=[],array $cookies=[]): Request
    =>Request::fromGlobals(['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'REMOTE_ADDR'=>'192.0.2.199'],[],$form,$cookies);

$normal=$kernel->handle($request('GET','/console',[],$validCookies));
Assert::same(200,$normal->status,'normal authenticated HTML still renders');
Assert::contains('value="'.$csrf.'"',$normal->body,'normal HTML uses the existing token');
Assert::same([],$normal->cookies,'normal GET never rotates an already matching CSRF cookie');

$beforeDigest=(string)$db->scalar('SELECT csrf_token_hash FROM platform_sessions WHERE session_id=?',[$sessionId]);
Assert::same(Authenticator::digest($csrf),$beforeDigest,'session initially holds canonical CSRF digest');
$health=$kernel->handle($request('GET','/healthz',[],$badCookies));
Assert::same(200,$health->status,'healthz works with a stale browser cookie');
Assert::same([],$health->cookies,'healthz never rotates a session or emits a fresh cookie');
$api=$kernel->handle($request('GET','/console/api/v1/test',[],$badCookies));
Assert::same(200,$api->status,'JSON GET still follows its normal auth route');
Assert::same([],$api->cookies,'JSON read never regenerates CSRF on demand');
$unknown=$kernel->handle($request('GET','/does-not-exist',[],$badCookies));
Assert::same(404,$unknown->status,'unknown GET fails closed');
Assert::same([],$unknown->cookies,'unknown GET cannot rotate session state');
Assert::same($beforeDigest,$db->scalar('SELECT csrf_token_hash FROM platform_sessions WHERE session_id=?',[$sessionId]),
    'read-only API/health/unknown requests cannot change the session CSRF digest');

$stalePost=$kernel->handle($request('POST','/logout',['csrf_token'=>$stale],$badCookies));
Assert::same(403,$stalePost->status,'a bad CSRF POST still never reaches logout');
Assert::true($auth->resolveSession($token)->isOk(),'invalid logout leaves the original session active');

$cas=$auth->renewSessionCsrf($sessionId,str_repeat('0',64));
Assert::true($cas->isRefusal(),'a stale renewal digest is refused by compare-and-swap');
Assert::same($beforeDigest,$db->scalar('SELECT csrf_token_hash FROM platform_sessions WHERE session_id=?',[$sessionId]),
    'CAS refusal never modifies the session');

$recovered=$kernel->handle($request('GET','/console',[],$badCookies));
Assert::same(200,$recovered->status,'authenticated HTML GET is able to recover a stale token');
Assert::same(1,count($recovered->cookies),'recovery sends exactly one new __Host- CSRF cookie');
preg_match('/'.preg_quote(Csrf::COOKIE_NAME,'/').'=([^;]+)/',$recovered->cookies[0]??'',$matches);
$fresh=$matches[1]??'';
Assert::true(strlen($fresh)>=32,'recovery cookie contains a real random token');
Assert::contains('SameSite=Strict',$recovered->cookies[0]??'','recovered cookie retains SameSite Strict');
Assert::contains('HttpOnly',$recovered->cookies[0]??'','recovered cookie remains HttpOnly');
Assert::contains('Secure',$recovered->cookies[0]??'','recovered cookie requires TLS');
Assert::contains('value="'.$fresh.'"',$recovered->body,'rendered logout form has the matching recovered token');
Assert::true($fresh!==$csrf && $fresh!==$stale,'recovery never reuses the old/stale token');
Assert::same(Authenticator::digest($fresh),$db->scalar('SELECT csrf_token_hash FROM platform_sessions WHERE session_id=?',[$sessionId]),
    'recovered form token is now the current server-side digest');

$oldPost=$kernel->handle($request('POST','/logout',['csrf_token'=>$csrf],$validCookies));
Assert::same(403,$oldPost->status,'recovery invalidated stale in-flight logout forms');
Assert::true($auth->resolveSession($token)->isOk(),'stale logout form cannot revoke current session');
$fixedCookies=['af_session'=>$token,Csrf::COOKIE_NAME=>$fresh];
$logout=$kernel->handle($request('POST','/logout',['csrf_token'=>$fresh],$fixedCookies));
Assert::same(303,$logout->status,'recovered logout token permits a real POST logout');
Assert::same('/login',$logout->headers['Location']??null,'successful logout redirects to login');
Assert::true($auth->resolveSession($token)->isRefusal(),'successful logout revokes the server-side session');
Assert::contains('Max-Age=0',$logout->headers['Set-Cookie']??'','logout clears the session cookie');
Assert::contains('Max-Age=0',$logout->cookies[0]??'','logout clears the CSRF cookie');
$after=$kernel->handle($request('GET','/console',[],$fixedCookies));
Assert::same(303,$after->status,'revoked session is not accepted on later protected reads');
Assert::same('/login',$after->headers['Location']??null,'revoked session must authenticate again');

// Even with no CSRF cookie at all, an authenticated HTML GET can recover.
$loginAgain=$auth->authenticate($user['email'],$user['password'],'192.0.2.199','CsrfRecoveryTest/1.0');
Assert::true($loginAgain->isOk(),'account can establish a fresh session after logout');
if (!$loginAgain->isOk()) return;
$second=$loginAgain->value();
$missing=$kernel->handle($request('GET','/login',[],['af_session'=>$second['session_token']]));
Assert::same(303,$missing->status,'authenticated /login redirects to console on a stale/missing cookie');
Assert::same('/console',$missing->headers['Location']??null,'authenticated login GET retains its redirect target');
Assert::same(1,count($missing->cookies),'redirect also repairs and sets a new CSRF cookie');


$db->run('UPDATE platform_users SET must_change_password = 1 WHERE platform_user_id = ?',
    [$user['platform_user_id']]);
$forced=$kernel->handle($request('GET','/console',[],['af_session'=>$second['session_token']]));
Assert::same(303,$forced->status,'password-change gate stays enforced during CSRF recovery');
Assert::same('/account/password',$forced->headers['Location']??null,
    'password-change redirect is not bypassed by a stale token');
Assert::same(1,count($forced->cookies),
    'password-change redirect also reissues CSRF so the operator can submit their password form');
