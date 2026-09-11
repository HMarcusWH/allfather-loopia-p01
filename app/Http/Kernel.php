<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

/**
 * The front controller. Identity is resolved here; authorization remains with each owning surface.
 *
 * TWO THINGS ARE DECIDED HERE AND NOWHERE ELSE, because both are properties of "a request" rather
 * than of any one surface, and a surface that had to remember them is a surface that can forget:
 *
 *   THE SUPPORT REFERENCE. Every response leaves with `X-Request-ID` and is recorded once against
 *   it, including redirects and safe error pages. Attaching it per-controller would mean the
 *   responses that matter most to a confused beta partner — the 403 and the 503 — are the ones most
 *   likely to be missing it. The record is what makes the reference resolvable; see `RequestLog`
 *   for what it deliberately does not contain.
 *
 *   THE ACCOUNT LIFECYCLE GATE. An account holding a password somebody else chose is authenticated
 *   but not yet trusted, and the ONE thing it may do is replace that password. Enforcing that in
 *   the React shell would put a server-side boundary behind a button; enforcing it per-route would
 *   leave the next route added to remember it. It is enforced between routing and dispatch, so
 *   `/console`, `/console/app`, the workspace routes and every JSON read are covered by the same
 *   decision, and a route added later is covered before anybody writes it.
 */
final class Kernel
{
    /** @var array<string, callable(Request, ?array): Response> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $unauthenticatedModes = [];

    /** @var list<string> */
    private array $publicPaths = ['/login', '/healthz'];

    /**
     * The only paths reachable while `must_change_password` is set.
     *
     * Deliberately two. The password surface, because that is the point; and sign-out, because an
     * account that cannot leave has to close the browser to end a session, which teaches exactly
     * the wrong habit. Everything else — including `/account` itself — waits.
     *
     * @var list<string>
     */
    private const PASSWORD_CHANGE_PATHS = ['/account/password', '/logout'];

    /** Where a caller under the gate is sent. */
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

    /**
     * Dispatch one request and stamp its reference on whatever comes back.
     *
     * The stamp is applied to the handler's response rather than passed into the handler, so a
     * surface cannot return a response without one — not by oversight, and not by constructing its
     * own `Response` directly.
     */
    public function handle(Request $request): Response
    {
        $response = $this->dispatch($request)->withHeader(RequestId::HEADER, $request->requestId);

        // RECORDED HERE, once, for the same reason the header is stamped here: this is the one
        // place every response passes through. A reference an operator cannot look up would make
        // the support page's "quote this" an instruction with no other end.
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

        // THE LIFECYCLE GATE, between routing and dispatch. Identity has been established and the
        // route exists; what has not been established is that the caller is the only person who
        // knows this password. Until they replace it, the handler is not reached at all.
        //
        // A DISTINCT STATE, not a 401 and not an ordinary 403. The session is valid, so 401 would
        // send a browser back to a sign-in that would succeed and land here again; and the caller
        // is not forbidden from the resource, they are forbidden until one specific act. The JSON
        // reason code names the act, because the remedy is the caller's own and telling them costs
        // nothing — it discloses no product data and no other account's existence.
        if ($session !== null
            && ($session['must_change_password'] ?? false) === true
            && !in_array($request->path, self::PASSWORD_CHANGE_PATHS, true)
        ) {
            if (($this->unauthenticatedModes[$key] ?? 'REDIRECT_LOGIN') === 'JSON_401') {
                return Response::json([
                    'status' => 'PASSWORD_CHANGE_REQUIRED',
                    'reason_codes' => ['PASSWORD_CHANGE_REQUIRED'],
                    // No `data` key at all. An envelope carrying an empty data field would be one
                    // refactor away from carrying a populated one.
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

    /**
     * A safe error page carrying the request's own reference.
     *
     * The reference is the ONLY thing here a caller could not have worked out themselves. No
     * exception text, no path, no SQL, no session state — a beta partner quotes the reference and
     * an operator looks up what actually happened on a plane the partner cannot see.
     */
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
