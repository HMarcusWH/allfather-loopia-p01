<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * Platform authentication: login, session issue, session resolution, logout.
 *
 * This plane is Loopia-specific — upstream runs in-process and has no login. It binds a
 * `platform_users` row to an `operators` row so that every governed write still records an
 * `operator_id`, keeping the audit trail in the canonical vocabulary.
 *
 * Security properties:
 *  - session tokens are stored HASHED; a database dump yields no usable session;
 *  - session id rotates on login, so a pre-set session cookie cannot be fixated;
 *  - a failed login is indistinguishable from an unknown account, in both response and timing;
 *  - attempts are rate limited per email AND per IP, both recorded as digests;
 *  - the active workspace lives in the session row, never in a request parameter.
 */
final class Authenticator
{
    public const SESSION_TTL_SECONDS = 43200;      // 12 hours
    private const MAX_ATTEMPTS_PER_EMAIL = 10;
    private const MAX_ATTEMPTS_PER_IP = 30;
    private const ATTEMPT_WINDOW_SECONDS = 900;    // 15 minutes
    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Authenticate and issue a session.
     *
     * @return Outcome Outcome::ok(array{session_token: string, csrf_token: string, session_id: string,
     *                                   platform_user_id: string, tenant_id: string, operator_id: string})
     */
    public function authenticate(string $email, string $password, string $ip, ?string $userAgent = null): Outcome
    {
        $email = mb_strtolower(trim($email));
        $emailDigest = self::digest($email);
        $ipDigest = self::digest($ip);
        $now = $this->clock->now();

        $limited = $this->rateLimited($emailDigest, $ipDigest, $now);
        if ($limited !== null) {
            $this->recordAttempt($emailDigest, $ipDigest, 'RATE_LIMITED', $now);

            return $limited;
        }

        $user = $this->db->one(
            'SELECT platform_user_id, tenant_id, operator_id, password_hash, status, locked_until, failed_attempts
             FROM platform_users WHERE email = ?',
            [$email]
        );

        // Unknown account: still perform a full verify against a dummy hash so the response time
        // does not distinguish "no such user" from "wrong password".
        if ($user === null) {
            PasswordPolicy::verify($password, PasswordPolicy::dummyHash());
            $this->recordAttempt($emailDigest, $ipDigest, 'BAD_CREDENTIALS', $now);

            return Outcome::refuse('AUTH_INVALID_CREDENTIALS', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }

        $lockedUntil = $user['locked_until'] !== null ? (string) $user['locked_until'] : null;
        if ($lockedUntil !== null && $lockedUntil > $now) {
            $this->recordAttempt($emailDigest, $ipDigest, 'ACCOUNT_LOCKED', $now);

            return Outcome::refuse('AUTH_ACCOUNT_LOCKED', [], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }

        if ((string) $user['status'] !== 'ACTIVE') {
            PasswordPolicy::verify($password, PasswordPolicy::dummyHash());
            $this->recordAttempt($emailDigest, $ipDigest, 'ACCOUNT_NOT_ACTIVE', $now);

            return Outcome::refuse('AUTH_ACCOUNT_NOT_ACTIVE', [], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }

        if (!PasswordPolicy::verify($password, (string) $user['password_hash'])) {
            $this->registerFailure((string) $user['platform_user_id'], (int) $user['failed_attempts'], $now);
            $this->recordAttempt($emailDigest, $ipDigest, 'BAD_CREDENTIALS', $now);

            // The SAME reason code as an unknown account: the difference is not the caller's to know.
            return Outcome::refuse('AUTH_INVALID_CREDENTIALS', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }

        $sessionToken = Ids::token(32);
        $csrfToken = Ids::token(32);
        $sessionId = Ids::new();
        $expires = self::plusSeconds($now, self::SESSION_TTL_SECONDS);

        $this->db->transaction(function (Database $tx) use (
            $user, $sessionId, $sessionToken, $csrfToken, $now, $expires, $userAgent, $ipDigest, $password
        ): void {
            // Upgrade the stored hash opportunistically: the only moment the plaintext is
            // available is right here, immediately after a successful verify.
            if (PasswordPolicy::needsRehash((string) $user['password_hash'])) {
                $rehashed = PasswordPolicy::hash($password);
                $tx->run(
                    'UPDATE platform_users SET password_hash = ?, password_algo = ? WHERE platform_user_id = ?',
                    [$rehashed['hash'], $rehashed['algo'], $user['platform_user_id']]
                );
            }

            $tx->run(
                'UPDATE platform_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ?
                 WHERE platform_user_id = ?',
                [$now, $user['platform_user_id']]
            );

            $tx->insert('platform_sessions', [
                'session_id' => $sessionId,
                'schema_version' => '1',
                'token_hash' => self::digest($sessionToken),
                'platform_user_id' => $user['platform_user_id'],
                'tenant_id' => $user['tenant_id'],
                'active_workspace_id' => null,
                'csrf_token_hash' => self::digest($csrfToken),
                'user_agent_digest' => $userAgent === null ? null : self::digest($userAgent),
                'ip_digest' => $ipDigest,
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expires,
            ]);
        });

        $this->recordAttempt($emailDigest, $ipDigest, 'SUCCEEDED', $now);

        return Outcome::ok([
            'session_token' => $sessionToken,
            'csrf_token' => $csrfToken,
            'session_id' => $sessionId,
            'platform_user_id' => (string) $user['platform_user_id'],
            'tenant_id' => (string) $user['tenant_id'],
            'operator_id' => (string) $user['operator_id'],
        ]);
    }

    /**
     * Resolve a session token to its principal.
     *
     * @return Outcome Outcome::ok(array{session_id, platform_user_id, tenant_id, operator_id,
     *                                  active_workspace_id, csrf_token_hash, display_name})
     */
    public function resolveSession(string $sessionToken): Outcome
    {
        $now = $this->clock->now();
        $row = $this->db->one(
            'SELECT s.session_id, s.platform_user_id, s.tenant_id, s.active_workspace_id,
                    s.csrf_token_hash, s.expires_at, s.revoked_at,
                    u.operator_id, u.display_name, u.status
             FROM platform_sessions s
             JOIN platform_users u ON u.platform_user_id = s.platform_user_id
             WHERE s.token_hash = ?',
            [self::digest($sessionToken)]
        );

        if ($row === null) {
            return Outcome::refuse('SESSION_NOT_FOUND', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }
        if ($row['revoked_at'] !== null) {
            return Outcome::refuse('SESSION_REVOKED', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }
        if ((string) $row['expires_at'] <= $now) {
            return Outcome::refuse('SESSION_EXPIRED', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }
        if ((string) $row['status'] !== 'ACTIVE') {
            // The account was suspended after the session was issued; the session dies with it.
            return Outcome::refuse('AUTH_ACCOUNT_NOT_ACTIVE', [], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }

        $this->db->run(
            'UPDATE platform_sessions SET last_seen_at = ? WHERE session_id = ?',
            [$now, $row['session_id']]
        );

        return Outcome::ok([
            'session_id' => (string) $row['session_id'],
            'platform_user_id' => (string) $row['platform_user_id'],
            'tenant_id' => (string) $row['tenant_id'],
            'operator_id' => (string) $row['operator_id'],
            'active_workspace_id' => $row['active_workspace_id'] === null ? null : (string) $row['active_workspace_id'],
            'csrf_token_hash' => (string) $row['csrf_token_hash'],
            'display_name' => (string) $row['display_name'],
        ]);
    }

    /**
     * Set the session's active workspace.
     *
     * The membership is re-checked here rather than trusted from the caller: a workspace id in a
     * form field is untrusted input, and this is the single point where it becomes session state.
     */
    public function setActiveWorkspace(string $sessionId, string $workspaceId): Outcome
    {
        $session = $this->db->one(
            'SELECT platform_user_id, tenant_id FROM platform_sessions WHERE session_id = ? AND revoked_at IS NULL',
            [$sessionId]
        );
        if ($session === null) {
            return Outcome::refuse('SESSION_NOT_FOUND', [], 'AUTHENTICATION');
        }

        $workspace = $this->db->one(
            'SELECT workspace_id FROM workspaces WHERE workspace_id = ? AND tenant_id = ? AND status = ?',
            [$workspaceId, $session['tenant_id'], 'ACTIVE']
        );
        if ($workspace === null) {
            // Also the cross-tenant probe guard: a workspace in another tenant is NOT_FOUND, not
            // FORBIDDEN, so the response cannot be used to enumerate other tenants' workspaces.
            return Outcome::refuse('WORKSPACE_NOT_FOUND_IN_SCOPE', [], 'SCOPE');
        }

        $operator = $this->db->one(
            'SELECT operator_id FROM platform_users WHERE platform_user_id = ?',
            [$session['platform_user_id']]
        );
        $membership = $this->db->one(
            'SELECT membership_id FROM workspace_memberships
             WHERE workspace_id = ? AND tenant_id = ? AND principal_type = ? AND principal_id = ? AND status = ?',
            [$workspaceId, $session['tenant_id'], 'OPERATOR', $operator['operator_id'] ?? '', 'ACTIVE']
        );
        if ($membership === null) {
            return Outcome::refuse('WORKSPACE_MEMBERSHIP_REQUIRED', [], 'AUTHORIZATION');
        }

        $this->db->run(
            'UPDATE platform_sessions SET active_workspace_id = ? WHERE session_id = ?',
            [$workspaceId, $sessionId]
        );

        return Outcome::ok(['workspace_id' => $workspaceId]);
    }

    public function logout(string $sessionId, string $reason = 'USER_LOGOUT'): Outcome
    {
        $this->db->run(
            'UPDATE platform_sessions SET revoked_at = ?, revoked_reason = ? WHERE session_id = ? AND revoked_at IS NULL',
            [$this->clock->now(), $reason, $sessionId]
        );

        return Outcome::ok(['session_id' => $sessionId]);
    }

    /** Revoke every session for a user — used by the stand-down runbook and on password change. */
    public function revokeAllSessions(string $platformUserId, string $reason): int
    {
        return $this->db->run(
            'UPDATE platform_sessions SET revoked_at = ?, revoked_reason = ?
             WHERE platform_user_id = ? AND revoked_at IS NULL',
            [$this->clock->now(), $reason, $platformUserId]
        );
    }

    public static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    private function rateLimited(string $emailDigest, string $ipDigest, string $now): ?Outcome
    {
        $since = self::plusSeconds($now, -self::ATTEMPT_WINDOW_SECONDS);

        $byEmail = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM auth_attempts WHERE email_digest = ? AND attempted_at >= ? AND outcome <> ?',
            [$emailDigest, $since, 'SUCCEEDED']
        );
        if ($byEmail >= self::MAX_ATTEMPTS_PER_EMAIL) {
            return Outcome::refuse('AUTH_RATE_LIMITED', [], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }

        $byIp = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM auth_attempts WHERE ip_digest = ? AND attempted_at >= ? AND outcome <> ?',
            [$ipDigest, $since, 'SUCCEEDED']
        );
        if ($byIp >= self::MAX_ATTEMPTS_PER_IP) {
            return Outcome::refuse('AUTH_RATE_LIMITED', [], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }

        return null;
    }

    private function registerFailure(string $platformUserId, int $currentFailures, string $now): void
    {
        $next = $currentFailures + 1;
        $lockUntil = $next >= self::MAX_ATTEMPTS_PER_EMAIL ? self::plusSeconds($now, self::LOCK_SECONDS) : null;
        $this->db->run(
            'UPDATE platform_users SET failed_attempts = ?, locked_until = ? WHERE platform_user_id = ?',
            [$next, $lockUntil, $platformUserId]
        );
    }

    private function recordAttempt(string $emailDigest, string $ipDigest, string $outcome, string $now): void
    {
        $this->db->insert('auth_attempts', [
            'auth_attempt_id' => Ids::new(),
            'schema_version' => '1',
            'email_digest' => $emailDigest,
            'ip_digest' => $ipDigest,
            'outcome' => $outcome,
            'attempted_at' => $now,
        ]);
    }

    private static function plusSeconds(string $iso, int $seconds): string
    {
        $at = Clock::parse($iso);
        if ($at === null) {
            throw new \InvalidArgumentException("non-canonical timestamp: {$iso}");
        }
        $sign = $seconds >= 0 ? '+' : '-';

        return Clock::format($at->modify("{$sign}" . abs($seconds) . ' seconds'));
    }
}
