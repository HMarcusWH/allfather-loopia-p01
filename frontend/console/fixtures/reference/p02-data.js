/* Allfather BI — P02 Entity Twin Network seed data.
   The governed graph: the tenant's own-company twin, bounded external-entity
   twins, and the typed relationship edges between them — each evidence-backed,
   uncertainty-aware, source-lineaged, access-controlled. Internal STATE only;
   NO external action path anywhere (INV-P02-015). Schema G18.

   Build state: cp1–cp11 complete. OWNER-GATED 2026-06-10: APPROVED WITH
   EXPLICIT LIMITATIONS — entity + relationship twin verticals only; offering
   twins EXCLUDED (no surfacing function — V1-GAP-097). F1 storage pin landed
   (CHG-100): released twin + relationship snapshots decision_record_id NOT NULL. */
window.AFB = window.AFB || {};

window.AFB.p02 = {
  tier: { name: "Command", code: "TIER_COMMAND" },
  gate: { status: "OWNER_GATED_WITH_LIMITATIONS", ceiling: "APPROVED WITH EXPLICIT LIMITATIONS", gated: "2026-06-10 · cp11", schema: "G18", curator: "ENTITY_TWIN_CURATOR_VALKYRIE" },

  stats: [
    { id: "twins",   label: "Entity twins",        value: "10", unit: "modelled", tone: "cyan",   icon: "boxes",        sub: "1 own · 9 external" },
    { id: "edges",   label: "Relationship edges",  value: "9",  unit: "typed",    tone: "violet", icon: "share-2",      sub: "evidence-backed" },
    { id: "snap",    label: "Released snapshots",  value: "23", unit: "G18",      tone: "gold",   icon: "layers",       sub: "DR-bound · NOT NULL pin" },
    { id: "unknown", label: "Open unknowns",       value: "12", unit: "visible",  tone: "amber",  icon: "circle-help",  sub: "+2 conflicting" },
    { id: "audits",  label: "M11 audit profiles",  value: "12", unit: "passing",  tone: "green",  icon: "clipboard-check", sub: "0 incidents" },
  ],
};

/* ── The network graph. viewBox 0 0 1000 640. Own company centered.
   entity_kind drives tone. is_first_party true only for OWN_COMPANY. ───── */
window.AFB.p02.nodes = [
  { id: "nordvik",  name: "Nordvik Industrier AB", kind: "OWN_COMPANY", firstParty: true,  x: 500, y: 320, tone: "gold",    short: "Own company · logistics operator" },
  { id: "acme",     name: "Acme Logistics",        kind: "CUSTOMER",    firstParty: false, x: 792, y: 178, tone: "green",   short: "Customer · active account" },
  { id: "stenmark", name: "Stenmark Logistik",     kind: "PROSPECT",    firstParty: false, x: 832, y: 392, tone: "cyan",    short: "Prospect · in qualification" },
  { id: "boreal",   name: "Boreal Systems AB",     kind: "COMPETITOR",  firstParty: false, x: 222, y: 150, tone: "amber",   short: "Direct competitor" },
  { id: "fjordr",   name: "Fjordr",                kind: "COMPETITOR",  firstParty: false, x: 168, y: 364, tone: "amber",   short: "Direct competitor" },
  { id: "vastkust", name: "Västkust Komponenter",  kind: "SUPPLIER",    firstParty: false, x: 486, y: 86,  tone: "violet",  short: "Component supplier" },
  { id: "sigma",    name: "Sigma Integrations",    kind: "PARTNER",     firstParty: false, x: 540, y: 558, tone: "cyan",    short: "Integration partner" },
  { id: "imy",      name: "IMY (regulator)",       kind: "REGULATOR",   firstParty: false, x: 248, y: 548, tone: "violet",  short: "Swedish data-protection authority" },
  { id: "norrsken", name: "Norrsken Ventures",     kind: "INVESTOR",    firstParty: false, x: 772, y: 556, tone: "green",   short: "Investor" },
  { id: "kvant",    name: "Kvant Industri",        kind: "OTHER_EXTERNAL_ORG", firstParty: false, x: 116, y: 248, tone: "neutral", short: "Adjacent operator" },
];

/* Typed relationship edges. type ∈ 17 supported kinds. conf HIGH/MODERATE/LOW.
   Each edge is evidenced + versioned; an edge with no evidence cannot release. */
