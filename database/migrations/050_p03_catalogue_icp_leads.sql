-- 050_p03_catalogue_icp_leads
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS customer_product_catalogues (
  customer_product_catalogue_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  catalogue_key VARCHAR(191) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  source_license_card_id CHAR(36) NOT NULL,
  catalogue_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (customer_product_catalogue_id),
  CHECK (catalogue_status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'RETIRED')),
  CONSTRAINT fk_customer_product_catalogues_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_customer_product_catalogues_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_customer_product_catalogues_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_customer_product_catalogues_source_license_card_id FOREIGN KEY (source_license_card_id) REFERENCES source_license_cards (source_license_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_customer_product_catalogues_active
--   canonical: CREATE UNIQUE INDEX ... ON customer_product_catalogues (tenant_id, catalogue_key)
--   predicate: WHERE catalogue_status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_customer_product_catalogues_active_np ON customer_product_catalogues (tenant_id, catalogue_key);

CREATE TABLE IF NOT EXISTS customer_products (
  customer_product_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  customer_product_catalogue_id CHAR(36) NOT NULL,
  product_key VARCHAR(191) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  product_offer_kind VARCHAR(32) NOT NULL,
  value_proposition_safe TEXT,
  attributes_json TEXT NOT NULL DEFAULT '[]',
  product_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (customer_product_id),
  CHECK (product_offer_kind IN ('PRODUCT', 'SERVICE', 'BUNDLE', 'VALUE_PROPOSITION')),
  CHECK (product_status IN ('DRAFT', 'ACTIVE', 'RETIRED', 'WITHDRAWN')),
  CONSTRAINT fk_customer_products_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_customer_products_customer_product_catalogue_id FOREIGN KEY (customer_product_catalogue_id) REFERENCES customer_product_catalogues (customer_product_catalogue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_customer_products_catalogue ON customer_products (customer_product_catalogue_id, product_status);

CREATE TABLE IF NOT EXISTS icp_profiles (
  icp_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (icp_profile_id),
  CHECK (length(display_name) > 0),
  CHECK (status IN ('ACTIVE', 'SUPERSEDED')),
  CONSTRAINT fk_icp_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_icp_profiles_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_icp_profiles_one_active
--   canonical: CREATE UNIQUE INDEX ... ON icp_profiles (tenant_id, workspace_id)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_icp_profiles_one_active_np ON icp_profiles (tenant_id, workspace_id);

DROP TRIGGER IF EXISTS trg_icp_profiles_identity_immutable;
DELIMITER $$
CREATE TRIGGER trg_icp_profiles_identity_immutable
  BEFORE UPDATE ON icp_profiles
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.icp_profile_id <> NEW.icp_profile_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.display_name <> NEW.display_name OR OLD.created_at <> NEW.created_at
  IF OLD.icp_profile_id <> NEW.icp_profile_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.display_name <> NEW.display_name OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'icp_profiles identity is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_icp_profiles_no_delete;
DELIMITER $$
CREATE TRIGGER trg_icp_profiles_no_delete
  BEFORE DELETE ON icp_profiles
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'icp_profiles rows are never deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS customer_product_catalogue_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  customer_product_catalogue_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_customer_product_catalogue_status_transitions_customer_produc FOREIGN KEY (customer_product_catalogue_id) REFERENCES customer_product_catalogues (customer_product_catalogue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_cpc_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_cpc_transitions_no_update
  BEFORE UPDATE ON customer_product_catalogue_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_product_catalogue_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cpc_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_cpc_transitions_no_delete
  BEFORE DELETE ON customer_product_catalogue_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_product_catalogue_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS icp_profile_versions (
  icp_profile_version_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  icp_profile_id CHAR(36) NOT NULL,
  version_number INT NOT NULL,
  industries_json TEXT NOT NULL DEFAULT '[]',
  geographies_json TEXT NOT NULL DEFAULT '[]',
  company_size_band TEXT,
  buyer_role_classes_json TEXT NOT NULL DEFAULT '[]',
  excluded_segments_json TEXT NOT NULL DEFAULT '[]',
  uncertainty_notes_safe TEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (icp_profile_version_id),
  CHECK (version_number >= 1),
  CHECK (company_size_band IS NULL OR company_size_band IN (     'MICRO_1_9', 'SMALL_10_49', 'MID_50_249', 'LARGE_250_999', 'ENTERPRISE_1000_PLUS')),
  CHECK (length(uncertainty_notes_safe) > 0),
  CONSTRAINT fk_icp_profile_versions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_icp_profile_versions_icp_profile_id FOREIGN KEY (icp_profile_id) REFERENCES icp_profiles (icp_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_icp_profile_versions_number ON icp_profile_versions (icp_profile_id, version_number);

DROP TRIGGER IF EXISTS trg_icp_profile_versions_no_update;
DELIMITER $$
CREATE TRIGGER trg_icp_profile_versions_no_update
  BEFORE UPDATE ON icp_profile_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'icp_profile_versions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_icp_profile_versions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_icp_profile_versions_no_delete
  BEFORE DELETE ON icp_profile_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'icp_profile_versions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS fit_hypotheses (
  fit_hypothesis_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  customer_product_id CHAR(36) NOT NULL,
  company_signal_id CHAR(36) NOT NULL,
  fit_band VARCHAR(32) NOT NULL,
  claim_kind VARCHAR(32) NOT NULL,
  uncertainty_explicit TINYINT(1) NOT NULL,
  fit_assessment_digest CHAR(64) NOT NULL,
  icp_profile_version_id CHAR(36),
  icp_fit_scorer_version VARCHAR(32),
  icp_fit_score INT,
  icp_fit_reasons_json TEXT,
  basis_evidence_id CHAR(36),
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (fit_hypothesis_id),
  CHECK (fit_band IN ('STRONG', 'PROBABLE', 'WEAK', 'UNFIT')),
  CHECK (claim_kind IN ('FIT_HYPOTHESIS', 'REASON_TO_INVESTIGATE')),
  CHECK (uncertainty_explicit IN (0, 1)),
  CHECK (status IN ('DRAFT', 'SUBMITTED', 'ADMITTED', 'REJECTED', 'SUPERSEDED')),
  CHECK (     (icp_profile_version_id IS NULL) = (icp_fit_scorer_version IS NULL)     AND (icp_profile_version_id IS NULL) = (icp_fit_score IS NULL)     AND (icp_profile_version_id IS NULL) = (icp_fit_reasons_json IS NULL)   ),
  CONSTRAINT fk_fit_hypotheses_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_fit_hypotheses_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_fit_hypotheses_customer_product_id FOREIGN KEY (customer_product_id) REFERENCES customer_products (customer_product_id),
  CONSTRAINT fk_fit_hypotheses_company_signal_id FOREIGN KEY (company_signal_id) REFERENCES company_signals (company_signal_id),
  CONSTRAINT fk_fit_hypotheses_icp_profile_version_id FOREIGN KEY (icp_profile_version_id) REFERENCES icp_profile_versions (icp_profile_version_id),
  CONSTRAINT fk_fit_hypotheses_basis_evidence_id FOREIGN KEY (basis_evidence_id) REFERENCES evidence_objects (evidence_id),
  CONSTRAINT fk_fit_hypotheses_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_fit_hypotheses_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_fit_hypotheses_pair ON fit_hypotheses (customer_product_id, company_signal_id, status);

CREATE TABLE IF NOT EXISTS monitored_prospects (
  monitored_prospect_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  fit_hypothesis_id CHAR(36) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  watch_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitored_prospect_id),
  CHECK (watch_status IN ('ACTIVE_WATCH', 'EXIT_FIT_LOST', 'EXIT_PROMOTED_TO_QUEUE', 'EXIT_SUPERSEDED')),
  CONSTRAINT fk_monitored_prospects_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_monitored_prospects_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_monitored_prospects_fit_hypothesis_id FOREIGN KEY (fit_hypothesis_id) REFERENCES fit_hypotheses (fit_hypothesis_id),
  CONSTRAINT fk_monitored_prospects_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_monitored_prospects_hypothesis ON monitored_prospects (fit_hypothesis_id, watch_status);

CREATE TABLE IF NOT EXISTS priority_outreach_queue_items (
  priority_outreach_queue_item_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  fit_hypothesis_id CHAR(36) NOT NULL,
  triggering_company_signal_id CHAR(36) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  queue_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (priority_outreach_queue_item_id),
  CHECK (queue_status IN ('OPEN', 'ACKNOWLEDGED_BY_SALES', 'EXIT_SOLD', 'EXIT_LOST', 'EXIT_SUPERSEDED', 'EXIT_WITHDRAWN')),
  CONSTRAINT fk_priority_outreach_queue_items_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_priority_outreach_queue_items_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_priority_outreach_queue_items_fit_hypothesis_id FOREIGN KEY (fit_hypothesis_id) REFERENCES fit_hypotheses (fit_hypothesis_id),
  CONSTRAINT fk_priority_outreach_queue_items_triggering_company_signal_id FOREIGN KEY (triggering_company_signal_id) REFERENCES company_signals (company_signal_id),
  CONSTRAINT fk_priority_outreach_queue_items_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_priority_outreach_queue_items_hypothesis ON priority_outreach_queue_items (fit_hypothesis_id, queue_status);

CREATE TABLE IF NOT EXISTS prospect_battlecard_briefs (
  prospect_battlecard_brief_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  priority_outreach_queue_item_id CHAR(36) NOT NULL,
  fit_hypothesis_id CHAR(36) NOT NULL,
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  fit_summary_safe TEXT NOT NULL,
  source_evidence_ids TEXT NOT NULL DEFAULT '[]',
  why_it_matters_safe TEXT,
  customer_value_hypothesis_safe TEXT,
  talk_track_safe TEXT,
  recommended_response_safe TEXT,
  confidence_band VARCHAR(32) NOT NULL,
  uncertainty_summary_safe TEXT,
  rejected_claims_safe TEXT,
  source_license_visibility_flags TEXT NOT NULL DEFAULT '{}',
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (prospect_battlecard_brief_id),
  CHECK (confidence_band IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (status IN ('DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED', 'RELEASED', 'SUPERSEDED', 'BLOCKED')),
  CONSTRAINT fk_prospect_battlecard_briefs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_prospect_battlecard_briefs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_prospect_battlecard_briefs_priority_outreach_queue_item_id FOREIGN KEY (priority_outreach_queue_item_id) REFERENCES priority_outreach_queue_items (priority_outreach_queue_item_id),
  CONSTRAINT fk_prospect_battlecard_briefs_fit_hypothesis_id FOREIGN KEY (fit_hypothesis_id) REFERENCES fit_hypotheses (fit_hypothesis_id),
  CONSTRAINT fk_prospect_battlecard_briefs_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_prospect_battlecard_briefs_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_prospect_battlecard_briefs_queue ON prospect_battlecard_briefs (priority_outreach_queue_item_id, status);

CREATE TABLE IF NOT EXISTS released_prospect_outputs (
  released_prospect_output_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  output_kind VARCHAR(32) NOT NULL,
  monitored_prospect_id CHAR(36),
  priority_outreach_queue_item_id CHAR(36),
  prospect_battlecard_brief_id CHAR(36),
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  PRIMARY KEY (released_prospect_output_id),
  CHECK (output_kind IN ('MONITORED_PROSPECT', 'PRIORITY_OUTREACH_QUEUE_ITEM', 'PROSPECT_BATTLECARD_BRIEF')),
  CHECK (     (CASE WHEN monitored_prospect_id IS NOT NULL THEN 1 ELSE 0 END) +     (CASE WHEN priority_outreach_queue_item_id IS NOT NULL THEN 1 ELSE 0 END) +     (CASE WHEN prospect_battlecard_brief_id IS NOT NULL THEN 1 ELSE 0 END) = 1   ),
  CONSTRAINT fk_released_prospect_outputs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_prospect_outputs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_prospect_outputs_monitored_prospect_id FOREIGN KEY (monitored_prospect_id) REFERENCES monitored_prospects (monitored_prospect_id),
  CONSTRAINT fk_released_prospect_outputs_priority_outreach_queue_item_id FOREIGN KEY (priority_outreach_queue_item_id) REFERENCES priority_outreach_queue_items (priority_outreach_queue_item_id),
  CONSTRAINT fk_released_prospect_outputs_prospect_battlecard_brief_id FOREIGN KEY (prospect_battlecard_brief_id) REFERENCES prospect_battlecard_briefs (prospect_battlecard_brief_id),
  CONSTRAINT fk_released_prospect_outputs_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_prospect_outputs_scope ON released_prospect_outputs (tenant_id, workspace_id, output_kind);

DROP TRIGGER IF EXISTS trg_released_prospect_outputs_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_prospect_outputs_no_update
  BEFORE UPDATE ON released_prospect_outputs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_prospect_outputs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_prospect_outputs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_prospect_outputs_no_delete
  BEFORE DELETE ON released_prospect_outputs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_prospect_outputs is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS monitored_prospect_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  monitored_prospect_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_monitored_prospect_status_transitions_monitored_prospect_id FOREIGN KEY (monitored_prospect_id) REFERENCES monitored_prospects (monitored_prospect_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_monitored_prospect_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_monitored_prospect_transitions_no_update
  BEFORE UPDATE ON monitored_prospect_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitored_prospect_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_monitored_prospect_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_monitored_prospect_transitions_no_delete
  BEFORE DELETE ON monitored_prospect_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'monitored_prospect_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS priority_outreach_queue_item_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  priority_outreach_queue_item_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_priority_outreach_queue_item_status_transitions_priority_outr FOREIGN KEY (priority_outreach_queue_item_id) REFERENCES priority_outreach_queue_items (priority_outreach_queue_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_poqi_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_poqi_transitions_no_update
  BEFORE UPDATE ON priority_outreach_queue_item_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'priority_outreach_queue_item_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_poqi_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_poqi_transitions_no_delete
  BEFORE DELETE ON priority_outreach_queue_item_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'priority_outreach_queue_item_status_transitions is append-only';
END$$
DELIMITER ;
