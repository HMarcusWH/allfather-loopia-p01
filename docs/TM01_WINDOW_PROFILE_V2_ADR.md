# TM-01 — M17 deterministic P01 WindowProfile v2

Prepared: 2026-10-29. Wave 06 / BUILD-12. Depends on HS-01 and upstream U04-A/A2/B Option A as recorded in canonical PR #378. Status: source candidate only, no activation or independent U04/M05/U03 ratification.

## Reuse and version identity

The existing M17 Vocabulary, InputSet, WindowEngine, ProfileResolver, DynamicMeasures, Lineage, RollupEngine and MemoryStore.preview remain unchanged and authoritative over their historical v1 frozen data. The new P01WindowProfileV2 class and HistoryM17V2CandidateBridge are a strictly inert additional v2 representation. Do not rewrite a legacy hash, window, profile, existing candidate 280 DDL or ledger.

The HS-01 source is a self-hashed point-in-time historical metadata basis with separate event-at, observed-at, known-at and review-at, complete-within-supplied-input selection, distinct tenant/workspace/competitor/question scope, and masked future evidence. It is NOT M05 authentication or historical source-use permission. Incomplete or capped input refuses. The new bridge maps the selected reference IDs and exact SHA payload values into existing InputSet, preserving all contrary polarity and never inventing magnitude/diagnosticity or original publisher independence. The window must match every selected reference and the exact half-open period. Default profile is frozen at period start so a later knowledge cutoff does not silently alter its profile.

## Selected U04 Option A, A2, B

A proposed run derives from tenant, workspace, typed P01_COMPETITOR subject, separate question, data class, window kind and exact UTC period, input digest, resolved-profile digest and deterministic engine version. Exact repeated input yields a repeated identity. Late-known same-period input yields a distinct immutable run, not period-only overwrite. The proposed future output key is (compression_run_id, memory_class, output_ordinal), with no fabricated live run ID. Current head is explicitly NOT_PERFORMED_AMBIGUOUS_HEAD_MUST_HOLD, never a last-write-wins guess. P01 and P02 namespaces stay distinct.

## UTC, DST, numbers and historical replay

New v2 creation permits only UTC_ISO_FALLBACK with zero UTC start/end offsets and exact seconds. A previously frozen v1 Stockholm window may still replay under the old semantics, but cannot become a new v2 governed window until M13 provides an independently verified timezone-rule digest; host tzdb label 0.system is insufficient. Existing month-end, ISO week and leap-year rules remain in v1 WindowEngine, untouched. Metadata input magnitudes remain bounded integer milli-units or null, derived measures use integer/rational arithmetic, null commercial importance stays unknown, and all contrary/source lineage remains present. A body SHA class never implies an independent event.

## No side effect / no authority

The bridge and v2 profile are pure in-memory functions with no SQL, transport or source-byte access. Six candidate authority fields are fixed false; activate always refuses. No current M05/P01 source-use permission, M17 schema install, model inference, scheduler, P02 state, M10/M16 release, customer output or Railway/main mutation is granted.

Focus tests: accepted synthetic same-month candidate; stable same-profile/changed-input successor; digest replay; P01 namespace; UTC restriction with legacy v1 Stockholm compatibility; contrary preservation; incomplete basis and authority-spoof refusals. Later HS-02, RT-01/RT-02, V01-V11, actual source rights, M13 calendar, multi-owner U04 and production GO are separate.
