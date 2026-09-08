/* Allfather BI — P07 CRM Execution console seed data.
   The "from AI output to admissible action" climax. P07-A is the no-external-
   effect SAFETY FOUNDATION — the full governed dispatch pipeline (consent +
   lawful basis → PII firewall → admission → mandatory M09 approval → frozen
   artifact → single-use short-TTL authorization → SYNTHETIC dispatch → truthful
   delivery outcome). Every dispatch is a NO_OP_TEST_EVENT; nothing is sent.
   Schema G16.

   Status: P07-A OWNER-GATED `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS`
   (2026-06-07). P07-B (the live external send) is a SEPARATE, HEAVIEST gate —
   HARD-BLOCKED behind (a) a recorded legal/privacy review and (b) the owner's
   explicit go. external_side_effects_allowed = 0 · adapter NO_OP_TEST_EVENT. */
window.AFB = window.AFB || {};

window.AFB.p07 = {
  tier: { name: "Governed Ops", code: "TIER_GOVERNED_OPS" },
  gate: { a: "INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS", aNote: "historical gate record · granted 2026-06-07 — current environment ceiling is INTERNAL_GOVERNED_SUBSTRATE_WITH_OPERATOR_AUTHORIZED_READ_ONLY_CONTROLLED_PILOT_OBJECT", schema: "G16", valkyrie: "CRM_EXECUTION_VALKYRIE", adapter: "NO_OP_TEST_EVENT" },

  stats: [
    { id: "flight",   label: "Outreach in flight",  value: "3",  unit: "drafts",  tone: "cyan",   icon: "send",          sub: "on the governed rail" },
    { id: "approval", label: "Awaiting M09 approval",value: "2",  unit: "held",    tone: "violet", icon: "user-check",    sub: "separate approver seat" },
    { id: "frozen",   label: "Frozen artifacts",    value: "4",  unit: "sealed",   tone: "gold",   icon: "lock",          sub: "digest-bound payload" },
    { id: "synthetic",label: "Synthetic deliveries",value: "11", unit: "NO_OP",    tone: "green",  icon: "check-check",   sub: "no real send" },
    { id: "external", label: "External sends",      value: "0",  unit: "pinned",   tone: "red",    icon: "shield-x",      sub: "external_side_effects = 0" },
  ],
};

/* The governed dispatch pipeline for a worked outreach (OEX-0231).
   state: done · active · pending · blocked. guard names the invariant each
   stage enforces. */
window.AFB.p07.pipeline = {
  id: "OEX-0231", subject: "Compliance positioning follow-up", recipient: "rcpt_7f3a",
  channel: "EMAIL · NO_OP_TEST_EVENT", source: "P01 BTL-0411 (released)",
  stages: [
    { m: "M04", name: "Outreach proposal",        state: "done",    guard: "Cites the M10 source + a derived CONSENT_STATE_REFERENCE — never raw content", detail: "CRM_OUTREACH_EXECUTION" },
    { m: "M07", name: "Compliance review",         state: "done",    guard: "Producer self-review is denied", detail: "OUTREACH_COMPLIANCE_REVIEW" },
    { m: "M08", name: "Admission",                 state: "done",    guard: "5-rule policy · consent BLOCK is non-overridable", detail: "REQUIRE_HUMAN_APPROVAL" },
    { m: "M09", name: "Human approval",            state: "active",  guard: "Mandatory · separate approver seat (separation of duties)", detail: "ROLE_CRM_APPROVER" },
    { m: "M09", name: "Frozen outreach artifact",  state: "pending", guard: "Exact payload · digest cross-checked against the approved digest", detail: "binds recipient · channel · payload-kind · source" },
    { m: "M06", name: "Execution authorization",   state: "pending", guard: "Single-use · short-TTL · no auto-retry (no retry column)", detail: "consumed after one dispatch" },
    { m: "M01", name: "Synthetic dispatch",        state: "pending", guard: "NO_OP adapter · HARMLESS_DEMO_ACTION · no M06→M01 external seam", detail: "→ truthful DELIVERY_OUTCOME" },
  ],
};

