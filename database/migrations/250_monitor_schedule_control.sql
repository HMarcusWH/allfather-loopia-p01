-- 250_monitor_schedule_control
--
-- Completion plan P07: recurring observation with bounded per-occurrence authority. Loopia-local
-- deployment control, not canonical law. Nothing here schedules anything by existing:
--
--   * a schedule VERSION is an immutable definition; it becomes usable only through an ADMITTED
--     lifecycle event that cites a reviewed owner decision naming its exact digest, and it is only
--     ever used together with an ADMITTED SCHEDULED_OBSERVATION execution grant (migration 210);
--   * an OCCURRENCE is a reservation, unique per (schedule, version, scheduled instant), so a
--     duplicate tick cannot reserve the same slot twice;
--   * an OUTCOME is the one append-only result of an occurrence;
--   * a MONITOR_STEP job is a typed job over one occurrence. It carries no callback, SQL, URL or
--     workflow definition — only the occurrence it names;
--   * a run event written by the scheduler's SERVICE principal is a v2 event naming the service and
--     the delegating operator separately. v1 events, and their digests, are unchanged.
--
-- `observation_mode` admits exactly one value: OBSERVE_AND_QUEUE. A scheduled occurrence captures
-- and compares, then queues the run for a human. There is no stored value meaning "infer unattended".

