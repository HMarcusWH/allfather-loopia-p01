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

Live source capture is the only outbound path, and it is guarded (`app/Ingestion/`, `app/Egress/`).

The guard resolves the hostname, validates the resolved address against the policy, and then pins
the connection to **that address**. It does not resolve, check, and then hand the hostname back to
cURL to resolve again — that pattern is a DNS-rebinding hole, and avoiding it is the entire reason
the resolution is pinned rather than repeated.

Private, loopback, link-local and multicast ranges are refused. A redirect is re-validated rather
than followed. An unknown policy state denies.

## 4. Tenant and workspace isolation

Every product read and write is scoped by tenant AND workspace, never by id alone. The pattern is
consistent and deliberate: a lookup that includes the scope in its WHERE clause cannot return a
foreign row for a later check to trust.

Cross-tenant access is S4 with an incident, not a quiet miss — because a caller reaching another
tenant's object is either a bug worth finding or an attack worth recording.

## 5. Secrets

Provider credentials (`database/migrations/130_provider_credentials.sql`) are stored as
AES-256-GCM ciphertext with the nonce, auth tag and key version alongside. The master key lives in
server-private configuration and never in the database. Credentials are never logged and never
echoed back after save; rotation and deletion are supported, and every credential is tenant-scoped.

## 6. Privacy

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

## 7. Append-only surfaces

The following refuse UPDATE, DELETE or both at the storage layer: `decision_records`,
`decision_supersession_records`, `evidence_objects`, `raw_artifacts`, `source_diffs`,
`consent_registry`, `recipient_pseudonym_mappings` (delete), `marketing_claim_citations`,
`released_marketing_assets`, `delivery_outcome_records`, `customer_output_projections`, and the
status-transition tables.

The consequence is stated in `docs/DEPLOYMENT_RUNBOOK.md` §6: rollback is never a delete.

## 8. Known limits, stated plainly

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
