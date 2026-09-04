-- 100_m16_release_output
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS release_source_use_profiles (
  release_source_use_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  status VARCHAR(32) NOT NULL,
  hold_reasons TEXT NOT NULL DEFAULT '[]',
  profile_digest CHAR(64) NOT NULL,
  compiler_version VARCHAR(32) NOT NULL,
  customer_output_allowed TINYINT(1) NOT NULL,
  license_ceiling VARCHAR(32) NOT NULL,
  sensitivity_ceiling VARCHAR(32) NOT NULL,
  retention_floor VARCHAR(32) NOT NULL,
  audience_ceiling VARCHAR(32) NOT NULL,
  data_ownership_mix VARCHAR(32) NOT NULL,
  profile_json TEXT NOT NULL,
  compiled_at CHAR(24) NOT NULL,
  PRIMARY KEY (release_source_use_profile_id),
  CHECK (status IN ('COMPILED_ALLOW', 'COMPILED_HELD')),
  CHECK (customer_output_allowed IN (0, 1)),
  CHECK (license_ceiling IN (     'PROHIBITED', 'LICENSED_INTERNAL_ONLY', 'LICENSED_NO_CUSTOMER_OUTPUT', 'PUBLIC_WEB_RESTRICTED',     'LICENSED_DERIVED_OUTPUT_ALLOWED', 'PUBLIC_WEB_ALLOWED', 'OPEN_CC0')),
  CHECK (sensitivity_ceiling IN (     'PUBLIC_OR_NON_SENSITIVE', 'INTERNAL_DEMO', 'RESTRICTED_REVIEW', 'PROHIBITED_IN_V1')),
  CHECK (retention_floor IN ('short', 'standard', 'audit_minimized', 'prohibited')),
  CHECK (audience_ceiling IN (     'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM')),
  CHECK (data_ownership_mix IN ('PUBLIC', 'TENANT_OWNED', 'MIXED')),
  CHECK (status <> 'COMPILED_HELD' OR hold_reasons <> '[]'),
  CONSTRAINT fk_release_source_use_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_release_source_use_profiles_tenant_digest ON release_source_use_profiles (tenant_id, profile_digest);

DROP TRIGGER IF EXISTS trg_release_source_use_profiles_no_update;
DELIMITER $$
CREATE TRIGGER trg_release_source_use_profiles_no_update
  BEFORE UPDATE ON release_source_use_profiles
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_source_use_profiles is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_source_use_profiles_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_source_use_profiles_no_delete
  BEFORE DELETE ON release_source_use_profiles
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_source_use_profiles is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS release_artifacts (
  release_artifact_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  artifact_kind VARCHAR(48) NOT NULL,
  released_briefing_id CHAR(36),
  released_entity_twin_snapshot_id CHAR(36),
  released_relationship_snapshot_id CHAR(36),
  proposal_id CHAR(36) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  source_use_profile_id CHAR(36) NOT NULL,
  source_use_profile_digest CHAR(64) NOT NULL,
  content_elements_json TEXT NOT NULL,
  uncertainty_summary_safe TEXT NOT NULL,
  freshness_observed_at CHAR(24) NOT NULL,
  claim_ceiling VARCHAR(48) NOT NULL,
  artifact_digest CHAR(64) NOT NULL,
  assembler_version VARCHAR(32) NOT NULL,
  assembled_at CHAR(24) NOT NULL,
  status VARCHAR(32) NOT NULL,
  internal_scope_lens TEXT,
  supersedes_release_artifact_id CHAR(36),
  PRIMARY KEY (release_artifact_id),
  CHECK (artifact_kind IN (     'COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING', 'ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW')),
  CHECK (length(uncertainty_summary_safe) > 0),
  CHECK (claim_ceiling IN (     'INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS', 'SUPERSEDED', 'RETRACTED')),
  CHECK (status IN (     'DRAFT', 'READY_FOR_RELEASE', 'RELEASED_INTERNAL', 'RELEASED_CUSTOMER_VISIBLE',     'HELD_SOURCE_USE', 'HELD_FRESHNESS', 'HELD_CONFLICT', 'SUPERSEDED', 'ROLLED_BACK', 'QUARANTINED')),
  CHECK (internal_scope_lens IS NULL OR internal_scope_lens IN (     'TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (status <> 'RELEASED_CUSTOMER_VISIBLE'),
  CHECK ((released_briefing_id IS NOT NULL) + (released_entity_twin_snapshot_id IS NOT NULL) + (released_relationship_snapshot_id IS NOT NULL) = 1),
  CHECK (artifact_kind NOT IN ('COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING') OR released_briefing_id IS NOT NULL),
  CHECK (artifact_kind <> 'ENTITY_TWIN_SNAPSHOT_VIEW' OR released_entity_twin_snapshot_id IS NOT NULL),
  CHECK (artifact_kind <> 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW' OR released_relationship_snapshot_id IS NOT NULL),
  CONSTRAINT fk_release_artifacts_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_release_artifacts_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_release_artifacts_released_briefing_id FOREIGN KEY (released_briefing_id) REFERENCES released_briefings (released_briefing_id),
  CONSTRAINT fk_release_artifacts_released_entity_twin_snapshot_id FOREIGN KEY (released_entity_twin_snapshot_id) REFERENCES released_entity_twin_snapshots (released_entity_twin_snapshot_id),
  CONSTRAINT fk_release_artifacts_released_relationship_snapshot_id FOREIGN KEY (released_relationship_snapshot_id) REFERENCES released_entity_relationship_snapshots (released_relationship_snapshot_id),
  CONSTRAINT fk_release_artifacts_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_release_artifacts_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id),
  CONSTRAINT fk_release_artifacts_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id),
  CONSTRAINT fk_release_artifacts_source_use_profile_id FOREIGN KEY (source_use_profile_id) REFERENCES release_source_use_profiles (release_source_use_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_release_artifacts_decision_kind ON release_artifacts (decision_record_id, artifact_kind);

CREATE INDEX ix_release_artifacts_scope_kind ON release_artifacts (tenant_id, workspace_id, artifact_kind, status);

DROP TRIGGER IF EXISTS trg_release_artifacts_content_immutable;
DELIMITER $$
CREATE TRIGGER trg_release_artifacts_content_immutable
  BEFORE UPDATE ON release_artifacts
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.release_artifact_id <> NEW.release_artifact_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.artifact_kind <> NEW.artifact_kind OR COALESCE(OLD.released_briefing_id, '') <> COALESCE(NEW.released_briefing_id, '') OR COALESCE(OLD.released_entity_twin_snapshot_id, '') <> COALESCE(NEW.released_entity_twin_snapshot_id, '') OR COALESCE(OLD.released_relationship_snapshot_id, '') <> COALESCE(NEW.released_relationship_snapshot_id, '') OR OLD.proposal_id <> NEW.proposal_id OR OLD.arbiter_verdict_id <> NEW.arbiter_verdict_id OR OLD.decision_record_id <> NEW.decision_record_id OR OLD.source_use_profile_id <> NEW.source_use_profile_id OR OLD.source_use_profile_digest <> NEW.source_use_profile_digest OR OLD.content_elements_json <> NEW.content_elements_json OR OLD.uncertainty_summary_safe <> NEW.uncertainty_summary_safe OR OLD.freshness_observed_at <> NEW.freshness_observed_at OR OLD.claim_ceiling <> NEW.claim_ceiling OR OLD.artifact_digest <> NEW.artifact_digest OR OLD.assembler_version <> NEW.assembler_version OR OLD.assembled_at <> NEW.assembled_at OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR COALESCE(OLD.supersedes_release_artifact_id, '') <> COALESCE(NEW.supersedes_release_artifact_id, '')
  IF OLD.release_artifact_id <> NEW.release_artifact_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.artifact_kind <> NEW.artifact_kind OR COALESCE(OLD.released_briefing_id, '') <> COALESCE(NEW.released_briefing_id, '') OR COALESCE(OLD.released_entity_twin_snapshot_id, '') <> COALESCE(NEW.released_entity_twin_snapshot_id, '') OR COALESCE(OLD.released_relationship_snapshot_id, '') <> COALESCE(NEW.released_relationship_snapshot_id, '') OR OLD.proposal_id <> NEW.proposal_id OR OLD.arbiter_verdict_id <> NEW.arbiter_verdict_id OR OLD.decision_record_id <> NEW.decision_record_id OR OLD.source_use_profile_id <> NEW.source_use_profile_id OR OLD.source_use_profile_digest <> NEW.source_use_profile_digest OR OLD.content_elements_json <> NEW.content_elements_json OR OLD.uncertainty_summary_safe <> NEW.uncertainty_summary_safe OR OLD.freshness_observed_at <> NEW.freshness_observed_at OR OLD.claim_ceiling <> NEW.claim_ceiling OR OLD.artifact_digest <> NEW.artifact_digest OR OLD.assembler_version <> NEW.assembler_version OR OLD.assembled_at <> NEW.assembled_at OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR COALESCE(OLD.supersedes_release_artifact_id, '') <> COALESCE(NEW.supersedes_release_artifact_id, '') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifacts content is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_artifacts_terminal_status;
DELIMITER $$
CREATE TRIGGER trg_release_artifacts_terminal_status
  BEFORE UPDATE ON release_artifacts
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.status IN ('SUPERSEDED', 'ROLLED_BACK', 'QUARANTINED') AND NEW.status <> OLD.status
  IF OLD.status IN ('SUPERSEDED', 'ROLLED_BACK', 'QUARANTINED') AND NEW.status <> OLD.status THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifacts terminal status cannot transition';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_artifacts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_artifacts_no_delete
  BEFORE DELETE ON release_artifacts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifacts rows are never deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS release_lineage_events (
  release_lineage_event_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  release_artifact_id CHAR(36) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  successor_release_artifact_id CHAR(36),
  decision_correction_record_id CHAR(36) NOT NULL,
  authority_source_module_id CHAR(36),
  authority_source_object_id CHAR(36),
  safe_reason_summary TEXT,
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (release_lineage_event_id),
  CHECK (kind IN ('SUPERSESSION', 'ROLLBACK', 'QUARANTINE')),
  CHECK (authority_source_module_id IS NULL OR authority_source_module_id IN ('M08', 'M11', 'M02')),
  CHECK (kind <> 'SUPERSESSION' OR successor_release_artifact_id IS NOT NULL),
  CHECK (kind = 'SUPERSESSION' OR successor_release_artifact_id IS NULL),
  CONSTRAINT fk_release_lineage_events_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_release_lineage_events_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_release_lineage_events_release_artifact_id FOREIGN KEY (release_artifact_id) REFERENCES release_artifacts (release_artifact_id),
  CONSTRAINT fk_release_lineage_events_successor_release_artifact_id FOREIGN KEY (successor_release_artifact_id) REFERENCES release_artifacts (release_artifact_id),
  CONSTRAINT fk_release_lineage_events_decision_correction_record_id FOREIGN KEY (decision_correction_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_release_lineage_events_unique ON release_lineage_events (release_artifact_id, kind, decision_correction_record_id);

DROP TRIGGER IF EXISTS trg_release_lineage_events_no_update;
DELIMITER $$
CREATE TRIGGER trg_release_lineage_events_no_update
  BEFORE UPDATE ON release_lineage_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_lineage_events is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_lineage_events_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_lineage_events_no_delete
  BEFORE DELETE ON release_lineage_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_lineage_events is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS release_visibility_activations (
  release_visibility_activation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  customer_output_projection_id CHAR(36) NOT NULL,
  release_artifact_id CHAR(36) NOT NULL,
  audience_class VARCHAR(32) NOT NULL,
  event_kind VARCHAR(32) NOT NULL,
  authority_decision_record_id CHAR(36) NOT NULL,
  reason_code VARCHAR(48) NOT NULL,
  event_sequence INT NOT NULL,
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (release_visibility_activation_id),
  CHECK (audience_class IN (     'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM')),
  CHECK (event_kind IN ('ACTIVATED', 'DEACTIVATED')),
  CHECK (reason_code IN (     'INTERNAL_SCOPE_OF_RELEASE_VERDICT', 'CUSTOMER_OUTPUT_ADMISSION', 'ROLLBACK', 'SUPERSESSION', 'QUARANTINE', 'HOLD_DISCOVERED_POST_RELEASE')),
  CHECK (event_kind <> 'ACTIVATED' OR audience_class IN ('OPERATOR_ONLY', 'TENANT_INTERNAL')),
  CONSTRAINT fk_release_visibility_activations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_release_visibility_activations_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_release_visibility_activations_customer_output_projection_id FOREIGN KEY (customer_output_projection_id) REFERENCES customer_output_projections (customer_output_projection_id),
  CONSTRAINT fk_release_visibility_activations_release_artifact_id FOREIGN KEY (release_artifact_id) REFERENCES release_artifacts (release_artifact_id),
  CONSTRAINT fk_release_visibility_activations_authority_decision_record_id FOREIGN KEY (authority_decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_release_visibility_activations_sequence ON release_visibility_activations (customer_output_projection_id, event_sequence);

DROP TRIGGER IF EXISTS trg_release_visibility_activations_no_update;
DELIMITER $$
CREATE TRIGGER trg_release_visibility_activations_no_update
  BEFORE UPDATE ON release_visibility_activations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_visibility_activations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_visibility_activations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_visibility_activations_no_delete
  BEFORE DELETE ON release_visibility_activations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_visibility_activations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS release_artifact_evidence_links (
  release_artifact_evidence_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  release_artifact_id CHAR(36) NOT NULL,
  element_ordinal INT NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  link_role VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (release_artifact_evidence_link_id),
  CHECK (element_ordinal >= 0),
  CHECK (link_role IN ('PRIMARY', 'SUPPORTING')),
  CONSTRAINT fk_release_artifact_evidence_links_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_release_artifact_evidence_links_release_artifact_id FOREIGN KEY (release_artifact_id) REFERENCES release_artifacts (release_artifact_id),
  CONSTRAINT fk_release_artifact_evidence_links_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_release_artifact_evidence_links_unique ON release_artifact_evidence_links (release_artifact_id, element_ordinal, evidence_id);

DROP TRIGGER IF EXISTS trg_release_artifact_evidence_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_release_artifact_evidence_links_no_update
  BEFORE UPDATE ON release_artifact_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifact_evidence_links is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_artifact_evidence_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_artifact_evidence_links_no_delete
  BEFORE DELETE ON release_artifact_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifact_evidence_links is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS release_artifact_status_transitions (
  release_artifact_status_transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  release_artifact_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  actor_type TEXT,
  actor_id CHAR(36),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (release_artifact_status_transition_id),
  CHECK (actor_type IS NULL OR actor_type IN ('OPERATOR', 'SERVICE')),
  CONSTRAINT fk_release_artifact_status_transitions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_release_artifact_status_transitions_release_artifact_id FOREIGN KEY (release_artifact_id) REFERENCES release_artifacts (release_artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_release_artifact_status_transitions_artifact ON release_artifact_status_transitions (tenant_id, release_artifact_id);

DROP TRIGGER IF EXISTS trg_release_artifact_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_release_artifact_status_transitions_no_update
  BEFORE UPDATE ON release_artifact_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifact_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_release_artifact_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_release_artifact_status_transitions_no_delete
  BEFORE DELETE ON release_artifact_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release_artifact_status_transitions is append-only';
END$$
DELIMITER ;