CREATE TABLE IF NOT EXISTS monitor_schedules (
  monitor_schedule_version_id CHAR(36) NOT NULL,
  monitor_schedule_id CHAR(36) NOT NULL,
  schedule_version INT NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  definition_digest CHAR(64) NOT NULL,
  execution_grant_id CHAR(36) NOT NULL,
  service_identity_id CHAR(36) NOT NULL,
  delegating_operator_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  cadence_seconds INT NOT NULL,
  catch_up_policy VARCHAR(32) NOT NULL,
  observation_mode VARCHAR(32) NOT NULL,
  max_occurrences INT NOT NULL,
  valid_from CHAR(24) NOT NULL,
  valid_until CHAR(24) NOT NULL,
  definition_json LONGTEXT NOT NULL,
  schedule_digest CHAR(64) NOT NULL,
  proposed_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitor_schedule_version_id),
  UNIQUE KEY uq_monitor_schedule_version (monitor_schedule_id, schedule_version),
  UNIQUE KEY uq_monitor_schedule_digest (schedule_digest),
  KEY ix_monitor_schedules_scope (tenant_id, workspace_id),
  KEY ix_monitor_schedules_source (tenant_id, monitored_source_id),
  CHECK (schedule_version >= 1),
  -- The cadence floor (hourly) and ceiling (31 days) are a property of the schema, not of a caller.
  CHECK (cadence_seconds BETWEEN 3600 AND 2678400),
  CHECK (catch_up_policy IN ('LATEST_ONLY')),
  CHECK (observation_mode IN ('OBSERVE_AND_QUEUE')),
  CHECK (max_occurrences BETWEEN 1 AND 500),
  CHECK (valid_until > valid_from),
  CHECK (JSON_VALID(definition_json)),
  CONSTRAINT fk_monitor_schedules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_monitor_schedules_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_monitor_schedules_mission FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_monitor_schedules_grant FOREIGN KEY (execution_grant_id) REFERENCES monitor_execution_grants (execution_grant_id),
  CONSTRAINT fk_monitor_schedules_service FOREIGN KEY (service_identity_id) REFERENCES service_identities (service_identity_id),
  CONSTRAINT fk_monitor_schedules_delegator FOREIGN KEY (delegating_operator_id) REFERENCES operators (operator_id),
  CONSTRAINT fk_monitor_schedules_source FOREIGN KEY (monitored_source_id) REFERENCES monitored_sources (monitored_source_id),
  CONSTRAINT fk_monitor_schedules_proposer FOREIGN KEY (proposed_by_operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_schedule_events (
  monitor_schedule_event_id CHAR(36) NOT NULL,
  monitor_schedule_version_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  event_kind VARCHAR(16) NOT NULL,
  reason_code VARCHAR(96) NOT NULL,
  owner_decision_ref VARCHAR(191),
  owner_decision_sha256 CHAR(64),
  actor_operator_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitor_schedule_event_id),
  UNIQUE KEY uq_monitor_schedule_event_sequence (monitor_schedule_version_id, sequence_no),
  CHECK (sequence_no >= 1),
  CHECK (event_kind IN ('ADMITTED', 'SUSPENDED', 'REVOKED')),
  -- Admission, and only admission, cites an owner decision.
  CHECK ((event_kind = 'ADMITTED') = (owner_decision_ref IS NOT NULL AND owner_decision_sha256 IS NOT NULL)),
  CONSTRAINT fk_monitor_schedule_events_version FOREIGN KEY (monitor_schedule_version_id) REFERENCES monitor_schedules (monitor_schedule_version_id),
  CONSTRAINT fk_monitor_schedule_events_actor FOREIGN KEY (actor_operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_schedule_occurrences (
  monitor_schedule_occurrence_id CHAR(36) NOT NULL,
  monitor_schedule_version_id CHAR(36) NOT NULL,
  monitor_schedule_id CHAR(36) NOT NULL,
  schedule_version INT NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  scheduled_for CHAR(24) NOT NULL,
  slot_index INT NOT NULL,
  -- Missed slots collapsed into this occurrence by the LATEST_ONLY catch-up policy. Recorded so an
  -- outage is visible; never replayed.
  skipped_slots INT NOT NULL DEFAULT 0,
  reserved_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitor_schedule_occurrence_id),
  UNIQUE KEY uq_monitor_schedule_occurrence (monitor_schedule_id, schedule_version, scheduled_for),
  KEY ix_monitor_schedule_occurrences_version (monitor_schedule_version_id, slot_index),
  CHECK (slot_index >= 0),
  CHECK (skipped_slots >= 0),
  CONSTRAINT fk_monitor_schedule_occurrences_version FOREIGN KEY (monitor_schedule_version_id) REFERENCES monitor_schedules (monitor_schedule_version_id),
  CONSTRAINT fk_monitor_schedule_occurrences_series FOREIGN KEY (monitor_schedule_id, schedule_version) REFERENCES monitor_schedules (monitor_schedule_id, schedule_version),
  CONSTRAINT fk_monitor_schedule_occurrences_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_monitor_schedule_occurrences_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_schedule_occurrence_outcomes (
  monitor_schedule_outcome_id CHAR(36) NOT NULL,
  monitor_schedule_occurrence_id CHAR(36) NOT NULL,
  monitor_run_id CHAR(36),
  outcome VARCHAR(32) NOT NULL,
  run_stage VARCHAR(32),
  run_revision INT,
  reason_code VARCHAR(96),
  recorded_by_service_identity_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitor_schedule_outcome_id),
  UNIQUE KEY uq_monitor_schedule_outcome_occurrence (monitor_schedule_occurrence_id),
  KEY ix_monitor_schedule_outcomes_waiting (outcome, recorded_at),
  CHECK (outcome IN ('WAITING_FOR_OPERATOR', 'BASELINE_RECORDED', 'NO_CHANGE', 'BLOCKED', 'REFUSED')),
  -- Waiting for an operator always names the run and the exact stage it waits at, which is the
  -- model-request inspection boundary. A scheduler outcome can never claim a released stage.
  CHECK (outcome <> 'WAITING_FOR_OPERATOR' OR (monitor_run_id IS NOT NULL AND run_stage = 'DIFFED' AND run_revision IS NOT NULL)),
  CHECK (run_stage IS NULL OR run_stage IN ('NEW', 'CAPTURED', 'BASELINE', 'DIFFED', 'NO_CHANGE', 'BLOCKED', 'FAILED', 'STOPPED')),
  CHECK (outcome NOT IN ('BLOCKED', 'REFUSED') OR reason_code IS NOT NULL),
  CONSTRAINT fk_monitor_schedule_outcomes_occurrence FOREIGN KEY (monitor_schedule_occurrence_id) REFERENCES monitor_schedule_occurrences (monitor_schedule_occurrence_id),
  CONSTRAINT fk_monitor_schedule_outcomes_run FOREIGN KEY (monitor_run_id) REFERENCES monitor_runs (monitor_run_id),
  CONSTRAINT fk_monitor_schedule_outcomes_service FOREIGN KEY (recorded_by_service_identity_id) REFERENCES service_identities (service_identity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- the journal's actor, v2

-- A v1 event names the human operator who acted, exactly as before. A v2 event is written only by
-- the scheduler's SERVICE principal and names the service and the delegating operator SEPARATELY,
-- so a scheduled capture can never read as a human act. The digest format follows the version
-- (app/Workflow/RunJournal.php); v1 rows keep their original digests.
ALTER TABLE monitor_run_events
  MODIFY COLUMN actor_operator_id CHAR(36) NULL,
  ADD COLUMN event_version TINYINT NOT NULL DEFAULT 1 AFTER event_kind,
  ADD COLUMN actor_service_identity_id CHAR(36) NULL AFTER actor_operator_id,
  ADD COLUMN delegating_operator_id CHAR(36) NULL AFTER actor_service_identity_id,
  ADD CONSTRAINT ck_monitor_run_events_actor_version CHECK (
    (event_version = 1 AND actor_operator_id IS NOT NULL AND actor_service_identity_id IS NULL AND delegating_operator_id IS NULL)
    OR (event_version = 2 AND actor_operator_id IS NULL AND actor_service_identity_id IS NOT NULL AND delegating_operator_id IS NOT NULL)
  ),
  ADD CONSTRAINT fk_monitor_event_service FOREIGN KEY (actor_service_identity_id) REFERENCES service_identities (service_identity_id),
  ADD CONSTRAINT fk_monitor_event_delegator FOREIGN KEY (delegating_operator_id) REFERENCES operators (operator_id);

ALTER TABLE monitor_runs
  ADD COLUMN initiated_by_service_identity_id CHAR(36) NULL AFTER initiated_by_operator_id,
  ADD CONSTRAINT fk_monitor_runs_service FOREIGN KEY (initiated_by_service_identity_id) REFERENCES service_identities (service_identity_id);

-- The definition freeze now covers the new initiator column too. Replaced, not edited in 200.
DROP TRIGGER IF EXISTS trg_monitor_run_definition_frozen;
DELIMITER $$
CREATE TRIGGER trg_monitor_run_definition_frozen BEFORE UPDATE ON monitor_runs FOR EACH ROW
BEGIN
  IF NOT (OLD.tenant_id <=> NEW.tenant_id) OR NOT (OLD.workspace_id <=> NEW.workspace_id)
     OR NOT (OLD.mission_id <=> NEW.mission_id) OR NOT (OLD.initiated_by_operator_id <=> NEW.initiated_by_operator_id)
     OR NOT (OLD.initiated_by_service_identity_id <=> NEW.initiated_by_service_identity_id)
     OR NOT (OLD.idempotency_key <=> NEW.idempotency_key) OR NOT (OLD.definition_json <=> NEW.definition_json)
     OR NOT (OLD.definition_digest <=> NEW.definition_digest) OR NOT (OLD.execution_mode <=> NEW.execution_mode)
     OR NOT (OLD.created_at <=> NEW.created_at) OR NOT (OLD.monitor_run_id <=> NEW.monitor_run_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor run definition is frozen';
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------- the typed MONITOR_STEP job

-- Migration 110 declared the job vocabulary as unnamed CHECKs. MariaDB names those CONSTRAINT_n by
-- position; rather than assume the numbering, each is found by its own clause and dropped by the
-- name the server actually holds. An unmatched clause leaves @sql NULL and PREPARE fails, so the
-- migration stops instead of adding a second, conflicting vocabulary beside the old one.
SET @ck = (SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND CHECK_CLAUSE LIKE '%P01_SOURCE_MONITOR%' LIMIT 1);
SET @sql = CONCAT('ALTER TABLE jobs DROP CONSTRAINT `', @ck, '`');
PREPARE drop_jobs_check FROM @sql;
EXECUTE drop_jobs_check;
DEALLOCATE PREPARE drop_jobs_check;

SET @ck = (SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND CHECK_CLAUSE LIKE '%PREFLIGHT%' LIMIT 1);
SET @sql = CONCAT('ALTER TABLE jobs DROP CONSTRAINT `', @ck, '`');
PREPARE drop_jobs_check FROM @sql;
EXECUTE drop_jobs_check;
DEALLOCATE PREPARE drop_jobs_check;

SET @ck = (SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND CHECK_CLAUSE LIKE '%INGESTION_SOURCE_POLICY%' LIMIT 1);
SET @sql = CONCAT('ALTER TABLE jobs DROP CONSTRAINT `', @ck, '`');
PREPARE drop_jobs_check FROM @sql;
EXECUTE drop_jobs_check;
DEALLOCATE PREPARE drop_jobs_check;

ALTER TABLE jobs
  ADD CONSTRAINT ck_jobs_job_type CHECK (job_type IN (
    'P01_SOURCE_MONITOR', 'M15_INGESTION_CAPTURE', 'M11_AUDIT_RUN', 'M16_PROJECTION_REFRESH', 'MONITOR_STEP'
  )),
  ADD CONSTRAINT ck_jobs_state CHECK (state IN (
    'PENDING', 'PREFLIGHT', 'FETCH', 'CAPTURE', 'DIFF', 'ANALYZE', 'ADJUDICATE', 'RELEASE', 'VISIBLE',
    'FAILED', 'ABANDONED', 'WAITING_FOR_OPERATOR', 'SETTLED'
  )),
  ADD CONSTRAINT ck_jobs_subject_kind CHECK (subject_kind IN (
    'MONITORED_SOURCE', 'INGESTION_SOURCE_POLICY', 'AUDIT_PROFILE', 'RELEASE_ARTIFACT', 'MONITOR_SCHEDULE_OCCURRENCE'
  )),
  -- A MONITOR_STEP job is about exactly one occurrence, and only it may use the two new states.
  ADD CONSTRAINT ck_jobs_monitor_step_subject CHECK ((job_type = 'MONITOR_STEP') = (subject_kind = 'MONITOR_SCHEDULE_OCCURRENCE')),
  ADD CONSTRAINT ck_jobs_monitor_step_states CHECK (
    (job_type = 'MONITOR_STEP' AND state IN ('PENDING', 'FAILED', 'WAITING_FOR_OPERATOR', 'SETTLED', 'ABANDONED'))
    OR (job_type <> 'MONITOR_STEP' AND state NOT IN ('WAITING_FOR_OPERATOR', 'SETTLED'))
  );

-- ---------------------------------------------------------------- append-only history

DROP TRIGGER IF EXISTS trg_monitor_schedules_no_update;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedules_no_update BEFORE UPDATE ON monitor_schedules FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule versions are immutable'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedules_no_delete;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedules_no_delete BEFORE DELETE ON monitor_schedules FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule versions preserve history'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_events_no_update;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_events_no_update BEFORE UPDATE ON monitor_schedule_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule events are append-only'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_events_no_delete;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_events_no_delete BEFORE DELETE ON monitor_schedule_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule events are append-only'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_occurrences_no_update;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_occurrences_no_update BEFORE UPDATE ON monitor_schedule_occurrences FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule occurrences are immutable'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_occurrences_no_delete;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_occurrences_no_delete BEFORE DELETE ON monitor_schedule_occurrences FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule occurrences preserve history'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_outcomes_no_update;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_outcomes_no_update BEFORE UPDATE ON monitor_schedule_occurrence_outcomes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule outcomes are append-only'; END$$
DELIMITER ;
DROP TRIGGER IF EXISTS trg_monitor_schedule_outcomes_no_delete;
DELIMITER $$
CREATE TRIGGER trg_monitor_schedule_outcomes_no_delete BEFORE DELETE ON monitor_schedule_occurrence_outcomes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitor schedule outcomes are append-only'; END$$
DELIMITER ;
