/* Allfather BI — Pilot Road Test view (controlled road-test execution readiness).
   Reflects chain head #262. Read-only readout — no run/go/activate affordance.

   Design grammar (three-plane discipline): this is Ops/Sources packaging around
   Core proof objects, NOT live execution authority.
     · amber  → the current blocked-pending / held lifecycle state
     · cyan   → evidence / provenance / proof commands
     · red    → the closed boundary (things asserted false)
     · gold   → RESERVED for the single authority element: the next owner-go gate
   Exported to window. */

const RD_TONE = {
  ready:   "var(--abi-evidence-cyan)",
  blocked: "var(--abi-ops-amber)",
  closed:  "var(--abi-success-green)",
};
const RD_LADDER = {
  COMPLETE: { tone: "var(--abi-success-green)", icon: "check", label: "Complete" },
  BLOCKED:  { tone: "var(--abi-ops-amber)",     icon: "circle-pause", label: "Blocked" },
  FUTURE:   { tone: "var(--abi-text-muted)",    icon: "circle-dashed", label: "Future" },
};

/* ── 1. Current status banner ── */
function RdBanner({ b }) {
  return (
    <div className="rd-banner">
      <div className="rd-banner-l">
        <span className="rd-banner-tag"><span className="rd-pulse" />Controlled road test · execution-ready</span>
        <div className="rd-banner-status mono" title={window.AFB.gloss(b.status)}>{b.status}</div>
        <div className="rd-banner-lines">
          {b.lines.map((l, i) => (
            <div key={i} className="rd-banner-line"><Ic name={i === 0 ? "check" : i === 1 ? "minus" : "lock"} size={13} />{l}</div>
          ))}
        </div>
      </div>
      <div className="rd-banner-r">
        <div className="rd-banner-cell">
          <span className="abi-label">Verdict</span>
          <span className="rd-banner-v mono" title={window.AFB.gloss(b.verdict)}>{b.verdict}</span>
        </div>
        <div className="rd-banner-cell">
          <span className="abi-label">Chain head</span>
          <span className="rd-banner-head mono">{b.chainHead}</span>
        </div>
        <div className="rd-banner-cell">
          <span className="abi-label">Claim ceiling</span>
          <span className="rd-banner-ceil mono" title={window.AFB.gloss(b.ceiling)}>{b.ceiling}</span>
        </div>
        <div className="rd-banner-cell">
          <span className="abi-label">Proof lane</span>
          <span className="rd-banner-v mono">{b.proof}</span>
        </div>
      </div>
    </div>
  );
}

