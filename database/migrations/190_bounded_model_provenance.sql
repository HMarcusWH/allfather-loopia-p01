-- Loopia-local bounded provider composition, not a canonical PromptOps durability claim.
-- Exact bodies remain in private governed run bundles. These rows contain identities only.
CREATE TABLE IF NOT EXISTS provider_model_attempts (
  model_attempt_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  request_digest CHAR(64) NOT NULL,
  context_digest CHAR(64) NOT NULL,
  prompt_version_id VARCHAR(191) NOT NULL,
  binding_id VARCHAR(191) NOT NULL,
  output_schema_id VARCHAR(191) NOT NULL,
  prompt_sha256 CHAR(64) NOT NULL,
  schema_sha256 CHAR(64) NOT NULL,
  execution_mode VARCHAR(16) NOT NULL,
  reserved_at CHAR(24) NOT NULL,
  UNIQUE(tool_request_id),
  CHECK(execution_mode IN ('FIXTURE','LIVE')),
  FOREIGN KEY(tenant_id) REFERENCES tenants(tenant_id),
  FOREIGN KEY(workspace_id) REFERENCES workspaces(workspace_id),
  FOREIGN KEY(mission_id) REFERENCES missions(mission_id),
  FOREIGN KEY(tool_request_id) REFERENCES tool_requests(tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_provider_model_attempt_budget ON provider_model_attempts(tenant_id,workspace_id,mission_id);

CREATE TABLE IF NOT EXISTS provider_model_results (
  model_result_id CHAR(36) NOT NULL PRIMARY KEY,
  model_attempt_id CHAR(36) NOT NULL,
  provider_call_id CHAR(36),
  tool_request_id CHAR(36) NOT NULL,
  outcome VARCHAR(16) NOT NULL,
  candidate_digest CHAR(64),
  provider_request_id VARCHAR(128),
  failure_code VARCHAR(96),
  recorded_at CHAR(24) NOT NULL,
  UNIQUE(model_attempt_id),
  CHECK(outcome IN ('CANDIDATE','REFUSED')),
  FOREIGN KEY(model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  FOREIGN KEY(provider_call_id) REFERENCES provider_call_records(provider_call_id),
  FOREIGN KEY(tool_request_id) REFERENCES tool_requests(tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_provider_model_attempts_no_update BEFORE UPDATE ON provider_model_attempts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider_model_attempts is append-only'; END$$
CREATE TRIGGER trg_provider_model_attempts_no_delete BEFORE DELETE ON provider_model_attempts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider_model_attempts is append-only'; END$$
CREATE TRIGGER trg_provider_model_results_no_update BEFORE UPDATE ON provider_model_results FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider_model_results is append-only'; END$$
CREATE TRIGGER trg_provider_model_results_no_delete BEFORE DELETE ON provider_model_results FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider_model_results is append-only'; END$$
DELIMITER ;
