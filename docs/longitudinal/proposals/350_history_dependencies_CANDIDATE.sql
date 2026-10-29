-- IV-01 / family 350: candidate-only dependency and invalidation journal.
-- DO NOT COPY INTO database/migrations or apply on staging/production.
-- Not an adopted source/retention/legal erasure authority. No raw bytes.
-- Exercise ONLY in disposable CLI TEST database with explicit fixture marker.

CREATE TABLE IF NOT EXISTS history_dependency_index_candidates (
  history_dependency_index_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  graph_digest CHAR(64) NOT NULL,
  graph_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_dependency_index_id),
  UNIQUE KEY uq_iv01_graph_identity
    (tenant_id,workspace_id,competitor_id,question_id,graph_digest),
  UNIQUE KEY uq_iv01_graph_scope_id
    (history_dependency_index_id,tenant_id,workspace_id),
  CONSTRAINT fk_iv01_graph_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_iv01_graph_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_iv01_graph_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (JSON_VALID(graph_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_invalidation_events (
  history_invalidation_event_id CHAR(36) NOT NULL,
  history_dependency_index_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  cause_kind VARCHAR(64) NOT NULL,
  cause_digest CHAR(64) NOT NULL,
  cause_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_invalidation_event_id),
  UNIQUE KEY uq_iv01_event_graph_cause
    (history_dependency_index_id,cause_digest),
  UNIQUE KEY uq_iv01_event_scoped_id
    (history_invalidation_event_id,tenant_id,workspace_id),
  CONSTRAINT fk_iv01_event_graph_scoped
    FOREIGN KEY (history_dependency_index_id,tenant_id,workspace_id)
    REFERENCES history_dependency_index_candidates
      (history_dependency_index_id,tenant_id,workspace_id),
  CHECK (JSON_VALID(cause_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_invalidation_receipts (
  history_invalidation_receipt_id CHAR(36) NOT NULL,
  history_invalidation_event_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  cursor_start INT NOT NULL,
  next_cursor INT NULL,
  disposition VARCHAR(48) NOT NULL,
  receipt_digest CHAR(64) NOT NULL,
  receipt_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (history_invalidation_receipt_id),
  UNIQUE KEY uq_iv01_receipt_cursor
    (history_invalidation_event_id,cursor_start),
  CONSTRAINT fk_iv01_receipt_event_scoped
    FOREIGN KEY (history_invalidation_event_id,tenant_id,workspace_id)
    REFERENCES history_invalidation_events
      (history_invalidation_event_id,tenant_id,workspace_id),
  CHECK (JSON_VALID(receipt_json)),
  CHECK (cursor_start BETWEEN 0 AND 256),
  CHECK (next_cursor IS NULL OR next_cursor>cursor_start),
  CHECK (disposition IN (
    'PROPAGATION_INCOMPLETE_HOLD','COMPLETE_CANDIDATE_HOLD',
    'HOLD_CYCLE','HOLD_EXCESS_FANOUT'
  )),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER iv01_graph_no_update BEFORE UPDATE ON history_dependency_index_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_GRAPH'; END$$
CREATE TRIGGER iv01_graph_no_delete BEFORE DELETE ON history_dependency_index_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_GRAPH'; END$$
CREATE TRIGGER iv01_event_no_update BEFORE UPDATE ON history_invalidation_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_EVENT'; END$$
CREATE TRIGGER iv01_event_no_delete BEFORE DELETE ON history_invalidation_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_EVENT'; END$$
CREATE TRIGGER iv01_receipt_no_update BEFORE UPDATE ON history_invalidation_receipts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_RECEIPT'; END$$
CREATE TRIGGER iv01_receipt_no_delete BEFORE DELETE ON history_invalidation_receipts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IV01_APPEND_ONLY_RECEIPT'; END$$
DELIMITER ;