window.AFB.p02.edges = [
  { from: "nordvik",  to: "acme",     type: "SELLS_TO",                    conf: "HIGH",     fresh: "2h",  ev: 4 },
  { from: "nordvik",  to: "boreal",   type: "COMPETES_WITH",               conf: "HIGH",     fresh: "2h",  ev: 6 },
  { from: "nordvik",  to: "fjordr",   type: "COMPETES_WITH",               conf: "MODERATE", fresh: "1d",  ev: 3 },
  { from: "vastkust", to: "nordvik",  type: "SUPPLIES",                    conf: "HIGH",     fresh: "3d",  ev: 5 },
  { from: "nordvik",  to: "sigma",    type: "INTEGRATES_WITH",             conf: "HIGH",     fresh: "5d",  ev: 2 },
  { from: "imy",      to: "nordvik",  type: "REGULATES",                   conf: "HIGH",     fresh: "1d",  ev: 2 },
  { from: "norrsken", to: "nordvik",  type: "INVESTS_IN",                  conf: "HIGH",     fresh: "30d", ev: 3 },
  { from: "vastkust", to: "kvant",    type: "SUPPLIES",                    conf: "MODERATE", fresh: "6d",  ev: 2 },
  { from: "stenmark", to: "acme",     type: "SHARES_CUSTOMER_SEGMENT_WITH",conf: "MODERATE", fresh: "4d",  ev: 2 },
];

/* Per-twin state. value_status keeps unknowns visible. The Boreal external twin
   demonstrates the full discipline: facts, derived summaries, an inferred
   hypothesis, an UNKNOWN, a CONFLICTING field, and a PROHIBITED (refused) field. */
window.AFB.p02.twin = {
  nordvik: {
    kind: "OWN_COMPANY", external: false,
    basis: "TENANT_OWNED · first-party", version: "v22", relied: "CURRENT",
    fields: [
      { k: "Legal name",        v: "Nordvik Industrier AB",        status: "KNOWN_FACT",      src: "Bolagsverket registry" },
      { k: "Org units",         v: "4 (Ops, Sales, CS, Finance)",  status: "TENANT_PROVIDED", src: "tenant-owned" },
      { k: "Active customers",  v: "38 accounts",                  status: "TENANT_PROVIDED", src: "imported lawfully" },
      { k: "Win rate vs Boreal",v: "47% (last 12 mo)",             status: "DERIVED_SUMMARY", src: "tenant CRM summary" },
      { k: "Headcount",         v: "96",                           status: "TENANT_PROVIDED", src: "tenant-owned" },
    ],
    uncertainty: { conflict: false, summary: "First-party twin — deeper internal state is permitted under tenant authority and role-gated access. No unresolved conflict on this version." },
    anchors: [
      { kind: "SOURCE",    note: "Tenant-owned system of record" },
      { kind: "RETENTION", note: "Tenant retention policy · required before release" },
      { kind: "PRIVACY",   note: "Employee fields minimized to work-role only" },
    ],
  },
  acme: {
    kind: "CUSTOMER", external: true,
    basis: "PUBLIC_SOURCE + TENANT_PROVIDED", version: "v11", relied: "CURRENT",
    fields: [
      { k: "Legal name",        v: "Acme Logistics",               status: "KNOWN_FACT",          src: "registry" },
      { k: "Series B",          v: "Closed",                       status: "PUBLIC_SOURCE",       src: "P01 · PRP-0419" },
      { k: "Fleet size",        v: "~80 units (est.)",             status: "DERIVED_SUMMARY",     src: "2 public signals" },
      { k: "Procurement init.", v: "Q3 (likely)",                  status: "INFERRED_HYPOTHESIS", src: "low confidence" },
      { k: "Budget envelope",   v: "Not disclosed",                status: "UNKNOWN",             src: "—" },
    ],
    uncertainty: { conflict: false, summary: "External twin — bounded to public, licensed and tenant-provided evidence only. The procurement trigger is an inferred hypothesis, not asserted as buyer intent (INV-P02-009)." },
    anchors: [
      { kind: "SOURCE",    note: "P01 released-source lineage + tenant account record" },
      { kind: "RETENTION", note: "180-day rolling · required before release" },
    ],
  },
  boreal: {
    kind: "COMPETITOR", external: true,
    basis: "PUBLIC_SOURCE + LICENSED_SOURCE", version: "v7", relied: "CURRENT",
    fields: [
      { k: "Legal name",        v: "Boreal Systems AB",            status: "KNOWN_FACT",          src: "Bolagsverket registry" },
      { k: "Headquarters",      v: "Göteborg, SE",                 status: "PUBLIC_SOURCE",       src: "boreal.se/about" },
      { k: "Headcount",         v: "~120 (est.)",                  status: "DERIVED_SUMMARY",     src: "3 public signals" },
      { k: "Tier-2 list price", v: "5 488 kr / mo",                status: "PUBLIC_SOURCE",       src: "P01 · DIFF-3307" },
      { k: "Strategic intent",  v: "Enterprise repositioning",     status: "INFERRED_HYPOTHESIS", src: "low confidence" },
      { k: "Q3 launch timing",  v: "Sept vs. Nov",                 status: "CONFLICTING",         src: "2 press sources disagree" },
      { k: "Funding stage",     v: "Not disclosed",                status: "UNKNOWN",             src: "—" },
      { k: "Exec disposition",  v: "Refused at admission",         status: "PROHIBITED",          src: "psych-profiling — blocked" },
    ],
    uncertainty: { conflict: true, summary: "Two press sources disagree on Q3 launch timing (Sept vs. Nov). Field kept CONFLICTING — not collapsed to one answer." },
    anchors: [
      { kind: "SOURCE",    note: "P01 released-source lineage" },
      { kind: "LICENSE",   note: "Press feed · use-class within terms" },
      { kind: "RETENTION", note: "180-day rolling · required before release" },
    ],
  },
};

