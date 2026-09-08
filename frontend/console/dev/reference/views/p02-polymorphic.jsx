/* Allfather BI — Polymorphic Database (PDB) P0 control-plane OVERLAY view.
   Rendered as a distinct mode on the P02 screen; the admitted P02 graph stays
   the untouched base layer. Everything here is CANDIDATE / governance / control-
   plane — dashed candidate treatment, plane-tagged, gate-chipped. No action verb
   implies runtime: only View / Inspect / Preview / Show. Exported to window. */

const PDB_TONE = {
  cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)", green: "var(--abi-success-green)",
  violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)",
  neutral: "var(--abi-text-secondary)",
};
const PDB_PLANE_TONE = { "CORE-BOUNDARY": "gold", SOURCES: "cyan", OPS: "amber" };
const PDB_KIND_ICON = { OWN_COMPANY: "building-2", SUPPLIER: "truck", CUSTOMER: "handshake", COMPETITOR: "swords" };
const PDB_STATUS = {
  ADMITTED_CANDIDATE: { tone: "cyan",  label: "Candidate · fixture-admitted" },
  HELD:               { tone: "amber", label: "Held for evidence" },
};

/* ── Classification + gate-summary bar ────────────────────────────────── */
function PDBClassBar() {
  const m = window.AFB.p02Polymorphic.meta;
  return (
    <div className="pdb-classbar">
      <div className="pdb-cb-l">
        <span className="pdb-cb-tag"><Ic name="database" size={13} />Polymorphic Database · P0 control-plane</span>
        <span className="pdb-cb-class">{m.classification}</span>
        <span className="pdb-cb-schema mono">{m.schema}</span>
      </div>
      <div className="pdb-cb-r">
        <div className="pdb-cb-cell"><span className="abi-label">Fixture cage</span><span className="pdb-cb-v">{m.fixtureTenant}</span></div>
        <div className="pdb-cb-cell"><span className="abi-label">Local proof</span><span className="pdb-cb-v mono">{m.baseline}</span></div>
      </div>
    </div>
  );
}

/* ── The "this is not runtime" pin strip ──────────────────────────────── */
function PDBPinStrip() {
  const m = window.AFB.p02Polymorphic.meta;
  return (
    <div className="pdb-pins">
      <div className="pdb-frozen"><Ic name="snowflake" size={12} /><span>{m.frozenWarning}</span></div>
      <div className="pdb-pins-hd"><Ic name="shield" size={13} /><span><b>Inert by storage pin.</b> Built, owner-gated as control-plane <b>persistence only</b> — not runtime, not customer-visible, not docs-only. Activation needs a future owner-gated schema change, never data.</span></div>
      <div className="pdb-pins-row">
        {m.runtimePins.map((p) => <span key={p} className="pdb-pin mono">{p}</span>)}
      </div>
      <div className="pdb-gate-note">{m.ownerGate}</div>
    </div>
  );
}

/* ── Three-plane legend ───────────────────────────────────────────────── */
function PDBPlaneLegend() {
  return (
    <div className="pdb-legend">
      {window.AFB.p02Polymorphic.planes.map((p) => (
        <div key={p.key} className="pdb-plane" style={{ "--pt": PDB_TONE[p.tone] }}>
          <div className="pdb-plane-hd"><Ic name={p.icon} size={13} /><span className="pdb-plane-key">{p.key}</span></div>
          <div className="pdb-plane-desc">{p.desc}</div>
          <div className="pdb-plane-tables mono">{p.tables}</div>
        </div>
      ))}
    </div>
  );
}

/* ── Overlay toggle bar ───────────────────────────────────────────────── */
function PDBToggleBar({ show, onToggle }) {
  const opts = [
    { id: "candidates", label: "Candidates", icon: "circle-dashed" },
    { id: "evidence",   label: "Evidence",   icon: "link" },
    { id: "governance", label: "Governance", icon: "git-branch" },
    { id: "simulation", label: "Simulation", icon: "flask-conical" },
    { id: "q6",         label: "Q6 readiness", icon: "waypoints" },
    { id: "blockers",   label: "Blockers",   icon: "shield-x" },
  ];
  return (
    <div className="pdb-toggles">
      <span className="pdb-toggles-lbl"><Ic name="layers" size={13} />Overlays</span>
      {opts.map((o) => (
        <button key={o.id} className={`pdb-toggle ${show[o.id] ? "on" : ""}`} onClick={() => onToggle(o.id)}>
          <Ic name={o.icon} size={12} />{o.label}
        </button>
      ))}
    </div>
  );
}

