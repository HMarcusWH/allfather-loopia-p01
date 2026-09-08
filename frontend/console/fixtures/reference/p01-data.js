/* Allfather BI — P01 Competition Monitoring seed data.
   The first commercial module (shipped: G13, source-cards, live capture,
   change detection → COMPANY_SIGNAL_BRIEF → relevance review → Arbiter rule
   engine → BATTLECARD_BRIEF → M10 release → tier-gated M12 surfacing → usage
   metering → SOURCE_FRESHNESS / DIFF_INTEGRITY assurance).
   Substrate vocabulary throughout. action class A/B/C — no external effect. */
window.AFB = window.AFB || {};

window.AFB.p01 = {
  /* Subscription entitlement (two-gate default-deny: M02 role-permission AND tier).
     Recording-only usage metering — no external charge executed. */
  tier: { name: "Command", code: "TIER_COMMAND", note: "Competition Monitor entitled" },
  usage: { tokens: "1.84M", cycle: "Billing cycle · Jun 2026", released: 3, signals: 5, charge: "recording-only" },

  /* Operational monitoring posture — not analytics. */
  stats: [
    { id: "sources",     label: "Registered sources",  value: "11",    unit: "cards",   tone: "cyan",   icon: "rss",          sub: "approved source cards · 1 pending review" },
    { id: "fresh",       label: "Source freshness",    value: "9/11",  unit: "fresh",   tone: "amber",  icon: "radar",        sub: "1 warn · 1 terms recheck overdue" },
    { id: "changes",     label: "Changes detected",    value: "4",     unit: "7d",      tone: "amber",  icon: "git-compare",  sub: "2 major · 2 structural" },
    { id: "signals",     label: "Signal briefs",       value: "4",     unit: "in rail", tone: "violet", icon: "file-text",    sub: "review · verdict" },
    { id: "battlecards", label: "Battlecards released",value: "2",     unit: "internal",tone: "gold",   icon: "shield-check",  sub: "M10-recorded · M16-projected" },
  ],
};

/* Watchlist → monitored sources, each gated by an approved source card.
   card: APPROVED · PENDING_REVIEW · BLOCKED  (fail-closed on unknown/prohibited)
   fresh: FRESH · WARN · STALE · TERMS_RECHECK_OVERDUE  (card-owned thresholds) */
window.AFB.p01.watchlist = [
  { id: "boreal", name: "Boreal Systems AB", seg: "Direct competitor", posture: "Threat", tone: "amber", signals: 5,
    sources: [
      { url: "boreal.se/pricing",       card: "APPROVED", fresh: "FRESH", checked: "2h",  lic: "Public web · terms clear" },
      { url: "boreal.se/launch",        card: "APPROVED", fresh: "FRESH", checked: "2h",  lic: "Public web" },
      { url: "press · Mynewsdesk",      card: "APPROVED", fresh: "WARN",  checked: "19h", lic: "Press feed · recheck 24h" },
    ] },
  { id: "fjordr", name: "Fjordr", seg: "Direct competitor", posture: "Watch", tone: "cyan", signals: 3,
    sources: [
      { url: "fjordr.io/launch",        card: "APPROVED", fresh: "FRESH", checked: "1d",  lic: "Public web" },
      { url: "fjordr.io/pricing",       card: "APPROVED", fresh: "FRESH", checked: "1d",  lic: "Public web" },
    ] },
  { id: "acme", name: "Acme Logistics", seg: "Prospect", posture: "Opportunity", tone: "green", signals: 4,
    sources: [
      { url: "acme.com/news",           card: "APPROVED", fresh: "FRESH", checked: "5h",  lic: "Public web" },
      { url: "procurement portal",      card: "PENDING_REVIEW", fresh: "—", checked: "—",  lic: "Unknown license · held" },
    ] },
  { id: "kvant", name: "Kvant Industri", seg: "Adjacent", posture: "Watch", tone: "neutral", signals: 2,
    sources: [
      { url: "kvant.se",                card: "APPROVED", fresh: "FRESH", checked: "2d",  lic: "Public web" },
      { url: "Börsdata · financials",   card: "APPROVED", fresh: "TERMS_RECHECK_OVERDUE", checked: "3d", lic: "Licensed feed · terms lapsed" },
    ] },
  { id: "scb", name: "Regulatory & market", seg: "Context", posture: "Monitor", tone: "violet", signals: 1,
    sources: [
      { url: "ec.europa.eu/data-act",   card: "APPROVED", fresh: "FRESH", checked: "1d",  lic: "Public regulatory" },
      { url: "SCB · sector index",      card: "APPROVED", fresh: "FRESH", checked: "1d",  lic: "Statistics Sweden · open" },
    ] },
];

