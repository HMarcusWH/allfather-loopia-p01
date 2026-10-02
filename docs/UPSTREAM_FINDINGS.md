# UPSTREAM_FINDINGS

**Plane:** Sources — observations about the canonical implementation, recorded during the Loopia
port. **This repository does not modify the canonical repository.** Each finding is reported here
for the owner to route through upstream's own governed change path.

**Canonical head examined:** `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`
**Recorded:** 2026-09-04

---

## UF-001 — `isBlockedIp` accepts non-canonical IPv4 spellings that a C connect path reads differently

**Where:** `packages/allfather-control-substrate/src/ingestion/egress-guard.ts`, `isBlockedIpv4`
**Severity assessment:** low exploitability at present, high consequence if reached.
**Status:** REPORTED — not fixed here (this repository holds no authority over canonical code).

### Observation

`isBlockedIpv4` parses each octet with JavaScript `Number()`. `Number()` accepts leading zeros,
a leading `+`, and the empty string, and reads all of them as decimal. Several of those spellings
mean something different to the POSIX `inet_aton` path that C-based connect stacks have
historically used.

Measured at the pinned head:

| Input | Canonical screen reads | Canonical verdict | `inet_aton` reads |
| --- | --- | --- | --- |
| `0177.0.0.1` | `177.0.0.1` | **ALLOW** | **`127.0.0.1` — loopback** |
| `1.2.3.04` | `1.2.3.4` | ALLOW | `1.2.3.4` |
| `1.2.3.+4` | `1.2.3.4` | ALLOW | INVALID |
| `1..2.3` | `1.0.2.3` | ALLOW | INVALID |

The first row is the one that matters: the guard screens an address it believes is public, while an
octal-aware resolution of the same string is loopback. The screen and the connect would disagree
about where the request goes — which is precisely the class of divergence ING-T01 exists to close.

### Why it has not bitten

`isBlockedIp` is only ever called on addresses produced by the injected `HostResolver`, and a
conforming resolver emits canonical dotted-quads. Node's own `dns.lookup` does. So no live path
currently supplies one of these spellings.

That makes this a latent defect rather than an open hole: the guarantee rests on a property of
every present and future resolver implementation, not on the screen itself.

### What this port did

`app/Ingestion/EgressGuard.php` requires a canonical decimal octet (`0` or `[1-9][0-9]{0,2}`) and
refuses every other spelling, so the four inputs above are BLOCKED rather than allowed. This is a
deliberate, documented divergence in the fail-closed direction, pinned by
`tests/security/egress_guard.php` and recorded in `docs/CONFORMANCE_MATRIX.md`.

The remaining **1922 of 1926** probed addresses match canonical exactly, including every SSRF
category, both mapped-IPv6 forms, and both transition families.

### Suggested upstream remedy

Validate each octet against `/^(0|[1-9][0-9]{0,2})$/` before `Number()`, mirroring the rule this
port applies. It is a strictly narrowing change with no effect on any conforming resolver output,
so it does not widen or narrow the policy the owning architecture set — it only removes the
disagreement between the screen and the connect.

---

## UF-002 — `evaluateFetchPolicy` allows a URL carrying credentials in its authority

**Where:** `packages/allfather-control-substrate/src/ingestion/egress-guard.ts`,
`evaluateFetchPolicy`
**Severity assessment:** informational.
**Status:** REPORTED — canonical behavior PRESERVED in this port.

### Observation

`new URL("https://user:pass@example.com/p").hostname` is `example.com`, so a source URL carrying
credentials passes the host allowlist and is ALLOWED. The credentials are then dropped in practice,
because `https-transport.ts` builds its request from `url.hostname` / `url.pathname` and sets no
auth option — nothing is forwarded.

The outcome is therefore safe, but it is safe by construction of the transport rather than by a
decision of the policy. A future transport that passed the URL through wholesale would forward the
credentials with no policy refusal in the way.

### What this port did

