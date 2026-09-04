-- 001_identity_scope
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


CREATE TABLE IF NOT EXISTS access_evaluation_records (
  access_evaluation_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36),
  actor_type VARCHAR(32) NOT NULL,
  actor_id CHAR(36) NOT NULL,
  requested_permission VARCHAR(255) NOT NULL,
  resource_type VARCHAR(255) NOT NULL,
  resource_id CHAR(36),
  applicable_policy_version VARCHAR(32),
  matched_role_ids TEXT NOT NULL DEFAULT '[]',
  decision VARCHAR(32) NOT NULL,
  reason_codes TEXT NOT NULL DEFAULT '[]',
  severity VARCHAR(255),
  incident_required INT NOT NULL DEFAULT 0,
  evaluated_at CHAR(24) NOT NULL,
  correlation_id CHAR(36),
  PRIMARY KEY (access_evaluation_id),
  CHECK (actor_type IN ('OPERATOR', 'SERVICE', 'VALKYRIE', 'SYSTEM')),
  CHECK (decision IN ('ALLOW', 'DENY', 'HOLD_FOR_REVIEW'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_access_eval_scope ON access_evaluation_records (tenant_id, workspace_id, decision, evaluated_at);

CREATE INDEX ix_access_eval_correlation ON access_evaluation_records (correlation_id);

DROP TRIGGER IF EXISTS trg_access_eval_no_update;
DELIMITER $$
CREATE TRIGGER trg_access_eval_no_update
  BEFORE UPDATE ON access_evaluation_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'access_evaluation_records is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_access_eval_no_delete;
DELIMITER $$
CREATE TRIGGER trg_access_eval_no_delete
  BEFORE DELETE ON access_evaluation_records
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'access_evaluation_records is append-only';
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS data_scopes (
  data_scope_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  capability_contract_id CHAR(36) NOT NULL,
  scope_type VARCHAR(32) NOT NULL,
  scope_description TEXT NOT NULL,
  permitted_source_types TEXT NOT NULL DEFAULT '[]',
  prohibited_data_classes TEXT NOT NULL DEFAULT '[]',
  permitted_use TEXT NOT NULL DEFAULT '[]',
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (data_scope_id),
  CHECK (scope_type IN ('PERMITTED_DOCUMENTS', 'PERMITTED_PUBLIC_RESOURCES', 'MISSION_SUBMITTED_EVIDENCE', 'SYNTHETIC_TEST_FIXTURES', 'INTERNAL_DEMO_RECORDS')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SUPERSEDED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_data_scopes_contract ON data_scopes (capability_contract_id);

CREATE TABLE IF NOT EXISTS permissions (
  permission_key VARCHAR(191) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255),
  description VARCHAR(255),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  role_id CHAR(36) NOT NULL,
  role_key VARCHAR(191) NOT NULL,
  role_version INT NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  purpose_summary TEXT,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (role_id),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUPERSEDED', 'SUSPENDED', 'REVOKED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_roles_key_version ON roles (role_key, role_version);

CREATE TABLE IF NOT EXISTS subscription_tiers (
  subscription_tier_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tier_key VARCHAR(191) NOT NULL,
  tier_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  monthly_fee_minor_units INT NOT NULL DEFAULT 0,
  currency VARCHAR(255) NOT NULL DEFAULT 'SEK',
  billing_period VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  activated_at CHAR(24),
  PRIMARY KEY (subscription_tier_id),
  CHECK (billing_period IN ('MONTHLY', 'ANNUAL')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'SUPERSEDED', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_subscription_tiers_key_version ON subscription_tiers (tier_key, tier_version);

-- PARTIAL INDEX (MariaDB has no partial index): ux_subscription_tiers_one_active
--   canonical: CREATE UNIQUE INDEX ... ON subscription_tiers (tier_key)
--   predicate: WHERE status = 'ACTIVE'
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_subscription_tiers_one_active_np ON subscription_tiers (tier_key);

CREATE TABLE IF NOT EXISTS tenants (
  tenant_id CHAR(36) NOT NULL,
  tenant_key VARCHAR(191) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  tenant_type VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  data_boundary_summary TEXT,
  created_at CHAR(24) NOT NULL,
  created_by_operator_id CHAR(36),
  PRIMARY KEY (tenant_id),
  CHECK (tenant_type IN ('INTERNAL_DEMO_OWNER', 'TEST', 'FUTURE_RESERVED')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tenants_tenant_key ON tenants (tenant_key);

CREATE TABLE IF NOT EXISTS workspaces (
  workspace_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_key VARCHAR(191) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  workspace_type VARCHAR(32) NOT NULL,
  environment_class VARCHAR(32) NOT NULL,
  external_side_effects_allowed TINYINT(1) NOT NULL DEFAULT 0,
  active_v1_capability_ceiling VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  created_by_operator_id CHAR(36),
  PRIMARY KEY (workspace_id),
  CHECK (workspace_type IN ('DEMO', 'INTERNAL', 'TEST')),
  CHECK (environment_class IN ('LOCAL_DEV', 'TEST', 'DEMO')),
  CHECK (external_side_effects_allowed = 0),
  CHECK (status IN ('ACTIVE', 'SUSPENDED', 'CLOSED')),
  CONSTRAINT fk_workspaces_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_workspaces_tenant_key ON workspaces (tenant_id, workspace_key);

CREATE TABLE IF NOT EXISTS operators (
  operator_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  external_identity_ref VARCHAR(255),
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  last_reviewed_at CHAR(24),
  PRIMARY KEY (operator_id),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'RETIRED')),
  CONSTRAINT fk_operators_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_operators_external_identity_ref ON operators (external_identity_ref);

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id CHAR(36) NOT NULL,
  permission_key VARCHAR(191) NOT NULL,
  PRIMARY KEY (role_id, permission_key),
  CONSTRAINT fk_role_permissions_role_id FOREIGN KEY (role_id) REFERENCES roles (role_id),
  CONSTRAINT fk_role_permissions_permission_key FOREIGN KEY (permission_key) REFERENCES permissions (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_identities (
  service_identity_id CHAR(36) NOT NULL,
  tenant_id CHAR(36),
  schema_version VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  service_key VARCHAR(191) NOT NULL,
  service_purpose VARCHAR(255),
  service_class VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (service_identity_id),
  CHECK (service_class IN ('M01_HOST_ADAPTER', 'EVENT_PROJECTOR', 'M10_LEDGER_WRITER', 'M11_REPLAY_RUNNER', 'SYSTEM_SCHEDULER', 'OTHER_DECLARED')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'RETIRED')),
  CONSTRAINT fk_service_identities_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_service_identities_service_key ON service_identities (service_key);

CREATE TABLE IF NOT EXISTS subscription_tier_permissions (
  subscription_tier_id CHAR(36) NOT NULL,
  permission_key VARCHAR(191) NOT NULL,
  PRIMARY KEY (subscription_tier_id, permission_key),
  CONSTRAINT fk_subscription_tier_permissions_subscription_tier_id FOREIGN KEY (subscription_tier_id) REFERENCES subscription_tiers (subscription_tier_id),
  CONSTRAINT fk_subscription_tier_permissions_permission_key FOREIGN KEY (permission_key) REFERENCES permissions (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  tenant_subscription_id CHAR(36) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  subscription_tier_id CHAR(36) NOT NULL,
  status VARCHAR(32) NOT NULL,
  started_at CHAR(24) NOT NULL,
  current_period_start VARCHAR(255),
  current_period_end VARCHAR(255),
  PRIMARY KEY (tenant_subscription_id),
  CHECK (status IN ('TRIAL', 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED')),
  CONSTRAINT fk_tenant_subscriptions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_tenant_subscriptions_subscription_tier_id FOREIGN KEY (subscription_tier_id) REFERENCES subscription_tiers (subscription_tier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL INDEX (MariaDB has no partial index): ux_tenant_subscriptions_one_live
--   canonical: CREATE UNIQUE INDEX ... ON tenant_subscriptions (tenant_id)
--   predicate: WHERE status IN ('TRIAL', 'ACTIVE')
--   The predicate is a real invariant, so it is NOT downgraded to a plain
--   unique index. It is enforced in the application layer and proved in
--   tests/integration/. The index below serves the lookup path only.
CREATE INDEX ux_tenant_subscriptions_one_live_np ON tenant_subscriptions (tenant_id);

CREATE TABLE IF NOT EXISTS workspace_memberships (
  membership_id CHAR(36) NOT NULL,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  principal_type VARCHAR(32) NOT NULL,
  principal_id CHAR(36) NOT NULL,
  role_id CHAR(36) NOT NULL,
  role_version INT NOT NULL,
  status VARCHAR(32) NOT NULL,
  valid_from CHAR(24) NOT NULL,
  valid_until CHAR(24),
  created_at CHAR(24) NOT NULL,
  PRIMARY KEY (membership_id),
  CHECK (principal_type IN ('OPERATOR', 'SERVICE')),
  CHECK (status IN ('DRAFT', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'EXPIRED')),
  CONSTRAINT fk_workspace_memberships_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_workspace_memberships_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id),
  CONSTRAINT fk_workspace_memberships_role_id FOREIGN KEY (role_id) REFERENCES roles (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_workspace_memberships_lookup ON workspace_memberships (workspace_id, principal_type, principal_id, status);
