# CONFORMANCE_PLAN

**Plane:** Ops — verification design.
**Upstream oracle SHA:** `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`

Conformance here means **governed-outcome equivalence**, not implementation equivalence. The
question a fixture answers is: *given this authority state and this input, does the Loopia
projection reach the same governed decision, with the same reason code, the same
FACT/HYPOTHESIS classification, the same visibility and the same reliance as the canonical
implementation?*

---

## 1. Fixture format

Fixtures are language-neutral JSON under `tests/conformance/fixtures/`, so neither side owns the
comparison format:

```json
{
  "fixture_id": "CF-P01-004",
  "upstream_provenance": {
    "sha": "c6d9cf9a7a1001b001ca6118999e9cb75c831c4d",
    "test": "packages/allfather-control-substrate/src/p01/capture-source.test.ts",
    "case": "refuses capture when the source card review window has expired"
  },
  "authority_state": { "...": "seed operations, in order" },
  "input": { "...": "the command under test" },
  "expected": {
    "outcome": "REFUSED",
    "reason_codes": ["SOURCE_CARD_REVIEW_EXPIRED"],
    "interpretation_kind": null,
    "released": false,
    "visible": false,
    "current_reliance": null,
    "audit_findings": []
  }
}
```

## 2. Normalization

**Normalized away** (implementation-incidental):

- object identifiers — replaced by stable `$ref` labels assigned in first-appearance order;
- timestamps — replaced by a monotonic logical clock (`T0`, `T1`, …);
- database sequence values, row counts used only for ordering, PHP/Node type spellings
  (`1` vs `true` for a CHECK-pinned flag).

**Never normalized** (these are the conformance claim):

- reason codes and error codes;
- policy decisions and arbiter verdicts;
- interpretation kind (`FACT_OBSERVED` / `HYPOTHESIS`);
- release eligibility and release state;
- visibility;
- scope denial;
- current reliance;
- audit findings and incident openings;
- append-only history preservation.

## 3. Test layers

| Layer | Location | Runs against | Proves |
| --- | --- | --- | --- |
| Pure unit | `tests/unit/` | no DB | digest/canonicalization, IP classification, fit + ICP scoring, reason-code mapping, redaction, visibility/reliance predicates |
| MariaDB integration | `tests/integration/` | real MariaDB | migrations, CHECKs, FKs, triggers, transactions, tenant/workspace isolation, idempotency, job leases, the HTTP console end to end (sign-in, CSRF, escaping, per-surface denial) |
| Conformance | `tests/conformance/` | real MariaDB | normalized transcripts vs the canonical expectations |
| Acceptance | `tests/acceptance/` | real MariaDB | full SF-P01…SF-P07-A verticals end to end |
| Security | `tests/security/` | real MariaDB + fake transport | auth, scope, SSRF, secret handling, source policy, cross-tenant/workspace, privacy admission |
| Rollback | `tests/rollback/` | real MariaDB | per-product stand-down + whole-deployment stand-down |

**MariaDB parity rule:** integration, conformance, acceptance, security and rollback layers must
execute against MariaDB. A result obtained against SQLite is not a MariaDB parity claim and must
not be recorded as one.

## 4. Acceptance flows ported from upstream

| Upstream fixture | Upstream test | Loopia scenario |
| --- | --- | --- |
| SF-P01-001 | `p01/acceptance-sf-p01.test.ts` | snapshot N-1 → N → MATERIAL diff → signal → review → ALLOW → release → RELEASED + VISIBLE; replay with no live effect; stand-down |
| SF-P02-001 | `p02/acceptance-sf-p02.test.ts` | twin state change → review → admission → release → surfacing; relationship path; stand-down |
| SF-P03-001 | `p03/acceptance-sf-p03.test.ts` | catalogue → pairing → queue admission → release → VISIBLE → battlecard; Watch-List path; tier default-deny; stand-down |
| SF-P04-001 | `p04/acceptance-sf-p04.test.ts` | briefing composition → sensitivity review → admission → release → three-gate visibility → acknowledgement; stand-down |
| SF-P05-001 | `p05/acceptance-sf-p05.test.ts` | draft composition → review → admission → internal release → surfacing; stand-down |
| SF-P07-001 | `p07/acceptance-sf-p07.test.ts` | consent → pseudonymize → frozen artifact → admission → approval → single-use authorization → **synthetic** dispatch; replay with no re-dispatch; revocation |

