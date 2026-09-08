/* Allfather BI — P03 Lead Qualification & Product Matching seed data.
   Second commercial module (schema G14 landed). Flow (arch §21.3):
   product catalogue → company observations → FIT HYPOTHESIS → evidence/
   uncertainty review → priority outreach queue → prospect battlecard →
   human sales decision (OUTSIDE the system). Action class B/C — no external effect.

   HARD INVARIANT — buyer-intent (storage-pinned + M08 non-overridable + M11 audit):
   output is an evidence-supported FIT HYPOTHESIS, NEVER a claim of buyer intent.
   `claim_kind` excludes BUYER_INTENT_CLAIMED; a queue item REQUIRES a triggering
   company-signal FK. Canonical names: MonitoredProspect (Watch List) ·
   PriorityOutreachQueueItem (Priority Outreach Queue — the prohibited "Call Now
   List" label never appears in product UI). */
window.AFB = window.AFB || {};

window.AFB.p03 = {
  tier: { name: "Command", code: "TIER_COMMAND", note: "Lead Qualification entitled" },
  usage: { tokens: "0.62M", cycle: "Billing cycle · Jun 2026", charge: "recording-only" },

  stats: [
    { id: "catalogue", label: "Catalogue items",   value: "6",  unit: "products",  tone: "cyan",   icon: "package",      sub: "tenant-scoped source" },
    { id: "fit",       label: "Fit hypotheses",    value: "7",  unit: "open",      tone: "violet", icon: "git-merge",    sub: "evidence-backed" },
    { id: "watch",     label: "Monitored prospects",value: "11", unit: "watch",     tone: "neutral",icon: "binoculars",   sub: "awaiting trigger" },
    { id: "queue",     label: "Priority outreach",  value: "3",  unit: "ready",     tone: "gold",   icon: "list-checks",  sub: "trigger-backed" },
    { id: "icp",       label: "ICP excluded",       value: "1",  unit: "blocked",   tone: "violet", icon: "crosshair",    sub: "M08 segment match" },
    { id: "blocked",   label: "Intent claims blocked",value: "2",unit: "by Arbiter",tone: "red",    icon: "shield-x",     sub: "downgraded to fit" },
  ],
};

/* ICP PROFILE — the ratified bounded P03 extension (CHG-121..126; ICP-D5
   GRANTED 2026-06-12, internal / no-customer-visible ceiling). Tenant-declared
   FIRST-PARTY configuration only: buyer roles are closed P04 role CLASSES,
   NEVER person data. The versioned deterministic scorer (p03-icp-fit-scorer-v2)
   attributes every fit hypothesis; an excluded-segment match is a non-overridable
   M08 BLOCK (`p03_icp_excluded_segment` → ICP_EXCLUDED_SEGMENT_MATCH). One ACTIVE
   profile per tenant/workspace; the company-size band stays optional (no guessing). */
window.AFB.p03.icp = {
  scorer: "p03-icp-fit-scorer-v2",
  gate: "OWNER-GATED · ICP-D5 · internal / no-customer-visible",
  version: "profile v4 · ACTIVE",
  industries: ["Logistics & distribution", "Industrial / asset-heavy", "Regulated cloud"],
  geographies: ["Sweden", "Nordics"],
  sizeBand: "50–500 employees · optional (no guessing)",
  roleClasses: ["OPERATIONS_LEADERSHIP", "FLEET_PROCUREMENT", "COMPLIANCE_FUNCTION"],
  excluded: ["Public-sector tenders", "Pre-seed startups", "Consumer / B2C"],
};

/* Customer product catalogue (the matching input — tenant-owned source class). */
window.AFB.p03.catalogue = [
  { id: "telem",  name: "Fleet Telemetry Suite",       cat: "Platform",  vp: "Real-time fleet sensing + uptime analytics.", icp: "Logistics / industrial fleets > 50 units" },
  { id: "maint",  name: "Predictive Maintenance Module",cat: "Add-on",    vp: "Failure prediction from machine telemetry.",  icp: "Asset-heavy operators, multi-site" },
  { id: "comply", name: "Compliance Reporting Pack",    cat: "Add-on",    vp: "Automated EU regulatory reporting.",          icp: "Regulated logistics & cloud" },
  { id: "route",  name: "Route Optimisation Engine",    cat: "Platform",  vp: "Cost-aware multi-stop routing.",              icp: "Distribution & last-mile" },
];

/* FIT HYPOTHESES — the hero. Each pairs a product to an observed company signal,
   states an explicit hypothesis with uncertainty, cites evidence, and rides the
   governance rail. `guardrail` cards demonstrate the buyer-intent invariant. */
