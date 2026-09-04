-- 070_p05_marketing
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS marketing_asset_drafts (
  marketing_asset_draft_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  asset_kind VARCHAR(32) NOT NULL,
  released_briefing_id CHAR(36),
  released_prospect_output_id CHAR(36),
  title_safe VARCHAR(255) NOT NULL,
  draft_content_safe VARCHAR(255) NOT NULL,
  basis_evidence_id CHAR(36),
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  asset_status VARCHAR(32) NOT NULL,
  asset_digest CHAR(64) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (marketing_asset_draft_id),
  CHECK (asset_kind IN ('LANDING_PAGE_VARIANT', 'OFFER_POSITIONING', 'COMPETITOR_WEAKNESS_MAP', 'CAMPAIGN_ANGLE', 'COPY_VARIANT')),
  CHECK (asset_status IN ('DRAFT', 'IN_REVIEW', 'VERDICT_ISSUED', 'RELEASED_INTERNAL', 'SUPERSEDED', 'BLOCKED')),
  CHECK (     (CASE WHEN released_briefing_id IS NOT NULL THEN 1 ELSE 0 END) +     (CASE WHEN released_prospect_output_id IS NOT NULL THEN 1 ELSE 0 END) = 1   ),
  CONSTRAINT fk_marketing_asset_drafts_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_marketing_asset_drafts_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_marketing_asset_drafts_released_briefing_id FOREIGN KEY (released_briefing_id) REFERENCES released_briefings (released_briefing_id),
  CONSTRAINT fk_marketing_asset_drafts_released_prospect_output_id FOREIGN KEY (released_prospect_output_id) REFERENCES released_prospect_outputs (released_prospect_output_id),
  CONSTRAINT fk_marketing_asset_drafts_basis_evidence_id FOREIGN KEY (basis_evidence_id) REFERENCES evidence_objects (evidence_id),
  CONSTRAINT fk_marketing_asset_drafts_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_marketing_asset_drafts_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_marketing_asset_drafts_scope ON marketing_asset_drafts (tenant_id, workspace_id, asset_kind, asset_status);

CREATE TABLE IF NOT EXISTS marketing_asset_variants (
  marketing_asset_variant_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  marketing_asset_draft_id CHAR(36) NOT NULL,
  variant_key VARCHAR(191) NOT NULL,
  variant_content_safe VARCHAR(255) NOT NULL,
  variant_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (marketing_asset_variant_id),
  CHECK (variant_status IN ('ACTIVE', 'SUPERSEDED')),
  CONSTRAINT fk_marketing_asset_variants_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_marketing_asset_variants_marketing_asset_draft_id FOREIGN KEY (marketing_asset_draft_id) REFERENCES marketing_asset_drafts (marketing_asset_draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_marketing_asset_variants_draft ON marketing_asset_variants (marketing_asset_draft_id, variant_status);

CREATE TABLE IF NOT EXISTS marketing_claim_citations (
  marketing_claim_citation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  marketing_asset_draft_id CHAR(36) NOT NULL,
  claim_text_safe VARCHAR(255) NOT NULL,
  cited_evidence_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (marketing_claim_citation_id),
  CONSTRAINT fk_marketing_claim_citations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_marketing_claim_citations_marketing_asset_draft_id FOREIGN KEY (marketing_asset_draft_id) REFERENCES marketing_asset_drafts (marketing_asset_draft_id),
  CONSTRAINT fk_marketing_claim_citations_cited_evidence_id FOREIGN KEY (cited_evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_marketing_claim_citations_draft ON marketing_claim_citations (marketing_asset_draft_id);

DROP TRIGGER IF EXISTS trg_marketing_claim_citations_no_update;
DELIMITER $$
CREATE TRIGGER trg_marketing_claim_citations_no_update
  BEFORE UPDATE ON marketing_claim_citations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'marketing_claim_citations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_marketing_claim_citations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_marketing_claim_citations_no_delete
  BEFORE DELETE ON marketing_claim_citations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'marketing_claim_citations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS released_marketing_assets (
  released_marketing_asset_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  marketing_asset_draft_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  PRIMARY KEY (released_marketing_asset_id),
  CONSTRAINT fk_released_marketing_assets_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_marketing_assets_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_marketing_assets_marketing_asset_draft_id FOREIGN KEY (marketing_asset_draft_id) REFERENCES marketing_asset_drafts (marketing_asset_draft_id),
  CONSTRAINT fk_released_marketing_assets_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_marketing_assets_scope ON released_marketing_assets (tenant_id, workspace_id);

DROP TRIGGER IF EXISTS trg_released_marketing_assets_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_marketing_assets_no_update
  BEFORE UPDATE ON released_marketing_assets
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_marketing_assets is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_marketing_assets_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_marketing_assets_no_delete
  BEFORE DELETE ON released_marketing_assets
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_marketing_assets is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS marketing_asset_draft_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  marketing_asset_draft_id CHAR(36) NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_marketing_asset_draft_status_transitions_marketing_asset_draf FOREIGN KEY (marketing_asset_draft_id) REFERENCES marketing_asset_drafts (marketing_asset_draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_mad_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_mad_transitions_no_update
  BEFORE UPDATE ON marketing_asset_draft_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'marketing_asset_draft_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_mad_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_mad_transitions_no_delete
  BEFORE DELETE ON marketing_asset_draft_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'marketing_asset_draft_status_transitions is append-only';
END$$
DELIMITER ;
