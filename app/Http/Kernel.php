<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

/**
 * The front controller. Identity is resolved here; authorization remains with each owning surface.
 */
final class Kernel
{
    /** @var array<string, callable(Request, ?array): Response> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $unauthenticatedModes = [];

    /** @var list<string> */
    private array $publicPaths = ['/login', '/healthz'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Authenticator $auth,
        private readonly View $view,
    ) {
    }

    /**
     * @param callable(Request, ?array): Response $handler
     * @param 'REDIRECT_LOGIN'|'JSON_401' $unauthenticatedMode
     */
    public function route(
        string $method,
        string $path,
        callable $handler,
        string $unauthenticatedMode = 'REDIRECT_LOGIN',
    ): void {
        if (!in_array($unauthenticatedMode, ['REDIRECT_LOGIN', 'JSON_401'], true)) {
            throw new \InvalidArgumentException('unknown unauthenticated route mode');
        }
        $key = $method . ' ' . $path;
        $this->routes[$key] = $handler;
        $this->unauthenticatedModes[$key] = $unauthenticatedMode;
    }

    public function handle(Request $request): Response
    {
        $session = $this->resolveSession($request);

        if (Csrf::required($request->method)) {
            $expectedDigest = $session !== null
                ? (string) $session['csrf_token_hash']
                : Csrf::digest($request->cookie(Csrf::COOKIE_NAME) ?? '');

            if (!Csrf::verify($request->form['csrf_token'] ?? '', $expectedDigest)) {
                return $this->error(403, 'This request could not be verified. Reload and try again.', $session);
            }
        }

        $key = $request->method . ' ' . $request->path;
        $handler = $this->routes[$key] ?? null;
        if ($handler === null) {
            return $this->error(404, 'No such surface.', $session);
        }

        if ($session === null && !in_array($request->path, $this->publicPaths, true)) {
            if (($this->unauthenticatedModes[$key] ?? 'REDIRECT_LOGIN') === 'JSON_401') {
                return Response::json([
                    'status' => 'UNAUTHENTICATED',
                    'reason_codes' => ['AUTHENTICATION_REQUIRED'],
                ], 401);
            }
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
        if (!$resolved->isOk()) {
            return null;
        }
        $session = $resolved->value();

        $cookieToken = $request->cookie(Csrf::COOKIE_NAME) ?? '';
        if (Csrf::verify($cookieToken, (string) $session['csrf_token_hash'])) {
            $session['csrf_token'] = $cookieToken;
        }

        return $session;
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
