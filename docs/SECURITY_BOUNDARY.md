# SECURITY BOUNDARY

Where this projection's authority stops, how each stop is enforced, and which guarantees are
structural rather than procedural.

The distinction matters throughout. A guarantee held by application code can be edited away by
anyone who can edit the code. A guarantee held by a CHECK constraint or a unique index survives
that, and survives a direct database connection too. Where a boundary is structural, this document
says so, because that is the difference between "we don't do that" and "that cannot happen here".

---

## 1. The action-class ceiling

Five classes, in one canonical ordering (`app/Core/ActionClass.php`):

    OBSERVE < ANALYZE < RELEASE_INTELLIGENCE < PREPARE_EXTERNAL_ACTION < EXECUTE_CONSEQUENTIAL_ACTION

Only the first three are reachable. The two external classes are refused at **four independent
planes**, none of which depends on the others:

| Plane | How |
| --- | --- |
| M02 | the matching permissions are in the EXCLUDED set — requesting one is `EXCLUDED_PERMISSION_REQUESTED`, S5, incident |
| M03 | `issueContract` refuses to issue a contract granting either class (`CONTRACT_ACTION_CLASS_ABOVE_CEILING`) |
| M04 | `createDraft` refuses a proposal requesting either class (`PROPOSAL_ACTION_CLASS_ABOVE_CEILING`) |
| M01 | Class E dispatch is refused outright at `CLASS_E_NOT_AVAILABLE_IN_PROJECTION` |

Independence is the property that matters: an error in any one of these leaves the other three
standing.

## 2. External effect

**There is no channel adapter in this projection**, and that is a stronger statement than a disabled
one. The following are structural:

| Guarantee | Enforced by |
| --- | --- |
| `workspaces.external_side_effects_allowed` cannot be 1 | CHECK constraint |
| a capability contract cannot carry human-approval or execution authority | CHECK constraint |
| an outreach channel binding cannot permit external effect | CHECK constraint |
| a channel binding cannot name a real adapter | CHECK constraint pinning `adapter_type` to `NO_OP_TEST_EVENT` |
| no channel binding exists at all | **no function in this projection creates one** — the P07-B producer is not ported |
| an outreach proposal cannot reach a sent state | the status vocabulary has no such member |
| a marketing asset cannot be published | the status vocabulary has no `PUBLISHED` member |
| one authorization cannot produce two delivery outcomes | unique index |

`tests/security/p07a_artifact_chain.php` asserts the first four by attempting the mutation and
showing the storage refuses it — which is why four of the external-effect guard audit's five arms
cannot fire in this build. The fifth (an outcome carrying a record type no synthetic dispatch could
produce) is reachable, and is exercised.

## 3. Egress

Live source capture is the only outbound path, and it is guarded (`app/Ingestion/EgressGuard.php`, with the transports beside it).

The guard resolves the hostname, validates the resolved address against the policy, and then pins
the connection to **that address**. It does not resolve, check, and then hand the hostname back to
cURL to resolve again — that pattern is a DNS-rebinding hole, and avoiding it is the entire reason
the resolution is pinned rather than repeated.

Private, loopback, link-local and multicast ranges are refused. A redirect is re-validated rather
than followed. An unknown policy state denies.

## 4. The reachable surfaces

Three things can be reached from outside this deployment, and each has exactly one authority.

| Surface | Entry | Authority |
| --- | --- | --- |
| the console | `public_html/index.php` | a session cookie, plus the M02 guard re-run per surface |
| the scheduler tick | `public_html/scheduler.php` | `X-Scheduler-Token`, compared constant-time against a stored digest |
| the liveness probe | `GET /healthz` | none — it touches no governed state and names no tenant |

**Being signed in is identity, not authority.** No authorization happens in the kernel. Each
surface re-runs the M02 guard against the permission it actually needs, so a revoked role stops
working at the next request rather than at the next sign-in — and a new surface cannot inherit
authority by being added.

**CSRF is decided by METHOD, never by a route list.** A route list is something a future change can
forget to update. Every non-safe method is checked, including the sign-in POST, and the token is
read from the form only — a token accepted from the query string would end up in access logs, in
Referer headers, and in any link a caller could be talked into following.

Which digest is authoritative depends on whether a session exists, and the ORDER is the security
property:

| State | Checked against | Why |
| --- | --- | --- |
| signed in | the session's own `csrf_token_hash` | the authority is a server-side row; a cookie the caller chose proves nothing |
| signing in | the matching `__Host-` cookie | there is no session row yet, so the double-submit pair is what is available |

The session case is tried FIRST, so the weaker sign-in posture can never become the posture for
everything else. The `__Host-` prefix is what keeps that weaker posture honest: a browser accepts
that name only for a Secure, `Path=/`, `Domain`-less cookie, so a sibling subdomain cannot plant
the value the sign-in POST is checked against. The token ROTATES at the boundary — a fresh one per
login render, per failed attempt, and the session's own on success — so a value an unauthenticated
caller could have influenced never becomes the authenticated one.

The server never stores a usable token. `platform_sessions` holds the DIGEST of both the session
token and the CSRF token; the tokens themselves live only in the caller's cookies. A database read
— a backup, a dump, an injection — yields nothing presentable.

**The scheduler is not a console surface**, and is deliberately not mounted in the kernel: doing so
would mean carving an exemption into the absolute CSRF rule. Its own entry point reads no cookie
and sets none, which is what makes CSRF inapplicable there rather than merely waived — there is no
ambient credential for a browser to be tricked into attaching. It is POST-only, accepts no work
definition of any kind, and an unconfigured deployment has a scheduler nobody can call rather than
an open one.

