-- 246_assurance_refs_width
--
-- P08 translation repair, same class as 245. Canonical `audit_runs.target_object_refs` and
-- `incident_evaluation_requests.related_object_refs` are SQLite TEXT, which has no practical length
-- limit. Migration 010 translated them to MariaDB TEXT, which holds at most 65,535 bytes. The P03
-- BUYER_INTENT_INVARIANT audit records EVERY violating queue item's id in both columns, so a scope
-- with more than about 1,680 violators — exactly the mass bypass that defence-in-depth audit exists
-- for — could not record its finding or open its incident at all under STRICT_ALL_TABLES; canonical
-- records both.
--
-- The repair widens both columns to LONGTEXT (MariaDB's JSON alias). It is data-preserving: every
-- TEXT value is the same LONGTEXT value; NOT NULL and the '[]' default are kept; no CHECK, index or
-- trigger references either column's width. Applied SQL in 010 is NOT edited. The in-place upgrade
-- of an existing projection is proved by tests/integration/p08_migration_order.php, and the at-scale
-- audit by tests/integration/p03_assurance_scale.php.

ALTER TABLE audit_runs
  MODIFY COLUMN target_object_refs LONGTEXT NOT NULL DEFAULT '[]';

ALTER TABLE incident_evaluation_requests
  MODIFY COLUMN related_object_refs LONGTEXT NOT NULL DEFAULT '[]';
