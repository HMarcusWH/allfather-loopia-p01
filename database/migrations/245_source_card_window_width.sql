-- 245_source_card_window_width
--
-- P08 translation repair. Canonical `source_license_cards.warn_after_ms`, `error_after_ms` and
-- `recheck_terms_after_ms` are SQLite INTEGER, which is 64-bit. Migration 020 translated them to
-- MariaDB INT, which is 32-bit signed: the largest storable window is 2,147,483,647 ms, about 24.86
-- days. Any freshness or terms-recheck window longer than that — a 30-day recheck is ordinary — was
-- unstorable here, so a card carrying one could not be projected at all and the P01/P03 freshness
-- audits could never be asked about it. The canonical P03 assurance parity vectors use 30-day
-- windows and exposed it.
--
-- The repair widens the three columns to BIGINT. It is data-preserving: every value an INT could hold
-- is the same BIGINT value and nullability is unchanged. The `>= 0` CHECK constraints migration 020
-- declares on these columns are table-level and keep applying to the widened columns.
-- Applied SQL in 020 is NOT edited; this successor migration carries the change. The in-place
-- upgrade of an existing projection is proved by tests/integration/p08_migration_order.php.
--
-- The same translation class also applies to `ingestion_fetch_plans.cadence_min_interval_ms`
-- (090). That column belongs to the inert M15 live-read plane, which no P08 path reads, so it is
-- recorded in docs/SCHEMA_REACHABILITY.md §6 rather than widened here without a consumer to prove it.

ALTER TABLE source_license_cards
  MODIFY COLUMN warn_after_ms BIGINT NULL,
  MODIFY COLUMN error_after_ms BIGINT NULL,
  MODIFY COLUMN recheck_terms_after_ms BIGINT NULL;
