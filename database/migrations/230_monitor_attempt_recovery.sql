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
  CHECK (BINARY candidate_digest = BINARY artifact_digest),
  CONSTRAINT fk_model_candidate_artifact_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_model_candidate_artifact_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_model_candidate_artifact_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_model_candidate_artifact_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_model_candidate_artifact_tool_request FOREIGN KEY (tool_request_id) REFERENCES tool_requests(tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_model_candidate_artifact_scope
  ON provider_model_candidate_artifacts(tenant_id,workspace_id,mission_id);

CREATE TABLE IF NOT EXISTS monitor_model_attempt_bindings (
  model_attempt_id CHAR(36) NOT NULL PRIMARY KEY,
  monitor_run_id CHAR(36) NOT NULL,
  run_revision INT NOT NULL,
  model_stage VARCHAR(32) NOT NULL,
  request_artifact_digest CHAR(64) NOT NULL,
  request_digest CHAR(64) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_monitor_attempt_binding_stage (monitor_run_id,run_revision,model_stage),
  CHECK (run_revision >= 0),
  CHECK (model_stage IN ('classifier','draft','critic','routing_critic')),
  CONSTRAINT fk_monitor_attempt_binding_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_monitor_attempt_binding_run FOREIGN KEY (monitor_run_id) REFERENCES monitor_runs(monitor_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_monitor_attempt_binding_run
  ON monitor_model_attempt_bindings(monitor_run_id,run_revision,model_stage);

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
    tool_request_id IS NOT NULL AND model_attempt_id IS NOT NULL AND model_result_id IS NOT NULL
    AND request_digest IS NOT NULL AND candidate_digest IS NOT NULL AND artifact_digest IS NOT NULL
    AND BINARY candidate_digest = BINARY artifact_digest
  )),
  CHECK (disposition <> 'ADOPTED_REFUSAL' OR (
    tool_request_id IS NOT NULL AND model_attempt_id IS NOT NULL AND model_result_id IS NOT NULL
    AND request_digest IS NOT NULL AND candidate_digest IS NULL AND artifact_digest IS NULL
  )),
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
CREATE TRIGGER trg_model_candidate_artifacts_consistent BEFORE INSERT ON provider_model_candidate_artifacts FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM provider_model_attempts a
    WHERE a.model_attempt_id=NEW.model_attempt_id
      AND a.tenant_id=NEW.tenant_id
      AND a.workspace_id=NEW.workspace_id
      AND a.mission_id=NEW.mission_id
      AND a.tool_request_id=NEW.tool_request_id
      AND BINARY a.request_digest=BINARY NEW.request_digest
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider candidate artifact does not match its attempt';
  END IF;
END$$

CREATE TRIGGER trg_provider_model_results_require_artifact BEFORE INSERT ON provider_model_results FOR EACH ROW
BEGIN
  IF NEW.outcome='CANDIDATE' THEN
    IF NEW.candidate_digest IS NULL OR NEW.failure_code IS NOT NULL OR NEW.provider_call_id IS NULL
       OR NOT EXISTS (
         SELECT 1
         FROM provider_model_candidate_artifacts ca
         JOIN provider_model_attempts a ON a.model_attempt_id=ca.model_attempt_id
         JOIN provider_call_records pc ON pc.provider_call_id=NEW.provider_call_id
         JOIN tool_execution_outcomes teo ON teo.tool_request_id=NEW.tool_request_id
         WHERE ca.model_attempt_id=NEW.model_attempt_id
           AND ca.tool_request_id=NEW.tool_request_id
           AND a.tool_request_id=NEW.tool_request_id
           AND pc.tenant_id=a.tenant_id
           AND pc.workspace_id=a.workspace_id
           AND pc.outcome='SUCCEEDED'
           AND teo.outcome_status='SUCCEEDED'
           AND BINARY teo.result_digest=BINARY NEW.candidate_digest
           AND BINARY ca.candidate_digest=BINARY NEW.candidate_digest
           AND BINARY ca.artifact_digest=BINARY NEW.candidate_digest
       )
    THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'candidate provider result requires exact durable artifact';
    END IF;
  ELSEIF NEW.outcome='REFUSED' THEN
    IF NEW.candidate_digest IS NOT NULL OR NEW.failure_code IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused provider result shape invalid';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_monitor_attempt_bindings_consistent BEFORE INSERT ON monitor_model_attempt_bindings FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM provider_model_attempts a
    JOIN monitor_runs r ON r.monitor_run_id=NEW.monitor_run_id
    JOIN tool_requests tr ON tr.tool_request_id=a.tool_request_id
    WHERE a.model_attempt_id=NEW.model_attempt_id
      AND a.tenant_id=r.tenant_id
      AND a.workspace_id=r.workspace_id
      AND a.mission_id=r.mission_id
      AND (
        (r.execution_mode='NATIVE' AND a.execution_mode='LIVE')
        OR (r.execution_mode='FIXTURE' AND a.execution_mode='FIXTURE')
      )
      AND r.revision=NEW.run_revision
      AND (
        (r.stage='DIFFED' AND NEW.model_stage='classifier')
        OR (r.stage='ANALYZED' AND NEW.model_stage='draft')
        OR (r.stage='DRAFTED' AND NEW.model_stage='critic')
        OR (r.stage='ROUTING_PREPARED' AND NEW.model_stage='routing_critic')
      )
      AND BINARY a.request_digest=BINARY NEW.request_digest
      AND tr.tenant_id=r.tenant_id
      AND tr.workspace_id=r.workspace_id
      AND tr.idempotency_key=CONCAT('monitor.model.',NEW.monitor_run_id,'.',NEW.model_stage)
      AND NOT EXISTS (
        SELECT 1 FROM provider_attempt_purposes pp
        WHERE pp.model_attempt_id=a.model_attempt_id
          AND pp.execution_purpose='MODEL_EVALUATION'
      )
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor attempt binding crosses run/request scope';
  END IF;
END$$

CREATE TRIGGER trg_monitor_reconciliations_consistent BEFORE INSERT ON monitor_run_reconciliations FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM monitor_runs r
    JOIN operators o ON o.operator_id=NEW.recorded_by_operator_id
    WHERE r.monitor_run_id=NEW.monitor_run_id
      AND r.tenant_id=NEW.tenant_id
      AND r.workspace_id=NEW.workspace_id
      AND r.revision=NEW.run_revision
      AND o.tenant_id=NEW.tenant_id
      AND (
        (r.stage='DIFFED' AND NEW.model_stage='classifier')
        OR (r.stage='ANALYZED' AND NEW.model_stage='draft')
        OR (r.stage='DRAFTED' AND NEW.model_stage='critic')
        OR (r.stage='ROUTING_PREPARED' AND NEW.model_stage='routing_critic')
      )
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation run scope mismatch';
  END IF;

  IF NEW.tool_request_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM tool_requests tr
    WHERE tr.tool_request_id=NEW.tool_request_id
      AND tr.tenant_id=NEW.tenant_id
      AND tr.workspace_id=NEW.workspace_id
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation tool request scope mismatch';
  END IF;

  IF NEW.model_attempt_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
    FROM provider_model_attempts a
    JOIN monitor_runs r ON r.monitor_run_id=NEW.monitor_run_id
    JOIN monitor_model_attempt_bindings b ON b.model_attempt_id=a.model_attempt_id
    WHERE a.model_attempt_id=NEW.model_attempt_id
      AND a.tenant_id=NEW.tenant_id
      AND a.workspace_id=NEW.workspace_id
      AND a.mission_id=r.mission_id
      AND b.monitor_run_id=NEW.monitor_run_id
      AND b.run_revision=NEW.run_revision
      AND b.model_stage=NEW.model_stage
      AND (NEW.tool_request_id IS NULL OR a.tool_request_id=NEW.tool_request_id)
      AND (NEW.request_digest IS NULL OR (
        BINARY a.request_digest=BINARY NEW.request_digest
        AND BINARY b.request_digest=BINARY NEW.request_digest
      ))
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation attempt scope mismatch';
  END IF;

  IF NEW.model_result_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM provider_model_results mr
    WHERE mr.model_result_id=NEW.model_result_id
      AND (NEW.model_attempt_id IS NULL OR mr.model_attempt_id=NEW.model_attempt_id)
      AND (NEW.tool_request_id IS NULL OR mr.tool_request_id=NEW.tool_request_id)
      AND (
        NEW.disposition='UNRESOLVED'
        OR (
          NEW.disposition='ADOPTED_CANDIDATE'
          AND mr.outcome='CANDIDATE'
          AND NEW.candidate_digest IS NOT NULL
          AND BINARY mr.candidate_digest=BINARY NEW.candidate_digest
        )
        OR (
          NEW.disposition='ADOPTED_REFUSAL'
          AND mr.outcome='REFUSED'
          AND NEW.candidate_digest IS NULL
        )
      )
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation result mismatch';
  END IF;

  IF NEW.artifact_digest IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM provider_model_candidate_artifacts ca
    WHERE ca.model_attempt_id=NEW.model_attempt_id
      AND BINARY ca.artifact_digest=BINARY NEW.artifact_digest
      AND BINARY ca.candidate_digest=BINARY NEW.candidate_digest
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation candidate artifact mismatch';
  END IF;
END$$

CREATE TRIGGER trg_model_candidate_artifacts_no_update BEFORE UPDATE ON provider_model_candidate_artifacts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider model candidate artifacts are append-only'; END$$
CREATE TRIGGER trg_model_candidate_artifacts_no_delete BEFORE DELETE ON provider_model_candidate_artifacts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'provider model candidate artifacts preserve history'; END$$
CREATE TRIGGER trg_monitor_attempt_bindings_no_update BEFORE UPDATE ON monitor_model_attempt_bindings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor model attempt bindings are append-only'; END$$
CREATE TRIGGER trg_monitor_attempt_bindings_no_delete BEFORE DELETE ON monitor_model_attempt_bindings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor model attempt bindings preserve history'; END$$
CREATE TRIGGER trg_monitor_reconciliations_no_update BEFORE UPDATE ON monitor_run_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor reconciliations are append-only'; END$$
CREATE TRIGGER trg_monitor_reconciliations_no_delete BEFORE DELETE ON monitor_run_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor reconciliations preserve history'; END$$
DELIMITER ;
