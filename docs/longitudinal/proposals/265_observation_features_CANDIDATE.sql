-- OB-03 / LI-04 / schema family 265. Candidate-only additive DDL.
-- DO NOT INSTALL with database/migrations, on Railway, or on Loopia.
-- U02 independent-origin semantics and U03 historical source-use are UNRATIFIED.
-- Only disposable TEST database integration may parse/run these statements.
-- Family 260 OB-02 candidate tables must be installed FIRST in QA.
-- All rows are immutable METADATA REFERENCES, not copied M05 source bytes,
-- publication facts, actual independent corroboration, or business materiality.
CREATE TABLE IF NOT EXISTS p01_observation_features (
  p01_observation_feature_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  p01_observation_assessment_id CHAR(36) NOT NULL,
  source_snapshot_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  semantic_delta_digest CHAR(64) NOT NULL,
  feature_digest CHAR(64) NOT NULL,
  feature_json LONGTEXT NOT NULL,
  result_kind VARCHAR(64) NOT NULL,
  event_count_state VARCHAR(32) NOT NULL,
  commercial_materiality VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  candidate_state VARCHAR(40) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  PRIMARY KEY (p01_observation_feature_id),
  UNIQUE KEY uq_ob03_feature_scoped_digest
    (tenant_id, workspace_id, p01_observation_assessment_id, feature_digest),
  UNIQUE KEY uq_ob03_feature_scoped_assessment
    (tenant_id, workspace_id, p01_observation_assessment_id),
  KEY ix_ob03_feature_source
    (tenant_id,workspace_id,competitor_id,source_snapshot_id),
  CONSTRAINT fk_ob03_feature_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_ob03_feature_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_ob03_feature_competitor FOREIGN KEY(competitor_id) REFERENCES competitors(competitor_id),
  CONSTRAINT fk_ob03_feature_assessment FOREIGN KEY
    (p01_observation_assessment_id,tenant_id,workspace_id)
    REFERENCES p01_observation_assessments(p01_observation_assessment_id,tenant_id,workspace_id),
  CONSTRAINT fk_ob03_feature_capture FOREIGN KEY(source_snapshot_id)
    REFERENCES source_snapshots(source_snapshot_id),
  CONSTRAINT fk_ob03_feature_evidence FOREIGN KEY(evidence_id)
    REFERENCES evidence_objects(evidence_id),
  CHECK (JSON_VALID(feature_json)),
  CHECK (result_kind='OBSERVATION_FEATURE_CANDIDATE_ONLY'),
  CHECK (event_count_state='UNKNOWN'),
  CHECK (commercial_materiality='NOT_ASSESSED'),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p01_source_origin_assertions (
  p01_source_origin_assertion_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  source_snapshot_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  p01_canonical_content_hash CHAR(64) NOT NULL,
  assertion_digest CHAR(64) NOT NULL,
  assertion_json LONGTEXT NOT NULL,
  origin_identity VARCHAR(40) NOT NULL,
  independent_evidence_state VARCHAR(40) NOT NULL,
  created_at CHAR(24) NOT NULL,
  candidate_state VARCHAR(40) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  PRIMARY KEY(p01_source_origin_assertion_id),
  UNIQUE KEY uq_ob03_origin_scoped_snapshot
    (tenant_id,workspace_id,competitor_id,source_snapshot_id),
  KEY ix_ob03_origin_source(tenant_id,workspace_id,source_ref_id,created_at),
  CONSTRAINT fk_ob03_origin_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_ob03_origin_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_ob03_origin_competitor FOREIGN KEY(competitor_id) REFERENCES competitors(competitor_id),
  CONSTRAINT fk_ob03_origin_ref FOREIGN KEY(source_ref_id) REFERENCES source_references(source_ref_id),
  CONSTRAINT fk_ob03_origin_capture FOREIGN KEY(source_snapshot_id)
    REFERENCES source_snapshots(source_snapshot_id),
  CONSTRAINT fk_ob03_origin_evidence FOREIGN KEY(evidence_id)
    REFERENCES evidence_objects(evidence_id),
  CHECK (JSON_VALID(assertion_json)),
  CHECK (origin_identity='UNKNOWN'),
  CHECK (independent_evidence_state='NOT_ASSESSED_U02'),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Four independent append-only triggers: no UPDATE, DELETE, cascade mutation.
DELIMITER $$
CREATE TRIGGER ob03_feature_no_update BEFORE UPDATE ON p01_observation_features FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB03_FEATURE_APPEND_ONLY'; END$$
CREATE TRIGGER ob03_feature_no_delete BEFORE DELETE ON p01_observation_features FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB03_FEATURE_APPEND_ONLY'; END$$
CREATE TRIGGER ob03_origin_no_update BEFORE UPDATE ON p01_source_origin_assertions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB03_ORIGIN_APPEND_ONLY'; END$$
CREATE TRIGGER ob03_origin_no_delete BEFORE DELETE ON p01_source_origin_assertions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB03_ORIGIN_APPEND_ONLY'; END$$
DELIMITER ;
