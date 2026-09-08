/* Allfather BI — operator-throughline surfaces: the "Needs you" action queue,
   decision lineage traces, and the command-palette search index. All three are
   read-only projections that reuse existing governed objects — they add no new
   authority, only faster ways to reach what already exists. */
window.AFB = window.AFB || {};

/* ── "Needs you" — derived from LIVE artifact state + the M09 approval case +
   M11 incidents + the road-test owner-go gate. Grouped by the kind of operator
   authority each item needs. Nothing here acts; every row is a jump. ───────── */
window.AFB.buildQueue = function (arts) {
  const sections = [];
  const push = (key, title, tone, icon, desc, items) => {
    if (items.length) sections.push({ key, title, tone, icon, desc, items, owner: (window.AFB.queueOwner || {})[key] || null });
  };

  // 1. Signature required — frozen actions + the live M09 approval case
  const frozen = (arts || []).filter((a) => a.state === "frozen").map((a) => ({
    ref: a.ref, title: a.title, why: "Sealed for exact-artifact signature — approve or decline.", view: "frozen", trace: window.AFB.packetFor(a.ref),
  }));
  const ac = window.AFB.approvalCase;
  if (ac) frozen.push({ ref: ac.ref, title: ac.title, why: `${ac.actionClass.replace(/_/g, " ").toLowerCase()} · single-use · TTL ${ac.ttl}s. Awaiting your exact-artifact decision.`, view: "frozen", trace: window.AFB.packetFor(ac.proposal) });
  push("sign", "Signature required", "gold", "pen-line", "Only you hold approval authority for these exact frozen artifacts.", frozen);

  // 2. Decision required — holds (add evidence or downgrade the claim)
  const holds = (arts || []).filter((a) => a.state === "hold").map((a) => ({
    ref: a.ref, title: a.title,
    why: a.verdict ? a.verdict.basis : "Held pending a condition — add a primary source or downgrade the claim.",
    view: "mission-control", goId: a.id, trace: window.AFB.packetFor(a.ref),
  }));
  push("decide", "Decision required", "amber", "circle-pause", "Held pending your call — resolve the condition or downgrade.", holds);

  // 3. Owner-go pending — the single biggest outstanding operator decision
  const rt = window.AFB.roadTest;
  if (rt) push("owner-go", "Owner go pending", "gold", "key-round",
    "Execution capability is prepared and disabled; the only missing packet is your exact instruction.",
    [{ ref: "RT-001", title: "Road Test 001 — controlled live-source observation", why: rt.nextGate.gate + " — six intake packets defined, all MISSING.", view: "road-test" }]);

  // 4. Incident review — open / recommended M11 cases
  const inc = (window.AFB.incidents || []).filter((i) => i.status !== "RESOLVED").map((i) => ({
    ref: i.id, title: i.summary, why: `${i.cls.replace(/_/g, " ").toLowerCase()} · ${i.status.replace(/_/g, " ").toLowerCase()} · severity ${i.sev}.`, view: "replay",
  }));
  push("incident", "Incident review", "violet", "octagon-alert", "Containment recommended — the owning module enacts; you verify.", inc);

  // 5. Source operations — GSMC review lifecycle, health and rights (MVP classes)
  const g = window.AFB.gsmc;
  const srcItems = [];
  if (g) {
    g.routes.filter((r) => r.status === "OPEN" || r.status === "ACKNOWLEDGED").forEach((r) => srcItems.push({
      ref: r.id, title: `${r.sourceRef} · ${r.evidencePreview}`,
      why: `Live finding review ${r.status.toLowerCase()} · severity ${r.severity} · review due ${r.reviewDueAt.slice(5, 16)}. Owner GSMC.`,
      view: "sources", trace: r.packet,
    }));
    g.sources.filter((s) => s.health === "DEGRADED").forEach((s) => srcItems.push({
      ref: s.id, title: `${s.ref} degraded`,
      why: `${s.uncertainty} Last observed ${s.lastObserved}. Owner GSMC.`, view: "sources",
    }));
    g.sources.filter((s) => s.rights !== "APPROVED").forEach((s) => srcItems.push({
      ref: s.id, title: `${s.ref} · source rights ${s.rights.replace(/_/g, " ").toLowerCase()}`,
      why: `${s.rightsNote} New admission holds until a terms review is recorded.`, view: "sources",
    }));
  }
  push("sources", "Source operations", "cyan", "radar", "Monitoring configuration, review cadence and source rights — owned by GSMC, never by this queue.", srcItems);

  // 6. Intake held at admission — M15 candidates that cannot lawfully proceed
  const m15 = window.AFB.m15;
  const intake = m15 ? m15.packets.filter((p) => p.status === "HELD" || p.status === "REJECTED").map((p) => ({
    ref: p.id, title: `${p.source} · ${p.outcome}`,
    why: `${p.detail} Owner M15.`, view: "ingestion", trace: p.packet,
  })) : [];
  push("intake", "Intake held or refused", "violet", "funnel", "A candidate stopped in the intake chain. Resolving it means fixing evidence or resolution — never forcing the stage.", intake);

  // 7. Release & visibility — held assembly, stale reliance, withdrawals
  const m16 = window.AFB.m16;
  const rel = [];
  if (m16) {
    m16.artifacts.filter((a) => a.status.startsWith("HELD")).forEach((a) => rel.push({
      ref: a.id, title: `${a.title} · assembly held`,
      why: `${a.sourceUse.holds.join(" · ")}. No projection, no audience, no reliance. Owner M16.`, view: "release", trace: a.packet,
    }));
    m16.artifacts.filter((a) => a.staleness === "STALE_WARNING" || a.staleness === "STALE_DOWNGRADED").forEach((a) => rel.push({
      ref: a.id, title: `${a.title} · ${a.staleness.replace(/_/g, " ").toLowerCase()}`,
      why: `Content freshness ${a.freshness}. The mark travels with every render; refresh the observation or supersede the artifact. Owner M16.`, view: "release", trace: a.packet,
    }));
  }
  push("release", "Release & visibility", "green", "send", "Governed output that cannot be assembled, or that is still being relied on past its freshness threshold.", rel);

  // 8. Blocked — for awareness (no action, but must stay visible)
  const blocked = (arts || []).filter((a) => a.state === "blocked").map((a) => ({
    ref: a.ref, title: a.title, why: a.verdict ? a.verdict.basis : "Blocked under the current authority ceiling.", view: "arbiter", trace: window.AFB.packetFor(a.ref),
  }));
  push("blocked", "Blocked — for awareness", "red", "shield-x", "No action available; surfaced so nothing blocked is silent.", blocked);

  return sections;
};

