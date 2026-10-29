# UI-01 — Inert P01 temporal memory and historical-review metadata panels

**Package:** UI-01, Wave 09. Depends on OB-02, HS-01, TM-02, DU-01 and IV-01, all merged. Semantic owners are existing Console/M02, P01 and M17 read model. U01/U03/U09 product-read authority remains unratified. This candidate is not a live route.

## Owner-safe PHP read projection

TemporalMemoryAdapter takes an authenticated operator session, typed competitor and question UUIDs, bounded page size (1–50) and an optional memory-ID cursor. It derives tenant/workspace/actor scope exclusively from the session and passes it to a supplied owner-reader closure. The closure must recheck native M02 competition_monitoring.read/evidence.read, current source rights and the exact P01/MemoryStore historical identity on **every** read/page; this presenter does not independently grant such rights.

Only a VISIBLE owner result with explicit evidence.read, candidate_only/read_only true, U03_NOT_ADOPTED and runtime_actions_available=false can be projected. Unknown/denied/foreign/unavailable remain distinct safe states, not forged EMPTY results. Each returned memory is validated for v2 run/rollup hashes, P01 class, UTC half-open period and cutoff, bounded selected/contrary counts, unresolved/head/metadata replay enums and non-admitted rights. The adapter copies only these fields, dropping any extra source URL, model candidate, raw HTML, private credential or operator-ID field. A next_cursor must be the exact last visible memory ID.

The existing CompetitorHistoryAdapter and HistoryReviewReadAdapter stay unchanged, still inert; the existing HistoryReviewCandidateReadModel retains its **CLI/disposable factory and real M02 test access check**, not a new live HTTP route. No private M05 bytes, source card URLs or reviewed claims are returned.

## React view: real empty/HOLD over supplied owner data

frontend/console/src/data/temporal-memory-view-model.js independently checks both closed PHP candidate envelope versions, typed rows, UTC clocks, explicit U03 hold and safe pagination. Missing/denied/not-found/empty each render distinct states. Replayed M17 metadata does **not** prove source truth, business materiality or current authority. A due candidate is not model spending authorization.

frontend/console/src/views/p01/history-reviews.jsx renders provided review bases and memory candidates side-by-side, including selected/excluded references, ambiguous head and contrary counts, explicit held due decisions and known cutoff. It never invents data from fixture mode or background fetches. Optional pagination only invokes a caller-supplied callback after explicit user click. No model/tool, observer, release, email, POST, timer or unsafe HTML operation exists. It is **not imported by active navigation** and cannot become a browser route merely because a build passes.

## Verification and postponed gates

Source-only PHP unit/foundation tests cover strict tenant/workspace/actor scope, owner denied/missing, immutable cursor, foreign/malformed IDs, unapproved source-right changes, source bytes never echoed, safe versioned projection and test-only native owners. Node-based frontend tests validate fixture-backed positive rendering, empty/denied, source/token secrecy, model-use holds, and esbuild JSX compile with no active route or effects.

These tests are build hygiene, not a populated live P01 UI, U03 rights adoption, real source eligibility, M10 release approval, U09 attention policy, Railway hosting, RT-02 producer-consumer composition or final V01–V11 certification. Customer visibility, notifications, source reads, model calls and production deployment remain false.
