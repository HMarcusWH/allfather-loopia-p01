/* Allfather BI — SCALE MODE.

   The seed fixtures describe one believable operating day: 5 sources, 6 findings,
   8 candidates, 5 artifacts. That is the right size to READ, and the wrong size
   to PROVE the interface holds when a tenant has been monitoring for a year.

   Scale mode expands each collection to launch-plus-a-year volume by generating
   governed objects that obey the same invariants as the seed rows — same enums,
   same owners, same authority chains, same tone maps. Nothing new is invented:
   every generated row is a recombination of vocabulary already grounded in the
   repository.

   It is a DEMONSTRATION TOGGLE, not a second product state. It changes the
   number of rows, never what a row is allowed to claim.

     window.AFB.scale.on()     expand every collection
     window.AFB.scale.off()    restore the readable seed
     window.AFB.scale.active   current state (persisted)

   Reachable from the Console State surface, where the read-model row counts
   live — the one place an operator is already thinking about volume. */
window.AFB = window.AFB || {};

(function () {
  const A = window.AFB;
  const KEY = "afb.scale.v1";

  // Deterministic PRNG — the same seed always yields the same fixture set, so a
  // screenshot, a page number and a bug report stay reproducible.
  function rng(seed) {
    let s = seed >>> 0;
    return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
  }
  const pick = (r, arr) => arr[Math.floor(r() * arr.length) % arr.length];
  const pad = (n, w) => String(n).padStart(w, "0");

  const ENTITIES = [
    { name: "Boreal Systems AB", seg: "Direct competitor" }, { name: "Fjordr", seg: "Direct competitor" },
    { name: "Kvant Industri", seg: "Adjacent" }, { name: "Acme Logistics", seg: "Prospect" },
    { name: "Nordlys Data AS", seg: "Direct competitor" }, { name: "Saltö Marine", seg: "Adjacent" },
    { name: "Vinter Verkstad", seg: "Prospect" }, { name: "Granit Bygg AB", seg: "Prospect" },
    { name: "Öresund Frakt", seg: "Adjacent" }, { name: "Talvi Software Oy", seg: "Direct competitor" },
    { name: "Bергen Analytics", seg: "Adjacent" }, { name: "Lumen Nordic", seg: "Direct competitor" },
  ];
  const PAGES = ["pricing", "launch", "news", "changelog", "careers", "customers", "security", "status", "docs/api", "legal/terms"];
  const KINDS = ["Pricing page", "Launch page", "Company updates", "Changelog", "Licensed feed", "Portal", "Press feed"];

  function slug(name) {
    return name.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, "").slice(0, 10);
  }

  /* ── sources ─────────────────────────────────────────────────────────────── */
  function makeSources(n) {
    const r = rng(9001);
    const healths = ["MONITORING", "MONITORING", "MONITORING", "MONITORING", "REVIEW_DUE", "DEGRADED", "PAUSED"];
    const rights = ["APPROVED", "APPROVED", "APPROVED", "APPROVED", "APPROVED", "PENDING_REVIEW", "TERMS_RECHECK_OVERDUE"];
    const trigs = ["CONTENT_HASH_CHANGED", "FIELD_THRESHOLD", "KEYWORD_MATCH", "ANY_NEW_ITEM", "SIGNAL_CLASS_MATCH"];
    const out = [];
    for (let i = 0; i < n; i++) {
      const e = ENTITIES[i % ENTITIES.length];
      const health = pick(r, healths), right = pick(r, rights);
      const id = `SRC-${pad(200 + i, 4)}`;
      const ver = 1 + Math.floor(r() * 3);
      const hh = pad(Math.floor(r() * 24), 2), mm = pad(Math.floor(r() * 60), 2);
      out.push({
        id, ref: `${slug(e.name)}.se/${pick(r, PAGES)}`, entity: e.name, kind: pick(r, KINDS),
        health, tone: A.gsmcHealthTone[health],
        configId: `SMC-${pad(200 + i, 4)}`, versionId: `SMCV-${pad(200 + i, 4)}-${ver}`, version: ver,
        configStatus: "ACTIVE", versionStatus: "ADMITTED",
        autonomy: { mode: r() > 0.35 ? "ASSISTED" : "MANUAL", ownerApprovalRequired: true },
        fetchCadenceMs: pick(r, [3600000, 10800000, 21600000, 86400000]),
        reviewCadenceMs: pick(r, [86400000, 172800000, 604800000]),
        ownerReviewRequired: true, manualFetchAllowed: right === "APPROVED",
        lastObserved: health === "PAUSED" ? "—" : `2026-08-${pad(20 + Math.floor(r() * 8), 2)} ${hh}:${mm}`,
        nextDue: health === "PAUSED" || health === "DEGRADED" ? "held" : `${hh}:${mm}`,
        freshness: health === "DEGRADED" ? "STALE" : health === "REVIEW_DUE" ? "WARN" : health === "PAUSED" ? "—" : "FRESH",
        rights: right,
        rightsNote: right === "APPROVED" ? "Public web page · terms clear"
          : right === "PENDING_REVIEW" ? "Access terms unknown · source access policy compiles BLOCKED until a terms review is recorded"
          : "Licensed feed · terms recheck lapsed · new admission holds until the recheck is recorded",
        triggers: [{ kind: pick(r, trigs), detail: "generated fixture condition", fires: Math.floor(r() * 6) }],
        openRoutes: health === "MONITORING" ? 0 : 1, dueRoutes: health === "REVIEW_DUE" ? 1 : 0,
        uncertainty: "Generated scale fixture. Carries the same invariants as the seed rows and no additional claim.",
        authority: { proposal: `PRP-${pad(500 + i, 4)}`, verdict: `AV-${pad(500 + i, 4)}`, record: `DR-${pad(100 + i, 4)}` },
        observations: [{ id: `OBR-${pad(3000 + i, 4)}`, at: `2026-08-${pad(20 + Math.floor(r() * 8), 2)} ${hh}:${mm}`,
          outcome: health === "DEGRADED" ? "FETCH_FAILED" : r() > 0.6 ? "CAPTURED" : "NO_CHANGE",
          matched: Math.floor(r() * 3), selected: Math.floor(r() * 2), note: "Generated scale fixture observation." }],
        history: [{ version: ver, at: "2026-08-14", status: "ADMITTED", change: "Generated scale fixture — initial admitted configuration.", record: `DR-${pad(100 + i, 4)}` }],
        _generated: true,
      });
    }
    return out;
  }

  /* ── findings ────────────────────────────────────────────────────────────── */
  function makeFindings(n, sources) {
    const r = rng(4242);
    const statuses = ["OPEN", "OPEN", "ACKNOWLEDGED", "RESOLVED", "RESOLVED", "RESOLVED", "DISMISSED"];
    const sevs = ["HIGH", "MEDIUM", "MEDIUM", "MEDIUM", "LOW", "LOW"];
    const previews = [
      "Tier-2 list price changed · new add-on block present",
      "Positioning claim replaced on the hero section",
      "New filing item present · normalization could not map one field",
      "Funding announcement detected without a primary source",
      "Changelog entry references a capability not previously listed",
      "Security page added a certification claim",
      "Careers page shows a new regional office",
      "Customer logo set changed · two additions",
    ];
    const trigs = ["CONTENT_HASH_CHANGED", "FIELD_THRESHOLD", "KEYWORD_MATCH", "ANY_NEW_ITEM", "SIGNAL_CLASS_MATCH"];
    const out = [];
    for (let i = 0; i < n; i++) {
      const s = sources[Math.floor(r() * sources.length)];
      const status = pick(r, statuses), sev = pick(r, sevs);
      const day = pad(1 + Math.floor(r() * 27), 2), hh = pad(Math.floor(r() * 24), 2), mm = pad(Math.floor(r() * 60), 2);
      const decided = status === "ACKNOWLEDGED" || status === "RESOLVED" || status === "DISMISSED";
      out.push({
        id: `LFR-${pad(1000 + i, 4)}`, sourceId: s.id, sourceRef: s.ref, entity: s.entity,
        status, severity: sev,
        promotedAt: `2026-08-${day} ${hh}:${mm}`, reviewDueAt: `2026-08-${day} ${pad((Number(hh) + 4) % 24, 2)}:${mm}`,
        matchedCount: 1 + Math.floor(r() * 3), selectedCount: Math.floor(r() * 2),
        triggerKinds: [pick(r, trigs)],
        evidencePreview: pick(r, previews),
        lastDecisionKind: decided ? (status === "ACKNOWLEDGED" ? "ACKNOWLEDGE" : status === "RESOLVED" ? "RESOLVE" : "DISMISS") : null,
        lastDecidedAt: decided ? `2026-08-${day} ${pad((Number(hh) + 1) % 24, 2)}:${mm}` : null,
        packet: null, _generated: true,
      });
    }
    return out;
  }

  /* ── candidates ──────────────────────────────────────────────────────────── */
  function makeCandidates(n, sources) {
    const r = rng(7777);
    const types = ["COMPANY_SIGNAL", "ENTITY_FACT", "RELATIONSHIP", "MARKET_CONTEXT"];
    const term = [
      { status: "ADMITTED_TO_CONSUMER", stage: "admission", outcome: "SUBMITTED", tone: "green", consumer: "P01_COMPANY_SIGNAL" },
      { status: "ADMITTED_TO_CONSUMER", stage: "admission", outcome: "SUBMITTED", tone: "green", consumer: "P02_TWIN_STATE" },
      { status: "HELD", stage: "admission", outcome: "HELD · CANDIDATE_EVIDENCE_UNUSABLE", tone: "amber", consumer: "P01_COMPANY_SIGNAL" },
      { status: "HELD", stage: "resolution", outcome: "HELD · ENTITY_NOT_RESOLVED", tone: "amber", consumer: null },
      { status: "HELD", stage: "admission", outcome: "HELD · NO_ADMITTED_CONSUMER", tone: "muted", consumer: null },
      { status: "REJECTED", stage: "normalize", outcome: "REFUSED · NORMALIZER_UNMAPPED", tone: "red", consumer: null },
      { status: "REJECTED", stage: "policy", outcome: "REFUSED · SOURCE_NO_LONGER_APPROVED", tone: "red", consumer: null },
    ];
    const out = [];
    for (let i = 0; i < n; i++) {
      const s = sources[Math.floor(r() * sources.length)];
      const t = pick(r, term);
      const fact = t.status === "ADMITTED_TO_CONSUMER" || r() > 0.4;
      out.push({
        id: `ICD-${pad(5000 + i, 4)}`, type: pick(r, types), ...t,
        source: s.ref, connector: r() > 0.15 ? "PUBLIC_WEB_PAGE" : "SYNTHETIC_TEST_FIXTURE",
        authority: "PUBLIC_SOURCE",
        extraction: pick(r, ["DETERMINISTIC_DIFF", "DETERMINISTIC_PARSER", "STRUCTURED_FIELD_MAP", "OPERATOR_DECLARED"]),
        interpretation: fact ? "FACT_OBSERVED" : "HYPOTHESIS",
        confidence: fact ? pick(r, ["HIGH", "MEDIUM"]) : pick(r, ["LOW", "NOT_QUANTIFIED"]),
        detail: "Generated scale fixture. Terminal outcome and stage are drawn from the closed union; no new claim is made.",
        resolved: t.status === "ADMITTED_TO_CONSUMER" ? `${s.entity} · COMPETITOR` : "—",
        packet: null, _generated: true,
      });
    }
    return out;
  }

  /* ── artifacts ───────────────────────────────────────────────────────────── */
  function makeArtifacts(n) {
    const r = rng(3131);
    const shapes = [
      { status: "RELEASED_INTERNAL", tone: "green", render: "VISIBLE", reliance: "CURRENT", aud: "TENANT_INTERNAL", stale: "FRESH" },
      { status: "RELEASED_INTERNAL", tone: "green", render: "VISIBLE", reliance: "CURRENT", aud: "TENANT_INTERNAL", stale: "STALE_WARNING" },
      { status: "HELD_SOURCE_USE", tone: "amber", render: "REFUSED", reliance: "NONE", aud: "OPERATOR_ONLY", stale: null },
      { status: "HELD_FRESHNESS", tone: "amber", render: "WITHHELD_STALE", reliance: "NONE", aud: "OPERATOR_ONLY", stale: "STALE_DOWNGRADED" },
      { status: "SUPERSEDED", tone: "muted", render: "WITHHELD_STALE", reliance: "SUPERSEDED", aud: "TENANT_INTERNAL", stale: "STALE_DOWNGRADED" },
      { status: "ROLLED_BACK", tone: "red", render: "WITHDRAWN", reliance: "WITHDRAWN", aud: "TENANT_INTERNAL", stale: null },
    ];
    const kinds = ["BATTLECARD_BRIEFING", "COMPANY_SIGNAL_BRIEFING", "ENTITY_TWIN_SNAPSHOT_VIEW"];
    const titles = ["pricing move — response play", "positioning shift", "entity context snapshot", "capability claim change", "regional expansion signal"];
    const out = [];
    for (let i = 0; i < n; i++) {
      const e = ENTITIES[i % ENTITIES.length], sh = pick(r, shapes);
      const held = sh.status.startsWith("HELD");
      const day = pad(1 + Math.floor(r() * 27), 2);
      out.push({
        id: `RA-${pad(1000 + i, 4)}`, kind: pick(r, kinds), status: sh.status, tone: sh.tone,
        title: `${e.name} — ${pick(r, titles)}`, entity: e.name,
        decision: held ? "—" : `DR-${pad(200 + i, 4)}`,
        assembled: held ? "—" : `2026-08-${day} ${pad(Math.floor(r() * 24), 2)}:${pad(Math.floor(r() * 60), 2)}`,
        freshness: `2026-08-${day} ${pad(Math.floor(r() * 24), 2)}:00`,
        staleness: sh.stale, claimCeiling: held ? "INTERNAL_DRAFT" : "INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS",
        audience: sh.aud, lenses: sh.render === "VISIBLE" ? ["SALES", "MARKETING"] : [],
        renderState: sh.render, reliance: sh.reliance, displayRef: sh.render === "VISIBLE" ? `DSP-${pad(2000 + i, 4)}` : null,
        uncertainty: "Generated scale fixture. The claim ceiling and audience are drawn from the closed unions.",
        sourceUse: { sources: 1 + Math.floor(r() * 4), primary: 1 + Math.floor(r() * 2), excerptRights: Math.floor(r() * 2),
          license: r() > 0.2 ? "PUBLIC_WEB" : "LICENSED", holds: held ? ["A CLAIM element carries no PRIMARY evidence link"] : [] },
        elements: [
          { cls: "CLAIM", interpretation: "FACT_OBSERVED", confidence: "HIGH", text: "Generated observed change on the monitored page." },
          { cls: "UNCERTAINTY_BLOCK", text: "Non-redactable." },
          { cls: "FRESHNESS_BLOCK", text: "Non-redactable." },
        ],
        packet: null, _generated: true,
      });
    }
    return out;
  }

  /* ── ledger records ──────────────────────────────────────────────────────── */
  function makeLedger(n, seedHead) {
    const r = rng(5150);
    const kinds = [
      { type: "SOURCE_CONFIG_APPROVED", out: "RELEASED_INTERNAL", rel: "ACTIVE_AS_RECORDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "SOURCE_CONFIG_SUPERSEDED", out: "CORRECTED_OR_INVALIDATED", rel: "SUPERSEDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "INTERNAL_INFORMATIONAL_RELEASE", out: "RELEASED_INTERNAL", rel: "ACTIVE_AS_RECORDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "INTERNAL_INFORMATIONAL_RELEASE", out: "RELEASED_INTERNAL", rel: "SUPERSEDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "DECISION_CORRECTION_OR_INVALIDATION", out: "CORRECTED_OR_INVALIDATED", rel: "ACTIVE_AS_RECORDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "PROPOSAL_HELD", out: "HELD", rel: "ACTIVE_AS_RECORDED", mat: "GOVERNANCE_MATERIAL" },
      { type: "TOOL_OPERATION_DENIED_NON_DISPATCH", out: "DENIED_NON_DISPATCH", rel: "ACTIVE_AS_RECORDED", mat: "SECURITY_MATERIAL" },
      { type: "AUDIT_FINDING_LINKED", out: "REPLAY_RECORDED", rel: "ACTIVE_AS_RECORDED", mat: "AUDIT_MATERIAL" },
    ];
    const chainOf = (i) => `sha256:${pad(100000 + i, 6)}…gen`;
    const out = [];
    // Build oldest → newest so each record can chain onto the previous one, then
    // reverse for the newest-first array the ledger renders.
    for (let i = 0; i < n; i++) {
      const k = pick(r, kinds), e = ENTITIES[i % ENTITIES.length];
      const refs = [
        { m: "M08", t: "arbiter_verdict", id: `AV-${pad(1000 + i, 4)}`, role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
        { m: "M04", t: "proposal_envelope", id: `PRP-${pad(1000 + i, 4)}`, role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      ];
      out.push({
        seq: seedHead.seq + 1 + i, id: `DR-${pad(1000 + i, 4)}`,
        at: `2026-08-${pad(1 + Math.floor(r() * 27), 2)} ${pad(Math.floor(r() * 24), 2)}:${pad(Math.floor(r() * 60), 2)}`,
        type: k.type, materiality: k.mat, outcome: k.out, reliance: k.rel, env: "DEMO",
        summary: `Generated scale fixture — ${(A.recordTypeLabel[k.type] || k.type).toLowerCase()} recorded for ${e.name}.`,
        reasons: ["M10_RECORD_APPENDED"], severity: k.mat === "SECURITY_MATERIAL" ? "S2" : null, incident: false,
        payload: `sha256:${pad(i, 6)}…payld`,
        prev: i === 0 ? seedHead.chain : chainOf(i - 1),
        chain: chainOf(i),
        refs, _generated: true,
      });
    }
    return out.reverse();
  }

  /* ── P01 change feed ─────  /* ── P01 change feed ─────────────────────────────────────────────────────── */
  function makeChanges(n) {
    const r = rng(8080);
    const classes = ["PRICING_CHANGE", "FUNDING_EVENT", "POSITIONING_SHIFT", "REGULATORY_CHANGE"];
    const sigs = ["MAJOR", "STRUCTURAL", "STRUCTURAL", "MINOR", "MINOR"];
    const states = ["recorded", "released", "review", "held"];
    const out = [];
    for (let i = 0; i < n; i++) {
      const e = ENTITIES[i % ENTITIES.length], cls = pick(r, classes), sig = pick(r, sigs);
      out.push({
        id: `DIFF-${pad(4000 + i, 4)}`, entity: e.name, source: `${slug(e.name)}.se/${pick(r, PAGES)}`,
        captured: `${1 + Math.floor(r() * 9)}${pick(r, ["h", "h", "d"])}`, significance: sig, signalClass: cls,
        before: [{ t: "Prior captured span — " }, { t: "previous value", hi: true }],
        after: [{ t: "Current captured span — " }, { t: "changed value", hi: true }],
        observed: "Generated scale fixture. A directly observed span change on the monitored page.",
        interpretation: "Generated scale fixture. A candidate reading of that change, carried separately from the observation.",
        confidence: pick(r, ["HIGH", "MEDIUM", "LOW", "NOT_QUANTIFIED"]),
        ceiling: "Advisory. Generated fixture carries no additional claim.",
        signal: { ref: `PRP-${pad(2000 + i, 4)}`, state: pick(r, states), verdict: r() > 0.4 ? "ALLOW_INFORMATIONAL_RELEASE" : null,
          evidence: 1 + Math.floor(r() * 4), route: pick(r, ["CMO", "CMO · Sales", "CFO", "Product"]), battlecard: null },
        _generated: true,
      });
    }
    return out;
  }

  const seed = {};
  function snapshot() {
    if (seed.taken) return;
    seed.sources = A.gsmc.sources; seed.routes = A.gsmc.routes;
    seed.packets = A.m15.packets; seed.artifacts = A.m16.artifacts;
    seed.ledger = A.ledgerRecords; seed.changes = A.p01.changes;
    seed.taken = true;
  }

  const PROFILE = { sources: 240, findings: 1840, candidates: 2600, artifacts: 620, ledger: 1450, changes: 980 };

  A.scale = {
    active: false,
    profile: PROFILE,
    note: "Scale mode expands each collection to launch-plus-a-year volume using the same enums, owners and authority chains as the seed rows. It changes how many rows exist — never what a row may claim.",

    on() {
      snapshot();
      const sources = makeSources(PROFILE.sources);
      const findings = makeFindings(PROFILE.findings, sources);
      A.gsmc.sources = seed.sources.concat(sources);
      A.gsmc.routes = seed.routes.concat(findings);
      A.m15.packets = seed.packets.concat(makeCandidates(PROFILE.candidates, sources));
      A.m16.artifacts = seed.artifacts.concat(makeArtifacts(PROFILE.artifacts));
      // Generated records extend ABOVE the seed head so sequence stays contiguous and
      // the linkage walk in window.AFB.ledgerHead() passes on real chaining.
      A.ledgerRecords = makeLedger(PROFILE.ledger, seed.ledger[0]).concat(seed.ledger);
      A.p01.changes = seed.changes.concat(makeChanges(PROFILE.changes));

      // gsmc.snapshot is no longer recomputed here: window.AFB.counts() derives
      // every snapshot cell from the routes array, so there is one derivation.
      // m16.summary is no longer recomputed here — counts() derives every release
      // figure from the artifacts array, so exactly one derivation exists.
      this.active = true;
      if (A.rerender) A.rerender();
    },

    off() {
      if (!seed.taken) { this.active = false; return; }
      A.gsmc.sources = seed.sources; A.gsmc.routes = seed.routes;
      A.m15.packets = seed.packets; A.m16.artifacts = seed.artifacts;
      A.ledgerRecords = seed.ledger; A.p01.changes = seed.changes;
      this.active = false;
      if (A.rerender) A.rerender();
    },

    toggle() { this.active ? this.off() : this.on(); },
    counts() {
      return { sources: A.gsmc.sources.length, findings: A.gsmc.routes.length,
        candidates: A.m15.packets.length, artifacts: A.m16.artifacts.length,
        ledger: A.ledgerRecords.length, changes: A.p01.changes.length };
    },
  };

  // Deliberately NOT persisted. Scale mode is a demonstration toggle, and the
  // console's default state must be the curated, readable seed — never a few
  // thousand generated rows a previous session happened to leave switched on.
  // Clear any flag written by an earlier build that did persist it.
  try { localStorage.removeItem(KEY); } catch (e) {}
})();