/* ── Candidate graph (Sources plane) — dashed, never admitted ─────────── */
function PDBCandidateGraph({ sel, onSel }) {
  const d = window.AFB.p02Polymorphic;
  const byId = {}; d.candidateNodes.forEach((n) => { byId[n.id] = n; });
  return (
    <div className="pdb-graph-wrap">
      <div className="ov-phead p01-feed-head">
        <span className="ov-ptitle"><Ic name="circle-dashed" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Entity / relation anchor candidates</span>
        <span className="ov-pmeta"><span className="pdb-srcbadge">SOURCES · G23</span> not admitted · not P02 twins</span>
      </div>
      <div className="pdb-graph">
        <svg className="pdb-graph-svg" viewBox="0 0 640 380" preserveAspectRatio="none">
          {d.candidateEdges.map((e, i) => {
            const a = byId[e.from], b = byId[e.to];
            return <line key={i} x1={a.x} y1={a.y} x2={b.x} y2={b.y} className={`pdb-edge ${e.status === "HELD" ? "held" : ""}`} />;
          })}
        </svg>
        {d.candidateEdges.map((e, i) => {
          const a = byId[e.from], b = byId[e.to];
          const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
          return <span key={i} className={`pdb-edge-lbl ${e.status === "HELD" ? "held" : ""}`} style={{ left: (mx / 640 * 100) + "%", top: (my / 380 * 100) + "%" }}>{e.type}{e.status === "HELD" ? " · held" : ""}</span>;
        })}
        {d.candidateNodes.map((n) => {
          const s = PDB_STATUS[n.status];
          return (
            <button key={n.id} className={`pdb-node ${n.status === "HELD" ? "held" : ""} ${sel === n.id ? "sel" : ""} ${n.kind === "OWN_COMPANY" ? "own" : ""}`}
              style={{ left: (n.x / 640 * 100) + "%", top: (n.y / 380 * 100) + "%", "--nt": PDB_TONE[s.tone] }} onClick={() => onSel(n.id)}>
              <span className="pdb-node-ic"><Ic name={PDB_KIND_ICON[n.kind] || "circle"} size={n.kind === "OWN_COMPANY" ? 15 : 12} /></span>
              <span className="pdb-node-tx">
                <span className="pdb-node-name">{n.name}</span>
                <span className="pdb-node-kind">{n.kind.replace(/_/g, " ")} · {n.anchorKind.replace(/_/g, " ")}</span>
              </span>
              <span className={`pdb-node-badge tone-${s.tone}`}>{n.status === "HELD" ? "HELD" : "CAND"}</span>
            </button>
          );
        })}
      </div>
      <div className="pdb-graph-foot"><Ic name="info" size={12} />Dashed = candidate. An anchor is admitted only through M04→M07→M08→M10; admission is linkage, not effect — no candidate here touches a live P02 twin or relationship edge.</div>
    </div>
  );
}

