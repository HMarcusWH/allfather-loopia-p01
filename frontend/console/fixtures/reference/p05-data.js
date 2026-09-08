/* Allfather BI — P05 Marketing Customization Suite seed data.
   Composes marketing assets from RELEASED intelligence (P01 battlecards, P02
   twins, P03 fits) into governed, evidence-cited DRAFTS. The drafts stop at
   internal release — they are NEVER published/sent/dispatched. Publication is
   P07/X01 (blocked). Schema G17.

   Governed flow: marketing_asset_drafts DRAFT → M04 MARKETING_ASSET_DRAFT →
   M05 basis → M07 MARKETING_ASSET_REVIEW → M08 P05_MARKETING_ASSET_ADMISSION →
   VERDICT_ISSUED/BLOCKED → M10 INTERNAL_INFORMATIONAL_RELEASE → M12 two-gate.

   Build state: cp1–cp6 complete. OWNER-GATED 2026-06-10: APPROVED for the
   internal/no-live-effect ceiling ONLY. The post-gate rollback drill (CHG-101)
   exercised the full stand-down: M10 supersession + contract revocation + tier
   removal + policy-set deactivation — then a fresh draft refuses with
   ARBITER_POLICY_NOT_ACTIVE. external_side_effects_allowed = 0 (INV). */
window.AFB = window.AFB || {};

window.AFB.p05 = {
  tier: { name: "Command", code: "TIER_COMMAND" },
  build: { status: "OWNER_GATED", cp: "No-live-effect ceiling", gated: "2026-06-10", schema: "G17", role: "MARKETING_CUSTOMIZER_VALKYRIE" },

  /* CHG-101 — the rollback drill credential. The stand-down is proven, not assumed. */
  drill: {
    id: "CHG-101", label: "Rollback drill exercised",
    steps: ["M10 supersession", "Contract + Valkyrie revocation", "Tier removal", "Policy-set deactivation"],
    proof: "A fresh draft after stand-down refuses: ARBITER_POLICY_NOT_ACTIVE. A revoked Valkyrie cannot re-activate.",
  },

  stats: [
    { id: "drafts",  label: "Assets composed",     value: "15", unit: "drafts",   tone: "cyan",   icon: "pen-tool",      sub: "from released intel" },
    { id: "review",  label: "In review",           value: "1",  unit: "M07",       tone: "violet", icon: "search",        sub: "held on uncited claim" },
    { id: "released",label: "Released internal",   value: "11", unit: "G17",       tone: "green",  icon: "check-check",   sub: "never published" },
    { id: "cited",   label: "Claims cited",        value: "94%",unit: "coverage",  tone: "gold",   icon: "link",          sub: "evidence-bound" },
    { id: "blocked", label: "Dark patterns blocked",value: "2", unit: "by M08",    tone: "red",    icon: "shield-x",      sub: "+ flagged in audit" },
  ],

  /* The M08 admission policy set — 5 rules, derived from G17 facts. */
  policy: { key: "P05_MARKETING_ASSET_ADMISSION", rules: [
    "p05_uncited_competitor_claim", "p05_forbidden_marketing_pattern",
    "p05_source_still_released", "p05_draft_only", "p05_evidence_basis_present",
  ] },
};

/* Marketing asset drafts. status: DRAFT · IN_REVIEW · VERDICT_ISSUED ·
   RELEASED_INTERNAL · BLOCKED. Every claim carries a citation state; an uncited
   competitor/comparative claim cannot be released. */
