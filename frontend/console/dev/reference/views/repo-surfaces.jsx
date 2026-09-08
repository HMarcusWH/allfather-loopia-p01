/* Allfather BI — repo surfaces: MCM Diagnostics + Migration Readiness views.
   Read-only readouts; no run/approve/analyze affordance anywhere. Exported. */

const RS_TONE = {
  cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)", green: "var(--abi-success-green)",
  violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)",
  muted: "var(--abi-text-muted)",
};

/* Inert banner — reuses the rm-preview treatment (global CSS). */
function RsBanner({ tag, doctrine, status }) {
  return (
    <div className="rm-preview">
      <span className="rm-preview-tag"><Ic name="flask-conical" size={13} />{tag}</span>
      <span className="rm-preview-tx">{doctrine}</span>
      <span className="rm-preview-status"><span className="d" />{status}</span>
    </div>
  );
}

/* ════════════════ MCM-HMWH DIAGNOSTICS ════════════════ */
function McmView() {
  const d = window.AFB.mcm;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="p01-root rs-root">
      <RsBanner tag="MCM-HMWH · Diagnostic MVP"
        doctrine="A governed diagnostic operator layer — schema/report slice only. It reads recorded evidence, scores candidate explanations deterministically, red-teams its own rigor, and stops at a manual review gate it never performs."
        status={`Runtime-inert · imported ${d.imported}`} />
      <div className="rs-law"><Ic name="scale" size={13} /><p><b>Copy law:</b> {d.law}</p></div>
      <div className="rs-grid">
        <div className="rs-col">
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="git-commit-vertical" size={14} style={{ color: "var(--abi-governance-violet)" }} />Diagnostic flow</span>
              <span className="ov-pmeta">every artifact review-gated</span>
            </div>
            <div className="rs-flow">
              {d.flow.map((s, i) => (
                <div key={s.id} className="rs-fs" style={{ "--fc": RS_TONE[s.tone] }}>
                  {i < d.flow.length - 1 && <span className="rs-fs-spine" />}
                  <span className="rs-fs-node">{s.id}</span>
                  <div className="rs-fs-main">
                    <div className="rs-fs-hd">
                      <span className="rs-fs-name">{s.name}</span>
                      {s.gate && <span className="rs-fs-gate mono">{s.gate}</span>}
                    </div>
                    <div className="rs-fs-note">{s.note}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="rs-col">
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="flask-conical" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Fixture cases</span>
              <span className="ov-pmeta">{d.roles}</span>
            </div>
            <div className="rs-cases">
              {d.cases.map((c) => (
                <div key={c.id} className={`rs-case ${c.neg ? "neg" : ""}`}>
                  <div className="rs-case-hd">
                    <span className="rs-case-id mono">{c.id}</span>
                    {c.neg && <span className="rs-case-neg mono">NEGATIVE CONTROL</span>}
                  </div>
                  <div className="rs-case-lb">{c.label}</div>
                  <div className="rs-case-v">{c.verdict}</div>
                </div>
              ))}
            </div>
            <div className="rs-pins">
              {d.pins.map((p) => <span key={p} className="rs-pin mono">{p}</span>)}
            </div>
          </div>
          <div className="rs-law">
            <Ic name="shield" size={13} />
            <p><b>Ceiling</b> · <span className="mono" title={window.AFB.gloss(d.ceiling)}>{d.ceiling}</span> — pinned on every artifact this layer emits.</p>
          </div>
          <div className="rs-proof"><Ic name="terminal" size={12} />{d.proof}</div>
        </div>
      </div>
    </div>
  );
}

/* ════════════════ MIGRATION READINESS ════════════════ */
function MigrationView() {
  const d = window.AFB.migration;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="p01-root rs-root">
      <RsBanner tag="Standalone migration · A-campaign"
        doctrine="The repo is formally prepared for extraction into a standalone Allfather repository. Preparation is evidence work — freeze, attest, inventory, gate — and grants nothing."
        status="Read-only readout · preparation ≠ promotion" />
      <div className="rs-gate">
        <span className="rs-gate-tag"><Ic name="badge-check" size={14} />{d.gate.label}</span>
        <span className="rs-gate-v mono">{d.gate.value}</span>
        <span className="rs-gate-x">{d.gate.extra}</span>
        <span className="rs-gate-x rs-gate-aud mono">{d.audited}</span>
      </div>
      <div className="rs-grid">
        <div className="rs-col">
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="milestone" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Phase ladder</span>
              <span className="ov-pmeta">host repo becomes read-only source of truth</span>
            </div>
            <div className="rs-phases">
              {d.phases.map((p) => (
                <div key={p.id} className="rs-ph" style={{ "--pc": RS_TONE[p.tone] }}>
                  <span className="rs-ph-id mono">{p.id}</span>
                  <div className="rs-ph-main">
                    <div className="rs-ph-hd"><span className="rs-ph-name">{p.name}</span><span className="rs-ph-state mono">{p.state}</span></div>
                    <div className="rs-ph-note">{p.note}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="rs-law"><Ic name="shield-off" size={13} /><p>{d.law}</p></div>
        </div>
        <div className="rs-col">
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="clipboard-check" size={14} style={{ color: "var(--abi-ops-amber)" }} />Sweep audit findings</span>
              <span className="ov-pmeta">owner-requested · 2026-07-10</span>
            </div>
            <div className="rs-findings">
              {d.findings.map((f) => (
                <div key={f.id} className={`rs-f ${f.state === "OPEN" ? "open" : "repaired"}`}>
                  <span className="rs-f-id mono">{f.id}</span>
                  <span className="rs-f-note">{f.note}</span>
                  <span className="rs-f-st mono">{f.state}</span>
                </div>
              ))}
            </div>
          </div>
          <div className="rs-proof"><Ic name="terminal" size={12} />{d.proofLine}</div>
          <div className="rs-law">
            <Ic name="info" size={13} />
            <p>The Overview's <b>Audit findings 0</b> counts M11 incident findings, which remain zero. This dated migration sweep exists alongside it with three open recommendations — different register, kept distinct on purpose.</p>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { McmView, MigrationView });