/* Other outreach on the rail — incl. a consent-withdrawn BLOCK. */
window.AFB.p07.outreach = [
  { id: "OEX-0231", subject: "Compliance positioning follow-up", recipient: "rcpt_7f3a", status: "AWAITING_APPROVAL", tone: "violet", note: "At M09 — awaiting the separate approver seat." },
  { id: "OEX-0228", subject: "Series-B congratulations + fit note", recipient: "rcpt_5e1d", status: "BLOCKED", tone: "red", note: "M08 BLOCK · p07_consent_lawful_basis — recipient consent WITHDRAWN. Incident INC-0098.", incident: "INC-0098" },
  { id: "OEX-0225", subject: "Telemetry suite one-pager", recipient: "rcpt_7f3a", status: "DELIVERED_SYNTHETIC", tone: "green", note: "Synthetic DELIVERED · truthful M10 outcome · authorization consumed." },
];

/* Consent / lawful-basis registry — append-only (a withdrawal is a NEW row).
   Recipients are referenced ONLY by pseudonym (the PII firewall). */
window.AFB.p07.consent = [
  { ref: "rcpt_7f3a", who: "Procurement lead · Acme",   state: "OPTED_IN",  basis: "LEGITIMATE_INTEREST", channel: "email" },
  { ref: "rcpt_2b9c", who: "Ops director · Stenmark",   state: "NO_BASIS",  basis: "—",                   channel: "email" },
  { ref: "rcpt_5e1d", who: "Marketing lead · Kvant",    state: "WITHDRAWN", basis: "revoked",             channel: "email" },
  { ref: "rcpt_9d40", who: "CTO · Acme",                state: "OPTED_OUT", basis: "—",                   channel: "sms" },
];
window.AFB.p07.consentTone = { OPTED_IN: "green", NO_BASIS: "neutral", WITHDRAWN: "red", OPTED_OUT: "amber" };

/* PII firewall posture. */
window.AFB.p07.pii = {
  status: "HOLDING", scanned: "recursive · keys + values",
  note: "Pseudonymized recipient refs only. GDPR erasure = pseudonymization + retention downgrade + an M11 audit record — never deletion (append-only law).",
};

/* M08 admission policy — P07_OUTREACH_EXECUTION_ADMISSION. */
window.AFB.p07.policy = {
  key: "P07_OUTREACH_EXECUTION_ADMISSION",
  rules: [
    { k: "p07_consent_lawful_basis", effect: "BLOCK", over: false },
    { k: "p07_pii_not_pseudonymized", effect: "BLOCK", over: false },
    { k: "p07_content_policy", effect: "HOLD", over: true },
    { k: "p07_channel_cooldown", effect: "HOLD", over: true },
    { k: "p07_source_not_released", effect: "HOLD", over: true },
  ],
};

/* Capability Contract forbidden clauses (the strictest contract). */
window.AFB.p07.forbidden = [
  "SEND_WITHOUT_CONSENT_OR_LAWFUL_BASIS", "SEND_WITHOUT_HUMAN_APPROVAL",
  "AUTO_RETRY_ON_FAILURE", "MUTATE_FROZEN_PAYLOAD", "BULK_UNTARGETED_SEND",
];

/* M11 audit profiles (P07-A). */
window.AFB.p07.audits = [
  { id: "CONSENT_STATE_INTEGRITY", pass: 8,  total: 8,  tone: "green", note: "Append-only; latest-row-wins recency." },
  { id: "PII_FIREWALL",           pass: 14, total: 14, tone: "green", note: "Pseudonymized refs only." },
  { id: "DELIVERY_RECONCILIATION",pass: 11, total: 11, tone: "green", note: "Every outcome truthful; no orphan consume." },
  { id: "CONTENT_COMPLIANCE",     pass: 10, total: 11, tone: "amber", note: "1 HOLD — content policy review pending." },
];

/* The external boundary — the hard stop. P07-B preconditions, both UNMET. */
window.AFB.p07.boundary = {
  pin: "external_side_effects_allowed = 0",
  adapter: "NO_OP_TEST_EVENT",
  channelPermit: "outreach_channel_bindings.external_effect_permitted = 0",
  preconditions: [
    { id: "a", label: "Recorded legal / privacy review", detail: "DPA · lawful-basis · GDPR-erasure for the email channel (V1-GAP-025/081)", met: false },
    { id: "b", label: "Owner's explicit final go", detail: "The heaviest gate — the only one that authorizes a real external effect", met: false },
  ],
  guard: "The standing EXTERNAL_EFFECT_GUARD M11 audit proves no real send, no pin flip, and no live adapter has occurred.",
};
