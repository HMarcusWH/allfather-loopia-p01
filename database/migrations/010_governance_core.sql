-- 010_governance_core
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS arbiter_invalidations (
  arbiter_invalidation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  affected_object_type VARCHAR(32) NOT NULL,
  affected_object_id CHAR(36) NOT NULL,
  reason_code VARCHAR(255) NOT NULL,
  reason_summary TEXT NOT NULL,
  trigger_record_refs TEXT NOT NULL DEFAULT '[]',
  blocks_pending_progression INT NOT NULL DEFAULT 1,
  severity VARCHAR(32) NOT NULL,
  incident_evaluation_required INT NOT NULL DEFAULT 0,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_invalidation_id),
  CHECK (affected_object_type IN ('EVALUATION_RUN', 'VERDICT')),
  CHECK (severity IN ('S1', 'S2', 'S3', 'S4', 'S5'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_invalidations_object ON arbiter_invalidations (affected_object_id, created_at);

DROP TRIGGER IF EXISTS trg_arbiter_invalidations_no_update;
DELIMITER $$
CREATE TRIGGER trg_arbiter_invalidations_no_update
  BEFORE UPDATE ON arbiter_invalidations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_invalidations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_arbiter_invalidations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_arbiter_invalidations_no_delete
  BEFORE DELETE ON arbiter_invalidations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_invalidations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS arbiter_policy_sets (
  arbiter_policy_set_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36),
  workspace_id CHAR(36),
  policy_key VARCHAR(191) NOT NULL,
  policy_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  purpose_summary TEXT,
  applicable_proposal_types TEXT NOT NULL DEFAULT '[]',
  applicable_requested_action_classes TEXT NOT NULL DEFAULT '[]',
  available_verdicts TEXT NOT NULL DEFAULT '[]',
  default_fail_closed_verdict VARCHAR(32) NOT NULL,
  requires_decision_ledger_for_material_outcome TINYINT(1) NOT NULL DEFAULT 1,
  does_not_approve_or_execute TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  supersedes_policy_set_id CHAR(36),
  PRIMARY KEY (arbiter_policy_set_id),
  CHECK (default_fail_closed_verdict IN ('HOLD', 'BLOCK', 'REQUIRE_INCIDENT_EVALUATION')),
  CHECK (requires_decision_ledger_for_material_outcome = 1),
  CHECK (does_not_approve_or_execute = 1),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'SUPERSEDED', 'REVOKED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artifact_integrity_verifications (
  artifact_integrity_verification_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  artifact_id CHAR(36) NOT NULL,
  verification_purpose VARCHAR(32) NOT NULL,
  expected_artifact_hash CHAR(64) NOT NULL,
  observed_artifact_hash CHAR(64) NOT NULL,
  expected_payload_digest CHAR(64) NOT NULL,
  observed_payload_digest CHAR(64) NOT NULL,
  expected_adapter_type VARCHAR(255) NOT NULL,
  observed_adapter_type VARCHAR(255) NOT NULL,
  expected_destination_or_scope_summary TEXT NOT NULL,
  observed_destination_or_scope_summary TEXT NOT NULL,
  integrity_decision VARCHAR(32) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  verified_at CHAR(24) NOT NULL,
  PRIMARY KEY (artifact_integrity_verification_id),
  CHECK (verification_purpose IN ('BEFORE_AUTHORIZATION', 'BEFORE_DISPATCH', 'POST_RESULT_RECONCILIATION', 'AUDIT', 'REPLAY_TEST')),
  CHECK (integrity_decision IN ('PASS', 'FAIL', 'HOLD'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_artifact_integrity_verifications_artifact ON artifact_integrity_verifications (artifact_id, verification_purpose, verified_at);

DROP TRIGGER IF EXISTS trg_artifact_integrity_verifications_no_update;
DELIMITER $$
CREATE TRIGGER trg_artifact_integrity_verifications_no_update
  BEFORE UPDATE ON artifact_integrity_verifications
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'artifact_integrity_verifications is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_artifact_integrity_verifications_no_delete;
DELIMITER $$
CREATE TRIGGER trg_artifact_integrity_verifications_no_delete
  BEFORE DELETE ON artifact_integrity_verifications
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'artifact_integrity_verifications is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS audit_profiles (
  audit_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  profile_key VARCHAR(191) NOT NULL,
  profile_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  audit_category VARCHAR(48) NOT NULL,
  does_not_grant_authority TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  PRIMARY KEY (audit_profile_id),
  CHECK (audit_category IN (     'DECISION_LINEAGE', 'LEDGER_INTEGRITY', 'ACTION_CONTROL', 'TOOL_GATEWAY', 'EVIDENCE_AND_PROVENANCE',     'SCOPE_AND_V1_BOUNDARY', 'REPLAY_AND_CHANGE_CONTROL', 'RELEASE_READINESS', 'SOURCE_FRESHNESS', 'DIFF_INTEGRITY',     'CATALOGUE_FRESHNESS', 'SIGNAL_PAIRING_FRESHNESS', 'BUYER_INTENT_INVARIANT',     'DISTRIBUTION_AUTHORITY', 'REDACTION_POLICY', 'STAKEHOLDER_ROLE_PROVENANCE',     'DELIVERY_RECONCILIATION', 'CONSENT_STATE_INTEGRITY', 'CONTENT_COMPLIANCE',     'EXTERNAL_EFFECT_GUARD',     'TWIN_STATE_EVIDENCE_COVERAGE', 'TWIN_RELATIONSHIP_EDGE_SUPPORT', 'TWIN_UNCERTAINTY_VISIBILITY',     'STAKEHOLDER_PROFILE_MINIMIZATION', 'NO_EMPLOYEE_SCORING', 'NO_PRIVATE_EXTERNAL_DATA',     'SOURCE_LICENSE_CONFORMANCE', 'TWIN_STATE_VERSION_INTEGRITY', 'BUYER_INTENT_OVERCLAIM',     'FIRST_PARTY_EXTERNAL_BOUNDARY', 'RELEASE_CEILING_DECLARATION', 'RETENTION_POLICY_COVERAGE',     'MARKETING_EVIDENCE_CITATION', 'MARKETING_DRAFT_ONLY_INVARIANT', 'MARKETING_CONTENT_COMPLIANCE',     'INGESTION_POLICY_DISCIPLINE', 'INGESTION_EVIDENCE_DISCIPLINE', 'INGESTION_CANDIDATE_DISCIPLINE',     'INGESTION_RESOLUTION_DISCIPLINE', 'INGESTION_REPLAY_DETERMINISM',     'RELEASE_SOURCE_USE_CONFORMANCE', 'RELEASE_AUDIENCE_MONOTONICITY', 'RELEASE_ROLLBACK_VISIBILITY',     'RELEASE_HOLD_DISCIPLINE', 'RELEASE_CAVEAT_PRESERVATION', 'RELEASE_LINEAGE_INTEGRITY')),
  CHECK (does_not_grant_authority = 1),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'SUPERSEDED', 'REVOKED')),
  CONSTRAINT fk_audit_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_audit_profiles_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_audit_profiles_active_key
--   canonical: CREATE UNIQUE INDEX ... ON audit_profiles (tenant_id, workspace_id, profile_key)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_audit_profiles_active_key_np ON audit_profiles (tenant_id, workspace_id, profile_key);

CREATE TABLE IF NOT EXISTS audit_runs (
  audit_run_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  audit_profile_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  audit_category VARCHAR(255) NOT NULL,
  target_object_refs TEXT NOT NULL DEFAULT '[]',
  target_ledger_stream_id CHAR(36),
  target_ledger_first_sequence INT,
  target_ledger_last_sequence INT,
  requested_by_actor_type VARCHAR(32) NOT NULL,
  requested_by_actor_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  summary VARCHAR(255),
  requested_at CHAR(24) NOT NULL,
  completed_at CHAR(24),
  PRIMARY KEY (audit_run_id),
  CHECK (requested_by_actor_type IN ('OPERATOR', 'SERVICE', 'SYSTEM')),
  CHECK (status IN (     'DRAFT', 'VALIDATING_SCOPE', 'RUNNING', 'COMPLETED', 'FAILED', 'INCOMPLETE', 'CANCELLED', 'INVALIDATED')),
  CONSTRAINT fk_audit_runs_audit_profile_id FOREIGN KEY (audit_profile_id) REFERENCES audit_profiles (audit_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_audit_runs_profile ON audit_runs (audit_profile_id, status, requested_at);

CREATE TABLE IF NOT EXISTS console_command_surface_definitions (
  console_command_surface_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  command_surface_key VARCHAR(191) NOT NULL,
  surface_version VARCHAR(32) NOT NULL,
  owner_module_id VARCHAR(32) NOT NULL,
  owner_command_type VARCHAR(255) NOT NULL,
  required_permission VARCHAR(255) NOT NULL,
  effect_ceiling_summary TEXT,
  confirmation_level VARCHAR(32) NOT NULL,
  active_v1_status VARCHAR(32) NOT NULL,
  owner_revalidation_required TINYINT(1) NOT NULL DEFAULT 1,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_command_surface_id),
  CHECK (owner_module_id IN (     'M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11')),
  CHECK (confirmation_level IN (     'NONE', 'NORMAL', 'EXPLICIT_GOVERNANCE', 'EXACT_HUMAN_APPROVAL', 'SENSITIVE_EXPORT')),
  CHECK (active_v1_status IN (     'ACTIVE', 'READ_ONLY', 'NOT_IMPLEMENTED', 'PROHIBITED')),
  CHECK (owner_revalidation_required = 1),
  CONSTRAINT fk_console_command_surface_definitions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_console_command_surface_definitions_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_command_surface_key ON console_command_surface_definitions (tenant_id, workspace_id, command_surface_key);

CREATE TABLE IF NOT EXISTS console_decision_timeline_projections (
  console_decision_timeline_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ledger_stream_id CHAR(36) NOT NULL,
  timeline_json TEXT NOT NULL DEFAULT '[]',
  record_count INT NOT NULL DEFAULT 0,
  head_chain_hash CHAR(64),
  stale_status VARCHAR(32) NOT NULL,
  generated_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_decision_timeline_projection_id),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_decision_timeline ON console_decision_timeline_projections (tenant_id, workspace_id, ledger_stream_id);

CREATE TABLE IF NOT EXISTS console_display_representation_references (
  console_display_representation_reference_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  representation_type VARCHAR(48) NOT NULL,
  displayed_object_type VARCHAR(255) NOT NULL,
  displayed_object_id CHAR(36) NOT NULL,
  owner_basis_ref VARCHAR(255) NOT NULL,
  is_material TINYINT(1) NOT NULL DEFAULT 1,
  safe_label VARCHAR(255) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_display_representation_reference_id),
  CHECK (representation_type IN (     'INTERNAL_RELEASE_DISPLAY', 'PROVISIONAL_ANALYSIS_DISPLAY', 'APPROVAL_EXACT_PREVIEW_DISPLAY',     'ACTION_OUTCOME_DISPLAY', 'INTEGRITY_OR_FINDING_DISPLAY',     'CUSTOMER_OUTPUT_PROJECTION_DISPLAY',     'PROSPECT_OUTPUT_DISPLAY', 'PROSPECT_BATTLECARD_DISPLAY')),
  CHECK (is_material IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_console_display_refs_object ON console_display_representation_references (displayed_object_type, displayed_object_id, representation_type);

DROP TRIGGER IF EXISTS trg_console_display_refs_no_update;
DELIMITER $$
CREATE TRIGGER trg_console_display_refs_no_update
  BEFORE UPDATE ON console_display_representation_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_display_representation_references is append-only when material';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_console_display_refs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_console_display_refs_no_delete
  BEFORE DELETE ON console_display_representation_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_display_representation_references is append-only when material';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS console_navigation_entitlement_projections (
  console_navigation_entitlement_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  operator_id CHAR(36) NOT NULL,
  visible_nav_keys TEXT NOT NULL DEFAULT '[]',
  is_usability_only TINYINT(1) NOT NULL DEFAULT 1,
  stale_status VARCHAR(32) NOT NULL,
  generated_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_navigation_entitlement_projection_id),
  CHECK (is_usability_only = 1),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_nav_entitlement ON console_navigation_entitlement_projections (tenant_id, workspace_id, operator_id);

CREATE TABLE IF NOT EXISTS console_redaction_policy_projections (
  console_redaction_policy_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  target_org_role VARCHAR(255) NOT NULL,
  redaction_rules_json TEXT NOT NULL DEFAULT '[]',
  is_advisory_only TINYINT(1) NOT NULL DEFAULT 1,
  stale_status VARCHAR(32) NOT NULL,
  generated_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_redaction_policy_projection_id),
  CHECK (is_advisory_only = 1),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_redaction_policy ON console_redaction_policy_projections (tenant_id, workspace_id, target_org_role);

CREATE TABLE IF NOT EXISTS console_refresh_statuses (
  console_refresh_status_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  projection_type VARCHAR(255) NOT NULL,
  last_refresh_reason VARCHAR(32) NOT NULL,
  stale_status VARCHAR(32) NOT NULL,
  last_refreshed_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_refresh_status_id),
  CHECK (last_refresh_reason IN (     'USER_REQUEST', 'OWNER_EVENT', 'STALE_DETECTED', 'POST_COMMAND', 'AUDIT_RECONCILIATION', 'TEST')),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_console_refresh_statuses_scope ON console_refresh_statuses (tenant_id, workspace_id, projection_type);

CREATE TABLE IF NOT EXISTS console_release_readiness_projections (
  console_release_readiness_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  release_readiness_state VARCHAR(32) NOT NULL,
  released_basis_decision_record_id CHAR(36),
  blocking_gap_count INT NOT NULL DEFAULT 0,
  blocking_gap_ids TEXT NOT NULL DEFAULT '[]',
  stale_status VARCHAR(32) NOT NULL,
  generated_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_release_readiness_projection_id),
  CHECK (release_readiness_state IN (     'NOT_EVALUATED', 'INCOMPLETE', 'BLOCKED', 'READY_FOR_INTERNAL_DEMO_REVIEW', 'INTERNAL_DEMO_VALIDATED',     'ROLLED_BACK_OR_SUPERSEDED')),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_release_readiness ON console_release_readiness_projections (tenant_id, workspace_id, proposal_id);

CREATE TABLE IF NOT EXISTS console_workspace_context_projections (
  console_workspace_context_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  operator_id CHAR(36) NOT NULL,
  visible_role_summaries TEXT NOT NULL DEFAULT '[]',
  visible_permission_summaries TEXT NOT NULL DEFAULT '[]',
  stale_status VARCHAR(32) NOT NULL,
  source_refs TEXT NOT NULL DEFAULT '[]',
  generated_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_workspace_context_projection_id),
  CHECK (stale_status IN ('CURRENT', 'STALE', 'UNKNOWN', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_console_workspace_context ON console_workspace_context_projections (tenant_id, workspace_id, operator_id);

CREATE TABLE IF NOT EXISTS customer_output_projections (
  customer_output_projection_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  release_artifact_id CHAR(36) NOT NULL,
  audience_class VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  refusal_reason_codes TEXT NOT NULL DEFAULT '[]',
  content_elements_safe_json TEXT NOT NULL DEFAULT '[]',
  redaction_decisions_json TEXT NOT NULL,
  contains_claim TINYINT(1) NOT NULL,
  caveat_blocks_present TINYINT(1) NOT NULL,
  redaction_engine_version VARCHAR(32) NOT NULL,
  projection_digest CHAR(64) NOT NULL,
  projected_at CHAR(24) NOT NULL,
  PRIMARY KEY (customer_output_projection_id),
  CHECK (audience_class IN (     'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM')),
  CHECK (status IN ('PROJECTED', 'REFUSED')),
  CHECK (contains_claim IN (0, 1)),
  CHECK (caveat_blocks_present IN (0, 1)),
  CHECK (status <> 'REFUSED' OR (refusal_reason_codes <> '[]' AND content_elements_safe_json = '[]')),
  CHECK (status <> 'PROJECTED' OR refusal_reason_codes = '[]'),
  CHECK (contains_claim = 0 OR caveat_blocks_present = 1),
  CONSTRAINT fk_customer_output_projections_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_customer_output_projections_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_customer_output_projections_artifact_audience ON customer_output_projections (release_artifact_id, audience_class);

DROP TRIGGER IF EXISTS trg_customer_output_projections_no_update;
DELIMITER $$
CREATE TRIGGER trg_customer_output_projections_no_update
  BEFORE UPDATE ON customer_output_projections
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_output_projections is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_customer_output_projections_no_delete;
DELIMITER $$
CREATE TRIGGER trg_customer_output_projections_no_delete
  BEFORE DELETE ON customer_output_projections
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_output_projections is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS decision_ledger_streams (
  decision_ledger_stream_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  environment_class VARCHAR(32) NOT NULL,
  stream_key VARCHAR(191) NOT NULL,
  genesis_or_initial_anchor_hash CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  current_head_record_id CHAR(36),
  current_head_chain_hash CHAR(64) NOT NULL,
  head_sequence_number INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  PRIMARY KEY (decision_ledger_stream_id),
  CHECK (environment_class IN ('LOCAL_DEV', 'TEST', 'DEMO')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'SEALED', 'SUPERSEDED')),
  CONSTRAINT fk_decision_ledger_streams_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_decision_ledger_streams_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_decision_ledger_streams_active_scope
--   canonical: CREATE UNIQUE INDEX ... ON decision_ledger_streams (tenant_id, workspace_id, environment_class)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_decision_ledger_streams_active_scope_np ON decision_ledger_streams (tenant_id, workspace_id, environment_class);

CREATE UNIQUE INDEX ux_decision_ledger_streams_key ON decision_ledger_streams (tenant_id, workspace_id, stream_key);

CREATE TABLE IF NOT EXISTS decision_records (
  decision_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  ledger_stream_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  record_sequence_number INT NOT NULL,
  record_type VARCHAR(48) NOT NULL,
  materiality_class VARCHAR(32) NOT NULL,
  primary_outcome_status VARCHAR(32) NOT NULL,
  mission_id CHAR(36),
  proposal_id CHAR(36),
  proposal_submission_digest CHAR(64),
  correlation_id CHAR(36),
  safe_decision_summary TEXT NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  uncertainty_and_limitation_summary TEXT,
  severity VARCHAR(255),
  incident_evaluation_required TINYINT(1) NOT NULL DEFAULT 0,
  supersedes_or_corrects_decision_record_id CHAR(36),
  current_reliance_status VARCHAR(48) NOT NULL DEFAULT 'ACTIVE_AS_RECORDED',
  record_payload_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  recorded_by_service_identity_id CHAR(36) NOT NULL,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (decision_record_id),
  CHECK (record_type IN (     'INTERNAL_INFORMATIONAL_RELEASE', 'PROVISIONAL_ANALYSIS_DISPLAY', 'PROPOSAL_HELD', 'PROPOSAL_BLOCKED',     'PROPOSAL_QUARANTINED', 'INCIDENT_EVALUATION_ROUTED', 'TOOL_OPERATION_DENIED_NON_DISPATCH',     'TOOL_OPERATION_FAILED_OR_UNKNOWN', 'HARMLESS_DEMO_ACTION_COMPLETED', 'HARMLESS_DEMO_ACTION_PREVENTED',     'HARMLESS_DEMO_ACTION_FAILED', 'HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN', 'APPROVAL_OR_AUTHORIZATION_REVOKED',     'REPLAY_TEST_FINDING_RECORDED', 'AUDIT_FINDING_LINKED', 'POLICY_OR_PROVENANCE_BLOCK_RECORDED',     'DECISION_CORRECTION_OR_INVALIDATION', 'SOURCE_CONFIG_APPROVED', 'SOURCE_CONFIG_SUPERSEDED')),
  CHECK (materiality_class IN (     'GOVERNANCE_MATERIAL', 'ACTION_MATERIAL', 'SECURITY_MATERIAL', 'AUDIT_MATERIAL', 'REPLAY_TEST_MATERIAL')),
  CHECK (primary_outcome_status IN (     'RELEASED_INTERNAL', 'DISPLAYED_PROVISIONAL', 'HELD', 'BLOCKED', 'QUARANTINED', 'INCIDENT_ROUTED',     'DENIED_NON_DISPATCH', 'EXECUTED_SUCCESS', 'EXECUTION_PREVENTED', 'EXECUTION_FAILED', 'OUTCOME_UNKNOWN',     'REVOKED', 'REPLAY_RECORDED', 'CORRECTED_OR_INVALIDATED')),
  CHECK (severity IS NULL OR severity IN ('S0', 'S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (incident_evaluation_required IN (0, 1)),
  CHECK (current_reliance_status IN (     'ACTIVE_AS_RECORDED', 'HISTORICAL_ONLY', 'SUPERSEDED', 'INVALIDATED_FOR_CURRENT_RELIANCE', 'UNDER_REVIEW')),
  CONSTRAINT fk_decision_records_ledger_stream_id FOREIGN KEY (ledger_stream_id) REFERENCES decision_ledger_streams (decision_ledger_stream_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_decision_records_stream_sequence ON decision_records (ledger_stream_id, record_sequence_number);

CREATE INDEX ix_decision_records_proposal ON decision_records (proposal_id, record_type, recorded_at);

DROP TRIGGER IF EXISTS trg_decision_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_decision_records_no_update
  BEFORE UPDATE ON decision_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_records is append-only and immutable (INV-013)';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_decision_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_decision_records_no_delete
  BEFORE DELETE ON decision_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_records cannot be deleted (INV-013)';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS decision_supersession_records (
  decision_supersession_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  original_decision_record_id CHAR(36) NOT NULL,
  successor_decision_record_id CHAR(36),
  change_type VARCHAR(48) NOT NULL,
  authority_source_module_id VARCHAR(32) NOT NULL,
  authority_source_object_id CHAR(36) NOT NULL,
  safe_change_summary TEXT NOT NULL,
  new_current_reliance_status VARCHAR(48) NOT NULL,
  original_remains_historical TINYINT(1) NOT NULL DEFAULT 1,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (decision_supersession_record_id),
  CHECK (change_type IN (     'SUPERSEDED_BY_NEW_DECISION', 'CORRECTED_BY_APPEND_ONLY_RECORD', 'INVALIDATED_FOR_CURRENT_RELIANCE',     'ANNOTATED_WITH_LATER_FINDING', 'REPLAY_COMPARISON_ONLY')),
  CHECK (authority_source_module_id IN ('M08', 'M09', 'M10', 'M11')),
  CHECK (new_current_reliance_status IN (     'HISTORICAL_ONLY', 'SUPERSEDED', 'INVALIDATED_FOR_CURRENT_RELIANCE', 'UNDER_REVIEW')),
  CHECK (original_remains_historical = 1),
  CONSTRAINT fk_decision_supersession_records_original_decision_record_id FOREIGN KEY (original_decision_record_id) REFERENCES decision_records (decision_record_id),
  CONSTRAINT fk_decision_supersession_records_successor_decision_record_id FOREIGN KEY (successor_decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_decision_supersession_records_original ON decision_supersession_records (original_decision_record_id, created_at);

DROP TRIGGER IF EXISTS trg_decision_supersession_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_decision_supersession_records_no_update
  BEFORE UPDATE ON decision_supersession_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_supersession_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_decision_supersession_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_decision_supersession_records_no_delete
  BEFORE DELETE ON decision_supersession_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_supersession_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS gap_records (
  gap_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  gap_type VARCHAR(32) NOT NULL,
  affected_plane VARCHAR(32) NOT NULL,
  effect_on_v1 VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  safe_gap_summary TEXT NOT NULL,
  source_finding_id CHAR(36),
  source_incident_case_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24),
  PRIMARY KEY (gap_record_id),
  CHECK (gap_type IN (     'SOURCE_GAP', 'RUNTIME_GAP', 'OPS_GAP', 'POLICY_GAP', 'INTEGRATION_GAP', 'PROVENANCE_IP_GAP', 'VALIDATION_GAP')),
  CHECK (affected_plane IN ('CORE', 'SOURCES', 'OPS', 'MULTI_PLANE')),
  CHECK (effect_on_v1 IN (     'NONE', 'QUALIFY_CLAIM', 'BLOCK_FEATURE', 'BLOCK_RELEASE', 'REQUIRE_INCIDENT_REVIEW')),
  CHECK (status IN (     'OPEN', 'UNDER_INVESTIGATION', 'REMEDIATION_PROPOSED', 'RESOLVED_WITH_EVIDENCE', 'ACCEPTED_LIMITATION',     'SUPERSEDED', 'CLOSED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_gap_records_effect ON gap_records (gap_type, status, effect_on_v1, created_at);

CREATE TABLE IF NOT EXISTS governed_admission_events (
  governed_admission_event_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  reason_code VARCHAR(255) NOT NULL,
  materiality VARCHAR(32) NOT NULL,
  observed_at CHAR(24) NOT NULL,
  PRIMARY KEY (governed_admission_event_id),
  CHECK (event_type IN ('COMMAND_SNAPSHOT_FAILED', 'DISPATCH_ADMISSION_REFUSED', 'DISPATCH_FINALIZATION_FAILED')),
  CHECK (materiality IN ('SECURITY'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_governed_admission_events_observed ON governed_admission_events (observed_at);

DROP TRIGGER IF EXISTS trg_governed_admission_events_no_update;
DELIMITER $$
CREATE TRIGGER trg_governed_admission_events_no_update
  BEFORE UPDATE ON governed_admission_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'governed_admission_events is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_governed_admission_events_no_delete;
DELIMITER $$
CREATE TRIGGER trg_governed_admission_events_no_delete
  BEFORE DELETE ON governed_admission_events
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'governed_admission_events is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS governed_change_proposals (
  governed_change_proposal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  proposal_type VARCHAR(32) NOT NULL,
  promotion_requirement_status VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  not_active_policy_or_runtime_law TINYINT(1) NOT NULL DEFAULT 1,
  safe_proposal_summary TEXT NOT NULL,
  source_gap_record_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24),
  PRIMARY KEY (governed_change_proposal_id),
  CHECK (proposal_type IN (     'SPECIFICATION_CHANGE', 'POLICY_CHANGE', 'CONFIGURATION_CHANGE', 'MODULE_IMPLEMENTATION_CHANGE',     'TOOL_OR_CAPABILITY_PROMOTION', 'ROLLOUT_CHANGE', 'ROLLBACK_CHANGE', 'DOCUMENTATION_ALIGNMENT_CHANGE')),
  CHECK (promotion_requirement_status IN (     'NOT_APPLICABLE', 'INCOMPLETE', 'READY_FOR_EVALUATION')),
  CHECK (status IN (     'DRAFT', 'READY_FOR_REVIEW', 'UNDER_REVIEW', 'ACCEPTED_FOR_IMPLEMENTATION', 'REJECTED',     'IMPLEMENTATION_PENDING', 'VERIFICATION_PENDING', 'IMPLEMENTED_AND_VERIFIED', 'ROLLED_BACK', 'SUPERSEDED')),
  CHECK (not_active_policy_or_runtime_law = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_governed_change_proposals_scope ON governed_change_proposals (tenant_id, workspace_id, status, proposal_type);

CREATE TABLE IF NOT EXISTS incident_evaluation_requests (
  incident_evaluation_request_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  trigger_owner_module_id VARCHAR(32) NOT NULL,
  trigger_reason_codes TEXT NOT NULL DEFAULT '[]',
  trigger_severity VARCHAR(32) NOT NULL,
  related_decision_record_ids TEXT NOT NULL DEFAULT '[]',
  related_object_refs TEXT NOT NULL DEFAULT '[]',
  status VARCHAR(32) NOT NULL,
  triage_decision VARCHAR(255),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (incident_evaluation_request_id),
  CHECK (trigger_owner_module_id IN (     'M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11', 'M12')),
  CHECK (trigger_severity IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (status IN (     'RECEIVED', 'TRIAGED', 'CASE_OPENED', 'NOT_AN_INCIDENT', 'DUPLICATE', 'CLOSED')),
  CHECK (triage_decision IS NULL OR triage_decision IN (     'OPEN_CASE', 'NOT_AN_INCIDENT', 'DUPLICATE_OF_EXISTING_CASE', 'HOLD_FOR_MORE_INFORMATION', 'ESCALATE_OUTSIDE_V1'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_incident_requests_scope ON incident_evaluation_requests (tenant_id, workspace_id, status);

CREATE TABLE IF NOT EXISTS integration_health_records (
  health_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  component_ref VARCHAR(255) NOT NULL,
  health_status VARCHAR(32) NOT NULL,
  impact_scope VARCHAR(255),
  observed_issue VARCHAR(255),
  recommended_response VARCHAR(255),
  observed_at CHAR(24) NOT NULL,
  PRIMARY KEY (health_record_id),
  CHECK (health_status IN ('HEALTHY', 'DEGRADED', 'UNAVAILABLE', 'MISCONFIGURED', 'UNTRUSTED_BEHAVIOR', 'UNKNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_integration_health_no_update;
DELIMITER $$
CREATE TRIGGER trg_integration_health_no_update
  BEFORE UPDATE ON integration_health_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration_health_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_integration_health_no_delete;
DELIMITER $$
CREATE TRIGGER trg_integration_health_no_delete
  BEFORE DELETE ON integration_health_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration_health_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ledger_integrity_links (
  ledger_integrity_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  ledger_stream_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  record_sequence_number INT NOT NULL,
  previous_decision_record_id CHAR(36),
  previous_chain_hash CHAR(64) NOT NULL,
  record_payload_digest CHAR(64) NOT NULL,
  chain_hash CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (ledger_integrity_link_id),
  CONSTRAINT fk_ledger_integrity_links_ledger_stream_id FOREIGN KEY (ledger_stream_id) REFERENCES decision_ledger_streams (decision_ledger_stream_id),
  CONSTRAINT fk_ledger_integrity_links_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ledger_integrity_links_stream_sequence ON ledger_integrity_links (ledger_stream_id, record_sequence_number);

CREATE UNIQUE INDEX ux_ledger_integrity_links_record ON ledger_integrity_links (decision_record_id);

DROP TRIGGER IF EXISTS trg_ledger_integrity_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_ledger_integrity_links_no_update
  BEFORE UPDATE ON ledger_integrity_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_integrity_links is append-only and immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ledger_integrity_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ledger_integrity_links_no_delete
  BEFORE DELETE ON ledger_integrity_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_integrity_links cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ledger_verification_runs (
  ledger_verification_run_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  ledger_stream_id CHAR(36) NOT NULL,
  verification_purpose VARCHAR(32) NOT NULL,
  first_sequence_number INT,
  last_sequence_number INT,
  records_verified_count INT NOT NULL DEFAULT 0,
  expected_head_chain_hash CHAR(64),
  observed_head_chain_hash CHAR(64),
  chain_verification_status VARCHAR(32) NOT NULL,
  reference_completeness_status VARCHAR(32) NOT NULL,
  broken_link_or_missing_reference_ids TEXT NOT NULL DEFAULT '[]',
  severity VARCHAR(255),
  incident_evaluation_required TINYINT(1) NOT NULL DEFAULT 0,
  verified_at CHAR(24) NOT NULL,
  PRIMARY KEY (ledger_verification_run_id),
  CHECK (verification_purpose IN (     'INTEGRITY_CHECK', 'PRE_RELEASE', 'PRE_EXPORT', 'AUDIT', 'REPLAY', 'INCIDENT_INVESTIGATION', 'TEST')),
  CHECK (chain_verification_status IN ('PASS', 'FAIL', 'INCOMPLETE', 'NOT_RUN')),
  CHECK (reference_completeness_status IN ('PASS', 'FAIL', 'INCOMPLETE', 'NOT_RUN')),
  CHECK (severity IS NULL OR severity IN ('S0', 'S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (incident_evaluation_required IN (0, 1)),
  CONSTRAINT fk_ledger_verification_runs_ledger_stream_id FOREIGN KEY (ledger_stream_id) REFERENCES decision_ledger_streams (decision_ledger_stream_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_ledger_verification_runs_stream ON ledger_verification_runs (ledger_stream_id, verified_at);

DROP TRIGGER IF EXISTS trg_ledger_verification_runs_no_update;
DELIMITER $$
CREATE TRIGGER trg_ledger_verification_runs_no_update
  BEFORE UPDATE ON ledger_verification_runs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_verification_runs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ledger_verification_runs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ledger_verification_runs_no_delete
  BEFORE DELETE ON ledger_verification_runs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_verification_runs is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS missions (
  mission_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_version INT NOT NULL DEFAULT 1,
  mission_type VARCHAR(32) NOT NULL,
  title VARCHAR(255) NOT NULL,
  objective VARCHAR(255) NOT NULL,
  purpose_summary TEXT NOT NULL,
  target_scope VARCHAR(255) NOT NULL,
  requested_action_classes TEXT NOT NULL DEFAULT '[]',
  permitted_output_classes TEXT NOT NULL DEFAULT '[]',
  allowed_tool_ids TEXT NOT NULL DEFAULT '[]',
  allowed_source_scope_ids TEXT NOT NULL DEFAULT '[]',
  evidence_requirement_id CHAR(36),
  review_path_id CHAR(36),
  completion_criteria TEXT NOT NULL DEFAULT '[]',
  explicit_prohibitions TEXT NOT NULL DEFAULT '[]',
  status VARCHAR(32) NOT NULL,
  valid_from CHAR(24),
  expires_at CHAR(24),
  created_by_actor_type VARCHAR(32) NOT NULL,
  created_by_actor_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  activated_by_operator_id CHAR(36),
  supersedes_mission_id CHAR(36),
  PRIMARY KEY (mission_id),
  CHECK (mission_type IN ('RESEARCH', 'INTERNAL_BRIEFING', 'CONTROLLED_DEMO_ACTION', 'REPLAY_SUPPORT', 'TEST', 'COMPETITION_MONITORING', 'LEAD_QUALIFICATION', 'INTELLIGENCE_DISTRIBUTION', 'CRM_OUTREACH', 'ENTITY_TWIN_CURATION', 'MARKETING_CUSTOMIZATION')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'PAUSED', 'EXPIRED', 'COMPLETED', 'CANCELLED', 'BLOCKED', 'SUPERSEDED')),
  CHECK (created_by_actor_type IN ('OPERATOR', 'SERVICE')),
  CONSTRAINT fk_missions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_missions_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_missions_scope_status ON missions (tenant_id, workspace_id, status);

CREATE TABLE IF NOT EXISTS output_access_rules (
  output_access_rule_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  audience_class VARCHAR(32) NOT NULL,
  artifact_kind VARCHAR(48) NOT NULL,
  rule_version VARCHAR(32) NOT NULL,
  required_permission_key VARCHAR(191) NOT NULL,
  internal_scope_lens VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  supersedes_output_access_rule_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (output_access_rule_id),
  CHECK (audience_class IN (     'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM')),
  CHECK (artifact_kind IN (     'COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING', 'ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW')),
  CHECK (internal_scope_lens IS NULL OR internal_scope_lens IN (     'TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (status IN ('ACTIVE', 'SUPERSEDED', 'SUSPENDED')),
  CHECK (audience_class IN ('OPERATOR_ONLY', 'TENANT_INTERNAL')),
  CHECK (audience_class = 'TENANT_INTERNAL' OR internal_scope_lens IS NULL),
  CONSTRAINT fk_output_access_rules_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_output_access_rules_version ON output_access_rules (tenant_id, audience_class, artifact_kind, rule_version);

-- PARTIAL INDEX (MariaDB has no partial index): ux_output_access_rules_one_active
--   canonical: CREATE UNIQUE INDEX ... ON output_access_rules (tenant_id, audience_class, artifact_kind)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_output_access_rules_one_active_np ON output_access_rules (tenant_id, audience_class, artifact_kind);

DROP TRIGGER IF EXISTS trg_output_access_rules_identity_immutable;
DELIMITER $$
CREATE TRIGGER trg_output_access_rules_identity_immutable
  BEFORE UPDATE ON output_access_rules
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.output_access_rule_id <> NEW.output_access_rule_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.audience_class <> NEW.audience_class OR OLD.artifact_kind <> NEW.artifact_kind OR OLD.rule_version <> NEW.rule_version OR OLD.required_permission_key <> NEW.required_permission_key OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR COALESCE(OLD.supersedes_output_access_rule_id, '') <> COALESCE(NEW.supersedes_output_access_rule_id, '') OR OLD.created_at <> NEW.created_at
  IF OLD.output_access_rule_id <> NEW.output_access_rule_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.audience_class <> NEW.audience_class OR OLD.artifact_kind <> NEW.artifact_kind OR OLD.rule_version <> NEW.rule_version OR OLD.required_permission_key <> NEW.required_permission_key OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR COALESCE(OLD.supersedes_output_access_rule_id, '') <> COALESCE(NEW.supersedes_output_access_rule_id, '') OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'output_access_rules identity is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_output_access_rules_no_delete;
DELIMITER $$
CREATE TRIGGER trg_output_access_rules_no_delete
  BEFORE DELETE ON output_access_rules
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'output_access_rules rows are never deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS output_visibility_profiles (
  output_visibility_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  audience_class VARCHAR(32) NOT NULL,
  profile_version VARCHAR(32) NOT NULL,
  activation_permitted TINYINT(1) NOT NULL,
  required_permission_key VARCHAR(191) NOT NULL,
  internal_scope_lens VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (output_visibility_profile_id),
  CHECK (audience_class IN (     'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE', 'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM')),
  CHECK (activation_permitted IN (0, 1)),
  CHECK (internal_scope_lens IS NULL OR internal_scope_lens IN (     'TENANT_ADMIN_LEADERSHIP', 'SALES', 'MARKETING', 'PRODUCT', 'PROCUREMENT_FINANCE_OPS', 'BROAD_INTERNAL_BI', 'RESTRICTED_NEED_TO_KNOW')),
  CHECK (status IN ('ACTIVE', 'SUPERSEDED', 'SUSPENDED')),
  CHECK (audience_class IN ('OPERATOR_ONLY', 'TENANT_INTERNAL') OR activation_permitted = 0),
  CHECK (audience_class = 'TENANT_INTERNAL' OR internal_scope_lens IS NULL),
  CONSTRAINT fk_output_visibility_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_output_visibility_profiles_version ON output_visibility_profiles (tenant_id, audience_class, profile_version);

-- PARTIAL INDEX (MariaDB has no partial index): ux_output_visibility_profiles_one_active
--   canonical: CREATE UNIQUE INDEX ... ON output_visibility_profiles (tenant_id, audience_class)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_output_visibility_profiles_one_active_np ON output_visibility_profiles (tenant_id, audience_class);

DROP TRIGGER IF EXISTS trg_output_visibility_profiles_identity_immutable;
DELIMITER $$
CREATE TRIGGER trg_output_visibility_profiles_identity_immutable
  BEFORE UPDATE ON output_visibility_profiles
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.output_visibility_profile_id <> NEW.output_visibility_profile_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.audience_class <> NEW.audience_class OR OLD.profile_version <> NEW.profile_version OR OLD.activation_permitted <> NEW.activation_permitted OR OLD.required_permission_key <> NEW.required_permission_key OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR OLD.created_at <> NEW.created_at
  IF OLD.output_visibility_profile_id <> NEW.output_visibility_profile_id OR OLD.schema_version <> NEW.schema_version OR OLD.tenant_id <> NEW.tenant_id OR OLD.audience_class <> NEW.audience_class OR OLD.profile_version <> NEW.profile_version OR OLD.activation_permitted <> NEW.activation_permitted OR OLD.required_permission_key <> NEW.required_permission_key OR COALESCE(OLD.internal_scope_lens, '') <> COALESCE(NEW.internal_scope_lens, '') OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'output_visibility_profiles identity is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_output_visibility_profiles_no_delete;
DELIMITER $$
CREATE TRIGGER trg_output_visibility_profiles_no_delete
  BEFORE DELETE ON output_visibility_profiles
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'output_visibility_profiles rows are never deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS proposal_envelopes (
  proposal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  producing_valkyrie_id CHAR(36) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  capability_contract_version_id CHAR(36) NOT NULL,
  proposal_type VARCHAR(48) NOT NULL,
  requested_action_class VARCHAR(32) NOT NULL,
  requested_next_stage VARCHAR(32) NOT NULL,
  title VARCHAR(255) NOT NULL,
  safe_purpose_summary TEXT NOT NULL,
  proposed_content_or_action_summary TEXT NOT NULL,
  reasoning_summary TEXT,
  uncertainty_summary TEXT,
  declared_limitations TEXT NOT NULL DEFAULT '[]',
  evidence_refs TEXT NOT NULL DEFAULT '[]',
  evidence_bundle_id CHAR(36),
  adverse_or_conflict_evidence_refs TEXT NOT NULL DEFAULT '[]',
  missingness_statement VARCHAR(255),
  requested_release_or_action_scope VARCHAR(255),
  requested_review_path VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  supersedes_proposal_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  submitted_at CHAR(24),
  submission_digest CHAR(64),
  PRIMARY KEY (proposal_id),
  CHECK (proposal_type IN ('INTERNAL_BRIEFING', 'CONTROLLED_DEMO_ACTION', 'COMPANY_SIGNAL_BRIEF', 'BATTLECARD_BRIEF', 'FIT_HYPOTHESIS', 'PRIORITY_OUTREACH_QUEUE_ADMISSION', 'INTELLIGENCE_DISTRIBUTION_ROUTING', 'CRM_OUTREACH_EXECUTION', 'ENTITY_TWIN_STATE_CHANGE', 'ENTITY_RELATIONSHIP_STATE_CHANGE', 'MARKETING_ASSET_DRAFT', 'SOURCE_MONITORING_CONFIG_PROPOSAL', 'SOURCE_MONITORING_TRIGGER_FINDING')),
  CHECK (requested_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (requested_next_stage IN ('EVIDENCE_COLLECTION', 'HMWH_REVIEW', 'ARBITER_EVALUATION')),
  CHECK (requested_review_path IS NULL OR requested_review_path IN ('ARBITER_ONLY', 'HMWH_THEN_ARBITER', 'ARBITER_THEN_APPROVAL_FOR_DEMO_ACTION')),
  CHECK (status IN ('DRAFT', 'SUBMITTED', 'RELEASED', 'SUPERSEDED', 'WITHDRAWN', 'BLOCKED')),
  CONSTRAINT fk_proposal_envelopes_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_proposal_envelopes_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_proposal_envelopes_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_proposal_envelopes_mission ON proposal_envelopes (mission_id, status);

DROP TRIGGER IF EXISTS trg_proposal_envelopes_no_delete;
DELIMITER $$
CREATE TRIGGER trg_proposal_envelopes_no_delete
  BEFORE DELETE ON proposal_envelopes
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.status <> 'DRAFT'
  IF OLD.status <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'submitted proposal_envelopes cannot be deleted';
  END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS proposal_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  from_status VARCHAR(255) NOT NULL,
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  initiated_by_actor_type VARCHAR(32) NOT NULL,
  initiated_by_actor_id CHAR(36),
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CHECK (initiated_by_actor_type IN ('VALKYRIE', 'OPERATOR', 'SERVICE', 'ARBITER', 'HMWH', 'M09', 'M10', 'M11')),
  CONSTRAINT fk_proposal_status_transitions_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_proposal_status_transitions_proposal ON proposal_status_transitions (proposal_id, occurred_at);

DROP TRIGGER IF EXISTS trg_proposal_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_proposal_status_transitions_no_update
  BEFORE UPDATE ON proposal_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_proposal_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_proposal_status_transitions_no_delete
  BEFORE DELETE ON proposal_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS proposal_submission_records (
  submission_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  submission_digest CHAR(64) NOT NULL,
  submitted_at CHAR(24) NOT NULL,
  submitted_by_actor_type VARCHAR(32) NOT NULL,
  submitted_by_actor_id CHAR(36) NOT NULL,
  PRIMARY KEY (submission_record_id),
  CHECK (submitted_by_actor_type IN ('VALKYRIE', 'OPERATOR', 'SERVICE')),
  CONSTRAINT fk_proposal_submission_records_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_proposal_submission_per_proposal ON proposal_submission_records (proposal_id);

DROP TRIGGER IF EXISTS trg_proposal_submission_no_update;
DELIMITER $$
CREATE TRIGGER trg_proposal_submission_no_update
  BEFORE UPDATE ON proposal_submission_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_submission_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_proposal_submission_no_delete;
DELIMITER $$
CREATE TRIGGER trg_proposal_submission_no_delete
  BEFORE DELETE ON proposal_submission_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_submission_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS replay_profiles (
  replay_profile_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  profile_key VARCHAR(191) NOT NULL,
  profile_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  requires_no_side_effect_enforcement TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  PRIMARY KEY (replay_profile_id),
  CHECK (requires_no_side_effect_enforcement = 1),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'SUPERSEDED', 'REVOKED')),
  CONSTRAINT fk_replay_profiles_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_replay_profiles_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_replay_profiles_active_key
--   canonical: CREATE UNIQUE INDEX ... ON replay_profiles (tenant_id, workspace_id, profile_key)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_replay_profiles_active_key_np ON replay_profiles (tenant_id, workspace_id, profile_key);

CREATE TABLE IF NOT EXISTS runtime_host_instances (
  runtime_host_instance_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  host_family VARCHAR(255) NOT NULL,
  host_name VARCHAR(255) NOT NULL,
  host_instance_key VARCHAR(191) NOT NULL,
  host_version VARCHAR(32) NOT NULL,
  repository_reference_safe VARCHAR(255) NOT NULL,
  inspected_commit_or_build_ref VARCHAR(255),
  licence_status_ref VARCHAR(255) NOT NULL,
  integration_map_document_ref VARCHAR(255) NOT NULL,
  environment_class VARCHAR(32) NOT NULL,
  declared_security_assumptions TEXT NOT NULL DEFAULT '[]',
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (runtime_host_instance_id),
  CHECK (length(host_family) > 0),
  CHECK (length(host_name) > 0),
  CHECK (length(host_instance_key) > 0),
  CHECK (environment_class IN ('LOCAL_DEV', 'TEST', 'DEMO')),
  CHECK (status IN ('REGISTERED', 'SUSPENDED', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_runtime_host_instances_identity ON runtime_host_instances (host_family, host_name, host_instance_key);

CREATE TABLE IF NOT EXISTS usage_metering_records (
  usage_metering_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  subscription_tier_id CHAR(36),
  metered_dimension VARCHAR(32) NOT NULL,
  quantity INT NOT NULL,
  unit_rate_minor_units INT NOT NULL DEFAULT 0,
  currency VARCHAR(255) NOT NULL DEFAULT 'SEK',
  billable_minor_units INT NOT NULL DEFAULT 0,
  correlation_ref VARCHAR(255),
  no_external_charge_executed TINYINT(1) NOT NULL DEFAULT 1,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (usage_metering_record_id),
  CHECK (metered_dimension IN ('TOKEN_USAGE', 'FIT_MATCHING_TOKEN_USAGE', 'DISTRIBUTION_SEAT_USAGE')),
  CHECK (no_external_charge_executed = 1),
  CONSTRAINT fk_usage_metering_records_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_usage_metering_records_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_usage_metering_records_subscription_tier_id FOREIGN KEY (subscription_tier_id) REFERENCES subscription_tiers (subscription_tier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_usage_metering_records_scope ON usage_metering_records (tenant_id, metered_dimension, recorded_at);

DROP TRIGGER IF EXISTS trg_usage_metering_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_usage_metering_records_no_update
  BEFORE UPDATE ON usage_metering_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'usage_metering_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_usage_metering_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_usage_metering_records_no_delete
  BEFORE DELETE ON usage_metering_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'usage_metering_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS arbiter_evaluation_runs (
  arbiter_evaluation_run_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  proposal_submission_digest CHAR(64) NOT NULL,
  requested_proposal_type VARCHAR(255) NOT NULL,
  requested_action_class VARCHAR(32) NOT NULL,
  evidence_bundle_id CHAR(36),
  evidence_bundle_hash CHAR(64),
  hmwh_result_id CHAR(36),
  arbiter_policy_set_id CHAR(36) NOT NULL,
  policy_version VARCHAR(32) NOT NULL,
  correlation_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  requested_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_evaluation_run_id),
  CHECK (requested_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (status IN ('EVALUATING', 'VERDICT_ISSUED', 'HELD', 'BLOCKED', 'INVALIDATED', 'CANCELLED')),
  CONSTRAINT fk_arbiter_evaluation_runs_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_arbiter_evaluation_runs_arbiter_policy_set_id FOREIGN KEY (arbiter_policy_set_id) REFERENCES arbiter_policy_sets (arbiter_policy_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_evaluation_runs_proposal ON arbiter_evaluation_runs (proposal_id, status);

CREATE TABLE IF NOT EXISTS arbiter_policy_rules (
  arbiter_policy_rule_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  arbiter_policy_set_id CHAR(36) NOT NULL,
  policy_version VARCHAR(32) NOT NULL,
  rule_key VARCHAR(191) NOT NULL,
  rule_category VARCHAR(48) NOT NULL,
  rule_summary TEXT NOT NULL,
  check_type VARCHAR(32) NOT NULL,
  failure_effect VARCHAR(255) NOT NULL,
  incident_evaluation_if_failed INT NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_policy_rule_id),
  CHECK (rule_category IN ('V1_SCOPE', 'M02_ACCESS', 'M03_AUTHORITY', 'M04_MISSION_PROPOSAL', 'M05_EVIDENCE', 'M07_HMWH', 'M06_TOOL_HISTORY', 'M09_APPROVAL_ROUTE_PRECONDITION', 'PROVENANCE_IP', 'UNCERTAINTY_DISCLOSURE', 'INCIDENT_QUARANTINE', 'P01_RELEVANCE', 'P03_PROSPECT_ADMISSION', 'P04_DISTRIBUTION_ADMISSION', 'P07_OUTREACH_EXECUTION_ADMISSION', 'P02_TWIN_STATE_ADMISSION', 'P02_RELATIONSHIP_STATE_ADMISSION', 'P05_MARKETING_ASSET_ADMISSION', 'SOURCE_CONFIG_ADMISSION')),
  CHECK (check_type IN ('PASS_FAIL', 'ENUM_STATUS', 'REQUIRED_REFERENCE', 'PROHIBITION_CHECK', 'CONDITION_CHECK')),
  CHECK (status IN ('ACTIVE', 'SUPERSEDED', 'REVOKED')),
  CONSTRAINT fk_arbiter_policy_rules_arbiter_policy_set_id FOREIGN KEY (arbiter_policy_set_id) REFERENCES arbiter_policy_sets (arbiter_policy_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_policy_rules_set ON arbiter_policy_rules (arbiter_policy_set_id, priority);

CREATE TABLE IF NOT EXISTS arbiter_verdicts (
  arbiter_verdict_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  arbiter_evaluation_run_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  proposal_submission_digest CHAR(64) NOT NULL,
  evidence_bundle_id CHAR(36),
  evidence_bundle_hash CHAR(64),
  hmwh_result_id CHAR(36),
  arbiter_policy_set_id CHAR(36) NOT NULL,
  policy_version VARCHAR(32) NOT NULL,
  verdict VARCHAR(32) NOT NULL,
  permitted_next_stage VARCHAR(32) NOT NULL,
  admissibility_scope VARCHAR(255),
  passed_check_ids TEXT NOT NULL DEFAULT '[]',
  failed_or_holding_check_ids TEXT NOT NULL DEFAULT '[]',
  condition_ids TEXT NOT NULL DEFAULT '[]',
  reason_codes TEXT NOT NULL DEFAULT '[]',
  safe_rationale_summary TEXT NOT NULL,
  uncertainty_and_limitation_summary TEXT,
  severity VARCHAR(255),
  incident_evaluation_required INT NOT NULL DEFAULT 0,
  not_human_approval_or_execution_authority TINYINT(1) NOT NULL DEFAULT 1,
  basis_hash CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  issued_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_verdict_id),
  CHECK (verdict IN ('ALLOW_INFORMATIONAL_RELEASE', 'ALLOW_PROVISIONAL_ANALYSIS', 'ALLOW_WITH_CONDITIONS', 'REQUIRE_HUMAN_APPROVAL', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION', 'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION', 'ALLOW_BLOCK_ROUTING', 'ALLOW_REPLAY_TEST_FINDING')),
  CHECK (permitted_next_stage IN ('M10_INTERNAL_RELEASE_RECORDING', 'M12_PROVISIONAL_DISPLAY', 'M09_APPROVAL_REQUEST_CREATION', 'M04_REVISION_PATH', 'M05_ADDITIONAL_EVIDENCE_PATH', 'M11_INCIDENT_EVALUATION', 'M11_REPLAY_TEST_RECORDING', 'NO_PROGRESSION')),
  CHECK (severity IS NULL OR severity IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (not_human_approval_or_execution_authority = 1),
  CONSTRAINT fk_arbiter_verdicts_arbiter_evaluation_run_id FOREIGN KEY (arbiter_evaluation_run_id) REFERENCES arbiter_evaluation_runs (arbiter_evaluation_run_id),
  CONSTRAINT fk_arbiter_verdicts_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_arbiter_verdicts_arbiter_policy_set_id FOREIGN KEY (arbiter_policy_set_id) REFERENCES arbiter_policy_sets (arbiter_policy_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_verdicts_proposal ON arbiter_verdicts (proposal_id, issued_at);

CREATE INDEX ix_arbiter_verdicts_run ON arbiter_verdicts (arbiter_evaluation_run_id);

DROP TRIGGER IF EXISTS trg_arbiter_verdicts_no_update;
DELIMITER $$
CREATE TRIGGER trg_arbiter_verdicts_no_update
  BEFORE UPDATE ON arbiter_verdicts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_verdicts is immutable; a changed basis requires a new verdict row';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_arbiter_verdicts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_arbiter_verdicts_no_delete
  BEFORE DELETE ON arbiter_verdicts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_verdicts cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS audit_check_records (
  audit_check_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  audit_run_id CHAR(36) NOT NULL,
  check_key VARCHAR(191) NOT NULL,
  decision VARCHAR(32) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  severity_if_failed VARCHAR(255),
  safe_summary TEXT,
  evaluated_at CHAR(24) NOT NULL,
  PRIMARY KEY (audit_check_record_id),
  CHECK (decision IN ('PASS', 'FAIL', 'INCOMPLETE', 'NOT_APPLICABLE', 'DISPUTED')),
  CHECK (severity_if_failed IS NULL OR severity_if_failed IN ('S0', 'S1', 'S2', 'S3', 'S4', 'S5')),
  CONSTRAINT fk_audit_check_records_audit_run_id FOREIGN KEY (audit_run_id) REFERENCES audit_runs (audit_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_audit_check_records_run ON audit_check_records (audit_run_id, decision);

DROP TRIGGER IF EXISTS trg_audit_check_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_audit_check_records_no_update
  BEFORE UPDATE ON audit_check_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_check_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_audit_check_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_audit_check_records_no_delete
  BEFORE DELETE ON audit_check_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_check_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS audit_findings (
  audit_finding_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  audit_run_id CHAR(36) NOT NULL,
  audit_check_record_id CHAR(36) NOT NULL,
  finding_type VARCHAR(48) NOT NULL,
  finding_status VARCHAR(32) NOT NULL,
  severity VARCHAR(32) NOT NULL,
  confidence_classification VARCHAR(32) NOT NULL,
  current_reliance_consequence VARCHAR(32) NOT NULL,
  safe_finding_summary TEXT NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  incident_evaluation_required TINYINT(1) NOT NULL DEFAULT 0,
  decision_ledger_record_required TINYINT(1) NOT NULL DEFAULT 0,
  decision_record_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (audit_finding_id),
  CHECK (finding_type IN (     'CONTROL_PASS', 'CONTROL_FAILURE', 'INTEGRITY_FAILURE', 'REFERENCE_INCOMPLETENESS', 'SCOPE_VIOLATION',     'PROVENANCE_OR_IP_CONCERN', 'EVIDENCE_OR_AUTHORITY_GAP', 'POLICY_GAP', 'REPLAY_DIVERGENCE',     'OVERCLAIM_OR_UI_MISREPRESENTATION', 'REMEDIATION_REQUIRED', 'RELEASE_BLOCKING_CONDITION', 'OTHER')),
  CHECK (finding_status IN (     'OPEN', 'ACKNOWLEDGED', 'CONTAINED', 'REMEDIATION_PROPOSED', 'VERIFICATION_PENDING',     'RESOLVED_WITH_EVIDENCE', 'ACCEPTED_RISK', 'INVALIDATED', 'SUPERSEDED')),
  CHECK (severity IN ('S0', 'S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (confidence_classification IN ('KNOWN', 'INFERRED', 'MISSING', 'DISPUTED')),
  CHECK (current_reliance_consequence IN (     'NONE', 'QUALIFICATION_REQUIRED', 'HOLD', 'BLOCK', 'QUARANTINE', 'INCIDENT_EVALUATION_REQUIRED', 'RELEASE_BLOCK_REQUIRED')),
  CHECK (incident_evaluation_required IN (0, 1)),
  CHECK (decision_ledger_record_required IN (0, 1)),
  CONSTRAINT fk_audit_findings_audit_run_id FOREIGN KEY (audit_run_id) REFERENCES audit_runs (audit_run_id),
  CONSTRAINT fk_audit_findings_audit_check_record_id FOREIGN KEY (audit_check_record_id) REFERENCES audit_check_records (audit_check_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_audit_findings_run ON audit_findings (audit_run_id, severity, finding_status, created_at);

DROP TRIGGER IF EXISTS trg_audit_findings_no_update;
DELIMITER $$
CREATE TRIGGER trg_audit_findings_no_update
  BEFORE UPDATE ON audit_findings
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_findings is append-only and immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_audit_findings_no_delete;
DELIMITER $$
CREATE TRIGGER trg_audit_findings_no_delete
  BEFORE DELETE ON audit_findings
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_findings cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS console_command_intents (
  console_command_intent_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  console_command_surface_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  actor_type VARCHAR(32) NOT NULL,
  actor_id CHAR(36) NOT NULL,
  requested_permission VARCHAR(255) NOT NULL,
  resource_type VARCHAR(255) NOT NULL,
  resource_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  access_evaluation_id CHAR(36),
  idempotency_key VARCHAR(191),
  correlation_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_command_intent_id),
  CHECK (actor_type IN ('OPERATOR', 'SERVICE', 'SYSTEM')),
  CHECK (status IN (     'CREATED', 'ROUTED_TO_OWNER', 'OWNER_ACCEPTED', 'OWNER_REJECTED', 'OWNER_HELD', 'FAILED_TO_ROUTE', 'CANCELLED')),
  CONSTRAINT fk_console_command_intents_console_command_surface_id FOREIGN KEY (console_command_surface_id) REFERENCES console_command_surface_definitions (console_command_surface_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_console_command_intents_scope ON console_command_intents (tenant_id, workspace_id, status, created_at);

DROP TRIGGER IF EXISTS trg_console_command_intents_no_update;
DELIMITER $$
CREATE TRIGGER trg_console_command_intents_no_update
  BEFORE UPDATE ON console_command_intents
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_command_intents is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_console_command_intents_no_delete;
DELIMITER $$
CREATE TRIGGER trg_console_command_intents_no_delete
  BEFORE DELETE ON console_command_intents
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_command_intents is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS console_command_result_references (
  console_command_result_reference_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  console_command_intent_id CHAR(36) NOT NULL,
  owner_module_id CHAR(36) NOT NULL,
  owner_decision VARCHAR(32) NOT NULL,
  owner_object_ref VARCHAR(255),
  reason_codes TEXT NOT NULL DEFAULT '[]',
  safe_summary TEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (console_command_result_reference_id),
  CHECK (owner_decision IN ('ROUTED', 'ACCEPTED', 'DENIED', 'HELD', 'FAILED', 'CANCELLED')),
  CONSTRAINT fk_console_command_result_references_console_command_intent_id FOREIGN KEY (console_command_intent_id) REFERENCES console_command_intents (console_command_intent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_console_command_results_intent ON console_command_result_references (console_command_intent_id);

DROP TRIGGER IF EXISTS trg_console_command_results_no_update;
DELIMITER $$
CREATE TRIGGER trg_console_command_results_no_update
  BEFORE UPDATE ON console_command_result_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_command_result_references is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_console_command_results_no_delete;
DELIMITER $$
CREATE TRIGGER trg_console_command_results_no_delete
  BEFORE DELETE ON console_command_result_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'console_command_result_references is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS decision_input_references (
  decision_input_reference_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  owner_module_id VARCHAR(32) NOT NULL,
  input_object_type VARCHAR(255) NOT NULL,
  input_object_id CHAR(36) NOT NULL,
  input_object_version_or_digest CHAR(64),
  reference_role VARCHAR(32) NOT NULL,
  verification_status_at_record_time VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (decision_input_reference_id),
  CHECK (owner_module_id IN (     'M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11', 'M12')),
  CHECK (reference_role IN (     'AUTHORITY_BASIS', 'MISSION_BASIS', 'PROPOSAL_BASIS', 'EVIDENCE_BASIS', 'REVIEW_BASIS', 'VERDICT_BASIS',     'APPROVAL_BASIS', 'ARTIFACT_BASIS', 'AUTHORIZATION_BASIS', 'TOOL_DECISION_BASIS', 'DISPATCH_BASIS',     'OUTCOME_BASIS', 'CONDITION_SATISFACTION', 'INCIDENT_OR_AUDIT_BASIS', 'DISPLAY_OR_EXPORT_REFERENCE', 'OTHER')),
  CHECK (verification_status_at_record_time IN (     'VERIFIED_REFERENCE', 'PRESENT_UNVERIFIED', 'REQUIRED_BUT_MISSING', 'RESTRICTED_REFERENCE')),
  CONSTRAINT fk_decision_input_references_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_decision_input_references_record ON decision_input_references (decision_record_id, reference_role);

-- PARTIAL INDEX (MariaDB has no partial index): ux_decision_input_references_action_ack
--   canonical: CREATE UNIQUE INDEX ... ON decision_input_references (owner_module_id, input_object_type, input_object_id)
--   predicate: WHERE owner_module_id = 'M09' AND input_object_type = 'action_execution_acknowledgement'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_decision_input_references_action_ack_np ON decision_input_references (owner_module_id, input_object_type, input_object_id);

DROP TRIGGER IF EXISTS trg_decision_input_references_no_update;
DELIMITER $$
CREATE TRIGGER trg_decision_input_references_no_update
  BEFORE UPDATE ON decision_input_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_input_references is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_decision_input_references_no_delete;
DELIMITER $$
CREATE TRIGGER trg_decision_input_references_no_delete
  BEFORE DELETE ON decision_input_references
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_input_references is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS hmwh_review_sessions (
  review_session_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  evidence_bundle_id CHAR(36),
  proposal_submission_digest CHAR(64) NOT NULL,
  evidence_bundle_hash CHAR(64),
  review_mode VARCHAR(32) NOT NULL,
  review_purpose VARCHAR(48) NOT NULL,
  status VARCHAR(32) NOT NULL,
  initiated_by_actor_type VARCHAR(32) NOT NULL,
  initiated_by_actor_id CHAR(36) NOT NULL,
  initiated_at CHAR(24) NOT NULL,
  closed_at CHAR(24),
  PRIMARY KEY (review_session_id),
  CHECK (review_mode IN ('MULTI_REVIEWER_DEMO', 'SINGLE_REVIEWER_FIXTURE')),
  CHECK (review_purpose IN ('INTERNAL_BRIEFING_REVIEW', 'CONTROLLED_DEMO_ACTION_REVIEW', 'REPLAY_TEST_REVIEW', 'COMPETITION_RELEVANCE_REVIEW', 'FIT_HYPOTHESIS_UNCERTAINTY_REVIEW', 'DISTRIBUTION_SENSITIVITY_REVIEW', 'OUTREACH_COMPLIANCE_REVIEW', 'TWIN_STATE_REVIEW', 'RELATIONSHIP_STATE_REVIEW', 'MARKETING_ASSET_REVIEW', 'SOURCE_CONFIG_REVIEW')),
  CHECK (status IN ('DRAFT', 'OPEN', 'AWAITING_ASSESSMENTS', 'READY_FOR_AGGREGATION', 'AGGREGATED', 'SUBMITTED_TO_ARBITER', 'INCOMPLETE', 'BLOCKED', 'INVALIDATED', 'CANCELLED', 'SUPERSEDED')),
  CHECK (initiated_by_actor_type IN ('OPERATOR', 'SERVICE', 'SYSTEM')),
  CONSTRAINT fk_hmwh_review_sessions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_hmwh_review_sessions_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_hmwh_review_sessions_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_hmwh_review_sessions_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_review_sessions_proposal ON hmwh_review_sessions (proposal_id, status);

CREATE TABLE IF NOT EXISTS incident_cases (
  incident_case_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  incident_evaluation_request_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  incident_class VARCHAR(32) NOT NULL,
  severity VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  current_release_or_operation_consequence VARCHAR(32) NOT NULL DEFAULT 'NONE',
  safe_summary TEXT NOT NULL,
  verified_resolution TINYINT(1) NOT NULL DEFAULT 0,
  created_at CHAR(24) NOT NULL,
  resolved_at CHAR(24),
  PRIMARY KEY (incident_case_id),
  CHECK (incident_class IN (     'INTEGRITY', 'AUTHORITY_BYPASS', 'SCOPE_OR_TENANT_BREACH', 'FORBIDDEN_CAPABILITY_ATTEMPT',     'PROVENANCE_OR_IP', 'UNKNOWN_OR_UNEXPECTED_EFFECT', 'LEDGER_INTEGRITY', 'DISCLOSURE_OR_OVERCLAIM', 'OTHER')),
  CHECK (severity IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (status IN (     'OPEN', 'TRIAGE', 'CONTAINMENT_RECOMMENDED', 'CONTAINMENT_ACKNOWLEDGED', 'INVESTIGATING',     'REMEDIATION_PLANNED', 'VERIFICATION_PENDING', 'RESOLVED', 'CLOSED_AS_NON_INCIDENT', 'ACCEPTED_RISK', 'ESCALATED')),
  CHECK (current_release_or_operation_consequence IN (     'NONE', 'QUALIFY', 'HOLD', 'BLOCK', 'QUARANTINE', 'RELEASE_BLOCK')),
  CHECK (verified_resolution IN (0, 1)),
  CONSTRAINT fk_incident_cases_incident_evaluation_request_id FOREIGN KEY (incident_evaluation_request_id) REFERENCES incident_evaluation_requests (incident_evaluation_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_incident_cases_request ON incident_cases (incident_evaluation_request_id);

CREATE INDEX ix_incident_cases_scope ON incident_cases (tenant_id, workspace_id, status, severity);

CREATE TABLE IF NOT EXISTS incident_timeline_entries (
  incident_timeline_entry_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  incident_case_id CHAR(36) NOT NULL,
  entry_type VARCHAR(32) NOT NULL,
  safe_detail_summary TEXT NOT NULL,
  related_object_ref VARCHAR(255),
  recorded_by_actor_type VARCHAR(32) NOT NULL,
  recorded_by_actor_id CHAR(36),
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (incident_timeline_entry_id),
  CHECK (entry_type IN (     'OPENED', 'TRIAGE', 'CONTAINMENT_RECOMMENDED', 'CONTAINMENT_ACKNOWLEDGED', 'INVESTIGATION_NOTE',     'FINDING_LINKED', 'REPLAY_LINKED', 'REMEDIATION_NOTE', 'RESOLVED', 'CLOSED', 'ESCALATED')),
  CHECK (recorded_by_actor_type IN ('OPERATOR', 'SERVICE', 'SYSTEM')),
  CONSTRAINT fk_incident_timeline_entries_incident_case_id FOREIGN KEY (incident_case_id) REFERENCES incident_cases (incident_case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_incident_timeline_case ON incident_timeline_entries (incident_case_id, occurred_at);

DROP TRIGGER IF EXISTS trg_incident_timeline_no_update;
DELIMITER $$
CREATE TRIGGER trg_incident_timeline_no_update
  BEFORE UPDATE ON incident_timeline_entries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'incident_timeline_entries is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_incident_timeline_no_delete;
DELIMITER $$
CREATE TRIGGER trg_incident_timeline_no_delete
  BEFORE DELETE ON incident_timeline_entries
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'incident_timeline_entries is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS mission_assignments (
  assignment_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  capability_contract_version_id CHAR(36) NOT NULL,
  assignment_role VARCHAR(255) NOT NULL,
  assigned_action_classes TEXT NOT NULL DEFAULT '[]',
  assigned_tool_ids TEXT NOT NULL DEFAULT '[]',
  assigned_output_classes TEXT NOT NULL DEFAULT '[]',
  compatibility_evaluation_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  assigned_by_operator_id CHAR(36) NOT NULL,
  assigned_at CHAR(24) NOT NULL,
  valid_until CHAR(24),
  PRIMARY KEY (assignment_id),
  CHECK (status IN ('PROPOSED', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'COMPLETED', 'EXPIRED')),
  CONSTRAINT fk_mission_assignments_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_mission_assignments_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_mission_assignments_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_mission_assignments_lookup ON mission_assignments (mission_id, valkyrie_id, status);

CREATE TABLE IF NOT EXISTS mission_review_paths (
  review_path_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  mission_version INT NOT NULL,
  required_for_output_class_json TEXT NOT NULL DEFAULT '{}',
  hmwh_requirement VARCHAR(32) NOT NULL,
  arbiter_required TINYINT(1) NOT NULL DEFAULT 1,
  approval_requirement VARCHAR(32) NOT NULL,
  frozen_artifact_required INT NOT NULL DEFAULT 0,
  decision_ledger_required_for_material TINYINT(1) NOT NULL DEFAULT 1,
  fail_closed_if_missing TINYINT(1) NOT NULL DEFAULT 1,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (review_path_id),
  CHECK (hmwh_requirement IN ('NOT_REQUIRED', 'REQUIRED', 'CONDITIONAL')),
  CHECK (arbiter_required = 1),
  CHECK (approval_requirement IN ('NOT_APPLICABLE', 'CONDITIONAL', 'REQUIRED_FOR_DEMO_ACTION')),
  CHECK (decision_ledger_required_for_material = 1),
  CHECK (fail_closed_if_missing = 1),
  CONSTRAINT fk_mission_review_paths_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mission_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  from_status VARCHAR(255) NOT NULL,
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  initiated_by_operator_id CHAR(36),
  occurred_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_mission_status_transitions_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_mission_status_transitions_mission ON mission_status_transitions (mission_id, occurred_at);

DROP TRIGGER IF EXISTS trg_mission_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_mission_status_transitions_no_update
  BEFORE UPDATE ON mission_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mission_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_mission_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_mission_status_transitions_no_delete
  BEFORE DELETE ON mission_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mission_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS replay_cases (
  replay_case_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  replay_profile_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  replay_purpose VARCHAR(32) NOT NULL,
  original_decision_record_ids TEXT NOT NULL DEFAULT '[]',
  original_object_refs TEXT NOT NULL DEFAULT '[]',
  side_effect_mode VARCHAR(255) NOT NULL DEFAULT 'NO_SIDE_EFFECT_TEST_ONLY',
  tool_and_action_dispatch_prohibited TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (replay_case_id),
  CHECK (replay_purpose IN (     'REGRESSION_TEST', 'POLICY_COMPARISON', 'INCIDENT_ANALYSIS', 'AUDIT_VERIFICATION', 'RELEASE_READINESS_TEST')),
  CHECK (side_effect_mode = 'NO_SIDE_EFFECT_TEST_ONLY'),
  CHECK (tool_and_action_dispatch_prohibited = 1),
  CHECK (status IN (     'DRAFT', 'VALIDATING', 'READY', 'RUNNING', 'COMPLETED', 'FAILED', 'INVALIDATED', 'CANCELLED')),
  CONSTRAINT fk_replay_cases_replay_profile_id FOREIGN KEY (replay_profile_id) REFERENCES replay_profiles (replay_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_replay_cases_profile ON replay_cases (replay_profile_id, status);

CREATE TABLE IF NOT EXISTS replay_results (
  replay_result_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  replay_case_id CHAR(36) NOT NULL,
  replay_outcome_classification VARCHAR(48) NOT NULL,
  regression_passed TINYINT(1) NOT NULL DEFAULT 0,
  identified_differences TEXT NOT NULL DEFAULT '[]',
  original_outcome_summary TEXT NOT NULL,
  candidate_outcome_summary TEXT NOT NULL,
  result_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  not_original_decision TINYINT(1) NOT NULL DEFAULT 1,
  not_execution_authority TINYINT(1) NOT NULL DEFAULT 1,
  decision_record_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (replay_result_id),
  CHECK (replay_outcome_classification IN (     'SAME_ROUTE', 'MORE_RESTRICTIVE_ROUTE', 'LESS_RESTRICTIVE_ROUTE_CANDIDATE_ONLY', 'DIFFERENT_CONDITIONS',     'FAILURE_DISCOVERED', 'INCONCLUSIVE')),
  CHECK (regression_passed IN (0, 1)),
  CHECK (not_original_decision = 1),
  CHECK (not_execution_authority = 1),
  CONSTRAINT fk_replay_results_replay_case_id FOREIGN KEY (replay_case_id) REFERENCES replay_cases (replay_case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_replay_results_case ON replay_results (replay_case_id, created_at);

DROP TRIGGER IF EXISTS trg_replay_results_no_update;
DELIMITER $$
CREATE TRIGGER trg_replay_results_no_update
  BEFORE UPDATE ON replay_results
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'replay_results is immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_replay_results_no_delete;
DELIMITER $$
CREATE TRIGGER trg_replay_results_no_delete
  BEFORE DELETE ON replay_results
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'replay_results cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS runtime_adapter_instances (
  runtime_adapter_instance_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  runtime_host_instance_id CHAR(36) NOT NULL,
  adapter_family VARCHAR(255) NOT NULL,
  adapter_name VARCHAR(255) NOT NULL,
  adapter_version VARCHAR(32) NOT NULL,
  descriptor_identity VARCHAR(255) NOT NULL,
  capability_manifest_identity VARCHAR(255) NOT NULL,
  conformance_profile_identity VARCHAR(255) NOT NULL,
  identity_digest CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  retired_at CHAR(24),
  PRIMARY KEY (runtime_adapter_instance_id),
  CHECK (length(adapter_family) > 0),
  CHECK (length(adapter_name) > 0),
  CHECK (length(adapter_version) > 0),
  CHECK (length(descriptor_identity) > 0),
  CHECK (length(capability_manifest_identity) > 0),
  CHECK (length(conformance_profile_identity) > 0),
  CHECK (length(identity_digest) > 0),
  CHECK (status IN ('REGISTERED', 'READY', 'SUSPENDED', 'RETIRED')),
  CHECK ((status = 'RETIRED') = (retired_at IS NOT NULL)),
  UNIQUE (runtime_host_instance_id, adapter_family, adapter_name, adapter_version),
  UNIQUE (identity_digest),
  CONSTRAINT fk_runtime_adapter_instances_runtime_host_instance_id FOREIGN KEY (runtime_host_instance_id) REFERENCES runtime_host_instances (runtime_host_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runtime_host_bindings (
  binding_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  runtime_host_instance_id CHAR(36) NOT NULL,
  runtime_adapter_instance_id CHAR(36) NOT NULL,
  binding_type VARCHAR(32) NOT NULL,
  allfather_object_type VARCHAR(255),
  allfather_object_id CHAR(36),
  runtime_host_reference VARCHAR(255) NOT NULL,
  runtime_host_version VARCHAR(32) NOT NULL,
  mapping_purpose VARCHAR(255) NOT NULL,
  permitted_operation_classes TEXT NOT NULL DEFAULT '[]',
  safety_classification VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  supersedes_binding_id CHAR(36),
  tenant_id CHAR(36),
  workspace_id CHAR(36),
  activation_evidence_refs TEXT NOT NULL DEFAULT '[]',
  activated_at CHAR(24),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (binding_id),
  CHECK (binding_type IN ('HOST_INSTANCE', 'SESSION_SURFACE', 'TOOL_SURFACE', 'EVENT_SURFACE', 'HEALTH_SURFACE', 'UI_TRANSPORT_SURFACE', 'DEMO_ADAPTER_SURFACE')),
  CHECK (safety_classification IN ('SAFE_READ_ONLY', 'SAFE_INTERNAL_TRANSFORM', 'HARMLESS_DEMO_ACTION', 'UNSAFE_BLOCKED')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'DEGRADED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED', 'UNTRUSTED')),
  CONSTRAINT fk_runtime_host_bindings_runtime_host_instance_id FOREIGN KEY (runtime_host_instance_id) REFERENCES runtime_host_instances (runtime_host_instance_id),
  CONSTRAINT fk_runtime_host_bindings_runtime_adapter_instance_id FOREIGN KEY (runtime_adapter_instance_id) REFERENCES runtime_adapter_instances (runtime_adapter_instance_id),
  CONSTRAINT fk_runtime_host_bindings_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_runtime_host_bindings_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_runtime_host_bindings_lookup ON runtime_host_bindings (runtime_adapter_instance_id, binding_type, status);

CREATE TABLE IF NOT EXISTS runtime_host_tool_refs (
  tool_ref_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  binding_id CHAR(36) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  runtime_host_tool_ref VARCHAR(255) NOT NULL,
  tool_surface_type VARCHAR(255) NOT NULL,
  declared_operation_class VARCHAR(32) NOT NULL,
  mapping_status VARCHAR(32) NOT NULL,
  version VARCHAR(255) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_ref_id),
  CHECK (declared_operation_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (mapping_status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_runtime_host_tool_refs_binding_id FOREIGN KEY (binding_id) REFERENCES runtime_host_bindings (binding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_runtime_host_tool_refs_governed ON runtime_host_tool_refs (governed_tool_id, mapping_status);

CREATE TABLE IF NOT EXISTS valkyries (
  valkyrie_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  display_name VARCHAR(255) NOT NULL,
  canonical_role_class VARCHAR(48) NOT NULL,
  purpose_summary TEXT NOT NULL,
  status VARCHAR(32) NOT NULL,
  active_capability_contract_id CHAR(36),
  active_runtime_binding_id CHAR(36),
  created_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  last_authority_review_at CHAR(24),
  PRIMARY KEY (valkyrie_id),
  CHECK (canonical_role_class IN ('RESEARCH_SOURCE_VALKYRIE', 'REVIEW_CRITIC_VALKYRIE', 'DEMO_ACTION_PREPARER_VALKYRIE', 'REPLAY_TEST_VALKYRIE', 'COMPETITION_MONITOR_VALKYRIE', 'LEAD_QUALIFIER_VALKYRIE', 'INTELLIGENCE_DISTRIBUTOR_VALKYRIE', 'CRM_EXECUTION_VALKYRIE', 'ENTITY_TWIN_CURATOR_VALKYRIE', 'MARKETING_CUSTOMIZER_VALKYRIE')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'RETIRED')),
  CONSTRAINT fk_valkyries_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_valkyries_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_valkyries_active_runtime_binding_id FOREIGN KEY (active_runtime_binding_id) REFERENCES runtime_host_bindings (binding_id),
  CONSTRAINT fk_valkyries_created_by_operator_id FOREIGN KEY (created_by_operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_valkyries_scope_status_role ON valkyries (tenant_id, status, canonical_role_class);

CREATE TABLE IF NOT EXISTS arbiter_check_records (
  arbiter_check_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  arbiter_evaluation_run_id CHAR(36) NOT NULL,
  rule_category VARCHAR(255) NOT NULL,
  check_type VARCHAR(255) NOT NULL,
  decision VARCHAR(32) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  safe_assessment_summary TEXT,
  severity_if_failed VARCHAR(255),
  incident_evaluation_required INT NOT NULL DEFAULT 0,
  evaluated_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_check_id),
  CHECK (decision IN ('PASS', 'FAIL', 'HOLD', 'NOT_APPLICABLE')),
  CHECK (severity_if_failed IS NULL OR severity_if_failed IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CONSTRAINT fk_arbiter_check_records_arbiter_evaluation_run_id FOREIGN KEY (arbiter_evaluation_run_id) REFERENCES arbiter_evaluation_runs (arbiter_evaluation_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_check_records_run ON arbiter_check_records (arbiter_evaluation_run_id, evaluated_at);

DROP TRIGGER IF EXISTS trg_arbiter_check_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_arbiter_check_records_no_update
  BEFORE UPDATE ON arbiter_check_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_check_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_arbiter_check_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_arbiter_check_records_no_delete
  BEFORE DELETE ON arbiter_check_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arbiter_check_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS arbiter_conditions (
  arbiter_condition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  arbiter_evaluation_run_id CHAR(36) NOT NULL,
  arbiter_verdict_id CHAR(36),
  condition_type VARCHAR(32) NOT NULL,
  condition_summary TEXT NOT NULL,
  satisfaction_owner_module_id VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  satisfaction_reference_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (arbiter_condition_id),
  CHECK (condition_type IN ('VISIBILITY_QUALIFICATION', 'UNCERTAINTY_DISCLOSURE', 'SCOPE_LIMITATION', 'ADDITIONAL_EVIDENCE_REQUIRED', 'REVISION_REQUIRED', 'HUMAN_APPROVAL_REQUIRED', 'FROZEN_ARTIFACT_REQUIRED', 'DECISION_LEDGER_REQUIRED', 'INCIDENT_EVALUATION_REQUIRED', 'QUARANTINE_REQUIRED', 'OTHER')),
  CHECK (satisfaction_owner_module_id IN ('M04', 'M05', 'M09', 'M10', 'M11', 'M12')),
  CHECK (status IN ('OPEN', 'SATISFIED_BY_REFERENCE', 'UNSATISFIED', 'SUPERSEDED', 'FAILED')),
  CONSTRAINT fk_arbiter_conditions_arbiter_evaluation_run_id FOREIGN KEY (arbiter_evaluation_run_id) REFERENCES arbiter_evaluation_runs (arbiter_evaluation_run_id),
  CONSTRAINT fk_arbiter_conditions_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_arbiter_conditions_verdict ON arbiter_conditions (arbiter_verdict_id, status);

CREATE TABLE IF NOT EXISTS capability_contracts (
  capability_contract_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  valkyrie_id CHAR(36) NOT NULL,
  contract_version INT NOT NULL,
  role_class VARCHAR(48) NOT NULL,
  purpose_summary TEXT NOT NULL,
  allowed_action_classes TEXT NOT NULL DEFAULT '[]',
  maximum_autonomy_statement VARCHAR(255) NOT NULL,
  evidence_obligation_summary TEXT NOT NULL,
  escalation_obligation_summary TEXT NOT NULL,
  human_approval_or_execution_authority TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  valid_from CHAR(24) NOT NULL,
  valid_until CHAR(24),
  activated_by_operator_id CHAR(36),
  supersedes_contract_id CHAR(36),
  revoked_by_operator_id CHAR(36),
  revocation_reason TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (capability_contract_id),
  CHECK (role_class IN ('RESEARCH_SOURCE_VALKYRIE', 'REVIEW_CRITIC_VALKYRIE', 'DEMO_ACTION_PREPARER_VALKYRIE', 'REPLAY_TEST_VALKYRIE', 'COMPETITION_MONITOR_VALKYRIE', 'LEAD_QUALIFIER_VALKYRIE', 'INTELLIGENCE_DISTRIBUTOR_VALKYRIE', 'CRM_EXECUTION_VALKYRIE', 'ENTITY_TWIN_CURATOR_VALKYRIE', 'MARKETING_CUSTOMIZER_VALKYRIE')),
  CHECK (human_approval_or_execution_authority = 0),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED', 'EXPIRED')),
  CONSTRAINT fk_capability_contracts_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_capability_contracts_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_capability_contracts_valkyrie_id FOREIGN KEY (valkyrie_id) REFERENCES valkyries (valkyrie_id),
  CONSTRAINT fk_capability_contracts_activated_by_operator_id FOREIGN KEY (activated_by_operator_id) REFERENCES operators (operator_id),
  CONSTRAINT fk_capability_contracts_revoked_by_operator_id FOREIGN KEY (revoked_by_operator_id) REFERENCES operators (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_capability_contracts_version ON capability_contracts (valkyrie_id, contract_version);

-- PARTIAL INDEX (MariaDB has no partial index): ux_capability_contracts_one_active_per_valkyrie
--   canonical: CREATE UNIQUE INDEX ... ON capability_contracts (valkyrie_id)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_capability_contracts_one_active_per_valkyrie_np ON capability_contracts (valkyrie_id);

CREATE INDEX ix_capability_contracts_validity ON capability_contracts (valid_from, valid_until);

DROP TRIGGER IF EXISTS trg_capability_contracts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_capability_contracts_no_delete
  BEFORE DELETE ON capability_contracts
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.status <> 'DRAFT'
  IF OLD.status <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'capability_contracts cannot be deleted once activated';
  END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS containment_recommendations (
  containment_recommendation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  incident_case_id CHAR(36) NOT NULL,
  containment_class VARCHAR(48) NOT NULL,
  urgency VARCHAR(32) NOT NULL,
  owner_module_id VARCHAR(32) NOT NULL,
  requires_owner_acknowledgement TINYINT(1) NOT NULL DEFAULT 1,
  is_not_self_executed TINYINT(1) NOT NULL DEFAULT 1,
  safe_recommendation_summary TEXT NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (containment_recommendation_id),
  CHECK (containment_class IN (     'SUSPEND_TOOL_OPERATION', 'BLOCK_ACTION_PATH', 'INVALIDATE_PENDING_VERDICT_RELIANCE',     'QUARANTINE_EVIDENCE_OR_PROVENANCE_PATH', 'SUSPEND_VALKYRIE_OR_CONTRACT', 'BLOCK_RELEASE_OR_CLAIM',     'RESTRICT_EXPORT_OR_VISIBILITY', 'REQUIRE_MANUAL_REVIEW')),
  CHECK (urgency IN ('ROUTINE', 'URGENT', 'IMMEDIATE_FAIL_CLOSED')),
  CHECK (owner_module_id IN (     'M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11', 'M12')),
  CHECK (requires_owner_acknowledgement = 1),
  CHECK (is_not_self_executed = 1),
  CONSTRAINT fk_containment_recommendations_incident_case_id FOREIGN KEY (incident_case_id) REFERENCES incident_cases (incident_case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_containment_recs_case ON containment_recommendations (incident_case_id, urgency);

DROP TRIGGER IF EXISTS trg_containment_recs_no_update;
DELIMITER $$
CREATE TRIGGER trg_containment_recs_no_update
  BEFORE UPDATE ON containment_recommendations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'containment_recommendations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_containment_recs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_containment_recs_no_delete
  BEFORE DELETE ON containment_recommendations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'containment_recommendations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS forbidden_action_clauses (
  forbidden_action_clause_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  forbidden_category VARCHAR(255) NOT NULL,
  description VARCHAR(255) NOT NULL,
  severity_if_attempted VARCHAR(32) NOT NULL,
  incident_evaluation_required INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (forbidden_action_clause_id),
  CHECK (severity_if_attempted IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (status IN ('ACTIVE', 'SUPERSEDED', 'REVOKED')),
  CONSTRAINT fk_forbidden_action_clauses_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_forbidden_action_clauses_contract ON forbidden_action_clauses (capability_contract_id);

CREATE TABLE IF NOT EXISTS governed_tools (
  governed_tool_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_key VARCHAR(191) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  purpose_summary TEXT NOT NULL,
  tool_class VARCHAR(32) NOT NULL,
  risk_level VARCHAR(32) NOT NULL,
  maximum_action_class VARCHAR(32) NOT NULL,
  runtime_mapping_ref_id CHAR(36),
  evidence_capture_required_by_default INT NOT NULL DEFAULT 0,
  approval_required INT NOT NULL DEFAULT 0,
  frozen_artifact_required INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  descriptor_version VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (governed_tool_id),
  CHECK (tool_class IN ('READ_ONLY_SOURCE', 'INTERNAL_TRANSFORM', 'DEMO_SIDE_EFFECT', 'ADMIN_CONTROLLED', 'FORBIDDEN_IN_V1')),
  CHECK (risk_level IN ('T0', 'T1', 'T2', 'T3', 'T4', 'T5')),
  CHECK (maximum_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION', 'FORBIDDEN')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'DEGRADED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED', 'FORBIDDEN')),
  CONSTRAINT fk_governed_tools_runtime_mapping_ref_id FOREIGN KEY (runtime_mapping_ref_id) REFERENCES runtime_host_bindings (binding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_governed_tools_key_version ON governed_tools (tool_key, descriptor_version);

CREATE TABLE IF NOT EXISTS hmwh_dissent_records (
  dissent_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  result_id CHAR(36),
  dissenting_assessment_ids TEXT NOT NULL DEFAULT '[]',
  dissent_type VARCHAR(32) NOT NULL,
  dissent_summary TEXT NOT NULL,
  material_to_arbiter_evaluation INT NOT NULL DEFAULT 0,
  preserved_for_routing TINYINT(1) NOT NULL DEFAULT 1,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (dissent_record_id),
  CHECK (dissent_type IN ('MINORITY_RECOMMENDATION', 'MATERIAL_EVIDENCE_DISAGREEMENT', 'RISK_VETO_DISAGREEMENT', 'SCOPE_OR_POLICY_DISAGREEMENT', 'CONFIDENCE_DISAGREEMENT', 'OTHER')),
  CHECK (preserved_for_routing = 1),
  CONSTRAINT fk_hmwh_dissent_records_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_dissent_records_session ON hmwh_dissent_records (review_session_id, material_to_arbiter_evaluation);

DROP TRIGGER IF EXISTS trg_hmwh_dissent_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_hmwh_dissent_records_no_update
  BEFORE UPDATE ON hmwh_dissent_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_dissent_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_hmwh_dissent_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_hmwh_dissent_records_no_delete
  BEFORE DELETE ON hmwh_dissent_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_dissent_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS hmwh_eligibility_checks (
  eligibility_check_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  proposed_review_role VARCHAR(255) NOT NULL,
  m03_eligibility_result VARCHAR(32) NOT NULL,
  m03_rejection_reason_code VARCHAR(255),
  conflict_or_separation_check VARCHAR(32) NOT NULL,
  assessed_at CHAR(24) NOT NULL,
  PRIMARY KEY (eligibility_check_id),
  CHECK (m03_eligibility_result IN ('ELIGIBLE', 'INELIGIBLE', 'CONDITIONAL')),
  CHECK (conflict_or_separation_check IN ('CLEARED', 'CONFLICT_DETECTED', 'SELF_REVIEW_ONLY')),
  CONSTRAINT fk_hmwh_eligibility_checks_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_eligibility_checks_session ON hmwh_eligibility_checks (review_session_id, assessed_at);

DROP TRIGGER IF EXISTS trg_hmwh_eligibility_checks_no_update;
DELIMITER $$
CREATE TRIGGER trg_hmwh_eligibility_checks_no_update
  BEFORE UPDATE ON hmwh_eligibility_checks
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_eligibility_checks is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_hmwh_eligibility_checks_no_delete;
DELIMITER $$
CREATE TRIGGER trg_hmwh_eligibility_checks_no_delete
  BEFORE DELETE ON hmwh_eligibility_checks
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_eligibility_checks is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS hmwh_participant_assignments (
  participant_assignment_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  capability_contract_id CHAR(36),
  review_role VARCHAR(32) NOT NULL,
  independence_classification VARCHAR(32) NOT NULL,
  eligibility_check_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  assigned_at CHAR(24) NOT NULL,
  PRIMARY KEY (participant_assignment_id),
  CHECK (review_role IN ('EVIDENCE_CRITIC', 'SCOPE_AND_POLICY_CRITIC', 'RISK_CRITIC', 'GENERAL_REVIEWER', 'FIXTURE_REVIEWER')),
  CHECK (independence_classification IN ('INDEPENDENT_DECLARED_REVIEWER', 'NON_INDEPENDENT_SELF_CRITIQUE', 'TEST_FIXTURE_ONLY')),
  CHECK (status IN ('PROPOSED', 'ACTIVE', 'SUBMITTED', 'REVOKED', 'INELIGIBLE', 'EXPIRED', 'REMOVED')),
  CONSTRAINT fk_hmwh_participant_assignments_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id),
  CONSTRAINT fk_hmwh_participant_assignments_valkyrie_id FOREIGN KEY (valkyrie_id) REFERENCES valkyries (valkyrie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_participant_assignments_session ON hmwh_participant_assignments (review_session_id, status);

CREATE TABLE IF NOT EXISTS hmwh_results (
  hmwh_result_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  evidence_bundle_id CHAR(36),
  review_mode VARCHAR(32) NOT NULL,
  submitted_assessment_ids TEXT NOT NULL DEFAULT '[]',
  result_recommendation VARCHAR(48) NOT NULL,
  result_summary TEXT NOT NULL,
  confidence_summary TEXT NOT NULL,
  dissent_record_ids TEXT NOT NULL DEFAULT '[]',
  risk_veto_record_ids TEXT NOT NULL DEFAULT '[]',
  known_limitations TEXT NOT NULL DEFAULT '[]',
  validity_status VARCHAR(32) NOT NULL,
  not_approval_or_execution_authority TINYINT(1) NOT NULL DEFAULT 1,
  result_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (hmwh_result_id),
  CHECK (review_mode IN ('MULTI_REVIEWER_DEMO', 'SINGLE_REVIEWER_FIXTURE')),
  CHECK (result_recommendation IN ('PROCEED_TO_ARBITER', 'PROCEED_WITH_CONDITIONS', 'REQUIRE_MORE_EVIDENCE', 'HOLD', 'BLOCK', 'QUARANTINE_OR_INCIDENT_EVALUATION')),
  CHECK (validity_status IN ('VALID_FOR_ARBITER_INPUT', 'INCOMPLETE', 'INVALIDATED', 'TEST_FIXTURE_ONLY')),
  CHECK (not_approval_or_execution_authority = 1),
  CONSTRAINT fk_hmwh_results_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id),
  CONSTRAINT fk_hmwh_results_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_hmwh_results_session ON hmwh_results (review_session_id);

CREATE INDEX ix_hmwh_results_proposal ON hmwh_results (proposal_id, validity_status);

DROP TRIGGER IF EXISTS trg_hmwh_results_no_update;
DELIMITER $$
CREATE TRIGGER trg_hmwh_results_no_update
  BEFORE UPDATE ON hmwh_results
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_results is immutable once frozen';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_hmwh_results_no_delete;
DELIMITER $$
CREATE TRIGGER trg_hmwh_results_no_delete
  BEFORE DELETE ON hmwh_results
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_results cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS output_class_bindings (
  output_class_binding_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  output_class VARCHAR(48) NOT NULL,
  requested_action_class_maximum VARCHAR(32) NOT NULL,
  required_downstream_gates TEXT NOT NULL DEFAULT '[]',
  permitted_visibility_scope VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (output_class_binding_id),
  CHECK (output_class IN ('INTERNAL_RESEARCH_NOTE', 'EVIDENCE_BUNDLE_PROPOSAL', 'INTERNAL_BRIEFING_PROPOSAL', 'HMWH_REVIEW_ASSESSMENT', 'HARMLESS_DEMO_ACTION_PROPOSAL', 'REPLAY_TEST_ASSESSMENT', 'COMPANY_SIGNAL_BRIEF_PROPOSAL', 'BATTLECARD_BRIEF_PROPOSAL', 'FIT_HYPOTHESIS_PROPOSAL', 'PRIORITY_OUTREACH_QUEUE_ITEM_PROPOSAL', 'MONITORED_PROSPECT_PROPOSAL', 'PROSPECT_BATTLECARD_BRIEF_PROPOSAL', 'ROLE_DISTRIBUTION_BRIEFING_PROPOSAL', 'DISTRIBUTION_ROUTING_PROPOSAL', 'CRM_OUTREACH_EXECUTION_PROPOSAL', 'ENTITY_TWIN_STATE_CHANGE_PROPOSAL', 'ENTITY_RELATIONSHIP_STATE_CHANGE_PROPOSAL', 'MARKETING_ASSET_DRAFT_PROPOSAL')),
  CHECK (requested_action_class_maximum IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_output_class_bindings_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_output_class_bindings_contract ON output_class_bindings (capability_contract_id);

CREATE TABLE IF NOT EXISTS review_conditions (
  review_condition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  trigger_summary TEXT NOT NULL,
  required_review_path TEXT NOT NULL DEFAULT '[]',
  fail_closed_behavior VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (review_condition_id),
  CHECK (fail_closed_behavior IN ('DENY', 'HOLD', 'REQUIRE_REVIEW', 'QUARANTINE')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_review_conditions_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tool_rights (
  tool_right_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  allowed_operations TEXT NOT NULL DEFAULT '[]',
  tool_class VARCHAR(32) NOT NULL,
  maximum_action_class VARCHAR(32) NOT NULL,
  approval_required INT NOT NULL DEFAULT 0,
  frozen_artifact_required INT NOT NULL DEFAULT 0,
  evidence_capture_required INT NOT NULL DEFAULT 0,
  constraints_json TEXT,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_right_id),
  CHECK (tool_class IN ('READ_ONLY_SOURCE', 'INTERNAL_TRANSFORM', 'DEMO_SIDE_EFFECT')),
  CHECK (maximum_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_tool_rights_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_tool_rights_contract_tool ON tool_rights (capability_contract_id, governed_tool_id);

CREATE TABLE IF NOT EXISTS valkyrie_authority_evaluations (
  authority_evaluation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  capability_contract_id CHAR(36),
  contract_version INT,
  evaluation_purpose VARCHAR(32) NOT NULL,
  request_snapshot_json TEXT NOT NULL,
  decision VARCHAR(255) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  correlation_id CHAR(36),
  evaluated_at CHAR(24) NOT NULL,
  PRIMARY KEY (authority_evaluation_id),
  CHECK (evaluation_purpose IN ('MISSION_ASSIGNMENT', 'TOOL_RIGHT', 'HMWH_PARTICIPANT', 'PRODUCER_VALIDATION', 'AUTHORITY_AT_TIME')),
  CONSTRAINT fk_valkyrie_authority_evaluations_valkyrie_id FOREIGN KEY (valkyrie_id) REFERENCES valkyries (valkyrie_id),
  CONSTRAINT fk_valkyrie_authority_evaluations_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_authority_evaluations_lookup ON valkyrie_authority_evaluations (valkyrie_id, evaluation_purpose, evaluated_at);

DROP TRIGGER IF EXISTS trg_authority_evaluations_no_update;
DELIMITER $$
CREATE TRIGGER trg_authority_evaluations_no_update
  BEFORE UPDATE ON valkyrie_authority_evaluations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valkyrie_authority_evaluations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_authority_evaluations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_authority_evaluations_no_delete
  BEFORE DELETE ON valkyrie_authority_evaluations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valkyrie_authority_evaluations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS valkyrie_revocations (
  revocation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  valkyrie_id CHAR(36),
  capability_contract_id CHAR(36),
  action VARCHAR(32) NOT NULL,
  reason_code VARCHAR(255) NOT NULL,
  reason_summary TEXT,
  effective_at CHAR(24) NOT NULL,
  affected_mission_handling VARCHAR(255),
  incident_id CHAR(36),
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (revocation_id),
  CHECK (action IN ('SUSPEND', 'REVOKE', 'RETIRE', 'SUPERSEDE', 'EXPIRE')),
  CONSTRAINT fk_valkyrie_revocations_valkyrie_id FOREIGN KEY (valkyrie_id) REFERENCES valkyries (valkyrie_id),
  CONSTRAINT fk_valkyrie_revocations_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_valkyrie_revocations_lookup ON valkyrie_revocations (valkyrie_id, effective_at);

DROP TRIGGER IF EXISTS trg_valkyrie_revocations_no_update;
DELIMITER $$
CREATE TRIGGER trg_valkyrie_revocations_no_update
  BEFORE UPDATE ON valkyrie_revocations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valkyrie_revocations is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_valkyrie_revocations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_valkyrie_revocations_no_delete
  BEFORE DELETE ON valkyrie_revocations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'valkyrie_revocations is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS containment_acknowledgements (
  containment_acknowledgement_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  containment_recommendation_id CHAR(36) NOT NULL,
  owner_module_id CHAR(36) NOT NULL,
  owner_response VARCHAR(32) NOT NULL,
  response_summary TEXT NOT NULL,
  acknowledged_by_actor_id CHAR(36),
  acknowledged_at CHAR(24) NOT NULL,
  PRIMARY KEY (containment_acknowledgement_id),
  CHECK (owner_response IN (     'APPLIED', 'REJECTED_WITH_REASON', 'PARTIALLY_APPLIED', 'NOT_APPLICABLE', 'FAILED_TO_APPLY')),
  CONSTRAINT fk_containment_acknowledgements_containment_recommendation_id FOREIGN KEY (containment_recommendation_id) REFERENCES containment_recommendations (containment_recommendation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_containment_acks_rec ON containment_acknowledgements (containment_recommendation_id);

DROP TRIGGER IF EXISTS trg_containment_acks_no_update;
DELIMITER $$
CREATE TRIGGER trg_containment_acks_no_update
  BEFORE UPDATE ON containment_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'containment_acknowledgements is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_containment_acks_no_delete;
DELIMITER $$
CREATE TRIGGER trg_containment_acks_no_delete
  BEFORE DELETE ON containment_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'containment_acknowledgements is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS governed_tool_operations (
  governed_tool_operation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  operation_key VARCHAR(191) NOT NULL,
  operation_summary TEXT,
  operation_class VARCHAR(32) NOT NULL,
  classified_action_class VARCHAR(32) NOT NULL,
  requires_mission INT NOT NULL DEFAULT 1,
  requires_valkyrie_contract INT NOT NULL DEFAULT 1,
  requires_evidence_capture INT NOT NULL DEFAULT 0,
  requires_approval INT NOT NULL DEFAULT 0,
  requires_frozen_artifact INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (governed_tool_operation_id),
  CHECK (operation_class IN ('READ', 'TRANSFORM', 'DEMO_EFFECT', 'ADMIN', 'FORBIDDEN')),
  CHECK (classified_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION', 'FORBIDDEN')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'DEGRADED', 'SUSPENDED', 'REVOKED', 'FORBIDDEN')),
  CONSTRAINT fk_governed_tool_operations_governed_tool_id FOREIGN KEY (governed_tool_id) REFERENCES governed_tools (governed_tool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_governed_tool_operations_key ON governed_tool_operations (governed_tool_id, operation_key);

CREATE TABLE IF NOT EXISTS hmwh_assessments (
  assessment_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  participant_assignment_id CHAR(36) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  recommendation VARCHAR(32) NOT NULL,
  assessment_summary TEXT NOT NULL,
  evidence_refs_cited TEXT NOT NULL DEFAULT '[]',
  identified_risks TEXT NOT NULL DEFAULT '[]',
  confidence_band VARCHAR(32) NOT NULL,
  evidence_sufficiency_view VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  submission_digest CHAR(64) NOT NULL,
  submitted_at CHAR(24) NOT NULL,
  PRIMARY KEY (assessment_id),
  CHECK (recommendation IN ('SUPPORT_PROCEED_TO_ARBITER', 'SUPPORT_WITH_CONDITIONS', 'REQUIRE_MORE_EVIDENCE', 'HOLD', 'BLOCK', 'QUARANTINE_OR_INCIDENT_REVIEW')),
  CHECK (confidence_band IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (evidence_sufficiency_view IN ('SUFFICIENT_FOR_REVIEW', 'INCOMPLETE', 'CONFLICTED', 'STALE', 'QUARANTINED', 'UNKNOWN')),
  CHECK (status IN ('SUBMITTED', 'INVALIDATED', 'SUPERSEDED')),
  CONSTRAINT fk_hmwh_assessments_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id),
  CONSTRAINT fk_hmwh_assessments_participant_assignment_id FOREIGN KEY (participant_assignment_id) REFERENCES hmwh_participant_assignments (participant_assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_assessments_session ON hmwh_assessments (review_session_id, status);

DROP TRIGGER IF EXISTS trg_hmwh_assessments_no_update;
DELIMITER $$
CREATE TRIGGER trg_hmwh_assessments_no_update
  BEFORE UPDATE ON hmwh_assessments
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_assessments is immutable once submitted';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_hmwh_assessments_no_delete;
DELIMITER $$
CREATE TRIGGER trg_hmwh_assessments_no_delete
  BEFORE DELETE ON hmwh_assessments
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_assessments cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS hmwh_risk_veto_records (
  risk_veto_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  review_session_id CHAR(36) NOT NULL,
  assessment_id CHAR(36),
  veto_type VARCHAR(48) NOT NULL,
  risk_summary TEXT NOT NULL,
  recommended_routing VARCHAR(32) NOT NULL,
  mandatory_visibility_to_arbiter TINYINT(1) NOT NULL DEFAULT 1,
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (risk_veto_record_id),
  CHECK (veto_type IN ('EVIDENCE_INTEGRITY_CONCERN', 'SCOPE_VIOLATION_CONCERN', 'FORBIDDEN_ACTION_CONCERN', 'PRIVACY_OR_PROVENANCE_CONCERN', 'ARTIFACT_OR_EXECUTION_RISK_CONCERN', 'POLICY_GAP_CONCERN', 'OTHER')),
  CHECK (recommended_routing IN ('ARBITER_HOLD', 'ARBITER_BLOCK', 'ARBITER_QUARANTINE', 'INCIDENT_EVALUATION', 'REQUIRE_MORE_EVIDENCE')),
  CHECK (mandatory_visibility_to_arbiter = 1),
  CONSTRAINT fk_hmwh_risk_veto_records_review_session_id FOREIGN KEY (review_session_id) REFERENCES hmwh_review_sessions (review_session_id),
  CONSTRAINT fk_hmwh_risk_veto_records_assessment_id FOREIGN KEY (assessment_id) REFERENCES hmwh_assessments (assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_hmwh_risk_veto_records_session ON hmwh_risk_veto_records (review_session_id, recorded_at);

DROP TRIGGER IF EXISTS trg_hmwh_risk_veto_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_hmwh_risk_veto_records_no_update
  BEFORE UPDATE ON hmwh_risk_veto_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_risk_veto_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_hmwh_risk_veto_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_hmwh_risk_veto_records_no_delete
  BEFORE DELETE ON hmwh_risk_veto_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hmwh_risk_veto_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_requests (
  tool_request_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36),
  assignment_id CHAR(36),
  valkyrie_id CHAR(36),
  capability_contract_id CHAR(36),
  governed_tool_id CHAR(36) NOT NULL,
  governed_tool_operation_id CHAR(36) NOT NULL,
  requested_action_class VARCHAR(32) NOT NULL,
  normalized_input_ref_safe VARCHAR(255),
  normalized_input_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  justification_summary TEXT,
  idempotency_key VARCHAR(191) NOT NULL,
  correlation_id CHAR(36) NOT NULL,
  status VARCHAR(32) NOT NULL,
  requested_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_request_id),
  CHECK (requested_action_class IN ('OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE', 'PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION')),
  CHECK (status IN ('RECEIVED', 'EVALUATING', 'ALLOWED', 'DENIED', 'HELD', 'REQUIRE_APPROVAL', 'QUARANTINED', 'DISPATCHED', 'SUCCEEDED', 'FAILED', 'UNKNOWN', 'CANCELLED')),
  CONSTRAINT fk_tool_requests_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_tool_requests_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_tool_requests_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_tool_requests_assignment_id FOREIGN KEY (assignment_id) REFERENCES mission_assignments (assignment_id),
  CONSTRAINT fk_tool_requests_valkyrie_id FOREIGN KEY (valkyrie_id) REFERENCES valkyries (valkyrie_id),
  CONSTRAINT fk_tool_requests_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id),
  CONSTRAINT fk_tool_requests_governed_tool_id FOREIGN KEY (governed_tool_id) REFERENCES governed_tools (governed_tool_id),
  CONSTRAINT fk_tool_requests_governed_tool_operation_id FOREIGN KEY (governed_tool_operation_id) REFERENCES governed_tool_operations (governed_tool_operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tool_requests_idempotency ON tool_requests (tenant_id, idempotency_key);

CREATE TABLE IF NOT EXISTS tool_authorization_decisions (
  tool_authorization_decision_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  decision VARCHAR(32) NOT NULL,
  validated_action_class VARCHAR(255),
  passed_gate_check_ids TEXT NOT NULL DEFAULT '[]',
  failed_or_holding_gate_check_ids TEXT NOT NULL DEFAULT '[]',
  reason_codes TEXT NOT NULL DEFAULT '[]',
  decision_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  decided_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_authorization_decision_id),
  CHECK (decision IN ('ALLOW', 'DENY', 'REQUIRE_APPROVAL', 'HOLD_FOR_REVIEW', 'QUARANTINE')),
  CONSTRAINT fk_tool_authorization_decisions_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tool_authorization_decisions_request ON tool_authorization_decisions (tool_request_id);

DROP TRIGGER IF EXISTS trg_tool_auth_decisions_no_update;
DELIMITER $$
CREATE TRIGGER trg_tool_auth_decisions_no_update
  BEFORE UPDATE ON tool_authorization_decisions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_authorization_decisions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_tool_auth_decisions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_tool_auth_decisions_no_delete
  BEFORE DELETE ON tool_authorization_decisions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_authorization_decisions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_denial_escalation_records (
  tool_denial_escalation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_authorization_decision_id CHAR(36) NOT NULL,
  refusal_category VARCHAR(255) NOT NULL,
  severity VARCHAR(32) NOT NULL,
  failed_gate_check_ids TEXT NOT NULL DEFAULT '[]',
  no_dispatch_confirmed TINYINT(1) NOT NULL DEFAULT 1,
  incident_evaluation_required INT NOT NULL DEFAULT 0,
  safe_summary TEXT,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_denial_escalation_id),
  CHECK (severity IN ('S1', 'S2', 'S3', 'S4', 'S5')),
  CHECK (no_dispatch_confirmed = 1),
  CONSTRAINT fk_tool_denial_escalation_records_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id),
  CONSTRAINT fk_tool_denial_escalation_records_tool_authorization_decision_id FOREIGN KEY (tool_authorization_decision_id) REFERENCES tool_authorization_decisions (tool_authorization_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_tool_denial_escalation_request ON tool_denial_escalation_records (tool_request_id);

DROP TRIGGER IF EXISTS trg_tool_denial_escalation_no_update;
DELIMITER $$
CREATE TRIGGER trg_tool_denial_escalation_no_update
  BEFORE UPDATE ON tool_denial_escalation_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_denial_escalation_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_tool_denial_escalation_no_delete;
DELIMITER $$
CREATE TRIGGER trg_tool_denial_escalation_no_delete
  BEFORE DELETE ON tool_denial_escalation_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_denial_escalation_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_dispatch_records (
  tool_dispatch_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_authorization_decision_id CHAR(36) NOT NULL,
  runtime_host_binding_id CHAR(36),
  runtime_host_execution_ref VARCHAR(255),
  action_class VARCHAR(255) NOT NULL,
  exact_dispatched_input_digest CHAR(64) NOT NULL,
  dispatched_at CHAR(24) NOT NULL,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (tool_dispatch_id),
  CHECK (status IN ('DISPATCHED', 'ACCEPTED_BY_ADAPTER', 'REJECTED_BEFORE_ADAPTER', 'FAILED_TO_DISPATCH')),
  CONSTRAINT fk_tool_dispatch_records_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id),
  CONSTRAINT fk_tool_dispatch_records_tool_authorization_decision_id FOREIGN KEY (tool_authorization_decision_id) REFERENCES tool_authorization_decisions (tool_authorization_decision_id),
  CONSTRAINT fk_tool_dispatch_records_runtime_host_binding_id FOREIGN KEY (runtime_host_binding_id) REFERENCES runtime_host_bindings (binding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_tool_dispatch_records_request ON tool_dispatch_records (tool_request_id);

CREATE UNIQUE INDEX ux_tool_dispatch_records_decision ON tool_dispatch_records (tool_authorization_decision_id);

DROP TRIGGER IF EXISTS trg_tool_dispatch_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_tool_dispatch_records_no_update
  BEFORE UPDATE ON tool_dispatch_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_dispatch_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_tool_dispatch_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_tool_dispatch_records_no_delete
  BEFORE DELETE ON tool_dispatch_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_dispatch_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_execution_outcomes (
  tool_execution_outcome_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_dispatch_id CHAR(36) NOT NULL,
  tool_authorization_decision_id CHAR(36) NOT NULL,
  runtime_host_execution_ref VARCHAR(255),
  outcome_status VARCHAR(32) NOT NULL,
  outcome_classification VARCHAR(32),
  result_reference_safe VARCHAR(255),
  result_digest CHAR(64),
  evidence_capture_candidate INT NOT NULL DEFAULT 0,
  error_code VARCHAR(255),
  completed_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_execution_outcome_id),
  CHECK (outcome_status IN ('SUCCEEDED', 'FAILED', 'UNKNOWN', 'CANCELLED', 'REJECTED_INTEGRITY_FAILURE', 'REJECTED_AUTHORITY_FAILURE')),
  CHECK (outcome_classification IN ('SOURCE_CANDIDATE', 'TRANSFORM_OUTPUT', 'DEMO_ACTION_OUTCOME', 'FAILURE_ONLY')),
  CONSTRAINT fk_tool_execution_outcomes_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id),
  CONSTRAINT fk_tool_execution_outcomes_tool_dispatch_id FOREIGN KEY (tool_dispatch_id) REFERENCES tool_dispatch_records (tool_dispatch_id),
  CONSTRAINT fk_tool_execution_outcomes_tool_authorization_decision_id FOREIGN KEY (tool_authorization_decision_id) REFERENCES tool_authorization_decisions (tool_authorization_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tool_execution_outcomes_dispatch ON tool_execution_outcomes (tool_dispatch_id);

DROP TRIGGER IF EXISTS trg_tool_execution_outcomes_no_update;
DELIMITER $$
CREATE TRIGGER trg_tool_execution_outcomes_no_update
  BEFORE UPDATE ON tool_execution_outcomes
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_execution_outcomes is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_tool_execution_outcomes_no_delete;
DELIMITER $$
CREATE TRIGGER trg_tool_execution_outcomes_no_delete
  BEFORE DELETE ON tool_execution_outcomes
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_execution_outcomes is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_gate_checks (
  tool_gate_check_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  gate_type VARCHAR(32) NOT NULL,
  dependency_object_refs_json TEXT NOT NULL DEFAULT '{}',
  decision VARCHAR(32) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  assessed_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_gate_check_id),
  CHECK (gate_type IN ('M02_ACCESS_SCOPE', 'M03_CAPABILITY_CONTRACT', 'M04_MISSION_SCOPE', 'M06_TOOL_DESCRIPTOR', 'V1_SCOPE_PROHIBITION', 'M01_HOST_MAPPING_HEALTH', 'M09_APPROVAL_ARTIFACT_INTEGRITY', 'IDEMPOTENCY_CONCURRENCY')),
  CHECK (decision IN ('PASS', 'FAIL', 'HOLD', 'NOT_APPLICABLE')),
  CONSTRAINT fk_tool_gate_checks_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_tool_gate_checks_request ON tool_gate_checks (tool_request_id, assessed_at);

DROP TRIGGER IF EXISTS trg_tool_gate_checks_no_update;
DELIMITER $$
CREATE TRIGGER trg_tool_gate_checks_no_update
  BEFORE UPDATE ON tool_gate_checks
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_gate_checks is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_tool_gate_checks_no_delete;
DELIMITER $$
CREATE TRIGGER trg_tool_gate_checks_no_delete
  BEFORE DELETE ON tool_gate_checks
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tool_gate_checks is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS tool_idempotency_records (
  idempotency_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  normalized_input_digest CHAR(64) NOT NULL,
  prior_tool_request_id CHAR(36) NOT NULL,
  prior_tool_authorization_decision_id CHAR(36),
  prior_tool_execution_outcome_id CHAR(36),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (idempotency_record_id),
  CONSTRAINT fk_tool_idempotency_records_prior_tool_request_id FOREIGN KEY (prior_tool_request_id) REFERENCES tool_requests (tool_request_id),
  CONSTRAINT fk_tool_idempotency_records_prior_tool_authorization_decision_id FOREIGN KEY (prior_tool_authorization_decision_id) REFERENCES tool_authorization_decisions (tool_authorization_decision_id),
  CONSTRAINT fk_tool_idempotency_records_prior_tool_execution_outcome_id FOREIGN KEY (prior_tool_execution_outcome_id) REFERENCES tool_execution_outcomes (tool_execution_outcome_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tool_idempotency_records_lookup ON tool_idempotency_records (tenant_id, idempotency_key);

CREATE TABLE IF NOT EXISTS governed_execution_claims (
  tool_dispatch_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  claim_id CHAR(36) NOT NULL,
  claimed_at CHAR(24) NOT NULL,
  PRIMARY KEY (tool_dispatch_id),
  CONSTRAINT fk_governed_execution_claims_tool_dispatch_id FOREIGN KEY (tool_dispatch_id) REFERENCES tool_dispatch_records (tool_dispatch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_governed_execution_claims_no_update;
DELIMITER $$
CREATE TRIGGER trg_governed_execution_claims_no_update
  BEFORE UPDATE ON governed_execution_claims
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'governed_execution_claims is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_governed_execution_claims_no_delete;
DELIMITER $$
CREATE TRIGGER trg_governed_execution_claims_no_delete
  BEFORE DELETE ON governed_execution_claims
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'governed_execution_claims is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS runtime_host_execution_refs (
  execution_ref_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  binding_id CHAR(36) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_authorization_decision_id CHAR(36) NOT NULL,
  tool_dispatch_id CHAR(36),
  execution_authorization_id CHAR(36),
  artifact_id CHAR(36),
  runtime_host_execution_ref VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  result_reference_safe VARCHAR(255),
  result_digest CHAR(64),
  output_classification VARCHAR(32),
  error_code VARCHAR(255),
  side_effect_uncertainty INT NOT NULL DEFAULT 0,
  tenant_id CHAR(36),
  workspace_id CHAR(36),
  started_at CHAR(24) NOT NULL,
  completed_at CHAR(24),
  correlation_id CHAR(36),
  PRIMARY KEY (execution_ref_id),
  CHECK (status IN ('REFUSED_PRE_DISPATCH', 'REFUSED_POST_DISPATCH', 'SUCCEEDED', 'FAILED', 'UNKNOWN', 'CANCELLED')),
  CHECK (output_classification IN ('SOURCE_CANDIDATE', 'TRANSFORM_OUTPUT', 'DEMO_ACTION_OUTCOME', 'FAILURE_ONLY')),
  CONSTRAINT fk_runtime_host_execution_refs_binding_id FOREIGN KEY (binding_id) REFERENCES runtime_host_bindings (binding_id),
  CONSTRAINT fk_runtime_host_execution_refs_tool_dispatch_id FOREIGN KEY (tool_dispatch_id) REFERENCES tool_dispatch_records (tool_dispatch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_exec_refs_terminal_dispatch
--   canonical: CREATE UNIQUE INDEX ... ON runtime_host_execution_refs (tool_dispatch_id)
--   predicate: WHERE tool_dispatch_id IS NOT NULL AND status IN ('SUCCEEDED', 'FAILED', 'REFUSED_POST_DISPATCH', 'UNKNOWN', 'CANCELLED')
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_exec_refs_terminal_dispatch_np ON runtime_host_execution_refs (tool_dispatch_id);

DROP TRIGGER IF EXISTS trg_exec_refs_no_update;
DELIMITER $$
CREATE TRIGGER trg_exec_refs_no_update
  BEFORE UPDATE ON runtime_host_execution_refs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'runtime_host_execution_refs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_exec_refs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_exec_refs_no_delete
  BEFORE DELETE ON runtime_host_execution_refs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'runtime_host_execution_refs is append-only';
END$$
DELIMITER ;
