-- LI-07B G21 CANDIDATE, NOT A MIGRATION. 2026-10-26.
-- U04 has NOT been ratified. THIS FILE IS INTENTIONALLY OUTSIDE database/migrations.
-- It is executed ONLY against disposable MariaDB test databases by a named fixture suite.
-- DO NOT install manually on Railway/customer environments or promote before owner decision.
-- Candidate uniqueness: exact run identity digest (includes input/profile/engine) and
-- exact output identity UNIQUE(run_id, memory_class, output_ordinal). Multiple incomparable
-- successors of one predecessor are permitted; current head is not MAX(created_at).
-- All bodies are immutable derived METADATA; no source bytes and no authority grants.

CREATE TABLE IF NOT EXISTS temporal_compression_profiles (
  profile_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  subject_kind VARCHAR(32) NOT NULL,
  subject_id VARCHAR(128) NOT NULL,
  profile_version VARCHAR(96) NOT NULL,
  profile_digest CHAR(64) NOT NULL,
  profile_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(profile_id),
  UNIQUE KEY uq_m17_profile_digest(tenant_id,workspace_id,subject_kind,subject_id,profile_digest),
  UNIQUE KEY uq_m17_profile_scope(profile_id,tenant_id,workspace_id),
  CHECK (subject_kind='P01_COMPETITOR'),
  CHECK (candidate_state='NOT_ADMITTED'),
  CHECK (JSON_VALID(profile_json)),
  CONSTRAINT fk_m17_profile_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_m17_profile_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temporal_compression_profile_rules (
  profile_rule_id CHAR(36) NOT NULL,
  profile_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ordinal_no INT NOT NULL,
  rule_digest CHAR(64) NOT NULL,
  rule_json LONGTEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(profile_rule_id),
  UNIQUE KEY uq_m17_profile_rule(profile_id,ordinal_no),
  CHECK (ordinal_no BETWEEN 0 AND 255),
  CHECK (JSON_VALID(rule_json)),
  CONSTRAINT fk_m17_rule_profile FOREIGN KEY(profile_id,tenant_id,workspace_id)
    REFERENCES temporal_compression_profiles(profile_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temporal_compression_runs (
  compression_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  subject_kind VARCHAR(32) NOT NULL,
  subject_id VARCHAR(128) NOT NULL,
  data_class VARCHAR(32) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  window_kind VARCHAR(16) NOT NULL,
  period_start_utc CHAR(20) NOT NULL,
  period_end_utc CHAR(20) NOT NULL,
  engine_version VARCHAR(96) NOT NULL,
  input_digest CHAR(64) NOT NULL,
  profile_digest CHAR(64) NOT NULL,
  derivation_identity_digest CHAR(64) NOT NULL,
  profile_id CHAR(36) NOT NULL,
  frozen_inputs_json LONGTEXT NOT NULL,
  window_json LONGTEXT NOT NULL,
  profile_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(compression_run_id),
  UNIQUE KEY uq_m17_run_identity(derivation_identity_digest),
  UNIQUE KEY uq_m17_run_scope(compression_run_id,tenant_id,workspace_id),
  KEY ix_m17_run_group(tenant_id,workspace_id,subject_kind,subject_id,data_class,period_start_utc),
  CHECK (subject_kind='P01_COMPETITOR'),
  CHECK (data_class IN ('SOURCE_DIFF','COMPANY_SIGNAL')),
  CHECK (window_kind IN ('DAY','WEEK','MONTH')),
  CHECK (period_end_utc>period_start_utc),
  CHECK (candidate_state='NOT_ADMITTED'),
  CHECK (JSON_VALID(frozen_inputs_json)),
  CHECK (JSON_VALID(window_json)),
  CHECK (JSON_VALID(profile_json)),
  CONSTRAINT fk_m17_run_profile FOREIGN KEY(profile_id,tenant_id,workspace_id)
    REFERENCES temporal_compression_profiles(profile_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temporal_rollups (
  temporal_rollup_id CHAR(36) NOT NULL,
  compression_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  rollup_digest CHAR(64) NOT NULL,
  rollup_json LONGTEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(temporal_rollup_id),
  UNIQUE KEY uq_m17_rollup_run(compression_run_id),
  UNIQUE KEY uq_m17_rollup_scoped(temporal_rollup_id,compression_run_id,tenant_id,workspace_id),
  CHECK (JSON_VALID(rollup_json)),
  CONSTRAINT fk_m17_rollup_run FOREIGN KEY(compression_run_id,tenant_id,workspace_id)
    REFERENCES temporal_compression_runs(compression_run_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temporal_memories (
  temporal_memory_id CHAR(36) NOT NULL,
  compression_run_id CHAR(36) NOT NULL,
  temporal_rollup_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  subject_kind VARCHAR(32) NOT NULL,
  subject_id VARCHAR(128) NOT NULL,
  data_class VARCHAR(32) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  memory_class VARCHAR(32) NOT NULL,
  output_ordinal INT NOT NULL,
  period_start_utc CHAR(20) NOT NULL,
  period_end_utc CHAR(20) NOT NULL,
  content_digest CHAR(64) NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'NOT_ADMITTED',
  supersedes_memory_id CHAR(36) NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(temporal_memory_id),
  UNIQUE KEY uq_m17_memory_output(compression_run_id,memory_class,output_ordinal),
  UNIQUE KEY uq_m17_memory_scope(temporal_memory_id,tenant_id,workspace_id),
  KEY ix_m17_memory_predecessor(supersedes_memory_id,tenant_id,workspace_id),
  KEY ix_m17_memory_group(tenant_id,workspace_id,subject_kind,subject_id,data_class,period_start_utc),
  CHECK (subject_kind='P01_COMPETITOR'),
  CHECK (memory_class='PERIOD_SUMMARY'),
  CHECK (output_ordinal BETWEEN 0 AND 63),
  CHECK (period_end_utc>period_start_utc),
  CHECK (candidate_state='NOT_ADMITTED'),
  CHECK (supersedes_memory_id IS NULL OR supersedes_memory_id<>temporal_memory_id),
  CONSTRAINT fk_m17_memory_run FOREIGN KEY(compression_run_id,tenant_id,workspace_id)
    REFERENCES temporal_compression_runs(compression_run_id,tenant_id,workspace_id),
  CONSTRAINT fk_m17_memory_rollup FOREIGN KEY(temporal_rollup_id,compression_run_id,tenant_id,workspace_id)
    REFERENCES temporal_rollups(temporal_rollup_id,compression_run_id,tenant_id,workspace_id),
  CONSTRAINT fk_m17_memory_prior FOREIGN KEY(supersedes_memory_id,tenant_id,workspace_id)
    REFERENCES temporal_memories(temporal_memory_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temporal_memory_status_transitions (
  temporal_memory_status_transition_id CHAR(36) NOT NULL,
  temporal_memory_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  sequence_no INT NOT NULL,
  event_kind VARCHAR(40) NOT NULL,
  reason_code VARCHAR(96) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY(temporal_memory_status_transition_id),
  UNIQUE KEY uq_m17_transition_order(temporal_memory_id,sequence_no),
  CHECK (sequence_no>=1),
  CHECK (event_kind IN ('CANDIDATE_RECORDED','CANDIDATE_SUPERSEDED','CANDIDATE_INVALIDATED','REDACTION_AFFECTED')),
  CONSTRAINT fk_m17_transition_memory FOREIGN KEY(temporal_memory_id,tenant_id,workspace_id)
    REFERENCES temporal_memories(temporal_memory_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compression_lineage_links (
  compression_lineage_link_id CHAR(36) NOT NULL,
  temporal_memory_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  reference_ordinal INT NOT NULL,
  ref_type VARCHAR(32) NOT NULL,
  ref_id VARCHAR(128) NOT NULL,
  source_ref_id VARCHAR(128) NOT NULL,
  observed_at_utc CHAR(20) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  polarity VARCHAR(16) NOT NULL,
  conflict_flag BOOLEAN NOT NULL,
  diagnostic_flag BOOLEAN NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(compression_lineage_link_id),
  UNIQUE KEY uq_m17_lineage_ordinal(temporal_memory_id,reference_ordinal),
  UNIQUE KEY uq_m17_lineage_ref(temporal_memory_id,ref_type,ref_id),
  CHECK (reference_ordinal>=0),
  CHECK (ref_type IN ('SOURCE_DIFF','COMPANY_SIGNAL')),
  CHECK (polarity IN ('SUPPORT','CONTRARY','UNKNOWN')),
  CONSTRAINT fk_m17_link_memory FOREIGN KEY(temporal_memory_id,tenant_id,workspace_id)
    REFERENCES temporal_memories(temporal_memory_id,tenant_id,workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only triggers. The ONLY mutable objects here are the database's internal index pages.
-- No UPDATE/DELETE on candidate source tables; proposed owner-approved invalidation is additive.
DELIMITER $$
CREATE TRIGGER m17_profiles_no_update BEFORE UPDATE ON temporal_compression_profiles FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_PROFILE'; END$$
CREATE TRIGGER m17_profiles_no_delete BEFORE DELETE ON temporal_compression_profiles FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_PROFILE'; END$$
CREATE TRIGGER m17_profile_rules_no_update BEFORE UPDATE ON temporal_compression_profile_rules FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_RULE'; END$$
CREATE TRIGGER m17_profile_rules_no_delete BEFORE DELETE ON temporal_compression_profile_rules FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_RULE'; END$$
CREATE TRIGGER m17_runs_no_update BEFORE UPDATE ON temporal_compression_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_RUN'; END$$
CREATE TRIGGER m17_runs_no_delete BEFORE DELETE ON temporal_compression_runs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_RUN'; END$$
CREATE TRIGGER m17_rollups_no_update BEFORE UPDATE ON temporal_rollups FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_ROLLUP'; END$$
CREATE TRIGGER m17_rollups_no_delete BEFORE DELETE ON temporal_rollups FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_ROLLUP'; END$$
CREATE TRIGGER m17_memories_no_update BEFORE UPDATE ON temporal_memories FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_MEMORY'; END$$
CREATE TRIGGER m17_memories_no_delete BEFORE DELETE ON temporal_memories FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_MEMORY'; END$$
CREATE TRIGGER m17_transitions_no_update BEFORE UPDATE ON temporal_memory_status_transitions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_STATUS'; END$$
CREATE TRIGGER m17_transitions_no_delete BEFORE DELETE ON temporal_memory_status_transitions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_STATUS'; END$$
CREATE TRIGGER m17_lineage_no_update BEFORE UPDATE ON compression_lineage_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_LINEAGE'; END$$
CREATE TRIGGER m17_lineage_no_delete BEFORE DELETE ON compression_lineage_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='M17_APPEND_ONLY_LINEAGE'; END$$
DELIMITER ;