/* Change feed — the hero. detectSourceChange → source_diffs (significance) →
   CHANGE_DETECTION_REFERENCE evidence → COMPANY_SIGNAL_BRIEF (M04) →
   COMPETITION_RELEVANCE_REVIEW (M07) → Arbiter verdict (M08) → BATTLECARD_BRIEF.
   diff parts: {t, hi} — hi marks the changed span. significance: MAJOR·STRUCTURAL·MINOR */
window.AFB.p01.changes = [
  { id: "DIFF-3307", entity: "Boreal Systems AB", source: "boreal.se/pricing", captured: "2h", significance: "MAJOR",
    signalClass: "PRICING_CHANGE",
    before: [{ t: "Tier-2 — " }, { t: "4 900 kr", hi: true }, { t: " / mo. “Simple pricing for growing teams.”" }],
    after:  [{ t: "Tier-2 — " }, { t: "5 488 kr", hi: true }, { t: " / mo. “Enterprise-grade controls, priced for scale.”" }],
    snapBefore: "snap_9f2a14", snapAfter: "snap_b71c08",
    signal: { ref: "PRP-0418", state: "recorded", verdict: "ALLOW_INFORMATIONAL_RELEASE", evidence: 3, route: "CMO · Sales", battlecard: "BTL-0418" },
    observed: "Tier-2 list price 4 900 kr → 5 488 kr (+12.0%). Headline copy replaced. Both spans are present in the captured page.",
    interpretation: "Possible shift toward higher-value, control-led packaging. Consistent with an enterprise motion — not evidence of one.",
    confidence: "MEDIUM", ceiling: "Advisory. No pricing recommendation is authorized from this observation alone." },

  { id: "DIFF-3306", entity: "Acme Logistics", source: "acme.com/news", captured: "5h", significance: "MAJOR",
    signalClass: "FUNDING_EVENT",
    before: [{ t: "Company updates — no funding disclosures." }],
    after:  [{ t: "“Acme closes ", hi: true }, { t: "Series B", hi: true }, { t: "; opening a procurement initiative for Q3.”" }],
    snapBefore: "snap_4ad9c2", snapAfter: "snap_7e10bb",
    signal: { ref: "PRP-0419", state: "hold", verdict: "REQUIRE_MORE_EVIDENCE", evidence: 2, route: "Sales", battlecard: null },
    observed: "A Series B funding announcement is present on the company updates page. No prior funding disclosure existed in the previous capture.",
    interpretation: "A procurement initiative may be opening. The only support for this reading is the announcement's own phrasing; no primary source was captured.",
    confidence: "LOW", ceiling: "Held. The procurement claim cannot be released while its supporting evidence object is quarantined." },

  { id: "DIFF-3305", entity: "Fjordr", source: "fjordr.io/launch", captured: "1d", significance: "STRUCTURAL",
    signalClass: "POSITIONING_SHIFT",
    before: [{ t: "“The ", hi: true }, { t: "simple", hi: true }, { t: " way to ship.”" }],
    after:  [{ t: "“", hi: true }, { t: "Compliant by default", hi: true }, { t: ". Ship with confidence.”" }],
    snapBefore: "snap_c0b1f7", snapAfter: "snap_d3e9a4",
    signal: { ref: "PRP-0420", state: "review", verdict: null, evidence: 2, route: "CMO", battlecard: null },
    observed: "Positioning claim changed from “the simple way to ship” to “compliant by default”. No pricing element changed in this capture.",
    interpretation: "A messaging pivot toward regulated buyers. The capture covers published messaging only — it says nothing about certification status.",
    confidence: "MEDIUM", ceiling: "Advisory. In relevance review; no claim about their compliance posture may be made." },

  { id: "DIFF-3304", entity: "Regulatory & market", source: "ec.europa.eu/data-act", captured: "1d", significance: "STRUCTURAL",
    signalClass: "REGULATORY_CHANGE",
    before: [{ t: "Switching-cost guidance — draft, under consultation." }],
    after:  [{ t: "Switching-cost obligations ", hi: true }, { t: "clarified", hi: true }, { t: " for cloud providers." }],
    snapBefore: "snap_11aa90", snapAfter: "snap_22bc41",
    signal: { ref: "PRP-0422", state: "evidence", verdict: null, evidence: 1, route: "Finance · Product", battlecard: null },
    observed: "Switching-cost guidance moved from draft consultation to clarified obligations for cloud providers.",
    interpretation: "Likely material to our portfolio compliance posture. Scope of applicability has not been assessed.",
    confidence: "NOT_QUANTIFIED", ceiling: "Evidence threshold still being assembled. Not admissible to review yet." },
];

