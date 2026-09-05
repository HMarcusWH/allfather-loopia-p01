# CONFORMANCE MATRIX

What was verified, how, and — where it matters most — what was NOT.

Conformance here means governed OUTCOMES match, not that implementations resemble each other. A
port that produces different identifiers is conformant; one that produces a different reason code
is not.

---

## 1. Test execution, stated truthfully

**Executed in this development container** — PHP 8.4.19, MariaDB 10.11.14:

| Layer | Files | Assertions | Result |
| --- | --- | --- | --- |
| foundation | 3 | 99 | PASS |
| unit | 2 | 153 | PASS |
| integration | 24 | 1148 | PASS |
| conformance | 2 | 110 | PASS |
| acceptance | 1 | 32 | PASS |
| security | 10 | 656 | PASS |
| rollback | 1 | 27 | PASS |
| **TOTAL** | **43** | **2225** | **PASS, 0 failing, 0 skipped** |

Run with `php tests/run.php --strict`. Under `--strict` a skip is a non-zero exit, so an
unreachable database cannot be mistaken for a green suite.

**Every database-backed layer ran against MariaDB 10.11**, not SQLite. This matters because the
port's guarantees lean on MariaDB CHECK constraints and `SIGNAL SQLSTATE '45000'` triggers, and a
suite executed against SQLite would prove nothing about either.

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
| `audiencePermittedByCeiling` compared across two vocabularies | M16 | an audience ceiling that silently permitted the wrong audience |
| `rollbackArtifact` did not withdraw visibility, and was not idempotent | M16 | a rolled-back artifact still visible on the surface |
| seeded diff digest used a different projection than the detector | test seed | tests passing over state the product could not reach |
| `issueContract` hardcoded `contract_version` to 1 | M03 | a Valkyrie could never be reissued a contract |
| UUIDs read as phone numbers to the PII detector | `P02\PrivacyPatterns` | ~24% of safe references quoting a record id would open spurious S4 incidents — measured over 20,000 ids — teaching operators the PII audit cries wolf |

The last is the one worth dwelling on: it was found because a test failed intermittently, and the
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
