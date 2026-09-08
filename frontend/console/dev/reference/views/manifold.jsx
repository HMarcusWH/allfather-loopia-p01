/* Allfather BI — Evidence-Provenance Manifold (signature visualization).
   A living form of the "Eye in the Manifold" mark: monitored Sources on the
   outer ring, Evidence and Claims converging inward along lineage paths to the
   gold Core eye. Hover any node to trace its full provenance to the Core.
   Exported to window. Pure SVG + CSS — no chart libs. */

const MF_CX = 350, MF_CY = 350;
const MF_RING_R = { sources: 292, evidence: 196, claims: 102 };
const MF_NODE_R = { sources: 4.5, evidence: 6, claims: 8.5 };

// Hand-placed angles (deg, y-down) clustered into 3 lineage sectors so chains
// read cleanly: c1/top, c2/lower-right, c3/left.
const MF_ANG = {
  s1: 246, s2: 270, s3: 294, s4: 4, s5: 50, s6: 145,
  e1: 258, e2: 282, e3: 10, e4: 42, e5: 152,
  c1: 270, c2: 25, c3: 150,
};

const MF_TONE = {
  ready:   "var(--abi-evidence-cyan)",
  partial: "var(--abi-ops-amber)",
  missing: "var(--abi-ops-amber)",
  blocked: "var(--abi-block-red)",
  sealed:  "var(--abi-authority-gold)",
};
const MF_STATUS_LABEL = {
  ready: "Verified", partial: "Partial", missing: "Missing source",
  blocked: "Blocked", sealed: "Sealed · recorded",
};

function mfPos(node) {
  const r = MF_RING_R[node.ring];
  const a = (MF_ANG[node.id] || 0) * Math.PI / 180;
  return { x: MF_CX + r * Math.cos(a), y: MF_CY + r * Math.sin(a) };
}

