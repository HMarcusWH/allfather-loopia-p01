# TM-01 — Deterministic M17 P01 WindowProfile v2 candidate

**Package:** BUILD-12 / TM-01, Wave 06. **Owner:** canonical M17 and separate P01/M05/M13/Ops reviewers. **Dependencies:** merged HS-01 and owner-selected U04-A/A2/B recorded in canonical PR #378. **Status:** BUILT_INERT source-only candidate, no live authority grant.

## Existing implementation reused

The existing PHP M17 Vocabulary, InputSet, WindowEngine, ProfileResolver, DynamicMeasures, Lineage, RollupEngine and MemoryStore::preview are deliberately preserved. TM-01 adds P01WindowProfileV2 and HistoryM17V2CandidateBridge above them, rather than replacing correct v1 behavior, tampering with old frozen digests, installing a new factory, or creating another evidence store. Original reference timestamps, SHA-256s, polarity and exact input lineage are passed through their established contracts. The v1 MemoryStore::preview still derives the same deterministic rollup, but is not used as a live writer.

## U04 Option A, Option A2 and typed subject

The new versioned derivation identity is bound to tenant/workspace, P01_COMPETITOR subject ID, separate question ID, M17 data class, UTC window kind and exact half-open period, input digest, resolved profile digest, deterministic engine version and calendar version. Different same-period input digests produce distinct immutable derivation identities. Repeating the same exact inputs yields the same identity. P01 does not impersonate P02_ENTITY_TWIN or P02_RELATIONSHIP_EDGE merely because names or IDs look similar.

Memory output identity is described separately as a proposed unique key on (compression_run_id, memory_class, output_ordinal). The pure TM-01 candidate has compression_run_id=null. It never mints a persisted run, production key, M10 reliance record or M16 release. A candidate does not resolve competing same-period heads: ambiguous history remains an explicit HOLD, not last-write-wins or timestamp-based supersession.

The source basis is verified under the exact HS-01 candidate version and remains a self-hashed, offline metadata declaration. A candidate with missing/incomplete references, selection-cap omissions, invalid scope, wrong data class or a mismatched period refuses. Every selected contrary reference survives as a typed input, not only as an aggregate count. No economic magnitude is invented; magnitude_milli remains null without owner-certified input. Hash duplicate count does not prove source/publisher independence or real-world event identity.

## Calendar, time, serialization and numerical discipline

New v2 creation is restricted to the existing WindowEngine UTC ISO fallback. Under U04's current decision, arbitrary Europe/Stockholm or other caller-declared local windows cannot be newly treated as governed v2 windows until the M13 owner supplies a pinned verifiable timezone-rule artifact; a host tzdb label such as 0.system is insufficient. Old v1 windows remain replayable with their original hashes. The window is [start_inclusive_utc,end_exclusive_utc) and clocks are canonical UTC seconds. This avoids assuming every local day has 86,400 seconds during DST changes. Month-end, leap-day and ISO-week boundaries remain delegated to the original versioned engine and golden vectors.

The existing fixed-point integer magnitude and rational sum/variance implementation is reused without floats, non-finite measurements or arbitrary rounding. ProfileResolver uses a frozen period-start evaluation clock and its original narrow-only override/floor rules; late input during the same period cannot silently generate a broader profile or alter historical entries. Derived M17 summaries are not primary M05 source evidence.

## Authority non-collapse

All source-ownership, source-use, profile-owner, native-persistence, model-assisted compression and customer-release flags remain false. The bridge's activate() always refuses. No HTTP/CLI owner impersonation, provider dispatch, source read, scheduler, deployed schema migration, P02 state mutation, customer output, Railway/main change or production GO results from this PR. Canonical U04 owner selection is not, by itself, an independent cross-owner authority sign-off.

## Focused verification and deferred acceptance

Unit and security fixtures cover: exact same-input replay, P01 competitor/question binding, contrary lineage, same-period late-input successor with frozen profile, changed class/different run identity, wrong window refusal, invalid/incomplete source basis, forged rights with recomputed self-hash, P02 identity spoof and non-UTC creation refusal. The existing v1 tests and legacy digest semantics are unchanged.

Remaining separate decisions include U03 historical source eligibility/disclosure; U04 multi-owner adoption and M13 calendar proof; HS-02 current-use adapter; RT-01 schema/recovery; RT-02 end-to-end integration; V01–V11 human/model quality, host, external source, production and customer qualification. All five mandated CI jobs and exact merge-tree equality are required before calling BUILD-12 merged.
