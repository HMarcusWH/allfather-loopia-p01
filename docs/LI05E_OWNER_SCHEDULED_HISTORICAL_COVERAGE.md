# LI-05E — Owner-native P07 scheduled historical coverage

**Prepared:** 2026-10-27. **Status:** `SOURCE_ONLY / U03_NOT_ADOPTED / NOT_RUNTIME_ACTIVATED`.

This integration joins the existing P07 schedule authority and append-only occurrence ledger with the existing LI-05D P01/M05 original-capture verification and LI-05A four-clock basis selector. It introduces **no scheduler**, no new database schema, no model invocation, no migration and no customer output.

## Contracts

- `ScheduleHistoryCoverage` requires a **specific versioned schedule ID**, the exact M02 tenant/workspace actor, a competitor, a half-open UTC period and a known-at cutoff. It refuses unknown, foreign, expired, suspended or no-longer-admitted schedule versions through the real `ScheduleService::current` owner-decision and execution-grant checks.
- It requires exactly **one original monitored source** for the narrow P01 competitor projection, matching the schedule's owner-assigned source. Multi-source competitors are **HOLD** until a multi-source coverage composition is adopted.
- Expected slots derive from the immutable schedule's `valid_from` and cadence, not capture frequency. The last as-of slot is strictly **before** the knowledge cutoff; future reservations/results cannot rewrite a prior cutoff.
- `monitor_schedule_occurrences` and `monitor_schedule_occurrence_outcomes` supply immutable reserved, skipped, settled, refused and pending facts. `BASELINE_RECORDED`, `NO_CHANGE`, `WAITING_FOR_OPERATOR` map to `CAPTURED_BASELINE`, `CAPTURED_UNCHANGED`, `CAPTURED_CHANGED` only with a verified `RunJournal` event chain, the exact scheduled-run idempotency key, originating service principal, and a real P01 `SOURCE_CAPTURED` snapshot binding.
- `ObservationCoverage::summarize` is reused for exact `planned_slots`, captured/unchanged counts, `LATEST_ONLY` skipped slots, refused, pending and unreported coverage. Missing and failed slots are **not** counted as `NO_CHANGE`.
- `NativeCaptureHistoryCandidate` accepts an **optional, injected, typed** `ScheduleHistoryCoverage` owner reader and an optional exact schedule version. Its default **stays UNKNOWN** with no schedule reader. An explicitly requested schedule without the reader refuses.
- Even if slot accounting has no gaps, the basis is upgraded from `UNKNOWN` to `KNOWN` only if the **exact set** of original P01 snapshot IDs equals the independently byte-verified M05/P01 source captures known at the same cutoff. If identities disagree, the entire basis refuses, never silently omits other evidence.
- `KNOWN` here means **complete within this one exact schedule version and source scope at the supplied cutoff**, not independently corroborated real-world events, source rights, evidence freshness beyond that period, business materiality, M10 reliance or authorization to call a model.

The original LI-05A v1 basis serialization uses the legacy `CALLER_SUPPLIED_UNVERIFIED` coverage-origin label. LI-05E carries the additional owner-verified, separately versioned schedule receipt alongside the v1 basis without silently rewriting existing v1 digest semantics. No effective U03 permission or runtime evidence-disclosure grant is introduced.

## Gaps remain visible

`COMPLETE_OWNER_SLOT_COVERAGE` requires at least one planned slot, all planned slots successfully captured and pinned to original run/M05 references, no skipped/unreported/failed/refused/pending slots, and an exact match to the privately verified source identities.

`HOLD_GAPPED_OR_INCOMPLETE` retains an explicit schedule summary and assigns `coverage.state=UNKNOWN`. Missing, `LATEST_ONLY` skipped, backpressure, operator holds and source policy refusal may never imply absence of change.

Revoked or suspended schedule authority refuses the proof even for a previously captured history. This is intentionally stricter than proving an independently frozen historical grant was valid at an earlier date; that more general as-of contract is **not yet implemented**.

## CI acceptance

`tests/integration/li05e_schedule_backed_history_coverage.php` runs with real disposable MariaDB 10.6 and 10.11 and the existing `ScheduleFixture`:

1. Current owner-admitted schedule prior to first observed slot: not complete.
2. Real scheduled P01 baseline, changed and unchanged captures (M06 provenance, original M05 private bytes), including source-run/event chain and exact scope match.
3. As-of late capture changes immutable input digest, but not the original basis; the operator model is never called.
4. `LATEST_ONLY` outage skipping a slot causes `HOLD`, not an invented `NO_CHANGE`.
5. Pending reserved-but-unexecuted slot remains pending, not a success.
6. Prior cutoff excludes later outcomes/captures, and current schedule stand-down disables future coverage-read promotion.
7. Foreign and unregistered actor identity fail before source enumeration; zero provider attempts or new network fetches.

`tests/foundation/li05e_schedule_coverage_inert_boundary.php` pins the lack of HTTP or cron, source/provider execution and active 270/280/290 migrations.

## Operational gates still required

**U03** historical source-use and disclosure approval is not adopted. **U05** is not an authorized review-due scheduler. **U06** historical inference is not authorized. This patch does not install database tables or enable M17 persistence. A scheduled observation cannot act as a retention or customer-release grant.

For a live longitudinal E2E, the separate remaining work is owner-approved historical-use purposes, independently reviewed additive schema/migration and populated DB/volume restore, and then explicit operator-authenticated analysis admission, review and release. *Passing LI-05E only qualifies the exact source and schedule coverage surface.*
