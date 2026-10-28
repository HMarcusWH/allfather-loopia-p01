-- OB-02 / LI-03 candidate DDL only. U01 and U02 are NOT RATIFIED.
-- NEVER COPY into database/migrations or execute on Loopia/Railway.
-- Intended ONLY for disposable MariaDB QA; owner scopes and current M05 rights
-- require independent runtime adoption before any real history materialization.
--
-- Family 260 proposes its TWO expected tables plus a THIRD append-only cursor
-- ledger needed for safe bounded replay. Registry currently allocates two;
-- RT-01 must reconcile/approve the additional ledger and FK plan, not assume GO.
-- No raw HTML/text, provider prompts, rejected candidates or fabricated
-- historical assessment is stored. A legacy metadata link is NOT_ASSESSED.
CREATE TABLE IF NOT EXISTS p01_observation_assessments (
  p01_observation_assessment_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  source_license_card_id CHAR(36) NOT NULL,
  source_snapshot_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  question_id CHAR(36) NULL,
  question_identity VARCHAR(64) NOT NULL,
  run_id CHAR(36) NULL,
  record_kind VARCHAR(40) NOT NULL,
  coverage_class VARCHAR(64) NOT NULL,
  assessment_status VARCHAR(32) NOT NULL,
  business_significance VARCHAR(32) NOT NULL,
  lineage_digest CHAR(64) NOT NULL,
  record_json LONGTEXT NOT NULL,
  source_captured_at CHAR(24) NOT NULL,
  known_at CHAR(24) NOT NULL,
  assessed_at CHAR(24) NOT NULL,
  human_annotation_id CHAR(36) NULL,
  candidate_state VARCHAR(40) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  PRIMARY KEY (p01_observation_assessment_id),
  UNIQUE KEY uq_ob02_assessment_id_scope (p01_observation_assessment_id, tenant_id, workspace_id),
  UNIQUE KEY uq_ob02_metadata_snapshot (tenant_id,workspace_id,competitor_id,source_snapshot_id,record_kind),
  KEY ix_ob02_timeline (tenant_id,workspace_id,competitor_id,known_at,p01_observation_assessment_id),
  CONSTRAINT fk_ob02_as_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_ob02_as_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_ob02_as_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CONSTRAINT fk_ob02_as_source FOREIGN KEY (monitored_source_id) REFERENCES monitored_sources(monitored_source_id),
  CONSTRAINT fk_ob02_as_snapshot FOREIGN KEY (source_snapshot_id) REFERENCES source_snapshots(source_snapshot_id),
  CONSTRAINT fk_ob02_as_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_objects(evidence_id),
  CHECK (JSON_VALID(record_json)),
  CHECK (record_kind='LEGACY_METADATA_ONLY'),
  CHECK (assessment_status='NOT_ASSESSED' AND business_significance='NOT_ASSESSED'),
  CHECK (question_id IS NULL AND run_id IS NULL AND human_annotation_id IS NULL),
  CHECK (question_identity='NOT_RECORDED_IN_LEGACY_P01'),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED'),
  CHECK (coverage_class IN (
    'BASELINE_NO_COMPARISON','UNCHANGED_STRUCTURE_NOT_MATERIALITY',
    'CHANGED_STRUCTURE_NOT_MATERIALITY','HELD_CONTRARY_OR_MULTIPLE_DIFF_REFERENCES',
    'INSUFFICIENT_COMPARISON_EVIDENCE'
  )),
  CHECK (known_at>=source_captured_at AND assessed_at=known_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p01_observation_assessment_refs (
  p01_observation_assessment_ref_id CHAR(36) NOT NULL,
  p01_observation_assessment_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ordinal_no INT NOT NULL,
  ref_kind VARCHAR(32) NOT NULL,
  source_snapshot_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NULL,
  source_diff_id CHAR(36) NULL,
  ref_recorded_at CHAR(24) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (p01_observation_assessment_ref_id),
  UNIQUE KEY uq_ob02_ref_ordinal (p01_observation_assessment_id,ordinal_no),
  UNIQUE KEY uq_ob02_ref_scope_id (p01_observation_assessment_ref_id,tenant_id,workspace_id),
  KEY ix_ob02_ref_evidence (tenant_id,workspace_id,evidence_id),
  CONSTRAINT fk_ob02_ref_assessment FOREIGN KEY
    (p01_observation_assessment_id,tenant_id,workspace_id)
    REFERENCES p01_observation_assessments(p01_observation_assessment_id,tenant_id,workspace_id),
  CONSTRAINT fk_ob02_ref_snapshot FOREIGN KEY (source_snapshot_id) REFERENCES source_snapshots(source_snapshot_id),
  CONSTRAINT fk_ob02_ref_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_objects(evidence_id),
  CONSTRAINT fk_ob02_ref_diff FOREIGN KEY (source_diff_id) REFERENCES source_diffs(source_diff_id),
  CHECK (ordinal_no BETWEEN 0 AND 8),
  CHECK (ref_kind IN ('M05_EVIDENCE','P01_STRUCTURAL_DIFF')),
  CHECK ((ref_kind='M05_EVIDENCE' AND evidence_id IS NOT NULL AND source_diff_id IS NULL)
       OR (ref_kind='P01_STRUCTURAL_DIFF' AND evidence_id IS NULL AND source_diff_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p01_observation_backfill_ledger (
  p01_observation_backfill_ledger_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  cursor_key CHAR(64) NOT NULL,
  cursor_before CHAR(36) NULL,
  cursor_after CHAR(36) NULL,
  page_limit INT NOT NULL,
  page_digest CHAR(64) NOT NULL,
  record_count INT NOT NULL,
  newly_inserted_count INT NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  state VARCHAR(40) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  PRIMARY KEY (p01_observation_backfill_ledger_id),
  UNIQUE KEY uq_ob02_ledger_cursor (tenant_id,workspace_id,competitor_id,cursor_key),
  KEY ix_ob02_ledger_history (tenant_id,workspace_id,competitor_id,recorded_at),
  CONSTRAINT fk_ob02_ledger_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_ob02_ledger_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_ob02_ledger_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (page_limit BETWEEN 1 AND 20),
  CHECK (record_count BETWEEN 0 AND 20),
  CHECK (newly_inserted_count BETWEEN 0 AND record_count),
  CHECK (state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Six immutability triggers. Candidate QA verifies update/delete refusal.
DELIMITER $$
CREATE TRIGGER ob02_assessments_no_update BEFORE UPDATE ON p01_observation_assessments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_ASSESSMENT_APPEND_ONLY'; END$$
CREATE TRIGGER ob02_assessments_no_delete BEFORE DELETE ON p01_observation_assessments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_ASSESSMENT_APPEND_ONLY'; END$$
CREATE TRIGGER ob02_refs_no_update BEFORE UPDATE ON p01_observation_assessment_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_REF_APPEND_ONLY'; END$$
CREATE TRIGGER ob02_refs_no_delete BEFORE DELETE ON p01_observation_assessment_refs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_REF_APPEND_ONLY'; END$$
CREATE TRIGGER ob02_ledger_no_update BEFORE UPDATE ON p01_observation_backfill_ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_LEDGER_APPEND_ONLY'; END$$
CREATE TRIGGER ob02_ledger_no_delete BEFORE DELETE ON p01_observation_backfill_ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OB02_LEDGER_APPEND_ONLY'; END$$
DELIMITER ;
