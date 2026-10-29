# AQ-01 — Inert bounded historical review work and candidate handlers

**Package:** AQ-01 / Wave 09 / LI-09, U05/U08. Depends on merged DU-01, TM-02 and IV-01. Semantic owner is the **existing JobMachine/SchedulerRunner and ScheduleDispatcher**, not a new scheduler. Status BUILT_INERT source-only, no authority or runtime registration.

## Existing owner preservation

The original P07/MONITOR_STEP scheduled observer remains unchanged, including three-unread changed-run backpressure, zero unattended model attempts and the existing job lease/fencing/dispatch machinery. No new JobMachine type, worker/tick route, scheduler.php handler, credential, provider grant, shell-cron assertion or external callback is registered. The new HistoryReviewDueHandler and TemporalRollupHandler are **pure previews only**, with activate() refusing under U05/U08/U04.

HistoryReviewDueHandler consumes an exact DU-01 policy, verified due decision and immutable HS-01 basis metadata via HistoryReviewQueue::freeze. TemporalRollupHandler reuses P01WindowProfileV2 and MemoryStore::preview; it rechecks the deterministic v1 rollup hash and never persists a memory or creates a job.

## Bounded immutable work identities

HistoryReviewQueue.v1 defines exact P01 tenant/workspace/competitor/question scope, frozen policy/basis/UTC tick, optional unadmitted IV-01 dependency graph, a bounded append-only list (1–64) of typed OBSERVATION, CONTRARY_EVIDENCE, INVALIDATION or QUIET_REVIEW_CLOCK refs, and declared pending/human-reviewed watermarks. Member ID, known and recorded timestamps and safe digest are all checked. Raw page text, source URLs, credentials and model prompts are forbidden.

A quiet-clock proposal is not a late-known source observation. A subsequent rights withdrawal or contradictory input is marked CRITICAL and ordered first, even when coalescing. Source data known after the frozen basis cutoff remains in the work receipt with an explicit out-of-cutoff HOLD, never silently added to historical model context. Duplicate IDs refuse; exact duplicate pending members are retained and marked already pending without creating a second work claim or fabricating independent witnesses. Real-world event and independent-origin counts stay null. The queue is a HUMAN_REVIEW_CANDIDATE_ONLY at most, not a human acceptance.

The whole canonical result is capped at 32 KiB and 64 members. Pending queues are bounded. Separate observed/available/queued/analysed/human-reviewed watermarks remain visible through DU-01 source and are not inferred from one another.

## Family 300 disposable candidate record and pseudo-claim

Existing native JobMachine remains the sole **real** job/lease owner. Family 300 is proposed as three **disposable-only** append-only tables: scoped work candidates, immutable member log, and a one-claim-per-work **fixture pseudo-claim** used exclusively to prove exact replay and different-claim conflict. Six SQL UPDATE/DELETE triggers refuse mutation. The fixture pseudo-claim sets runtime_lease_acquired=false and scheduler_action_authorized=false, never receives a live lease/fence or dispatches work.

HistoryReviewQueueStore is CLI/disposable-only; forRuntime refuses unconditionally. Each new candidate writes all members transactionally under the owner scope and one work digest; exact retry returns NO_WRITE. A cross-tenant candidate ID cannot be claimed. Concurrent delivery will ultimately require independent connection/race qualification for the RT-01 native job-owner adapter; these tests do not pretend that fixture claims prove live lease safety.

## Verification and limits

Focused unit/security/foundation and disposable MariaDB 10.6/10.11 tests verify quiet/time-derived due, stable digest, duplicate-member refusal, coalescing without loss, critical invalidation and contrary-priority HOLD, rights/known-at cutoff limits, no native job type, no model/network/notification, and candidate unique/pseudo-claim idempotence.

U05 review-policy adoption, U08 service identity and deterministic job authorization, genuine source/revocation lifecycle, M17 live source admission, real worker races, RT-01/RT-02, host restore and V01–V11 all remain separate. This package does not implement unattended inference, a paid model, real human release, customer output or operational GO.
