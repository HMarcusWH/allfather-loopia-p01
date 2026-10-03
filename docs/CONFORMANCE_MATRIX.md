# CONFORMANCE MATRIX

What was verified, how, and — where it matters most — what was NOT.

Conformance here means governed OUTCOMES match, not that implementations resemble each other. A
port that produces different identifiers is conformant; one that produces a different reason code
is not.

---

## 1. Test execution, stated truthfully

**Current PR9 qualification measurement** — hosted GitHub Actions on PHP 8.2.33, against BOTH
supported MariaDB lines: 10.6.28-MariaDB and 10.11.19-MariaDB. Each matrix leg starts from an empty
database, reads back and asserts the actual server version, applies all migrations, re-applies them
to prove idempotency/no drift, runs the capability probe, validates the empty deployment-evidence
registry, then runs the full strict suite and publishes a sanitized machine-readable report.

| Layer | Files | Assertions | 10.6 | 10.11 |
| --- | --- | --- | --- | --- |
| foundation | 10 | 953 | PASS | PASS |
| unit | 2 | 153 | PASS | PASS |
| integration | 32 | 1797 | PASS | PASS |
| conformance | 6 | 395 | PASS | PASS |
| acceptance | 6 | 668 | PASS | PASS |
| security | 21 | 1302 | PASS | PASS |
| rollback | 3 | 92 | PASS | PASS |
| **TOTAL** | **80** | **5360** | **0 failing, 0 skipped** | **0 failing, 0 skipped** |

Migrations were applied from empty and then re-applied on both servers: 19 applied, 0 drift, then
19 skipped, 0 drift. **PR9 adds no migration.** The migration count remains 19.

The CI probe on both database versions reports `staging_host_verdict: READY` while deliberately
reporting `live_read_host_verdict: NOT_QUALIFIED`, because CI does not perform the host-specific DNS
resolution/outbound-HTTPS proof. `scheduler_transport_verdict` remains `UNVERIFIED`, the handler
registry remains empty, and the probe explicitly grants no runtime authority, live-read activation,
or deployment authorization. That distinction is part of the qualification result, not a caveat
added afterwards.

### Earlier local lock-timeout observation, retained rather than erased

At an earlier PR8 development revision, one local 10.6 run failed a single test —
`security/p07a_privacy_foundation`, with `SQLSTATE[HY000] 1205 Lock wait timeout exceeded` raised
by the harness's `TRUNCATE` reset at `tests/support/Harness.php:140`. It remains recorded because a
matrix that only ever shows the runs that passed is a matrix nobody can use to recognise this if it
happens again. It is **historical evidence**, not the current PR9 result above.

**What was ruled out.** The probe lowers `innodb_lock_wait_timeout` to demonstrate row locking, which
would produce exactly this symptom if it leaked — it does not: it is `SET SESSION`, and both servers
read back the 50-second default globally after the failure. So the reset genuinely waited fifty
seconds for a lock, which is a hold, not momentary contention.

**What was not established.** Nothing in the process list held a lock afterwards, and the run did not
reproduce: five consecutive clean runs at the same head followed it — three security lanes and two
full strict suites — plus one full 10.6 pass immediately before it. The holder was never identified.
The container's MariaDB instances had been hard-killed by the sandbox and restarted minutes earlier,
and the 10.6 instance logged InnoDB crash recovery on startup; that is a difference from CI, not an
explanation, and it is not offered as one.

**So it is UNATTRIBUTED, not dismissed.** Hosted CI has since run the full strict suite cleanly on
both supported MariaDB lines, including the current PR9 qualification above. If the timeout recurs,
the thing to capture before anything else is `information_schema.innodb_trx` and the full process
list *while the run is blocked* — after the timeout there is nothing left to see.

These current numbers are MEASURED, not remembered. CI wrote the same result as machine-readable
qualification reports for both database lines, and the final `release qualification` job downloaded
and re-read them rather than trusting matrix-job status alone.

**And they are measured in a way that cannot vary with the checkout.** A CI run once reported four
fewer than a developer machine at the same commit, because an assertion loop in
`tests/rollback/stand_down_verifier.php` ran once per file in the source-fingerprint inventory — and
the staged Operator Console bundle under `public_html/assets/operator-console/` is generated and
gitignored, so a tree that had run the frontend build carried four more files than one that had not.
A total that moves with the build state of the checkout is not a measurement of the suite. That
assertion is now one statement over the SET of covered directories, and the count is identical with
the bundle present and absent.

    php tests/run.php --strict --report-json=qualification-10.11.json
    TEST_ENV_FILE=.env.testing106 php tests/run.php --strict --report-json=qualification-10.6.json

