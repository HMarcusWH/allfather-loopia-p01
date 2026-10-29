-- AN-01 family 310: CANDIDATE ONLY. NOT a runtime migration.
-- No active ExecutionAdmission purpose, provider authorization or source-use grant.
-- Install ONLY in independently disposable CLI test schema.
-- All reservations remain consumed/unknown; there is no automatic budget refund.

CREATE TABLE IF NOT EXISTS history_review_budget_candidates (
  history_review_budget_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  parent_grant_digest CHAR(64) NOT NULL,
  parent_max_attempts INT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_budget_candidate_id),
  UNIQUE KEY uq_an01_parent_budget (tenant_id,workspace_id,parent_grant_digest),
  UNIQUE KEY uq_an01_budget_scope (history_review_budget_candidate_id,tenant_id,workspace_id),
  CONSTRAINT fk_an01_budget_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_an01_budget_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CHECK (parent_max_attempts BETWEEN 1 AND 64),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_attempt_candidates (
  history_review_attempt_candidate_id CHAR(36) NOT NULL,
  history_review_budget_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  review_id VARCHAR(128) NOT NULL,
  attempt_id VARCHAR(128) NOT NULL,
  request_digest CHAR(64) NOT NULL,
  candidate_digest CHAR(64) NOT NULL,
  review_max_attempts INT NOT NULL,
  request_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_attempt_candidate_id),
  UNIQUE KEY uq_an01_attempt_identity (history_review_budget_candidate_id,attempt_id),
  UNIQUE KEY uq_an01_request_no_clone (history_review_budget_candidate_id,request_digest),
  UNIQUE KEY uq_an01_attempt_scope (history_review_attempt_candidate_id,tenant_id,workspace_id),
  KEY ix_an01_review_budget (history_review_budget_candidate_id,review_id),
  CONSTRAINT fk_an01_attempt_budget FOREIGN KEY
    (history_review_budget_candidate_id,tenant_id,workspace_id)
    REFERENCES history_review_budget_candidates
      (history_review_budget_candidate_id,tenant_id,workspace_id),
  CHECK (review_max_attempts BETWEEN 1 AND 4),
  CHECK (JSON_VALID(request_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_reconciliation_candidates (
  history_review_reconciliation_candidate_id CHAR(36) NOT NULL,
  history_review_attempt_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  reconciliation_digest CHAR(64) NOT NULL,
  reconciliation_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_reconciliation_candidate_id),
  UNIQUE KEY uq_an01_reconciliation_identity
    (history_review_attempt_candidate_id,reconciliation_digest),
  CONSTRAINT fk_an01_reconciliation_attempt FOREIGN KEY
    (history_review_attempt_candidate_id,tenant_id,workspace_id)
    REFERENCES history_review_attempt_candidates
      (history_review_attempt_candidate_id,tenant_id,workspace_id),
  CHECK (JSON_VALID(reconciliation_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER an01_budget_no_update BEFORE UPDATE ON history_review_budget_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_BUDGET_APPEND_ONLY'; END$$
CREATE TRIGGER an01_budget_no_delete BEFORE DELETE ON history_review_budget_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_BUDGET_APPEND_ONLY'; END$$
CREATE TRIGGER an01_attempt_no_update BEFORE UPDATE ON history_review_attempt_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_ATTEMPT_APPEND_ONLY'; END$$
CREATE TRIGGER an01_attempt_no_delete BEFORE DELETE ON history_review_attempt_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_ATTEMPT_APPEND_ONLY'; END$$
CREATE TRIGGER an01_reconcile_no_update BEFORE UPDATE ON history_review_reconciliation_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_RECONCILIATION_APPEND_ONLY'; END$$
CREATE TRIGGER an01_reconcile_no_delete BEFORE DELETE ON history_review_reconciliation_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN01_RECONCILIATION_APPEND_ONLY'; END$$
DELIMITER ;
