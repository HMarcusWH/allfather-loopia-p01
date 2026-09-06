-- 020_sources_evidence
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS evidence_bundles (
  evidence_bundle_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36),
  bundle_version INT NOT NULL DEFAULT 1,
  purpose TEXT NOT NULL,
  status VARCHAR(32) NOT NULL,
  missingness_summary TEXT,
  bundle_hash CHAR(64),
  hash_algorithm TEXT NOT NULL DEFAULT 'sha256',
  supersedes_bundle_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  submitted_at CHAR(24),
  PRIMARY KEY (evidence_bundle_id),
  CHECK (status IN ('DRAFT', 'SUBMITTED', 'QUARANTINED', 'SUPERSEDED', 'REVOKED_FOR_USE')),
  CHECK (status <> 'SUBMITTED' OR (bundle_hash IS NOT NULL AND submitted_at IS NOT NULL)),
  CONSTRAINT fk_evidence_bundles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_evidence_bundles_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_evidence_bundles_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_evidence_bundles_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_evidence_bundles_mission_version ON evidence_bundles (mission_id, bundle_version);

DROP TRIGGER IF EXISTS trg_evidence_bundles_no_delete_after_submit;
DELIMITER $$
CREATE TRIGGER trg_evidence_bundles_no_delete_after_submit
  BEFORE DELETE ON evidence_bundles
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.status <> 'DRAFT'
  IF OLD.status <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'submitted evidence_bundles cannot be deleted';
  END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS evidence_missingness_records (
  missingness_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36),
  proposal_id CHAR(36),
  missing_requirement_type VARCHAR(32) NOT NULL,
  required_evidence_description TEXT NOT NULL,
  why_material TEXT,
  blocks_release_or_action INT NOT NULL DEFAULT 1,
  resolution_condition TEXT,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (missingness_id),
  CHECK (missing_requirement_type IN ('REQUIRED_SOURCE', 'REQUIRED_RAW_CAPTURE', 'REQUIRED_AUTHORITY_ASSESSMENT', 'REQUIRED_FRESHNESS_ASSESSMENT', 'REQUIRED_CANONICAL_PAIR_MEMBER', 'LIVE_HEAD_UNRESOLVED', 'REQUIRED_CONFLICT_RESOLUTION', 'OTHER')),
  CONSTRAINT fk_evidence_missingness_records_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_evidence_missingness_records_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_evidence_missingness_records_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_evidence_missingness_records_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_evidence_missingness_mission ON evidence_missingness_records (mission_id, proposal_id);

DROP TRIGGER IF EXISTS trg_evidence_missingness_no_update;
DELIMITER $$
CREATE TRIGGER trg_evidence_missingness_no_update
  BEFORE UPDATE ON evidence_missingness_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evidence_missingness_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_evidence_missingness_no_delete;
