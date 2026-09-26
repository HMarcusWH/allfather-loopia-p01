<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;

/**
 * Sign in and out.
 *
 * THE FAILURE MESSAGE IS THE SAME whatever went wrong. An unknown address and a wrong password
 * produce identical output, because distinguishing them tells an attacker which addresses exist —
 * and the page says so, so an operator who wonders why the error is vague can see it is deliberate.
 *
 * THE CSRF TOKEN IS ROTATED AT THE BOUNDARY. The sign-in form carries a pre-auth token that exists
 * only to protect the sign-in POST itself; the moment a session exists, that token is replaced by
 * the session's own — whose digest is the one held server-side. So a token an unauthenticated
 * caller could have influenced never survives into an authenticated one.
 */
final class SessionController
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly View $view,
    ) {
    }

    public function showLogin(Request $request, ?array $session): Response
    {
        if ($session !== null) {
            return Response::redirect('/console');
        }

        // A fresh token on every render, cookie and form together. Reusing whatever cookie the
        // caller arrived with would let a token chosen elsewhere be the one the sign-in POST is
        // checked against, which is the whole attack the pre-auth check exists to stop.
        $token = Csrf::issue();

        return Response::html($this->view->render('login', [
            'title' => 'Sign in',
            'error' => $request->query['error'] ?? null,
            'csrf_token' => $token,
            'session' => null,
            'nav' => [],
        ]))->withCookie(Csrf::cookie($token));
    }

    public function login(Request $request, ?array $session): Response
    {
        $result = $this->auth->authenticate(
            $request->body('email'),
            $request->body('password'),
            $request->ip,
            $request->userAgent,
        );

        if ($result->isRefusal()) {
            // A new token for the retry, cookie and form rotated together. Leaving the old one in
            // place would mean a failed attempt hands the next one a token an attacker has already
            // seen in a page they may have induced.
            $retryToken = Csrf::issue();

            return Response::html(
                $this->view->render('login', [
                    'title' => 'Sign in',
                    'error' => 'Those credentials were not accepted.',
                    'csrf_token' => $retryToken,
                    'session' => null,
                    'nav' => [],
                ]),
                401,
            )->withCookie(Csrf::cookie($retryToken));
        }

        $token = (string) $result->value()['session_token'];

        // WHERE a successful sign-in lands depends on the account's lifecycle. An account holding a
        // password an operator issued goes to the password surface — which is a COURTESY, not the
        // enforcement: the Kernel's gate refuses every other path regardless of where this redirect
        // points, so a future change here cannot open a bypass.
        $destination = ($result->value()['must_change_password'] ?? false) === true
            ? '/account/password'
            : '/console';

        // BOTH cookies rotate here: the session token, and the CSRF token whose digest the
        // authenticator has just written into the session row. From this point the weaker
        // pre-auth posture is unreachable for this caller — the session is the authority.
        return Response::redirect($destination, [
            // HttpOnly so no script can read it — and the console runs no script at all. SameSite
            // strict because a governance console has no cross-site flow worth supporting.
            'Set-Cookie' => sprintf(
                'af_session=%s; Path=/; HttpOnly; SameSite=Strict; Secure',
                $token,
            ),
        ])->withCookie(Csrf::cookie((string) $result->value()['csrf_token']));
    }

    public function logout(Request $request, ?array $session): Response
    {
        if ($session !== null) {
            $this->auth->logout((string) $session['session_id']);
        }

        // Both cookies cleared. A CSRF cookie left behind would outlive the session whose digest
        // gave it meaning, and the next sign-in would render a form whose token does not match it.
        return Response::redirect('/login', [
            'Set-Cookie' => 'af_session=; Path=/; HttpOnly; SameSite=Strict; Secure; Max-Age=0',
        ])->withCookie(Csrf::clearedCookie());
    }
}
