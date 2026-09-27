-- Monitor completion P04: repeatable mission setup receipts.
--
-- A receipt records what one reviewed setup plan did: which objects it reused, which it provisioned
-- through their existing owners (M03/M04/M06/P01/P04), and which frozen mission configuration it
-- produced. It is evidence of a setup act, not authority: nothing reads a receipt to decide whether
-- a capture, model call or release may happen. A changed request never rewrites a receipt or a
-- configuration; it appends a numbered successor that names its predecessor.

CREATE TABLE IF NOT EXISTS monitor_setup_receipts (
  setup_receipt_id CHAR(36) NOT NULL PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  workspace_id CHAR(36) NOT NULL,
  setup_key VARCHAR(64) NOT NULL,
  revision INT NOT NULL,
  predecessor_receipt_id CHAR(36),
  request_digest CHAR(64) NOT NULL,
  plan_digest CHAR(64) NOT NULL,
  mission_id CHAR(36) NOT NULL,
  definition_digest CHAR(64) NOT NULL,
  outcome_json LONGTEXT NOT NULL,
  applied_by_operator_id CHAR(36) NOT NULL,
  created_at CHAR(24) NOT NULL,
  UNIQUE KEY uq_setup_revision (tenant_id, workspace_id, setup_key, revision),
  UNIQUE KEY uq_setup_mission (mission_id),
  UNIQUE KEY uq_setup_predecessor (predecessor_receipt_id),
  CHECK (JSON_VALID(outcome_json)),
  CHECK (revision >= 1),
  CHECK ((revision = 1 AND predecessor_receipt_id IS NULL) OR (revision > 1 AND predecessor_receipt_id IS NOT NULL)),
  CONSTRAINT fk_setup_receipt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
  CONSTRAINT fk_setup_receipt_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(workspace_id),
  CONSTRAINT fk_setup_receipt_mission FOREIGN KEY (mission_id) REFERENCES missions(mission_id),
  CONSTRAINT fk_setup_receipt_predecessor FOREIGN KEY (predecessor_receipt_id) REFERENCES monitor_setup_receipts(setup_receipt_id),
  CONSTRAINT fk_setup_receipt_operator FOREIGN KEY (applied_by_operator_id) REFERENCES operators(operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_setup_receipts_no_update BEFORE UPDATE ON monitor_setup_receipts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'setup receipts are append-only'; END$$
CREATE TRIGGER trg_setup_receipts_no_delete BEFORE DELETE ON monitor_setup_receipts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'setup receipts are append-only'; END$$
DELIMITER ;
