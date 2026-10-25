# Allfather Monitor — dated longitudinal currentness (LI-00)
**As-of 25 October 2026, Europe/Stockholm.** This is a dated reconciliation, not an automatic status service, adopted canonical authority or execution grant. Verify live SHA, operational permissions and deployment again before implementation/merge.

| Plane | Verified state | Claim ceiling |
|---|---|---|
| PHP main | `d4fa09d6de924bb18b99fd229383e5d4992017ab` | Code only |
| Canonical inspected main | `1169428f8163ba7ec81e4c739fea855b4b246cba` | Not automatically adopted |
| Adopted PHP global canonical pin | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | Not advanced |
| GitHub CI | [run 40131656219](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/40131656219): 5 failed jobs, **0 recorded steps** | **NOT EXECUTED** — neither app FAIL nor PASS |
| Railway staging PHP | `4810dcc3-ec21-452d-b19f-c3b2a17d85ac` SUCCESS, source `d4fa09d6de924bb18b99fd229383e5d4992017ab`; Dockerfile PHP 8.5.12, MariaDB 10.11 image, attached volumes, no PHP cron | Deploy SUCCESS ≠ full QA ≠ admission |
| Issue65 / PR66 | [Issue #65](https://github.com/HMarcusWH/allfather-loopia-p01/issues/65) OPEN, [PR66](https://github.com/HMarcusWH/allfather-loopia-p01/pull/66) draft/unmerged | Offline guard tests 203/0/0, disposable P01 integration unexecuted; PR CI [40144285054](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/40144285054) has zero steps |
| PR64 diagnostic candidate | **CLOSED without merge**; `9a001370c7c3973d4edd6f279c958acafe71df3d` | Safe slice recovered separately in draft [PR67](https://github.com/HMarcusWH/allfather-loopia-p01/pull/67), not present in main |

**Model quality:** historic DEV7 had 5/7 development mechanical passes; held-out and independent human judgement remain unqualified. This document does not change old evaluation manifests, rejected critic payloads, prompt versions, digests or spent model attempts. Actual current execution grants have **not been independently queried** in this check; do not infer grant presence/absence from git or Railway.

**Schema:** 31 migration files at inspected PHP source. Applied `900_deferred_foreign_keys.sql` and prior migration hashes must stay unchanged; new migrations only after owner decisions and fresh/upgrade/no-op disposable MariaDB proof.

## Dependencies and next work

**P0 security gate:** PR66 checks full source-card path scope on every redirect hop, preserving existing HTTP, HTTPS, origin/port, host, DNS/IP, SSRF, byte and redirect controls. It still needs exact-head P01 integration and release qualification. **Do not merge into auto-deploying Railway main** without that and staging rollout approval.

**LI-00 foundation:** dated source/host/quality reconciliation and separate safe PR64 metering repair; unknown token use remains UNKNOWN, never zero. No new paid attempt.

**LI-01 owner decisions U01..U10:** resolve proposed descriptive nonmaterial semantics (U01), P01 scope vs P02 identity (U02), evidence-history rights and clocks (U03), deterministic M17 subset and same-period successor uniqueness (U04), due/queue policy owner (U05), historical-analysis purpose/budgets (U06), genuine multi-evidence release (U07), service seat (U08), internal attention/digest rights (U09), outcome/invalidation (U10). No plan text or PHP enum may act as approval.

**R1 quiet history:** retain evidence, append-only observations, semantic features, origin/coverage and a read-only competitor timeline. Original v1 prompt/evaluation artifacts frozen.

**R2 temporal:** point-in-time evidence basis, approved M17 deterministic windows, lineage, due checks. Being due ≠ permission to fetch, infer, release or notify.

**R3 governed analysis:** separately admitted manual historical-analysis execution with frozen context, bounded analyst/critic, rivals/contradictions, true human review and correct multi-source P01/M07/M08/M10/M16 admission.

**R4 attention:** coalesced bounded review queue, internal digest, dedup, suppression. Scheduler observer v1 still zero model attempts. Meta/meta-meta layers remain optional proposal-only.

Preserve authority owners P01, M05, M07/M08/M10/M16, P04, scheduler, existing provider, EvidenceStore, backup and recovery. No duplicate database, no provider calls, no expanded crawling, no customer release/notification, no production action from a code merge.

## Source provenance
The **full original** uploaded files remain separate input artifacts and are not silently rewritten or claimed imported by this PR:
`Allfather_PHP_Longitudinal_Intelligence_Plan_2026-10-25.md`, `Allfather_PHP_Intelligence_Work_Packages_2026-10-25.json`, and `Allfather_Monitor_Longitudinal_Engineering_Handover_2026-10-25.md`. Their obsolete PR64-OPEN assertion is corrected here without editing source history.

Per PR, bind exact base/head, changed files, command/test receipts (including FAIL/SKIP), owning decisions, source rights, deployment consequences and blockers. **Never use staging or preserved restores as disposable test databases.** See companion machine-readable ledger.