The report carries the counts above, the PHP version, and the MariaDB version read back from the
connection the suite actually used — and no credential, DSN, fixture or assertion message, because
it is uploaded as a CI artifact. It also carries `"complete": true`, which is true only when neither
a layer nor a file filter narrowed the run: a partial run cannot be filed as a full one. CI writes
one per MariaDB lane and a `release qualification` job re-reads both and asserts `fail: 0`,
`skip: 0`, `complete` and `strict` rather than trusting the job status.

**The counts live here and nowhere else.** `DEPLOYMENT_RUNBOOK.md` used to carry its own, and it
went stale — a count in two places is a count that will eventually disagree with itself.

### What PR8 added to these numbers

| Layer | Added | What it proves |
| --- | --- | --- |
| foundation | `deployment_evidence_boundary.php`, `loopia_probe_contract.php` | that a claim asserted true is backed by a record, and that the probe's verdicts cannot quietly widen |
| acceptance | `beta_launch_journey.php` | the whole invited-partner journey as ONE composition, through the real HTTP kernel |
| security | `loopia_probe_safety.php`, `isolation_canary.php`, `staging_smoke_safety.php`, `release_preflight_safety.php` | that the four new host-facing commands touch no governed table, leak no secret, cannot hang, and refuse a configuration file that is publicly served |
| rollback | `stand_down_verifier.php` | that every contradiction of a stood-down posture produces a finding — and that the stood-down posture itself produces none |
| foundation | the web-PHP isolation policy block in `deployment_boundary.php` | that the blocker stays a non-waivable pre-secret gate in all three normative documents, and that the CLI canary never starts claiming to close it. It **throws** rather than asserting, deliberately: a structural policy over launch documentation should not inflate a published assertion total, so a violation fails the file while a clean policy adds no count |

### What PR9 adds to the qualification surface

| Layer | Added / changed | What it proves |
| --- | --- | --- |
| foundation | `release_packaging_boundary.php`, `runtime_storage_boundary.php` | release preparation comes from the exact Git object; Node and npm are pinned; deployment cannot silently ship a dirty checkout; destructive root rsync cannot own runtime storage or manifest-last console assets |
| acceptance | `unhandled_failure_over_http.php` | an unexpected handler failure becomes a sanitized 500 that still carries the request support reference rather than escaping the request boundary |
| security | `runtime_invariant_probe.php` | the real runtime database can be qualified against the TEST workspace external-effect CHECK and a real append-only governance table without widening M10's canonical caller/reference surface |
| rollback | `source_fingerprint_release_assets.php` | the release fingerprint commits to the current manifest and referenced assets while intentionally retained historical content hashes do not create false rollback failures |
| runtime/support | `RequestLog` + deployment storage rules | support references are written to deterministic private `storage/request.log`, and release deployment preserves that runtime state rather than deleting or uploading it from a checkout |
| runbook/rollback | first-release and later-release procedures | the first deployment honestly proves `INITIAL_RELEASE_RESTORE`; only a later deployment can claim `PREVIOUS_RELEASE_ROLLBACK` |

Run with `php tests/run.php --strict`. Under `--strict` a skip is a non-zero exit, so an
unreachable database cannot be mistaken for a green suite.

**Pointing the suite at a second server needs `TEST_ENV_FILE`.** The harness used to resolve its
connection from `.env.testing` and `.env` only, and it ignored the `--env=` flag that `ops/migrate.php`
accepts. Running against 10.6 therefore meant editing `.env.testing` in place — and a run launched
with `--env=.env.mariadb106` silently connected to 10.11 anyway while reporting a clean pass. That
very nearly became a recorded 10.6 result for a run that never touched 10.6. `TEST_ENV_FILE=<path>`
now wins over both conventional files and throws if the file is missing, so the mistake fails loudly
instead of passing quietly.

