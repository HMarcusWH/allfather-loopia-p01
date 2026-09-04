-- 130_provider_credentials
--
-- Loopia-specific (BYOK). Upstream has no credential store: model-assisted analysis is injected
-- there. A deployed platform must hold the tenant's own provider key, so this group exists — and
-- it holds CIPHERTEXT ONLY.
--
-- The encryption is AES-256-GCM under APP_MASTER_KEY, which lives in server-private configuration
-- and never in this database. A database dump therefore yields no usable key. Nonce, auth tag and
-- key version are stored alongside so a master-key rotation can re-wrap without guessing which
-- key a row was sealed under.

CREATE TABLE IF NOT EXISTS provider_credentials (
  provider_credential_id CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  tenant_id         CHAR(36) NOT NULL,
  provider          VARCHAR(48) NOT NULL,
  label             VARCHAR(255) NOT NULL,
  -- The three AES-256-GCM components. `ciphertext` is base64; `nonce` and `auth_tag` are hex.
  ciphertext        TEXT NOT NULL,
  nonce_hex         VARCHAR(64) NOT NULL,
  auth_tag_hex      VARCHAR(64) NOT NULL,
  key_version       INT NOT NULL DEFAULT 1,
  -- A non-reversible fingerprint so the UI can show WHICH key is configured without decrypting it
  -- and without echoing any part of the secret.
  credential_digest CHAR(64) NOT NULL,
  status            VARCHAR(32) NOT NULL,
  created_at        CHAR(24) NOT NULL,
  created_by_operator_id CHAR(36) NOT NULL,
  rotated_at        CHAR(24),
  revoked_at        CHAR(24),
  PRIMARY KEY (provider_credential_id),
  CHECK (provider IN ('ANTHROPIC', 'OPENAI', 'AZURE_OPENAI', 'MISTRAL', 'LOCAL', 'OTHER_DECLARED')),
  CHECK (status IN ('ACTIVE', 'ROTATED', 'REVOKED')),
  CHECK (key_version >= 1),
  CONSTRAINT fk_provider_credentials_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_provider_credentials_operator FOREIGN KEY (created_by_operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_provider_credentials_tenant ON provider_credentials (tenant_id, provider, status);

-- Provider call telemetry. Records what was asked and what it cost — never the prompt, never the
-- response, never the key. Model output is an INTERPRETATION and is governed elsewhere; this table
-- exists for metering and diagnosis only.
CREATE TABLE IF NOT EXISTS provider_call_records (
  provider_call_id  CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  tenant_id         CHAR(36) NOT NULL,
  workspace_id      CHAR(36) NOT NULL,
  provider          VARCHAR(48) NOT NULL,
  model             VARCHAR(191) NOT NULL,
  request_class     VARCHAR(48) NOT NULL,
  input_tokens      INT,
  output_tokens     INT,
  latency_ms        INT,
  outcome           VARCHAR(32) NOT NULL,
  failure_code      VARCHAR(191),
  correlation_id    CHAR(36) NOT NULL,
  called_at         CHAR(24) NOT NULL,
  PRIMARY KEY (provider_call_id),
  CHECK (outcome IN ('SUCCEEDED', 'FAILED', 'REFUSED_NO_CREDENTIAL', 'REFUSED_TIER', 'TIMEOUT')),
  CHECK (request_class IN ('SIGNAL_INTERPRETATION', 'UNCERTAINTY_SUMMARY', 'DRAFT_COMPOSITION', 'NORMALIZATION_ASSIST')),
  CONSTRAINT fk_provider_call_records_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_provider_call_records_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_provider_call_records_scope ON provider_call_records (tenant_id, workspace_id, called_at);

DROP TRIGGER IF EXISTS trg_provider_call_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_provider_call_records_no_update
  BEFORE UPDATE ON provider_call_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider_call_records is append-only';
END$$
DELIMITER ;
