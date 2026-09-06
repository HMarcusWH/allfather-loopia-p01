-- M10 WRITER PROVENANCE: a service identity is a name, not a row id.
--
-- Canonical (packages/allfather-control-substrate/src/db/schema.ts
-- @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d):
--
--   recorded_by_service_identity_id  TEXT NOT NULL,
--
-- Migration 010 declared it CHAR(36) — the width of a UUID, and exactly the width of a
-- decision_record_id. That was not a coincidence. The port's generic append defaulted the writer
-- to `$recordId` when the caller supplied none, so the only values the column had ever been asked
-- to hold WERE record ids, and a UUID-shaped column fit them perfectly. The schema had been shaped
-- around the defect.
--
-- The canonical writers are namespaced service names — `service:m10-decision-ledger`,
-- `service:p07-crm-delivery`, `service:m11-replay`. The three that this port uses happen to be 27,
-- 24 and 18 characters and would have fitted; a longer one would not, and under STRICT_ALL_TABLES
-- it would have been a hard insert failure on a governed append rather than a truncation. Either
-- way the column was declaring "this holds a UUID" about a field that holds no UUID.
--
-- TEXT here matches canonical exactly, as `severity` and `hash_algorithm` already do in this same
-- table. The column stays NOT NULL: upstream requires a writer and so does this port, now as a
-- required parameter on `DecisionLedger::append()` rather than as a default nobody supplied.
--
-- No backfill. Every row this widens is a row the old fallback wrote, and rewriting history to
-- make the ledger look like it always had correct provenance is the one repair an append-only
-- ledger must never accept. A pre-repair record says it was written by itself because it was, and
-- that is the truthful record of a defect that existed. Test databases are rebuilt from empty.

ALTER TABLE decision_records
  MODIFY COLUMN recorded_by_service_identity_id TEXT NOT NULL;
