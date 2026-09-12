<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

/**
 * The front controller. Identity is resolved here; authorization remains with each owning surface.
 *
 * Every response that reaches this boundary carries the same opaque support reference that is
 * written to the private request index. Unexpected handler failures are also converted here into a
 * safe 500 rather than escaping before the reference can be stamped and recorded.
 */
final class Kernel
{
    /** @var array<string, callable(Request, ?array): Response> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $unauthenticatedModes = [];

    /** @var list<string> */
    private array $publicPaths = ['/login', '/healthz'];

    /** @var list<string> */
    private const PASSWORD_CHANGE_PATHS = ['/account/password', '/logout'];

    private const PASSWORD_CHANGE_PATH = '/account/password';

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

    /** Dispatch one request; every outcome, including an unexpected failure, keeps its reference. */
    public function handle(Request $request): Response
    {
        try {
            // Keep stamping at the dispatch boundary: every ordinary response, redirect and safe
            // error gets the header before anything downstream sees it.
            $response = $this->dispatch($request)->withHeader(RequestId::HEADER, $request->requestId);
        } catch (\Throwable $error) {
            RequestLog::recordFailure($request->requestId, $error);
            $response = $this->unexpectedFailure($request)
                ->withHeader(RequestId::HEADER, $request->requestId);
        }

        RequestLog::record($request->requestId, $request->method, $request->path, $response->status);

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $session = $this->resolveSession($request);

        if (Csrf::required($request->method)) {
            $expectedDigest = $session !== null
                ? (string) $session['csrf_token_hash']
                : Csrf::digest($request->cookie(Csrf::COOKIE_NAME) ?? '');

            if (!Csrf::verify($request->form['csrf_token'] ?? '', $expectedDigest)) {
                return $this->error(403, 'This request could not be verified. Reload and try again.', $request);
            }
        }

        $key = $request->method . ' ' . $request->path;
        $handler = $this->routes[$key] ?? null;
        if ($handler === null) {
            return $this->error(404, 'No such surface.', $request);
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

        if ($session !== null
            && ($session['must_change_password'] ?? false) === true
            && !in_array($request->path, self::PASSWORD_CHANGE_PATHS, true)
        ) {
            if (($this->unauthenticatedModes[$key] ?? 'REDIRECT_LOGIN') === 'JSON_401') {
                return Response::json([
                    'status' => 'PASSWORD_CHANGE_REQUIRED',
                    'reason_codes' => ['PASSWORD_CHANGE_REQUIRED'],
                    'change_password_path' => self::PASSWORD_CHANGE_PATH,
                ], 403);
            }

            return Response::redirect(self::PASSWORD_CHANGE_PATH);
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

    private function unexpectedFailure(Request $request): Response
    {
        $key = $request->method . ' ' . $request->path;
        if (($this->unauthenticatedModes[$key] ?? 'REDIRECT_LOGIN') === 'JSON_401') {
            return Response::json([
                'status' => 'UNAVAILABLE',
                'reason_codes' => ['UNEXPECTED_ERROR'],
            ], 500);
        }

        return $this->error(
            500,
            'Something went wrong. Quote the support reference below when you contact support.',
            $request,
        );
    }

    private function error(int $status, string $message, Request $request): Response
    {
        return Response::html(
            $this->view->render('error', [
                'title' => (string) $status,
                'status' => $status,
                'message' => $message,
                'requestId' => $request->requestId,
                'session' => null,
                'nav' => [],
            ]),
            $status,
        );
    }
}
