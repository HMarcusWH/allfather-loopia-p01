-- 040_p02_entities
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS entity_twins (
  entity_twin_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_kind VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  legal_name TEXT,
  domain TEXT,
  country TEXT,
  jurisdiction TEXT,
  industry TEXT,
  segment TEXT,
  is_first_party TINYINT(1) NOT NULL,
  is_external TINYINT(1) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  created_by_actor_id CHAR(36),
  PRIMARY KEY (entity_twin_id),
  CHECK (entity_kind IN ('OWN_COMPANY', 'PROSPECT', 'CUSTOMER', 'SUPPLIER', 'VENDOR', 'COMPETITOR', 'PARTNER', 'INVESTOR', 'REGULATOR', 'MARKETPLACE', 'MEDIA', 'TRADE_ASSOCIATION', 'OTHER_EXTERNAL_ORG')),
  CHECK (is_first_party IN (0, 1)),
  CHECK (is_external IN (0, 1)),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'UNDER_REVIEW', 'SUPERSEDED', 'RETIRED')),
  CHECK (is_first_party + is_external = 1),
  CHECK ((entity_kind = 'OWN_COMPANY') = is_first_party),
  CONSTRAINT fk_entity_twins_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twins_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twins_scope ON entity_twins (tenant_id, workspace_id, entity_kind, status);

