-- Canonical PR368: exact setup replay; no adoption or mutation of pre-existing registrations.
CREATE TABLE IF NOT EXISTS p01_registration_receipts (
    tenant_id CHAR(36) NOT NULL,
    workspace_id CHAR(36) NOT NULL,
    tool_key VARCHAR(191) NOT NULL,
    input_digest CHAR(64) NOT NULL,
    outcome_json LONGTEXT NOT NULL,
    created_at CHAR(24) NOT NULL,
    PRIMARY KEY (tenant_id, workspace_id, tool_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
    FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER trg_p01_registration_receipts_no_update BEFORE UPDATE ON p01_registration_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='p01_registration_receipts is append-only';
CREATE TRIGGER trg_p01_registration_receipts_no_delete BEFORE DELETE ON p01_registration_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='p01_registration_receipts is append-only';