**Preserved canonical behavior.** `evaluateFetchPolicy` allows the URL, because refusing it would
be a stricter policy than the owning architecture set and this port is not authority to change it.
The no-forwarding rule is enforced where canonical enforces it — at the transport leaf, where
`CurlHttpsTransport` rebuilds the request URL from host, port, path and query, dropping the
credentials before cURL sees them (cURL WOULD send them otherwise).

Pinned by `tests/security/egress_guard.php`.

### Suggested upstream consideration

If the owning architecture wants the property to hold at policy level rather than by transport
construction, a refusal would need a reason code, and the refusal vocabulary is closed — so this is
an owner/architecture decision, not a code change.

---

## Nothing new from the 2026-09-06 repair pass

Recorded because an empty result is only evidence if someone says where they looked.

That pass compared the port against canonical field by field for the P07-A synthetic dispatch
(`p07/outreach-delivery.ts`), the M10 append and its three wrappers (`m10/ledger.ts`), the
transaction shape of every composite command in the selected graph, all 25 canonical partial UNIQUE
indexes (`db/schema.ts`), the M03 contract activation rule (`m03/registry.ts`), and the P02 status
history writers (`p02/entity-twins.ts`, `p02/entity-relationships.ts`).

**Every divergence found was the port's.** Canonical was correct in each case, and in two of them
canonical's own comments named the invariant the port had missed — "the no-effect path either
records the truthful outcome or leaves the authorization unconsumed", and "The partial UNIQUE index
enforces 'at most one ACTIVE contract per valkyrie'". No new upstream finding was opened, and no
canonical file was modified.

---

## UF-003 — P03 assurance audits fail OPEN on a malformed `asOf`

**Where:** `packages/allfather-control-substrate/src/p03/assurance.ts` @ `1c40306c22e15b2ad3049e8d32abff47450c8921`,
all three `run*Audit` functions. **Recorded:** 2026-10-02 (completion plan P08).
**Status:** REPORTED — not fixed upstream; narrowed at this port's input boundary.

### Observation

Each audit computes `asOfMs = Date.parse(input.asOf ?? now)`. A malformed `asOf` yields `NaN`, and
every subsequent `x < asOfMs` / `asOfMs - y > window` comparison is then false. The catalogue and
signal-pairing audits therefore record `CONTROL_PASS` for a stale target, and no incident opens. The
terms-recheck branch alone stays closed, because it tests `!Number.isFinite(reviewedMs)` on the
REVIEW instant, not on `asOfMs`.

### What this port did

`app/Product/P03/Assurance.php` refuses a non-canonical `as_of` (`AS_OF_NOT_CANONICAL`), a negative
or non-integer window (`FRESHNESS_WINDOW_INVALID`) and an unknown requester type before any row is
read or written. Valid inputs keep canonical semantics; the 46 parity vectors use only valid inputs.
`tests/integration/p03_assurance.php` pins the refusals.

### Suggested upstream remedy

Validate `asOf` (and the window numbers) at entry and reject rather than compute with `NaN`.

---

## UF-004 — the P03 catalogue audit compares tenant only, even for a workspace-owned catalogue

**Where:** `runCatalogueFreshnessAudit` in the same file. **Recorded:** 2026-10-02 (P08).
**Status:** REPORTED — parity kept; pinned by vector `catalogue-workspace-owned-from-sibling-workspace`.

### Observation

The sibling signal-pairing audit compares tenant AND workspace because `fit_hypotheses.workspace_id`
is NOT NULL, and its comment says the catalogue audit compares tenant alone "lawfully because ITS
target carries a nullable workspace". That reasoning holds for a tenant-level catalogue
(`workspace_id IS NULL`). It does not hold for a catalogue that HAS a workspace: a profile in a
sibling workspace of the same tenant can audit it, record the catalogue's status in its own
reason codes and open an incident in its own workspace citing the catalogue id.

### What this port did

Kept canonical behaviour (the plan requires shared defects to be repaired upstream first) and pinned
it with a parity vector, so a canonical repair regenerates the vector and the port follows. The
port's own **sweep** does not reach across: it audits only tenant-level and this-workspace catalogues.

### Suggested upstream remedy

Compare `workspace_id` when the catalogue carries one; keep tenant-only comparison for a NULL
workspace.