/* Entity exposure summaries — bounded decision-support rollups, NOT certified
   risk ratings (INV-P02-009 discipline). */
window.AFB.p02.exposure = [
  { id: "SUPPLIER_CONCENTRATION", label: "Supplier concentration", level: "Elevated", tone: "amber",
    note: "Nordvik and Kvant both source from Västkust Komponenter — single-supplier dependency on a shared component line." },
  { id: "COMPETITIVE_PRESSURE", label: "Competitive pressure", level: "Rising", tone: "amber",
    note: "Boreal (+12% price, enterprise pivot) and Fjordr (compliance repositioning) moving on the same segment within one cycle." },
];

/* Stakeholder role profiles — minimized business-role objects ONLY.
   The forbidden-field guard (no psych / emotion / loyalty / productivity) is a
   first-class UI affordance (INV-P02-006/-007). */
window.AFB.p02.stakeholders = [
  { id: "SH-014", name: "Anna Lind", role: "Head of Procurement", company: "Acme Logistics",
    decisionRole: "ECONOMIC_BUYER", contact: "OPTED_IN", source: "Public professional profile", visibility: "Sales · CS" },
  { id: "SH-021", name: "Erik Sandberg", role: "Operations Director", company: "Stenmark Logistik",
    decisionRole: "TECHNICAL_EVALUATOR", contact: "NO_BASIS", source: "Public company page", visibility: "Sales" },
];
window.AFB.p02.forbiddenFields = ["psychographic profile", "emotion / sentiment", "loyalty score", "productivity score", "sensitive traits", "private-life inference"];

/* Offering twins — products with evidence-cited claims. claim_status gates
   release: UNVERIFIED / FORBIDDEN cannot be released (§8.4). */
window.AFB.p02.offerings = [
  { id: "OFF-01", name: "Fleet Telemetry Suite", owner: "Nordvik Industrier AB", version: "v4",
    claims: [
      { txt: "Real-time fleet sensing across 50+ unit fleets", status: "APPROVED_EVIDENCED", anchor: "M05 · ev_telem_4421" },
      { txt: "EU-compliant data residency", status: "TENANT_DECLARED", anchor: "tenant positioning" },
      { txt: "Best-in-class uptime", status: "UNVERIFIED", anchor: "blocked from release" },
    ] },
];

/* A twin-state change in flight on the governed rail (M04→M07→M08→M10). */
window.AFB.p02.flow = {
  id: "ETSC-0461", target: "Boreal Systems AB", proposal: "ENTITY_TWIN_STATE_CHANGE",
  step: "M08", verdict: null, review: "TWIN_STATE_REVIEW",
  note: "Folding the P01 Tier-2 price diff (DIFF-3307) into Boreal's twin v8. In P02_TWIN_STATE_ADMISSION — 15 rules, derives every fact from G18.",
  rail: [
    { m: "M04", label: "Proposal", state: "done" },
    { m: "M05", label: "Evidence basis", state: "done" },
    { m: "M07", label: "Twin state review", state: "done" },
    { m: "M08", label: "Admission", state: "active" },
    { m: "M10", label: "Snapshot", state: "pending" },
    { m: "M12", label: "Surfacing", state: "pending" },
  ],
};

/* value_status → tone, for the twin inspector badges. */
window.AFB.p02.statusTone = {
  KNOWN_FACT: "green", TENANT_PROVIDED: "green", PUBLIC_SOURCE: "cyan", LICENSED_SOURCE: "cyan",
  DERIVED_SUMMARY: "violet", INFERRED_HYPOTHESIS: "amber", UNSUPPORTED_CLAIM: "red",
  UNKNOWN: "neutral", CONFLICTING: "amber", STALE: "amber", PROHIBITED: "red",
};
