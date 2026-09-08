/* Allfather BI — Intelligence-first Overview (the product home).
   Executive strategic top-line. Three switchable compositions of the same
   evidence: Command (operator grid), Briefing (narrative), Manifold (viz-led).
   Exported to window. */

const OV_TONE_VAR = {
  cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)",
  green: "var(--abi-success-green)", violet: "var(--abi-governance-violet)",
  gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)",
  neutral: "var(--abi-text-secondary)",
};


/* mini sparkline */
function Spark({ data, tone = "cyan", w = 132, h = 38 }) {
  const max = Math.max(...data), min = Math.min(...data), span = (max - min) || 1;
  const pts = data.map((v, i) => [(i / (data.length - 1)) * w, h - 3 - ((v - min) / span) * (h - 6)]);
  const line = pts.map((p, i) => (i ? "L" : "M") + p[0].toFixed(1) + " " + p[1].toFixed(1)).join(" ");
  const area = `${line} L${w} ${h} L0 ${h} Z`;
  const c = OV_TONE_VAR[tone] || OV_TONE_VAR.cyan;
  const id = "sg" + tone;
  return (
    <svg className="spark" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" style={{ width: "100%", height: h }}>
      <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor={c} stopOpacity="0.22" /><stop offset="100%" stopColor={c} stopOpacity="0" />
      </linearGradient></defs>
      <path d={area} fill={`url(#${id})`} />
      <path d={line} fill="none" stroke={c} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={pts[pts.length - 1][0]} cy={pts[pts.length - 1][1]} r="2.4" fill={c} />
    </svg>
  );
}

function PostureGauge() {
  const p = window.AFB.posture;
  const R = 30, C = 2 * Math.PI * R, frac = p.index / 100;
  return (
    <div className="posture">
      <div className="posture-ring">
        <svg viewBox="0 0 80 80">
          <circle cx="40" cy="40" r={R} fill="none" stroke="var(--abi-border-subtle)" strokeWidth="6" />
          <circle cx="40" cy="40" r={R} fill="none" stroke="var(--abi-ops-amber)" strokeWidth="6"
            strokeLinecap="round" strokeDasharray={`${(C * frac).toFixed(1)} ${C.toFixed(1)}`}
            transform="rotate(-90 40 40)" />
        </svg>
        <div className="posture-idx"><b>{p.index}</b><span>index</span></div>
      </div>
      <div className="posture-main">
        <div className="abi-label">Market posture</div>
        <div className="posture-stance">{p.stance}
          <span className="posture-delta">{p.indexDelta > 0 ? "+" : ""}{p.indexDelta} wk</span>
        </div>
        <div className="posture-note">{p.stanceNote}</div>
      </div>
    </div>
  );
}

function MetricTile({ m }) {
  return (
    <div className="mtile">
      <div className="mt-top">
        <Ic name={m.icon} size={15} style={{ color: OV_TONE_VAR[m.tone] }} />
        <span className="mt-delta">{m.delta}</span>
      </div>
      <div className="mt-val" style={m.tone !== "neutral" ? { color: OV_TONE_VAR[m.tone] } : null}>
        {m.value}<span className="mt-unit">{m.unit}</span>
      </div>
      <div className="mt-lbl">{m.label}</div>
      <Spark data={m.spark} tone={m.tone === "neutral" ? "cyan" : m.tone} />
    </div>
  );
}

function SignalRow({ s, onOpen }) {
  const vp = s.verdict ? verdictPres(s.verdict) : null;
  return (
    <div className="sig" onClick={() => s.linkArtifact && onOpen(s.linkArtifact)} style={{ cursor: s.linkArtifact ? "pointer" : "default" }}>
      <span className="sig-rail" style={{ background: OV_TONE_VAR[s.tone] }} />
      <div className="sig-main">
        <div className="sig-top">
          <span className="sig-kind" style={{ color: OV_TONE_VAR[s.tone] }}>{s.kind}</span>
          <span className="sig-mat">{s.materiality} materiality</span>
          <span className="sig-time">{s.fresh}</span>
        </div>
        <div className="sig-title">{s.title}</div>
        <div className="sig-detail">{s.detail}</div>
        <div className="sig-foot">
          <span className="sig-chip"><Ic name="building-2" size={11} />{s.entity}</span>
          <span className="sig-chip mono"><Ic name="link" size={11} />{s.source}</span>
          <span className="sig-chip"><Ic name="file-text" size={11} />{s.evidence} evidence</span>
          {vp && <span className={`sig-verdict tone-${VTONE_BADGE[vp.tone]}`}>{vp.short}</span>}
          {s.linkArtifact && <span className="sig-open">{s.linkArtifact}<Ic name="arrow-right" size={12} /></span>}
        </div>
      </div>
    </div>
  );
}

