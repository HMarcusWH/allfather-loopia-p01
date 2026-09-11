<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;

/**
 * Serve the authenticated React Operator Console shell — and ONLY the shell.
 *
 * There is deliberately no product service, database handle, M02 guard, entitlement resolver or
 * browser-read adapter in this controller. Identity is supplied by the existing Kernel session
 * boundary; governed reads are made by the browser against the explicit read routes, each of which
 * re-runs the owner gate server-side.
 *
 * The ONE value it passes into the document is the session's own CSRF token, so the shell's
 * sign-out control can stay the POST it has always been rather than being weakened to a GET link
 * for the convenience of a React button.
 */
final class OperatorConsoleController
{
    public function __construct(
        private readonly ConsoleAssetManifest $assets,
        private readonly View $view,
    ) {
    }

    /** @param array<string, mixed>|null $session */
    public function shell(Request $request, ?array $session): Response
    {
        // Kernel already enforces this because /console/app is not public. Keep the local assertion
        // anyway: calling the controller directly must never turn it into an anonymous shell.
        if ($session === null) {
            return Response::redirect('/login');
        }

        try {
            $assets = $this->assets->load();
        } catch (\RuntimeException) {
            // Never leak filesystem/build details and never guess an unhashed fallback filename.
            return Response::html(
                $this->view->render('error', [
                    'title' => '503',
                    'status' => 503,
                    'message' => 'The Operator Console assets are unavailable. The existing server-rendered console remains available.',
                    'session' => null,
                    'nav' => [],
                ]),
                503,
            );
        }

        return Response::operatorConsoleHtml($this->view->partial('operator-console', [
            'scriptUrl' => $assets['script'],
            'styleUrl' => $assets['style'],
            // The SESSION's CSRF token, and only when the caller's cookie verified against the
            // session's stored digest — the Kernel sets `csrf_token` on the session exactly then.
            // Absent, this renders empty and the shell's sign-out POST is refused with a 403 the
            // operator can act on, which is the fail-closed direction.
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
        ]));
    }
}
