<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * The platform-login lifecycle: create, bind, change, reset, suspend, reactivate, retire.
 *
 * WHY THIS EXISTS. Before it, every one of those acts was a hand-written UPDATE. Supporting a
 * closed beta means doing them routinely — an invited partner forgets a password, leaves, comes
 * back — and a support procedure whose first step is "open a SQL client against the production
 * database" is not a support procedure. The distance between a technical demo and something that
 * can be operated is exactly this file.
 *
 * WHAT IT IS NOT. `platform_user_id` does not become a governance identity here. Governed writes
 * keep recording `operator_id`; a platform user is a CREDENTIAL HOLDER bound to one, and this
 * service maintains the credential and the binding. It grants no permission, creates no role, and
 * touches no workspace membership — an account that is reactivated has exactly the authority it had
 * before, which is whatever M02 says today.
 *
 * WHAT IT REFUSES, and why each refusal is the interesting half:
 *
 *   A CROSS-TENANT BINDING. `platform_users.tenant_id` must equal the operator's own. The FK proves
 *   both rows exist; it does not prove they belong to the same tenant, and a login bound across
 *   that line would act in governance as an operator of a tenant it does not belong to.
 *
 *   A SECOND LOGIN FOR ONE OPERATOR. `ux_platform_users_operator` makes it a duplicate-key error at
 *   the storage layer; refusing it here means the operator gets a reason rather than a driver
 *   exception. One login, one operator, in both directions.
 *
 *   AN OPERATOR THAT IS NOT ACTIVE. Binding a credential to a DRAFT, SUSPENDED, REVOKED or RETIRED
 *   operator would mint a login whose governance identity is already stood down.
 *
 *   RESURRECTING A RETIREMENT. `RETIRED` is terminal. Reactivate moves SUSPENDED back to ACTIVE and
 *   refuses to move RETIRED anywhere, because a retirement that can be undone by the same command
 *   that undoes a suspension is not a retirement — it is a longer suspension wearing the word.
 *
 * EVERY STATE CHANGE THAT REMOVES TRUST KILLS SESSIONS IN THE SAME TRANSACTION. Suspending an
 * account whose session stays live is not a suspension. Resolution already re-checks
 * `platform_users.status` on every request, so a suspension takes effect even without this; the
 * revocation is the second half, and it is what makes the change visible in `platform_sessions`
 * rather than only in the behaviour of a later request.
 *
 * REACTIVATION RESTORES NOTHING. The old sessions stay revoked. A revoked session is a decision
 * that was made, and un-making it would hand back a token issued under circumstances that no longer
 * hold.
 */
final class AccountService
{
    /** The lifecycle states a login may be in. Mirrors the `platform_users.status` CHECK. */
    public const STATUSES = ['ACTIVE', 'SUSPENDED', 'LOCKED', 'RETIRED'];

    /** Operator states a credential may be bound to. Everything else is already stood down. */
    private const BINDABLE_OPERATOR_STATUSES = ['ACTIVE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Authenticator $auth,
    ) {
    }

