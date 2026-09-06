# CONFORMANCE MATRIX

What was verified, how, and — where it matters most — what was NOT.

Conformance here means governed OUTCOMES match, not that implementations resemble each other. A
port that produces different identifiers is conformant; one that produces a different reason code
is not.

---

## 1. Test execution, stated truthfully

**Executed in this development container** — PHP 8.4.19, against BOTH servers: MariaDB
10.11.14-MariaDB-0ubuntu0.24.04.1 and MariaDB 10.6.28-MariaDB-ubu2204, each version read back from
the server it ran on rather than assumed from how the run was launched:

| Layer | Files | Assertions | 10.6 | 10.11 |
| --- | --- | --- | --- | --- |
| foundation | 3 | 144 | PASS | PASS |
| unit | 2 | 153 | PASS | PASS |
| integration | 29 | 1605 | PASS | PASS |
| conformance | 4 | 207 | PASS | PASS |
| acceptance | 3 | 163 | PASS | PASS |
| security | 11 | 702 | PASS | PASS |
| rollback | 1 | 27 | PASS | PASS |
| **TOTAL** | **53** | **3001** | **0 failing, 0 skipped** | **0 failing, 0 skipped** |

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

### What has NOT been independently verified

- **Hosted GitHub Actions CI runs the full suite against MariaDB 10.11.19 on PHP 8.2.33.** It is
  the only environment that has verified this code on the SUPPORTED PHP floor rather than on
  8.4 — worth noting, because the development container runs 8.4.19 and a language-level
  regression would show up first here.

  CI **failed** on commits `d92c1fd` and `f12d249`, and the failure was real: an acceptance
  assertion queried `information_schema` with an unordered `LIMIT 1` and matched a different
  table's constraint on the runner than it did locally. Fixed in `5d1a16e`, which also scoped the
  five other instances of the same pattern. Recorded here rather than quietly repaired, because
  an earlier draft of this document claimed CI had "reported green on pushed commits" — that
  claim was made from notification summaries rather than from the runs, and it was wrong.
- **Loopia shared hosting has not been tested.** The target host is stated in the README; no
  deployment to it has occurred. See `DEPLOYMENT_RUNBOOK.md` §8.
- **No road test has been executed.** See `LOOPIA_CAPABILITY_REPORT.md` §5.

## 2. Conformance cases

Four files, answering four different questions.

| File | Compares | Against |
| --- | --- | --- |
| `p01_governed_transcripts.php` | normalized decision transcripts for seven bent authority states | the outcome each is required to produce |
| `p01_source_policy_adapter.php` | every compiler fail-closed code as P01 renders it, and the closure of the map | `src/p01/capture-source.ts` |
| `governed_vocabulary.php` | the closed vocabularies, member by member and in order; and the RELEASE TOPOLOGY — that M10 owns release for every module, that M16 admits exactly four artifact kinds, that exactly TWO product families adopt it (P01 and P02, as canonical does), and that P03/P04/P05 keep their own canonical release paths | `release-output/vocabulary.ts`, `action-class.ts`, `ingestion/policy.ts`, the storage CHECKs, and the product code itself |
| `m10_release_conformance.php` | CONF-M10-001 … CONF-M10-010: the release path's outcome and its EXACT reason code for every branch — valid ALLOW, missing verdict, non-ALLOW verdict, ALLOW routed elsewhere, invalidated verdict, unsatisfied conditions, satisfied conditions, proposal not releasable, scope mismatch, digest mismatch | `appendInternalReleaseDecisionRecord` in `m10/ledger.ts` |

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
| The M10 release path validated NOTHING, and M04 performed the RELEASED mutation | M10 / M04 | the record the console treats as PROOF of release provable by any caller willing to assemble a basis; two modules owning release, in two transactions, so a failure between them left a durable release record against a still-SUBMITTED proposal — and the retry would mint a second |
| `ReleasePathCapability` had a public static factory | M10 | the guard on the release record type was satisfiable by any module that could name the class; the docblock claimed a strength the code did not have |
| The M16 audience ceiling was capped to match the activation policy | M16 | the stored source-use profile asserted that a licence permitting customer output does not permit it — a false claim about the SOURCES, defended in three documents as "stricter, never looser" |
| `audiencePermittedByCeiling` compared across two vocabularies | M16 | an audience ceiling that silently permitted the wrong audience |
| `rollbackArtifact` did not withdraw visibility, and was not idempotent | M16 | a rolled-back artifact still visible on the surface |
| seeded diff digest used a different projection than the detector | test seed | tests passing over state the product could not reach |
| `issueContract` hardcoded `contract_version` to 1 | M03 | a Valkyrie could never be reissued a contract |
| UUIDs read as phone numbers to the PII detector | `P02\PrivacyPatterns` | ~24% of safe references quoting a record id would open spurious S4 incidents — measured over 20,000 ids — teaching operators the PII audit cries wolf |

The first is the one worth dwelling on. It was not found by a failing test — every test passed
throughout, because the tests asserted the release SUCCEEDED using fabricated UUID basis rows, which
is exactly the behaviour the defect produced. A test that proves a defect is worse than no test: it
converts the defect into a pinned requirement. That test now releases a real seeded governance chain
and asserts M10 built the basis and moved the status itself.

The last is the one worth dwelling on for a different reason: it was found because a test failed intermittently, and the
intermittency was the symptom. It is now pinned by a regression test in both directions — UUIDs do
not flag, and a real phone number adjacent to a UUID still does.

## 5. Characterized divergences from canonical

Three, each recorded in `PORT_SCOPE_MATRIX.md` §4.2 with its observable difference:

1. **P04 `observeRoleClass`** adds a subject-company identifier scan, placed AFTER all upstream
   guards so no upstream reason code changes.
2. **P05 `p05_source_still_released`** does not reproduce an upstream branch that cannot fire —
   `INVALIDATED_AT_RELEASE` is in no reliance vocabulary. Observable behaviour is identical.
3. **P05 `captureClaimBasis`** is an addition, closing an upstream hole where the citation gate and
   the citation audit disagreed about what counts as a citation and only the audit was right.

Plus one partial port, characterized in §4.1: adapter conformance proves identity has not drifted,
not conformance to a harness descriptor contract.

**Two former entries were removed rather than reworded.** Both concerned the M16 audience ceiling —
the card path reaching `OPERATOR_ONLY` where canonical reaches `TENANT_INTERNAL`, and both paths
capping the recorded ceiling at `TENANT_INTERNAL`. They were defended as "stricter, never looser",
which is not the same claim as conformant: the ceiling states what the SOURCES permit, and this port
was answering that question differently from canonical in order to encode a deployment decision the
activation pin already encodes. There was no host necessity. Both compile paths now call one fold
implementing canonical exactly, and `governed_vocabulary.php` asserts their equality.
