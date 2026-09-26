# Owner directed inert staging deviation

**Recorded:** 2026-09-25T21:42:52Z (record preparation time; not the message timestamp)

**Disposition:** `OWNER_DIRECTED_STAGING_DEVIATION`

**Target:** `staging.allfatherbi.com`, internal TEST qualification through Gate S only

**Application candidate:** `322874b17500d837b2b9b974fde1e4e408b318d6` (PR13)

**Canonical pin:** `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`

The owner has directed continuation of the existing inert Allfather staging deployment after the
shared-account findings and has expressly excluded WordPress maintenance. This records that actual
direction and the resulting deviations. It does not claim that the original mandatory controls all
passed, that filesystem isolation exists, or that the owner approved production or provider use.

## 1. Owner direction and scope

The owner stated in the deployment conversation on 25 September 2026:

> All sites are controlled by me, so you can "run wild"

After the older WordPress installation findings, the owner stated:

> Ignore updating the older wordpress installations, most of those are no longer live.

Read these instructions in the scope of the existing request to deploy the Allfather MVP to Loopia:
continue the inert staging qualification, preserve unrelated sites, and perform no WordPress updates.
They are not a blanket authorization for unrelated account changes, production, or external actions.
No further ownership confirmation or WordPress-maintenance approval is required for this scope.

For this execution only, this record supersedes the named SSH-canary, historical two-blog inventory
and sibling-maintenance stop conditions in `BETA_RUNBOOK.md` E.1/E.3/F,
`BETA_RUNBOOK_MVP_SHARED_HOST_ADDENDUM.md` B/C, `MVP_SHARED_HOST_RISK_ACCEPTANCE.md` sections 3/4,
`SECURITY_BOUNDARY.md` section 5.3, `LOOPIA_HOST_MODEL.md` section 5, and
`ops/loopia-probe/README.md` section 5. The measured results and generic policy remain unchanged;
continuation is an owner-directed staging deviation, not satisfaction of the original checklist.
The separate conditional Gate A treatment in section 4 does not excuse any test or release check.

## 2. Observations and residual risks

The 25 September read-only host inventory contains 47 owner-controlled web roots, including 23 with
WordPress core markers. This exceeds the historical two-blog inventory. Marker presence is not a
claim that every root is live: static/media, parked, inactive and other homes are also present. The
owner states that most older installations are no longer live; that statement is not an independent
exposure or integrity check. Full site names and metadata remain in local deployment evidence.

The version-header review found older components. The owner explicitly excludes updating those
WordPress installations from this task. Their current patch, activation and exposure state is not
certified, and no maintenance completion is recorded. Administrative credential uniqueness, MFA,
backup restorability and non-reuse of Allfather staging credentials by sibling sites remain
`UNVERIFIED` where evidence does not establish them. They are reported residual risks in this
owner-directed continuation, not fabricated completed controls.

The current 25 September host rerun (`outputs/baseline/e1-current-host.jsonl` in local evidence)
confirmed the same account-wide owner SSH/CLI identity. The staging control and platform sibling
canaries both returned `READABLE` with matching non-secret markers; both were then removed with
`CANARY_REMOVED`. No secret was used or written. Preserve `E1 = FAIL_READABLE` and
`identity_measured = SSH_CLI`; do not change the canary tool, its hard-stop output, or its tests.
Web-PHP cross-site isolation remains `UNVERIFIED`. This SSH result proves neither isolation nor
readability under a site's web PHP.

A compromise of the account, SSH/FTP access, or a sibling application with sufficient filesystem
access could expose staging database credentials and the master key, allowing access to or alteration
of staging data and resources those credentials authorize. Ownership and an inactive website label
do not remove this filesystem risk. Suspected compromise or confirmed credential exposure still
invokes the existing incident-response and rotation procedure; this record does not dismiss one.

The current read-only configuration check establishes private runtime and qualification files at
mode 0600, distinct expected database names/users, absent scheduler credentials, and zero provider
credential records. It does not establish secret non-reuse across sibling sites. The qualification
suite may use only the separate disposable database, never the persistent staging runtime database.

## 3. Boundaries retained

- Preserve the independent public site, redirect, sibling sites and inactive future production home.
- Deploy only an exact prepared Git object; preserve private configuration, runtime storage and
  governed history, and publish content-hashed console assets manifest-last.
- Require Gate E.2 private-path refusal, real-host preflight/probes, migrations without drift,
  full strict qualification, runtime invariants, authenticated/manual smoke, security negatives,
  request-ID proof and the previous-release rollback/current-release restore drill.
- Admit no provider/API or production credentials during this continuation. Keep internal TEST use
  only, live read and scheduler inactive, and external action/customer visibility/production false.
- Record operational evidence truthfully. Never file `CROSS_SITE_ISOLATION` PASS for this deviation,
  and never move an authority lock because tests or an operational evidence record passed.

The covered work stops at Gate S. It does not authorize partner invitations, ROAD_TEST_001, provider
onboarding, live-read activation, scheduler activation, external actions or production. A changed
hosting/access scope or security incident requires reassessment against its actual facts; ownership
and the existing no-WordPress-maintenance instruction must not be repeatedly requested again.

## 4. Conditional exact-release local qualification during hosted billing failure

GitHub Actions run [35525291974](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/35525291974),
attempt 2, names PR13 SHA `322874b17500d837b2b9b974fde1e4e408b318d6`. All five hosted jobs failed before
running a step. The recorded annotation is:

> The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings

The hosted result remains failed/not executed. Do not call it GitHub green, successful hosted CI or
a test failure. Sanitized run/annotation evidence is retained in the local execution evidence.

