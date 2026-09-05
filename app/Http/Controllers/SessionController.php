<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;

/**
 * Sign in and out.
 *
 * THE FAILURE MESSAGE IS THE SAME whatever went wrong. An unknown address and a wrong password
 * produce identical output, because distinguishing them tells an attacker which addresses exist —
 * and the page says so, so an operator who wonders why the error is vague can see it is deliberate.
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

        return Response::html($this->view->render('login', [
            'title' => 'Sign in',
            'error' => $request->query['error'] ?? null,
            'session' => null,
            'nav' => [],
        ]));
    }

    public function login(Request $request, ?array $session): Response
    {
        $result = $this->auth->authenticate(
            $request->input('email'),
            $request->input('password'),
            $request->ip,
            $request->userAgent,
        );

        if ($result->isRefusal()) {
            return Response::html(
                $this->view->render('login', [
                    'title' => 'Sign in',
                    'error' => 'Those credentials were not accepted.',
                    'session' => null,
                    'nav' => [],
                ]),
                401,
            );
        }

        $token = (string) $result->value()['session_token'];

        return Response::redirect('/console', [
            // HttpOnly so no script can read it — and the console runs no script at all. SameSite
            // strict because a governance console has no cross-site flow worth supporting.
            'Set-Cookie' => sprintf(
                'af_session=%s; Path=/; HttpOnly; SameSite=Strict; Secure',
                $token,
            ),
        ]);
    }

    public function logout(Request $request, ?array $session): Response
    {
        if ($session !== null) {
            $this->auth->logout((string) $session['session_id']);
        }

        return Response::redirect('/login', [
            'Set-Cookie' => 'af_session=; Path=/; HttpOnly; SameSite=Strict; Secure; Max-Age=0',
        ]);
    }
}
