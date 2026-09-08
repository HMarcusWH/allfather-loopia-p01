/* Allfather BI — Intelligence-layer seed data.
   Extends window.AFB (see data.js) with the everyday-BI / executive surface:
   strategic posture, change-detection signals, monitored companies, the
   executive briefing, and the evidence-provenance MANIFOLD graph that powers
   the signature visualization. Kept in substrate vocabulary throughout. */
window.AFB = window.AFB || {};

/* Strategic top-line — the executive's "what is true right now" tiles.
   `delta` is the 7d change; `spark` is a 12-point trend (0..1 normalised). */
window.AFB.posture = {
  stance: "Defending",
  stanceNote: "Two competitors moved on price this week. Responses staged, none auto-executed.",
  index: 71,           // market posture index 0..100
  indexDelta: -4,
};

window.AFB.metrics = [
  { id: "watched",  label: "Entities watched",      value: "9",   unit: "companies", tone: "neutral", icon: "radar",
    spark: [.5,.5,.55,.6,.6,.62,.7,.72,.78,.82,.86,.9], delta: "+2" },
  { id: "sources",  label: "Sources monitored",     value: "47",  unit: "live feeds", tone: "cyan", icon: "rss",
    spark: [.3,.34,.4,.42,.5,.52,.6,.66,.7,.78,.86,.95], delta: "+6" },
  { id: "coverage", label: "Evidence coverage",     value: "92%", unit: "claims grounded", tone: "cyan", icon: "link",
    spark: [.6,.62,.66,.64,.7,.72,.74,.8,.82,.86,.9,.92], delta: "+3pt" },
  { id: "sealed",   label: "Decisions sealed",      value: "12",  unit: "last 30 days", tone: "gold", icon: "book-marked",
    spark: [.2,.3,.3,.4,.45,.5,.55,.6,.7,.78,.88,1], delta: "+4" },
];

/* Change-detection signals — the intelligence feed. Each maps loosely to the
   substrate (a mission observed it; some became proposals). materiality drives
   ranking; `kind` drives the role accent. */
window.AFB.signals = [
  { id: "SIG-2207", kind: "competitive", materiality: "High", title: "Boreal Systems raised Tier‑2 pricing +12%",
    entity: "Boreal Systems AB", source: "boreal.se/pricing", fresh: "2h", tone: "amber",
    detail: "List price up 12% and launch‑page positioning rewritten toward enterprise. Battlecard response staged as PRP‑0418.",
    linkArtifact: "PRP-0418", evidence: 3, verdict: "ALLOW_INFORMATIONAL_RELEASE" },
  { id: "SIG-2206", kind: "opportunity", materiality: "High", title: "Acme Logistics announced a funding round",
    entity: "Acme Logistics", source: "acme.com/news/funding", fresh: "5h", tone: "green",
    detail: "Series B close plus a procurement initiative relevant to the portfolio. Priority Outreach Queue admission held — procurement signal lacks a primary source.",
    linkArtifact: "PRP-0419", evidence: 2, verdict: "REQUIRE_MORE_EVIDENCE" },
  { id: "SIG-2205", kind: "competitive", materiality: "Medium", title: "Fjordr rewrote launch‑page positioning",
    entity: "Fjordr", source: "fjordr.io/launch", fresh: "1d", tone: "cyan",
    detail: "Messaging shifted from “simple” to “compliant by default”. Before/after captured; no pricing change detected.",
    linkArtifact: null, evidence: 2, verdict: null },
  { id: "SIG-2204", kind: "risk", materiality: "Medium", title: "EU Data Act guidance update published",
    entity: "Regulatory", source: "ec.europa.eu/data-act", fresh: "1d", tone: "violet",
    detail: "Clarifies switching‑cost obligations for cloud providers. Flagged for finance & product review; no customer action implied yet.",
    linkArtifact: null, evidence: 1, verdict: null },
  { id: "SIG-2203", kind: "signal", materiality: "Low", title: "Kvant Industri opened 4 sales roles in Göteborg",
    entity: "Kvant Industri", source: "linkedin · jobs", fresh: "2d", tone: "neutral",
    detail: "Hiring pattern suggests a regional commercial push. Watch‑only; not yet material to positioning.",
    linkArtifact: null, evidence: 1, verdict: null },
];

/* Monitored companies — the watchlist projection. `health` is the threat/opp
   posture toward Nordvik; `change` is the 7d signal volume. */
