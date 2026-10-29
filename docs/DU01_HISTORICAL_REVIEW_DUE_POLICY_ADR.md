# DU-01 — Historical review policy and deterministic quiet-stream due candidates

**Package:** BUILD-14 / DU-01 · Wave 07 · LI-08 / U05.
**Dependencies:** OB-03, HS-01, TM-01, HS-02.
**Status:** BUILT_INERT source candidate. No U03/U05 owner grant, scheduler, provider, release, notification, live migration or Railway mutation.

## Reuse and identities

M17 ReviewDue.v1 and HistoryReviewDueStore already provide pure due arithmetic and an append-only, disposable family-290 candidate ledger. They are preserved. HistoryReviewPolicy.v2 and HistoryReviewDue.v2 add a versioned P01 tenant/workspace/competitor/question scope, ordered source refs (1–20), UTC seconds, cadence (60 seconds to 90 days), maximum wait, cooldown, pending cap, reference cap and daily work budget. The original v1 methods and digests stay valid. A valid policy hash is never owner approval.

U02 independent real-world event and contradiction triggers remain disabled. There is no universal 1000-events/30-day threshold; raw captures, hashes, source refs and repeated pages are not independent witnesses.

## Quiet time and watermark separation

The pure due input binds an immutable HS-01 historical basis, selected source refs, knowledge cutoff, review tick and distinct observed, available, queued, analysed, human-reviewed and last-considered basis/time watermarks. Future-known material cannot be used. A repeated unchanged basis can become due when its own review clock passes, with reason QUIET_REVIEW_CLOCK rather than an invented external event. Maximum wait and fresh basis have distinct reasons. Empty or gapped basis, unknown current rights, revoked source, exhausted work budget, pending human work and cooldown produce explicit HOLD/DEFERRED decisions. No due calculation changes watermarks, starts an M17 run or sends a notification.

A changed state repeated at the exact same policy/basis/tick identity conflicts. An exact replay returns NO_WRITE. Existing 290 disposition vocabulary is reused; the versioned quiet reason is preserved in the digest-bound due_json. Candidate 290 remains outside database/migrations and retains three tables, six append-only triggers and the existing CLI/disposable factory gate.

## Operator commands and security

ops/history/policy.php and ops/history/review-due-candidate.php accept --help, --status and a guarded --synthetic-development mode requiring ALLFATHER_DU01_SYNTHETIC_TEST=1. They expose no arbitrary source URL, actor-impersonation flag, secrets or customer data; the positive path computes only over the repository-fixed synthetic fixture.

All results set U03/U05, model, source acquisition, scheduler action, notification and customer output authority false. Caller-supplied OWNER_METADATA_READ_TEST_ONLY is explicitly a synthetic processing posture, not M02/M05 owner authentication. Later owner-admitted scheduler and review activation require separate written approvals.

## Qualification

Focused unit/security/foundation tests cover quiet due, cutoff, capped/unknown origin, replay, rights HOLD, work budgets and no egress. Disposable MariaDB 10.6/10.11 integration exercises 270→290 v2 and v1 coexistence, exact no-write replay, changed same-tick refusal, foreign tenant and append-only triggers. Post-build RT-02 and V01–V11, U03/U05 and host/production quality remain unverified.