/* ── Decision lineage — the full causal chain of a material decision, one place.
   Each stage names the governed object, its owning module, and where it lives so
   the operator can jump. Chains are assembled from the same objects the planes
   render; a stage with reached:false shows where a chain legitimately stops. ── */
window.AFB.lineages = [
  {
    id: "PRP-0418", title: "Competitor battlecard · Tier-2 pricing shift",
    outcome: "RELEASED_INTERNAL", tone: "green", head: "DR-0007",
    summary: "A clean end-to-end release: three primary-sourced claims, HMWH proceed with one preserved dissent, Arbiter ALLOW, recorded to the ledger. No external effect.",
    stages: [
      { m: "M04", kind: "Proposal", id: "PRP-0418", at: "2026-07-04 09:12", status: "SUBMITTED", tone: "cyan", view: "mission-control", goId: "PRP-0418", note: "Mission MSN-0407 scoped the battlecard release; contract authority narrowed to advisory." },
      { m: "M05", kind: "Evidence bundle", id: "EB-0204", at: "09:22", status: "3 SOURCES VERIFIED", tone: "cyan", view: "evidence", note: "Three primary public sources, freshness ≤ 2h; safe-content reference + digest only." },
      { m: "M07", kind: "HMWH session", id: "HRS-0418", at: "09:31 → 10:03", status: "PROCEED_TO_ARBITER", tone: "violet", view: "hmwh", goId: "HRS-0418", note: "Four typed critics; producer self-review denied and re-assigned; one dissent (VK-Urd) preserved for the Arbiter." },
      { m: "M08", kind: "Arbiter verdict", id: "AV-0418", at: "10:05", status: "ALLOW_INFORMATIONAL_RELEASE", tone: "green", view: "arbiter", goId: "PRP-0418", note: "V1 scope → evidence → HMWH → terminal all PASS. An ALLOW permits the next stage; it is not a release." },
      { m: "M09", kind: "Freeze / approval", id: "—", at: "—", status: "NOT REQUIRED", tone: "muted", view: null, reached: false, note: "Informational release carries no effect-bearing action, so no frozen artifact or signature is required." },
      { m: "M10", kind: "Decision Record", id: "DR-0007", at: "2026-06-04 11:42", status: "RELEASED_INTERNAL · ACTIVE", tone: "gold", view: "ledger", goId: "DR-0007", note: "Appended to the hash-chained ledger with four verified references. Head of the demo stream." },
      { m: "M11", kind: "Replay", id: "AR-LI-0007", at: "11:43", status: "REPLAYABLE · CONTROL_PASS", tone: "cyan", view: "replay", note: "Ledger chain + references verified; a no-side-effect replay re-derives the same route." },
    ],
  },
  {
    id: "PRP-0419", title: "Admit Acme Logistics to the Priority Outreach Queue",
    outcome: "HELD_FOR_EVIDENCE", tone: "amber", head: "INC-0044",
    summary: "A chain that legitimately stops: the funding claim is primary-sourced but the procurement signal rests on a single secondary source. HMWH and Arbiter both hold; a provenance incident quarantines the path.",
    stages: [
      { m: "M04", kind: "Proposal", id: "PRP-0419", at: "2026-07-06 09:58", status: "SUBMITTED", tone: "cyan", view: "mission-control", goId: "PRP-0419", note: "Promote Acme from Watch List to Priority Outreach on a funding round + procurement initiative." },
      { m: "M05", kind: "Evidence bundle", id: "EB-0298", at: "10:04", status: "1 VERIFIED · 1 UNVERIFIED", tone: "amber", view: "evidence", note: "Funding round primary-sourced; procurement signal inferred, no primary source." },
      { m: "M07", kind: "HMWH session", id: "HRS-0419", at: "10:12 → 15:40", status: "REQUIRE_MORE_EVIDENCE", tone: "amber", view: "hmwh", goId: "HRS-0419", note: "Evidence critic and general reviewer agree on the gap; risk veto RV-0042 routes REQUIRE_MORE_EVIDENCE." },
      { m: "M08", kind: "Arbiter verdict", id: "AV-0419", at: "15:42", status: "REQUIRE_MORE_EVIDENCE", tone: "amber", view: "arbiter", goId: "PRP-0419", note: "V1 scope PASS, M05 evidence FAIL → HOLD_FOR_EVIDENCE. HMWH not applicable once evidence fails." },
      { m: "M11", kind: "Incident", id: "INC-0044", at: "09:21", status: "CONTAINMENT_ACKNOWLEDGED", tone: "violet", view: "replay", note: "Provenance-or-IP incident: the procurement path is quarantined; M05 held the claim for evidence." },
      { m: "M10", kind: "Decision Record", id: "—", at: "—", status: "NOT YET RECORDED", tone: "muted", view: null, reached: false, note: "No material outcome to record while the claim is held. A record is appended only when the decision resolves." },
    ],
  },
  {
    id: "PRP-0421", title: "Auto-adjust ad budget on competitor launch",
    outcome: "BLOCKED", tone: "red", head: "INC-0042",
    summary: "A one-check chain: the request is an effect-bearing action outside the V1 safe set, so the Arbiter's first prohibition check fails and nothing downstream runs. A forbidden-capability incident is opened and resolved.",
    stages: [
      { m: "M04", kind: "Proposal", id: "PRP-0421", at: "2026-07-06 11:20", status: "SUBMITTED", tone: "cyan", view: "mission-control", goId: "PRP-0421", note: "Proposal to automatically shift campaign budget in response to a detected competitor launch." },
      { m: "M08", kind: "Arbiter verdict", id: "AV-0421", at: "11:25", status: "BLOCK", tone: "red", view: "arbiter", goId: "PRP-0421", note: "V1_SCOPE prohibition check FAILS — budget execution is outside the V1 safe set. Sequence stops; NO_PROGRESSION." },
      { m: "M11", kind: "Incident", id: "INC-0042", at: "11:25 → 11:52", status: "RESOLVED", tone: "green", view: "replay", note: "Forbidden-capability-attempt incident opened from the BLOCK; dispatch path pinned off; verified no dispatch occurred." },
      { m: "M10", kind: "Decision Record", id: "DR-block", at: "11:53", status: "POLICY_BLOCK_RECORDED", tone: "gold", view: "ledger", note: "The block itself is a material governance event and is recorded — a denial is still auditable." },
    ],
  },
];
window.AFB.lineageTone = { cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)", green: "var(--abi-success-green)", violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)", muted: "var(--abi-text-muted)" };

