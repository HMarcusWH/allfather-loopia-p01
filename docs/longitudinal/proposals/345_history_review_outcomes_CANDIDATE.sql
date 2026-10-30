-- AN-04 family 345: outcome and follow-up CANDIDATE ONLY.
-- Requires family330 disposable historical finding candidates.
-- Never place in database/migrations or run against staging/production.
-- Observations are reported metadata, NOT approved outcomes or adaptations.

CREATE TABLE IF NOT EXISTS history_review_outcome_annotations (
  history_review_outcome_annotation_id CHAR(36) NOT NULL,
  historical_finding_candidate_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  question_id VARCHAR(128) NOT NULL,
  finding_digest CHAR(64) NOT NULL,
  annotation_digest CHAR(64) NOT NULL,
  prior_annotation_digest CHAR(64) NULL,
  inspected_at_utc CHAR(20) NOT NULL,
  reported_disposition VARCHAR(48) NOT NULL,
  annotation_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_review_outcome_annotation_id),
  UNIQUE KEY uq_an04_annotation_digest(tenant_id,workspace_id,annotation_digest),
  UNIQUE KEY uq_an04_annotation_scoped(history_review_outcome_annotation_id,tenant_id,workspace_id),
  KEY ix_an04_finding_chain(historical_finding_candidate_id,inspected_at_utc),
  CONSTRAINT fk_an04_finding_candidate FOREIGN KEY
    (historical_finding_candidate_id,tenant_id,workspace_id)
    REFERENCES historical_finding_candidates
      (historical_finding_candidate_id,tenant_id,workspace_id),
  CHECK(reported_disposition IN (
    'STILL_UNRESOLVED','REPORTED_CONFIRMED_IN_SCOPE',
    'REPORTED_CONTRADICTED','COVERAGE_INSUFFICIENT','REPORTED_SUPERSEDED'
  )),
  CHECK(JSON_VALID(annotation_json)),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_review_followup_conditions (
  history_review_followup_condition_id CHAR(36) NOT NULL,
  history_review_outcome_annotation_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  followup_digest CHAR(64) NOT NULL,
  candidate_kind VARCHAR(48) NOT NULL,
  not_before_utc CHAR(20) NOT NULL,
  deadline_utc CHAR(20) NOT NULL,
  followup_json LONGTEXT NOT NULL,
  candidate_state VARCHAR(32) NOT NULL DEFAULT 'TEST_ONLY_NOT_ADMITTED',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY(history_review_followup_condition_id),
  UNIQUE KEY uq_an04_followup_digest(tenant_id,workspace_id,followup_digest),
  CONSTRAINT fk_an04_followup_annotation FOREIGN KEY
    (history_review_outcome_annotation_id,tenant_id,workspace_id)
    REFERENCES history_review_outcome_annotations
      (history_review_outcome_annotation_id,tenant_id,workspace_id),
  CHECK(JSON_VALID(followup_json)),
  CHECK(candidate_state='TEST_ONLY_NOT_ADMITTED')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER an04_outcomes_no_update BEFORE UPDATE ON history_review_outcome_annotations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN04_OUTCOME_APPEND_ONLY'; END$$
CREATE TRIGGER an04_outcomes_no_delete BEFORE DELETE ON history_review_outcome_annotations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN04_OUTCOME_APPEND_ONLY'; END$$
CREATE TRIGGER an04_followups_no_update BEFORE UPDATE ON history_review_followup_conditions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN04_FOLLOWUP_APPEND_ONLY'; END$$
CREATE TRIGGER an04_followups_no_delete BEFORE DELETE ON history_review_followup_conditions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='AN04_FOLLOWUP_APPEND_ONLY'; END$$
DELIMITER ;
