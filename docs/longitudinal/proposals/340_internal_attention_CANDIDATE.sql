-- AQ-02 family340: bounded internal attention and suppression CANDIDATES only.
-- Never install in staging/production or under database/migrations.
-- No notifications, P04 live distribution, M16 visibility or M10 release.

CREATE TABLE IF NOT EXISTS history_internal_attention_candidates (
  history_internal_attention_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id VARCHAR(128) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  source_kind VARCHAR(32) NOT NULL,
  source_id VARCHAR(128) NOT NULL,
  source_decision_record_id VARCHAR(128) NOT NULL,
  target_org_role VARCHAR(40) NOT NULL,
  attention_dedup_key CHAR(64) NOT NULL,
  attention_digest CHAR(64) NOT NULL,
  event_status VARCHAR(72) NOT NULL,
  reason_code VARCHAR(72) NOT NULL,
  attention_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_internal_attention_candidate_id),
  UNIQUE KEY uq_aq02_attention_digest(tenant_id,workspace_id,attention_digest),
  UNIQUE KEY uq_aq02_attention_scope(history_internal_attention_candidate_id,tenant_id,workspace_id),
  KEY ix_aq02_dedup(tenant_id,workspace_id,target_org_role,attention_dedup_key),
  CHECK(JSON_VALID(attention_json)),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_attention_suppression_events (
  history_attention_suppression_event_id CHAR(36) NOT NULL,
  history_internal_attention_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  attention_digest CHAR(64) NOT NULL,
  suppression_reason VARCHAR(72) NOT NULL,
  suppression_event_digest CHAR(64) NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_attention_suppression_event_id),
  UNIQUE KEY uq_aq02_suppression_origin(history_internal_attention_candidate_id),
  CONSTRAINT fk_aq02_suppression_attention FOREIGN KEY
    (history_internal_attention_candidate_id,tenant_id,workspace_id)
    REFERENCES history_internal_attention_candidates
      (history_internal_attention_candidate_id,tenant_id,workspace_id),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_internal_digest_candidates (
  history_internal_digest_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id VARCHAR(128) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  target_org_role VARCHAR(40) NOT NULL,
  digest CHAR(64) NOT NULL,
  digest_status VARCHAR(48) NOT NULL,
  digest_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_internal_digest_candidate_id),
  UNIQUE KEY uq_aq02_digest(tenant_id,workspace_id,digest),
  UNIQUE KEY uq_aq02_digest_scope(history_internal_digest_candidate_id,tenant_id,workspace_id),
  CHECK(JSON_VALID(digest_json)),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_internal_digest_members (
  history_internal_digest_member_id CHAR(36) NOT NULL,
  history_internal_digest_candidate_id CHAR(36) NOT NULL,
  history_internal_attention_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  attention_digest CHAR(64) NOT NULL,
  member_kind VARCHAR(24) NOT NULL,
  suppression_reason VARCHAR(72) NULL,
  member_ordinal INT UNSIGNED NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_internal_digest_member_id),
  UNIQUE KEY uq_aq02_digest_membership(history_internal_digest_candidate_id,attention_digest),
  UNIQUE KEY uq_aq02_digest_ordinal(history_internal_digest_candidate_id,member_ordinal),
  CONSTRAINT fk_aq02_digest_members_parent FOREIGN KEY
    (history_internal_digest_candidate_id,tenant_id,workspace_id)
    REFERENCES history_internal_digest_candidates
      (history_internal_digest_candidate_id,tenant_id,workspace_id),
  CONSTRAINT fk_aq02_digest_members_attention FOREIGN KEY
    (history_internal_attention_candidate_id,tenant_id,workspace_id)
    REFERENCES history_internal_attention_candidates
      (history_internal_attention_candidate_id,tenant_id,workspace_id),
  CHECK(member_kind IN ('INCLUDED','SUPPRESSED')),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER aq02_attention_no_update BEFORE UPDATE ON history_internal_attention_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_ATTENTION_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_attention_no_delete BEFORE DELETE ON history_internal_attention_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_ATTENTION_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_suppression_no_update BEFORE UPDATE ON history_attention_suppression_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_SUPPRESSION_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_suppression_no_delete BEFORE DELETE ON history_attention_suppression_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_SUPPRESSION_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_digest_no_update BEFORE UPDATE ON history_internal_digest_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_DIGEST_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_digest_no_delete BEFORE DELETE ON history_internal_digest_candidates FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_DIGEST_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_member_no_update BEFORE UPDATE ON history_internal_digest_members FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_MEMBER_APPEND_ONLY'; END$$
CREATE TRIGGER aq02_member_no_delete BEFORE DELETE ON history_internal_digest_members FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AQ02_MEMBER_APPEND_ONLY'; END$$
DELIMITER ;
