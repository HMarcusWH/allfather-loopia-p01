# Longitudinal Monitor work-package dependency map

**Prepared 2026-10-25. Status: proposed; NOT a replacement for the complete original machine-readable 22-package manifest.** The original manifest is separately supplied; this is an operational index and stop-gate map.

| Work package | Dependencies | Owner decisions / qualification gate |
|---|---|---|
| LI-00 | none | currentness, metering diagnostics, truthful qualification |
| LI-01 | LI-00 | U01–U10 canonical owner decisions |
| LI-02 | LI-01 | U01, immutable successor observation contract |
| LI-03 | LI-01,02 | U01/U02, scoped history |
| LI-04 | LI-03 | U02/U03, bounded semantic/provenance/coverage features |
| LI-05 | LI-01,03,04 | U03/U06, knowledge-cut historical basis |
| LI-06 | LI-01,05 | U04, pure deterministic M17 windows/profiles |
| LI-07 | LI-06 | U04, temporal memory persistence/lineage |
| LI-08 | LI-01,04,05 | U05, review-due policy |
| LI-09 | LI-01,03,08 | U05/U08, bounded coalescing/service due jobs |
| LI-10 | LI-01,02,05 | U06/U08, historical inference purpose/budgets |
| LI-11 | LI-05,07,10 | U06, analyst/critic workflow with human review |
| LI-12 | LI-01,11 | U07, legitimate multi-evidence admission |
| LI-13 | LI-03,08,12 | U09, role-safe digest/attention |
| LI-14 | LI-11 | U06/U10, outcomes |
| LI-15 | LI-05,07,12 | U03/U10, invalidation |
| LI-16 | LI-03 | U01/U02/U09, read-only history UI |
| LI-17 | LI-03,05,07,08 | U04/U05, assurance |
| LI-18 | LI-01 | U01/U06, freeze longitudinal evaluation before tuning |
| LI-19 | LI-00,01 | additive migrations, fresh/upgrade/no-op/restore/stand-down |
| LI-20 | LI-00,01 | U06–U09, staged acceptance |
| LI-21 | LI-07,08,11,14,17,20 | U10, optional proposal-only meta evaluators |

**Do not promote** documentation, model outputs, service ticks or unqualified CI to runtime authority. Review and deploy each small source PR separately; source merges can auto-trigger Railway staging. Full original manifest and handover control proposed acceptance semantics, not this shortened map.
