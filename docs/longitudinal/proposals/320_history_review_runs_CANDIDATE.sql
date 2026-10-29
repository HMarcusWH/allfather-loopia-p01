-- AN-02 family 320: historical-review fixture journal CANDIDATE ONLY.
-- DO NOT MOVE TO database/migrations OR APPLY TO STAGING/PRODUCTION.
-- Not a Monitor run, provider attempt owner, real source-use grant or
-- factual/customer release. All contents are immutable synthetic metadata.
-- Runtime construction is blocked without U03/U06 owner decisions.

CREATE TABLE IF NOT EXISTS history_review_runs (
  history_review_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  review_ref_id VARCHAR(128) NOT NULL,
  run_digest CHAR(64) NOT NULL,
  request_candidate_digest CHAR(64) NOT NULL,
  basis_digest CHAR(64) NOT NULL,
  m17_run_digest CHAR(64) NULL,
  initial_state VARCHAR(48) NOT NULL,
  run_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_run_id),
  UNIQUE KEY uq_an02_run_digest (tenant_id,workspace_id,run_digest),
  UNIQUE KEY uq_an02_run_scoped (history_review_run_id,tenant_id,workspace_id),
  CONSTRAINT fk_an02_run_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_an02_run_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_an02_run_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (JSON_VALID(run_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_events (
  history_review_event_id CHAR(36) NOT NULL,
  history_review_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  event_kind VARCHAR(64) NOT NULL,
  state_from VARCHAR(48) NOT NULL,
  state_to VARCHAR(48) NOT NULL,
  event_digest CHAR(64) NOT NULL,
  previous_event_digest CHAR(64) NULL,
  event_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_event_id),
  UNIQUE KEY uq_an02_run_sequence (history_review_run_id,sequence_no),
  UNIQUE KEY uq_an02_run_event_digest (history_review_run_id,event_digest),
  CONSTRAINT fk_an02_event_run FOREIGN KEY
    (history_review_run_id,tenant_id,workspace_id)
    REFERENCES history_review_runs
      (history_review_run_id,tenant_id,workspace_id),
  CHECK (sequence_no BETWEEN 1 AND 16),
  CHECK (JSON_VALID(event_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_artifact_refs (
  history_review_artifact_ref_id CHAR(36) NOT NULL,
  history_review_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  artifact_role VARCHAR(20) NOT NULL,
  artifact_digest CHAR(64) NOT NULL,
  artifact_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_review_artifact_ref_id),
  UNIQUE KEY uq_an02_artifact_run_sequence (history_review_run_id,sequence_no),
  CONSTRAINT fk_an02_artifact_run FOREIGN KEY
    (history_review_run_id,tenant_id,workspace_id)
    REFERENCES history_review_runs
      (history_review_run_id,tenant_id,workspace_id),
  CHECK (sequence_no BETWEEN 1 AND 16),
  CHECK (artifact_role IN ('ANALYST','CRITIC','RECONCILIATION')),
  CHECK (JSON_VALID(artifact_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER an02_runs_no_update BEFORE UPDATE ON history_review_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_RUN_APPEND_ONLY'; END$$
CREATE TRIGGER an02_runs_no_delete BEFORE DELETE ON history_review_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_RUN_APPEND_ONLY'; END$$
CREATE TRIGGER an02_events_no_update BEFORE UPDATE ON history_review_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_EVENT_APPEND_ONLY'; END$$
CREATE TRIGGER an02_events_no_delete BEFORE DELETE ON history_review_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_EVENT_APPEND_ONLY'; END$$
CREATE TRIGGER an02_artifacts_no_update BEFORE UPDATE ON history_review_artifact_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_ARTIFACT_APPEND_ONLY'; END$$
CREATE TRIGGER an02_artifacts_no_delete BEFORE DELETE ON history_review_artifact_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN02_ARTIFACT_APPEND_ONLY'; END$$
DELIMITER ;
