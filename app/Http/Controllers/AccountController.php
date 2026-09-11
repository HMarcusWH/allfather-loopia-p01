<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Auth\PasswordPolicy;
use Allfather\Loopia\Http\Csrf;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;

/**
 * The account's own surface: what this login is, and how it replaces its password.
 *
 * SERVER-RENDERED, DELIBERATELY. The React shell links here rather than reimplementing it, for two
 * reasons that are the same reason twice. A password form inside the shell would have to carry the
 * CSRF token into JavaScript, and the change must work for an account the shell is not reachable
 * by — one under the lifecycle gate, which cannot load `/console/app` at all.
 *
 * WHAT THE PAGE SHOWS is the account, not the tenant: display name, address, lifecycle state, when
 * the password last changed, and the role memberships this operator holds. Memberships are shown
 * because "why can I not see battlecards?" is the most common beta support question and the answer
 * is usually visible right there. They are PRESENTATION — the page states it, because an operator
 * who reads a list of roles as the boundary will eventually test the boundary.
 *
 * WHAT IT NEVER SHOWS: a hash, an algorithm, a session token, a CSRF token belonging to anything
 * but this session's own form, another account, or any tenant identifier but its own.
 */
final class AccountController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly View $view,
    ) {
    }

    /** @param array<string, mixed>|null $session */
    public function show(Request $request, ?array $session): Response
    {
        if ($session === null) {
            return Response::redirect('/login');
        }

        $account = $this->accounts->inspect((string) $session['platform_user_id']);
        if ($account === null) {
            // The session resolved a moment ago, so the row disappearing means something concurrent
            // removed it. Fail closed rather than rendering a page about an account that is gone.
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('account', [
            'title' => 'Account',
            'session' => $session,
            'nav' => ['current' => 'account'],
            'account' => $account,
            'memberships' => $this->accounts->memberships(
                (string) $account['tenant_id'],
                (string) $account['operator_id'],
            ),
            'requestId' => $request->requestId,
        ]));
    }

    /** @param array<string, mixed>|null $session */
    public function showPasswordForm(Request $request, ?array $session): Response
    {
        if ($session === null) {
            return Response::redirect('/login');
        }

        return $this->renderPasswordForm($request, $session, null);
    }

    /**
     * Replace this account's password.
     *
     * The order of operations is the security property. CSRF was verified by the Kernel before this
     * method existed as a possibility; the current password is verified inside `AccountService`;
     * and every session — this one included — is revoked in the same transaction that writes the
     * new hash. The caller is then sent to sign in again, which is the honest consequence rather
     * than a courtesy: their session no longer exists.
     *
     * @param array<string, mixed>|null $session
     */
    public function changePassword(Request $request, ?array $session): Response
    {
        if ($session === null) {
            return Response::redirect('/login');
        }

        $newPassword = $request->input('new_password');
        // Checked BEFORE the service is called, so a mistyped confirmation writes nothing — and
        // answered 400 like every other refusal on this surface, because a refusal that returns 200
        // is a refusal nothing downstream can distinguish from success.
        if ($newPassword !== $request->input('confirm_password')) {
            return $this->renderPasswordForm($request, $session, 'PASSWORD_CONFIRMATION_MISMATCH', 400);
        }

        $result = $this->accounts->changePassword(
            (string) $session['platform_user_id'],
            $request->input('current_password'),
            $newPassword,
        );

        if ($result->isRefusal()) {
            // The SPECIFIC reason code. A wrong current password, a short new one and an unchanged
            // one need three different things from the person at the keyboard, and "could not
            // change password" tells them none of it. None of these codes discloses anything about
            // another account — they are all statements about input this caller just supplied.
            return $this->renderPasswordForm($request, $session, $result->reasonCode(), 400);
        }

        // EVERY session is gone, including this request's. Clearing the cookies is what keeps the
        // browser's state honest about it: leaving a session cookie that no longer resolves would
        // meet the operator with a silent redirect they cannot explain.
        return Response::redirect('/login?changed=1', [
            'Set-Cookie' => 'af_session=; Path=/; HttpOnly; SameSite=Strict; Secure; Max-Age=0',
        ])->withCookie(Csrf::clearedCookie());
    }

    /** @param array<string, mixed> $session */
    private function renderPasswordForm(
        Request $request,
        array $session,
        ?string $error,
        int $status = 200,
    ): Response {
        $required = ($session['must_change_password'] ?? false) === true;

        return Response::html($this->view->render('account-password', [
            'title' => $required ? 'Choose a password' : 'Change password',
            // Under the gate the navigation rail is deliberately NOT rendered: every link in it
            // leads somewhere this account may not go yet, and a page full of dead ends reads as a
            // broken console rather than as a required step.
            'session' => $required ? null : $session,
            'nav' => ['current' => 'account'],
            'csrf_token' => (string) ($session['csrf_token'] ?? ''),
            'mustChangePassword' => $required,
            'minimumLength' => PasswordPolicy::MIN_LENGTH,
            'error' => $error,
            'requestId' => $request->requestId,
        ]), $status);
    }
}
