-- 120_platform_auth
--
-- Loopia-specific. Upstream has `operators` as a governance identity but no authentication plane:
-- it runs in-process and is driven by tests and a host adapter. A deployed multi-tenant platform
-- needs real authentication, and inventing it inside the governance tables would blur an operator
-- (a governed authority) with a login (a credential holder).
--
-- So `platform_users` is a SEPARATE identity that BINDS to an `operators` row. Governance keeps
-- referring to operator_id; authentication keeps its own lifecycle, password hash, and sessions.

CREATE TABLE IF NOT EXISTS platform_users (
  platform_user_id  CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  tenant_id         CHAR(36) NOT NULL,
  -- The governance identity this login acts as. Every governed write records the operator_id,
  -- never the platform_user_id, so the audit trail stays in the canonical vocabulary.
  operator_id       CHAR(36) NOT NULL,
  email             VARCHAR(191) NOT NULL,
  display_name      VARCHAR(255) NOT NULL,
  -- password_hash() output: algorithm, cost and salt are encoded in the string itself, so a
  -- rehash on algorithm change needs no schema change.
  password_hash     VARCHAR(255) NOT NULL,
  password_algo     VARCHAR(32) NOT NULL,
  status            VARCHAR(32) NOT NULL,
  failed_attempts   INT NOT NULL DEFAULT 0,
  locked_until      CHAR(24),
  last_login_at     CHAR(24),
  password_changed_at CHAR(24) NOT NULL,
  created_at        CHAR(24) NOT NULL,
  PRIMARY KEY (platform_user_id),
  CHECK (status IN ('ACTIVE', 'SUSPENDED', 'LOCKED', 'RETIRED')),
  CHECK (failed_attempts >= 0),
  CONSTRAINT fk_platform_users_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_platform_users_operator_id FOREIGN KEY (operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email is globally unique: a login must identify exactly one account, or authentication would
-- need a tenant hint from the browser, which is untrusted input.
CREATE UNIQUE INDEX ux_platform_users_email ON platform_users (email);
CREATE INDEX ix_platform_users_tenant ON platform_users (tenant_id, status);
CREATE UNIQUE INDEX ux_platform_users_operator ON platform_users (operator_id);

CREATE TABLE IF NOT EXISTS platform_sessions (
  session_id        CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  -- Only the HASH of the session token is stored. A database read (a backup, a dump, an injection)
  -- must not yield a usable session.
  token_hash        CHAR(64) NOT NULL,
  platform_user_id  CHAR(36) NOT NULL,
  tenant_id         CHAR(36) NOT NULL,
  -- The workspace the session is currently acting in. Server-side: the browser never supplies it.
  active_workspace_id CHAR(36),
  csrf_token_hash   CHAR(64) NOT NULL,
  user_agent_digest CHAR(64),
  ip_digest         CHAR(64),
  created_at        CHAR(24) NOT NULL,
  last_seen_at      CHAR(24) NOT NULL,
  expires_at        CHAR(24) NOT NULL,
  revoked_at        CHAR(24),
  revoked_reason    VARCHAR(191),
  PRIMARY KEY (session_id),
  CONSTRAINT fk_platform_sessions_user FOREIGN KEY (platform_user_id) REFERENCES platform_users (platform_user_id),
  CONSTRAINT fk_platform_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_platform_sessions_workspace FOREIGN KEY (active_workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_platform_sessions_token ON platform_sessions (token_hash);
CREATE INDEX ix_platform_sessions_user ON platform_sessions (platform_user_id, expires_at);

-- Authentication attempt ledger: the rate-limit substrate and the security audit trail in one
-- append-only table. It records the outcome and a hashed identifier, never a password or a token.
CREATE TABLE IF NOT EXISTS auth_attempts (
  auth_attempt_id   CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  email_digest      CHAR(64) NOT NULL,
  ip_digest         CHAR(64) NOT NULL,
  outcome           VARCHAR(32) NOT NULL,
  attempted_at      CHAR(24) NOT NULL,
  PRIMARY KEY (auth_attempt_id),
  CHECK (outcome IN ('SUCCEEDED', 'BAD_CREDENTIALS', 'ACCOUNT_LOCKED', 'ACCOUNT_NOT_ACTIVE', 'RATE_LIMITED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_auth_attempts_email ON auth_attempts (email_digest, attempted_at);
CREATE INDEX ix_auth_attempts_ip ON auth_attempts (ip_digest, attempted_at);

DROP TRIGGER IF EXISTS trg_auth_attempts_no_update;
DELIMITER $$
CREATE TRIGGER trg_auth_attempts_no_update
  BEFORE UPDATE ON auth_attempts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auth_attempts is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_auth_attempts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_auth_attempts_no_delete
  BEFORE DELETE ON auth_attempts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auth_attempts is append-only';
END$$
DELIMITER ;
