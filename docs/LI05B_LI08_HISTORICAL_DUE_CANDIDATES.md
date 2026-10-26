# LI-05B / LI-08 — owner-bound historical preflight and pure review-due candidate

**Date:** 2026-10-26. **Status:** `SOURCE_ONLY / INERT / U03_HOLD / U05_HOLD / NOT_DEPLOYED / NOT_AUTHORIZED`.
**Stack:** PHP PR #86 (LI-07B) → #87 (LI-19) → this review candidate.
**Canonical:** U04 owner-selected Option A in [PR #378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378), not yet merged/adopted. U03 historical-use design has a separate [draft PR #380](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/380), not ratified.

## Exact code delivered

**HistoricalCaptureProbe** is a read-only, deliberately non-admitting source-boundary investigation. Existing M02 `competition_monitoring.read` and `evidence.read` must pass. It joins native scoped P01 snapshots/source cards, M05 evidence metadata, the original tool request/outcome and the original `monitor_capture_payloads`/raw artifact association. It derives a **conservative upper bound** on known-at from native capture/evidence/binding timestamps; never backdates an unknown source event. Current source/ref status, active monitored source, card licence, terms/robots review and retention must still permit use. Retained private bytes are fetched by digest, checked against the snapshot/M05/tool/storage chain and URL path scope; none are returned to the consumer.

Even a successful probe returns **`NATIVE_HISTORY_PREFLIGHT_VERIFIED_BUT_U03_HOLD`** with `U03_historical_use_admitted=false`, `provider_disclosure_authorized=false` and `M17_input_set_authorized=false`. It does not issue U03 permission or freeze a true historical M17 `InputSet`. Existing `P01HistoryAdapter` remains a safe metadata-only reader, not a source of payload SHA-256. Additional verification of a genuine native capture and scoped redaction/rights history is required in U03 adoption.

**ReviewDue** is a pure U05 candidate over one frozen synthetic metadata basis. It distinguishes `NO_NEW_BASIS_CANDIDATE`, `DUE_CANDIDATE_HUMAN_REVIEW_ONLY`, `NOT_DUE_CANDIDATE`, `HOLD_CURRENT_RIGHTS`, `DEFERRED_BACKPRESSURE`, `DEFERRED_COALESCE_REVIEW` and `DEFERRED_COOLDOWN`. Configured cadence/max-wait/cooldown/queue limits are bounded, but U05 has not adopted those controls. No due result produces a DB row, queue item, scheduler token, automatic model spend or notification.

## Important limits / next genuine gates

1. Owner-selected U04 is **not merged canonical authority**; preserve old v1 identities and digests.
2. U03 is **not ratified**. Current source rights and byte proofs do not imply historical-purpose/provider-disclosure rights. No new memory is admitted.
3. U05 review policy is **not ratified**. A caller-asserted interval is not a permission to run a job; the entire due engine is detached.
4. G21 remains in `docs/longitudinal/proposals`, outside the production migrator. PR #86/#87 remain draft and unmerged.
5. No real M05 source/rights-backed positive qualification has been performed by this change; integration tests exercise safe refusals for unknown, foreign and future references. The new candidate must not be described as an end-to-end native approved service.
6. No deployment to Railway or Loopia; no provider calls or customer-visible output.

## Validation paths

Run `php -l` on both new classes and `php tests/run.php --layer=unit --filter=m17_review_due_candidate --strict`, then the normal strict CI MariaDB 10.6/10.11 lanes. Integration `tests/integration/m17_historical_capture_preflight.php` needs real disposable MariaDB, not staging. The normal CI package integrity / filesystem boundary must remain unchanged.

The next promotion work is owner-adopted U03 with **native positive and negative source tests**, selective U04 implementation acceptance, an additive versioned migration plus populated upgrade/no-op/restore qualification, then U05 owner review-due policy and a separately authorized staging release.