/* Released BATTLECARD_BRIEF artifacts (Class C RELEASE_INTELLIGENCE, M10-sealed).
   Surfaced under two-gate default-deny — a tier-locked card shows a governed lock,
   never its content. reliance: CURRENT · SUPERSEDED. */
window.AFB.p01.battlecards = [
  { id: "BTL-0418", title: "Boreal Tier-2 pricing — response play", entity: "Boreal Systems AB",
    released: "2h", asOf: "as-of 06:40", verdict: "ALLOW_INFORMATIONAL_RELEASE", reliance: "CURRENT",
    evidence: 3, route: "CMO · Sales", locked: false, tierReq: null,
    summary: "Counter-positioning on total cost of control vs. headline price. Three proof points; two objection handlers. Recorded as DR-0071 and projected to TENANT_INTERNAL — no approval was required because nothing effect-bearing was proposed." },
  { id: "BTL-0411", title: "Fjordr compliance repositioning", entity: "Fjordr",
    released: "1d", asOf: "as-of 09:14", verdict: "ALLOW_INFORMATIONAL_RELEASE", reliance: "CURRENT",
    evidence: 2, route: "CMO", locked: false, tierReq: null,
    summary: "Where Fjordr now claims “compliant by default” — gaps in evidence, and how to frame our certified posture against it." },
  { id: "BTL-0402", title: "Sector cost-structure deep dossier", entity: "Kvant Industri",
    released: "3d", asOf: "as-of Jun 02", verdict: "ALLOW_WITH_CONDITIONS", reliance: "CURRENT",
    evidence: 5, route: "CFO", locked: true, tierReq: "Governed Ops",
    summary: "" },
];

/* M11 assurance — the two P01 audit profiles. A finding opens an incident (INV-014). */
window.AFB.p01.assurance = [
  { id: "SOURCE_FRESHNESS", label: "Source freshness", pass: 45, total: 47, tone: "amber",
    findings: [
      { sev: "warn",  txt: "Mynewsdesk press feed · WARN — 19h since recheck (warn_after 12h)", inc: null },
      { sev: "warn",  txt: "Börsdata · TERMS_RECHECK_OVERDUE — terms review lapsed", inc: "INC-0091" },
    ] },
  { id: "DIFF_INTEGRITY", label: "Diff integrity", pass: 14, total: 14, tone: "green",
    findings: [] },
];
