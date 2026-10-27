-- LI-08C / U05: CANDIDATE ONLY. NOT a production migration.
-- U05 source/cadence ownership is NOT RATIFIED. No cron, provider, M10,
-- notification or review watermark is activated by these append-only records.
-- Depends on proposed 270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql.

CREATE TABLE IF NOT EXISTS history_review_policies (
  history_review_policy_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  policy_version VARCHAR(96) NOT NULL,
  policy_digest CHAR(64) NOT NULL,
  policy_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_policy_id),
  UNIQUE KEY uq_history_policy_identity
    (tenant_id,workspace_id,competitor_id,question_id,policy_digest),
  UNIQUE KEY uq_history_policy_scoped
    (history_review_policy_id,tenant_id,workspace_id),
  CONSTRAINT fk_history_policy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_history_policy_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_history_policy_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (JSON_VALID(policy_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_policy_events (
  history_review_policy_event_id CHAR(36) NOT NULL,
  history_review_policy_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  event_kind VARCHAR(40) NOT NULL,
  event_digest CHAR(64) NOT NULL,
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_policy_event_id),
  UNIQUE KEY uq_history_policy_event
    (history_review_policy_id,event_kind,event_digest),
  CONSTRAINT fk_history_policy_event_scoped
    FOREIGN KEY (history_review_policy_id,tenant_id,workspace_id)
    REFERENCES history_review_policies(history_review_policy_id,tenant_id,workspace_id),
  CHECK (event_kind='CANDIDATE_RECORDED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_due_checks (
  history_review_due_check_id CHAR(36) NOT NULL,
  history_review_policy_id CHAR(36) NOT NULL,
  history_review_basis_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  evaluated_at_utc CHAR(20) NOT NULL,
  basis_digest CHAR(64) NOT NULL,
  candidate_digest CHAR(64) NOT NULL,
  disposition VARCHAR(48) NOT NULL,
  due_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_due_check_id),
  UNIQUE KEY uq_history_due_exact_clock
    (history_review_policy_id,history_review_basis_id,evaluated_at_utc),
  UNIQUE KEY uq_history_due_scoped
    (history_review_due_check_id,tenant_id,workspace_id),
  CONSTRAINT fk_history_due_policy_scoped
    FOREIGN KEY (history_review_policy_id,tenant_id,workspace_id)
    REFERENCES history_review_policies(history_review_policy_id,tenant_id,workspace_id),
  CONSTRAINT fk_history_due_basis_scoped
    FOREIGN KEY (history_review_basis_id,tenant_id,workspace_id)
    REFERENCES history_review_bases(history_review_basis_id,tenant_id,workspace_id),
  CHECK (JSON_VALID(due_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED'),
  CHECK (disposition IN (
    'DUE_CANDIDATE_HUMAN_REVIEW_ONLY','NO_NEW_BASIS_CANDIDATE',
    'NOT_DUE_CANDIDATE','HOLD_CURRENT_RIGHTS',
    'DEFERRED_BACKPRESSURE','DEFERRED_COALESCE_REVIEW','DEFERRED_COOLDOWN'
  ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER history_policies_no_update BEFORE UPDATE ON history_review_policies FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_POLICY_APPEND_ONLY'; END$$
CREATE TRIGGER history_policies_no_delete BEFORE DELETE ON history_review_policies FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_POLICY_APPEND_ONLY'; END$$
CREATE TRIGGER history_policy_events_no_update BEFORE UPDATE ON history_review_policy_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_POLICY_EVENT_APPEND_ONLY'; END$$
CREATE TRIGGER history_policy_events_no_delete BEFORE DELETE ON history_review_policy_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_POLICY_EVENT_APPEND_ONLY'; END$$
CREATE TRIGGER history_due_checks_no_update BEFORE UPDATE ON history_review_due_checks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_DUE_APPEND_ONLY'; END$$
CREATE TRIGGER history_due_checks_no_delete BEFORE DELETE ON history_review_due_checks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HISTORY_DUE_APPEND_ONLY'; END$$
DELIMITER ;
