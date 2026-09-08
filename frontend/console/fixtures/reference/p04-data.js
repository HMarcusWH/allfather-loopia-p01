/* Allfather BI — P04 Intelligence Distribution seed data.
   Third commercial module (arch §21.4). Routes M08-approved, M10-recorded
   intelligence to authorized organizational roles. Action class C — internal
   routing only; NO new canonical state (role views are projections over the
   SAME M10 records, never copies). Per-role redaction + default-deny visibility.

   HARD BOUND: internal workflow monitoring is privacy-controlled and MUST NOT
   become covert employee scoring (stakeholder-role identification deferred to a
   later module). Knowledge tracking records who received/reviewed/acted. */
window.AFB = window.AFB || {};

window.AFB.p04 = {
  tier: { name: "Command", code: "TIER_COMMAND", note: "Distribution entitled" },
  usage: { tokens: "0.21M", cycle: "Billing cycle · Jun 2026", charge: "recording-only" },

  stats: [
    { id: "dist",   label: "Intelligence routed", value: "18", unit: "this wk", tone: "cyan",   icon: "share-2",      sub: "M10-recorded only" },
    { id: "roles",  label: "Role consoles",       value: "5",  unit: "active",  tone: "gold",   icon: "users",        sub: "projection per role" },
    { id: "review", label: "Awaiting review",     value: "4",  unit: "items",   tone: "amber",  icon: "mail-open",    sub: "receipt tracked" },
    { id: "ack",    label: "Acknowledged",        value: "78%",unit: "delivered",tone: "green",  icon: "check-check",  sub: "knowledge tracking" },
    { id: "coord",  label: "Coordination threads",value: "2",  unit: "open",    tone: "violet", icon: "git-pull-request-arrow", sub: "cross-team" },
  ],
};

/* Role consoles — each is a redacted projection of the same canonical state. */
window.AFB.p04.roles = [
  { id: "ceo",   role: "CEO",             lens: "Strategy & risk",     icon: "compass",   tone: "gold",
    scope: "Market posture · material risks · competitor strategy.", items: 6, unread: 1,
    redaction: "Financial detail shown as summary." },
  { id: "cfo",   role: "CFO",             lens: "Financial risk",      icon: "landmark",  tone: "green",
    scope: "Supplier risk · regulatory cost exposure · anomaly flags.", items: 4, unread: 1,
    redaction: "Full financial fields." },
  { id: "cmo",   role: "CMO",             lens: "Market & competitor", icon: "megaphone", tone: "cyan",
    scope: "Competitor moves · positioning · public presence.", items: 7, unread: 1,
    redaction: "Sales pipeline hidden." },
  { id: "sales", role: "Sales",           lens: "Opportunities",       icon: "target",    tone: "amber",
    scope: "Battlecards · priority outreach · meeting briefs.", items: 5, unread: 0,
    redaction: "Finance internals hidden." },
  { id: "cs",    role: "Customer Success",lens: "Retention",           icon: "life-buoy", tone: "violet",
    scope: "Feedback themes · churn-risk briefs.", items: 3, unread: 1,
    redaction: "Individual scoring excluded." },
];

/* Distribution ledger — each routed item references a canonical M10 Decision
   Record (never a copy). Per-role acknowledgement: delivered · reviewed · acted · pending. */
window.AFB.p04.dist = [
  { id: "DR-1188", title: "Boreal Tier-2 pricing — response play", type: "BATTLECARD_BRIEF", plane: "Ops", from: "P01", urgency: "High",
    routes: [{ role: "CMO", ack: "reviewed" }, { role: "Sales", ack: "acted" }, { role: "CEO", ack: "delivered" }],
    redaction: "Pricing internals visible to CMO/Sales; CEO sees the summary lens." },
  { id: "DR-1186", title: "EU Data Act — switching-cost obligations", type: "COMPANY_SIGNAL_BRIEF", plane: "Sources", from: "P01", urgency: "Medium",
    routes: [{ role: "CFO", ack: "reviewed" }, { role: "CEO", ack: "delivered" }, { role: "Product", ack: "pending" }],
    redaction: "Routed to Finance & Product by regulatory class." },
  { id: "DR-1184", title: "Acme Logistics — compliance fit (priority outreach)", type: "BATTLECARD_BRIEF", plane: "Ops", from: "P03", urgency: "High",
    routes: [{ role: "Sales", ack: "reviewed" }, { role: "CMO", ack: "delivered" }],
    redaction: "Fit hypothesis only — no buyer-intent claim is distributed." },
  { id: "DR-1180", title: "Q2 retention — feedback theme summary", type: "DECISION_RECORD", plane: "Ops", from: "P06*", urgency: "Low",
    routes: [{ role: "Customer Success", ack: "acted" }, { role: "CEO", ack: "reviewed" }],
    redaction: "Aggregated themes only — no individual employee or customer scoring." },
];

/* Cross-team coordination / crisis routing. */
window.AFB.p04.coord = [
  { id: "COORD-07", title: "Boreal pricing response", roles: ["CMO", "Sales", "CEO"], status: "active",
    note: "Shared response staged; awaiting CEO signature on the battlecard release.", crisis: false },
  { id: "COORD-08", title: "Regulatory exposure review", roles: ["CFO", "Product", "CEO"], status: "forming",
    note: "EU Data Act clarification — Finance & Product assessing impact before routing.", crisis: false },
];
