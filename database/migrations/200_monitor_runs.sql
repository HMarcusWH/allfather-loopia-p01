-- Deployment composition state only. M04/M05/M07/M08/M10 retain their authorities.
-- No prompt/body/key is stored here: permitted private artifacts are addressed by digest.
CREATE TABLE IF NOT EXISTS monitor_mission_configs (
  mission_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  definition_json LONGTEXT NOT NULL,
  definition_digest CHAR(64) NOT NULL,
  execution_mode VARCHAR(16) NOT NULL,
  configured_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  CHECK (JSON_VALID(definition_json)), CHECK (execution_mode IN ('FIXTURE','NATIVE')),
  CONSTRAINT fk_monitor_config_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_monitor_config_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_monitor_config_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_monitor_config_operator FOREIGN KEY (configured_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_runs (
  monitor_run_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  initiated_by_operator_id CHAR(36) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  definition_json LONGTEXT NOT NULL,
  definition_digest CHAR(64) NOT NULL,
  execution_mode VARCHAR(16) NOT NULL,
  stage VARCHAR(32) NOT NULL,
  cursor_json LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 0,
  event_digest CHAR(64),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_monitor_run_scope_key (tenant_id, workspace_id, idempotency_key),
  CHECK (JSON_VALID(definition_json)), CHECK (JSON_VALID(cursor_json)),
  CHECK (execution_mode IN ('FIXTURE', 'NATIVE')),
  CHECK (stage IN ('NEW', 'CAPTURED', 'DIFFED', 'ANALYZED', 'DRAFTED', 'REVIEW_REQUIRED', 'P01_RELEASED', 'ROUTING_PREPARED', 'ROUTING_REVIEW', 'COMPLETE', 'BASELINE', 'NO_CHANGE', 'BLOCKED', 'FAILED', 'STOPPED')),
  CONSTRAINT fk_monitor_run_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_monitor_run_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_monitor_run_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_monitor_run_operator FOREIGN KEY (initiated_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_run_events (
  monitor_event_id CHAR(36) NOT NULL PRIMARY KEY,
  monitor_run_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  event_kind VARCHAR(48) NOT NULL,
  actor_operator_id CHAR(36) NOT NULL,
  previous_digest CHAR(64),
  event_digest CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_monitor_event_sequence (monitor_run_id, sequence_no),
  CHECK (JSON_VALID(payload_json)),
  CONSTRAINT fk_monitor_event_run FOREIGN KEY (monitor_run_id) REFERENCES monitor_runs(monitor_run_id),
  CONSTRAINT fk_monitor_event_operator FOREIGN KEY (actor_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only binding to permitted M05 private artifacts; never modify a prior capture.
CREATE TABLE IF NOT EXISTS monitor_capture_payloads (
  source_snapshot_id CHAR(36) NOT NULL PRIMARY KEY,
  raw_artifact_id CHAR(36) NOT NULL,
  payload_digest CHAR(64) NOT NULL,
  created_at CHAR(24) NOT NULL,
  CONSTRAINT fk_monitor_payload_snapshot FOREIGN KEY (source_snapshot_id) REFERENCES source_snapshots(source_snapshot_id),
  CONSTRAINT fk_monitor_payload_artifact FOREIGN KEY (raw_artifact_id) REFERENCES raw_artifacts(raw_artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_monitor_config_no_update BEFORE UPDATE ON monitor_mission_configs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor mission configuration is frozen'; END$$
CREATE TRIGGER trg_monitor_config_no_delete BEFORE DELETE ON monitor_mission_configs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor mission configuration preserves history'; END$$
CREATE TRIGGER trg_monitor_run_definition_frozen BEFORE UPDATE ON monitor_runs FOR EACH ROW
BEGIN
  IF NOT (OLD.tenant_id <=> NEW.tenant_id) OR NOT (OLD.workspace_id <=> NEW.workspace_id)
     OR NOT (OLD.mission_id <=> NEW.mission_id) OR NOT (OLD.initiated_by_operator_id <=> NEW.initiated_by_operator_id)
     OR NOT (OLD.idempotency_key <=> NEW.idempotency_key) OR NOT (OLD.definition_json <=> NEW.definition_json)
     OR NOT (OLD.definition_digest <=> NEW.definition_digest) OR NOT (OLD.execution_mode <=> NEW.execution_mode)
     OR NOT (OLD.created_at <=> NEW.created_at) OR NOT (OLD.monitor_run_id <=> NEW.monitor_run_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor run definition is frozen';
  END IF;
END$$
CREATE TRIGGER trg_monitor_runs_no_delete BEFORE DELETE ON monitor_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor runs preserve history'; END$$
CREATE TRIGGER trg_monitor_events_no_update BEFORE UPDATE ON monitor_run_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor events are append-only'; END$$
CREATE TRIGGER trg_monitor_events_no_delete BEFORE DELETE ON monitor_run_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor events are append-only'; END$$
CREATE TRIGGER trg_monitor_payload_no_update BEFORE UPDATE ON monitor_capture_payloads FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor capture binding is append-only'; END$$
CREATE TRIGGER trg_monitor_payload_no_delete BEFORE DELETE ON monitor_capture_payloads FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor capture binding is append-only'; END$$
DELIMITER ;