## 5. Required refusal cases

Conformance is **not complete** until every case below is exercised and passing. These map
one-to-one onto §17 of the porting directive.

### P01
`SOURCE_NOT_APPROVED` · prohibited licence · prohibited use · terms/robots not reviewed ·
source expired · out-of-scope URL · unsafe IP · redirect boundary · oversize response ·
content type · cross-tenant source · cross-workspace source · stale-source behavior ·
missing evidence → no release · cross-scope evidence → no release · non-reconciling diff →
no release · M07 producer self-review → refuse · M08 HOLD/BLOCK → no release · source authority
changed after verdict → release refusal · unsubscribed tier → invisible · superseded M10
reliance → invisible · inactive M16 projection → invisible.

### P02
cross-scope state change → refuse · excluded object family (offering twins) stays excluded ·
uncertainty preserved · superseded twin state no longer current · rollback preserves history.

### P03
inactive catalogue → refuse · product inactive → refuse · signal not released → refuse ·
insufficient fit evidence → refuse · outside customer interest → refuse · **fit hypothesis never
becomes buyer intent** · no buyer trigger → priority queue BLOCK · excluded ICP segment → BLOCK ·
Watch List distinct from priority path · cross-tenant/workspace → refuse · rollback removes
visibility without deleting history.

### P04
unauthorized role → invisible · wrong role target → invisible · sensitive briefing without legal
basis → refuse/hold · **individual identifier → BLOCK** · covert employee telemetry →
impossible + audit finding · redaction requirement enforced · superseded source intelligence →
no valid downstream visibility.

### P05
uncited competitor claim → BLOCK · forbidden marketing pattern → BLOCK · sensitive-trait
targeting → BLOCK · unreleased source → refuse · **external publish impossible** ·
**external send impossible** · rollback removes visibility while preserving history.

### P07-A
no consent/lawful basis → BLOCK · raw personal locator crossing the pseudonymized boundary →
refuse · drafter approving own execution → refuse · frozen payload mutation → refuse · second
authorization use → refuse · failed synthetic delivery → **no auto-retry** · revoked
authorization unusable · replay → no synthetic re-dispatch · **no live external call, provably**.

### Egress guard (M15)
method not GET/HEAD · scheme not HTTPS · malformed URL · host not allowlisted · origin/port
change · blocked IPv4 (0/8, 10/8, 127/8, 169.254/16 incl. metadata, 172.16/12, 192.168/16,
100.64/10, ≥224) · blocked IPv6 (`::`, `::1`, mapped-hex forms, non-2000::/3, 6to4 `2002::/16`,
Teredo `2001:0::/32`) · unresolvable host · redirect crossing boundary · too many redirects ·
response too large · content type not allowed · **DNS-rebinding TOCTOU: the connect address
equals the vetted address**.

## 6. Reason-code discipline

The canonical reason code is the conformance surface. The UI may render human-readable text, but
the audit record and the conformance transcript carry the canonical code. Generic replacements
("Invalid request", "Forbidden", "Something went wrong") are conformance failures.

Unknown policy state is `DENY` / `HOLD` / `REFUSE` — never `ALLOW`.

## 7. Honesty rule for CI

If hosted CI cannot run (upstream records `HOSTED_CI_LAST_KNOWN_BLOCKER=ACTIONS_CREDITS_EXHAUSTED`),
that is recorded truthfully in `CONFORMANCE_MATRIX.md` and every possible test is run locally
against real MariaDB with its version recorded. **No hosted PASS is invented.**