function Manifold({ height = 440, dense = false }) {
  const data = window.AFB.manifold;
  const [hover, setHover] = useState(null);

  // position lookup incl. core
  const pos = {};
  data.nodes.forEach((n) => { pos[n.id] = mfPos(n); });
  pos.core = { x: MF_CX, y: MF_CY };
  const nodeById = {}; data.nodes.forEach((n) => { nodeById[n.id] = n; });

  // undirected adjacency for lineage trace
  const adj = {};
  data.links.forEach(([a, b]) => {
    (adj[a] = adj[a] || []).push(b);
    (adj[b] = adj[b] || []).push(a);
  });
  let lineage = null;
  if (hover) {
    const seen = new Set([hover]); const stack = [hover];
    while (stack.length) {
      const cur = stack.pop();
      // Core is a sink: every claim links to it, so expanding it would flood the
      // whole graph. Light the edge INTO core, but never hop out to sibling claims.
      if (cur === "core") continue;
      (adj[cur] || []).forEach((n) => { if (!seen.has(n)) { seen.add(n); stack.push(n); } });
    }
    lineage = seen;
  }
  const lit = (id) => !lineage || lineage.has(id);

  function linkPath(a, b) {
    const p = pos[a], q = pos[b];
    // gentle inward bow toward center → convergence feel
    const mx = (p.x + q.x) / 2, my = (p.y + q.y) / 2;
    const cx = mx + (MF_CX - mx) * 0.16, cy = my + (MF_CY - my) * 0.16;
    return `M${p.x.toFixed(1)} ${p.y.toFixed(1)} Q${cx.toFixed(1)} ${cy.toFixed(1)} ${q.x.toFixed(1)} ${q.y.toFixed(1)}`;
  }

  const hoverNode = hover && hover !== "core" ? nodeById[hover] : null;
  const hoverPos = hover ? pos[hover] : null;

  return (
    <div className="mf-wrap" style={{ height }}>
      <svg className="mf-svg" viewBox="0 0 700 700" preserveAspectRatio="xMidYMid meet">
        <defs>
          <radialGradient id="mfCore" cx="50%" cy="50%" r="55%">
            <stop offset="0%" stopColor="#E9C06A" />
            <stop offset="100%" stopColor="#D6A84F" />
          </radialGradient>
          <radialGradient id="mfGlow" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stopColor="rgba(214,168,79,0.20)" />
            <stop offset="70%" stopColor="rgba(214,168,79,0.0)" />
          </radialGradient>
        </defs>

        {/* core glow + concentric authority rings */}
        <circle cx={MF_CX} cy={MF_CY} r="300" fill="url(#mfGlow)" />
        <circle className="mf-scan" cx={MF_CX} cy={MF_CY} r={MF_RING_R.sources} fill="none"
          stroke="var(--abi-border-subtle)" strokeWidth="1" strokeDasharray="2 12" />
        <circle cx={MF_CX} cy={MF_CY} r={MF_RING_R.evidence} fill="none" stroke="var(--abi-border-subtle)" strokeWidth="1" strokeOpacity="0.7" />
        <circle cx={MF_CX} cy={MF_CY} r={MF_RING_R.claims} fill="none" stroke="var(--abi-border-subtle)" strokeWidth="1" strokeOpacity="0.7" />

        {/* lineage links */}
        <g>
          {data.links.map(([a, b], i) => {
            const fromNode = nodeById[a];
            const tone = MF_TONE[(fromNode && fromNode.status) || "ready"] || MF_TONE.ready;
            const flowing = fromNode && (fromNode.status === "ready" || fromNode.status === "sealed");
            const on = lit(a) && lit(b);
            const strong = lineage && on;
            return (
              <path key={i} d={linkPath(a, b)} fill="none"
                stroke={tone}
                strokeWidth={strong ? 2 : 1.1}
                strokeOpacity={on ? (strong ? 0.9 : 0.4) : 0.06}
                strokeLinecap="round"
                className={flowing && on ? "mf-flow" : ""} />
            );
          })}
        </g>

        {/* ring labels (bottom, empty sector) */}
        <g className="mf-ringlabels" fill="var(--abi-text-muted)">
          <text x={MF_CX} y={MF_CY + MF_RING_R.sources + 4}>SOURCES</text>
          <text x={MF_CX} y={MF_CY + MF_RING_R.evidence + 4}>EVIDENCE</text>
          <text x={MF_CX} y={MF_CY + MF_RING_R.claims + 4}>CLAIMS</text>
        </g>

        {/* nodes */}
        <g>
          {data.nodes.map((n) => {
            const p = pos[n.id]; const r = MF_NODE_R[n.ring];
            const tone = MF_TONE[n.status] || MF_TONE.ready;
            const on = lit(n.id);
            const hollow = n.status === "missing";
            const isHover = hover === n.id;
            return (
              <g key={n.id} className="mf-node" opacity={on ? 1 : 0.18}
                onMouseEnter={() => setHover(n.id)} onMouseLeave={() => setHover(null)}>
                {isHover && <circle cx={p.x} cy={p.y} r={r + 7} fill="none" stroke={tone} strokeOpacity="0.5" strokeWidth="1" />}
                <circle cx={p.x} cy={p.y} r={r + 10} fill="transparent" />
                <circle cx={p.x} cy={p.y} r={r}
                  fill={hollow ? "var(--abi-layer-01)" : tone}
                  stroke={tone} strokeWidth={hollow ? 1.5 : 0}
                  strokeDasharray={hollow ? "2 2" : "none"}
                  className={(n.status === "blocked" || n.status === "missing") && on ? "mf-alert" : ""} />
              </g>
            );
          })}
        </g>

        {/* the Core eye — almond lens + gold node + pupil */}
        <g className="mf-core">
          <path d={`M${MF_CX - 64} ${MF_CY} Q${MF_CX} ${MF_CY - 24} ${MF_CX + 64} ${MF_CY} Q${MF_CX} ${MF_CY + 24} ${MF_CX - 64} ${MF_CY} Z`}
            fill="none" stroke="var(--abi-text-primary)" strokeOpacity="0.55" strokeWidth="1.4" strokeLinejoin="round" />
          <circle className="mf-corepulse" cx={MF_CX} cy={MF_CY} r="15" fill="url(#mfCore)" />
          <circle cx={MF_CX} cy={MF_CY} r="5.5" fill="#0B0D10" />
        </g>
      </svg>

      {/* hover tooltip */}
      {hoverNode && (
        <div className="mf-tip" style={{ left: `${(hoverPos.x / 700) * 100}%`, top: `${(hoverPos.y / 700) * 100}%` }}>
          <div className="mf-tip-src">{hoverNode.label}</div>
          <div className="mf-tip-st" style={{ color: MF_TONE[hoverNode.status] }}>
            <span className="mf-dot" style={{ background: MF_TONE[hoverNode.status] }} />
            {MF_STATUS_LABEL[hoverNode.status]}
          </div>
        </div>
      )}
      {hover === "core" && (
        <div className="mf-tip" style={{ left: "50%", top: "50%" }}>
          <div className="mf-tip-src">Allfather Core</div>
          <div className="mf-tip-st" style={{ color: "var(--abi-authority-gold)" }}>Runtime law · all lineage resolves here</div>
        </div>
      )}
    </div>
  );
}

Object.assign(window, { Manifold });