DELIMITER $$
CREATE TRIGGER trg_evidence_missingness_no_delete
  BEFORE DELETE ON evidence_missingness_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evidence_missingness_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS source_references (
  source_ref_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  source_type VARCHAR(32) NOT NULL,
  source_location_safe TEXT,
  lawful_or_permitted_use_scope TEXT,
  content_sensitivity_class VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  registered_at CHAR(24) NOT NULL,
  PRIMARY KEY (source_ref_id),
  CHECK (source_type IN ('DOCUMENT', 'WEB_RESOURCE', 'RUNTIME_TOOL_RESULT', 'UPLOADED_ARTIFACT', 'SYNTHETIC_TEST_FIXTURE', 'PROJECT_CANONICAL_OBJECT', 'PROJECT_SUPPORTING_OBJECT', 'OTHER')),
  CHECK (content_sensitivity_class IN ('PUBLIC_OR_NON_SENSITIVE', 'INTERNAL_DEMO', 'RESTRICTED_REVIEW', 'PROHIBITED_IN_V1')),
  CHECK (status IN ('REGISTERED', 'ACTIVE_FOR_CAPTURE', 'RESTRICTED', 'REVOKED', 'QUARANTINED')),
  CONSTRAINT fk_source_references_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_source_references_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raw_artifacts (
  raw_artifact_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  artifact_type VARCHAR(32) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  hash_algorithm TEXT NOT NULL DEFAULT 'sha256',
  captured_at CHAR(24) NOT NULL,
  capture_method TEXT NOT NULL,
  storage_reference_safe TEXT,
  content_excerpt_safe TEXT,
  access_classification TEXT NOT NULL,
  PRIMARY KEY (raw_artifact_id),
  CHECK (artifact_type IN ('FILE_CAPTURE', 'TEXT_CAPTURE', 'HOST_RESULT_CAPTURE', 'LINKED_REFERENCE', 'TEST_FIXTURE_CAPTURE')),
  CONSTRAINT fk_raw_artifacts_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_raw_artifacts_no_update;
DELIMITER $$
CREATE TRIGGER trg_raw_artifacts_no_update
  BEFORE UPDATE ON raw_artifacts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_artifacts is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_raw_artifacts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_raw_artifacts_no_delete
  BEFORE DELETE ON raw_artifacts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_artifacts is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS source_license_cards (
  source_license_card_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  source_name TEXT NOT NULL,
  source_url_safe TEXT,
  owner_company TEXT,
  source_type VARCHAR(32) NOT NULL,
  allowed_use_class VARCHAR(32) NOT NULL,
  license_class VARCHAR(32) NOT NULL,
  customer_output_allowed TINYINT(1) NOT NULL DEFAULT 0,
  redistribution_allowed TINYINT(1) NOT NULL DEFAULT 0,
  commercial_use_allowed TINYINT(1) NOT NULL DEFAULT 0,
  derived_summary_allowed TINYINT(1) NOT NULL DEFAULT 0,
  raw_excerpt_customer_visible TINYINT(1) NOT NULL DEFAULT 0,
  robots_policy_checked TINYINT(1) NOT NULL DEFAULT 0,
  terms_policy_checked TINYINT(1) NOT NULL DEFAULT 0,
  rate_limit_policy TEXT,
  caching_policy VARCHAR(32) NOT NULL DEFAULT 'no_cache',
  retention_class VARCHAR(32) NOT NULL DEFAULT 'short',
  pii_risk_class VARCHAR(32) NOT NULL DEFAULT 'none',
  sensitive_data_risk_class VARCHAR(32) NOT NULL DEFAULT 'none',
  review_status VARCHAR(32) NOT NULL,
  blocked_reason TEXT,
  approved_by TEXT,
  approved_at CHAR(24),
  review_expires_at CHAR(24),
  warn_after_ms INT,
  error_after_ms INT,
  recheck_terms_after_ms INT,
  stale_output_behavior VARCHAR(32) NOT NULL DEFAULT 'downgrade_to_hypothesis',
  data_ownership VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
  processing_purpose TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (source_license_card_id),
  CHECK (source_type IN ('website', 'rss', 'press_release', 'public_api', 'licensed_api', 'customer_upload', 'manual', 'search_api')),
  CHECK (allowed_use_class IN ('observe_only', 'evidence_capture', 'derived_summary_allowed', 'customer_visible_allowed', 'prohibited', 'customer_owned_tenant_scoped')),
  CHECK (license_class IN ('OPEN_CC0', 'PUBLIC_WEB_ALLOWED', 'PUBLIC_WEB_RESTRICTED', 'LICENSED_INTERNAL_ONLY', 'LICENSED_DERIVED_OUTPUT_ALLOWED', 'LICENSED_NO_CUSTOMER_OUTPUT', 'PROHIBITED')),
  CHECK (customer_output_allowed IN (0, 1)),
  CHECK (redistribution_allowed IN (0, 1)),
  CHECK (commercial_use_allowed IN (0, 1)),
  CHECK (derived_summary_allowed IN (0, 1)),
  CHECK (raw_excerpt_customer_visible IN (0, 1)),
  CHECK (robots_policy_checked IN (0, 1)),
  CHECK (terms_policy_checked IN (0, 1)),
  CHECK (caching_policy IN ('no_cache', 'short_cache', 'standard_cache', 'explicit_contract')),
  CHECK (retention_class IN ('short', 'standard', 'audit_minimized', 'prohibited')),
  CHECK (pii_risk_class IN ('none', 'low', 'medium', 'high')),
  CHECK (sensitive_data_risk_class IN ('none', 'low', 'medium', 'high')),
  CHECK (review_status IN ('draft', 'approved', 'blocked', 'expired')),
  CHECK (warn_after_ms IS NULL OR warn_after_ms >= 0),
  CHECK (error_after_ms IS NULL OR error_after_ms >= 0),
  CHECK (recheck_terms_after_ms IS NULL OR recheck_terms_after_ms >= 0),
  CHECK (stale_output_behavior IN ('downgrade_to_hypothesis', 'block', 'warn_only')),
  CHECK (data_ownership IN ('PUBLIC', 'TENANT_OWNED', 'MIXED')),
  CONSTRAINT fk_source_license_cards_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_source_license_cards_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_source_license_cards_source ON source_license_cards (source_ref_id, review_status);

CREATE TABLE IF NOT EXISTS source_terms_reviews (
  source_terms_review_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  source_license_card_id CHAR(36) NOT NULL,
  robots_url VARCHAR(2048),
  terms_url VARCHAR(2048),
  robots_policy_checked TINYINT(1) NOT NULL DEFAULT 0,
  terms_policy_checked TINYINT(1) NOT NULL DEFAULT 0,
  terms_snapshot_hash CHAR(64),
  reviewed_by TEXT NOT NULL,
  reviewed_at CHAR(24) NOT NULL,
  expires_at CHAR(24),
  PRIMARY KEY (source_terms_review_id),
  CHECK (robots_policy_checked IN (0, 1)),
  CHECK (terms_policy_checked IN (0, 1)),
  CONSTRAINT fk_source_terms_reviews_source_license_card_id FOREIGN KEY (source_license_card_id) REFERENCES source_license_cards (source_license_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_source_terms_reviews_card ON source_terms_reviews (source_license_card_id, reviewed_at);

DROP TRIGGER IF EXISTS trg_source_terms_reviews_no_update;
DELIMITER $$
CREATE TRIGGER trg_source_terms_reviews_no_update
  BEFORE UPDATE ON source_terms_reviews
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_terms_reviews is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_source_terms_reviews_no_delete;
DELIMITER $$
CREATE TRIGGER trg_source_terms_reviews_no_delete
  BEFORE DELETE ON source_terms_reviews
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_terms_reviews is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS evidence_objects (
  evidence_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36),
  source_ref_id CHAR(36) NOT NULL,
  raw_artifact_id CHAR(36),
  tool_request_id CHAR(36),
  tool_authorization_decision_id CHAR(36),
  runtime_host_execution_ref TEXT,
  evidence_type VARCHAR(48) NOT NULL,
  captured_content_reference_safe TEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  hash_algorithm TEXT NOT NULL DEFAULT 'sha256',
  capture_method TEXT NOT NULL,
  captured_at CHAR(24) NOT NULL,
  permitted_use_scope TEXT NOT NULL,
  evidence_status VARCHAR(32) NOT NULL,
  sensitivity_class TEXT NOT NULL,
  admitting_source_status TEXT,
  admitting_source_sensitivity_class TEXT,
  supersedes_evidence_id CHAR(36),
  PRIMARY KEY (evidence_id),
  CHECK (evidence_type IN ('SOURCE_CAPTURE', 'HOST_RETRIEVAL_CAPTURE', 'UPLOADED_SOURCE_CAPTURE', 'TEST_FIXTURE_EVIDENCE', 'DERIVED_ANNOTATION_REFERENCE', 'CHANGE_DETECTION_REFERENCE', 'FIT_HYPOTHESIS_BASIS_REFERENCE', 'STAKEHOLDER_ROLE_PROVENANCE_REFERENCE', 'CONSENT_STATE_REFERENCE', 'DELIVERY_OUTCOME_REFERENCE', 'ENTITY_TWIN_STATE_BASIS_REFERENCE', 'ENTITY_RELATIONSHIP_STATE_BASIS_REFERENCE', 'MARKETING_ASSET_DRAFT_BASIS_REFERENCE')),
  CHECK (evidence_status IN ('CAPTURED', 'ASSESSED', 'STALE_FOR_USE', 'CONFLICTED', 'MISSING_REQUIRED_COMPANION', 'QUARANTINED', 'SUPERSEDED', 'REVOKED_FOR_USE')),
  CONSTRAINT fk_evidence_objects_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_evidence_objects_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_evidence_objects_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_evidence_objects_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_evidence_objects_raw_artifact_id FOREIGN KEY (raw_artifact_id) REFERENCES raw_artifacts (raw_artifact_id),
  CONSTRAINT fk_evidence_objects_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id),
  CONSTRAINT fk_evidence_objects_tool_authorization_decision_id FOREIGN KEY (tool_authorization_decision_id) REFERENCES tool_authorization_decisions (tool_authorization_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_evidence_objects_mission ON evidence_objects (mission_id, evidence_status);

DROP TRIGGER IF EXISTS trg_evidence_objects_no_delete;
DELIMITER $$
CREATE TRIGGER trg_evidence_objects_no_delete
  BEFORE DELETE ON evidence_objects
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evidence_objects cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS evidence_bundle_items (
  bundle_item_id CHAR(36) NOT NULL,
  evidence_bundle_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  item_role VARCHAR(32) NOT NULL,
  included_at CHAR(24) NOT NULL,
  PRIMARY KEY (bundle_item_id),
  CHECK (item_role IN ('SUPPORTIVE', 'ADVERSE', 'CONTEXT', 'GAP')),
  CONSTRAINT fk_evidence_bundle_items_evidence_bundle_id FOREIGN KEY (evidence_bundle_id) REFERENCES evidence_bundles (evidence_bundle_id),
  CONSTRAINT fk_evidence_bundle_items_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_evidence_bundle_items ON evidence_bundle_items (evidence_bundle_id, evidence_id);

DROP TRIGGER IF EXISTS trg_evidence_bundle_items_no_update_on_submit;
DELIMITER $$
CREATE TRIGGER trg_evidence_bundle_items_no_update_on_submit
  BEFORE UPDATE ON evidence_bundle_items
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot modify items of a submitted evidence_bundle';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_evidence_bundle_items_no_delete_on_submit;
DELIMITER $$
CREATE TRIGGER trg_evidence_bundle_items_no_delete_on_submit
  BEFORE DELETE ON evidence_bundle_items
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot delete items from a submitted evidence_bundle';
END$$
DELIMITER ;
