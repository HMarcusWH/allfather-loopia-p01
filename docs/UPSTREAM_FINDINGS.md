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
`runCatalogueFreshnessAudit` and `runSignalPairingFreshnessAudit` (`runBuyerIntentInvariantAudit`
accepts an `asOf` but never reads it). **Recorded:** 2026-10-02 (completion plan P08).
**Status:** REPORTED — not fixed upstream; narrowed at this port's input boundary.

### Observation

Both time-based audits compute `asOfMs = Date.parse(input.asOf ?? now)`. A malformed `asOf` yields
`NaN`, and every comparison against it is then false. In the catalogue audit the review-expiry
check (`expiryMs < asOfMs`), the capture-age check (`asOfMs - capturedMs > window`) and the
terms-recheck age check (`asOfMs - reviewedMs > recheck`) all stop firing; in the signal-pairing
audit the signal-age check stops firing. A target that is stale **only by time** therefore records
`CONTROL_PASS` and no incident opens. Reasons that do not depend on the instant still fire: a
non-ACTIVE catalogue, a non-approved or expired review status, a missing capture, a missing or
unreadable terms review (that branch tests `!Number.isFinite(reviewedMs)` on the REVIEW instant), and
a non-RELEASED signal.

### What this port did

`app/Product/P03/Assurance.php` accepts `as_of` only in the canonical stored form
`YYYY-MM-DDTHH:MM:SS.sssZ` (`AS_OF_NOT_CANONICAL`) — which also refuses ES forms canonical would
read, such as a date-only `2026-07-01` — and refuses a negative or non-integer window
(`FRESHNESS_WINDOW_INVALID`) and an unknown requester type, before any row is read or written, for
all three audits. Valid inputs keep canonical semantics; the 62 parity vectors use only valid
inputs. The operator surface (`ops/assurance/p03.php`, `operatorSweep`) goes further and accepts no
`as_of` or catalogue-window override at all (UF-005). `tests/integration/p03_assurance.php` pins the
refusals.

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

---

## UF-005 — P03 audit records do not say which instant or window they judged, and instants are read engine- and host-dependently

**Where:** the same file; `openAuditRun` and every `Date.parse` call. **Recorded:** 2026-10-03 (P08
review). **Status:** REPORTED — parity kept where it is deterministic; the rest narrowed here.

### Observation

1. **The judged instant and window are not persisted.** `openAuditRun` stores `requested_at = now`.
   The `asOf` the audit evaluated and any `freshnessWindowMs` that overrode the card's
   `error_after_ms` appear nowhere on the run, check or finding. An audit evaluated as of a past or
   future instant, or under a widened window, is recorded as an ordinary PASS at `requested_at`, and
   nothing on the record lets a reviewer tell.
2. **Offset-less date-times are host-local.** `Date.parse("2026-07-01T00:00:00")` is read in the
   host's time zone, so the same stored row can be stale on one host and fresh on another.
3. **Legacy non-ISO strings parse.** V8's fallback parser accepts forms outside the ES
   date-time-string format (`"June 15, 2026"`, `"2026/06/15"`, `"2026-06-15 10:00:00"` — all read
   by canonical in the battery below), with engine-defined results. Canonical's own writers store
   `toISOString()` values, so such strings reach an audit only through imported or hand-written rows.

### What this port did

- (1) The operator surface accepts neither `as_of` nor a catalogue-window override
  (`SWEEP_AS_OF_NOT_ACCEPTED`, `SWEEP_CATALOGUE_WINDOW_NOT_ACCEPTED`; the CLI exits 2), so every
  operator-run audit is judged at the clock's now under the card's own window. The service methods
  keep both inputs for parity and remain unrecorded, as in canonical; the sweep's returned summary
  states the `as_of` and windows it used, but that summary is not persisted either.
- (2)/(3) `Assurance::ms()` reads stored instants exactly as `Date.parse` reads the ES format —
  date-only forms as UTC, fraction truncated, `T24:00` as the next midnight, day rollover, `:60` /
  month 13 / `±24:00` unreadable — pinned by a 47-instant battery generated from canonical with
  `TZ=UTC`. An offset-less date-time is read as UTC (canonical's answer on a UTC host). A string
  outside the ES format is unreadable (`null`) and takes the branch canonical takes for `NaN`, where
  V8 may instead have read a date: that is a recorded divergence, not parity, and the battery
  asserts both halves (ES forms equal canonical; non-ES forms are `null` here).

### Suggested upstream remedy

Persist the evaluated `asOf` and the effective window on the audit run (or refuse overrides outside
an explicit back-test path), and parse stored instants with an explicit, zone-qualified format
instead of `Date.parse`.
