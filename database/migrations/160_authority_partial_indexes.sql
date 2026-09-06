-- THE PARTIAL UNIQUE INDEXES A RACE CAN USE TO WIDEN AUTHORITY.
--
-- Canonical declares 25 partial UNIQUE indexes (packages/allfather-control-substrate/src/db/schema.ts
-- @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d). Migration 010 and its siblings applied this
-- repository's standing translation to all of them: document the canonical rule, install a
-- non-unique lookup index (`_np`), enforce the predicate in the application, prove it in tests.
--
-- Migration 140 established that the standing translation is not adequate where the predicate
-- guards whether two competing governed heads can exist, because application enforcement of a
-- uniqueness predicate is a read followed by a write and two callers can both pass the read. It
-- promoted ONE authority-sensitive partial index to database enforcement:
-- `ux_decision_ledger_streams_active_scope`.
--
-- THIS MIGRATION IS THE REST OF THAT SENTENCE. Of the remaining 24 canonical partial UNIQUE
-- indexes, six more answer YES to the same question: if two rows exist where the predicate permits
-- one, can a caller end up with MORE AUTHORITY than the governed state grants? Those six are
-- enforced here. Eighteen retain the standing application/lookup translation or are unreachable
-- from the selected graph. Together with migration 140 that is 7 database-enforced
-- authority-sensitive translations out of 25; docs/SCHEMA_REACHABILITY.md records every case.
--
--   capability_contracts        The contract IS an agent's permitted action classes. Two ACTIVE
--                               contracts for one Valkyrie means its permissions depend on which
--                               row a query happens to return first.
--   output_visibility_profiles  The M16 audience ceiling. Two ACTIVE profiles for one audience
--   output_access_rules         class are two different answers to "what may this audience see?",
--                               and the wider one wins whenever it is read first.
--   tenant_subscriptions        The tier gate every product surface consults. Two live
--   subscription_tiers          subscriptions, or two ACTIVE definitions of one tier, grant
--                               whichever set is read.
--   arbiter_policy_sets         The rules an admission is evaluated against. Two ACTIVE sets for
--                               one scope means ALLOW or BLOCK depends on row order. This one had
--                               no index AT ALL in this port — not even the `_np` lookup index the
--                               standing translation calls for.
--
-- NOT enforced here, with the reason in each case: audit and replay profiles (an audit grants
-- nothing — `does_not_grant_authority` is CHECK-pinned to 1); the admission idempotency keys (a
-- race duplicates work, it does not widen authority); the P03 catalogue and ICP actives (internal
-- read models, gated downstream by M02 AND the tenant tier); the M15 ingestion actives (live read
-- is inert and nothing constructs the transport); and the indexes on tables outside the selected
-- graph entirely.
--
-- THE TRANSLATION is migration 140's, unchanged: MariaDB has no partial index but a generated
-- column that is NULL wherever the predicate excludes a row, inside an ordinary UNIQUE index, is
-- an exact equivalent. NULLs are distinct in a unique index, so excluded rows never collide while
-- included rows collide exactly when the scope repeats — and it binds on INSERT and on an UPDATE
-- that moves a row INTO the predicate, which is the half a hand-rolled check forgets.
--
-- The `_np` lookup indexes stay. They serve the find paths, which do not lead with `status`.
--
-- NO BACKFILL AND NO DEDUPLICATION. If an existing database already holds two ACTIVE rows in one
-- scope, this migration fails loudly on the index build rather than choosing which of two competing
-- authorities to retire. That choice is an operator's. Nothing is deployed
-- (`staging_deployed = false`), so no such database exists yet.

ALTER TABLE capability_contracts
  ADD COLUMN active_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_capability_contracts_one_active_per_valkyrie
  ON capability_contracts (valkyrie_id, active_guard);

ALTER TABLE output_visibility_profiles
  ADD COLUMN active_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_output_visibility_profiles_one_active
  ON output_visibility_profiles (tenant_id, audience_class, active_guard);

ALTER TABLE output_access_rules
  ADD COLUMN active_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_output_access_rules_one_active
  ON output_access_rules (tenant_id, audience_class, artifact_kind, active_guard);

-- 'live' is TRIAL or ACTIVE here, exactly as canonical's `WHERE status IN ('TRIAL', 'ACTIVE')`.
ALTER TABLE tenant_subscriptions
  ADD COLUMN live_guard TINYINT
    AS (CASE WHEN status IN ('TRIAL', 'ACTIVE') THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_tenant_subscriptions_one_live
  ON tenant_subscriptions (tenant_id, live_guard);

ALTER TABLE subscription_tiers
  ADD COLUMN active_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_subscription_tiers_one_active
  ON subscription_tiers (tier_key, active_guard);

-- Canonical keys this one by `COALESCE(tenant_id, '*'), COALESCE(workspace_id, '*'), policy_key`,
-- because a platform-wide policy set has NULL scope columns and two NULLs never collide in a unique
-- index.
--
-- MARIADB CANNOT INDEX THAT COALESCE, and the reason is worth stating rather than working around
-- quietly. A generated column may be indexed only if its expression is deterministic in MariaDB's
-- sense, and a STRING LITERAL inside one is not: the literal takes the connection's character set
-- while the column takes the table's, so the result's collation depends on who is connected. Every
-- spelling was tried against 10.11.19 — COALESCE, IFNULL, CASE, CAST, CONCAT, an explicit COLLATE
-- on the expression, an explicit COLLATE on the column, VIRTUAL and STORED — and all fifteen are
-- refused with error 1901. `CASE WHEN a IS NULL THEN b ELSE a END`, column to column with no
-- literal, is accepted, which is what identifies the literal as the cause.
--
-- SO THE SENTINEL IS DROPPED, AND THE ROWS IT EXISTED FOR CANNOT BE CREATED HERE. The only writer
-- of this table in the whole port is `M08\PolicySets::seed(array $definition, string $tenantId,
-- string $workspaceId)`, whose scope parameters are non-nullable `string`. A NULL-scoped policy set
-- is unreachable by construction, so the index below — which does not defend the NULL case —
-- defends every row this projection can produce. `tests/security/authority_uniqueness.php` asserts
-- the unreachability by scanning for writers rather than trusting this comment, because the day
-- someone adds a platform-wide seed is the day this index quietly stops covering everything.
ALTER TABLE arbiter_policy_sets
  ADD COLUMN active_guard TINYINT
    AS (CASE WHEN status = 'ACTIVE' THEN 1 ELSE NULL END) VIRTUAL;
CREATE UNIQUE INDEX ux_arbiter_policy_sets_scope_one_active
  ON arbiter_policy_sets (tenant_id, workspace_id, policy_key, active_guard);

-- The lookup index the standing translation calls for, which this table never received.
CREATE INDEX ux_arbiter_policy_sets_scope_one_active_np
  ON arbiter_policy_sets (tenant_id, workspace_id, policy_key, status);
