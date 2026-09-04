-- 090_m15_ingestion
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS ingestion_connector_descriptors (
  ingestion_connector_descriptor_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  connector_class VARCHAR(32) NOT NULL,
  connector_version VARCHAR(32) NOT NULL,
  required_content_types TEXT NOT NULL DEFAULT '[]',
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_connector_descriptor_id),
  CHECK (connector_class IN ('PUBLIC_WEB_PAGE', 'SITEMAP_OR_URL_LIST', 'RSS_OR_ATOM_FEED', 'JSON_API', 'CSV_OR_STATIC_FILE', 'TENANT_UPLOADED_ARTIFACT', 'SYNTHETIC_TEST_FIXTURE')),
  CHECK (status IN ('INERT', 'ACTIVE', 'SUSPENDED', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_connector_descriptors_class_version ON ingestion_connector_descriptors (connector_class, connector_version);

DROP TRIGGER IF EXISTS trg_ingestion_connector_descriptors_identity_immutable;
DELIMITER $$
CREATE TRIGGER trg_ingestion_connector_descriptors_identity_immutable
  BEFORE UPDATE ON ingestion_connector_descriptors
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.connector_class <> NEW.connector_class OR OLD.connector_version <> NEW.connector_version OR OLD.required_content_types <> NEW.required_content_types OR OLD.created_at <> NEW.created_at
  IF OLD.connector_class <> NEW.connector_class OR OLD.connector_version <> NEW.connector_version OR OLD.required_content_types <> NEW.required_content_types OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_connector_descriptors identity is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_connector_descriptors_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_connector_descriptors_no_delete
  BEFORE DELETE ON ingestion_connector_descriptors
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_connector_descriptors is append/supersede-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_entity_aliases (
  ingestion_entity_alias_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  normalized_alias VARCHAR(191) NOT NULL,
  target_kind VARCHAR(32) NOT NULL,
  target_entity_twin_id CHAR(36),
  target_competitor_id CHAR(36),
  confidence_band VARCHAR(32) NOT NULL,
  declared_by_operator_id CHAR(36) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  revoked_at CHAR(24),
  PRIMARY KEY (ingestion_entity_alias_id),
  CHECK (length(normalized_alias) > 0),
  CHECK (target_kind IN ('ENTITY_TWIN', 'COMPETITOR')),
  CHECK (confidence_band IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (status IN ('ACTIVE', 'REVOKED')),
  CHECK ((target_kind = 'ENTITY_TWIN') = (target_entity_twin_id IS NOT NULL)),
  CHECK ((target_kind = 'COMPETITOR') = (target_competitor_id IS NOT NULL)),
  CONSTRAINT fk_ingestion_entity_aliases_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_entity_aliases_target_entity_twin_id FOREIGN KEY (target_entity_twin_id) REFERENCES entity_twins (entity_twin_id),
  CONSTRAINT fk_ingestion_entity_aliases_target_competitor_id FOREIGN KEY (target_competitor_id) REFERENCES competitors (competitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_ingestion_entity_aliases_active
--   canonical: CREATE UNIQUE INDEX ... ON ingestion_entity_aliases (tenant_id, normalized_alias, target_kind)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_ingestion_entity_aliases_active_np ON ingestion_entity_aliases (tenant_id, normalized_alias, target_kind);

DROP TRIGGER IF EXISTS trg_ingestion_entity_aliases_identity_immutable;
DELIMITER $$
CREATE TRIGGER trg_ingestion_entity_aliases_identity_immutable
  BEFORE UPDATE ON ingestion_entity_aliases
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.tenant_id <> NEW.tenant_id OR OLD.normalized_alias <> NEW.normalized_alias OR OLD.target_kind <> NEW.target_kind OR COALESCE(OLD.target_entity_twin_id, '') <> COALESCE(NEW.target_entity_twin_id, '') OR COALESCE(OLD.target_competitor_id, '') <> COALESCE(NEW.target_competitor_id, '') OR OLD.confidence_band <> NEW.confidence_band OR OLD.declared_by_operator_id <> NEW.declared_by_operator_id OR OLD.created_at <> NEW.created_at
  IF OLD.tenant_id <> NEW.tenant_id OR OLD.normalized_alias <> NEW.normalized_alias OR OLD.target_kind <> NEW.target_kind OR COALESCE(OLD.target_entity_twin_id, '') <> COALESCE(NEW.target_entity_twin_id, '') OR COALESCE(OLD.target_competitor_id, '') <> COALESCE(NEW.target_competitor_id, '') OR OLD.confidence_band <> NEW.confidence_band OR OLD.declared_by_operator_id <> NEW.declared_by_operator_id OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_entity_aliases identity is immutable (revocation-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_entity_aliases_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_entity_aliases_no_delete
  BEFORE DELETE ON ingestion_entity_aliases
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_entity_aliases is append/revoke-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_source_policies (
  ingestion_source_policy_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  source_license_card_id CHAR(36),
  status VARCHAR(32) NOT NULL,
  policy_digest CHAR(64) NOT NULL,
  compiler_version VARCHAR(32) NOT NULL,
  connector_class VARCHAR(32) NOT NULL,
  connector_version VARCHAR(32) NOT NULL,
  policy_json TEXT NOT NULL,
  blocked_reason TEXT,
  fail_closed_reason_codes TEXT NOT NULL DEFAULT '[]',
  compiled_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_source_policy_id),
  CHECK (status IN ('COMPILED_ALLOW', 'COMPILED_BLOCKED')),
  CHECK (connector_class IN ('PUBLIC_WEB_PAGE', 'SITEMAP_OR_URL_LIST', 'RSS_OR_ATOM_FEED', 'JSON_API', 'CSV_OR_STATIC_FILE', 'TENANT_UPLOADED_ARTIFACT', 'SYNTHETIC_TEST_FIXTURE')),
  CHECK (status <> 'COMPILED_BLOCKED' OR blocked_reason IS NOT NULL),
  CONSTRAINT fk_ingestion_source_policies_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_source_policies_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_ingestion_source_policies_source_license_card_id FOREIGN KEY (source_license_card_id) REFERENCES source_license_cards (source_license_card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_source_policies_tenant_digest ON ingestion_source_policies (tenant_id, policy_digest);

CREATE INDEX ix_ingestion_source_policies_source ON ingestion_source_policies (tenant_id, source_ref_id, status);

DROP TRIGGER IF EXISTS trg_ingestion_source_policies_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_source_policies_no_update
  BEFORE UPDATE ON ingestion_source_policies
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_source_policies is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_source_policies_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_source_policies_no_delete
  BEFORE DELETE ON ingestion_source_policies
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_source_policies is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_fetch_plans (
  ingestion_fetch_plan_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  connector_class VARCHAR(32) NOT NULL,
  policy_id CHAR(36) NOT NULL,
  policy_digest CHAR(64) NOT NULL,
  capture_url VARCHAR(2048),
  cadence_min_interval_ms INT NOT NULL,
  mission_id CHAR(36) NOT NULL,
  assignment_id CHAR(36) NOT NULL,
  valkyrie_id CHAR(36) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  governed_tool_operation_id CHAR(36) NOT NULL,
  calling_actor_type VARCHAR(32) NOT NULL,
  calling_actor_id CHAR(36) NOT NULL,
  required_entitlement_permission TEXT,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_fetch_plan_id),
  CHECK (connector_class IN ('PUBLIC_WEB_PAGE', 'SITEMAP_OR_URL_LIST', 'RSS_OR_ATOM_FEED', 'JSON_API', 'CSV_OR_STATIC_FILE', 'TENANT_UPLOADED_ARTIFACT', 'SYNTHETIC_TEST_FIXTURE')),
  CHECK (cadence_min_interval_ms >= 0),
  CHECK (calling_actor_type IN ('OPERATOR', 'SERVICE')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'RETIRED')),
  CONSTRAINT fk_ingestion_fetch_plans_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_fetch_plans_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_fetch_plans_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_ingestion_fetch_plans_policy_id FOREIGN KEY (policy_id) REFERENCES ingestion_source_policies (ingestion_source_policy_id),
  CONSTRAINT fk_ingestion_fetch_plans_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_ingestion_fetch_plans_one_active
--   canonical: CREATE UNIQUE INDEX ... ON ingestion_fetch_plans (tenant_id, source_ref_id, connector_class)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_ingestion_fetch_plans_one_active_np ON ingestion_fetch_plans (tenant_id, source_ref_id, connector_class);

CREATE INDEX ix_ingestion_fetch_plans_scope ON ingestion_fetch_plans (tenant_id, workspace_id, status);

DROP TRIGGER IF EXISTS trg_ingestion_fetch_plans_scope_immutable;
DELIMITER $$
CREATE TRIGGER trg_ingestion_fetch_plans_scope_immutable
  BEFORE UPDATE ON ingestion_fetch_plans
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.source_ref_id <> NEW.source_ref_id OR OLD.connector_class <> NEW.connector_class OR OLD.policy_id <> NEW.policy_id OR OLD.policy_digest <> NEW.policy_digest OR COALESCE(OLD.capture_url, '') <> COALESCE(NEW.capture_url, '') OR OLD.cadence_min_interval_ms <> NEW.cadence_min_interval_ms OR OLD.mission_id <> NEW.mission_id OR OLD.assignment_id <> NEW.assignment_id OR OLD.valkyrie_id <> NEW.valkyrie_id OR OLD.capability_contract_id <> NEW.capability_contract_id OR OLD.governed_tool_id <> NEW.governed_tool_id OR OLD.governed_tool_operation_id <> NEW.governed_tool_operation_id OR OLD.calling_actor_type <> NEW.calling_actor_type OR OLD.calling_actor_id <> NEW.calling_actor_id OR COALESCE(OLD.required_entitlement_permission, '') <> COALESCE(NEW.required_entitlement_permission, '') OR OLD.created_at <> NEW.created_at
  IF OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.source_ref_id <> NEW.source_ref_id OR OLD.connector_class <> NEW.connector_class OR OLD.policy_id <> NEW.policy_id OR OLD.policy_digest <> NEW.policy_digest OR COALESCE(OLD.capture_url, '') <> COALESCE(NEW.capture_url, '') OR OLD.cadence_min_interval_ms <> NEW.cadence_min_interval_ms OR OLD.mission_id <> NEW.mission_id OR OLD.assignment_id <> NEW.assignment_id OR OLD.valkyrie_id <> NEW.valkyrie_id OR OLD.capability_contract_id <> NEW.capability_contract_id OR OLD.governed_tool_id <> NEW.governed_tool_id OR OLD.governed_tool_operation_id <> NEW.governed_tool_operation_id OR OLD.calling_actor_type <> NEW.calling_actor_type OR OLD.calling_actor_id <> NEW.calling_actor_id OR COALESCE(OLD.required_entitlement_permission, '') <> COALESCE(NEW.required_entitlement_permission, '') OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_fetch_plans scope is immutable (status-only updates; new plan to change scope)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_fetch_plans_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_fetch_plans_no_delete
  BEFORE DELETE ON ingestion_fetch_plans
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_fetch_plans is lifecycle-only (suspend/retire, never delete)';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_raw_captures (
  ingestion_raw_capture_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  fetch_plan_id CHAR(36) NOT NULL,
  source_ref_id CHAR(36) NOT NULL,
  policy_id CHAR(36) NOT NULL,
  policy_digest CHAR(64) NOT NULL,
  connector_class VARCHAR(32) NOT NULL,
  connector_version VARCHAR(32) NOT NULL,
  normalized_request_digest CHAR(64) NOT NULL,
  request_url_safe TEXT NOT NULL,
  response_status INT,
  response_content_type TEXT,
  response_bytes INT,
  content_hash CHAR(64),
  raw_artifact_id CHAR(36),
  evidence_id CHAR(36),
  tool_request_id CHAR(36),
  tool_authorization_decision_id CHAR(36),
  runtime_host_execution_ref TEXT,
  fetch_outcome VARCHAR(32) NOT NULL,
  refusal_or_failure_reason TEXT,
  idempotency_key VARCHAR(191) NOT NULL,
  captured_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_raw_capture_id),
  CHECK (connector_class IN ('PUBLIC_WEB_PAGE', 'SITEMAP_OR_URL_LIST', 'RSS_OR_ATOM_FEED', 'JSON_API', 'CSV_OR_STATIC_FILE', 'TENANT_UPLOADED_ARTIFACT', 'SYNTHETIC_TEST_FIXTURE')),
  CHECK (fetch_outcome IN ('CAPTURED', 'REFUSED_POLICY', 'REFUSED_EGRESS', 'FETCH_FAILED', 'DENIED_GATE', 'IDEMPOTENT_REPLAY')),
  CHECK (fetch_outcome <> 'CAPTURED' OR (evidence_id IS NOT NULL AND content_hash IS NOT NULL AND tool_request_id IS NOT NULL)),
  CHECK (fetch_outcome = 'CAPTURED' OR (evidence_id IS NULL AND raw_artifact_id IS NULL)),
  CHECK (fetch_outcome = 'CAPTURED' OR refusal_or_failure_reason IS NOT NULL),
  CONSTRAINT fk_ingestion_raw_captures_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_raw_captures_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_raw_captures_fetch_plan_id FOREIGN KEY (fetch_plan_id) REFERENCES ingestion_fetch_plans (ingestion_fetch_plan_id),
  CONSTRAINT fk_ingestion_raw_captures_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_ingestion_raw_captures_policy_id FOREIGN KEY (policy_id) REFERENCES ingestion_source_policies (ingestion_source_policy_id),
  CONSTRAINT fk_ingestion_raw_captures_raw_artifact_id FOREIGN KEY (raw_artifact_id) REFERENCES raw_artifacts (raw_artifact_id),
  CONSTRAINT fk_ingestion_raw_captures_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id),
  CONSTRAINT fk_ingestion_raw_captures_tool_request_id FOREIGN KEY (tool_request_id) REFERENCES tool_requests (tool_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_raw_captures_idempotency ON ingestion_raw_captures (tenant_id, idempotency_key);

CREATE INDEX ix_ingestion_raw_captures_plan ON ingestion_raw_captures (tenant_id, workspace_id, fetch_plan_id, fetch_outcome);

DROP TRIGGER IF EXISTS trg_ingestion_raw_captures_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_raw_captures_no_update
  BEFORE UPDATE ON ingestion_raw_captures
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_raw_captures is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_raw_captures_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_raw_captures_no_delete
  BEFORE DELETE ON ingestion_raw_captures
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_raw_captures is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_capture_reservations (
  ingestion_capture_reservation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  fetch_plan_id CHAR(36) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  reserved_at CHAR(24) NOT NULL,
  expires_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_capture_reservation_id),
  CONSTRAINT fk_ingestion_capture_reservations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_capture_reservations_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_capture_reservations_fetch_plan_id FOREIGN KEY (fetch_plan_id) REFERENCES ingestion_fetch_plans (ingestion_fetch_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_capture_reservations_plan ON ingestion_capture_reservations (fetch_plan_id);

CREATE TABLE IF NOT EXISTS ingestion_normalization_runs (
  ingestion_normalization_run_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  raw_capture_id CHAR(36) NOT NULL,
  input_evidence_id CHAR(36),
  normalizer_key VARCHAR(191) NOT NULL,
  normalizer_version VARCHAR(32) NOT NULL,
  input_digest CHAR(64) NOT NULL,
  output_digest CHAR(64),
  candidate_count INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  refusal_or_failure_reason TEXT,
  ran_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_normalization_run_id),
  CHECK (status IN ('COMPLETED', 'FAILED', 'REFUSED')),
  CHECK (status = 'COMPLETED' OR refusal_or_failure_reason IS NOT NULL),
  CHECK (status <> 'COMPLETED' OR output_digest IS NOT NULL),
  CONSTRAINT fk_ingestion_normalization_runs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_normalization_runs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_normalization_runs_raw_capture_id FOREIGN KEY (raw_capture_id) REFERENCES ingestion_raw_captures (ingestion_raw_capture_id),
  CONSTRAINT fk_ingestion_normalization_runs_input_evidence_id FOREIGN KEY (input_evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_normalization_runs_idempotency ON ingestion_normalization_runs (tenant_id, normalizer_key, normalizer_version, input_digest);

CREATE INDEX ix_ingestion_normalization_runs_capture ON ingestion_normalization_runs (tenant_id, workspace_id, raw_capture_id);

DROP TRIGGER IF EXISTS trg_ingestion_normalization_runs_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_normalization_runs_no_update
  BEFORE UPDATE ON ingestion_normalization_runs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_normalization_runs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_normalization_runs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_normalization_runs_no_delete
  BEFORE DELETE ON ingestion_normalization_runs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_normalization_runs is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_candidates (
  ingestion_candidate_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  normalization_run_id CHAR(36) NOT NULL,
  primary_basis_evidence_id CHAR(36) NOT NULL,
  candidate_type VARCHAR(32) NOT NULL,
  source_authority_assessment VARCHAR(32) NOT NULL,
  confidence VARCHAR(32) NOT NULL,
  freshness_observed_at CHAR(24) NOT NULL,
  extraction_method VARCHAR(32) NOT NULL,
  normalized_value TEXT NOT NULL,
  raw_value_reference_or_hash CHAR(64) NOT NULL,
  conflict_key VARCHAR(191) NOT NULL,
  entity_resolution_key VARCHAR(191),
  candidate_status VARCHAR(32) NOT NULL,
  uncertainty_summary_safe TEXT NOT NULL,
  missingness_summary_safe TEXT,
  interpretation_kind VARCHAR(32) NOT NULL,
  customer_output_allowed TINYINT(1) NOT NULL,
  sensitivity_class VARCHAR(32) NOT NULL,
  candidate_digest CHAR(64) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_candidate_id),
  CHECK (candidate_type IN ('CANONICAL_OBSERVATION', 'ENTITY_FACT', 'RELATIONSHIP', 'COMPANY_SIGNAL', 'MARKET_CONTEXT')),
  CHECK (source_authority_assessment IN ('PUBLIC_SOURCE', 'LICENSED_SOURCE', 'TENANT_OWNED', 'TEST_FIXTURE')),
  CHECK (confidence IN ('LOW', 'MEDIUM', 'HIGH', 'NOT_QUANTIFIED')),
  CHECK (extraction_method IN ('DETERMINISTIC_PARSER', 'DETERMINISTIC_DIFF', 'STRUCTURED_FIELD_MAP', 'OPERATOR_DECLARED')),
  CHECK (candidate_status IN ('NORMALIZED', 'RESOLUTION_PENDING', 'RESOLVED', 'AMBIGUOUS', 'UNRESOLVED', 'SUBMITTED_FOR_ADMISSION', 'ADMITTED_TO_CONSUMER', 'HELD', 'REJECTED', 'SUPERSEDED', 'EXPIRED')),
  CHECK (length(uncertainty_summary_safe) > 0),
  CHECK (interpretation_kind IN ('FACT_OBSERVED', 'HYPOTHESIS')),
  CHECK (customer_output_allowed IN (0, 1)),
  CHECK (sensitivity_class IN ('PUBLIC_OR_NON_SENSITIVE', 'INTERNAL_DEMO', 'RESTRICTED_REVIEW', 'PROHIBITED_IN_V1')),
  CONSTRAINT fk_ingestion_candidates_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_candidates_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_candidates_normalization_run_id FOREIGN KEY (normalization_run_id) REFERENCES ingestion_normalization_runs (ingestion_normalization_run_id),
  CONSTRAINT fk_ingestion_candidates_primary_basis_evidence_id FOREIGN KEY (primary_basis_evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_candidates_digest ON ingestion_candidates (tenant_id, workspace_id, candidate_digest);

CREATE INDEX ix_ingestion_candidates_conflict ON ingestion_candidates (tenant_id, workspace_id, conflict_key);

CREATE INDEX ix_ingestion_candidates_status ON ingestion_candidates (tenant_id, workspace_id, candidate_status, candidate_type);

DROP TRIGGER IF EXISTS trg_ingestion_candidates_content_immutable;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidates_content_immutable
  BEFORE UPDATE ON ingestion_candidates
  FOR EACH ROW
BEGIN
  -- canonical guard: OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.normalization_run_id <> NEW.normalization_run_id OR OLD.primary_basis_evidence_id <> NEW.primary_basis_evidence_id OR OLD.candidate_type <> NEW.candidate_type OR OLD.source_authority_assessment <> NEW.source_authority_assessment OR OLD.confidence <> NEW.confidence OR OLD.freshness_observed_at <> NEW.freshness_observed_at OR OLD.extraction_method <> NEW.extraction_method OR OLD.normalized_value <> NEW.normalized_value OR OLD.raw_value_reference_or_hash <> NEW.raw_value_reference_or_hash OR OLD.conflict_key <> NEW.conflict_key OR COALESCE(OLD.entity_resolution_key, '') <> COALESCE(NEW.entity_resolution_key, '') OR OLD.uncertainty_summary_safe <> NEW.uncertainty_summary_safe OR COALESCE(OLD.missingness_summary_safe, '') <> COALESCE(NEW.missingness_summary_safe, '') OR OLD.interpretation_kind <> NEW.interpretation_kind OR OLD.customer_output_allowed <> NEW.customer_output_allowed OR OLD.sensitivity_class <> NEW.sensitivity_class OR OLD.candidate_digest <> NEW.candidate_digest OR OLD.created_at <> NEW.created_at
  IF OLD.tenant_id <> NEW.tenant_id OR OLD.workspace_id <> NEW.workspace_id OR OLD.normalization_run_id <> NEW.normalization_run_id OR OLD.primary_basis_evidence_id <> NEW.primary_basis_evidence_id OR OLD.candidate_type <> NEW.candidate_type OR OLD.source_authority_assessment <> NEW.source_authority_assessment OR OLD.confidence <> NEW.confidence OR OLD.freshness_observed_at <> NEW.freshness_observed_at OR OLD.extraction_method <> NEW.extraction_method OR OLD.normalized_value <> NEW.normalized_value OR OLD.raw_value_reference_or_hash <> NEW.raw_value_reference_or_hash OR OLD.conflict_key <> NEW.conflict_key OR COALESCE(OLD.entity_resolution_key, '') <> COALESCE(NEW.entity_resolution_key, '') OR OLD.uncertainty_summary_safe <> NEW.uncertainty_summary_safe OR COALESCE(OLD.missingness_summary_safe, '') <> COALESCE(NEW.missingness_summary_safe, '') OR OLD.interpretation_kind <> NEW.interpretation_kind OR OLD.customer_output_allowed <> NEW.customer_output_allowed OR OLD.sensitivity_class <> NEW.sensitivity_class OR OLD.candidate_digest <> NEW.candidate_digest OR OLD.created_at <> NEW.created_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidates content is immutable (status-only updates)';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_candidates_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidates_no_delete
  BEFORE DELETE ON ingestion_candidates
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidates is lifecycle-only (supersede/expire, never delete)';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_entity_resolution_attempts (
  ingestion_entity_resolution_attempt_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ingestion_candidate_id CHAR(36) NOT NULL,
  target_kind VARCHAR(32) NOT NULL,
  resolver_version VARCHAR(32) NOT NULL,
  strategy VARCHAR(32),
  outcome VARCHAR(32) NOT NULL,
  matched_entity_twin_id CHAR(36),
  matched_competitor_id CHAR(36),
  collision_target_ids TEXT NOT NULL DEFAULT '[]',
  confidence_score DECIMAL(18,6),
  match_keys_safe TEXT NOT NULL DEFAULT '{}',
  refusal_reason TEXT,
  attempted_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_entity_resolution_attempt_id),
  CHECK (target_kind IN ('ENTITY_TWIN', 'COMPETITOR')),
  CHECK (strategy IN ('ORGANIZATION_NUMBER_EXACT', 'REGISTERED_DOMAIN_EXACT', 'NORMALIZED_LEGAL_NAME_EXACT', 'CURATED_ALIAS')),
  CHECK (outcome IN ('RESOLVED', 'AMBIGUOUS', 'UNRESOLVED', 'REFUSED')),
  CHECK (outcome <> 'RESOLVED' OR strategy IS NOT NULL),
  CHECK (outcome <> 'RESOLVED' OR (matched_entity_twin_id IS NOT NULL OR matched_competitor_id IS NOT NULL)),
  CHECK (outcome = 'RESOLVED' OR (matched_entity_twin_id IS NULL AND matched_competitor_id IS NULL)),
  CHECK (outcome <> 'REFUSED' OR refusal_reason IS NOT NULL),
  CONSTRAINT fk_ingestion_entity_resolution_attempts_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_entity_resolution_attempts_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_entity_resolution_attempts_ingestion_candidate_id FOREIGN KEY (ingestion_candidate_id) REFERENCES ingestion_candidates (ingestion_candidate_id),
  CONSTRAINT fk_ingestion_entity_resolution_attempts_matched_entity_twin_id FOREIGN KEY (matched_entity_twin_id) REFERENCES entity_twins (entity_twin_id),
  CONSTRAINT fk_ingestion_entity_resolution_attempts_matched_competitor_id FOREIGN KEY (matched_competitor_id) REFERENCES competitors (competitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_ingestion_entity_resolution_attempts_candidate ON ingestion_entity_resolution_attempts (tenant_id, ingestion_candidate_id);

DROP TRIGGER IF EXISTS trg_ingestion_entity_resolution_attempts_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_entity_resolution_attempts_no_update
  BEFORE UPDATE ON ingestion_entity_resolution_attempts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_entity_resolution_attempts is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_entity_resolution_attempts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_entity_resolution_attempts_no_delete
  BEFORE DELETE ON ingestion_entity_resolution_attempts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_entity_resolution_attempts is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_admission_links (
  ingestion_admission_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  ingestion_candidate_id CHAR(36) NOT NULL,
  consumer VARCHAR(32) NOT NULL,
  consumer_object_id CHAR(36),
  proposal_id CHAR(36),
  outcome VARCHAR(32) NOT NULL,
  consumer_reason_code TEXT,
  routed_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_admission_link_id),
  CHECK (consumer IN ('P01_COMPANY_SIGNAL', 'P02_TWIN_STATE', 'P02_RELATIONSHIP')),
  CHECK (outcome IN ('SUBMITTED', 'CONSUMER_REFUSED')),
  CHECK (outcome <> 'SUBMITTED' OR (consumer_object_id IS NOT NULL AND proposal_id IS NOT NULL AND consumer_reason_code IS NULL)),
  CHECK (outcome <> 'CONSUMER_REFUSED' OR (consumer_object_id IS NULL AND proposal_id IS NULL AND consumer_reason_code IS NOT NULL)),
  CONSTRAINT fk_ingestion_admission_links_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_admission_links_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_ingestion_admission_links_ingestion_candidate_id FOREIGN KEY (ingestion_candidate_id) REFERENCES ingestion_candidates (ingestion_candidate_id),
  CONSTRAINT fk_ingestion_admission_links_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_admission_links_candidate_consumer ON ingestion_admission_links (ingestion_candidate_id, consumer);

DROP TRIGGER IF EXISTS trg_ingestion_admission_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_admission_links_no_update
  BEFORE UPDATE ON ingestion_admission_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_admission_links is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_admission_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_admission_links_no_delete
  BEFORE DELETE ON ingestion_admission_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_admission_links is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_candidate_evidence_links (
  ingestion_candidate_evidence_link_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  ingestion_candidate_id CHAR(36) NOT NULL,
  evidence_id CHAR(36) NOT NULL,
  link_role VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_candidate_evidence_link_id),
  CHECK (link_role IN ('PRIMARY_BASIS', 'SUPPORTING', 'ADVERSE', 'CONTEXT')),
  CONSTRAINT fk_ingestion_candidate_evidence_links_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_candidate_evidence_links_ingestion_candidate_id FOREIGN KEY (ingestion_candidate_id) REFERENCES ingestion_candidates (ingestion_candidate_id),
  CONSTRAINT fk_ingestion_candidate_evidence_links_evidence_id FOREIGN KEY (evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_ingestion_candidate_evidence_links_pair ON ingestion_candidate_evidence_links (ingestion_candidate_id, evidence_id);

DROP TRIGGER IF EXISTS trg_ingestion_candidate_evidence_links_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidate_evidence_links_no_update
  BEFORE UPDATE ON ingestion_candidate_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidate_evidence_links is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_candidate_evidence_links_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidate_evidence_links_no_delete
  BEFORE DELETE ON ingestion_candidate_evidence_links
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidate_evidence_links is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS ingestion_candidate_status_transitions (
  ingestion_candidate_status_transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  ingestion_candidate_id CHAR(36) NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  reason_code TEXT,
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (ingestion_candidate_status_transition_id),
  CONSTRAINT fk_ingestion_candidate_status_transitions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_ingestion_candidate_status_transitions_ingestion_candidate_id FOREIGN KEY (ingestion_candidate_id) REFERENCES ingestion_candidates (ingestion_candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_ingestion_candidate_status_transitions_candidate ON ingestion_candidate_status_transitions (tenant_id, ingestion_candidate_id);

DROP TRIGGER IF EXISTS trg_ingestion_candidate_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidate_status_transitions_no_update
  BEFORE UPDATE ON ingestion_candidate_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidate_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_ingestion_candidate_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_ingestion_candidate_status_transitions_no_delete
  BEFORE DELETE ON ingestion_candidate_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ingestion_candidate_status_transitions is append-only';
END$$
DELIMITER ;