CREATE TABLE IF NOT EXISTS stakeholder_role_profiles (
  stakeholder_role_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  business_role TEXT NOT NULL,
  decision_role_category VARCHAR(32) NOT NULL,
  account_relationship VARCHAR(32) NOT NULL,
  public_professional_source_safe TEXT,
  source_basis VARCHAR(32) NOT NULL,
  contact_permission VARCHAR(32) NOT NULL,
  is_opted_out TINYINT(1) NOT NULL DEFAULT 0,
  restriction_flags_safe TEXT,
  visibility_scope VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (stakeholder_role_profile_id),
  CHECK (decision_role_category IN ('DECISION_MAKER', 'CHAMPION', 'INFLUENCER', 'GATEKEEPER', 'USER', 'BLOCKER', 'UNKNOWN')),
  CHECK (account_relationship IN ('PROSPECT_CONTACT', 'CUSTOMER_CONTACT', 'PARTNER_CONTACT', 'SUPPLIER_CONTACT', 'INTERNAL_ROLE_ACTOR', 'OTHER')),
  CHECK (source_basis IN ('TENANT_OWNED', 'TENANT_PROVIDED_MANUAL', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'PRIOR_RELEASED_SNAPSHOT')),
  CHECK (contact_permission IN ('NO_CONTACT', 'OPT_IN', 'OPT_OUT', 'RESTRICTED', 'UNKNOWN')),
  CHECK (is_opted_out IN (0, 1)),
  CHECK (visibility_scope IN ('TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUPERSEDED', 'RETIRED')),
  CONSTRAINT fk_stakeholder_role_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_stakeholder_role_profiles_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_stakeholder_role_profiles_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_stakeholder_role_profiles_entity ON stakeholder_role_profiles (entity_twin_id, account_relationship);

CREATE INDEX ix_stakeholder_role_profiles_scope ON stakeholder_role_profiles (tenant_id, workspace_id, status);

CREATE TABLE IF NOT EXISTS entity_exposure_summaries (
  entity_exposure_summary_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  exposure_kind VARCHAR(32) NOT NULL,
  summary_safe TEXT NOT NULL,
  confidence VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_exposure_summary_id),
  CHECK (exposure_kind IN ('SUPPLIER_CONCENTRATION', 'CUSTOMER_CONCENTRATION', 'COMPETITIVE_PRESSURE', 'PARTNER_DEPENDENCY', 'REGULATORY_EXPOSURE', 'OTHER')),
  CHECK (confidence IN ('LOW', 'MEDIUM', 'HIGH', 'CONFIRMED')),
  CONSTRAINT fk_entity_exposure_summaries_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_exposure_summaries_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_exposure_summaries_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_exposure_summaries_entity ON entity_exposure_summaries (entity_twin_id, exposure_kind);

DROP TRIGGER IF EXISTS trg_entity_exposure_summaries_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_exposure_summaries_no_update
  BEFORE UPDATE ON entity_exposure_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_exposure_summaries is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_exposure_summaries_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_exposure_summaries_no_delete
  BEFORE DELETE ON entity_exposure_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_exposure_summaries is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_relationship_edges (
  relationship_edge_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  source_entity_twin_id CHAR(36) NOT NULL,
  target_entity_twin_id CHAR(36) NOT NULL,
  relationship_type VARCHAR(32) NOT NULL,
  direction VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  last_reviewed_at CHAR(24),
  PRIMARY KEY (relationship_edge_id),
  CHECK (relationship_type IN ('SUPPLIES', 'BUYS_FROM', 'SELLS_TO', 'COMPETES_WITH', 'PARTNERS_WITH', 'INTEGRATES_WITH', 'DISTRIBUTES_FOR', 'INVESTS_IN', 'OWNS', 'SUBSIDIARY_OF', 'REGULATES', 'MENTIONS', 'HIRES_FROM', 'SHARES_CUSTOMER_SEGMENT_WITH', 'REPLACES_PRODUCT_OF', 'DEPENDS_ON', 'ALTERNATIVE_TO')),
  CHECK (direction IN ('SOURCE_TO_TARGET', 'BIDIRECTIONAL')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'UNDER_REVIEW', 'SUPERSEDED', 'RETIRED')),
  CHECK (source_entity_twin_id <> target_entity_twin_id),
  CONSTRAINT fk_entity_relationship_edges_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_relationship_edges_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_relationship_edges_source_entity_twin_id FOREIGN KEY (source_entity_twin_id) REFERENCES entity_twins (entity_twin_id),
  CONSTRAINT fk_entity_relationship_edges_target_entity_twin_id FOREIGN KEY (target_entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_relationship_edges_scope ON entity_relationship_edges (tenant_id, workspace_id, relationship_type, status);

CREATE INDEX ix_entity_relationship_edges_source ON entity_relationship_edges (source_entity_twin_id, relationship_type);

CREATE INDEX ix_entity_relationship_edges_target ON entity_relationship_edges (target_entity_twin_id, relationship_type);

CREATE TABLE IF NOT EXISTS entity_relationship_status_history (
  relationship_status_history_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  relationship_edge_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  initiated_by_actor_type VARCHAR(32) NOT NULL,
  initiated_by_actor_id CHAR(36) NOT NULL,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (relationship_status_history_id),
  CHECK (initiated_by_actor_type IN ('OPERATOR', 'SERVICE', 'VALKYRIE', 'SYSTEM')),
  CONSTRAINT fk_entity_relationship_status_history_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_relationship_status_history_relationship_edge_id FOREIGN KEY (relationship_edge_id) REFERENCES entity_relationship_edges (relationship_edge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_relationship_status_history_edge ON entity_relationship_status_history (relationship_edge_id, transitioned_at);

DROP TRIGGER IF EXISTS trg_entity_relationship_status_history_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_status_history_no_update
  BEFORE UPDATE ON entity_relationship_status_history
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_status_history is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_relationship_status_history_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_status_history_no_delete
  BEFORE DELETE ON entity_relationship_status_history
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_status_history is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_relationship_versions (
  relationship_version_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  relationship_edge_id CHAR(36) NOT NULL,
  version_sequence INT NOT NULL,
  confidence VARCHAR(32) NOT NULL,
  freshness_observed_at CHAR(24) NOT NULL,
  business_relevance VARCHAR(32) NOT NULL,
  allowed_use VARCHAR(32) NOT NULL,
  uncertainty_summary_safe TEXT NOT NULL,
  claim_ceiling VARCHAR(48) NOT NULL,
  version_digest CHAR(64) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (relationship_version_id),
  CHECK (confidence IN ('LOW', 'MEDIUM', 'HIGH', 'CONFIRMED')),
  CHECK (business_relevance IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL')),
  CHECK (allowed_use IN ('INTERNAL_ONLY', 'DERIVED_SUMMARY_ALLOWED', 'EVIDENCE_REFERENCE_ONLY', 'RESTRICTED')),
  CHECK (claim_ceiling IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED')),
  UNIQUE (relationship_edge_id, version_sequence),
  CONSTRAINT fk_entity_relationship_versions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_relationship_versions_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_relationship_versions_relationship_edge_id FOREIGN KEY (relationship_edge_id) REFERENCES entity_relationship_edges (relationship_edge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_relationship_versions_edge ON entity_relationship_versions (relationship_edge_id, version_sequence);

DROP TRIGGER IF EXISTS trg_entity_relationship_versions_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_versions_no_update
  BEFORE UPDATE ON entity_relationship_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_versions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_relationship_versions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_versions_no_delete
  BEFORE DELETE ON entity_relationship_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_versions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_ledger_anchors (
  entity_twin_ledger_anchor_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  anchor_kind VARCHAR(32) NOT NULL,
  anchor_summary_safe TEXT NOT NULL,
  retention_policy_safe TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_ledger_anchor_id),
  CHECK (anchor_kind IN ('SOURCE', 'LEGAL', 'LICENSE', 'RETENTION', 'PRIVACY', 'FACTUAL')),
  CONSTRAINT fk_entity_twin_ledger_anchors_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_ledger_anchors_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_ledger_anchors_twin ON entity_twin_ledger_anchors (entity_twin_id, anchor_kind);

DROP TRIGGER IF EXISTS trg_entity_twin_ledger_anchors_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_ledger_anchors_no_update
  BEFORE UPDATE ON entity_twin_ledger_anchors
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_ledger_anchors is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_ledger_anchors_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_ledger_anchors_no_delete
  BEFORE DELETE ON entity_twin_ledger_anchors
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_ledger_anchors is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_status_history (
  entity_twin_status_history_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  initiated_by_actor_type VARCHAR(32) NOT NULL,
  initiated_by_actor_id CHAR(36) NOT NULL,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_status_history_id),
  CHECK (initiated_by_actor_type IN ('OPERATOR', 'SERVICE', 'VALKYRIE', 'SYSTEM')),
  CONSTRAINT fk_entity_twin_status_history_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_status_history_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_status_history_twin ON entity_twin_status_history (entity_twin_id, transitioned_at);

DROP TRIGGER IF EXISTS trg_entity_twin_status_history_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_status_history_no_update
  BEFORE UPDATE ON entity_twin_status_history
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_status_history is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_status_history_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_status_history_no_delete
  BEFORE DELETE ON entity_twin_status_history
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_status_history is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_versions (
  entity_twin_version_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  version_sequence INT NOT NULL,
  claim_ceiling VARCHAR(48) NOT NULL,
  version_digest CHAR(64) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_version_id),
  CHECK (claim_ceiling IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED')),
  UNIQUE (entity_twin_id, version_sequence),
  CONSTRAINT fk_entity_twin_versions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_versions_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_twin_versions_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_versions_twin ON entity_twin_versions (entity_twin_id, version_sequence);

DROP TRIGGER IF EXISTS trg_entity_twin_versions_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_versions_no_update
  BEFORE UPDATE ON entity_twin_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_versions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_versions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_versions_no_delete
  BEFORE DELETE ON entity_twin_versions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_versions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS released_entity_relationship_snapshots (
  released_relationship_snapshot_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  relationship_edge_id CHAR(36) NOT NULL,
  relationship_version_id CHAR(36) NOT NULL,
  claim_ceiling VARCHAR(48) NOT NULL,
  visibility_scope VARCHAR(32) NOT NULL,
  snapshot_digest CHAR(64) NOT NULL,
  release_status VARCHAR(48) NOT NULL,
  supersedes_release_id CHAR(36),
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  released_by_actor_id CHAR(36),
  PRIMARY KEY (released_relationship_snapshot_id),
  CHECK (claim_ceiling IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED')),
  CHECK (visibility_scope IN ('TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (release_status IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED', 'BLOCKED')),
  CONSTRAINT fk_released_entity_relationship_snapshots_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_entity_relationship_snapshots_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_entity_relationship_snapshots_relationship_edge_id FOREIGN KEY (relationship_edge_id) REFERENCES entity_relationship_edges (relationship_edge_id),
  CONSTRAINT fk_released_entity_relationship_snapshots_relationship_version_i FOREIGN KEY (relationship_version_id) REFERENCES entity_relationship_versions (relationship_version_id),
  CONSTRAINT fk_released_entity_relationship_snapshots_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_entity_relationship_snapshots_scope ON released_entity_relationship_snapshots (tenant_id, workspace_id, relationship_edge_id);

CREATE UNIQUE INDEX ux_released_entity_relationship_snapshots_version ON released_entity_relationship_snapshots (relationship_version_id);

DROP TRIGGER IF EXISTS trg_released_entity_relationship_snapshots_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_entity_relationship_snapshots_no_update
  BEFORE UPDATE ON released_entity_relationship_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_entity_relationship_snapshots is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_entity_relationship_snapshots_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_entity_relationship_snapshots_no_delete
  BEFORE DELETE ON released_entity_relationship_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_entity_relationship_snapshots is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS stakeholder_role_attributes (
  stakeholder_role_attribute_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  stakeholder_role_profile_id CHAR(36) NOT NULL,
  attribute_key VARCHAR(191) NOT NULL,
  attribute_value_safe TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (stakeholder_role_attribute_id),
  UNIQUE (stakeholder_role_profile_id, attribute_key),
  CONSTRAINT fk_stakeholder_role_attributes_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_stakeholder_role_attributes_stakeholder_role_profile_id FOREIGN KEY (stakeholder_role_profile_id) REFERENCES stakeholder_role_profiles (stakeholder_role_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_stakeholder_role_attributes_profile ON stakeholder_role_attributes (stakeholder_role_profile_id);

DROP TRIGGER IF EXISTS trg_stakeholder_role_attributes_no_update;
DELIMITER $$
CREATE TRIGGER trg_stakeholder_role_attributes_no_update
  BEFORE UPDATE ON stakeholder_role_attributes
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stakeholder_role_attributes is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_stakeholder_role_attributes_no_delete;
DELIMITER $$
CREATE TRIGGER trg_stakeholder_role_attributes_no_delete
  BEFORE DELETE ON stakeholder_role_attributes
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stakeholder_role_attributes is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_relationship_evidence_links (
  relationship_evidence_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  relationship_version_id CHAR(36) NOT NULL,
  evidence_id CHAR(36),
  source_ref_id CHAR(36),
  source_basis VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (relationship_evidence_link_id),
  CHECK (source_basis IN ('TENANT_OWNED', 'TENANT_PROVIDED_MANUAL', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'PRIOR_RELEASED_SNAPSHOT')),
  CHECK (     evidence_id IS NOT NULL OR source_ref_id IS NOT NULL     OR source_basis IN ('TENANT_PROVIDED_MANUAL', 'PRIOR_RELEASED_SNAPSHOT')   ),
  CONSTRAINT fk_entity_relationship_evidence_links_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_relationship_evidence_links_relationship_version_id FOREIGN KEY (relationship_version_id) REFERENCES entity_relationship_versions (relationship_version_id),
  CONSTRAINT fk_entity_relationship_evidence_links_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id),
  CONSTRAINT fk_entity_relationship_evidence_links_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_relationship_evidence_links_version ON entity_relationship_evidence_links (relationship_version_id);

DROP TRIGGER IF EXISTS trg_entity_relationship_evidence_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_evidence_links_no_update
  BEFORE UPDATE ON entity_relationship_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_evidence_links is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_relationship_evidence_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_relationship_evidence_links_no_delete
  BEFORE DELETE ON entity_relationship_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_evidence_links is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_relationship_state_change_proposals (
  entity_relationship_state_change_proposal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  relationship_version_id CHAR(36) NOT NULL,
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  change_status VARCHAR(32) NOT NULL,
  requested_visibility_scope VARCHAR(32) NOT NULL,
  requested_release_status VARCHAR(48) NOT NULL,
  supersedes_release_id CHAR(36),
  released_relationship_snapshot_id CHAR(36),
  admission_idempotency_key VARCHAR(191),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_relationship_state_change_proposal_id),
  CHECK (change_status IN ('DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED', 'BLOCKED')),
  CHECK (requested_visibility_scope IN ('TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (requested_release_status IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS')),
  CONSTRAINT fk_entity_relationship_state_change_proposals_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_relationship_versi FOREIGN KEY (relationship_version_id) REFERENCES entity_relationship_versions (relationship_version_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_supersedes_release FOREIGN KEY (supersedes_release_id) REFERENCES released_entity_relationship_snapshots (released_relationship_snapshot_id),
  CONSTRAINT fk_entity_relationship_state_change_proposals_released_relations FOREIGN KEY (released_relationship_snapshot_id) REFERENCES released_entity_relationship_snapshots (released_relationship_snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_relationship_state_change_proposals_scope ON entity_relationship_state_change_proposals (tenant_id, workspace_id, change_status);

CREATE INDEX ix_entity_relationship_state_change_proposals_proposal ON entity_relationship_state_change_proposals (proposal_id);

-- PARTIAL INDEX (MariaDB has no partial index): ux_entity_rel_state_change_admission_idempotency
--   canonical: CREATE UNIQUE INDEX ... ON entity_relationship_state_change_proposals (tenant_id, admission_idempotency_key)
--   predicate: WHERE admission_idempotency_key IS NOT NULL
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_entity_rel_state_change_admission_idempotency_np ON entity_relationship_state_change_proposals (tenant_id, admission_idempotency_key);

CREATE TABLE IF NOT EXISTS entity_relationship_state_change_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  entity_relationship_state_change_proposal_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_entity_relationship_state_change_status_transitions_entity_re FOREIGN KEY (entity_relationship_state_change_proposal_id) REFERENCES entity_relationship_state_change_proposals (entity_relationship_state_change_proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_entity_rel_state_change_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_rel_state_change_transitions_no_update
  BEFORE UPDATE ON entity_relationship_state_change_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_state_change_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_rel_state_change_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_rel_state_change_transitions_no_delete
  BEFORE DELETE ON entity_relationship_state_change_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_relationship_state_change_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_source_links (
  entity_twin_source_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  entity_twin_version_id CHAR(36) NOT NULL,
  evidence_id CHAR(36),
  source_ref_id CHAR(36),
  source_basis VARCHAR(32) NOT NULL,
  allowed_use VARCHAR(32) NOT NULL,
  license_basis_safe TEXT,
  freshness_observed_at CHAR(24),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_source_link_id),
  CHECK (source_basis IN ('TENANT_OWNED', 'TENANT_PROVIDED_MANUAL', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'PRIOR_RELEASED_SNAPSHOT')),
  CHECK (allowed_use IN ('INTERNAL_ONLY', 'DERIVED_SUMMARY_ALLOWED', 'EVIDENCE_REFERENCE_ONLY', 'RESTRICTED')),
  CHECK (     evidence_id IS NOT NULL OR source_ref_id IS NOT NULL     OR source_basis IN ('TENANT_PROVIDED_MANUAL', 'PRIOR_RELEASED_SNAPSHOT')   ),
  CONSTRAINT fk_entity_twin_source_links_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_source_links_entity_twin_version_id FOREIGN KEY (entity_twin_version_id) REFERENCES entity_twin_versions (entity_twin_version_id),
  CONSTRAINT fk_entity_twin_source_links_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id),
  CONSTRAINT fk_entity_twin_source_links_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_source_links_version ON entity_twin_source_links (entity_twin_version_id);

DROP TRIGGER IF EXISTS trg_entity_twin_source_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_source_links_no_update
  BEFORE UPDATE ON entity_twin_source_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_source_links is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_source_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_source_links_no_delete
  BEFORE DELETE ON entity_twin_source_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_source_links is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_state_summaries (
  entity_twin_state_summary_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  entity_twin_version_id CHAR(36) NOT NULL,
  field_key VARCHAR(191) NOT NULL,
  field_value_safe TEXT,
  value_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_state_summary_id),
  CHECK (value_status IN ('KNOWN_FACT', 'TENANT_PROVIDED', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'DERIVED_SUMMARY', 'INFERRED_HYPOTHESIS', 'UNSUPPORTED_CLAIM', 'UNKNOWN', 'CONFLICTING', 'STALE', 'PROHIBITED')),
  UNIQUE (entity_twin_version_id, field_key),
  CONSTRAINT fk_entity_twin_state_summaries_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_state_summaries_entity_twin_version_id FOREIGN KEY (entity_twin_version_id) REFERENCES entity_twin_versions (entity_twin_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_state_summaries_version ON entity_twin_state_summaries (entity_twin_version_id);

DROP TRIGGER IF EXISTS trg_entity_twin_state_summaries_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_state_summaries_no_update
  BEFORE UPDATE ON entity_twin_state_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_state_summaries is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_state_summaries_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_state_summaries_no_delete
  BEFORE DELETE ON entity_twin_state_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_state_summaries is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_uncertainty_summaries (
  entity_twin_uncertainty_summary_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  entity_twin_version_id CHAR(36) NOT NULL,
  summary_safe TEXT NOT NULL,
  unknown_field_count INT NOT NULL DEFAULT 0,
  has_conflict TINYINT(1) NOT NULL DEFAULT 0,
  conflict_summary_safe TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_uncertainty_summary_id),
  CHECK (unknown_field_count >= 0),
  CHECK (has_conflict IN (0, 1)),
  UNIQUE (entity_twin_version_id),
  CONSTRAINT fk_entity_twin_uncertainty_summaries_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_uncertainty_summaries_entity_twin_version_id FOREIGN KEY (entity_twin_version_id) REFERENCES entity_twin_versions (entity_twin_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_entity_twin_uncertainty_summaries_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_uncertainty_summaries_no_update
  BEFORE UPDATE ON entity_twin_uncertainty_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_uncertainty_summaries is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_uncertainty_summaries_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_uncertainty_summaries_no_delete
  BEFORE DELETE ON entity_twin_uncertainty_summaries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_uncertainty_summaries is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS released_entity_twin_snapshots (
  released_entity_twin_snapshot_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_twin_id CHAR(36) NOT NULL,
  entity_twin_version_id CHAR(36) NOT NULL,
  entity_twin_uncertainty_summary_id CHAR(36) NOT NULL,
  claim_ceiling VARCHAR(48) NOT NULL,
  visibility_scope VARCHAR(32) NOT NULL,
  snapshot_digest CHAR(64) NOT NULL,
  release_status VARCHAR(48) NOT NULL,
  supersedes_release_id CHAR(36),
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  released_by_actor_id CHAR(36),
  PRIMARY KEY (released_entity_twin_snapshot_id),
  CHECK (claim_ceiling IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED')),
  CHECK (visibility_scope IN ('TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (release_status IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED', 'BLOCKED')),
  CONSTRAINT fk_released_entity_twin_snapshots_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_entity_twin_snapshots_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_entity_twin_snapshots_entity_twin_id FOREIGN KEY (entity_twin_id) REFERENCES entity_twins (entity_twin_id),
  CONSTRAINT fk_released_entity_twin_snapshots_entity_twin_version_id FOREIGN KEY (entity_twin_version_id) REFERENCES entity_twin_versions (entity_twin_version_id),
  CONSTRAINT fk_released_entity_twin_snapshots_entity_twin_uncertainty_summar FOREIGN KEY (entity_twin_uncertainty_summary_id) REFERENCES entity_twin_uncertainty_summaries (entity_twin_uncertainty_summary_id),
  CONSTRAINT fk_released_entity_twin_snapshots_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_entity_twin_snapshots_scope ON released_entity_twin_snapshots (tenant_id, workspace_id, entity_twin_id);

DROP TRIGGER IF EXISTS trg_released_entity_twin_snapshots_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_entity_twin_snapshots_no_update
  BEFORE UPDATE ON released_entity_twin_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_entity_twin_snapshots is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_entity_twin_snapshots_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_entity_twin_snapshots_no_delete
  BEFORE DELETE ON released_entity_twin_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_entity_twin_snapshots is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS entity_twin_state_change_proposals (
  entity_twin_state_change_proposal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  entity_twin_version_id CHAR(36) NOT NULL,
  asserted_relationship_version_id CHAR(36),
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  change_status VARCHAR(32) NOT NULL,
  requested_visibility_scope VARCHAR(32) NOT NULL,
  requested_release_status VARCHAR(48) NOT NULL,
  supersedes_release_id CHAR(36),
  released_entity_twin_snapshot_id CHAR(36),
  admission_idempotency_key VARCHAR(191),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (entity_twin_state_change_proposal_id),
  CHECK (change_status IN ('DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED', 'BLOCKED')),
  CHECK (requested_visibility_scope IN ('TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (requested_release_status IN ('INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS')),
  CONSTRAINT fk_entity_twin_state_change_proposals_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_entity_twin_version_id FOREIGN KEY (entity_twin_version_id) REFERENCES entity_twin_versions (entity_twin_version_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_asserted_relationship_vers FOREIGN KEY (asserted_relationship_version_id) REFERENCES entity_relationship_versions (relationship_version_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_supersedes_release_id FOREIGN KEY (supersedes_release_id) REFERENCES released_entity_twin_snapshots (released_entity_twin_snapshot_id),
  CONSTRAINT fk_entity_twin_state_change_proposals_released_entity_twin_snaps FOREIGN KEY (released_entity_twin_snapshot_id) REFERENCES released_entity_twin_snapshots (released_entity_twin_snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_entity_twin_state_change_proposals_scope ON entity_twin_state_change_proposals (tenant_id, workspace_id, change_status);

CREATE INDEX ix_entity_twin_state_change_proposals_proposal ON entity_twin_state_change_proposals (proposal_id);

-- PARTIAL INDEX (MariaDB has no partial index): ux_entity_twin_state_change_admission_idempotency
--   canonical: CREATE UNIQUE INDEX ... ON entity_twin_state_change_proposals (tenant_id, admission_idempotency_key)
--   predicate: WHERE admission_idempotency_key IS NOT NULL
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_entity_twin_state_change_admission_idempotency_np ON entity_twin_state_change_proposals (tenant_id, admission_idempotency_key);

CREATE TABLE IF NOT EXISTS entity_twin_state_change_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  entity_twin_state_change_proposal_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_entity_twin_state_change_status_transitions_entity_twin_state FOREIGN KEY (entity_twin_state_change_proposal_id) REFERENCES entity_twin_state_change_proposals (entity_twin_state_change_proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_entity_twin_state_change_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_state_change_transitions_no_update
  BEFORE UPDATE ON entity_twin_state_change_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_state_change_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_entity_twin_state_change_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_entity_twin_state_change_transitions_no_delete
  BEFORE DELETE ON entity_twin_state_change_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entity_twin_state_change_status_transitions is append-only';
END$$
DELIMITER ;
