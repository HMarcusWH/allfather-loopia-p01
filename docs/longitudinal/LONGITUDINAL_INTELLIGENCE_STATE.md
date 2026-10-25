# Allfather Monitor — Longitudinal currentness and delivery entrypoint

**As of 25 October 2026, Europe/Stockholm.** This is a deliberately dated ledger separating source bytes, evaluated test head, staging deployment and runtime authority. It is not an automatically refreshed status dashboard, model approval or grant. Older records remain historical; do not silently re-label past FAIL/NOT_RUN to PASS.

## Baselines and operational observations

| Plane | As-of identity | Evidence ceiling |
| --- | --- | --- |
| PHP source `main` | `2a1a7eb571a439e1a33bed21f937639c393832db` | Merged source |
| Exact validated Git tree | `42d93cc9ad38f95177e04e40cb30f4cca4fb9415` | Same source tree as QA head |
| Canonical inspected `main` | `1169428f8163ba7ec81e4c739fea855b4b246cba` | Inspected, **not adopted** |
| PHP adopted global authority pin | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | Unchanged |
| Railway staging application | `2a3e3a2e-35f1-4393-b744-f40627118dd9` SUCCESS on PHP 8.5.12, source `2a1a7eb571a439e1a33bed21f937639c393832db`, `/healthz` 200 | Staging only, not production |
| Railway MariaDB | 10.11 image, persistent DB/storage volumes unchanged | NOT a disposable QA database |
| MariaDB 10.6.28 strict | 12,458 pass, 0 fail, 0 skip; Railway QA deployment `f95d3346-453a-48b2-995d-d451857a4983` | Target-host suite receipt in Railway logs |
| MariaDB 10.11.19 strict | 12,458 pass, 0 fail, 0 skip; QA deployment `ebdb2f4d-eae6-4fe9-bed0-0a5f9fab9f76` | Target-host suite receipt in Railway logs |
| Hosted GitHub Actions | private-repo credits exhausted; 0 reported job steps | **NOT EXECUTED**, neither code FAIL nor green PASS |

The current source diff was reviewed and merged as security [#66](https://github.com/HMarcusWH/allfather-loopia-p01/pull/66), test correction [#71](https://github.com/HMarcusWH/allfather-loopia-p01/pull/71) and security test inventories [#72](https://github.com/HMarcusWH/allfather-loopia-p01/pull/72). Issue #65 is closed. The passing Railway tests are on the **same exact full Git tree** as main, not a claim that a GitHub-hosted workflow ran. Source merge and deployment create no new provider/capture grants.

**Model quality:** original DEV7: 5/7 development mechanical pass, independent held-out/human grounding **unqualified**. Archived PR64 closed without merge; do not reclassify its failures, reconstruct refused critic bytes or mutate frozen prompts/manifest/attempt budgets. Current runtime admission was not queried as part of this dated source audit; presence/absence cannot be inferred.

**Current longitudinal feature state:** Existing P01/M05 capture evidence, snapshots, structural diffs, run journals, schedulers and private artifacts are real infrastructure. None is the new v2 observation semantic owner or M17 memory. PR73 offers safe model diagnostics/usage; PR74 offers a conservative scoped read-only history projection. Neither establishes model quality, persistent business materiality or customer release.

## Implementation plan sources — preserved, not silently modernized

- [Full October 25 handover](source/Allfather_Monitor_Longitudinal_Engineering_Handover_2026-10-25.md)
- [Full October 25 longitudinal plan](source/Allfather_PHP_Longitudinal_Intelligence_Plan_2026-10-25.md)
- [Complete machine-readable LI-00 through LI-21 work packages + U01–U10](source/Allfather_PHP_Intelligence_Work_Packages_2026-10-25.json)

The original JSON names the old PHP source and describes PR64 as OPEN at the time the source artifact was generated. **Do not rewrite that historical source**; consult this newer dated ledger for the corrected as-of state.

## Next implementation gates

1. **LI-00** — qualify privately-allowlisted candidate diagnostics and consumed-token metering with exact tests, preserve old DEV7 history and reconcile old release-state docs with this dated overlay.
2. **LI-01** — upstream owner decisions U01–U10 by dependency, including proposed canonical PR [#379](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/379) (observation/materiality) and [#378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378) (M17 successor uniqueness and P01 scope). Both are proposals, not adopted runtime authority.
3. **R1 LI-02/03/04/16** — versioned v2 classifier with nonmaterial descriptive citations, history, semantic features/origin/coverage and authorized UI. Start only with conservative read-only P01/M05 projection until U01/U02/U03 contracts settle.
4. **R2** — frozen history basis, deterministic/LLM-free M17 windows, referential memory, input-specific successors, reasoned review-due without automatic inference.
5. **R3** — separately admitted manual historical-analysis purpose, exact model budget, analyst/critic alternatives, real human review and legitimate multi-evidence internal finding.
6. **R4** — bounded coalescing and attention/digest under separate owner authority; scheduler observer v1 remains zero model attempts.

No new migration is approved by this ledger: the 31 existing migrations include historical 900 and must not be edited. Future additive migrations require fresh/upgrade/no-op/restore tests against disposable MariaDB 10.6/10.11. No customer-facing route, provider spend, source fetch, new role, scheduled inference, notification, or production GO is authorized by these plans.

Machine-readable dated facts: [STATUS_LEDGER_2026-10-25.json](STATUS_LEDGER_2026-10-25.json).