window.AFB.p05.assets = [
  { id: "MAD-0031", kind: "Competitor comparison · one-pager", source: "P01 BTL-0418 · P02 Boreal twin v7",
    status: "IN_REVIEW",
    headline: "When Boreal raised Tier-2 to 5 488 kr/mo, the headline price stopped telling the whole story.",
    claims: [
      { txt: "Boreal increased Tier-2 list price to 5 488 kr/mo (+12%)", cited: true,  ref: "P01 · DIFF-3307" },
      { txt: "Boreal is repositioning toward enterprise", cited: true, ref: "P02 twin · INFERRED_HYPOTHESIS", hedge: true },
      { txt: "Nordvik delivers measurably better uptime", cited: false, ref: "evidence threshold unmet" },
    ],
    note: "Held in M07 review. The uncited comparative claim must cite valid, fresh, in-scope evidence or be downgraded before admission.",
    audit: "MARKETING_EVIDENCE_CITATION" },

  { id: "MAD-0028", kind: "Positioning section · landing", source: "P01 BTL-0411 · Fjordr",
    status: "RELEASED_INTERNAL", released: "1d",
    headline: "Fjordr now says \u201ccompliant by default.\u201d We publish the evidence behind every claim.",
    claims: [
      { txt: "Fjordr changed its tagline to \u201cCompliant by default\u201d", cited: true, ref: "P01 · DIFF-3305" },
      { txt: "Nordvik's compliance pack automates EU Data Act reporting", cited: true, ref: "tenant positioning · TENANT_DECLARED" },
    ],
    note: "Admitted and released internally. Surfaced to CMO/Sales at Command tier. Not published anywhere." },

  { id: "MAD-0024", kind: "Segment landing copy", source: "P02 Acme twin · P03 FIT-0512",
    status: "RELEASED_INTERNAL", released: "3d",
    headline: "Funded, scaling, and ready for fleet-grade telemetry.",
    claims: [
      { txt: "Built for logistics fleets over 50 units", cited: true, ref: "catalogue · ICP" },
    ],
    note: "Composed from a released fit hypothesis — frames fit, never asserts buyer intent (P03 guardrail respected)." },

  { id: "MAD-0037", kind: "Outreach email draft", source: "P03 priority outreach",
    status: "BLOCKED",
    headline: "Only 2 onboarding slots left this quarter \u2014 act now or fall behind your competitors.",
    claims: [
      { txt: "False-scarcity / manipulative-urgency framing", cited: false, ref: "forbidden pattern" },
    ],
    note: "Blocked at M08 admission. P05_FORBIDDEN_MARKETING_PATTERN_PERSISTED \u2014 manipulative pressure / false scarcity. Incident opened.",
    block: { reason: "P05_FORBIDDEN_MARKETING_PATTERN", incident: "INC-0102" } },
];

/* M11 audit profiles — the invariant audits. Each violation → finding →
   incident (INV-014). audits record only; they grant no release authority. */
window.AFB.p05.audits = [
  { id: "MARKETING_EVIDENCE_CITATION", label: "Evidence-cited claim", pass: 14, total: 15, tone: "amber",
    desc: "Every competitor/comparative claim must cite valid, fresh, in-scope marketing evidence.",
    findings: [
      { sev: "warn", txt: "MAD-0031 \u2014 uncited comparative claim", code: "P05_CITATION_REQUIRED_CLAIM_UNCITED", inc: null },
    ] },
  { id: "MARKETING_DRAFT_ONLY_INVARIANT", label: "Draft-only / internal-only", pass: 15, total: 15, tone: "green",
    desc: "No PUBLISHED / SENT / DISPATCHED / CRM_WRITEBACK status; every released row joins a RELEASED_INTERNAL draft.",
    findings: [] },
  { id: "MARKETING_CONTENT_COMPLIANCE", label: "Content compliance", pass: 13, total: 15, tone: "red",
    desc: "Deterministic scan for dark patterns, deception, manipulative pressure and sensitive-trait targeting.",
    findings: [
      { sev: "block", txt: "MAD-0037 \u2014 manipulative urgency / false scarcity", code: "P05_FORBIDDEN_MARKETING_PATTERN_PERSISTED", inc: "INC-0102" },
      { sev: "block", txt: "MAD-0039 \u2014 sensitive-trait audience targeting", code: "P05_FORBIDDEN_MARKETING_PATTERN_PERSISTED", inc: "INC-0103" },
    ] },
];

window.AFB.p05.statusTone = {
  DRAFT: "neutral", IN_REVIEW: "violet", VERDICT_ISSUED: "cyan", RELEASED_INTERNAL: "green", BLOCKED: "red",
};
