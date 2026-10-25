# LI-03 — scoped P01 capture-history read foundation

**Status: source-only/read-model foundation, 2026-10-25.** Not the completed R1 observation/materiality model, not M17, and not an authorized customer or browser endpoint.

`app/Product/P01/ObservationHistory.php` reuses the existing `competitors`, `monitored_sources`, `source_snapshots`, `source_diffs` and M05 `evidence_objects`. It creates no schema, evidence, captured body, model assessment, schedule or release. It requires BOTH M02 `competition_monitoring.read` and `evidence.read`, plus the existing P01 commercial read entitlement. Every page re-checks current approved source cards, M05 reference status, source sensitivity, derived internal-use retention and evidence status. Foreign identifiers are not enumerable.

It returns stable, bounded, cursor-paginated capture metadata with exact source snapshot/diff reference IDs. Baselines, missing structural comparisons and structural change classes remain separate. A `MINOR` or `MATERIAL` **structural** diff is never represented as business materiality: that stays `NOT_ASSESSED`; derived observations, human reviews and releases remain explicitly unavailable.

**Deliberate stop gates:** U01/U02/U03 are still proposals. No durable observation assessments, candidate text reuse, full historical provider disclosure, origin independence, semantic rollup, customer-facing UI or new GET is enabled until its owner contracts and security/read checks are accepted. Paused sources or revoked/expired cards conservatively withhold individual history rows.

**Qualification required:** `php tests/run.php --layer=integration --filter=li03_observation_history --strict` on disposable MariaDB 10.6 and 10.11; full strict regression on exact combined release before merge. M05 and P01 source counts must not be modified by reading. Existing 31 migrations (including historical 900) remain unchanged.
