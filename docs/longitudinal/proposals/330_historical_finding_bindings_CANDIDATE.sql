-- AN-03 family 330: PROPOSED historical finding bindings, NOT a live migration.
-- No source bytes, native M05 evidence copies, M10 verdict or P02/P04 actions.
-- Install in CLI/disposable MariaDB TEST only, not database/migrations.

CREATE TABLE IF NOT EXISTS historical_finding_candidates (
  historical_finding_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  review_id VARCHAR(128) NOT NULL,
  finding_digest CHAR(64) NOT NULL,
  review_run_digest CHAR(64) NOT NULL,
  basis_digest CHAR(64) NOT NULL,
  dependency_graph_digest CHAR(64) NOT NULL,
  candidate_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(40) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (historical_finding_candidate_id),
  UNIQUE KEY uq_an03_scope_digest (tenant_id,workspace_id,finding_digest),
  UNIQUE KEY uq_an03_scope_ref (historical_finding_candidate_id,tenant_id,workspace_id),
  KEY ix_an03_review (tenant_id,workspace_id,competitor_id,review_id),
  CONSTRAINT fk_an03_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_an03_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_an03_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(competitor_id),
  CHECK (JSON_VALID(candidate_json)),
  CHECK (candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historical_finding_basis_bindings (
  historical_finding_basis_binding_id CHAR(36) NOT NULL,
  historical_finding_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ref_ordinal INT NOT NULL,
  ref_id VARCHAR(128) NOT NULL,
  source_ref_id VARCHAR(128) NOT NULL,
  evidence_id VARCHAR(128) NOT NULL,
  snapshot_id VARCHAR(128) NOT NULL,
  source_content_digest CHAR(64) NOT NULL,
  semantic_polarity VARCHAR(16) NOT NULL,
  cited_as_support TINYINT(1) NOT NULL,
  contrary_retained TINYINT(1) NOT NULL,
  known_at_utc CHAR(20) NOT NULL,
  reference_json LONGTEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (historical_finding_basis_binding_id),
  UNIQUE KEY uq_an03_ref_ordinal (historical_finding_candidate_id,ref_ordinal),
  UNIQUE KEY uq_an03_ref_identity (historical_finding_candidate_id,ref_id),
  CONSTRAINT fk_an03_binding_finding FOREIGN KEY
    (historical_finding_candidate_id,tenant_id,workspace_id)
    REFERENCES historical_finding_candidates
      (historical_finding_candidate_id,tenant_id,workspace_id),
  CHECK (ref_ordinal BETWEEN 0 AND 19),
  CHECK (semantic_polarity IN ('SUPPORT','CONTRARY','UNKNOWN')),
  CHECK (JSON_VALID(reference_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historical_finding_review_links (
  historical_finding_review_link_id CHAR(36) NOT NULL,
  historical_finding_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  analyst_candidate_digest CHAR(64) NOT NULL,
  critic_candidate_digest CHAR(64) NOT NULL,
  review_event_1_digest CHAR(64) NOT NULL,
  review_event_2_digest CHAR(64) NOT NULL,
  review_event_3_digest CHAR(64) NOT NULL,
  human_factual_review_approved TINYINT(1) NOT NULL DEFAULT 0,
  human_delivery_review_approved TINYINT(1) NOT NULL DEFAULT 0,
  m10_release_authorized TINYINT(1) NOT NULL DEFAULT 0,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (historical_finding_review_link_id),
  UNIQUE KEY uq_an03_one_review_link (historical_finding_candidate_id),
  CONSTRAINT fk_an03_review_finding FOREIGN KEY
    (historical_finding_candidate_id,tenant_id,workspace_id)
    REFERENCES historical_finding_candidates
      (historical_finding_candidate_id,tenant_id,workspace_id),
  CHECK (human_factual_review_approved=0),
  CHECK (human_delivery_review_approved=0),
  CHECK (m10_release_authorized=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER an03_findings_no_update BEFORE UPDATE ON historical_finding_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_FINDING_APPEND_ONLY'; END$$
CREATE TRIGGER an03_findings_no_delete BEFORE DELETE ON historical_finding_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_FINDING_APPEND_ONLY'; END$$
CREATE TRIGGER an03_refs_no_update BEFORE UPDATE ON historical_finding_basis_bindings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_REF_APPEND_ONLY'; END$$
CREATE TRIGGER an03_refs_no_delete BEFORE DELETE ON historical_finding_basis_bindings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_REF_APPEND_ONLY'; END$$
CREATE TRIGGER an03_review_no_update BEFORE UPDATE ON historical_finding_review_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_REVIEW_APPEND_ONLY'; END$$
CREATE TRIGGER an03_review_no_delete BEFORE DELETE ON historical_finding_review_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN03_REVIEW_APPEND_ONLY'; END$$
DELIMITER ;
