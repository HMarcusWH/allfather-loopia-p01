-- 170_platform_account_lifecycle
--
-- Loopia-specific, like 120_platform_auth which it extends. Upstream has no authentication plane,
-- so it has no notion of an account that must change its password before it may do anything else.
--
-- WHAT 120 ALREADY CARRIES, and is NOT re-invented here:
--   `platform_users.status`          ACTIVE / SUSPENDED / LOCKED / RETIRED — the lifecycle itself
--   `platform_users.password_hash`   and `password_algo` — the credential and how it was made
--   `password_changed_at`            when it last changed
--   `failed_attempts` / `locked_until` the lock-out substrate
--   `platform_sessions.revoked_at`   revocation, which the authenticator already honours
--
-- WHAT IS MISSING, and is the whole of this migration: somewhere to record that an account has
-- been handed a password somebody ELSE chose. An operator-issued credential is a credential two
-- people know, and the only thing that makes it safe is that it cannot be used for anything except
-- replacing itself. That is a STATE, not a convention — so it belongs in storage where a server
-- side gate reads it, rather than in a flow a later route could forget to run.
--
-- DEFAULT 0, DELIBERATELY. Every account that exists when this runs chose its own password.
-- Defaulting to 1 would lock every current login — including the ones the suite signs in with —
-- into a password-change flow nobody asked for, and a migration that changes the meaning of
-- existing rows is a migration that has done more than it said. The requirement is set by the act
-- that creates it: onboarding, or an operator-issued reset.
--
-- THE CHECK IS NOT DECORATION. The column is a boolean in a database that has no boolean type.
-- Without it, `2` is a value the column can hold, and a gate reading `=== 1` and a gate reading
-- `!== 0` would then disagree about the same row.
--
-- NO INVITATION OR RESET-TOKEN MODEL. A closed beta onboards by an operator running a command and
-- telling the partner out of band. An emailed reset token would be a new externally-reachable
-- credential path, and this projection performs no external send at all.
--
-- ONE STATEMENT, because DDL is not transactional in MariaDB. Adding the column and its constraint
-- separately admits a half-applied state in which the column exists unconstrained; `IF NOT EXISTS`
-- then makes the re-run converge rather than fail on a duplicate.

ALTER TABLE platform_users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0
    CHECK (must_change_password IN (0, 1));
