-- Monitor completion P05: durable model candidate bytes and explicit run reconciliation.
--
-- The provider attempt/result ledgers remain authoritative for dispatch history. Candidate bytes live
-- in the private content-addressed EvidenceStore; these rows bind only exact digests/identities.
-- Reconciliation is append-only and never reopens an old terminal run.

CREATE TABLE IF NOT EXISTS provider_model_candidate_artifacts (
  model_attempt_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  request_digest CHAR(64) NOT NULL,
  candidate_digest CHAR(64) NOT NULL,
  artifact_digest CHAR(64) NOT NULL,
  artifact_size INT NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  CHECK (artifact_size >= 0),
  CHECK (candidate_digest = artifact_digest),
  CONSTRAINT fk_model_candidate_artifact_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_model_candidate_artifact_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_model_candidate_artifact_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_model_candidate_artifact_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_model_candidate_artifact_tool_request FOREIGN KEY (tool_request_id) REFERENCES tool_requests(tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_model_candidate_artifact_scope
  ON provider_model_candidate_artifacts(tenant_id,workspace_id,mission_id);

CREATE TABLE IF NOT EXISTS monitor_run_reconciliations (
  reconciliation_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  monitor_run_id CHAR(36) NOT NULL,
  run_revision INT NOT NULL,
  model_stage VARCHAR(32) NOT NULL,
  disposition VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36),
  model_attempt_id CHAR(36),
  model_result_id CHAR(36),
  request_digest CHAR(64),
  candidate_digest CHAR(64),
  artifact_digest CHAR(64),
  observed_reason_code VARCHAR(96) NOT NULL,
  operator_reason_code VARCHAR(96),
  recorded_by_operator_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_monitor_reconciliation_revision_disposition (monitor_run_id,run_revision,disposition),
  CHECK (run_revision >= 0),
  CHECK (model_stage IN ('classifier','draft','critic','routing_critic')),
  CHECK (disposition IN ('ADOPTED_CANDIDATE','ADOPTED_REFUSAL','UNRESOLVED')),
  CHECK (disposition <> 'ADOPTED_CANDIDATE' OR (
    model_attempt_id IS NOT NULL AND model_result_id IS NOT NULL
    AND candidate_digest IS NOT NULL AND artifact_digest IS NOT NULL
    AND candidate_digest = artifact_digest
  )),
  CHECK (disposition <> 'ADOPTED_REFUSAL' OR model_result_id IS NOT NULL),
  CONSTRAINT fk_monitor_reconciliation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_monitor_reconciliation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_monitor_reconciliation_run FOREIGN KEY (monitor_run_id) REFERENCES monitor_runs(monitor_run_id),
  CONSTRAINT fk_monitor_reconciliation_tool_request FOREIGN KEY (tool_request_id) REFERENCES tool_requests(tool_request_id),
  CONSTRAINT fk_monitor_reconciliation_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_monitor_reconciliation_result FOREIGN KEY (model_result_id) REFERENCES provider_model_results(model_result_id),
  CONSTRAINT fk_monitor_reconciliation_operator FOREIGN KEY (recorded_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_monitor_reconciliation_scope
  ON monitor_run_reconciliations(tenant_id,workspace_id,monitor_run_id,run_revision);

DELIMITER $$
CREATE TRIGGER trg_model_candidate_artifacts_no_update BEFORE UPDATE ON provider_model_candidate_artifacts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider model candidate artifacts are append-only'; END$$
CREATE TRIGGER trg_model_candidate_artifacts_no_delete BEFORE DELETE ON provider_model_candidate_artifacts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider model candidate artifacts preserve history'; END$$
CREATE TRIGGER trg_monitor_reconciliations_no_update BEFORE UPDATE ON monitor_run_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor reconciliations are append-only'; END$$
CREATE TRIGGER trg_monitor_reconciliations_no_delete BEFORE DELETE ON monitor_run_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor reconciliations preserve history'; END$$
DELIMITER ;
