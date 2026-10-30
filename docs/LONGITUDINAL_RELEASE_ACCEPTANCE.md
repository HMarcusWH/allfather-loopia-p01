# Allfather build-first v2 — Final source acceptance and separate V01–V11 qualification

**Prepared:** 30 October 2026. **Build plane:** isolated PHP integration branch only. **Authority ceiling:** no new owner permissions, paid provider, external source, scheduled run, customer output, Railway/Loopia migration or production launch.

## Source-first acceptance, not an owner GO

The original 29-package build train is complete *in source only* when BF-00 through RT-02 all have existing implementation files, owner-named typed inputs/outputs, positive and refusal fixtures, source/migration/identity contracts, QA evidence, and no unresolved plan-only package. RT-02 must reject any missing package, a permanently refusing runtime placeholder without a positive fixture boundary, an unimplemented adapter, a missing owner contract, or an accidental provider/network effect.

A successful RT-02 build candidate does not automatically mean its 64 generic BF-01 carrier index entries are native producer-to-consumer integrations. Their registry preserves UNVERIFIED_UNTIL_REAL_CONSUMER_IN_RT02; each real product path must be grounded in the implemented P01/M05/PromptOps/M07/M08/M10/P04 owners and separate owned tests. Real semantic U01–U10 decisions are not smuggled into a fixture.

The BuildFreezeInspector produces a no-DB/no-network, source-hashed inventory of all 29 packages and hard-disabled authorities. The static BUILD_FREEZE_MANIFEST.json is an honest **preparation** document anchored to the qualified RT-01 integration head; it cannot embed its own final Git SHA without self-reference. On RT-02 hosted CI, record the exact tested head SHA/tree; after merging, verify that the actual integration tree equals the tested tree, and record that actual SHA/tree in the durable tracker. A later patch changes the immutable source freeze and starts a new receipt; it never edits a historical PASS.

## Self-contained source artifact and chain of custody

Use the pinned final merged commit, a clean tracked checkout, an owner-only output directory **outside the repository**, and the fail-closed source-allowlisted package builder:

    bash ops/history/package-source.sh --output=/outside-repo/private/source/allfather-source.tar.gz

The package builder uses only Git-tracked source, tests, docs, assets and build scripts. It rejects unexpected tracked secret/backup extensions, local dirty tracked bytes, output collisions, public/repository paths and nonprivate output parents. It never bundles local .env, live private M05 source blobs, node_modules, runtime snapshots, provider credentials, encryption private keys or backup dumps. Preserve the archive SHA-256 and exact Git SHA/tree as separate external evidence. Building an archive is not deploying it.

The PHP repository declares proprietary licensing in composer.json; npm frontend dependencies are lock-pinned. BUILD_LICENSE_SBOM_EVIDENCE.json and the inspector bind those files. **No independent third-party license audit has been performed**; a lock digest is not a legal opinion. A source archive created from the commit is one inspectable artifact, not proof of the deployed host's contents.

## Operator-source and synthetic commands

    php ops/history/shadow-report.php --status
    ALLFATHER_RT02_SYNTHETIC_TEST=1 php ops/history/shadow-report.php --synthetic-development
    php ops/road-test/export-history-review-evidence.php --status
    ALLFATHER_RT02_SYNTHETIC_TEST=1 php ops/road-test/export-history-review-evidence.php --synthetic-development
    php ops/history/migration-plan.php --status

These commands have closed arguments, refuse all web SAPIs before bootstrap and never select an actual tenant/database/provider. The source-only shadow uses the fixed BF-01 EgressGuard fixture, AN-03/AN-04/AQ-02 history, RT-01 source composition, M17/VA-02 scorecard and VA-03 recovery metadata. It verifies an out-of-scope redirect is refused and zero live model calls occurred. Positive output includes only digests and false live-authority flags. The evidence-export helper is a source metadata carrier; **it does not implement or claim the authorized V10 real road-test exporter**.

## Required post-build verification sequence

| Gate | Focus | Exact acceptance ceiling |
| --- | --- | --- |
| V01 | Exact frozen code and runtime versions | Same Git tree as final source freeze, full PHP/frontend CI with real MariaDB 10.6/10.11, no fail/skip/missing job |
| V02 | Fresh/upgrade/no-op/interrupt migration | Disposable physical family260–350 plus immutable migration900, indexes/triggers/FKs, interrupted DDL and source/bytes |
| V03 | Security/privacy/crawler/authority | SSRF, redirects, parsing, scope/rights, revocation timing, secrets, HTTP read-only boundaries, no accidental execution |
| V04 | Complete synthetic composed journey/replay | Captured fixture bytes to evidence, state, memory, due, human-review *fixtures*, finding, attention, supersession and explicit HOLD paths |
| V05 | Authorized model quality and independent scoring | Separate actual source/model permissions, unopened heldout, denominators, critical-failure zero, real independent reviewers; NO implicit release |
| V06 | Deployed browser acceptance | Only after V08 approved staging; two authorized TEST accounts, P01–P05/M10/history at desktop/mobile, CSRF, accessibility, refusals |
| V07 | Pre-migration recovery and key custody | (A) full synthetic candidate-schema encrypted restore on disposable target, (B) current live data/private-byte recovery proof only for tables actually installed, (C) off-host retained keys; Ops-approved host route |
| V08 | Owner-approved staging deployment and migration | V02/V03/V07 approved, exact source/manifest, pre-migration rollback evidence, migrated QA/staging with source/byte parity; no full destructive tests on real staging |
| V09 | Capacity, crash and bounded failure | Cost/storage/latency/queue ceilings and concurrency/failure injection on owner-approved isolated infra |
| V10 | Genuine scoped manual road tests and populated restore | V05/V06/V08/V09 accepted; real source/provider rights, exact inspected request, human decisions, internal release and **new post-migration populated restore** |
| V11 | Separate production and recurrence decision | Full owner business/technical/rights/cost/runbook sign-off; no unattended paid inference or external messaging by default |

V05–V11 have real owner/host dependencies. They are **NOT RUN** merely because a build PR merges; V01–V04 are also not inferred from ordinary component CI. V07 current-host proof does not include future unapplied candidate tables; V10 is where newly installed populated live state must be recovered. V06 cannot precede V08 because a source-only UI test cannot substitute for actual deployed browser behavior.

## Acceptance classifications

Use four distinct statuses: (1) BUILD_INERT_COMPLETE_SOURCE, (2) V01–V11 VERIFIED_IN_SCOPE, (3) OWNER_ADMITTED_IN_SCOPE and (4) RELEASED_IN_SCOPE. Do not combine them. Where the source still has a required unimplemented positive owner adapter, mark CONTRACT_BLOCKED/IMPLEMENTATION_INCOMPLETE rather than claiming a completed feature. A failing final test remains FAIL and requires a new frozen SHA; do not weaken the test.

**Explicit exclusions:** P07-B, email/SMS/CRM webhook, automatic source/model/provider execution, customer publishing, live database migration, real backup restore and key rotation unless separately adopted by the current owner. Railway staging continues tracking protected main only and build branches do not deploy.
