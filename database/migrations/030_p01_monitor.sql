-- 030_p01_monitor
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS competitors (
  competitor_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  organization_number TEXT,
  competitor_class VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (competitor_id),
  CHECK (competitor_class IN ('DIRECT', 'INDIRECT', 'ADJACENT', 'MARKET_REFERENCE', 'OTHER')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'PAUSED', 'ARCHIVED')),
  CONSTRAINT fk_competitors_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_competitors_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_competitors_scope ON competitors (tenant_id, workspace_id, status);

CREATE TABLE IF NOT EXISTS monitored_sources (
  monitored_source_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36),
  source_ref_id CHAR(36) NOT NULL,
  source_license_card_id CHAR(36) NOT NULL,
  source_kind VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (monitored_source_id),
  CHECK (source_kind IN ('WEBSITE', 'BLOG_NEWS', 'PRESS_RELEASE', 'RSS', 'PUBLIC_API', 'SEARCH_API', 'MANUAL_URL')),
  CHECK (status IN ('REGISTERED', 'ACTIVE', 'PAUSED', 'BLOCKED', 'REVOKED')),
  CONSTRAINT fk_monitored_sources_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_monitored_sources_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_monitored_sources_competitor_id FOREIGN KEY (competitor_id) REFERENCES competitors (competitor_id),
  CONSTRAINT fk_monitored_sources_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_monitored_sources_source_license_card_id FOREIGN KEY (source_license_card_id) REFERENCES source_license_cards (source_license_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_monitored_sources_competitor ON monitored_sources (competitor_id, status);

CREATE TABLE IF NOT EXISTS source_capture_reservations (
  source_capture_reservation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  reserved_at CHAR(24) NOT NULL,
  expires_at CHAR(24) NOT NULL,
  PRIMARY KEY (source_capture_reservation_id),
  CONSTRAINT fk_source_capture_reservations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_source_capture_reservations_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_source_capture_reservations_monitored_source_id FOREIGN KEY (monitored_source_id) REFERENCES monitored_sources (monitored_source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_source_capture_reservations_source ON source_capture_reservations (monitored_source_id);

CREATE INDEX ix_source_capture_reservations_expiry ON source_capture_reservations (expires_at);

CREATE TABLE IF NOT EXISTS source_snapshots (
  source_snapshot_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  snapshot_sequence INT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  hash_algorithm TEXT NOT NULL DEFAULT 'sha256',
  capture_method VARCHAR(32) NOT NULL,
  payload_class VARCHAR(32) NOT NULL,
  captured_at CHAR(24) NOT NULL,
  PRIMARY KEY (source_snapshot_id),
  CHECK (capture_method IN ('LIVE_FETCH', 'UPLOAD', 'FIXTURE')),
  CHECK (payload_class IN ('html_snapshot', 'text_excerpt', 'diff_summary')),
  CONSTRAINT fk_source_snapshots_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_source_snapshots_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_source_snapshots_monitored_source_id FOREIGN KEY (monitored_source_id) REFERENCES monitored_sources (monitored_source_id),
  CONSTRAINT fk_source_snapshots_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_source_snapshots_source ON source_snapshots (monitored_source_id, snapshot_sequence);

DROP TRIGGER IF EXISTS trg_source_snapshots_no_update;
DELIMITER $$
CREATE TRIGGER trg_source_snapshots_no_update
  BEFORE UPDATE ON source_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_snapshots is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_source_snapshots_no_delete;
DELIMITER $$
CREATE TRIGGER trg_source_snapshots_no_delete
  BEFORE DELETE ON source_snapshots
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_snapshots is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS competitor_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  initiated_by_actor_type VARCHAR(32) NOT NULL,
  initiated_by_actor_id CHAR(36) NOT NULL,
  reason_summary_safe TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CHECK (initiated_by_actor_type IN ('OPERATOR', 'SERVICE', 'VALKYRIE', 'SYSTEM')),
  CONSTRAINT fk_competitor_status_transitions_competitor_id FOREIGN KEY (competitor_id) REFERENCES competitors (competitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_competitor_transitions ON competitor_status_transitions (competitor_id, transitioned_at);

DROP TRIGGER IF EXISTS trg_competitor_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_competitor_transitions_no_update
  BEFORE UPDATE ON competitor_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'competitor_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_competitor_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_competitor_transitions_no_delete
  BEFORE DELETE ON competitor_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'competitor_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS source_diffs (
  source_diff_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  monitored_source_id CHAR(36) NOT NULL,
  before_snapshot_id CHAR(36) NOT NULL,
  after_snapshot_id CHAR(36) NOT NULL,
  change_evidence_id CHAR(36),
  diff_digest CHAR(64) NOT NULL,
  hash_algorithm TEXT NOT NULL DEFAULT 'sha256',
  change_significance VARCHAR(32) NOT NULL,
  diff_summary_safe TEXT,
  detected_at CHAR(24) NOT NULL,
  PRIMARY KEY (source_diff_id),
  CHECK (change_significance IN ('NONE', 'MINOR', 'MATERIAL', 'UNKNOWN')),
  CONSTRAINT fk_source_diffs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_source_diffs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_source_diffs_monitored_source_id FOREIGN KEY (monitored_source_id) REFERENCES monitored_sources (monitored_source_id),
  CONSTRAINT fk_source_diffs_before_snapshot_id FOREIGN KEY (before_snapshot_id) REFERENCES source_snapshots (source_snapshot_id),
  CONSTRAINT fk_source_diffs_after_snapshot_id FOREIGN KEY (after_snapshot_id) REFERENCES source_snapshots (source_snapshot_id),
  CONSTRAINT fk_source_diffs_change_evidence_id FOREIGN KEY (change_evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_source_diffs_source ON source_diffs (monitored_source_id, detected_at);

DROP TRIGGER IF EXISTS trg_source_diffs_no_update;
DELIMITER $$
CREATE TRIGGER trg_source_diffs_no_update
  BEFORE UPDATE ON source_diffs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_diffs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_source_diffs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_source_diffs_no_delete
  BEFORE DELETE ON source_diffs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'source_diffs is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS company_signals (
  company_signal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  source_diff_id CHAR(36),
  signal_class VARCHAR(32) NOT NULL,
  interpretation_kind VARCHAR(32) NOT NULL,
  confidence_band VARCHAR(32) NOT NULL,
  summary_safe TEXT NOT NULL,
  proposal_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  admission_idempotency_key VARCHAR(191),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (company_signal_id),
  CHECK (signal_class IN ('PRICING_CHANGE_SIGNAL', 'PRODUCT_LAUNCH_SIGNAL', 'POSITIONING_CHANGE_SIGNAL', 'CAMPAIGN_CHANGE_SIGNAL', 'PARTNERSHIP_SIGNAL', 'HIRING_OR_EXPANSION_SIGNAL', 'REGULATORY_OR_COMPLIANCE_SIGNAL', 'NEGATIVE_FEEDBACK_SIGNAL', 'MARKET_CONTEXT_SIGNAL')),
  CHECK (interpretation_kind IN ('FACT_OBSERVED', 'HYPOTHESIS')),
  CHECK (confidence_band IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (status IN ('DRAFT', 'PROPOSED', 'UNDER_REVIEW', 'RELEASED', 'SUPERSEDED', 'REJECTED', 'WITHDRAWN')),
  CONSTRAINT fk_company_signals_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_company_signals_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_company_signals_competitor_id FOREIGN KEY (competitor_id) REFERENCES competitors (competitor_id),
  CONSTRAINT fk_company_signals_source_diff_id FOREIGN KEY (source_diff_id) REFERENCES source_diffs (source_diff_id),
  CONSTRAINT fk_company_signals_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_company_signals_competitor ON company_signals (competitor_id, status);

CREATE INDEX ix_company_signals_proposal ON company_signals (proposal_id);

-- PARTIAL INDEX (MariaDB has no partial index): ux_company_signals_admission_idempotency
--   canonical: CREATE UNIQUE INDEX ... ON company_signals (tenant_id, admission_idempotency_key)
--   predicate: WHERE admission_idempotency_key IS NOT NULL
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_company_signals_admission_idempotency_np ON company_signals (tenant_id, admission_idempotency_key);

CREATE TABLE IF NOT EXISTS battlecard_briefs (
  battlecard_brief_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  competitor_id CHAR(36) NOT NULL,
  company_signal_id CHAR(36) NOT NULL,
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  signal_summary_safe TEXT NOT NULL,
  source_evidence_ids TEXT NOT NULL DEFAULT '[]',
  before_after_delta_safe TEXT,
  why_it_matters_safe TEXT,
  customer_impact_hypothesis_safe TEXT,
  talk_track_safe TEXT,
  recommended_response_safe TEXT,
  confidence_band VARCHAR(32) NOT NULL,
  uncertainty_summary_safe TEXT,
  rejected_claims_safe TEXT,
  source_license_visibility_flags TEXT NOT NULL DEFAULT '{}',
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (battlecard_brief_id),
  CHECK (confidence_band IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (status IN ('DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED', 'RELEASED', 'SUPERSEDED', 'BLOCKED')),
  CONSTRAINT fk_battlecard_briefs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_battlecard_briefs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_battlecard_briefs_competitor_id FOREIGN KEY (competitor_id) REFERENCES competitors (competitor_id),
  CONSTRAINT fk_battlecard_briefs_company_signal_id FOREIGN KEY (company_signal_id) REFERENCES company_signals (company_signal_id),
  CONSTRAINT fk_battlecard_briefs_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_battlecard_briefs_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_battlecard_briefs_signal ON battlecard_briefs (company_signal_id, status);

CREATE TABLE IF NOT EXISTS released_briefings (
  released_briefing_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  briefing_kind VARCHAR(32) NOT NULL,
  company_signal_id CHAR(36),
  battlecard_brief_id CHAR(36),
  proposal_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  PRIMARY KEY (released_briefing_id),
  CHECK (briefing_kind IN ('COMPANY_SIGNAL_BRIEF', 'BATTLECARD_BRIEF')),
  CONSTRAINT fk_released_briefings_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_briefings_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_briefings_company_signal_id FOREIGN KEY (company_signal_id) REFERENCES company_signals (company_signal_id),
  CONSTRAINT fk_released_briefings_battlecard_brief_id FOREIGN KEY (battlecard_brief_id) REFERENCES battlecard_briefs (battlecard_brief_id),
  CONSTRAINT fk_released_briefings_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_released_briefings_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_briefings_record ON released_briefings (decision_record_id);

DROP TRIGGER IF EXISTS trg_released_briefings_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_briefings_no_update
  BEFORE UPDATE ON released_briefings
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_briefings is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_briefings_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_briefings_no_delete
  BEFORE DELETE ON released_briefings
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_briefings is append-only';
END$$
DELIMITER ;
