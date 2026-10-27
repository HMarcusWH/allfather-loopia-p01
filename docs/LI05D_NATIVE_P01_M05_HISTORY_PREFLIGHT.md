# LI-05D — P01/M05 source-backed history preflight (U03 HOLD)

**Prepared 2026-10-27.** **Status: SOURCE-BACKED QA CANDIDATE — NOT ADMITTED.**

This slice addresses the gap between the previously green synthetic 270→280→290 E2E and actually authenticated P01/M05 capture provenance. It uses only existing P01 owner capture, native M06 tool receipts, M05 evidence, source-card/terms/robots policy and the original private content-addressed payload. No new rights are minted.

## New capability

`NativeCaptureHistoryCandidate::preview(actor,competitor,question,period,knowledgeCutoff,maxReferences)` is a read-only, bounded private composition. It:

- Demands current M02 `competition_monitoring.read` **and** `evidence.read` for an existing scoped competitor before querying snapshots.
- Enumerates up to 256 actual P01 owner snapshots and requires **every** one to pass the existing `HistoricalCaptureProbe` including current source status, licence/card/terms, M05 evidence, original successful M06 tool outcome, raw-artifact binding and byte digest. No live fetch or provider is invoked.
- Maintains separately proven `observed`, conservatively rounded `recorded`, `known_at` (upper bound), and requested historical cutoff. Milliseconds rounded upward for `recorded`/`known_at` prevent accidental backdating in the existing whole-second LI-05A selector.
- Passes exact typed M05/P01 identities and digest **without source body or URL** to the existing immutable `HistoryReviewBasis` selector.
- Retains **UNKNOWN** source coverage and origin independence, **UNKNOWN** semantic polarity, and null inferred business-event/assessment time. Two captured pages do not prove all scheduled observation slots or independent corroboration.
- Returns `INSUFFICIENT_BASIS` and `U03_historical_use_admitted=false` even when every native capture is authentically verified. This intentionally blocks `HistoryM17CandidateBridge` from persisting an admitted memory.
- Stops the whole proposed basis if any currently relevant owner source is revoked, its evidence quarantined, original bytes inaccessible, or its original authorization invalid. It cannot silently drop disconfirming records.

This is a **read-only composition**, not a historical-use or model-context authority. It has no route, cron, job, customer output, migration or runtime persistence constructor.

## Reproducible positive and negative qualification

`tests/integration/li05d_native_owner_capture_history_basis.php` uses the existing `MonitorModelFixture` and real `MonitorService` + `SourceCaptureStage` on an ephemeral MariaDB database to generate **two actual governed fixture captures**, **two M06 tool/evidence outcomes**, **two P01 snapshots**, original retained private payloads and source licence. The model transport stays uncalled.

It tests authentic preflight on both, exact digest/cutoff replay, a prior cutoff excluding later-known evidence, `INSUFFICIENT_BASIS` instead of fabricated source coverage, immutable candidate-only 270 persistence/replay, changed source-card rights, M05 quarantine, missing private bytes, foreign tenant, unauthorised actor, absence of new fetch/model spend and no source-byte disclosure.

This test does **not** exercise a newly authorised historical analysis using real-world source data. The fixture source, operator and network response are synthetic, although the producer and provenance code paths are real.

## Remaining authority gates

- U03 policy proposal is merged documentation **not adopted historical purpose, source retention/disclosure or provider permission**. An authenticated stored page is not permission to reuse it indefinitely.
- U04 selected Option A permits the semantics for the narrow M17 candidate; operational 270/280/290 DDL, populated native restoration, source basis integrity and release activation are separately reviewed.
- U05 due policy remains proposal; an eligible review event is **not** a scheduler, model, notification, or budget permission. U06/HistoricalModelPurpose is still unratified.
- Achieving genuinely complete historical basis coverage requires P01/monitor owner-native **planned slot/admission ledger** proof and a frozen completeness contract; snapshot count is not enough. Until then do not promote `INSUFFICIENT_BASIS` into `COMPLETE_WITHIN_INPUT`.

**GitHub-hosted qualification only:** strict foundation, MariaDB 10.6/10.11 full suite and package integrity on the exact PR head. Railway staging remains sourced from merged main; the feature is not installed or enabled by this draft.
