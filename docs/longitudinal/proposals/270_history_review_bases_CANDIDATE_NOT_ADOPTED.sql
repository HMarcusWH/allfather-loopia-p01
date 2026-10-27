-- LI-05C / U03: CANDIDATE ONLY. DO NOT PUT INTO database/migrations.
-- Owner-authenticated historical source eligibility and permitted historical reuse
-- are NOT RATIFIED. No runtime writer, customer output or source-copy grant.
-- This isolated additive DDL is exercised only against disposable MariaDB QA.

CREATE TABLE IF NOT EXISTS history_review_bases (
  history_review_basis_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  basis_digest CHAR(64) NOT NULL,
  manifest_json LONGTEXT NOT NULL,
  period_start_utc CHAR(20) NOT NULL,
  period_end_utc CHAR(20) NOT NULL,
  knowledge_cutoff_utc CHAR(20) NOT NULL,
  selected_count INT NOT NULL,
  selection_integrity VARCHAR(32) NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_basis_id),
  UNIQUE KEY uq_history_basis_scope_digest
    (tenant_id, workspace_id, competitor_id, question_id, basis_digest),
  UNIQUE KEY uq_history_basis_scoped_id
    (history_review_basis_id, tenant_id, workspace_id),
  KEY ix_history_basis_period
    (tenant_id,workspace_id,competitor_id,question_id,period_start_utc),
  CONSTRAINT fk_history_basis_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_history_basis_workspace
    FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_history_basis_competitor
    FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (JSON_VALID(manifest_json)),
  CHECK (selected_count BETWEEN 0 AND 32),
  CHECK (period_start_utc < period_end_utc),
  CHECK (period_start_utc <= knowledge_cutoff_utc),
  CHECK (selection_integrity IN ('COMPLETE_WITHIN_INPUT','INSUFFICIENT_BASIS')),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_basis_refs (
  history_review_basis_ref_id CHAR(36) NOT NULL,
  history_review_basis_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ordinal_no INT NOT NULL,
  ref_id VARCHAR(128) NOT NULL,
  source_ref_id VARCHAR(128) NOT NULL,
  evidence_id VARCHAR(128) NOT NULL,
  snapshot_id VARCHAR(128) NOT NULL,
  observed_at_utc CHAR(20) NOT NULL,
  known_at_utc CHAR(20) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  semantic_polarity VARCHAR(16) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_basis_ref_id),
  UNIQUE KEY uq_history_basis_ref_ordinal(history_review_basis_id, ordinal_no),
  UNIQUE KEY uq_history_basis_ref_identity(history_review_basis_id, ref_id),
  CONSTRAINT fk_history_basis_ref_scoped
    FOREIGN KEY (history_review_basis_id,tenant_id,workspace_id)
    REFERENCES history_review_bases(history_review_basis_id,tenant_id,workspace_id),
  CHECK (ordinal_no BETWEEN 0 AND 31),
  CHECK (semantic_polarity IN ('SUPPORT','CONTRARY','UNKNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable candidates: no silent overwrite or cascading deletion.
DELIMITER $$
CREATE TRIGGER history_bases_no_update BEFORE UPDATE ON history_review_bases FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_BASIS_APPEND_ONLY'; END$$
CREATE TRIGGER history_bases_no_delete BEFORE DELETE ON history_review_bases FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_BASIS_APPEND_ONLY'; END$$
CREATE TRIGGER history_basis_refs_no_update BEFORE UPDATE ON history_review_basis_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_BASIS_REF_APPEND_ONLY'; END$$
CREATE TRIGGER history_basis_refs_no_delete BEFORE DELETE ON history_review_basis_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_BASIS_REF_APPEND_ONLY'; END$$
DELIMITER ;
