-- AQ-01: proposed family 300. NEVER apply via database/migrations.
-- CLI/disposable TEST schema only. All rows are immutable metadata candidates.
-- No native job reservation, lease, budget grant, scheduler registration or model.

CREATE TABLE IF NOT EXISTS history_review_work_candidates (
  history_review_work_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  work_digest CHAR(64) NOT NULL,
  policy_digest CHAR(64) NOT NULL,
  basis_digest CHAR(64) NOT NULL,
  due_candidate_digest CHAR(64) NOT NULL,
  candidate_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_review_work_candidate_id),
  UNIQUE KEY uq_aq01_work_digest(tenant_id,workspace_id,work_digest),
  UNIQUE KEY uq_aq01_work_scoped_id(history_review_work_candidate_id,tenant_id,workspace_id),
  CONSTRAINT fk_aq01_work_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_aq01_work_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_aq01_work_competitor FOREIGN KEY(competitor_id) REFERENCES competitors(competitor_id),
  CHECK(JSON_VALID(candidate_json)),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_work_members (
  history_review_work_member_id CHAR(36) NOT NULL,
  history_review_work_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  member_ref_id VARCHAR(128) NOT NULL,
  member_kind VARCHAR(32) NOT NULL,
  reference_digest CHAR(64) NULL,
  member_ordinal INT NOT NULL,
  known_at_utc CHAR(20) NOT NULL,
  recorded_at_utc CHAR(20) NOT NULL,
  member_json LONGTEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_review_work_member_id),
  UNIQUE KEY uq_aq01_member_identity(history_review_work_candidate_id,member_ref_id),
  UNIQUE KEY uq_aq01_member_ordinal(history_review_work_candidate_id,member_ordinal),
  CONSTRAINT fk_aq01_member_work FOREIGN KEY
    (history_review_work_candidate_id,tenant_id,workspace_id)
    REFERENCES history_review_work_candidates
      (history_review_work_candidate_id,tenant_id,workspace_id),
  CHECK(member_ordinal BETWEEN 0 AND 63),
  CHECK(JSON_VALID(member_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_work_claim_candidates (
  history_review_work_claim_candidate_id CHAR(36) NOT NULL,
  history_review_work_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  claim_key_digest CHAR(64) NOT NULL,
  fence_generation INT NOT NULL DEFAULT 1,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_review_work_claim_candidate_id),
  UNIQUE KEY uq_aq01_claim_one_per_work(history_review_work_candidate_id),
  CONSTRAINT fk_aq01_claim_work FOREIGN KEY
    (history_review_work_candidate_id,tenant_id,workspace_id)
    REFERENCES history_review_work_candidates
      (history_review_work_candidate_id,tenant_id,workspace_id),
  CHECK(fence_generation=1),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER aq01_work_no_update BEFORE UPDATE ON history_review_work_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_WORK_APPEND_ONLY'; END$$
CREATE TRIGGER aq01_work_no_delete BEFORE DELETE ON history_review_work_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_WORK_APPEND_ONLY'; END$$
CREATE TRIGGER aq01_members_no_update BEFORE UPDATE ON history_review_work_members FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_MEMBER_APPEND_ONLY'; END$$
CREATE TRIGGER aq01_members_no_delete BEFORE DELETE ON history_review_work_members FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_MEMBER_APPEND_ONLY'; END$$
CREATE TRIGGER aq01_claims_no_update BEFORE UPDATE ON history_review_work_claim_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_CLAIM_APPEND_ONLY'; END$$
CREATE TRIGGER aq01_claims_no_delete BEFORE DELETE ON history_review_work_claim_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ01_CLAIM_APPEND_ONLY'; END$$
DELIMITER ;
