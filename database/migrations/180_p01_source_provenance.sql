-- P01 source authority/provenance parity: canonical draft PRs 365 and 366.
-- Additive only. Historical NULL means unproved; no licence or classification is backfilled.
ALTER TABLE source_license_cards ADD COLUMN IF NOT EXISTS contract_reference_id TEXT NULL;
ALTER TABLE source_snapshots ADD COLUMN IF NOT EXISTS text_provenance_json LONGTEXT NULL;
ALTER TABLE source_diffs ADD COLUMN IF NOT EXISTS classifier_version VARCHAR(80) NULL;
ALTER TABLE source_diffs ADD COLUMN IF NOT EXISTS material_change_threshold DOUBLE NULL
    CHECK (material_change_threshold IS NULL OR (material_change_threshold >= 0 AND material_change_threshold <= 1));
