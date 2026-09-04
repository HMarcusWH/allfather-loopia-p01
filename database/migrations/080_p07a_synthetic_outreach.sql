-- 080_p07a_synthetic_outreach
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS approval_requests (
  approval_request_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  proposal_submission_digest CHAR(64) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  arbiter_verdict_basis_hash CHAR(64) NOT NULL,
  action_category VARCHAR(255) NOT NULL,
  requested_adapter_type VARCHAR(32) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  governed_tool_operation_id CHAR(36) NOT NULL,
  requested_effect_summary TEXT NOT NULL,
  requested_destination_or_scope_summary TEXT NOT NULL,
  exact_payload_reference_safe VARCHAR(255) NOT NULL,
  exact_normalized_payload_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  status VARCHAR(32) NOT NULL,
  created_by_actor_type VARCHAR(32) NOT NULL,
  created_by_actor_id CHAR(36) NOT NULL,
  required_approver_permission VARCHAR(255),
  created_at CHAR(24) NOT NULL,
  decision_due_at CHAR(24),
  expires_at CHAR(24),
  PRIMARY KEY (approval_request_id),
  CHECK (action_category = 'HARMLESS_DEMO_ACTION'),
  CHECK (requested_adapter_type IN ('NO_OP_TEST_EVENT', 'INTERNAL_DEMO_NOTIFICATION', 'CONTROLLED_LOCAL_TEST_OUTPUT')),
  CHECK (status IN ('DRAFT', 'AWAITING_APPROVAL', 'APPROVED', 'DENIED', 'REVISION_REQUESTED', 'EXPIRED', 'REVOKED', 'ARTIFACT_FROZEN', 'EXECUTION_AUTHORIZED', 'COMPLETED', 'FAILED', 'OUTCOME_UNKNOWN', 'BLOCKED')),
  CHECK (created_by_actor_type IN ('SERVICE', 'SYSTEM', 'OPERATOR')),
  CONSTRAINT fk_approval_requests_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_approval_requests_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_approval_requests_mission_id FOREIGN KEY (mission_id) REFERENCES missions (mission_id),
  CONSTRAINT fk_approval_requests_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_approval_requests_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_approval_requests_scope ON approval_requests (tenant_id, workspace_id, status, created_at);

CREATE UNIQUE INDEX ux_approval_requests_verdict ON approval_requests (arbiter_verdict_id);

CREATE TABLE IF NOT EXISTS consent_registry (
  consent_registry_entry_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  recipient_pseudonym_ref VARCHAR(255) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  consent_state VARCHAR(32) NOT NULL,
  lawful_basis VARCHAR(32) NOT NULL,
  basis_reference_safe VARCHAR(255),
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (consent_registry_entry_id),
  CHECK (channel IN ('EMAIL', 'SMS', 'PUSH')),
  CHECK (consent_state IN ('OPTED_IN', 'OPTED_OUT', 'WITHDRAWN', 'NO_BASIS')),
  CHECK (lawful_basis IN ('CONSENT', 'LEGITIMATE_INTEREST', 'CONTRACT', 'LEGAL_OBLIGATION', 'NONE')),
  CONSTRAINT fk_consent_registry_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_consent_registry_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_consent_registry_current ON consent_registry (tenant_id, workspace_id, recipient_pseudonym_ref, channel, recorded_at);

DROP TRIGGER IF EXISTS trg_consent_registry_no_update;
DELIMITER $$
CREATE TRIGGER trg_consent_registry_no_update
  BEFORE UPDATE ON consent_registry
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_registry is append-only (a consent change is a new row)';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_consent_registry_no_delete;
DELIMITER $$
CREATE TRIGGER trg_consent_registry_no_delete
  BEFORE DELETE ON consent_registry
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_registry is append-only (GDPR erasure = pseudonymization + retention downgrade, never deletion)';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS outreach_channel_bindings (
  outreach_channel_binding_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  adapter_type VARCHAR(255) NOT NULL DEFAULT 'NO_OP_TEST_EVENT',
  external_effect_permitted TINYINT(1) NOT NULL DEFAULT 0,
  binding_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  updated_at CHAR(24),
  PRIMARY KEY (outreach_channel_binding_id),
  CHECK (channel IN ('EMAIL', 'SMS', 'PUSH')),
  CHECK (adapter_type = 'NO_OP_TEST_EVENT'),
  CHECK (external_effect_permitted = 0),
  CHECK (binding_status IN ('ENABLED', 'DISABLED_KILLSWITCH')),
  CONSTRAINT fk_outreach_channel_bindings_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_outreach_channel_bindings_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_outreach_channel_bindings_scope ON outreach_channel_bindings (tenant_id, workspace_id, channel, binding_status);

CREATE TABLE IF NOT EXISTS outreach_execution_proposals (
  outreach_execution_proposal_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  recipient_pseudonym_ref VARCHAR(255) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  payload_kind VARCHAR(32) NOT NULL,
  exact_payload_reference_safe VARCHAR(255) NOT NULL,
  content_to_scan_safe VARCHAR(255) NOT NULL,
  source_decision_record_id CHAR(36) NOT NULL,
  consent_registry_entry_id CHAR(36) NOT NULL,
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  proposal_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (outreach_execution_proposal_id),
  CHECK (channel IN ('EMAIL', 'SMS', 'PUSH')),
  CHECK (payload_kind IN ('SMS_BODY', 'EMAIL_SUBJECT_BODY', 'PUSH_TITLE_BODY')),
  CHECK (proposal_status IN ('DRAFT', 'PENDING_VERDICT', 'APPROVAL_REQUIRED', 'BLOCKED')),
  CONSTRAINT fk_outreach_execution_proposals_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_outreach_execution_proposals_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_outreach_execution_proposals_source_decision_record_id FOREIGN KEY (source_decision_record_id) REFERENCES decision_records (decision_record_id),
  CONSTRAINT fk_outreach_execution_proposals_consent_registry_entry_id FOREIGN KEY (consent_registry_entry_id) REFERENCES consent_registry (consent_registry_entry_id),
  CONSTRAINT fk_outreach_execution_proposals_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_outreach_execution_proposals_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_outreach_execution_proposals_recipient ON outreach_execution_proposals (tenant_id, workspace_id, recipient_pseudonym_ref, channel, created_at);

CREATE INDEX ix_outreach_execution_proposals_proposal ON outreach_execution_proposals (proposal_id);

DROP TRIGGER IF EXISTS trg_outreach_execution_proposals_facts_immutable;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_proposals_facts_immutable
  BEFORE UPDATE ON outreach_execution_proposals
  FOR EACH ROW
BEGIN
  -- canonical guard: (OLD.proposal_status <> 'DRAFT' OR NEW.proposal_status <> OLD.proposal_status) AND ( NEW.recipient_pseudonym_ref <> OLD.recipient_pseudonym_ref OR NEW.channel <> OLD.channel OR NEW.payload_kind <> OLD.payload_kind OR NEW.exact_payload_reference_safe <> OLD.exact_payload_reference_safe OR NEW.source_decision_record_id <> OLD.source_decision_record_id OR NEW.consent_registry_entry_id <> OLD.consent_registry_entry_id )
  IF (OLD.proposal_status <> 'DRAFT' OR NEW.proposal_status <> OLD.proposal_status) AND ( NEW.recipient_pseudonym_ref <> OLD.recipient_pseudonym_ref OR NEW.channel <> OLD.channel OR NEW.payload_kind <> OLD.payload_kind OR NEW.exact_payload_reference_safe <> OLD.exact_payload_reference_safe OR NEW.source_decision_record_id <> OLD.source_decision_record_id OR NEW.consent_registry_entry_id <> OLD.consent_registry_entry_id ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_proposals identity facts are immutable once submitted';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_outreach_execution_proposals_no_delete;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_proposals_no_delete
  BEFORE DELETE ON outreach_execution_proposals
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_proposals is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS recipient_pseudonym_mappings (
  recipient_pseudonym_mapping_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  recipient_pseudonym_ref VARCHAR(255) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  pseudonymization_method VARCHAR(32) NOT NULL,
  sealed_identity_locator_safe VARCHAR(255),
  retention_class VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  retention_downgraded_at CHAR(24),
  PRIMARY KEY (recipient_pseudonym_mapping_id),
  CHECK (channel IN ('EMAIL', 'SMS', 'PUSH')),
  CHECK (pseudonymization_method IN ('SALTED_SHA256')),
  CHECK (retention_class IN ('ACTIVE_LINKABLE', 'ERASED_PSEUDONYMIZED')),
  CONSTRAINT fk_recipient_pseudonym_mappings_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_recipient_pseudonym_mappings_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_recipient_pseudonym_mappings ON recipient_pseudonym_mappings (tenant_id, workspace_id, recipient_pseudonym_ref, channel);

DROP TRIGGER IF EXISTS trg_recipient_pseudonym_mappings_no_delete;
DELIMITER $$
CREATE TRIGGER trg_recipient_pseudonym_mappings_no_delete
  BEFORE DELETE ON recipient_pseudonym_mappings
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recipient_pseudonym_mappings cannot be deleted (GDPR erasure = pseudonymization + retention downgrade, never deletion)';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS approval_decisions (
  approval_decision_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  approval_request_id CHAR(36) NOT NULL,
  approver_operator_id CHAR(36) NOT NULL,
  access_evaluation_id CHAR(36),
  decision VARCHAR(32) NOT NULL,
  exact_payload_digest_at_decision VARCHAR(255) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  decision_reason_summary TEXT NOT NULL,
  decision_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  effective_at CHAR(24) NOT NULL,
  expires_at CHAR(24),
  PRIMARY KEY (approval_decision_id),
  CHECK (decision IN ('APPROVE_EXACT_ARTIFACT', 'DENY', 'REQUEST_REVISION', 'REVOKE_BEFORE_EXECUTION')),
  CONSTRAINT fk_approval_decisions_approval_request_id FOREIGN KEY (approval_request_id) REFERENCES approval_requests (approval_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_approval_decisions_request ON approval_decisions (approval_request_id, decision, effective_at);

DROP TRIGGER IF EXISTS trg_approval_decisions_no_update;
DELIMITER $$
CREATE TRIGGER trg_approval_decisions_no_update
  BEFORE UPDATE ON approval_decisions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval_decisions is immutable';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_approval_decisions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_approval_decisions_no_delete
  BEFORE DELETE ON approval_decisions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval_decisions cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS frozen_action_artifacts (
  artifact_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  approval_request_id CHAR(36) NOT NULL,
  approval_decision_id CHAR(36) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  action_category VARCHAR(255) NOT NULL,
  adapter_type VARCHAR(32) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  governed_tool_operation_id CHAR(36) NOT NULL,
  exact_normalized_payload_reference_safe VARCHAR(255) NOT NULL,
  exact_normalized_payload_digest CHAR(64) NOT NULL,
  artifact_hash CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  destination_or_scope_summary TEXT NOT NULL,
  effect_summary TEXT NOT NULL,
  permitted_execution_environment VARCHAR(32) NOT NULL,
  single_use_required TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL,
  frozen_at CHAR(24) NOT NULL,
  expires_at CHAR(24),
  PRIMARY KEY (artifact_id),
  CHECK (action_category = 'HARMLESS_DEMO_ACTION'),
  CHECK (adapter_type IN ('NO_OP_TEST_EVENT', 'INTERNAL_DEMO_NOTIFICATION', 'CONTROLLED_LOCAL_TEST_OUTPUT')),
  CHECK (permitted_execution_environment IN ('LOCAL_DEV', 'TEST', 'DEMO')),
  CHECK (single_use_required = 1),
  CHECK (status IN ('FROZEN', 'READY_FOR_AUTHORIZATION', 'AUTHORIZED', 'CONSUMED', 'EXPIRED', 'REVOKED', 'INTEGRITY_FAILED', 'EXECUTION_FAILED', 'OUTCOME_UNKNOWN', 'COMPLETED')),
  CONSTRAINT fk_frozen_action_artifacts_approval_request_id FOREIGN KEY (approval_request_id) REFERENCES approval_requests (approval_request_id),
  CONSTRAINT fk_frozen_action_artifacts_approval_decision_id FOREIGN KEY (approval_decision_id) REFERENCES approval_decisions (approval_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_frozen_action_artifacts_hash ON frozen_action_artifacts (artifact_hash);

CREATE INDEX ix_frozen_action_artifacts_request ON frozen_action_artifacts (approval_request_id, status);

DROP TRIGGER IF EXISTS trg_frozen_action_artifacts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_frozen_action_artifacts_no_delete
  BEFORE DELETE ON frozen_action_artifacts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'frozen_action_artifacts cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS frozen_outreach_artifacts (
  frozen_outreach_artifact_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  recipient_pseudonym_ref VARCHAR(255) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  payload_kind VARCHAR(32) NOT NULL,
  source_decision_record_id CHAR(36) NOT NULL,
  consent_registry_entry_id CHAR(36) NOT NULL,
  approval_request_id CHAR(36) NOT NULL,
  approval_decision_id CHAR(36) NOT NULL,
  exact_payload_reference_safe VARCHAR(255) NOT NULL,
  payload_digest CHAR(64) NOT NULL,
  hash_algorithm VARCHAR(255) NOT NULL DEFAULT 'sha256',
  artifact_status VARCHAR(32) NOT NULL,
  frozen_at CHAR(24) NOT NULL,
  expires_at CHAR(24),
  PRIMARY KEY (frozen_outreach_artifact_id),
  CHECK (channel IN ('EMAIL', 'SMS', 'PUSH')),
  CHECK (payload_kind IN ('SMS_BODY', 'EMAIL_SUBJECT_BODY', 'PUSH_TITLE_BODY')),
  CHECK (artifact_status IN ('FROZEN', 'AUTHORIZED', 'CONSUMED', 'EXPIRED', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_frozen_outreach_artifacts_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_frozen_outreach_artifacts_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_frozen_outreach_artifacts_source_decision_record_id FOREIGN KEY (source_decision_record_id) REFERENCES decision_records (decision_record_id),
  CONSTRAINT fk_frozen_outreach_artifacts_consent_registry_entry_id FOREIGN KEY (consent_registry_entry_id) REFERENCES consent_registry (consent_registry_entry_id),
  CONSTRAINT fk_frozen_outreach_artifacts_approval_request_id FOREIGN KEY (approval_request_id) REFERENCES approval_requests (approval_request_id),
  CONSTRAINT fk_frozen_outreach_artifacts_approval_decision_id FOREIGN KEY (approval_decision_id) REFERENCES approval_decisions (approval_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_frozen_outreach_artifacts_scope ON frozen_outreach_artifacts (tenant_id, workspace_id, artifact_status);

CREATE UNIQUE INDEX ux_frozen_outreach_artifacts_approval_decision ON frozen_outreach_artifacts (approval_decision_id);

DROP TRIGGER IF EXISTS trg_frozen_outreach_artifacts_status_forward;
DELIMITER $$
CREATE TRIGGER trg_frozen_outreach_artifacts_status_forward
  BEFORE UPDATE ON frozen_outreach_artifacts
  FOR EACH ROW
BEGIN
  -- canonical guard: NOT ( OLD.artifact_status = NEW.artifact_status OR (OLD.artifact_status = 'FROZEN' AND NEW.artifact_status IN ('AUTHORIZED', 'EXPIRED', 'REVOKED', 'SUPERSEDED')) OR (OLD.artifact_status = 'AUTHORIZED' AND NEW.artifact_status IN ('CONSUMED', 'EXPIRED', 'REVOKED', 'SUPERSEDED')) )
  IF NOT ( OLD.artifact_status = NEW.artifact_status OR (OLD.artifact_status = 'FROZEN' AND NEW.artifact_status IN ('AUTHORIZED', 'EXPIRED', 'REVOKED', 'SUPERSEDED')) OR (OLD.artifact_status = 'AUTHORIZED' AND NEW.artifact_status IN ('CONSUMED', 'EXPIRED', 'REVOKED', 'SUPERSEDED')) ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'frozen_outreach_artifacts status cannot move backward or reset';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_frozen_outreach_artifacts_no_delete;
DELIMITER $$
CREATE TRIGGER trg_frozen_outreach_artifacts_no_delete
  BEFORE DELETE ON frozen_outreach_artifacts
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'frozen_outreach_artifacts cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS outreach_channel_binding_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  outreach_channel_binding_id CHAR(36) NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_outreach_channel_binding_status_transitions_outreach_channel_ FOREIGN KEY (outreach_channel_binding_id) REFERENCES outreach_channel_bindings (outreach_channel_binding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_outreach_channel_binding_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_outreach_channel_binding_status_transitions_no_update
  BEFORE UPDATE ON outreach_channel_binding_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_channel_binding_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_outreach_channel_binding_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_outreach_channel_binding_status_transitions_no_delete
  BEFORE DELETE ON outreach_channel_binding_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_channel_binding_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS outreach_execution_authorizations (
  outreach_execution_authorization_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  frozen_outreach_artifact_id CHAR(36) NOT NULL,
  approval_decision_id CHAR(36) NOT NULL,
  single_use_required TINYINT(1) NOT NULL DEFAULT 1,
  authorization_status VARCHAR(32) NOT NULL,
  consumed_at CHAR(24),
  issued_at CHAR(24) NOT NULL,
  expires_at CHAR(24) NOT NULL,
  PRIMARY KEY (outreach_execution_authorization_id),
  CHECK (single_use_required = 1),
  CHECK (authorization_status IN ('ISSUED', 'CONSUMED', 'EXPIRED', 'REVOKED')),
  CONSTRAINT fk_outreach_execution_authorizations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_outreach_execution_authorizations_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_outreach_execution_authorizations_frozen_outreach_artifact_id FOREIGN KEY (frozen_outreach_artifact_id) REFERENCES frozen_outreach_artifacts (frozen_outreach_artifact_id),
  CONSTRAINT fk_outreach_execution_authorizations_approval_decision_id FOREIGN KEY (approval_decision_id) REFERENCES approval_decisions (approval_decision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_outreach_execution_authorizations_artifact ON outreach_execution_authorizations (frozen_outreach_artifact_id, authorization_status);

CREATE UNIQUE INDEX ux_outreach_execution_authorizations_artifact ON outreach_execution_authorizations (frozen_outreach_artifact_id);

DROP TRIGGER IF EXISTS trg_outreach_execution_authorizations_status_forward;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_authorizations_status_forward
  BEFORE UPDATE ON outreach_execution_authorizations
  FOR EACH ROW
BEGIN
  -- canonical guard: NOT ( OLD.authorization_status = NEW.authorization_status OR (OLD.authorization_status = 'ISSUED' AND NEW.authorization_status IN ('CONSUMED', 'EXPIRED', 'REVOKED')) )
  IF NOT ( OLD.authorization_status = NEW.authorization_status OR (OLD.authorization_status = 'ISSUED' AND NEW.authorization_status IN ('CONSUMED', 'EXPIRED', 'REVOKED')) ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_authorizations status cannot move backward or reset';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_outreach_execution_authorizations_no_delete;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_authorizations_no_delete
  BEFORE DELETE ON outreach_execution_authorizations
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_authorizations cannot be deleted';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS outreach_execution_proposal_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  outreach_execution_proposal_id CHAR(36) NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_outreach_execution_proposal_status_transitions_outreach_execu FOREIGN KEY (outreach_execution_proposal_id) REFERENCES outreach_execution_proposals (outreach_execution_proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_outreach_execution_proposal_status_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_proposal_status_transitions_no_update
  BEFORE UPDATE ON outreach_execution_proposal_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_proposal_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_outreach_execution_proposal_status_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_outreach_execution_proposal_status_transitions_no_delete
  BEFORE DELETE ON outreach_execution_proposal_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'outreach_execution_proposal_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS delivery_outcome_records (
  delivery_outcome_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  outreach_execution_authorization_id CHAR(36) NOT NULL,
  frozen_outreach_artifact_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  delivery_outcome VARCHAR(32) NOT NULL,
  outcome_detail_safe VARCHAR(255),
  recorded_at CHAR(24) NOT NULL,
  PRIMARY KEY (delivery_outcome_record_id),
  CHECK (delivery_outcome IN ('DELIVERED', 'FAILED', 'UNKNOWN')),
  CONSTRAINT fk_delivery_outcome_records_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_delivery_outcome_records_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_delivery_outcome_records_outreach_execution_authorization_id FOREIGN KEY (outreach_execution_authorization_id) REFERENCES outreach_execution_authorizations (outreach_execution_authorization_id),
  CONSTRAINT fk_delivery_outcome_records_frozen_outreach_artifact_id FOREIGN KEY (frozen_outreach_artifact_id) REFERENCES frozen_outreach_artifacts (frozen_outreach_artifact_id),
  CONSTRAINT fk_delivery_outcome_records_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_delivery_outcome_records_scope ON delivery_outcome_records (tenant_id, workspace_id, delivery_outcome);

CREATE UNIQUE INDEX ux_delivery_outcome_records_authorization ON delivery_outcome_records (outreach_execution_authorization_id);

DROP TRIGGER IF EXISTS trg_delivery_outcome_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_delivery_outcome_records_no_update
  BEFORE UPDATE ON delivery_outcome_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delivery_outcome_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_delivery_outcome_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_delivery_outcome_records_no_delete
  BEFORE DELETE ON delivery_outcome_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delivery_outcome_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS execution_authorizations (
  execution_authorization_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  proposal_id CHAR(36) NOT NULL,
  approval_request_id CHAR(36) NOT NULL,
  approval_decision_id CHAR(36) NOT NULL,
  artifact_id CHAR(36) NOT NULL,
  artifact_hash CHAR(64) NOT NULL,
  exact_payload_digest CHAR(64) NOT NULL,
  adapter_type VARCHAR(255) NOT NULL,
  governed_tool_id CHAR(36) NOT NULL,
  governed_tool_operation_id CHAR(36) NOT NULL,
  authorized_action_class VARCHAR(255) NOT NULL DEFAULT 'EXECUTE_CONSEQUENTIAL_ACTION',
  permitted_execution_environment VARCHAR(32) NOT NULL,
  permitted_destination_or_scope_summary TEXT NOT NULL,
  integrity_verification_id CHAR(36) NOT NULL,
  authorization_type VARCHAR(255) NOT NULL DEFAULT 'SINGLE_USE_EXACT_ARTIFACT',
  status VARCHAR(32) NOT NULL,
  valid_from CHAR(24) NOT NULL,
  valid_until CHAR(24) NOT NULL,
  idempotency_binding_key VARCHAR(191) NOT NULL,
  issued_at CHAR(24) NOT NULL,
  consumed_at CHAR(24),
  PRIMARY KEY (execution_authorization_id),
  CHECK (authorized_action_class = 'EXECUTE_CONSEQUENTIAL_ACTION'),
  CHECK (permitted_execution_environment IN ('LOCAL_DEV', 'TEST', 'DEMO')),
  CHECK (authorization_type = 'SINGLE_USE_EXACT_ARTIFACT'),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'RESERVED_FOR_DISPATCH', 'CONSUMED', 'EXPIRED', 'REVOKED', 'BLOCKED', 'OUTCOME_UNKNOWN')),
  CONSTRAINT fk_execution_authorizations_approval_request_id FOREIGN KEY (approval_request_id) REFERENCES approval_requests (approval_request_id),
  CONSTRAINT fk_execution_authorizations_approval_decision_id FOREIGN KEY (approval_decision_id) REFERENCES approval_decisions (approval_decision_id),
  CONSTRAINT fk_execution_authorizations_artifact_id FOREIGN KEY (artifact_id) REFERENCES frozen_action_artifacts (artifact_id),
  CONSTRAINT fk_execution_authorizations_integrity_verification_id FOREIGN KEY (integrity_verification_id) REFERENCES artifact_integrity_verifications (artifact_integrity_verification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_execution_authorizations_idempotency ON execution_authorizations (idempotency_binding_key);

CREATE INDEX ix_execution_authorizations_artifact ON execution_authorizations (artifact_id, status, valid_until);

CREATE TABLE IF NOT EXISTS action_execution_acknowledgements (
  action_execution_acknowledgement_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  approval_request_id CHAR(36) NOT NULL,
  approval_decision_id CHAR(36) NOT NULL,
  artifact_id CHAR(36) NOT NULL,
  execution_authorization_id CHAR(36) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_authorization_decision_id CHAR(36),
  tool_dispatch_id CHAR(36),
  tool_execution_outcome_id CHAR(36),
  runtime_host_execution_ref VARCHAR(255),
  acknowledged_outcome VARCHAR(32) NOT NULL,
  side_effect_uncertainty INT NOT NULL DEFAULT 0,
  reconciliation_required INT NOT NULL DEFAULT 0,
  acknowledged_at CHAR(24) NOT NULL,
  PRIMARY KEY (action_execution_acknowledgement_id),
  CHECK (acknowledged_outcome IN ('SUCCEEDED', 'FAILED', 'UNKNOWN', 'REJECTED_INTEGRITY_FAILURE', 'REJECTED_AUTHORITY_FAILURE')),
  CONSTRAINT fk_action_execution_acknowledgements_approval_request_id FOREIGN KEY (approval_request_id) REFERENCES approval_requests (approval_request_id),
  CONSTRAINT fk_action_execution_acknowledgements_artifact_id FOREIGN KEY (artifact_id) REFERENCES frozen_action_artifacts (artifact_id),
  CONSTRAINT fk_action_execution_acknowledgements_execution_authorization_id FOREIGN KEY (execution_authorization_id) REFERENCES execution_authorizations (execution_authorization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_action_execution_acknowledgements_artifact ON action_execution_acknowledgements (artifact_id, execution_authorization_id, acknowledged_at);

DROP TRIGGER IF EXISTS trg_action_execution_acknowledgements_no_update;
DELIMITER $$
CREATE TRIGGER trg_action_execution_acknowledgements_no_update
  BEFORE UPDATE ON action_execution_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'action_execution_acknowledgements is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_action_execution_acknowledgements_no_delete;
DELIMITER $$
CREATE TRIGGER trg_action_execution_acknowledgements_no_delete
  BEFORE DELETE ON action_execution_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'action_execution_acknowledgements is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS execution_authorization_consumptions (
  execution_authorization_consumption_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  execution_authorization_id CHAR(36) NOT NULL,
  tool_request_id CHAR(36) NOT NULL,
  tool_dispatch_id CHAR(36),
  tool_execution_outcome_id CHAR(36),
  runtime_host_execution_ref VARCHAR(255),
  reserved_at CHAR(24),
  consumed_at CHAR(24) NOT NULL,
  PRIMARY KEY (execution_authorization_consumption_id),
  CONSTRAINT fk_execution_authorization_consumptions_execution_authorization_ FOREIGN KEY (execution_authorization_id) REFERENCES execution_authorizations (execution_authorization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_execution_authorization_consumptions_auth ON execution_authorization_consumptions (execution_authorization_id);

DROP TRIGGER IF EXISTS trg_execution_authorization_consumptions_no_update;
DELIMITER $$
CREATE TRIGGER trg_execution_authorization_consumptions_no_update
  BEFORE UPDATE ON execution_authorization_consumptions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution_authorization_consumptions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_execution_authorization_consumptions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_execution_authorization_consumptions_no_delete
  BEFORE DELETE ON execution_authorization_consumptions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'execution_authorization_consumptions is append-only';
END$$
DELIMITER ;
