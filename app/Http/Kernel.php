<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

/**
 * The front controller.
 *
 * FOUR THINGS HAPPEN HERE AND NOWHERE ELSE, in this order, for every request:
 *
 *   1. the session is resolved from the cookie — or not, and the route decides whether that matters;
 *   2. CSRF is verified on every non-safe method, by METHOD rather than by route list, because a
 *      route list is something a future change can forget to update;
 *   3. the route is dispatched;
 *   4. security headers are set by the Response constructor rather than by whoever remembers.
 *
 * WHAT DOES NOT HAPPEN HERE is authorization. Each surface re-runs the M02 guard itself, against
 * the permission it actually needs. A kernel-level "is this operator allowed in" check would be
 * exactly the single point of failure the M02 discipline exists to avoid — and it would be the one
 * a new surface silently inherits without ever naming a permission.
 */
final class Kernel
{
    /** @var array<string, callable(Request, ?array): Response> */
    private array $routes = [];

    /** @var list<string> */
    private array $publicPaths = ['/login', '/healthz'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Authenticator $auth,
        private readonly View $view,
    ) {
    }

    /** @param callable(Request, ?array): Response $handler */
    public function route(string $method, string $path, callable $handler): void
    {
        $this->routes[$method . ' ' . $path] = $handler;
    }

    public function handle(Request $request): Response
    {
        $session = $this->resolveSession($request);

        // CSRF FIRST, before routing. A state-changing request with no valid token should not reach
        // a handler at all — not even one that would have refused it for another reason.
        if (Csrf::required($request->method)) {
            $storedDigest = $session === null ? '' : (string) $session['csrf_token_hash'];
            if (!Csrf::verify($request->input('csrf_token'), $storedDigest)) {
                return $this->error(403, 'This request could not be verified. Reload and try again.', $session);
            }
        }

        $key = $request->method . ' ' . $request->path;
        $handler = $this->routes[$key] ?? null;
        if ($handler === null) {
            return $this->error(404, 'No such surface.', $session);
        }

        // A protected route with no session redirects rather than 403s: the caller is not refused,
        // they are unidentified, and those are different situations.
        if ($session === null && !in_array($request->path, $this->publicPaths, true)) {
            return Response::redirect('/login');
        }

        return $handler($request, $session);
    }

    /** @return array<string, mixed>|null */
    private function resolveSession(Request $request): ?array
    {
        $token = $request->cookie('af_session');
        if ($token === null || $token === '') {
            return null;
        }
        $resolved = $this->auth->resolveSession($token);

        return $resolved->isOk() ? $resolved->value() : null;
    }

    /** @param array<string, mixed>|null $session */
    private function error(int $status, string $message, ?array $session): Response
    {
        return Response::html(
            $this->view->render('error', [
                'title' => (string) $status,
                'status' => $status,
                'message' => $message,
                'session' => null,
                'nav' => [],
            ]),
            $status,
        );
    }
}
