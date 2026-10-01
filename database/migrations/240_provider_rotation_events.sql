-- P11: append-only provider master-key rewrap receipts.
--
-- This table stores identities, versions and digests only. It never stores a master key, provider
-- plaintext, .env value or decrypted credential. Rewrap is Loopia-local operational durability,
-- not M10 authority and not permission to execute a model.

CREATE TABLE IF NOT EXISTS provider_credential_rotation_events (
  rotation_event_id CHAR(36) NOT NULL PRIMARY KEY,
  rotation_batch_id CHAR(36) NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  schema_version VARCHAR(32) NOT NULL DEFAULT '1',
  tenant_id CHAR(36) NOT NULL,
  provider_credential_id CHAR(36) NOT NULL,
  rotation_kind VARCHAR(32) NOT NULL,
  from_key_version INT NOT NULL,
  to_key_version INT NOT NULL,
  old_credential_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  new_credential_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  old_sealed_payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  new_sealed_payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  rotated_by_operator_id CHAR(36) NOT NULL,
  rotated_at CHAR(24) NOT NULL,

  UNIQUE KEY uq_provider_rotation_credential_sequence (provider_credential_id, sequence_no),
  UNIQUE KEY uq_provider_rotation_batch_credential (rotation_batch_id, provider_credential_id),
  KEY ix_provider_rotation_tenant_time (tenant_id, rotated_at, rotation_event_id),

  CHECK (rotation_kind = 'MASTER_KEY_REWRAP'),
  CHECK (from_key_version >= 1),
  CHECK (to_key_version >= 1),
  CHECK (from_key_version <> to_key_version),
  CHECK (old_credential_digest REGEXP '^[0-9a-f]{64}$'),
  CHECK (new_credential_digest REGEXP '^[0-9a-f]{64}$'),
  CHECK (old_sealed_payload_sha256 REGEXP '^[0-9a-f]{64}$'),
  CHECK (new_sealed_payload_sha256 REGEXP '^[0-9a-f]{64}$'),
  CHECK (plan_digest REGEXP '^[0-9a-f]{64}$'),

  CONSTRAINT fk_provider_rotation_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_provider_rotation_credential
    FOREIGN KEY (provider_credential_id) REFERENCES provider_credentials(provider_credential_id),
  CONSTRAINT fk_provider_rotation_operator
    FOREIGN KEY (rotated_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_provider_credential_rotation_events_no_update;
DELIMITER $$
CREATE TRIGGER trg_provider_credential_rotation_events_no_update
  BEFORE UPDATE ON provider_credential_rotation_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider_credential_rotation_events is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_provider_credential_rotation_events_no_delete;
DELIMITER $$
CREATE TRIGGER trg_provider_credential_rotation_events_no_delete
  BEFORE DELETE ON provider_credential_rotation_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider_credential_rotation_events is append-only';
END$$
DELIMITER ;
