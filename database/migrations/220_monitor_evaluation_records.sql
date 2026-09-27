-- Monitor completion P02: the evaluation lane.
--
-- An evaluation run executes named prompts against a frozen synthetic case manifest through the
-- product's own request, dispatch and candidate path. It never fetches a source and never releases
-- anything. These rows reference provider attempts and private content-addressed artifacts; they are
-- not a second evidence store. Human assessments decide grounding and usefulness — no model scores
-- itself. Everything is append-only.

CREATE TABLE IF NOT EXISTS monitor_evaluation_runs (
  evaluation_run_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  execution_grant_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  manifest_sha256 CHAR(64) NOT NULL,
  manifest_version VARCHAR(64) NOT NULL,
  selection VARCHAR(16) NOT NULL,
  selected_case_ids_json LONGTEXT NOT NULL,
  model_policy_digest CHAR(64) NOT NULL,
  execution_mode VARCHAR(16) NOT NULL,
  material_provenance VARCHAR(32) NOT NULL,
  initiated_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  CHECK (JSON_VALID(selected_case_ids_json)),
  CHECK (selection IN ('DEVELOPMENT', 'HELD_OUT', 'ALL')),
  CHECK (execution_mode IN ('FIXTURE', 'LIVE')),
  CHECK (material_provenance = 'SYNTHETIC_EVALUATION_CASE'),
  CONSTRAINT fk_evaluation_run_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_evaluation_run_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_evaluation_run_grant FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants(execution_grant_id),
  CONSTRAINT fk_evaluation_run_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_evaluation_run_operator FOREIGN KEY (initiated_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_evaluation_results (
  evaluation_result_id CHAR(36) NOT NULL PRIMARY KEY,
  evaluation_run_id CHAR(36) NOT NULL,
  case_id VARCHAR(96) NOT NULL,
  split VARCHAR(16) NOT NULL,
  category VARCHAR(64) NOT NULL,
  role VARCHAR(64) NOT NULL,
  critical TINYINT NOT NULL,
  request_digest CHAR(64),
  model_attempt_id CHAR(36),
  provider_call_id CHAR(36),
  outcome VARCHAR(16) NOT NULL,
  reason_code VARCHAR(96),
  candidate_digest CHAR(64),
  candidate_artifact_digest CHAR(64),
  mechanical_json LONGTEXT NOT NULL,
  mechanical_pass TINYINT NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_evaluation_result_case (evaluation_run_id, case_id),
  CHECK (JSON_VALID(mechanical_json)),
  CHECK (split IN ('DEVELOPMENT', 'HELD_OUT')),
  CHECK (outcome IN ('CANDIDATE', 'REFUSED')),
  CHECK (outcome <> 'CANDIDATE' OR (candidate_digest IS NOT NULL AND candidate_artifact_digest IS NOT NULL AND model_attempt_id IS NOT NULL AND provider_call_id IS NOT NULL)),
  CHECK (critical IN (0, 1)), CHECK (mechanical_pass IN (0, 1)),
  CONSTRAINT fk_evaluation_result_run FOREIGN KEY (evaluation_run_id) REFERENCES monitor_evaluation_runs(evaluation_run_id),
  CONSTRAINT fk_evaluation_result_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_evaluation_result_call FOREIGN KEY (provider_call_id) REFERENCES provider_call_records(provider_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_evaluation_assessments (
  evaluation_assessment_id CHAR(36) NOT NULL PRIMARY KEY,
  evaluation_result_id CHAR(36) NOT NULL,
  dimension VARCHAR(16) NOT NULL,
  verdict VARCHAR(16) NOT NULL,
  critical_failure TINYINT NOT NULL,
  critical_category VARCHAR(32),
  assessor_operator_id CHAR(36) NOT NULL,
  notes_safe VARCHAR(2000) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_evaluation_assessment_dimension (evaluation_result_id, dimension),
  CHECK (dimension IN ('GROUNDING', 'USEFULNESS')),
  CHECK (verdict IN ('PASS', 'FAIL', 'NOT_APPLICABLE')),
  CHECK (critical_failure IN (0, 1)),
  CHECK (critical_failure = 0 OR critical_category IN ('SCOPE', 'UNAUTHORIZED_ACTION', 'PRIVACY')),
  CHECK (critical_failure = 1 OR critical_category IS NULL),
  CONSTRAINT fk_evaluation_assessment_result FOREIGN KEY (evaluation_result_id) REFERENCES monitor_evaluation_results(evaluation_result_id),
  CONSTRAINT fk_evaluation_assessment_operator FOREIGN KEY (assessor_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Why an attempt was made, recorded beside it: a live API call with synthetic evaluation material is
-- LIVE + EVALUATION, not a real-world product mission. Historical LIVE/FIXTURE rows are not rewritten.
CREATE TABLE IF NOT EXISTS provider_attempt_purposes (
  model_attempt_id CHAR(36) NOT NULL PRIMARY KEY,
  execution_purpose VARCHAR(24) NOT NULL,
  execution_grant_id CHAR(36) NOT NULL,
  evaluation_run_id CHAR(36),
  monitor_run_id CHAR(36),
  material_provenance VARCHAR(32) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  CHECK (execution_purpose IN ('MODEL_EVALUATION', 'MANUAL_MONITOR')),
  CHECK (material_provenance IN ('SYNTHETIC_EVALUATION_CASE', 'PRODUCT_MISSION')),
  CHECK ((execution_purpose = 'MODEL_EVALUATION') = (material_provenance = 'SYNTHETIC_EVALUATION_CASE')),
  CHECK ((execution_purpose = 'MODEL_EVALUATION') = (evaluation_run_id IS NOT NULL)),
  CHECK ((execution_purpose = 'MANUAL_MONITOR') = (monitor_run_id IS NOT NULL)),
  CONSTRAINT fk_attempt_purpose_attempt FOREIGN KEY (model_attempt_id) REFERENCES provider_model_attempts(model_attempt_id),
  CONSTRAINT fk_attempt_purpose_grant FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants(execution_grant_id),
  CONSTRAINT fk_attempt_purpose_evaluation_run FOREIGN KEY (evaluation_run_id) REFERENCES monitor_evaluation_runs(evaluation_run_id),
  CONSTRAINT fk_attempt_purpose_monitor_run FOREIGN KEY (monitor_run_id) REFERENCES monitor_runs(monitor_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_evaluation_runs_no_update BEFORE UPDATE ON monitor_evaluation_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation runs are immutable'; END$$
CREATE TRIGGER trg_evaluation_runs_no_delete BEFORE DELETE ON monitor_evaluation_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation runs preserve history'; END$$
CREATE TRIGGER trg_evaluation_results_no_update BEFORE UPDATE ON monitor_evaluation_results FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation results are append-only'; END$$
CREATE TRIGGER trg_evaluation_results_no_delete BEFORE DELETE ON monitor_evaluation_results FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation results are append-only'; END$$
CREATE TRIGGER trg_evaluation_assessments_no_update BEFORE UPDATE ON monitor_evaluation_assessments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation assessments are append-only'; END$$
CREATE TRIGGER trg_evaluation_assessments_no_delete BEFORE DELETE ON monitor_evaluation_assessments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation assessments are append-only'; END$$
CREATE TRIGGER trg_attempt_purposes_no_update BEFORE UPDATE ON provider_attempt_purposes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt purposes are append-only'; END$$
CREATE TRIGGER trg_attempt_purposes_no_delete BEFORE DELETE ON provider_attempt_purposes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt purposes are append-only'; END$$
DELIMITER ;
