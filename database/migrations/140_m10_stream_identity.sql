-- M10 ACTIVE-STREAM IDENTITY: the canonical partial unique index, actually enforced.
--
-- Canonical (packages/allfather-control-substrate/src/db/schema.ts
-- @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d):
--
--   CREATE UNIQUE INDEX ux_decision_ledger_streams_active_scope
--     ON decision_ledger_streams (tenant_id, workspace_id, environment_class)
--     WHERE status = 'ACTIVE';
--
-- Migration 010 documented that index and installed a NON-UNIQUE lookup index in its place,
-- following this repository's standing translation for partial indexes: document the canonical
-- rule, serve the lookup, enforce the predicate in the application, prove it in tests.
--
-- That translation is not good enough HERE, and the difference matters. The predicate governs
-- whether two competing ledger HEADS can exist for one scope. Application enforcement of it is a
-- read followed by a write, and between those two statements a concurrent caller can insert — so
-- the invariant held only as long as nothing raced. "At most one ACTIVE stream" was a claim about
-- timing, not a fact about the database.
--
-- MariaDB has no partial index, but it has an exact equivalent: a generated column that is NULL
-- for every row the predicate excludes, inside an otherwise ordinary UNIQUE index. NULLs are
-- distinct in a unique index, so non-ACTIVE rows never collide with each other or with anything
-- else, while ACTIVE rows collide exactly when tenant, workspace and environment repeat. It binds
-- on INSERT and on an UPDATE that moves a row INTO 'ACTIVE', which is the whole predicate.
--
-- Verified on MariaDB 10.6.28 and 10.11.14: two environments coexist, many non-ACTIVE rows
-- coexist, a second ACTIVE row in the same scope is rejected 1062, and reactivating a superseded
-- row into an occupied scope is rejected 1062.
--
-- The non-unique lookup index from 010 stays: it serves the find path, which is by
-- (tenant, workspace, environment) without the status column leading.

ALTER TABLE decision_ledger_streams
  ADD COLUMN active_scope_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;

CREATE UNIQUE INDEX ux_decision_ledger_streams_active_scope
  ON decision_ledger_streams (tenant_id, workspace_id, environment_class, active_scope_guard);
