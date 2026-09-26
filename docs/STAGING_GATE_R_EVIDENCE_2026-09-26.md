# Gate R evidence filing - 26 September 2026

This filing records the completed inert staging deployment and qualification of PR16
`392f48deef11380f6dccfe88f2a47ba1fd94575c` on `staging.allfatherbi.com`, under the recorded
owner-directed shared-host staging deviation. Its source fingerprint is
`d0fd0877a2b7039a129bf8d4f11d632f6066f9afd534eba79bff1a10e8ef8266`; the canonical pin remains
`c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`. The documentation/evidence commit is not the running
application release and is not substituted for PR16's host qualification.

## Measured current-release results

| Activity | PR16 result |
| --- | --- |
| Exact local workflow reproduction | 34 shell steps exited zero; MariaDB 10.6, MariaDB 10.11 and both host-shaped strict lanes each passed 5,552/0/0 |
| Hosted GitHub Actions | Run 36197550928 failed before runner execution; all five jobs have zero steps and account billing/spending annotations. No hosted PASS is claimed |
| Staging copy and release identity | Exact PR16 reference and fingerprint; final remote preflight READY; destination confined to staging; manifest-last assets published |
| E.2 HTTP private paths | 16 checks PASS: 15 HTTP 403 responses plus the accepted Loopia dotfile roadblock redirect |
| Host capability | PHP 8.5.9, MariaDB 10.11.19-MariaDB-log, baseline/network READY; outbound HTTPS proved |
| Migrations and database boundary | Runtime and qualification databases/users distinct; 19 migrations applied, zero new/pending/drifted, unchanged before/after status |
| Complete host strict suite | 5,552 pass, zero fail, zero skip; qualification database only; report digest bound to execution context and pre/post release fingerprints |
| Runtime invariants | Workspace external-effect guard and access-evaluation append-only checks PASS; writes_external_systems=false |
| Anonymous Gate N | SMOKE_PASS, 23 steps, zero failures and zero missing required request references |
| Authenticated Gate N / Gate O | SMOKE_PASS / TESTED_CONTRACTS_PASS, 118 steps, zero failures; disposable security identities retired with zero live sessions |
| Manual browser | One workspace row for one workspace; logo loads from the public console asset path at natural size 392 x 96; account, support, P01-P05 and M10 checked |
| Gate P | One exact private request-log match; public log path HTTP 403; private log contents not emitted |
| Gate Q | PREVIOUS_RELEASE_ROLLBACK: PR16 to PR15 to PR16 actually executed and verified; final PR16 restored; governed history preserved |

PR16 host layer totals are foundation 1,009; unit 153; integration 1,798; conformance 395;
acceptance 668; security 1,437; rollback 92. Every layer has zero failures and zero skips.

The N/O count changed from PR15's 121 to PR16's 118 because four same-tenant fixture-provisioning
and post-provision checks became one existing-fixture reuse check. The other 117 step names are
identical. The lower count does not omit a security contract. The reconciliation is hash-bound
inside the current `STAGING_SMOKE` artifact.

Gate Q preserves the named immutable history tables and prior request-log prefix; it is not a full
database backup/restore. No M10 stream existed in any phase, so no populated ledger chain was
exercised. Independent review verified 26 phase-source hashes and 30 additional deployment files.

Manual inspection observed expected EMPTY product data, P04's
`DISTRIBUTION_TARGET_NOT_CONFIGURED` refusal and M10's `TEST_NO_STREAM`. Additional panels remain
`GOVERNED_READ_NOT_WIRED`. These results qualify the bounded inert staging journey; they do not
prove live intake, populated-ledger behavior or full feature readiness.

## Preserved failures and repair evidence

- PR13 host `CONFORMANCE` remains FAIL at 5,548/1/0. The copied payload omitted the exact workflow
  required by foundation qualification. PR15 fixed the release filter and independently passed
  5,551/0/0 locally and on Loopia. PR13 evidence is never relabeled as PR15 or PR16 evidence.
- PR15's automated N/O/P passed, while manual inspection found a relative logo URL and duplicate
  workspace choices. PR16 fixed these two defects and received its own qualification. The inherited
  PR15 deployment/probe/conformance artifacts remain historical; no composite PR15 manual-smoke
  PASS is invented by this filing.
- PR16's first post-copy preflight was NOT_READY because two retired PR15 hashed JS/CSS files
  remained alongside the new manifest. Only those obsolete manifest assets were removed after
  confinement and hash checks. Both preflight receipts and the exact cleanup result are retained.
- The first PR16 HTTP wrapper returned FAIL after incorrectly demanding request references from
  exempt provider/Apache refusal surfaces. Its underlying 23-step smoke was already SMOKE_PASS.
  The failed wrapper receipt remains unchanged; the corrected wrapper was rerun and passed with
  before/after PR16 preflights. This is a qualification-wrapper correction, not an application repair.

## Registry and claim boundary

The registry contains 12 reviewed pointer records: five `CONFORMANCE`, two `STAGING_DEPLOYMENT`,
two `LOOPIA_PROBE_001`, one `CROSS_SITE_ISOLATION` FAIL, one `STAGING_SMOKE`, and one
`ROLLBACK_DRILL`. Each pointer is a repository-relative POSIX path to a sanitized artifact and
pins its final SHA-256 bytes. Each host record names its exact release, environment and target.
Raw operational outputs remain in the local evidence archive; the committed summaries preserve
their source hashes without publishing credentials, sessions, private log lines or private config.

`recorded_at` is the actual sequential record-filing time. Activity times are separate fields in
the artifacts and remain unknown where the historical source did not record them. Historical
records are filed before current PR16 records so there is no equal-time ambiguity about the most
recently recorded host deployment.

The validator supports `conformance_complete`, `staging_deployed`, and
`loopia_capability_validated`. It does not support `live_read_activated` or
`road_test_001_executed`. Isolation failure is not silently converted into PASS: the current
reducer does not make `CROSS_SITE_ISOLATION` a prerequisite for those three operational claims.
The owner-directed staging deviation remains a separate, explicit qualification limit.

`upstream.lock.json` is byte-for-byte unchanged in this filing. Its stored operational flags remain
false until a separate reviewed flag update; evidence support is a one-way condition, not an
automatic flag mutation. All permanent authority locks remain false, and
`no_external_charge_executed` remains true.

## Gate S and remaining boundaries

The supported closure statement is: **the inert staging application is deployed and qualified on
Loopia under the recorded owner-directed staging deviation**. Technical E.1 remains
`FAIL_READABLE`; E.3 web-PHP isolation remains `UNVERIFIED`. The owner's WordPress maintenance
exclusion is not a hygiene PASS. Production cannot inherit this staging exception.

Live read, scheduler handlers, ROAD_TEST_001, customer-visible output, external action and
production remain inactive or unauthorized. No partner invitation, full feature launch or
production readiness is implied. ROAD_TEST_001 remains a separate bounded activation requiring
the reviewed source scope and explicit owner GO. Scheduler transport remains UNVERIFIED.

Before merging this filing, run `php ops/evidence/validate.php`, the foundation suite, artifact
path/hash/binding verification and diff review. The evidence-only commit must not be deployed as
though its application object had separately passed PR16's qualification.