/* ── 2. Promotion ladder ── */
function RdLadder({ rows }) {
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="milestone" size={14} style={{ color: "var(--abi-authority-gold)" }} />Promotion ladder</span>
        <span className="ov-pmeta">recorded order · gates are never skipped</span>
      </div>
      <div className="rd-ladder">
        {rows.map((s, i) => {
          const L = RD_LADDER[s.state];
          return (
            <div key={s.id} className={`rd-rung state-${s.state.toLowerCase()}`} style={{ "--rc": L.tone }}>
              {i < rows.length - 1 && <span className="rd-rung-spine" />}
              <span className="rd-rung-node"><Ic name={L.icon} size={13} /></span>
              <div className="rd-rung-body">
                <div className="rd-rung-hd">
                  <span className="rd-rung-label">{s.label}</span>
                  <span className="rd-rung-state" title={window.AFB.gloss(s.state)}>{L.label}</span>
                  <span className="rd-rung-ref mono">{s.ref}</span>
                </div>
                <div className="rd-rung-note">{s.note}</div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ── 3. Closed boundary panel ── */
function RdBoundary({ flags }) {
  return (
    <div className="panelwrap rd-boundary">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="shield-x" size={14} style={{ color: "var(--abi-block-red)" }} />Closed boundary</span>
        <span className="ov-pmeta">asserted false · tamper-tested in code</span>
      </div>
      <div className="rd-flags">
        {flags.map((f) => (
          <div key={f.k} className="rd-flag">
            <Ic name="x" size={12} />
            <span className="rd-flag-k mono">{f.k}</span>
            <span className="rd-flag-v mono">false</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ── 4. Execution-readiness evidence panel ── */
function RdEvidence({ rows, prior }) {
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="file-check-2" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Execution-readiness evidence</span>
        <span className="ov-pmeta">#255–#262 ladder</span>
      </div>
      <div className="rd-ev-list">
        {rows.map((e) => (
          <div key={e.id} className="rd-ev" style={{ "--ec": RD_TONE[e.tone] }}>
            <span className="rd-ev-n mono">#{e.id}</span>
            <div className="rd-ev-main">
              <div className="rd-ev-hd">
                <span className="rd-ev-name">{e.name}</span>
                <span className="rd-ev-state">{e.state}</span>
              </div>
              <div className="rd-ev-detail">{e.detail}</div>
              <div className="rd-ev-cmd mono"><Ic name="terminal" size={11} />pnpm {e.cmd}</div>
            </div>
          </div>
        ))}
        <div className="rd-ev-prior"><Ic name="history" size={11} />{prior}</div>
      </div>
    </div>
  );
}

/* ── 5. Transport capability panel ── */
function RdTransport({ t }) {
  return (
    <div className="panelwrap rd-transport">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="plug-zap" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Transport capability contract</span>
        <span className="rd-caveat"><Ic name="triangle-alert" size={11} />{t.caveat}</span>
      </div>
      <div className="rd-transport-verdict mono">{t.verdict}</div>
      <div className="rd-transport-ref">{t.ref}</div>
      <div className="rd-policies">
        {t.policies.map((p) => (
          <div key={p.k} className="rd-policy">
            <span className="rd-policy-k mono">{p.k}</span>
            <span className="rd-policy-v mono">{p.v}</span>
          </div>
        ))}
      </div>
      <div className="rd-transport-harness"><Ic name="power-off" size={12} />{t.harness}</div>
    </div>
  );
}

/* ── Next gate panel — the single authority (gold) element ── */
function RdNextGate({ g }) {
  return (
    <div className="rd-gate">
      <div className="rd-gate-hd">
        <span className="rd-gate-tag"><Ic name="key-round" size={14} />Next required gate</span>
      </div>
      <div className="rd-gate-token mono" title={window.AFB.gloss(g.gate)}>{g.gate}</div>
      <div className="rd-gate-intake"><Ic name="inbox" size={12} />{g.intake}</div>
      <div className="rd-gate-req">
        <span className="abi-label">Requires — none of which exists</span>
        <ul className="rd-gate-list">
          {g.requires.map((r, i) => <li key={i}><Ic name="lock" size={12} />{r}</li>)}
        </ul>
      </div>
      <div className="rd-gate-instr">
        <span className="abi-label">Exact owner instruction it would require</span>
        <div className="rd-gate-quote mono">“{g.ownerInstruction}”</div>
        <div className="rd-gate-instr-note"><Ic name="info" size={12} />{g.instructionNote}</div>
      </div>
    </div>
  );
}

/* ── 6. GSMC pipeline panel — the garage MVP behind the proof rung ── */
function RdGsmc({ g }) {
  return (
    <div className="panelwrap rd-gsmc">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="radar" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Source Monitoring (GSMC) — inside the proof rung</span>
        <span className="ov-pmeta mono">{g.src}</span>
      </div>
      <div className="rd-gsmc-law"><Ic name="hand" size={12} />{g.law}</div>
      <div className="rd-gsmc-stages">
        {g.stages.map((s) => (
          <div key={s.id} className={`rd-gs ${s.cls}`}>
            <span className="rd-gs-id mono">{s.id}</span>
            <div className="rd-gs-main">
              <div className="rd-gs-hd"><span className="rd-gs-name">{s.name}</span><span className="rd-gs-flag">{s.flag}</span></div>
              <div className="rd-gs-note">{s.note}</div>
            </div>
          </div>
        ))}
      </div>
      <div className="rd-gsmc-rm">
        <span className="abi-label">Cockpit read-model — mirrored read-only; nothing here fetches or sends</span>
        <div className="rd-gsmc-chips">{g.readModel.map((r) => <span key={r} className="rd-gsmc-chip">{r}</span>)}</div>
      </div>
    </div>
  );
}

function RoadTestView() {
  const d = window.AFB.roadTest;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="rd-root">
      <RdBanner b={d.banner} />
      <RdLadder rows={d.ladder} />
      <div className="rd-grid">
        <div className="rd-col">
          <RdEvidence rows={d.evidence} prior={d.evidencePrior} />
          <RdTransport t={d.transport} />
        </div>
        <div className="rd-col">
          <RdBoundary flags={d.boundary} />
          <RdNextGate g={d.nextGate} />
          <div className="rd-seam">
            <Ic name="shield-off" size={13} />
            <p>{d.seam} <a className="rd-seam-link" href="Allfather BI Guide.html#boundary">Boundary, in the guide</a>.</p>
          </div>
        </div>
      </div>
      <RdGsmc g={d.gsmc} />
    </div>
  );
}

Object.assign(window, { RoadTestView });