**Local fallback status: `SATISFIED_FOR_EXACT_PR13_RELEASE`.** All 31 workflow shell steps completed locally with PHP 8.2.34. MariaDB 10.6.28 and 10.11.19 each passed 5,549 assertions with zero failures/skips. The original final aggregation passed. The local record preserves exact commands, versions, image digests and the disposable tmpfs adaptation for the 10.11 lane; no crash-durability claim is made. For this inert
staging execution only, Gate A may use complete local reproduction of the exact candidate's workflow
while the hosted billing failure prevents execution. Partial local tests, parent-SHA evidence or a
passing Loopia suite alone do not satisfy this route. The fallback is supported by the following requirements, all of which must remain satisfied when the
reviewed evidence contains all of the following:

1. Full tested Git SHA, canonical pin, clean object-export identity, the exact workflow bytes/hash,
   execution time, OS/runtime details, pinned toolchain versions and immutable container-image
   digests. Record actual PHP 8.2.x/extensions, Node 22.16.0 and npm 10.9.2; read MariaDB 10.6.x and
   10.11.x versions from their actual server connections rather than inferring them from image tags.
2. Every executable check from that SHA's `.github/workflows/ci.yml`, with command/step mapping and
   successful exit status: frontend clean install/tests/build/stage, runtime reporting, lock parsing,
   complete PHP syntax check, strict foundation and unit layers.
3. Both real MariaDB lanes: migrations, idempotent repeat and status, non-network capability probe,
   evidence-registry validator and the entire strict suite. Each sanitized report must be complete,
   unfiltered and strict, with positive pass count, zero failures and zero skips; preserve the actual
   PHP/database versions and report hashes.
4. The full release-preparation lane: pin assertions, fresh exact-object preparation and reported
   SHA, no secret/config or repository metadata leakage, resolvable manifest, independent Git-export
   byte comparison, local root-rsync state-preservation/template rehearsal, prepared-tree preflight
   and every ready/inertness assertion. Retain its prepared source fingerprint and artifact hashes.
5. The original final release-qualification checks applied to exactly two retained lane reports,
   verifying every required job succeeded and all report/schema/completeness/strict/version/count
   predicates. Preserve sanitized logs and artifact integrity locally; GitHub upload/download
   services and hosted-runner execution are not claimed to have occurred.

PR13 workflow SHA256: `24d25b902e9b6f97b28b9af5f4db1fd24c870c472e2b8eed91fe309cd0080dac`.
Any skipped, omitted, filtered, failed or mismatched check leaves this route unsatisfied. A completed
local reproduction is recorded as local qualification with the hosted billing deviation, never as
GitHub green or Loopia host proof. The ordinary host Gates B-S still apply.

**Chosen route: external operational record.** This documentation review/PR accompanies the unchanged
PR13 application artifact; the deployment SHA remains `322874b17500d837b2b9b974fde1e4e408b318d6`.
A commit of this documentation record is not silently substituted as the tested/deployed application.
Evidence qualifies only the exact SHA it names. If a later decision makes a documentation commit the
application candidate, freeze that new SHA and re-run its qualification/preparation; PR13's reports
and a source fingerprint that excludes documentation do not prove the new Git object.

## 5. Evidence handling

### 2026-09-26 follow-up: completed PR16 inert staging qualification

PR13's actual host strict result remains a `CONFORMANCE` FAIL: 5,548 passes, one failure and no
skips because the deployed package omitted `.github/workflows/ci.yml`. PR15 repaired the filter
and independently passed 5,551/0/0 locally and on Loopia. Its automated N/O/P passed; manual
inspection then found the relative logo URL and repeated workspace choices. PR16 repaired those
two defects and received its own exact-object qualification.

PR16 is `392f48deef11380f6dccfe88f2a47ba1fd94575c`, fingerprint
`d0fd0877a2b7039a129bf8d4f11d632f6066f9afd534eba79bff1a10e8ef8266`.
Hosted [run 36197550928](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/36197550928)
failed before runner execution, with five zero-step jobs and billing/spending annotations. Local
exact-head reproduction passed 34 shell steps and four strict lanes at 5,552/0/0. The real Loopia
strict suite separately measured 5,552/0/0 on PHP 8.5.9 and MariaDB 10.11.19.

PR16 E.2, host probes, 19 unchanged migrations, runtime invariants, anonymous N, authenticated N/O
(118/0), browser inspection, request-ID proof and PR16-to-PR15-to-PR16 rollback/restore passed.
The N/O count difference is fixture reuse, with the other 117 steps unchanged. Initial asset-pruning
and HTTP-wrapper failures remain in the evidence alongside their measured corrections.

Gate R preserves the PR13 failure, PR15 history and current PR16 evidence in 12 hash-bound records.
The allowed Gate S claim is inert staging qualification **under this owner-directed deviation**.
E.1 remains FAIL_READABLE, E.3 remains UNVERIFIED and the WordPress maintenance exclusion remains
in force. Neither ownership nor accepted staging risk is an isolation, hygiene or production PASS.
No authority lock or activation flag is changed by filing evidence. See
[the Gate R record](STAGING_GATE_R_EVIDENCE_2026-09-26.md).

Retain owner statements with the current conversation, sanitized canary findings, local account
inventory/version-header results, configuration-status report, hosted billing annotations and exact
local/host qualification outputs. Record file hashes only for sanitized non-secret evidence. Never
copy `.env`, keys, credentials, cookie jars or the unsanitized historical transcript into this record.

The evidence registry schema and runtime authority flags remain unchanged. Record
`OWNER_DIRECTED_STAGING_DEVIATION`, `E1 = FAIL_READABLE` and web-PHP `UNVERIFIED` in deployment review
notes. The observed update gap is carried without WordPress maintenance or a false hygiene PASS.
