<?php

declare(strict_types=1);

/**
 * No-MariaDB source-boundary guard for the October 24 logout CSRF recovery.
 * The DB-backed end-to-end negative/replay cases live in tests/security/.
 */
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$kernel=(string)file_get_contents($root.'/app/Http/Kernel.php');
$auth=(string)file_get_contents($root.'/app/Auth/Authenticator.php');
$csrf=(string)file_get_contents($root.'/app/Http/Csrf.php');
$logout=(string)file_get_contents($root.'/app/Http/Controllers/SessionController.php');
$react=(string)file_get_contents($root.'/frontend/console/src/shell/utilities.jsx');
$ssr=(string)file_get_contents($root.'/app/Http/templates/layout.php');

Assert::contains('if (Csrf::required($request->method))',$kernel,
    'all unsafe methods are still checked before route dispatch');
Assert::contains('if (!Csrf::verify(',$kernel,
    'invalid POSTs remain rejected before reaching logout');
Assert::contains('return $this->error(403, \'This request could not be verified.',$kernel,
    'invalid CSRF remains HTTP 403');
Assert::contains('$request->method === \'GET\'',$kernel,
    'CSRF recovery is limited to GET');
Assert::contains('$request->path !== \'/healthz\'',$kernel,
    'health checks never refresh login state');
Assert::contains('($this->unauthenticatedModes[$key] ?? \'REDIRECT_LOGIN\') === \'REDIRECT_LOGIN\'',$kernel,
    'JSON APIs do not perform HTML CSRF recovery');
Assert::contains('if ($handler === null)',$kernel,
    'unknown routes cannot rotate session CSRF');
Assert::contains('($session[\'csrf_token\'] ?? \'\') === \'\'',$kernel,
    'recovery only happens when the authenticated token is missing/invalid');
Assert::contains('$this->auth->renewSessionCsrf(',$kernel,
    'Kernel delegates session mutation to the Authenticator');
Assert::contains('Csrf::cookie($session[\'csrf_token\'])',$kernel,
    'recovery sends an updated browser CSRF cookie alongside the new token');
Assert::contains('return $restoredCsrfCookie === null ? $response : $response->withCookie($restoredCsrfCookie);',$kernel,
    'session repair attaches a cookie without downgrading page security headers');
Assert::contains('session_id = ? AND csrf_token_hash = ?',$auth,
    'session token rotation has an optimistic concurrency guard');
Assert::contains('revoked_at IS NULL AND expires_at > ?',$auth,
    'only live unexpired sessions may recover a CSRF nonce');
Assert::contains('SESSION_CSRF_RENEWAL_STALE',$auth,
    'failed CAS refuses instead of guessing an existing token');
Assert::contains("'__Host-af_csrf'",$csrf,
    'CSRF continues to use a host-only cookie');
Assert::contains('SameSite=Strict; Secure',$csrf,
    'CSRF cookie retains Strict and Secure attributes');
Assert::contains('method="post" action="/logout"',$react,
    'React signout remains a POST');
Assert::contains('method="post" action="/logout"',$ssr,
    'SSR signout remains a POST');
Assert::contains('$this->auth->logout(',$logout,
    'successful protected logout still revokes the real session');