/* ── Evidence drawer (Sources plane) ──────────────────────────────────── */
function PDBEvidenceDrawer({ sel }) {
  const d = window.AFB.p02Polymorphic;
  const node = d.candidateNodes.find((n) => n.id === sel);
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="link" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Evidence &amp; claims</span>
        <span className="ov-pmeta"><span className="pdb-srcbadge">SOURCES</span> → M05 refs</span>
      </div>
      <div className="pdb-drawer-body">
        <div className="pdb-sel-note">{node.note}</div>
        <div className="pdb-sub">Source fixtures · SYNTHETIC_TEST_FIXTURE lane</div>
        <div className="pdb-src-list">
          {d.sources.map((s) => (
            <div key={s.id} className="pdb-src">
              <div className="pdb-src-top"><span className="pdb-src-id mono">{s.id}</span><span className="pdb-src-side">{s.side}</span></div>
              <div className="pdb-src-label">{s.label}</div>
              <div className="pdb-src-meta"><span className="pdb-tag-auth">{s.authority}</span><span className="pdb-tag-lic mono">{s.license}</span><span className="pdb-src-fresh">fresh · {s.fresh}</span></div>
            </div>
          ))}
        </div>
        <div className="pdb-sub">Candidate claims</div>
        <div className="pdb-claims">
          {d.claims.map((c) => (
            <div key={c.id} className={`pdb-claim cls-${c.cls.toLowerCase().replace(/_/g, "-")}`}>
              <div className="pdb-claim-top"><span className="pdb-claim-id mono">{c.id}</span><span className={`pdb-claim-cls cls-${c.cls.toLowerCase()}`}>{c.cls.replace(/_/g, " ")}</span></div>
              <div className="pdb-claim-txt">{c.txt}</div>
              <div className="pdb-claim-out"><Ic name="arrow-right" size={10} />{c.outcome} <span className="pdb-claim-src mono">{c.src}</span></div>
            </div>
          ))}
        </div>
        <div className="pdb-sub">Operating-trait candidates · {d.traitRun.dict}</div>
        <div className="pdb-traits">
          {d.traitAnnotations.map((t) => (
            <div key={t.key} className={`pdb-trait st-${t.status.toLowerCase()}`}>
              <span className="pdb-trait-k mono">{t.key.replace("operating_trait.", "")}</span>
              <span className="pdb-trait-v">{t.value}</span>
              <span className="pdb-trait-st">{t.conf != null ? t.conf.toFixed(2) : t.status}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ── Decision trail + config (Ops + Core-boundary) ────────────────────── */
function PDBGovernanceDrawer() {
  const d = window.AFB.p02Polymorphic;
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="git-branch" size={14} style={{ color: "var(--abi-ops-amber)" }} />Decision trail &amp; config</span>
        <span className="ov-pmeta"><span className="pdb-opsbadge">OPS</span> links → M10</span>
      </div>
      <div className="pdb-drawer-body">
        <div className="pdb-sub">Decision links · linkage, not self-authorization</div>
        <div className="pdb-dl-list">
          {d.decisionLinks.map((l) => {
            const vp = verdictPres(l.verdict);
            return (
              <div key={l.id} className="pdb-dl">
                <div className="pdb-dl-top"><span className="pdb-dl-subj">{l.subject}</span><span className={`p01-verdict tone-${VTONE_BADGE[vp.tone]}`}><Ic name={vp.icon} size={11} />{vp.short}</span></div>
                <div className="pdb-dl-route mono">{l.route}</div>
                <div className="pdb-dl-rec">{l.record}</div>
              </div>
            );
          })}
        </div>
        <div className="pdb-sub">Adaptive-config proposals · typed families (no blob, no EAV)</div>
        <div className="pdb-cfg-list">
          {d.configProposals.map((c) => (
            <div key={c.family} className={`pdb-cfg st-${c.status.toLowerCase()}`}>
              <div className="pdb-cfg-top"><span className="pdb-cfg-fam mono">{c.family}</span><span className="pdb-cfg-tier">{c.tier}</span><span className={`pdb-cfg-st st-${c.status.toLowerCase()}`}>{c.status}</span></div>
              <div className="pdb-cfg-note">{c.note}</div>
            </div>
          ))}
        </div>
        <div className="pdb-sub">Resolver dry-run · {d.dryRun.mode}</div>
        <div className="pdb-dryrun">
          <div className="pdb-dr-meta"><span className="mono">{d.dryRun.family}</span><span className="pdb-dr-hash mono">{d.dryRun.effectiveHash}</span></div>
          <ol className="pdb-overlay">
            {d.dryRun.overlayStack.map((o, i) => <li key={i} className="mono">{o}</li>)}
          </ol>
          <div className="pdb-reject"><Ic name="shield-x" size={12} /><span>{d.dryRun.rejectedWidening}</span></div>
        </div>
      </div>
    </div>
  );
}

/* ── Gate / blocker drawer (Ops) ──────────────────────────────────────── */
function PDBGateDrawer() {
  const d = window.AFB.p02Polymorphic;
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="shield-x" size={14} style={{ color: "var(--abi-block-red)" }} />Gates &amp; blockers</span>
        <span className="ov-pmeta">what stands between candidate and runtime</span>
      </div>
      <div className="pdb-gates">
        {d.gates.map((g) => (
          <div key={g.id} className="pdb-gate" style={{ "--gt": PDB_TONE[g.tone] }}>
            <div className="pdb-gate-top"><span className="pdb-gate-lbl">{g.label}</span><span className={`pdb-gate-chip tone-${g.tone}`}>{g.state}</span></div>
            <div className="pdb-gate-note2">{g.note}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ── Simulation + governance-package preview (Ops) ────────────────────── */
function PDBSimulationPanel() {
  const d = window.AFB.p02Polymorphic;
  const s = d.admissionSimulation, gp = d.governancePackage;
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="flask-conical" size={14} style={{ color: "var(--abi-governance-violet)" }} />Admission simulation</span>
        <span className="ov-pmeta"><span className="pdb-simbadge">{s.mode}</span></span>
      </div>
      <div className="pdb-drawer-body">
        <div className="pdb-sim-note"><Ic name="info" size={12} />Simulation reports readiness and gaps. It never admits, never updates status, never activates a flag. <b>READY_FOR_PROPOSAL ≠ admitted into runtime law.</b></div>
        <div className="pdb-sub">Ready for proposal</div>
        <div className="pdb-sim-ready">
          {s.readyForProposal.map((r) => <span key={r} className="pdb-ready-chip"><Ic name="circle-check" size={11} />{r}</span>)}
        </div>
        <div className="pdb-sub">Blocked · missing gates</div>
        <div className="pdb-sim-blocked">
          {s.blocked.map((b, i) => (
            <div key={i} className="pdb-sb"><span className="pdb-sb-ref">{b.ref}</span><span className="pdb-sb-miss"><Ic name="lock" size={10} />{b.missing}</span></div>
          ))}
        </div>
        <div className="pdb-audit"><Ic name="clipboard-list" size={12} /><span><b>Audit-bundle preview</b> · previewOnly · not persisted, not a release artifact, no customer output. {s.audit.note}</span></div>
        <div className="pdb-sub">Governance proposal package · P0-S11</div>
        <div className="pdb-s11"><span className="pdb-s11-state">{gp.status.replace(/_/g, " ")} · {gp.gate}</span><span className="pdb-s11-scope">{gp.scope}</span></div>
        <div className="pdb-sub">Activation-preflight chain · P0-S12→S22 · frozen</div>
        <div className="pdb-chain-note"><Ic name="info" size={12} />{d.activationChain.note}</div>
        <ol className="pdb-chain">
          {d.activationChain.rungs.map((r) => (
            <li key={r.id} className="pdb-rung">
              <span className="pdb-rung-id mono">{r.id}</span>
              <span className="pdb-rung-lbl">{r.label}</span>
              <span className="pdb-rung-state">{r.state}</span>
            </li>
          ))}
        </ol>
      </div>
    </div>
  );
}

/* ── Core-boundary refs (admitted versions, runtime pinned false) ─────── */
function PDBCoreBoundary() {
  const d = window.AFB.p02Polymorphic;
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="book-marked" size={14} style={{ color: "var(--abi-authority-gold)" }} />Admitted config versions</span>
        <span className="ov-pmeta"><span className="pdb-corebadge">CORE-BOUNDARY · G24</span> ref only</span>
      </div>
      <div className="pdb-cv-list">
        {d.configVersions.map((v) => (
          <div key={v.id} className="pdb-cv">
            <div className="pdb-cv-top"><span className="pdb-cv-id mono">{v.id}</span><span className="pdb-cv-fam mono">{v.family}</span></div>
            <div className="pdb-cv-foot">
              <span className="pdb-cv-status">{v.status}</span>
              <span className="pdb-cv-inactive"><Ic name="power-off" size={10} />runtime_active = 0</span>
              <span className="pdb-cv-reflbl">admitted ref only</span>
            </div>
            <div className="pdb-cv-note">{v.note}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ── Q6 graph-readiness fixture layer (display-only) ─────────────────── */
function PDBQ6Panel() {
  const q = window.AFB.p02Polymorphic.q6;
  return (
    <div className="panelwrap pdb-drawer">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="waypoints" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Q6 graph readiness</span>
        <span className="ov-pmeta"><span className="pdb-simbadge">{q.classification}</span></span>
      </div>
      <div className="pdb-drawer-body">
        <div className="pdb-sim-note"><Ic name="info" size={12} />{q.law}</div>
        <div className="pdb-sub">Path families · five product-feature candidates</div>
        <div className="pdb-cfg-list">
          {q.pathFamilies.map((p) => (
            <div key={p.feature} className={`pdb-cfg st-${p.ready ? "proposed" : "held"}`}>
              <div className="pdb-cfg-top"><span className="pdb-cfg-fam">{p.feature}</span><span className={`pdb-cfg-st st-${p.ready ? "proposed" : "held"}`}>{p.ready ? "PATH READY" : "GAPS"}</span></div>
              <div className="pdb-cfg-note mono">{p.path}</div>
              <div className="pdb-cfg-note">{p.note}</div>
            </div>
          ))}
        </div>
        <div className="pdb-sub">Kept visible — never smoothed away</div>
        <div className="pdb-sim-blocked">
          {q.keptVisible.map((k, i) => (
            <div key={i} className="pdb-sb"><span className="pdb-sb-ref">{k.ref}</span><span className="pdb-sb-miss"><Ic name="eye" size={10} />{k.note}</span></div>
          ))}
          {q.blockedActions.map((b, i) => (
            <div key={"b" + i} className="pdb-sb"><span className="pdb-sb-ref">{b}</span><span className="pdb-sb-miss"><Ic name="lock" size={10} />blocked — does not exist here</span></div>
          ))}
        </div>
        <div className="pdb-audit"><Ic name="arrow-right" size={12} /><span><b>Allowed next action</b> · {q.allowedNext}</span></div>
        <div className="pdb-audit"><Ic name="terminal" size={12} /><span><b>Proof</b> · pnpm {q.proof}</span></div>
      </div>
    </div>
  );
}

/* ── The PDB control-plane mode ───────────────────────────────────────── */
function PDBControlPlaneView() {
  const [sel, setSel] = useState("nordfleet");
  const [show, setShow] = useState({ candidates: true, evidence: true, governance: true, simulation: false, q6: true, blockers: true });
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const onToggle = (id) => setShow((s) => ({ ...s, [id]: !s[id] }));
  return (
    <div className="pdb-root">
      <PDBClassBar />
      <PDBPinStrip />
      <PDBPlaneLegend />
      <PDBToggleBar show={show} onToggle={onToggle} />
      <div className="pdb-main">
        <div className="pdb-col-l">
          {show.candidates && <PDBCandidateGraph sel={sel} onSel={setSel} />}
          {show.governance && <PDBCoreBoundary />}
          {!show.candidates && !show.governance && <div className="pdb-empty"><Ic name="layers" size={18} />Enable an overlay above to inspect the control plane.</div>}
        </div>
        <div className="pdb-col-r">
          {show.evidence && <PDBEvidenceDrawer sel={sel} />}
          {show.governance && <PDBGovernanceDrawer />}
          {show.blockers && <PDBGateDrawer />}
          {show.q6 && <PDBQ6Panel />}
          {show.simulation && <PDBSimulationPanel />}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, {
  PDBControlPlaneView, PDBClassBar, PDBPinStrip, PDBPlaneLegend, PDBToggleBar,
  PDBCandidateGraph, PDBEvidenceDrawer, PDBGovernanceDrawer, PDBGateDrawer, PDBSimulationPanel, PDBCoreBoundary, PDBQ6Panel,
});