function SignalsPanel({ onOpen, compact }) {
  const sigs = window.AFB.signals;
  return (
    <div className="panelwrap ov-signals">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="activity" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Signal stream</span>
        <span className="ov-pmeta">{sigs.length} changes · today</span>
      </div>
      <div className={`sig-list ${compact ? "compact" : ""}`}>
        {sigs.map((s) => <SignalRow key={s.id} s={s} onOpen={onOpen} />)}
      </div>
    </div>
  );
}

function ManifoldPanel({ height, onNav }) {
  return (
    <div className="panelwrap ov-manifold">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="git-fork" size={14} style={{ color: "var(--abi-authority-gold)" }} />Evidence manifold</span>
        <span className="ov-pmeta">hover a node to trace provenance</span>
      </div>
      <Manifold height={height} />
      <div className="mf-legend">
        <span><i style={{ background: "var(--abi-evidence-cyan)" }} />Verified</span>
        <span><i style={{ background: "var(--abi-ops-amber)" }} />Missing / partial</span>
        <span><i style={{ background: "var(--abi-block-red)" }} />Blocked</span>
        <span><i style={{ background: "var(--abi-authority-gold)" }} />Sealed → Core</span>
        <button className="mf-open" onClick={() => onNav("evidence")}>Open Evidence Spine<Ic name="arrow-right" size={12} /></button>
      </div>
    </div>
  );
}

function BriefItem({ it, onOpen }) {
  return (
    <div className="brief-item" onClick={() => onOpen(it.ref)}>
      <Badge stateKey={it.state} />
      <div className="brief-it-main">
        <div className="brief-it-lbl">{it.label}</div>
        <div className="brief-it-note">{it.note}</div>
      </div>
      <span className="brief-it-ref mono">{it.ref}<Ic name="arrow-right" size={12} /></span>
    </div>
  );
}

function BriefingCard({ onOpen }) {
  const b = window.AFB.briefing;
  return (
    <div className="panelwrap brief">
      <div className="brief-head">
        <div>
          <div className="abi-label">{b.date}</div>
          <h2 className="brief-greet">{b.greeting}</h2>
        </div>
        <Ic name="sunrise" size={22} style={{ color: "var(--abi-authority-gold)", opacity: .8 }} />
      </div>
      <p className="brief-lede">{b.lede}</p>
      <div className="brief-sec"><div className="brief-sec-h"><Ic name="pen-line" size={13} style={{ color: "var(--abi-authority-gold)" }} />Needs your signature</div>
        {b.needsYou.map((it) => <BriefItem key={it.ref} it={it} onOpen={onOpen} />)}</div>
      <div className="brief-sec"><div className="brief-sec-h"><Ic name="circle-pause" size={13} style={{ color: "var(--abi-ops-amber)" }} />Evidence is thin</div>
        {b.thin.map((it) => <BriefItem key={it.ref} it={it} onOpen={onOpen} />)}</div>
      <div className="brief-sec"><div className="brief-sec-h"><Ic name="shield-x" size={13} style={{ color: "var(--abi-block-red)" }} />Blocked under ceiling</div>
        {b.blocked.map((it) => <BriefItem key={it.ref} it={it} onOpen={onOpen} />)}</div>
    </div>
  );
}