window.AFB.companies = [
  { id: "boreal",  name: "Boreal Systems AB", seg: "Direct competitor", posture: "Threat",      signals: 5, change: "up",   last: "2h",  tone: "amber",  note: "Pricing + positioning move underway." },
  { id: "fjordr",  name: "Fjordr",            seg: "Direct competitor", posture: "Watch",       signals: 3, change: "up",   last: "1d",  tone: "cyan",   note: "Repositioning around compliance." },
  { id: "acme",    name: "Acme Logistics",    seg: "Prospect",          posture: "Opportunity", signals: 4, change: "up",   last: "5h",  tone: "green",  note: "Funding + procurement signal." },
  { id: "kvant",   name: "Kvant Industri",    seg: "Adjacent",          posture: "Watch",       signals: 2, change: "flat", last: "2d",  tone: "neutral",note: "Regional hiring only." },
  { id: "aurora",  name: "Aurora Labs",       seg: "Direct competitor", posture: "Watch",       signals: 1, change: "down", last: "4d",  tone: "neutral",note: "Quiet since partnership PR." },
  { id: "stenmark",name: "Stenmark Logistik", seg: "Prospect",          posture: "Opportunity", signals: 2, change: "flat", last: "3d",  tone: "green",  note: "Expanding Nordic footprint." },
];

/* Executive morning briefing — the role-specific projection (Command package).
   Sentence-case prose, decisive, never hyped. */
window.AFB.briefing = {
  date: "Friday · 28 August 2026",
  greeting: "Morning briefing",
  lede: "Posture is defensive this week. Two direct competitors moved on price and positioning; both responses are staged and evidence‑grounded, and nothing has executed without your sign‑off.",
  needsYou: [
    { ref: "PRP-0418", label: "Battlecard release · Tier‑2 pricing response", state: "frozen", note: "Frozen for approval. Evidence verified, Arbiter ALLOW." },
  ],
  thin: [
    { ref: "PRP-0419", label: "Admit Acme Logistics to the Priority Outreach Queue", state: "hold", note: "Procurement signal unverified — add source or downgrade." },
  ],
  blocked: [
    { ref: "PRP-0421", label: "Auto‑adjust ad budget on competitor launch", state: "blocked", note: "Effect‑bearing action outside the V1 safe set." },
  ],
};

/* ---------------------------------------------------------------------------
   EVIDENCE-PROVENANCE MANIFOLD (signature viz).
   Concentric planes converging on the gold Core eye:
     ring 3 (outer) = Sources      · cyan, evidence authority
     ring 2          = Evidence     · cyan
     ring 1 (inner)  = Claims/Proposals · amber/gold (ops → core)
     center          = Core (the eye)
   `links` connect outward→inward: source → evidence → claim → core.
   `status`: ready | partial | missing | blocked | sealed.
   --------------------------------------------------------------------------- */
window.AFB.manifold = {
  core: { id: "core", label: "Allfather Core" },
  nodes: [
    // Sources (outer)
    { id: "s1", ring: "sources",  label: "boreal.se/pricing",       status: "ready" },
    { id: "s2", ring: "sources",  label: "boreal.se/launch",        status: "ready" },
    { id: "s3", ring: "sources",  label: "press · 2026-06-03",      status: "ready" },
    { id: "s4", ring: "sources",  label: "acme.com/funding",        status: "ready" },
    { id: "s5", ring: "sources",  label: "procurement signal",      status: "missing" },
    { id: "s6", ring: "sources",  label: "fjordr.io/launch",        status: "ready" },
    // Evidence (middle)
    { id: "e1", ring: "evidence", label: "EV · Tier‑2 +12%",        status: "ready" },
    { id: "e2", ring: "evidence", label: "EV · positioning diff",   status: "ready" },
    { id: "e3", ring: "evidence", label: "EV · funding close",      status: "ready" },
    { id: "e4", ring: "evidence", label: "EV · procurement",        status: "partial" },
    { id: "e5", ring: "evidence", label: "EV · messaging diff",     status: "ready" },
    // Claims / proposals (inner)
    { id: "c1", ring: "claims",   label: "PRP‑0418 · Battlecard",   status: "sealed" },
    { id: "c2", ring: "claims",   label: "PRP‑0419 · Priority Outreach", status: "missing" },
    { id: "c3", ring: "claims",   label: "PRP‑0421 · Ad budget",    status: "blocked" },
  ],
  links: [
    ["s1","e1"],["s3","e1"],["s2","e2"],["s6","e5"],
    ["s4","e3"],["s5","e4"],
    ["e1","c1"],["e2","c1"],
    ["e3","c2"],["e4","c2"],
    ["e5","c3"],
    ["c1","core"],["c2","core"],["c3","core"],
  ],
};