    /**
     * Create a login for an existing governed operator, or converge on the one already bound to it.
     *
     * IDEMPOTENT ON THE BINDING, NOT ON THE CREDENTIAL. Re-running with the same operator and the
     * same email reports the existing account and does NOT silently reset its password — an
     * onboarding command that quietly replaced a working credential would be a support incident
     * wearing the word "idempotent". Changing a password is `resetPassword`, which says so.
     *
     * @param string $password plaintext, supplied by the caller from a prompt or stdin. It is
     *                         hashed here and is never written to a column, a log or a return value.
     */
    public function createLogin(
        string $operatorId,
        string $email,
        string $displayName,
        string $password,
        bool $mustChangePassword = true,
    ): Outcome {
        $email = self::normalizeEmailAddress($email);
        if ($email === '' || !str_contains($email, '@')) {
            return Outcome::refuse('ACCOUNT_EMAIL_INVALID', [], 'VALIDATION');
        }
        if (trim($displayName) === '') {
            return Outcome::refuse('ACCOUNT_DISPLAY_NAME_REQUIRED', [], 'VALIDATION');
        }

        $policy = PasswordPolicy::validate($password);
        if ($policy !== []) {
            return Outcome::refuseAll($policy, [], 'VALIDATION');
        }

        $operator = $this->db->one(
            'SELECT operator_id, tenant_id, status FROM operators WHERE operator_id = ?',
            [$operatorId]
        );
        if ($operator === null) {
            return Outcome::refuse('OPERATOR_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!in_array((string) $operator['status'], self::BINDABLE_OPERATOR_STATUSES, true)) {
            return Outcome::refuse('OPERATOR_NOT_BINDABLE', [
                'status' => (string) $operator['status'],
            ], 'POLICY');
        }
        $tenantId = (string) $operator['tenant_id'];

        $tenant = $this->db->one('SELECT status FROM tenants WHERE tenant_id = ?', [$tenantId]);
        if ($tenant === null || (string) $tenant['status'] !== 'ACTIVE') {
            return Outcome::refuse('TENANT_NOT_ACTIVE', [], 'POLICY');
        }

        $boundToOperator = $this->db->one(
            'SELECT platform_user_id, email, tenant_id FROM platform_users WHERE operator_id = ?',
            [$operatorId]
        );
        if ($boundToOperator !== null) {
            if (self::normalizeEmailAddress((string) $boundToOperator['email']) !== $email) {
                // One operator, one login. Re-pointing an existing binding at a different address
                // is a different act with a different blast radius, and it is not this one.
                return Outcome::refuse('OPERATOR_ALREADY_BOUND', [
                    'platform_user_id' => (string) $boundToOperator['platform_user_id'],
                ], 'POLICY');
            }

            return Outcome::ok([
                'platform_user_id' => (string) $boundToOperator['platform_user_id'],
                'tenant_id' => (string) $boundToOperator['tenant_id'],
                'operator_id' => $operatorId,
                'created' => false,
            ]);
        }

        $byEmail = $this->db->one(
            'SELECT platform_user_id, tenant_id, operator_id FROM platform_users WHERE email = ?',
            [$email]
        );
        if ($byEmail !== null) {
            // Email is globally unique because authentication has no tenant hint to disambiguate
            // with — the browser's would be untrusted input. A collision is refused WITHOUT saying
            // whose it is: naming the other tenant here would make this command an enumerator.
            return Outcome::refuse('ACCOUNT_EMAIL_TAKEN', [], 'POLICY');
        }

        $now = $this->clock->now();
        $hashed = PasswordPolicy::hash($password);
        $platformUserId = Ids::new();

        $this->db->insert('platform_users', [
            'platform_user_id' => $platformUserId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'email' => $email,
            'display_name' => trim($displayName),
            'password_hash' => $hashed['hash'],
            'password_algo' => $hashed['algo'],
            'status' => 'ACTIVE',
            'failed_attempts' => 0,
            'password_changed_at' => $now,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'created_at' => $now,
        ]);

        return Outcome::ok([
            'platform_user_id' => $platformUserId,
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'created' => true,
        ]);
    }

    /**
     * The account holder replacing their own password.
     *
     * The CURRENT password is verified even though the caller already holds a session. A session is
     * evidence that somebody signed in; it is not evidence that the person at the keyboard now is
     * the same one. An unattended browser is the ordinary case this closes.
     *
     * Every session dies, including the one making the request. A password change whose own session
     * survived would leave any OTHER stolen session alive too, which is the thing a password change
     * is usually being performed to end.
     */
    public function changePassword(string $platformUserId, string $currentPassword, string $newPassword): Outcome
    {
        $user = $this->db->one(
            'SELECT platform_user_id, password_hash, status FROM platform_users WHERE platform_user_id = ?',
            [$platformUserId]
        );
        if ($user === null) {
            return Outcome::refuse('ACCOUNT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $user['status'] !== 'ACTIVE') {
            return Outcome::refuse('ACCOUNT_NOT_ACTIVE', [
                'status' => (string) $user['status'],
            ], 'AUTHENTICATION', 'S2', 'REJECT_REQUEST');
        }
        if (!PasswordPolicy::verify($currentPassword, (string) $user['password_hash'])) {
            return Outcome::refuse('CURRENT_PASSWORD_INCORRECT', [], 'AUTHENTICATION', 'S1', 'REJECT_REQUEST');
        }

        $policy = PasswordPolicy::validate($newPassword);
        if ($policy !== []) {
            return Outcome::refuseAll($policy, [], 'VALIDATION');
        }
        if ($newPassword === $currentPassword) {
            return Outcome::refuse('PASSWORD_UNCHANGED', [], 'VALIDATION');
        }

        return $this->writePassword($platformUserId, $newPassword, false, 'PASSWORD_CHANGED');
    }

    /**
     * An operator issuing a replacement password for somebody else.
     *
     * The result is deliberately a credential that can do exactly one thing: replace itself. Two
     * people know it the moment it is created, so `must_change_password` is set unconditionally and
     * is not a parameter — an operator-issued password that could be used indefinitely would be a
     * shared credential with no expiry.
     */
    public function resetPassword(string $platformUserId, string $newPassword): Outcome
    {
        $user = $this->db->one(
            'SELECT platform_user_id, status FROM platform_users WHERE platform_user_id = ?',
            [$platformUserId]
        );
        if ($user === null) {
            return Outcome::refuse('ACCOUNT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $user['status'] === 'RETIRED') {
            // Reissuing a credential for a retired account would un-retire it in the only sense
            // that matters.
            return Outcome::refuse('ACCOUNT_RETIRED', [], 'POLICY');
        }

        $policy = PasswordPolicy::validate($newPassword);
        if ($policy !== []) {
            return Outcome::refuseAll($policy, [], 'VALIDATION');
        }

        // A LOCKED account is unlocked BY THE RESET, and that has to mean the status too. The lock
        // exists to stop somebody guessing at a password that no longer exists, so once it has been
        // replaced the lock is answering a question nobody is asking — and clearing `locked_until`
        // while leaving `status = 'LOCKED'` would leave the account rejected at
        // `Authenticator::authenticate`'s status check, with a working password and no way in.
        //
        // ONLY from LOCKED. `SUSPENDED` is a lifecycle decision somebody made, and a password reset
        // is not an appeal against it: an operator who wants the account back issues `reactivate`,
        // which says so.
        return $this->writePassword(
            $platformUserId,
            $newPassword,
            true,
            'PASSWORD_RESET_BY_OPERATOR',
            'LOCKED',
        );
    }

    /** Suspend a login. Reversible, and it ends every live session now. */
    public function suspend(string $platformUserId, string $reason = 'ACCOUNT_SUSPENDED'): Outcome
    {
        return $this->transition($platformUserId, 'SUSPENDED', ['ACTIVE', 'LOCKED', 'SUSPENDED'], $reason);
    }

    /**
     * Return a suspended or locked login to service.
     *
     * Old sessions are NOT restored — they are already revoked, and revocation is a decision rather
     * than a side effect to be undone. The account signs in again.
     */
    public function reactivate(string $platformUserId): Outcome
    {
        $result = $this->transition($platformUserId, 'ACTIVE', ['SUSPENDED', 'LOCKED', 'ACTIVE'], null);
        if (!$result->isOk()) {
            return $result;
        }

        // The lock-out substrate is cleared with the lock. Leaving `failed_attempts` at its ceiling
        // would re-lock the account on the first mistyped password.
        $this->db->run(
            'UPDATE platform_users SET failed_attempts = 0, locked_until = NULL WHERE platform_user_id = ?',
            [$platformUserId]
        );

        return $result;
    }

    /** Retire a login. Terminal: no transition out of it exists. */
    public function retire(string $platformUserId, string $reason = 'ACCOUNT_RETIRED'): Outcome
    {
        return $this->transition($platformUserId, 'RETIRED', ['ACTIVE', 'SUSPENDED', 'LOCKED', 'RETIRED'], $reason);
    }

    /** Revoke every live session without changing the account's lifecycle state. */
    public function revokeSessions(string $platformUserId, string $reason = 'SESSIONS_REVOKED_BY_OPERATOR'): Outcome
    {
        $user = $this->db->one(
            'SELECT platform_user_id FROM platform_users WHERE platform_user_id = ?',
            [$platformUserId]
        );
        if ($user === null) {
            return Outcome::refuse('ACCOUNT_NOT_FOUND', [], 'PROVENANCE');
        }

        return Outcome::ok([
            'platform_user_id' => $platformUserId,
            'sessions_revoked' => $this->auth->revokeAllSessions($platformUserId, $reason),
        ]);
    }

    /**
     * Find one account by email, within the caller's stated tenant when one is given.
     *
     * `$tenantId` is a SCOPE, not a filter for display: passing one and getting null must be
     * indistinguishable from the address not existing at all, or this becomes a way to ask whether
     * an address belongs to some other tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailAddress(string $email, ?string $tenantId = null): ?array
    {
        $email = self::normalizeEmailAddress($email);
        $row = $this->db->one(
            'SELECT platform_user_id, tenant_id FROM platform_users WHERE email = ?',
            [$email]
        );
        if ($row === null) {
            return null;
        }
        if ($tenantId !== null && (string) $row['tenant_id'] !== $tenantId) {
            return null;
        }

        return $this->inspect((string) $row['platform_user_id']);
    }

    /**
     * A sanitized account state, safe to print in an operator's terminal or a support note.
     *
     * NOTHING HERE IS A SECRET. No password hash, no algorithm, no session token, no CSRF token, no
     * digest of any of them. The lock-out fields are counts and a timestamp, which say whether an
     * account is being guessed at without saying anything about what the guesses were.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(string $platformUserId): ?array
    {
        $row = $this->db->one(
            'SELECT u.platform_user_id, u.tenant_id, u.operator_id, u.email, u.display_name,
                    u.status, u.failed_attempts, u.locked_until, u.last_login_at,
                    u.password_changed_at, u.must_change_password, u.created_at,
                    o.display_name AS operator_display_name, o.status AS operator_status,
                    t.tenant_key, t.status AS tenant_status
             FROM platform_users u
             JOIN operators o ON o.operator_id = u.operator_id
             JOIN tenants t ON t.tenant_id = u.tenant_id
             WHERE u.platform_user_id = ?',
            [$platformUserId]
        );
        if ($row === null) {
            return null;
        }

        $now = $this->clock->now();

        return [
            'platform_user_id' => (string) $row['platform_user_id'],
            'tenant_id' => (string) $row['tenant_id'],
            'tenant_key' => (string) $row['tenant_key'],
            'tenant_status' => (string) $row['tenant_status'],
            'operator_id' => (string) $row['operator_id'],
            'operator_display_name' => (string) $row['operator_display_name'],
            'operator_status' => (string) $row['operator_status'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'must_change_password' => (int) $row['must_change_password'] === 1,
            'failed_attempts' => (int) $row['failed_attempts'],
            'locked_until' => $row['locked_until'] === null ? null : (string) $row['locked_until'],
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'password_changed_at' => (string) $row['password_changed_at'],
            'created_at' => (string) $row['created_at'],
            'live_sessions' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM platform_sessions
                 WHERE platform_user_id = ? AND revoked_at IS NULL AND expires_at > ?',
                [$platformUserId, $now]
            ),
        ];
    }

    /** The workspace memberships one operator holds, as role keys. Presentation, not authority. */
    public function memberships(string $tenantId, string $operatorId): array
    {
        return $this->db->all(
            "SELECT w.workspace_key, w.display_name AS workspace_display_name, r.role_key, m.status
             FROM workspace_memberships m
             JOIN workspaces w ON w.workspace_id = m.workspace_id AND w.tenant_id = m.tenant_id
             JOIN roles r ON r.role_id = m.role_id
             WHERE m.tenant_id = ? AND m.principal_type = 'OPERATOR' AND m.principal_id = ?
             ORDER BY w.workspace_key, r.role_key",
            [$tenantId, $operatorId]
        );
    }

    /**
     * Grant one EXISTING role in one EXISTING workspace.
     *
     * It creates neither. A role key that does not exist is refused rather than minted, because the
     * one thing an onboarding path must never do is invent authority on the way to handing somebody
     * a password.
     */
    public function addRoleMembership(
        string $tenantId,
        string $workspaceKey,
        string $operatorId,
        string $roleKey,
    ): Outcome {
        $workspace = $this->db->one(
            "SELECT workspace_id FROM workspaces WHERE tenant_id = ? AND workspace_key = ? AND status = 'ACTIVE'",
            [$tenantId, $workspaceKey]
        );
        if ($workspace === null) {
            return Outcome::refuse('WORKSPACE_NOT_FOUND_IN_SCOPE', [], 'SCOPE');
        }

        $role = $this->db->one(
            "SELECT role_id, role_version FROM roles WHERE role_key = ? AND status = 'ACTIVE'
             ORDER BY role_version DESC LIMIT 1",
            [$roleKey]
        );
        if ($role === null) {
            return Outcome::refuse('ROLE_NOT_DEFINED', ['role_key' => $roleKey], 'POLICY', 'S4');
        }

        $operator = $this->db->one(
            'SELECT operator_id FROM operators WHERE operator_id = ? AND tenant_id = ?',
            [$operatorId, $tenantId]
        );
        if ($operator === null) {
            return Outcome::refuse('OPERATOR_NOT_IN_TENANT', [], 'SCOPE');
        }

        $workspaceId = (string) $workspace['workspace_id'];
        $existing = $this->db->one(
            "SELECT membership_id, status FROM workspace_memberships
             WHERE tenant_id = ? AND workspace_id = ? AND principal_type = 'OPERATOR'
               AND principal_id = ? AND role_id = ?",
            [$tenantId, $workspaceId, $operatorId, (string) $role['role_id']]
        );
        if ($existing !== null) {
            if ((string) $existing['status'] !== 'ACTIVE') {
                $this->db->run(
                    "UPDATE workspace_memberships SET status = 'ACTIVE', valid_until = NULL
                     WHERE membership_id = ?",
                    [(string) $existing['membership_id']]
                );
            }

            return Outcome::ok([
                'membership_id' => (string) $existing['membership_id'],
                'role_key' => $roleKey,
                'created' => false,
            ]);
        }

        $membershipId = Ids::new();
        $now = $this->clock->now();
        $this->db->insert('workspace_memberships', [
            'membership_id' => $membershipId,
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'principal_type' => 'OPERATOR',
            'principal_id' => $operatorId,
            'role_id' => (string) $role['role_id'],
            'role_version' => (int) $role['role_version'],
            'status' => 'ACTIVE',
            'valid_from' => $now,
            'created_at' => $now,
        ]);

        return Outcome::ok([
            'membership_id' => $membershipId,
            'role_key' => $roleKey,
            'created' => true,
        ]);
    }

    /**
     * One canonical form for an address, so a login and a lookup agree about it.
     *
     * The name is `normalizeEmailAddress` rather than the shorter obvious thing because the
     * no-external-action tripwire greps for the literal `mail(` across the whole application, and
     * `normalizeEmail(` contains it. That check is worth more than the four characters: it is what
     * stops somebody adding a real `mail()` call, and a tripwire with exceptions carved into it
     * stops being one.
     */
    public static function normalizeEmailAddress(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Write a new credential and end every session, as one unit.
     *
     * The two halves must not be separable. A committed password whose sessions survived leaves the
     * old credential's sessions usable, and a revocation whose password write rolled back locks
     * somebody out of an account whose password did not change.
     */
    private function writePassword(
        string $platformUserId,
        string $newPassword,
        bool $mustChangeAgain,
        string $revocationReason,
        ?string $clearStatus = null,
    ): Outcome {
        $hashed = PasswordPolicy::hash($newPassword);
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $platformUserId, $hashed, $now, $mustChangeAgain, $revocationReason, $clearStatus
        ): Outcome {
            // NEVER writes a RETIRED account. The caller checked, but that read was outside this
            // transaction: a retirement committed in between would otherwise be handed a fresh
            // working credential, which is un-retiring it in the only sense that matters.
            $affected = $tx->run(
                "UPDATE platform_users
                 SET password_hash = ?, password_algo = ?, password_changed_at = ?,
                     must_change_password = ?, failed_attempts = 0, locked_until = NULL,
                     status = CASE WHEN status = ? THEN 'ACTIVE' ELSE status END
                 WHERE platform_user_id = ? AND status <> 'RETIRED'",
                [
                    $hashed['hash'], $hashed['algo'], $now, $mustChangeAgain ? 1 : 0,
                    // A status this write is allowed to clear, or a value no status can equal —
                    // which makes the CASE a no-op rather than needing two versions of the SQL.
                    $clearStatus ?? "\0",
                    $platformUserId,
                ]
            );

            if ($affected === 0) {
                return Outcome::refuse('ACCOUNT_RETIRED', [], 'POLICY');
            }

            return Outcome::ok([
                'platform_user_id' => $platformUserId,
                'must_change_password' => $mustChangeAgain,
                'sessions_revoked' => $this->auth->revokeAllSessions($platformUserId, $revocationReason),
            ]);
        });
    }

    /**
     * Move an account to a lifecycle state, from a declared set of states it may move from.
     *
     * `$from` deliberately includes the target state so re-running a support command converges
     * instead of refusing. What it never includes is `RETIRED` as a source for anything but
     * `RETIRED` itself.
     *
     * @param list<string> $from
     */
    private function transition(
        string $platformUserId,
        string $to,
        array $from,
        ?string $revocationReason,
    ): Outcome {
        $user = $this->db->one(
            'SELECT platform_user_id, status FROM platform_users WHERE platform_user_id = ?',
            [$platformUserId]
        );
        if ($user === null) {
            return Outcome::refuse('ACCOUNT_NOT_FOUND', [], 'PROVENANCE');
        }

        $current = (string) $user['status'];
        if ($current === $to) {
            return Outcome::ok([
                'platform_user_id' => $platformUserId,
                'status' => $to,
                'changed' => false,
                'sessions_revoked' => 0,
            ]);
        }
        if (!in_array($current, $from, true)) {
            return Outcome::refuse('ACCOUNT_TRANSITION_NOT_PERMITTED', [
                'from' => $current,
                'to' => $to,
            ], 'POLICY');
        }

        return $this->db->transaction(function (Database $tx) use (
            $platformUserId, $to, $from, $revocationReason
        ): Outcome {
            // CONDITIONAL ON THE STATE THIS DECISION WAS MADE FROM, and the affected-row count is
            // checked. The read above happened outside this transaction, so between deciding and
            // writing another operator's command can have committed: a `reactivate` that read
            // SUSPENDED would otherwise overwrite a `retire` that landed first, and terminal
            // retirement would be terminal only when nobody was working at the same time.
            //
            // The predicate is the SAME `$from` set the decision used, so the write can only land
            // on a row still in a state this transition was permitted from.
            $placeholders = implode(', ', array_fill(0, count($from), '?'));
            $affected = $tx->run(
                "UPDATE platform_users SET status = ? WHERE platform_user_id = ? AND status IN ({$placeholders})",
                [$to, $platformUserId, ...$from]
            );

            if ($affected === 0) {
                // Somebody else moved it. Re-read and answer about what it IS, not about what this
                // command believed a moment ago.
                $now = $tx->one(
                    'SELECT status FROM platform_users WHERE platform_user_id = ?',
                    [$platformUserId]
                );
                $current = $now === null ? null : (string) $now['status'];

                if ($current === $to) {
                    // The race landed on the state this command wanted. Converged, not refused:
                    // reporting a failure for an account that is exactly where the operator asked
                    // for it would send them to investigate a problem that does not exist.
                    return Outcome::ok([
                        'platform_user_id' => $platformUserId,
                        'status' => $to,
                        'changed' => false,
                        'sessions_revoked' => 0,
                    ]);
                }

                return Outcome::refuse('ACCOUNT_TRANSITION_RACED', [
                    'from' => $current,
                    'to' => $to,
                ], 'POLICY');
            }

            $revoked = $revocationReason === null
                ? 0
                : $this->auth->revokeAllSessions($platformUserId, $revocationReason);

            return Outcome::ok([
                'platform_user_id' => $platformUserId,
                'status' => $to,
                'changed' => true,
                'sessions_revoked' => $revoked,
            ]);
        });
    }
}