**Workspace selection is a scope change, not a grant.** The chooser lists only workspaces the
operator holds an ACTIVE membership in — a chooser showing workspaces the caller cannot enter would
be an enumeration surface wearing a convenience label — and the POST does not consult that list:
the membership is re-decided from scratch, because a workspace id in a form field is untrusted
input whoever rendered the page before it. A cross-tenant id answers NOT_FOUND rather than
FORBIDDEN, so the form cannot be used to probe another tenant.

## 5. Deployment and configuration

**The served directory is the boundary.** Loopia serves a domain from `<domän>/public_html/` and
states it does not support pointing at any other application folder, so which directory is pointed
at *is* the security decision. Files above `public_html/` are reachable over FTP/SSH and not from
the web, so every private directory here is a **sibling** of the served one — `<domain>/` is never
itself a document root, which is stronger than excluding files from a tree they sit inside.

The repository layout IS the host layout: the served directory is named `public_html/` so a deploy
is a copy into `<domain>/` with no rename, no shim and no symlink.
`tests/foundation/deployment_boundary.php` keeps it that way — no third PHP file, no configuration
copy, no application directory inside the served tree, and each private directory asserted to exist
as a sibling rather than merely to be absent from within. The `.htaccess` files are defence in depth and are labelled as
such: every directive is `<IfModule>`-guarded, so on a host missing the module each is a silent
no-op.

**Configuration fails closed**, in three directions that used to fail open:

| Rule | Instead of |
| --- | --- |
| an unrecognised `APP_ENV` — unset, empty, misspelled — resolves to **production** | defaulting to development, where one typo turns every check off |
| a staging or production environment may only be configured from `.env` | silently loading the test file beside it and running on test credentials |
| no configuration file at all refuses to start | an empty configuration in which every default reads as a choice somebody made |

Staging and production additionally require `DB_NAME`, `DB_USER` and an `APP_MASTER_KEY` that
DECODES to 32 bytes, all at boot — so a missing key is met at startup rather than at the first
credential the deployment tries to seal. A refusal names the missing keys, which are public, and
never a value.

Errors are never displayed in a strict environment: a notice rendered into a response carries
absolute paths and a fatal carries a stack trace naming every layer it passed through. They are
still logged; the caller is not the audience.

## 6. Tenant and workspace isolation

Every product read and write is scoped by tenant AND workspace, never by id alone. The pattern is
consistent and deliberate: a lookup that includes the scope in its WHERE clause cannot return a
foreign row for a later check to trust.

Cross-tenant access is S4 with an incident, not a quiet miss — because a caller reaching another
tenant's object is either a bug worth finding or an attack worth recording.

## 7. Secrets

Provider credentials (`database/migrations/130_provider_credentials.sql`) are stored as
AES-256-GCM ciphertext with the nonce, auth tag and key version alongside. The master key lives in
server-private configuration and never in the database. Credentials are never logged and never
echoed back after save; rotation and deletion are supported, and every credential is tenant-scoped.

## 8. Privacy

Three separate planes, three separate mechanisms:

- **P02 entity twins** — four field screens (person overreach, employee scoring, buyer-intent
  overclaim, individual identifiers). One prohibited field refuses the whole version, so a
  partially-accepted version cannot silently drop the offending part.
- **P04 stakeholder roles** — `subject_kind` CHECK-pinned to `ROLE_CLASS`, with no column an
  individual could occupy. Observations must come from a public, capturable source.
- **P07-A recipients** — a one-entrance firewall. The raw identifier computes a salted pseudonym and
  is discarded; it is never persisted. GDPR erasure downgrades retention and clears the sealed
  locator, and the row survives because the pseudonym in past audit records must still resolve.

The individual-identifier detector is shared by all three (`P02\PrivacyPatterns`), deliberately, so
a gate and the audit that checks it cannot disagree about what PII is.

## 9. Append-only surfaces

The following refuse UPDATE, DELETE or both at the storage layer: `decision_records`,
`decision_supersession_records`, `evidence_objects`, `raw_artifacts`, `source_diffs`,
`consent_registry`, `recipient_pseudonym_mappings` (delete), `marketing_claim_citations`,
`released_marketing_assets`, `delivery_outcome_records`, `customer_output_projections`, and the
status-transition tables.

The consequence is stated in `docs/DEPLOYMENT_RUNBOOK.md` §6: rollback is never a delete.

## 10. Known limits, stated plainly

- The PII and content detectors are **deterministic markers, not classifiers**. They catch
  structured identifiers and named phrases. They do not attempt to detect a person described in
  free prose, and the code says so rather than implying otherwise. The primary guarantees are
  structural; the detectors are the layer over them.
- The P07-A pseudonym salt is a **documented demo constant**. With no real recipients in this
  projection a secret salt would be an unrotated constant with a warning label. The function is
  salt-injectable precisely so a deployment with real recipients supplies a sealed per-tenant
  secret — and acquiring real recipients is not something this projection can do.
- Adapter conformance is a **characterized partial port**: it proves the adapter identity has not
  drifted, not that it conforms to a harness descriptor contract. See `PORT_SCOPE_MATRIX.md` §4.1.
- The `.htaccess` files have **never been interpreted by a web server**. Apache does not run in the
  development container or in CI, so what the suite proves is that the directives are written — not
  that a host honoured them. `DEPLOYMENT_RUNBOOK.md` §3.1 carries the curls that establish it on a
  real host, and they have not been run either, because no Loopia host has served this code.
- The pre-sign-in CSRF posture is **double-submit**, which is genuinely weaker than the session
  posture that replaces it a request later. It is what is available before a session row exists;
  the `__Host-` prefix and `SameSite=Strict` are what bound it, and it is unreachable the moment a
  session exists.
