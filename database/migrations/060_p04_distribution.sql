-- 060_p04_distribution
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS role_distribution_briefings (
  role_distribution_briefing_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  target_org_role VARCHAR(32) NOT NULL,
  released_briefing_id CHAR(36),
  released_prospect_output_id CHAR(36),
  redaction_profile TEXT NOT NULL DEFAULT '{}',
  briefing_summary_safe VARCHAR(255) NOT NULL,
  proposal_id CHAR(36),
  arbiter_verdict_id CHAR(36),
  briefing_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (role_distribution_briefing_id),
  CHECK (target_org_role IN ('EXECUTIVE_CEO', 'EXECUTIVE_CFO', 'MARKETING_CMO', 'SALES', 'CUSTOMER_SUCCESS')),
  CHECK (briefing_status IN ('DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED', 'DISTRIBUTED', 'SUPERSEDED', 'BLOCKED')),
  CHECK (     (CASE WHEN released_briefing_id IS NOT NULL THEN 1 ELSE 0 END) +     (CASE WHEN released_prospect_output_id IS NOT NULL THEN 1 ELSE 0 END) = 1   ),
  CONSTRAINT fk_role_distribution_briefings_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_role_distribution_briefings_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_role_distribution_briefings_released_briefing_id FOREIGN KEY (released_briefing_id) REFERENCES released_briefings (released_briefing_id),
  CONSTRAINT fk_role_distribution_briefings_released_prospect_output_id FOREIGN KEY (released_prospect_output_id) REFERENCES released_prospect_outputs (released_prospect_output_id),
  CONSTRAINT fk_role_distribution_briefings_proposal_id FOREIGN KEY (proposal_id) REFERENCES proposal_envelopes (proposal_id),
  CONSTRAINT fk_role_distribution_briefings_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_role_distribution_briefings_scope ON role_distribution_briefings (tenant_id, workspace_id, target_org_role, briefing_status);

CREATE TABLE IF NOT EXISTS stakeholder_role_observations (
  stakeholder_role_observation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  subject_kind VARCHAR(32) NOT NULL,
  role_class_label VARCHAR(255) NOT NULL,
  subject_company_ref_safe VARCHAR(255) NOT NULL,
  competitor_id CHAR(36),
  source_ref_id CHAR(36) NOT NULL,
  basis_evidence_id CHAR(36),
  observation_status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (stakeholder_role_observation_id),
  CHECK (subject_kind IN ('ROLE_CLASS')),
  CHECK (observation_status IN ('DRAFT', 'ACTIVE', 'REVOKED', 'SUPERSEDED')),
  CONSTRAINT fk_stakeholder_role_observations_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_stakeholder_role_observations_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_stakeholder_role_observations_competitor_id FOREIGN KEY (competitor_id) REFERENCES competitors (competitor_id),
  CONSTRAINT fk_stakeholder_role_observations_source_ref_id FOREIGN KEY (source_ref_id) REFERENCES source_references (source_ref_id),
  CONSTRAINT fk_stakeholder_role_observations_basis_evidence_id FOREIGN KEY (basis_evidence_id) REFERENCES evidence_objects (evidence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_stakeholder_role_observations_scope ON stakeholder_role_observations (tenant_id, workspace_id, observation_status);

CREATE TABLE IF NOT EXISTS distribution_acknowledgements (
  distribution_acknowledgement_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  role_distribution_briefing_id CHAR(36) NOT NULL,
  ack_state VARCHAR(32) NOT NULL,
  is_delivery_assurance_only TINYINT(1) NOT NULL DEFAULT 1,
  acknowledged_by_role_seat VARCHAR(255) NOT NULL,
  acknowledged_at CHAR(24) NOT NULL,
  PRIMARY KEY (distribution_acknowledgement_id),
  CHECK (ack_state IN ('DELIVERED_TO_CONSOLE', 'VIEWED', 'ACKNOWLEDGED')),
  CHECK (is_delivery_assurance_only = 1),
  CONSTRAINT fk_distribution_acknowledgements_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_distribution_acknowledgements_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_distribution_acknowledgements_role_distribution_briefing_id FOREIGN KEY (role_distribution_briefing_id) REFERENCES role_distribution_briefings (role_distribution_briefing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_distribution_acknowledgements_briefing ON distribution_acknowledgements (role_distribution_briefing_id, ack_state);

DROP TRIGGER IF EXISTS trg_distribution_acknowledgements_no_update;
DELIMITER $$
CREATE TRIGGER trg_distribution_acknowledgements_no_update
  BEFORE UPDATE ON distribution_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_acknowledgements is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_distribution_acknowledgements_no_delete;
DELIMITER $$
CREATE TRIGGER trg_distribution_acknowledgements_no_delete
  BEFORE DELETE ON distribution_acknowledgements
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_acknowledgements is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS distribution_routing_records (
  distribution_routing_record_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  role_distribution_briefing_id CHAR(36) NOT NULL,
  target_org_role VARCHAR(32) NOT NULL,
  arbiter_verdict_id CHAR(36) NOT NULL,
  decision_record_id CHAR(36) NOT NULL,
  routed_at CHAR(24) NOT NULL,
  PRIMARY KEY (distribution_routing_record_id),
  CHECK (target_org_role IN ('EXECUTIVE_CEO', 'EXECUTIVE_CFO', 'MARKETING_CMO', 'SALES', 'CUSTOMER_SUCCESS')),
  CONSTRAINT fk_distribution_routing_records_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_distribution_routing_records_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_distribution_routing_records_role_distribution_briefing_id FOREIGN KEY (role_distribution_briefing_id) REFERENCES role_distribution_briefings (role_distribution_briefing_id),
  CONSTRAINT fk_distribution_routing_records_arbiter_verdict_id FOREIGN KEY (arbiter_verdict_id) REFERENCES arbiter_verdicts (arbiter_verdict_id),
  CONSTRAINT fk_distribution_routing_records_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_distribution_routing_records_briefing ON distribution_routing_records (role_distribution_briefing_id, target_org_role);

DROP TRIGGER IF EXISTS trg_distribution_routing_records_no_update;
DELIMITER $$
CREATE TRIGGER trg_distribution_routing_records_no_update
  BEFORE UPDATE ON distribution_routing_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_routing_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_distribution_routing_records_no_delete;
DELIMITER $$
CREATE TRIGGER trg_distribution_routing_records_no_delete
  BEFORE DELETE ON distribution_routing_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_routing_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS released_distribution_outputs (
  released_distribution_output_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  output_kind VARCHAR(32) NOT NULL,
  role_distribution_briefing_id CHAR(36),
  distribution_routing_record_id CHAR(36),
  decision_record_id CHAR(36) NOT NULL,
  released_at CHAR(24) NOT NULL,
  PRIMARY KEY (released_distribution_output_id),
  CHECK (output_kind IN ('ROLE_DISTRIBUTION_BRIEFING', 'DISTRIBUTION_ROUTING_RECORD')),
  CHECK (     (CASE WHEN role_distribution_briefing_id IS NOT NULL THEN 1 ELSE 0 END) +     (CASE WHEN distribution_routing_record_id IS NOT NULL THEN 1 ELSE 0 END) = 1   ),
  CONSTRAINT fk_released_distribution_outputs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_released_distribution_outputs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_released_distribution_outputs_role_distribution_briefing_id FOREIGN KEY (role_distribution_briefing_id) REFERENCES role_distribution_briefings (role_distribution_briefing_id),
  CONSTRAINT fk_released_distribution_outputs_distribution_routing_record_id FOREIGN KEY (distribution_routing_record_id) REFERENCES distribution_routing_records (distribution_routing_record_id),
  CONSTRAINT fk_released_distribution_outputs_decision_record_id FOREIGN KEY (decision_record_id) REFERENCES decision_records (decision_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_released_distribution_outputs_scope ON released_distribution_outputs (tenant_id, workspace_id, output_kind);

DROP TRIGGER IF EXISTS trg_released_distribution_outputs_no_update;
DELIMITER $$
CREATE TRIGGER trg_released_distribution_outputs_no_update
  BEFORE UPDATE ON released_distribution_outputs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_distribution_outputs is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_released_distribution_outputs_no_delete;
DELIMITER $$
CREATE TRIGGER trg_released_distribution_outputs_no_delete
  BEFORE DELETE ON released_distribution_outputs
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'released_distribution_outputs is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS role_distribution_briefing_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  role_distribution_briefing_id CHAR(36) NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_role_distribution_briefing_status_transitions_role_distributi FOREIGN KEY (role_distribution_briefing_id) REFERENCES role_distribution_briefings (role_distribution_briefing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_rdb_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_rdb_transitions_no_update
  BEFORE UPDATE ON role_distribution_briefing_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_distribution_briefing_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_rdb_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_rdb_transitions_no_delete
  BEFORE DELETE ON role_distribution_briefing_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_distribution_briefing_status_transitions is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS stakeholder_role_observation_status_transitions (
  transition_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  stakeholder_role_observation_id CHAR(36) NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  reason_code VARCHAR(255),
  transitioned_at CHAR(24) NOT NULL,
  PRIMARY KEY (transition_id),
  CONSTRAINT fk_stakeholder_role_observation_status_transitions_stakeholder_r FOREIGN KEY (stakeholder_role_observation_id) REFERENCES stakeholder_role_observations (stakeholder_role_observation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_sro_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_sro_transitions_no_update
  BEFORE UPDATE ON stakeholder_role_observation_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stakeholder_role_observation_status_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_sro_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_sro_transitions_no_delete
  BEFORE DELETE ON stakeholder_role_observation_status_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stakeholder_role_observation_status_transitions is append-only';
END$$
DELIMITER ;