window.AFB.p03.fits = [
  { id: "FIT-0512", product: "Fleet Telemetry Suite", company: "Acme Logistics", tone: "green",
    claimKind: "PRODUCT_FIT_HYPOTHESIS",
    claim: "Acme's Series B + stated procurement initiative is consistent with demand for fleet-scale telemetry.",
    confidence: "Moderate", coverage: 78,
    icp: { score: 79, match: "Industry + geography + FLEET_PROCUREMENT role-class" },
    uncertainty: "Funding is primary-sourced; the fleet-expansion link is inferred, not stated. Treated as a hypothesis.",
    triggerSignal: "PRP-0419", evidence: 4, state: "review", verdict: null, guardrail: false },

  { id: "FIT-0511", product: "Predictive Maintenance Module", company: "Stenmark Logistik", tone: "cyan",
    claimKind: "PRODUCT_FIT_HYPOTHESIS",
    claim: "Nordic footprint expansion suggests multi-site assets that fit predictive maintenance.",
    confidence: "Moderate", coverage: 71,
    icp: { score: 68, match: "Industry + geography" },
    uncertainty: "Expansion is public; asset profile is inferred from sector norms. Hypothesis, pending a primary signal.",
    triggerSignal: "PRP-0423", evidence: 3, state: "evidence", verdict: null, guardrail: false },

  { id: "FIT-0509", product: "Compliance Reporting Pack", company: "Acme Logistics", tone: "green",
    claimKind: "PRODUCT_FIT_HYPOTHESIS",
    claim: "EU Data Act clarification raises Acme's reporting burden — a fit for the compliance pack.",
    confidence: "High", coverage: 88,
    icp: { score: 91, match: "Industry + geography + COMPLIANCE_FUNCTION role-class" },
    uncertainty: "Regulatory trigger is primary-sourced and directly relevant. Strongest open hypothesis.",
    triggerSignal: "PRP-0422", evidence: 5, state: "frozen", verdict: "ALLOW_PROVISIONAL_ANALYSIS", guardrail: false },

  /* GUARDRAIL — ICP exclusion: the active profile excludes public-sector tenders;
     the M08 segment-match rule BLOCKs admission non-overridably (same-scope ACTIVE
     profile only), so the prospect is never queued. */
  { id: "FIT-0505", product: "Compliance Reporting Pack", company: "Kommun IT Väst", tone: "red",
    claimKind: "PRODUCT_FIT_HYPOTHESIS",
    claim: "A public-sector procurement notice was proposed as a compliance-pack fit.",
    confidence: "Low", coverage: 22,
    icp: { score: 0, match: "Excluded segment · Public-sector tenders" },
    uncertainty: "The tenant's ACTIVE ICP profile excludes public-sector tenders.",
    triggerSignal: "PRP-0420", evidence: 1, state: "blocked", verdict: "BLOCK", guardrail: true,
    guardrailNote: "Arbiter blocked admission on the ACTIVE ICP profile (rule ICP_EXCLUDED_SEGMENT_MATCH via p03_icp_excluded_segment, non-overridable). Recorded as an excluded-segment match against prospect facts only — never queued, never customer-visible." },

  /* GUARDRAIL — the invariant in action: an over-claim is blocked and downgraded. */
  { id: "FIT-0508", product: "Route Optimisation Engine", company: "Kvant Industri", tone: "red",
    claimKind: "PRODUCT_FIT_HYPOTHESIS",
    claim: "Kvant's Göteborg hiring was proposed as “ready to buy routing software this quarter.”",
    confidence: "Low", coverage: 34,
    icp: { score: 41, match: "Industry only — no role-class signal" },
    uncertainty: "Hiring alone cannot support a buyer-intent claim.",
    triggerSignal: "PRP-0424", evidence: 1, state: "blocked", verdict: "BLOCK", guardrail: true,
    guardrailNote: "Arbiter blocked the buyer-intent assertion (rule BUYER_INTENT_UNSUPPORTED, non-overridable). Surfaced instead as a low-confidence fit hypothesis. claim_kind pinned ≠ BUYER_INTENT_CLAIMED." },
];

/* PRIORITY OUTREACH QUEUE — companies with timely, evidence-backed signals
   surfaced for human outreach. Each REQUIRES a triggering company-signal (FK).
   Admission gated by Arbiter policy (evidence + freshness + fit thresholds). */
window.AFB.p03.queue = [
  { rank: 1, company: "Acme Logistics", seg: "Prospect", fit: "FIT-0509", product: "Compliance Reporting Pack",
    trigger: "PRP-0422 · REGULATORY_CHANGE", fitScore: 88, fresh: "1d", verdict: "ALLOW_PROVISIONAL_ANALYSIS",
    brief: "BTL-P03-09", reason: "Primary-sourced regulatory trigger directly raises reporting burden." },
  { rank: 2, company: "Acme Logistics", seg: "Prospect", fit: "FIT-0512", product: "Fleet Telemetry Suite",
    trigger: "PRP-0419 · FUNDING_EVENT", fitScore: 78, fresh: "5h", verdict: "ALLOW_PROVISIONAL_ANALYSIS",
    brief: "BTL-P03-12", reason: "Funded expansion + procurement initiative; fleet link inferred." },
  { rank: 3, company: "Stenmark Logistik", seg: "Prospect", fit: "FIT-0511", product: "Predictive Maintenance Module",
    trigger: "PRP-0423 · EXPANSION_SIGNAL", fitScore: 71, fresh: "3d", verdict: "ALLOW_WITH_CONDITIONS",
    brief: "BTL-P03-11", reason: "Expansion public; admitted with a freshness condition." },
];

/* MONITORED PROSPECTS (Watch List) — promising but not yet ready; each carries a
   reason-for-monitoring and the trigger that would promote it to the queue. */
window.AFB.p03.watch = [
  { company: "Kvant Industri",  seg: "Adjacent", reason: "Regional hiring only — no commercial trigger.", waitingFor: "Procurement or funding signal", signals: 2, last: "2d", tone: "neutral" },
  { company: "Aurora Labs",     seg: "Competitor-adjacent", reason: "Quiet since partnership PR.", waitingFor: "Product or pricing move", signals: 1, last: "4d", tone: "neutral" },
  { company: "Boreal Systems AB",seg: "Competitor", reason: "Monitored for displacement openings, not as a buyer.", waitingFor: "Customer-churn signal", signals: 5, last: "2h", tone: "amber" },
  { company: "Fjordr",          seg: "Competitor", reason: "Repositioning; watch for partner gaps.", waitingFor: "Integration / partner signal", signals: 3, last: "1d", tone: "cyan" },
];