**Every database-backed layer ran against real MariaDB — BOTH 10.6 and 10.11**, not SQLite. This
matters twice over. The port's guarantees lean on MariaDB CHECK constraints and `SIGNAL SQLSTATE
'45000'` triggers, so a suite executed against SQLite would prove nothing about either. And Loopia
Företag is 10.6 while 10.11 is Företag Plus — proving only 10.11 would have proved the tier a
customer may not be on. CI runs both as a matrix, and each job asserts the server version it
actually got rather than the one it asked for.

### Independently verified vs still unverified

- **Hosted GitHub Actions CI has independently verified the full strict suite on PHP 8.2.33 against
  BOTH MariaDB 10.6.28 and 10.11.19.** Each matrix leg reads back and asserts the actual server
  version before migrations and the strict suite. The current PR9 qualification is 5360 passing,
  zero failing and zero skipped on each database line, and the separate release-qualification job
  re-read both reports successfully.

  Earlier CI **failed** on commits `d92c1fd` and `f12d249`, and the failure was real: an acceptance
  assertion queried `information_schema` with an unordered `LIMIT 1` and matched a different
  table's constraint on the runner than it did locally. Fixed in `5d1a16e`, which also scoped the
  five other instances of the same pattern. Recorded here rather than quietly repaired, because
  an earlier draft of this document claimed CI had "reported green on pushed commits" — that
  claim was made from notification summaries rather than from the runs, and it was wrong.
- **Loopia shared hosting has not been tested.** The target host is stated in the README; no
  deployment to it has occurred. See `DEPLOYMENT_RUNBOOK.md`.
- **No road test has been executed.** See `LOOPIA_CAPABILITY_REPORT.md` §5.
- **`deployment-evidence.json` carries zero records**, which is the machine-readable form of the host
  statements above. Every evidence-backed operational claim is therefore false, and
  `tests/foundation/deployment_evidence_boundary.php` fails the build if one is asserted without a
  record. What a green suite establishes is that the CODE is qualified; it establishes nothing about
  Loopia, which is why a CI-produced record can only ever be filed with `environment: "ci"` and
  supports no host claim. See `DEPLOYMENT_EVIDENCE_MODEL.md` §4.2.

## 2. Conformance cases

Seven files, answering seven different questions.

| File | Compares | Against |
| --- | --- | --- |
| `p01_governed_transcripts.php` | normalized decision transcripts for seven bent authority states | the outcome each is required to produce |
| `p01_source_policy_adapter.php` | every compiler fail-closed code as P01 renders it, and the closure of the map | `src/p01/capture-source.ts` |
| `governed_vocabulary.php` | the closed vocabularies, member by member and in order; and the RELEASE TOPOLOGY — that M10 owns release for every module, that M16 admits exactly four artifact kinds, that exactly TWO product families adopt it (P01 and P02, as canonical does), and that P03/P04/P05 keep their own canonical release paths | `release-output/vocabulary.ts`, `action-class.ts`, `ingestion/policy.ts`, the storage CHECKs, and the product code itself |
| `m10_release_conformance.php` | CONF-M10-001 … CONF-M10-010: the release path's outcome and its EXACT reason code for every branch — valid ALLOW, missing verdict, non-ALLOW verdict, ALLOW routed elsewhere, invalidated verdict, unsatisfied conditions, satisfied conditions, proposal not releasable, scope mismatch, digest mismatch | `appendInternalReleaseDecisionRecord` in `m10/ledger.ts` |
| `m10_stream_identity.php` | that a ledger scope is (tenant, workspace, **environment**): idempotency per environment, TEST/DEMO/LOCAL_DEV coexisting, the canonical default stream keys, and genesis anchors against **fixed vectors produced by the canonical TypeScript encoder** | `createDecisionLedgerStream` / `ensureActiveDecisionLedgerStream` in `m10/ledger.ts`, and `digest.ts` |
| `p07a_delivery_conformance.php` | the P07-A synthetic dispatch end to end — dispatch outcome, final authorization and artifact state, the delivery row, every M10 record field, the reference SET by role (owner module, object type character for character, version-or-digest, verification status), the writer identity, and the `record_payload_digest` itself against **a vector produced by RUNNING canonical `dispatchOutreachSynthetic`**. Plus the port-wide object-type vocabulary | `p07/outreach-delivery.ts`, and `computeRecordPayloadDigest` in `m10/ledger.ts` |
| `p03_assurance.php` | the three P03 post-outcome audits (P08) over 62 declared fixtures — refusal codes, reason codes in canonical order, every run/check/finding field the audit sets, the incident request, case and `OPENED` timeline entry (with the one pre-existing M11 recorder/ref difference pinned), row-count effects, and that the audited rows did not change — plus a 47-instant `Date.parse` battery for stored instants (ES forms equal canonical; non-ES forms unreadable, UF-005), against **vectors produced by RUNNING canonical `assurance.ts`** | `p03/assurance.ts` @ `1c40306c`, with its source SHA-256 recorded in the fixture |

The sixth and seventh are the only ones whose expected values were not written by hand (the seventh's
generator is `tests/fixtures/p03-assurance-golden-generator.mts`; see `P03_ASSURANCE_PORT.md` §3).
`ops/conformance/p07a-canonical-vectors.ts` opens a real canonical SQLite database, drives a real
outreach through the real canonical governed path, calls the real `dispatchOutreachSynthetic`, and
reads back what the canonical ledger recorded; the pinned digest comes out of canonical's own
encoder. No PHP produced any value in `tests/fixtures/p07a_canonical_vectors.json`, and the fixture
carries the canonical head it was generated at, asserted against `upstream.lock.json`, so vectors
from a head this projection is no longer pinned to cannot pass quietly.

That distinction is the point rather than a nicety. If both sides of a digest comparison are
computed in PHP, the test asserts that the test agrees with itself — and the defect it exists to
prevent is a record that hashes cleanly over the WRONG content. Object identifiers are per-run in
both runtimes, so the fixture pins a fixed id per reference role and a fixed sequence number;
everything else the digest commits to is this port's own output.

The reason codes in the fourth are not normalized. Six of them distinguish six different reasons a
release can be refused, and an operator reading a refused release needs to know whether the verdict
was invalidated or the proposal had already moved. Its companion,
`tests/security/m10_release_ownership.php`, proves the same guards by reversion rather than by
assertion — see §4.

The third exists because of what the port loses at the language boundary. Upstream holds these
vocabularies as TypeScript union types and its compiler proves exhaustively that every consumer
handles every member, so a member added upstream cannot reach production unhandled. PHP has no
equivalent check anywhere. Vocabulary drift is therefore the failure mode this port is most exposed
to, and it is silent — a missing member is not a crash, it is a state nothing refuses.

Where a vocabulary is also pinned in storage, the CHECK constraint is compared as well: a constant
and a constraint that disagree is a gate that refuses in one layer and admits in the other.

### 2.1 P01 transcript cases


`tests/conformance/p01_governed_transcripts.php`. Each compares a normalized transcript —
decision, reason codes, and whether an artifact materialized. Ids and timestamps are stripped;
reason codes are read from the RECORDED verdict rather than a return value, because what the
arbiter wrote down is what an auditor reads.

| ID | Input authority state | Expected outcome | Reason codes |
| --- | --- | --- | --- |
| CONF-001 | source card review expired | REFUSED / HOLD | `STALE_INPUT`, `ARBITER_P01_RELEVANCE_DENIED` |
| CONF-002 | licence class PROHIBITED | REFUSED / HOLD | `SOURCE_AUTHORITY_TOO_LOW`, `ARBITER_P01_RELEVANCE_DENIED` |
| CONF-003 | change significance UNKNOWN | REFUSED / REQUIRE_MORE_EVIDENCE | `INSUFFICIENT_EVIDENCE`, `ARBITER_P01_RELEVANCE_DENIED` |
| CONF-004 | any held verdict | nothing released, tenant-wide | — |
| CONF-005 | interpretation HYPOTHESIS | ADMITTED, artifact says HYPOTHESIS | — |
| CONF-006 | clean chain | ADMITTED / ALLOW_INFORMATIONAL_RELEASE, released, ledger-backed | `ARBITER_ADMISSIBLE_FOR_INTERNAL_RELEASE` |
| CONF-007 | foreign tenant | REFUSED, nothing released | `BATTLECARD_SCOPE_MISMATCH` |

CONF-003 is worth a note: the correct verdict is `REQUIRE_MORE_EVIDENCE`, not `HOLD`. The
distinction is the point — it names what would change the answer.

## 3. Security verification method

Every security claim in this port is proved by REVERSION: the system is put into the state the
guard exists to refuse, and the refusal is asserted. A test that only exercised the happy path
would prove the feature works, not that the fence holds.

| Area | File | What is reverted |
| --- | --- | --- |
| M02 access | `security/m02_access.php` | excluded permissions, cross-tenant principals |
| M06 live capture | `security/m06_live_capture.php` | egress policy, DNS pinning, redirect revalidation |
| P02 privacy | `security/p02_*` | each of four field prohibitions, independently |
| P04 privacy | `security/p04_privacy_admission.php` | each of five admission rules, plus both release-boundary re-checks |
| P05 admission | `security/p05_marketing_admission.php` | each of five rules, the digest re-check, and the three audits |
| P07-A privacy | `security/p07a_privacy_foundation.php` | five consent directions, firewall entrance, erasure, UUID false positive |
| P07-A chain | `security/p07a_artifact_chain.php` | each digest-chain link, the facts binding, separation of duties, single use |
| Rollback | `rollback/stand_down_discipline.php` | five withdrawal levers, each proved to withdraw AND to preserve |

Where a guard turned out to be enforced structurally rather than in code, the test asserts the
STORAGE refuses the mutation. That is a stronger result and is recorded as such — see
`SECURITY_BOUNDARY.md` §2.

## 4. Defects found and fixed during the port

Recorded because a conformance document that lists only successes is not evidence of anything.

| Defect | Where | Consequence had it shipped |
| --- | --- | --- |
| The P07-A dispatch ran as FOUR separate transactions | P07-A | upstream opens `BEGIN IMMEDIATE` before the consent re-check and does not commit until the delivery row is in, and says why: the no-effect path must never strand a CONSUMED authorization with no truthful outcome. The port could produce both states upstream forbids — a CONSUMED authorization with nothing recorded against it, and a CONSUMED authorization with an M10 record but no delivery row. Invisible on the happy path, where a split unit and an atomic one are indistinguishable |
| `Database::transaction()` COMMITTED a returned refusal | every composite command | the same defect one level up, and the reason the P07-A one was not a one-off. Upstream's composite commands all read `if (step !== OK) { ROLLBACK; return REJECTED; }`; this port committed whatever the callback returned, so a refusal after four of five steps left those four writes durable while the caller was told the command refused — an evidence object and a bundle with no proposal, a released briefing with both status advances and no M16 artifact |
| The P07-A M10 record diverged from canonical in seven fields | P07-A | OUTCOME_BASIS cited an invented M09 `SYNTHETIC_DISPATCH_OUTCOME` identified by the authorization id — pointing the mandatory outcome basis at the object AUTHORIZATION_BASIS already cited; object types were upper-cased; three references carried version digests upstream does not set; the summary was paraphrased; `reason_codes` was empty; `proposal_id` was set and upstream does not set it; and severity S3 with the incident flag were absent, so a FAILED delivery was recorded as needing no operator triage. Every one is inside `record_payload_digest`, so each produced a different digest for the same governed event while verifying perfectly |
| M10's generic append defaulted the writer to the record's own id | M10 | `recorded_by_service_identity_id ?? $recordId` satisfied NOT NULL and destroyed the column's meaning: the record claimed to have been written by itself. All three runtime callers were affected. The schema had been shaped around it — the column was CHAR(36), the width of a UUID and of a decision_record_id, because the only values it had ever held were record ids |
| Two P02 status-history tables named their own subject as the actor | P02 | the twin's id, and the edge's id, recorded as the actor that created them. Upstream hardcodes actor type SYSTEM and defaults the id to `"system"` — and the sibling release paths in the same two files already did it correctly, so this was an inconsistency rather than a decision |
| M03 could issue unlimited ACTIVE contracts to one Valkyrie | M03 | a contract IS an agent's permitted action classes, so two ACTIVE ones make its authority depend on which row a query returns first. There was no check and no index; upstream has both and says so in a comment on this exact rule |
| `arbiter_policy_sets` had no scope uniqueness index at all | M08 | not even the non-unique lookup index the standing partial-index translation calls for. Two ACTIVE policy sets for one scope make ALLOW or BLOCK depend on row order |
| M10 collapsed TEST and DEMO ledger identity | M10 | a caller asking for the DEMO ledger received the TEST one and appended DEMO history to it. Silent by construction: the chain still verified, because a hash chain has no opinion about which environment its records came from. Three faults had to coincide — the ACTIVE lookup omitted `environment_class`, the default stream key embedded tenant and workspace (so the real unique index on `(tenant, workspace, stream_key)` allowed one stream per scope for all time), and the genesis anchor digested the random stream id instead of the logical identity |
| The M10 release path validated NOTHING, and M04 performed the RELEASED mutation | M10 / M04 | the record the console treats as PROOF of release provable by any caller willing to assemble a basis; two modules owning release, in two transactions, so a failure between them left a durable release record against a still-SUBMITTED proposal — and the retry would mint a second |
| `ReleasePathCapability` had a public static factory | M10 | the guard on the release record type was satisfiable by any module that could name the class; the docblock claimed a strength the code did not have |
| The M16 audience ceiling was capped to match the activation policy | M16 | the stored source-use profile asserted that a licence permitting customer output does not permit it — a false claim about the SOURCES, defended in three documents as "stricter, never looser" |
| `audiencePermittedByCeiling` compared across two vocabularies | M16 | an audience ceiling that silently permitted the wrong audience |
| `rollbackArtifact` did not withdraw visibility, and was not idempotent | M16 | a rolled-back artifact still visible on the surface |
| seeded diff digest used a different projection than the detector | test seed | tests passing over state the product could not reach |
| `issueContract` hardcoded `contract_version` to 1 | M03 | a Valkyrie could never be reissued a contract |
| UUIDs read as phone numbers to the PII detector | `P02\PrivacyPatterns` | ~24% of safe references quoting a record id would open spurious S4 incidents — measured over 20,000 ids — teaching operators the PII audit cries wolf |

The first four were all found the same way, and it is worth naming the way: by asking what happens
when a step in the middle FAILS. Every one of them is invisible on the happy path, which is the only
path the tests exercised, and the P07-A record divergence had a test asserting the port's own
paraphrase of the summary — a fixture that had turned a divergence into a pinned requirement. The
proofs are now `tests/security/p07a_dispatch_atomicity.php` (failures injected deterministically by
trigger, not raced) and `tests/conformance/p07a_delivery_conformance.php` (expected values from
canonical, not from PHP).

The first is the one worth dwelling on. It was not found by a failing test — every test passed
throughout, because the tests asserted the release SUCCEEDED using fabricated UUID basis rows, which
is exactly the behaviour the defect produced. A test that proves a defect is worse than no test: it
converts the defect into a pinned requirement. That test now releases a real seeded governance chain
and asserts M10 built the basis and moved the status itself.

The last is the one worth dwelling on for a different reason: it was found because a test failed intermittently, and the
intermittency was the symptom. It is now pinned by a regression test in both directions — UUIDs do
not flag, and a real phone number adjacent to a UUID still does.

## 5. Characterized divergences from canonical

Four, each recorded in `PORT_SCOPE_MATRIX.md` §4.2 with its observable difference:

1. **P04 `observeRoleClass`** adds a subject-company identifier scan, placed AFTER all upstream
   guards so no upstream reason code changes.
2. **P05 `p05_source_still_released`** does not reproduce an upstream branch that cannot fire —
   `INVALIDATED_AT_RELEASE` is in no reliance vocabulary. Observable behaviour is identical.
3. **P05 `captureClaimBasis`** is an addition, closing an upstream hole where the citation gate and
   the citation audit disagreed about what counts as a citation and only the audit was right.

4. **`ux_arbiter_policy_sets_scope_one_active` does not defend NULL scopes.** Canonical keys it by
   `COALESCE(tenant_id, '*'), COALESCE(workspace_id, '*'), policy_key` so that platform-wide policy
   sets, whose scope columns are NULL, still collide with each other. MariaDB refuses a string
   literal inside an indexable generated column (error 1901); every spelling was tried on 10.11.19
   — COALESCE, IFNULL, CASE, CAST, CONCAT, COLLATE on the expression, COLLATE on the column,
   VIRTUAL and STORED — and column-to-column with no literal is accepted, which identifies the
   literal as the cause. **This is a genuine host limitation, not a preference.** It is sound only
   while nothing writes a NULL-scoped policy set, so that is asserted rather than assumed: the sole
   writer's scope parameters are non-nullable `string` (checked by reflection), it is the only file
   that writes the table (checked by scan), and no such row exists (checked by query). The day
   someone adds a platform-wide seed, this index stops covering everything — and the test says so.

Plus one partial port, characterized in §4.1: adapter conformance proves identity has not drifted,
not conformance to a harness descriptor contract.

**Two former entries were removed rather than reworded.** Both concerned the M16 audience ceiling —
the card path reaching `OPERATOR_ONLY` where canonical reaches `TENANT_INTERNAL`, and both paths
capping the recorded ceiling at `TENANT_INTERNAL`. They were defended as "stricter, never looser",
which is not the same claim as conformant: the ceiling states what the SOURCES permit, and this port
was answering that question differently from canonical in order to encode a deployment decision the
activation pin already encodes. There was no host necessity. Both compile paths now call one fold
implementing canonical exactly, and `governed_vocabulary.php` asserts their equality.