/* ── Command-palette index — built fresh each open from the live globals so it
   never drifts. Every entry resolves to a governed object or a view via goTo. ─ */
window.AFB.buildSearchIndex = function () {
  const idx = [];
  // Views (search doubles as navigation)
  (window.AFB.navGroups || []).forEach((g) => g.items.forEach((n) =>
    idx.push({ kind: "View", group: g.group, id: n.id, label: n.label, sub: `${g.group} · ${n.module}`, view: n.id })));
  // Proposals / artifacts
  (window.AFB.artifacts || []).forEach((a) =>
    idx.push({ kind: "Proposal", id: a.ref, label: a.title, sub: `${a.ref} · ${a.type} · ${a.state}`, view: "mission-control", goId: a.id, trace: window.AFB.packetFor(a.ref) }));
  // Decision records
  (window.AFB.ledgerRecords || []).forEach((r) =>
    idx.push({ kind: "Decision record", id: r.id, label: r.summary, sub: `${r.id} · ${(window.AFB.recordTypeLabel && window.AFB.recordTypeLabel[r.type]) || r.type}`, view: "ledger", goId: r.id, trace: window.AFB.packetFor(r.id) || window.AFB.packetFor((r.refs && r.refs.find((x) => x.t === "proposal_envelope") || {}).id) }));
  // HMWH sessions
  (window.AFB.hmwhSessions || []).forEach((s) =>
    idx.push({ kind: "Review session", id: s.id, label: s.title, sub: `${s.id} · ${(window.AFB.hmwhPurposeLabel && window.AFB.hmwhPurposeLabel[s.purpose]) || s.purpose}`, view: "hmwh", goId: s.id, trace: window.AFB.packetFor(s.id) || window.AFB.packetFor(s.proposal) }));
  // Valkyries
  (window.AFB.registry || []).forEach((v) =>
    idx.push({ kind: "Valkyrie", id: v.id, label: v.display, sub: `${v.id} · ${(window.AFB.roleLabel && window.AFB.roleLabel[v.roleClass]) || v.roleClass}`, view: "valkyrie", goId: v.id }));
  // Packet traces
  (window.AFB.packets || []).forEach((p) =>
    idx.push({ kind: "Packet", id: p.id, label: p.title, sub: `${p.id} · ${p.nodes.length} nodes → ${p.outcome}`, view: "trace", goId: p.id }));
  // Monitored sources (GSMC)
  ((window.AFB.gsmc || {}).sources || []).forEach((s) =>
    idx.push({ kind: "Monitored source", id: s.id, label: s.ref, sub: `${s.id} · ${s.entity} · ${s.health}`, view: "sources", goId: s.id }));
  // Finding review routes (GSMC)
  ((window.AFB.gsmc || {}).routes || []).forEach((r) =>
    idx.push({ kind: "Finding route", id: r.id, label: r.evidencePreview, sub: `${r.id} · ${r.sourceRef} · ${r.status}`, view: "sources" }));
  // Ingestion candidates (M15)
  ((window.AFB.m15 || {}).packets || []).forEach((p) =>
    idx.push({ kind: "Ingestion candidate", id: p.id, label: p.detail.split(".")[0], sub: `${p.id} · ${p.outcome}`, view: "ingestion" }));
  // Release artifacts (M16)
  ((window.AFB.m16 || {}).artifacts || []).forEach((a) =>
    idx.push({ kind: "Release artifact", id: a.id, label: a.title, sub: `${a.id} · ${a.status} · ${a.audience}`, view: "release", trace: a.packet }));
  // Incidents
  (window.AFB.incidents || []).forEach((i) =>
    idx.push({ kind: "Incident", id: i.id, label: i.summary, sub: `${i.id} · ${i.status.replace(/_/g, " ")}`, view: "replay" }));
  return idx;
};