function CompaniesStrip({ onNav }) {
  return (
    <div className="panelwrap ov-companies">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="radar" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Watched entities</span>
        <span className="ov-pmeta">{window.AFB.companies.length} monitored</span>
      </div>
      <div className="comp-list">
        {window.AFB.companies.map((c) => (
          <div key={c.id} className="comp-row">
            <span className="comp-rail" style={{ background: OV_TONE_VAR[c.tone] }} />
            <div className="comp-name">{c.name}<span className="comp-seg">{c.seg}</span></div>
            <span className={`comp-posture tone-${c.tone === "neutral" ? "muted" : c.tone}`}>{c.posture}</span>
            <span className="comp-sig"><Ic name={c.change === "up" ? "trending-up" : c.change === "down" ? "trending-down" : "minus"} size={13}
              style={{ color: c.change === "up" ? "var(--abi-ops-amber)" : c.change === "down" ? "var(--abi-text-muted)" : "var(--abi-text-muted)" }} />{c.signals}</span>
            <span className="comp-last mono">{c.last}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function ModuleGatesStrip({ onNav }) {
  const g = window.AFB.moduleGates;
  const T = { green: "var(--abi-success-green)", red: "var(--abi-block-red)", violet: "var(--abi-governance-violet)", amber: "var(--abi-ops-amber)" };
  return (
    <div className="ov-gates">
      <div className="ov-gates-l">
        <span className="ov-gates-tag"><Ic name="badge-check" size={12} />{g.closed.label}</span>
        <span className="ov-gates-ceil mono">{g.closed.ceiling}</span>
      </div>
      <div className="ov-gates-mods">
        {g.mods.map((m, i) => (
          <button key={i} className="ov-gate-chip" style={{ "--gt": T[m.tone] }} onClick={() => onNav(m.id)} title={m.unlock}>
            <span className="ov-gate-dot" />
            <span className="ov-gate-code mono">{m.code}</span>
            <span className="ov-gate-status">{m.status}</span>
          </button>
        ))}
        <button className="ov-gate-chip ov-gate-more" onClick={() => onNav("roadmap")}>sequence<Ic name="arrow-right" size={11} /></button>
      </div>
    </div>
  );
}

/* ---- the Overview view with three compositions ---- */
function Overview({ onNav, onOpen }) {
  const [layout, setLayout] = useState(() => localStorage.getItem("afb-ov-layout") || "command");
  const pick = (l) => { setLayout(l); localStorage.setItem("afb-ov-layout", l); };
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });

  const LAYOUTS = [
    { id: "command", label: "Command", icon: "layout-grid" },
    { id: "briefing", label: "Briefing", icon: "scroll-text" },
    { id: "manifold", label: "Manifold", icon: "git-fork" },
  ];

  return (
    <div className="ov-root">
      <div className="ov-hero">
        <div className="ov-hero-l">
          <div className="abi-label">{window.AFB.workspace.tenant} · Intelligence overview</div>
          <h1 className="ov-h1">What changed, why it matters</h1>
        </div>
        <div className="ov-switch">
          {LAYOUTS.map((l) => (
            <button key={l.id} className={layout === l.id ? "on" : ""} onClick={() => pick(l.id)}>
              <Ic name={l.icon} size={13} />{l.label}
            </button>
          ))}
        </div>
      </div>

      {layout === "command" && (
        <div className="ov-command">
          <PostureGauge />
          <div className="ov-metrics">{window.AFB.metrics.map((m) => <MetricTile key={m.id} m={m} />)}</div>
          <div className="ov-cmd-grid">
            <ManifoldPanel height={400} onNav={onNav} />
            <SignalsPanel onOpen={onOpen} />
          </div>
          <CompaniesStrip onNav={onNav} />
        </div>
      )}

      {layout === "briefing" && (
        <div className="ov-briefing">
          <div className="ov-brief-l">
            <BriefingCard onOpen={onOpen} />
          </div>
          <div className="ov-brief-r">
            <PostureGauge />
            <div className="ov-metrics col2">{window.AFB.metrics.map((m) => <MetricTile key={m.id} m={m} />)}</div>
            <ManifoldPanel height={300} onNav={onNav} />
          </div>
          <div className="ov-brief-full"><SignalsPanel onOpen={onOpen} compact /></div>
        </div>
      )}

      {layout === "manifold" && (
        <div className="ov-manifoldview">
          <div className="ov-mf-side">
            <PostureGauge />
            <div className="ov-metrics col1">{window.AFB.metrics.map((m) => <MetricTile key={m.id} m={m} />)}</div>
          </div>
          <ManifoldPanel height={560} onNav={onNav} />
          <div className="ov-mf-side"><SignalsPanel onOpen={onOpen} compact /></div>
        </div>
      )}

      <ModuleGatesStrip onNav={onNav} />
    </div>
  );
}

Object.assign(window, { Overview, Spark, MetricTile, SignalsPanel, ManifoldPanel, PostureGauge, BriefingCard, CompaniesStrip, ModuleGatesStrip });
