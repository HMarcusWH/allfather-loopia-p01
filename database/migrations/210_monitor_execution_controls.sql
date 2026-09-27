-- Monitor completion P01: execution admission and prompt approval.
--
-- Loopia-local deployment controls, not canonical authority. Each definition row is immutable and
-- carries the reviewed owner decision it relies on (a document path and its SHA-256); a row cannot
-- mint authority by existing. Lifecycle is a separate append-only event stream, so suspension,
-- withdrawal and revocation never rewrite what was admitted. Nothing here writes M10.

CREATE TABLE IF NOT EXISTS monitor_prompt_approvals (
  prompt_approval_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  purpose VARCHAR(24) NOT NULL,
  role VARCHAR(64) NOT NULL,
  pack_version VARCHAR(64) NOT NULL,
  prompt_version_id VARCHAR(191) NOT NULL,
  binding_id VARCHAR(191) NOT NULL,
  prompt_sha256 CHAR(64) NOT NULL,
  schema_sha256 CHAR(64) NOT NULL,
  model_policy_id VARCHAR(96) NOT NULL,
  model_policy_digest CHAR(64) NOT NULL,
  evaluation_reference VARCHAR(191),
  owner_decision_ref VARCHAR(191) NOT NULL,
  owner_decision_sha256 CHAR(64) NOT NULL,
  approval_digest CHAR(64) NOT NULL,
  approved_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_prompt_approval_identity (tenant_id, workspace_id, purpose, prompt_sha256, schema_sha256, model_policy_digest),
  CHECK (purpose IN ('MODEL_EVALUATION', 'MANUAL_MONITOR')),
  CHECK (purpose <> 'MANUAL_MONITOR' OR evaluation_reference IS NOT NULL),
  CONSTRAINT fk_prompt_approval_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_prompt_approval_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_prompt_approval_operator FOREIGN KEY (approved_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_prompt_approval_events (
  prompt_approval_event_id CHAR(36) NOT NULL PRIMARY KEY,
  prompt_approval_id CHAR(36) NOT NULL,
  event_kind VARCHAR(16) NOT NULL,
  reason_code VARCHAR(96) NOT NULL,
  recorded_by_operator_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_prompt_approval_withdrawn (prompt_approval_id, event_kind),
  CHECK (event_kind IN ('WITHDRAWN')),
  CONSTRAINT fk_prompt_approval_event FOREIGN KEY (prompt_approval_id) REFERENCES monitor_prompt_approvals(prompt_approval_id),
  CONSTRAINT fk_prompt_approval_event_operator FOREIGN KEY (recorded_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_execution_grants (
  execution_grant_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  purpose VARCHAR(24) NOT NULL,
  mission_id CHAR(36),
  definition_digest CHAR(64),
  evaluation_manifest_sha256 CHAR(64),
  principal_operator_id CHAR(36) NOT NULL,
  definition_json LONGTEXT NOT NULL,
  grant_digest CHAR(64) NOT NULL,
  valid_from CHAR(24) NOT NULL,
  valid_until CHAR(24) NOT NULL,
  proposed_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_execution_grant_digest (grant_digest),
  CHECK (JSON_VALID(definition_json)),
  CHECK (purpose IN ('MODEL_EVALUATION', 'MANUAL_MONITOR', 'SCHEDULED_OBSERVATION')),
  CHECK (purpose NOT IN ('MANUAL_MONITOR', 'SCHEDULED_OBSERVATION') OR (mission_id IS NOT NULL AND definition_digest IS NOT NULL AND evaluation_manifest_sha256 IS NULL)),
  CHECK (purpose <> 'MODEL_EVALUATION' OR (mission_id IS NULL AND definition_digest IS NULL AND evaluation_manifest_sha256 IS NOT NULL)),
  CHECK (valid_from < valid_until),
  CONSTRAINT fk_execution_grant_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_execution_grant_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_execution_grant_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_execution_grant_principal FOREIGN KEY (principal_operator_id) REFERENCES operators(operator_id),
  CONSTRAINT fk_execution_grant_proposer FOREIGN KEY (proposed_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_execution_grant_mission ON monitor_execution_grants(tenant_id, workspace_id, mission_id, definition_digest);

CREATE TABLE IF NOT EXISTS monitor_execution_grant_events (
  execution_grant_event_id CHAR(36) NOT NULL PRIMARY KEY,
  execution_grant_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  event_kind VARCHAR(16) NOT NULL,
  owner_decision_ref VARCHAR(191),
  owner_decision_sha256 CHAR(64),
  reason_code VARCHAR(96) NOT NULL,
  recorded_by_operator_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_execution_grant_event_sequence (execution_grant_id, sequence_no),
  CHECK (event_kind IN ('ADMITTED', 'SUSPENDED', 'REVOKED')),
  CHECK (event_kind <> 'ADMITTED' OR (owner_decision_ref IS NOT NULL AND owner_decision_sha256 IS NOT NULL)),
  CONSTRAINT fk_execution_grant_event FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants(execution_grant_id),
  CONSTRAINT fk_execution_grant_event_operator FOREIGN KEY (recorded_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every unit of budget a grant has spent. Reserved inside the same transaction as the attempt or
-- capture it pays for, under a lock on the grant row, so concurrent work cannot overdraw it.
CREATE TABLE IF NOT EXISTS monitor_grant_consumptions (
  grant_consumption_id CHAR(36) NOT NULL PRIMARY KEY,
  execution_grant_id CHAR(36) NOT NULL,
  consumption_kind VARCHAR(24) NOT NULL,
  reference_id VARCHAR(96) NOT NULL,
  monitor_run_id CHAR(36),
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_grant_consumption_reference (execution_grant_id, consumption_kind, reference_id),
  CHECK (consumption_kind IN ('MODEL_ATTEMPT', 'SOURCE_CAPTURE')),
  CONSTRAINT fk_grant_consumption_grant FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants(execution_grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The decision at every admission checkpoint, allow or refuse. An audit trail, not a permission.
CREATE TABLE IF NOT EXISTS monitor_admission_decisions (
  admission_decision_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  execution_grant_id CHAR(36),
  checkpoint VARCHAR(48) NOT NULL,
  outcome VARCHAR(8) NOT NULL,
  reason_code VARCHAR(96) NOT NULL,
  monitor_run_id CHAR(36),
  request_digest CHAR(64),
  recorded_at CHAR(24) NOT NULL,
  CHECK (outcome IN ('ALLOW', 'REFUSE')),
  CONSTRAINT fk_admission_decision_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_admission_decision_grant FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants(execution_grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_admission_decision_grant ON monitor_admission_decisions(execution_grant_id, recorded_at);

DELIMITER $$
CREATE TRIGGER trg_prompt_approvals_no_update BEFORE UPDATE ON monitor_prompt_approvals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prompt approvals are immutable'; END$$
CREATE TRIGGER trg_prompt_approvals_no_delete BEFORE DELETE ON monitor_prompt_approvals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prompt approvals preserve history'; END$$
CREATE TRIGGER trg_prompt_approval_events_no_update BEFORE UPDATE ON monitor_prompt_approval_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prompt approval events are append-only'; END$$
CREATE TRIGGER trg_prompt_approval_events_no_delete BEFORE DELETE ON monitor_prompt_approval_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prompt approval events are append-only'; END$$
CREATE TRIGGER trg_execution_grants_no_update BEFORE UPDATE ON monitor_execution_grants FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution grant definitions are immutable'; END$$
CREATE TRIGGER trg_execution_grants_no_delete BEFORE DELETE ON monitor_execution_grants FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution grants preserve history'; END$$
CREATE TRIGGER trg_execution_grant_events_no_update BEFORE UPDATE ON monitor_execution_grant_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution grant events are append-only'; END$$
CREATE TRIGGER trg_execution_grant_events_no_delete BEFORE DELETE ON monitor_execution_grant_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution grant events are append-only'; END$$
CREATE TRIGGER trg_grant_consumptions_no_update BEFORE UPDATE ON monitor_grant_consumptions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consumed budget stays consumed'; END$$
CREATE TRIGGER trg_grant_consumptions_no_delete BEFORE DELETE ON monitor_grant_consumptions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consumed budget stays consumed'; END$$
CREATE TRIGGER trg_admission_decisions_no_update BEFORE UPDATE ON monitor_admission_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admission decisions are append-only'; END$$
CREATE TRIGGER trg_admission_decisions_no_delete BEFORE DELETE ON monitor_admission_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admission decisions are append-only'; END$$
DELIMITER ;
