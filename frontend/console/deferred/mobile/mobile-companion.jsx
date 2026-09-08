/* Allfather BI — Mobile companion: a phone projection of the OPERATOR CONSOLE.

   This is NOT the customer Intelligence Cockpit. The previous build was: it opened
   on a "Morning briefing" greeting with a market-posture ring, showed
   Signals/Approve/Ledger, carried the marketing tagline as a footer, and let the
   operator SIGN an approval — minting a Decision Record from a phone. That
   violated the console's two load-bearing rules at once:

     the console owns no domain truth
     a button does not create authority

   So mobile is deliberately READ-ONLY. It answers the questions an operator
   actually has away from the desk — what is Allfather doing, what needs me, are
   my sources healthy, can I see the route — and for anything requiring authority
   it states the required act and the surface that owns it. Nothing is signed,
   approved, dispatched or recorded here.

   Four tabs, mirroring the console's Operator band plus the one surface most worth
   checking from a phone:
     Operating · Needs you · Sources · Trace

   Every figure reads window.AFB.counts() or window.AFB.ds — the same derivations
   the desktop uses, so a phone can never disagree with the console.
   Native mechanics (pull-to-refresh, drag-dismiss sheets, tab transitions) are
   preserved from the previous build. */

const MB_TONEVAR = {
  neutral: "border-strong", muted: "text-muted", cyan: "evidence-cyan", amber: "ops-amber",
  green: "success-green", violet: "governance-violet", gold: "authority-gold", red: "block-red",
};
const mbTone = (t) => `var(--abi-${MB_TONEVAR[t] || "border-strong"})`;

/* ═══════════════ Gesture primitives (unchanged) ═══════════════ */

function MbPullScroll({ children, onRefresh, scrollRef }) {
  const inner = useRef(null);
  const ref = scrollRef || inner;
  const [pull, setPull] = useState(0);
  const [busy, setBusy] = useState(false);
  const drag = useRef({ active: false, y0: 0 });
  const THRESH = 64;

  function down(e) {
    if (busy) return;
    if (ref.current && ref.current.scrollTop <= 0) drag.current = { active: true, y0: e.clientY };
  }
  function move(e) {
    if (!drag.current.active) return;
    const dy = e.clientY - drag.current.y0;
    if (dy <= 0) { setPull(0); return; }
    if (ref.current.scrollTop > 0) { drag.current.active = false; setPull(0); return; }
    setPull(Math.min(96, dy * 0.5));
  }
  function up() {
    if (!drag.current.active) return;
    drag.current.active = false;
    if (pull >= THRESH) {
      setBusy(true); setPull(THRESH);
      Promise.resolve(onRefresh && onRefresh()).then(() => {
        setTimeout(() => { setBusy(false); setPull(0); }, 620);
      });
    } else setPull(0);
  }
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); }, [busy]);

  const armed = pull >= THRESH;
  return (
    <div className="mb-ptr-wrap" onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}>
      <div className="mb-ptr-ind" style={{ height: pull, opacity: pull > 6 ? 1 : 0 }}>
        <span className={`mb-ptr-spin ${busy ? "spin" : ""}`} style={{ transform: `rotate(${busy ? 0 : pull * 3}deg)` }}>
          <Ic name={busy ? "loader" : "arrow-down"} size={15} />
        </span>
        <span className="mb-ptr-txt">{busy ? "Re-reading projection…" : armed ? "Release to re-read" : "Pull to re-read"}</span>
      </div>
      <div className="mb-scroll" ref={ref} style={{ transform: pull ? `translateY(${pull}px)` : "none", transition: drag.current.active ? "none" : "transform .3s cubic-bezier(.2,0,0,1)" }}>
        {children}
      </div>
    </div>
  );
}

function MbSheet({ title, sub, onClose, children, accent = "gold" }) {
  const [dy, setDy] = useState(0);
  const [closing, setClosing] = useState(false);
  const drag = useRef({ active: false, y0: 0 });
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  function close() { setClosing(true); setTimeout(onClose, 220); }
  function down(e) { drag.current = { active: true, y0: e.clientY }; }
  function move(e) { if (drag.current.active) setDy(Math.max(0, e.clientY - drag.current.y0)); }
  function up() {
    if (!drag.current.active) return;
    drag.current.active = false;
    if (dy > 110) close(); else setDy(0);
  }
  return (
    <div className={`mb-sheet-scrim ${closing ? "out" : ""}`} onClick={close}>
      <div className={`mb-sheet ${closing ? "out" : ""}`} onClick={(e) => e.stopPropagation()}
        style={{ transform: dy ? `translateY(${dy}px)` : undefined, transition: drag.current.active ? "none" : undefined,
          boxShadow: `var(--abi-shadow-pop), inset 0 2px 0 0 ${mbTone(accent)}` }}>
        <div className="mb-sheet-grip" onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}><span /></div>
        <div className="mb-sheet-hd">
          <div><h3>{title}</h3>{sub && <p>{sub}</p>}</div>
          <button className="mb-sheet-x" onClick={close} aria-label="Close"><Ic name="x" size={16} /></button>
        </div>
        <div className="mb-sheet-body">{children}</div>
      </div>
    </div>
  );
}

/* The one component that keeps mobile honest: it names the required act and the
   surface that owns it, and states plainly that this phone performs neither. */
function MbOwnerNote({ act, owner, surface }) {
  return (
    <div className="mb-owner">
      <Ic name="lock" size={13} />
      <div>
        <b>{act}</b>
        <p>Owned by {owner}. The companion is read-only — this act is performed on the <span className="mb-owner-sfc">{surface}</span> surface in the console, where the owning module records it. Viewing it here changes nothing.</p>
      </div>
    </div>
  );
}


/* ═══════════════ 0 · What you need to know ═══════════════
   Readable-now governed output, not a generated digest. Source of truth is
   m16.artifacts: an artifact appears here only when M16 has actually activated a
   projection for it (renderState VISIBLE). Held and withdrawn artifacts are
   counted, never rendered as if readable. */
function MbBrief({ onRefresh, onOpenArtifact, onGoQueue }) {
  const m16 = window.AFB.ds.getReleaseState().m16;
  const k = window.AFB.counts();
  const w = window.AFB.workspace;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });

  const readable = m16.artifacts.filter((a) => a.renderState === "VISIBLE");
  const held = m16.artifacts.filter((a) => a.renderState === "REFUSED" || a.renderState === "WITHHELD_STALE").length;
  const withdrawn = m16.artifacts.filter((a) => a.renderState === "WITHDRAWN").length;
  const staleCount = readable.filter((a) => a.staleness && a.staleness !== "FRESH").length;
  const queueTotal = window.AFB.ds.query("attention", { pageSize: 1 }).total;

  return (
    <MbPullScroll onRefresh={onRefresh}>
      <div className="mb-briefhd">
        <div className="abi-label">{w.workspace} · {w.role}</div>
        <h2>What you need to know</h2>
        <p><b>{readable.length}</b> governed output{readable.length === 1 ? "" : "s"} {readable.length === 1 ? "is" : "are"} readable at your role lens right now{staleCount > 0 ? <React.Fragment>, <b className="mb-brief-warn">{staleCount} past its freshness threshold</b></React.Fragment> : ""}.</p>
      </div>

      {readable.length === 0 ? (
        <div className="mb-clear"><Ic name="inbox" size={18} />Nothing is readable at your lens. An empty brief is a real state — no output has been activated for this audience.</div>
      ) : readable.map((a) => {
        const claims = (a.elements || []).filter((el) => el.cls === "CLAIM");
        const facts = claims.filter((el) => el.interpretation === "FACT_OBSERVED");
        const reads = claims.filter((el) => el.interpretation !== "FACT_OBSERVED");
        const stale = a.staleness && a.staleness !== "FRESH";
        return (
          <div key={a.id} className="mb-brief" style={{ "--bc": mbTone(stale ? "amber" : "green") }}>
            <div className="mb-brief-top">
              <span className="mb-brief-kind">{(a.kind || "").replace(/_/g, " ").toLowerCase()}</span>
              <span className="mono mb-brief-id">{a.id}</span>
            </div>
            <h3 className="mb-brief-t">{a.title}</h3>
            <div className="mb-brief-ent"><Ic name="building-2" size={11} />{a.entity}</div>

            {facts.length > 0 && (
              <div className="mb-brief-blk obs">
                <span className="mb-brief-h"><Ic name="eye" size={11} />Observed</span>
                {facts.map((el, i) => <p key={i}>{el.text}</p>)}
              </div>
            )}
            {reads.length > 0 && (
              <div className="mb-brief-blk int">
                <span className="mb-brief-h"><Ic name="brain" size={11} />Interpretation{reads[0].confidence && <em>{reads[0].confidence}</em>}</span>
                {reads.map((el, i) => <p key={i}>{el.text}</p>)}
              </div>
            )}

            <div className="mb-brief-foot">
              <span className={stale ? "mb-brief-fresh warn" : "mb-brief-fresh"}>
                <Ic name={stale ? "clock-alert" : "clock"} size={11} />
                {stale ? (a.staleness === "STALE_DOWNGRADED" ? "Stale — downgraded" : "Stale — past warning") : "Fresh"} · observed {a.freshness}
              </span>
              <span className="mb-brief-ev"><Ic name="link" size={11} />{a.sourceUse.primary} primary of {a.sourceUse.sources}</span>
            </div>
            <div className="mb-brief-ceil"><Ic name="shield" size={11} />{(a.claimCeiling || "").replace(/_/g, " ").toLowerCase()}</div>
            <div className="mb-brief-acts">
              <button onClick={() => onOpenArtifact(a)}>Detail<Ic name="arrow-right" size={12} /></button>
              {a.packet && <button className="ghost" onClick={() => { window.AFB._pendingPacket = a.packet; onOpenArtifact(a); }}>Packet {a.packet}</button>}
            </div>
          </div>
        );
      })}

      {(held > 0 || withdrawn > 0 || queueTotal > 0) && (
        <div className="mb-brief-rest">
          <span className="abi-label">Not in this brief</span>
          {held > 0 && <p><b>{held}</b> artifact{held === 1 ? "" : "s"} held or withheld — no projection has been activated, so there is nothing to read yet.</p>}
          {withdrawn > 0 && <p><b>{withdrawn}</b> withdrawn — reliance revoked; the record and its reason are preserved.</p>}
          {queueTotal > 0 && <p><b>{queueTotal}</b> item{queueTotal === 1 ? "" : "s"} await your authority. <button onClick={onGoQueue}>Open Needs you<Ic name="arrow-right" size={11} /></button></p>}
        </div>
      )}
    </MbPullScroll>
  );
}

function MbArtifactSheet({ a, onClose }) {
  const T = window.AFB.m16StatusTone;
  return (
    <MbSheet title={a.title} accent={T[a.status]} sub={<span className="mono">{a.id} · {a.entity}</span>} onClose={onClose}>
      <div className="mb-sh-band">
        <div className="mb-sh-bcell"><span className="abi-label">Status</span><b>{a.status.replace(/_/g, " ")}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Audience</span><b>{a.audience.replace(/_/g, " ")}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Decision</span><b className="mono">{a.decision}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Reliance</span><b>{a.reliance}</b></div>
      </div>
      <p className="mb-sh-detail">{a.uncertainty}</p>
      {a.lenses && a.lenses.length > 0 && (
        <div className="mb-sh-cell mb-rightsrow"><span className="abi-label">Role lenses</span><b>{a.lenses.join(" · ")}</b></div>
      )}
      <div className="mb-sh-locknote"><Ic name="shield" size={13} />Claim ceiling · {(a.claimCeiling || "").replace(/_/g, " ").toLowerCase()}</div>
      <MbOwnerNote act="Release, supersession and withdrawal" owner="M16 · release & visibility" surface="Release & Visibility" />
    </MbSheet>
  );
}

/* ═══════════════ 1 · Operating ═══════════════ */

function MbOperating({ onRefresh, onGoQueue, onGoSources, onOpenStage }) {
  const k = window.AFB.counts();
  const ws = window.AFB.ds.getWorkspaceSummary();
  const w = window.AFB.workspace;
  const live = window.AFB.operatingLive();
  const queueTotal = window.AFB.ds.query("attention", { pageSize: 1 }).total;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });

  const tiles = [
    { v: `${k.monitoring.value}/${k.sources.value}`, l: "Sources monitoring", tone: "cyan" },
    { v: k.openFindings.value, l: "Open findings", tone: "amber" },
    { v: k.released.value, l: "Released internal", tone: "green" },
  ];

  return (
    <MbPullScroll onRefresh={onRefresh}>
      <div className="mb-opshd">
        <div className="abi-label">{w.tenant}</div>
        <h2>{w.workspace}</h2>
        <p>Operating the Allfather Monitor MVP. Read-only projection as of <b className="mono">{ws.asOf}</b>.</p>
      </div>

      <button className="mb-needs" onClick={onGoQueue}>
        <div className="mb-needs-l">
          <Ic name="hand" size={14} style={{ color: "var(--abi-authority-gold)" }} />
          <span><b>{queueTotal}</b> items need you</span>
        </div>
        <div className="mb-needs-tags">
          <span className="mb-mini-tag tone-amber">{k.underReview.value} under review</span>
          <Ic name="chevron-right" size={15} className="mb-chev" />
        </div>
      </button>

      <div className="mb-stats">
        {tiles.map((st) => (
          <div key={st.l} className="mb-stat">
            <div className="mb-stat-v" style={{ color: mbTone(st.tone) }}>{st.v}</div>
            <div className="mb-stat-l">{st.l}</div>
          </div>
        ))}
      </div>

      <div className="mb-sec-h"><Ic name="workflow" size={13} style={{ color: "var(--abi-evidence-cyan)" }} />The Monitor loop</div>
      <div className="mb-loop">
        {live.flow.map((f, i) => (
          <button key={f.id} className="mb-loop-row" style={{ "--lc": mbTone(f.tone) }} onClick={() => onOpenStage(f)}>
            <span className="mb-loop-n mono">{String(i + 1).padStart(2, "0")}</span>
            <span className="mb-loop-l">{f.label}</span>
            <span className="mb-loop-v mono">{f.count.toLocaleString()}</span>
            <span className="mb-loop-u">{f.unit}</span>
            {f.stalled ? <span className="mb-loop-st">{f.stalled} not moving</span> : null}
          </button>
        ))}
      </div>
      <p className="mb-loopnote">Each stage counts its own object type and is owned by the module named in its detail. A number moving does not make anything true.</p>

      <div className="mb-sec-h">
        <Ic name="radar" size={13} style={{ color: "var(--abi-evidence-cyan)" }} />Source health
        <button className="mb-seeall" onClick={onGoSources}>View all<Ic name="arrow-right" size={12} /></button>
      </div>
      <div className="mb-health">
        {[
          { l: "Monitoring", v: k.monitoring.value, tone: "green" },
          { l: "Review due", v: k.reviewDue.value, tone: "amber" },
          { l: "Degraded", v: k.degraded.value, tone: "red" },
          { l: "Paused", v: k.paused.value, tone: "muted" },
        ].filter((x) => x.v > 0).map((x) => (
          <div key={x.l} className="mb-health-cell" style={{ "--hc": mbTone(x.tone) }}>
            <b>{x.v}</b><span>{x.l}</span>
          </div>
        ))}
      </div>

      <div className="mb-envstrip">
        <Ic name="triangle-alert" size={13} />
        <p>Environment pins at this baseline: live read is <b className="mono">NOT ACTIVATED</b>, customer-visible output is <b className="mono">FALSE</b>, owner go is <b className="mono">NOT GRANTED</b>. These are environment state, not the product's structure.</p>
      </div>
    </MbPullScroll>
  );
}

/* ═══════════════ 2 · Needs you ═══════════════ */

function MbQueue({ onRefresh, onOpen }) {
  const sections = window.AFB.buildQueue(window.AFB.artifacts);
  const [sec, setSec] = useState("all");
  const total = sections.reduce((n, s) => n + s.items.length, 0);
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const shown = sec === "all" ? sections : sections.filter((s) => s.key === sec);

  return (
    <React.Fragment>
      <div className="mb-screen-hd">
        <h1>Needs you</h1>
        <span className="mb-screen-sub mono">{total} items</span>
      </div>
      <div className="mb-chips">
        <button className={`mb-chip ${sec === "all" ? "on" : ""}`} onClick={() => setSec("all")}>All<span className="mb-chip-n">{total}</span></button>
        {sections.map((s) => (
          <button key={s.key} className={`mb-chip ${sec === s.key ? "on" : ""}`} onClick={() => setSec(s.key)}>
            {s.title.replace(/ — for awareness/, "")}<span className="mb-chip-n">{s.items.length}</span>
          </button>
        ))}
      </div>
      <MbPullScroll onRefresh={onRefresh}>
        <div className="mb-qlaw"><Ic name="info" size={12} />The queue aggregates attention. It owns no object — every row belongs to another module.</div>
        {shown.map((s) => (
          <div key={s.key} className="mb-apgroup">
            <div className="mb-sec-h"><Ic name={s.icon} size={13} style={{ color: mbTone(s.tone) }} />{s.title}</div>
            <div className="mb-list">
              {s.items.map((it, i) => (
                <button key={it.ref + i} className="mb-row" onClick={() => onOpen(it, s)}>
                  <span className="mb-rail" style={{ background: mbTone(s.tone) }} />
                  <div className="mb-row-main">
                    <div className="mb-row-kicker">
                      <span className="mono mb-qref">{it.ref}</span>
                      <span className="mb-fresh mono">{s.key === "blocked" ? "awareness" : "action"}</span>
                    </div>
                    <div className="mb-row-title">{it.title}</div>
                    <div className="mb-row-note">{it.why}</div>
                  </div>
                  <Ic name="chevron-right" size={16} className="mb-chev" />
                </button>
              ))}
            </div>
          </div>
        ))}
      </MbPullScroll>
    </React.Fragment>
  );
}

/* ═══════════════ 3 · Sources ═══════════════ */

function MbSources({ onRefresh, onOpen }) {
  const g = window.AFB.ds.getSourceMonitoringStatus().gsmc;
  const [f, setF] = useState("all");
  const H = window.AFB.gsmcHealthTone;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const counts = {};
  g.sources.forEach((s) => { counts[s.health] = (counts[s.health] || 0) + 1; });
  const filters = [{ id: "all", label: "All", n: g.sources.length },
    ...["MONITORING", "REVIEW_DUE", "DEGRADED", "PAUSED"].filter((h) => counts[h])
      .map((h) => ({ id: h, label: h.replace(/_/g, " ").toLowerCase(), n: counts[h] }))];
  const vis = f === "all" ? g.sources : g.sources.filter((s) => s.health === f);

  return (
    <React.Fragment>
      <div className="mb-screen-hd">
        <h1>Sources</h1>
        <span className="mb-screen-sub mono">GSMC · {g.sources.length} configured</span>
      </div>
      <div className="mb-chips">
        {filters.map((x) => (
          <button key={x.id} className={`mb-chip ${f === x.id ? "on" : ""}`} onClick={() => setF(x.id)}>
            {x.label}<span className="mb-chip-n">{x.n}</span>
          </button>
        ))}
      </div>
      <MbPullScroll onRefresh={onRefresh}>
        <div className="mb-list">
          {vis.length ? vis.map((s) => (
            <button key={s.id} className="mb-row" onClick={() => onOpen(s)}>
              <span className="mb-rail" style={{ background: mbTone(H[s.health]) }} />
              <div className="mb-row-main">
                <div className="mb-row-kicker">
                  <span className="mb-kind" style={{ color: mbTone(H[s.health]) }}>{s.health.replace(/_/g, " ")}</span>
                  <span className="mb-fresh mono">{s.lastObserved === "—" ? "never" : s.lastObserved.slice(11)}</span>
                </div>
                <div className="mb-row-title mono mb-srcref">{s.ref}</div>
                <div className="mb-row-meta">
                  <span className="mb-ent">{s.entity}</span>
                  {s.rights !== "APPROVED" && <span className="mb-rights">{s.rights.replace(/_/g, " ").toLowerCase()}</span>}
                  {s.openRoutes > 0 && <span className="mb-ev"><Ic name="flag" size={11} />{s.openRoutes} open</span>}
                </div>
              </div>
              <Ic name="chevron-right" size={16} className="mb-chev" />
            </button>
          )) : <div className="mb-empty"><Ic name="filter" size={20} />No source under this filter.</div>}
        </div>
      </MbPullScroll>
    </React.Fragment>
  );
}

/* ═══════════════ 4 · Trace ═══════════════ */

function MbTrace({ onRefresh, onOpenNode }) {
  const packets = window.AFB.packets;
  const [sel, setSel] = useState(null);
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const p = packets.find((x) => x.id === sel);
  const PT = window.AFB.packetPlaneTone;

  if (p) return (
    <React.Fragment>
      <div className="mb-screen-hd mb-trace-hd">
        <button className="mb-back" onClick={() => setSel(null)}><Ic name="chevron-left" size={16} />Packets</button>
        <span className="mb-screen-sub mono">{p.nodes.length} nodes</span>
      </div>
      <MbPullScroll onRefresh={onRefresh}>
        <div className="mb-tsum" style={{ "--tc": mbTone(p.tone) }}>
          <span className="mono mb-tsum-id">{p.id}</span>
          <div className="mb-tsum-t">{p.title}</div>
          <span className="mb-tsum-out" style={{ color: mbTone(p.tone) }}>{p.outcome.replace(/_/g, " ")}</span>
        </div>
        <div className="mb-chain">
          {p.nodes.map((n, i) => (
            <button key={n.id} className="mb-cnode" style={{ "--nc": mbTone(n.tone) }} onClick={() => onOpenNode(n)}>
              <span className="mb-cnode-rail"><span className="mb-cnode-dot" />{i < p.nodes.length - 1 && <span className="mb-cnode-line" />}</span>
              <div className="mb-cnode-main">
                {n.edge && <span className="mb-cnode-edge mono">{window.AFB.packetEdge[n.edge] || n.edge}</span>}
                <div className="mb-cnode-top">
                  <span className="mono mb-cnode-id">{n.id}</span>
                  <span className="mb-cnode-plane" style={{ color: mbTone(PT[n.plane]) }}>{n.plane}</span>
                </div>
                <div className="mb-cnode-ty">{n.type}<span className="mb-cnode-owner mono">{n.owner}</span></div>
                <div className="mb-cnode-state" style={{ color: mbTone(n.tone) }}>{n.state}</div>
              </div>
            </button>
          ))}
        </div>
      </MbPullScroll>
    </React.Fragment>
  );

  return (
    <React.Fragment>
      <div className="mb-screen-hd">
        <h1>Packet Trace</h1>
        <span className="mb-screen-sub mono">{packets.length} packets</span>
      </div>
      <MbPullScroll onRefresh={onRefresh}>
        <div className="mb-qlaw"><Ic name="info" size={12} />Only the stages an object actually passed through are shown. Nothing is rendered as skipped.</div>
        <div className="mb-list">
          {packets.map((x) => (
            <button key={x.id} className="mb-row" onClick={() => setSel(x.id)}>
              <span className="mb-rail" style={{ background: mbTone(x.tone) }} />
              <div className="mb-row-main">
                <div className="mb-row-kicker">
                  <span className="mono mb-qref">{x.id}</span>
                  <span className="mb-fresh mono">{x.nodes.length} nodes</span>
                </div>
                <div className="mb-row-title">{x.title}</div>
                <div className="mb-row-meta">
                  <span className="mb-tout" style={{ color: mbTone(x.tone) }}>{x.outcome.replace(/_/g, " ")}</span>
                </div>
              </div>
              <Ic name="chevron-right" size={16} className="mb-chev" />
            </button>
          ))}
        </div>
      </MbPullScroll>
    </React.Fragment>
  );
}

/* ═══════════════ Sheets ═══════════════ */

function MbQueueSheet({ item, section, onClose }) {
  const blocked = section.key === "blocked";
  return (
    <MbSheet title={item.title} accent={section.tone} sub={<span className="mono">{item.ref}</span>} onClose={onClose}>
      <p className="mb-sh-detail">{item.why}</p>
      <div className="mb-sh-meta">
        <div className="mb-sh-cell"><span className="abi-label">Attention class</span><b>{section.title}</b></div>
        <div className="mb-sh-cell"><span className="abi-label">Owning surface</span><b>{window.AFB.viewLabel(item.view)}</b></div>
        <div className="mb-sh-cell"><span className="abi-label">Packet trace</span><b className="mono">{item.trace || "none recorded"}</b></div>
      </div>
      <MbOwnerNote
        act={blocked ? "No action available — surfaced for awareness" : "Requires an act on the owning surface"}
        owner={section.owner || section.title.replace(/ — for awareness/, "")}
        surface={window.AFB.viewLabel(item.view)} />
    </MbSheet>
  );
}

function MbSourceSheet({ s, onClose }) {
  const H = window.AFB.gsmcHealthTone;
  return (
    <MbSheet title={s.ref} accent={H[s.health]} sub={<span>{s.entity} · {s.kind}</span>} onClose={onClose}>
      <div className="mb-sh-status">
        <Badge tone={H[s.health]}>{s.health.replace(/_/g, " ")}</Badge>
        <span className="mono mb-ref">{s.id}</span>
      </div>
      <div className="mb-sh-band">
        <div className="mb-sh-bcell"><span className="abi-label">Config</span><b className="mono">{s.versionId}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Autonomy</span><b>{s.autonomy.mode}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Last observed</span><b className="mono">{s.lastObserved}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Next due</span><b className="mono">{s.nextDue}</b></div>
      </div>
      <div className="mb-sh-cell mb-rightsrow">
        <span className="abi-label">Source access policy</span>
        <b>{s.rights.replace(/_/g, " ")}</b>
      </div>
      <p className="mb-sh-detail">{s.rightsNote}</p>
      <div className="mb-sh-locknote"><Ic name="triangle-alert" size={13} />{s.uncertainty}</div>
      <MbOwnerNote act={s.manualFetchAllowed ? "Manual fetch is a stored permission on the configuration" : "Monitoring is held"}
        owner="GSMC" surface="Source Monitoring" />
    </MbSheet>
  );
}

function MbNodeSheet({ n, onClose }) {
  const PT = window.AFB.packetPlaneTone;
  return (
    <MbSheet title={n.id} accent={n.tone} sub={<span>{n.type} · {n.owner}</span>} onClose={onClose}>
      <div className="mb-sh-band">
        <div className="mb-sh-bcell"><span className="abi-label">Plane</span><b style={{ color: mbTone(PT[n.plane]) }}>{n.plane}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">State</span><b>{n.state}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">At</span><b className="mono">{n.at}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Digest</span><b className="mono">{n.digest}</b></div>
      </div>
      <div className="mb-sh-meta">
        <div className="mb-sh-cell mb-nodebl"><span className="abi-label">Authority basis</span></div>
      </div>
      <p className="mb-sh-detail">{n.basis}</p>
      <div className="mb-sh-meta">
        <div className="mb-sh-cell mb-nodebl"><span className="abi-label">Truth &amp; limits</span></div>
      </div>
      <p className="mb-sh-detail">{n.truth}</p>
    </MbSheet>
  );
}

function MbStageSheet({ f, onClose }) {
  return (
    <MbSheet title={f.label} accent={f.tone} sub={<span className="mono">{f.module}</span>} onClose={onClose}>
      <div className="mb-sh-band">
        <div className="mb-sh-bcell"><span className="abi-label">At this stage</span><b className="mono">{f.count.toLocaleString()}</b></div>
        <div className="mb-sh-bcell"><span className="abi-label">Counting</span><b>{f.unit}</b></div>
        {f.stalled ? <div className="mb-sh-bcell"><span className="abi-label">Not moving</span><b>{f.stalled.toLocaleString()}</b></div> : null}
        <div className="mb-sh-bcell"><span className="abi-label">Owner</span><b className="mono">{f.module}</b></div>
      </div>
      <p className="mb-sh-detail">This count is read from {f.module}, the module that owns the stage. Each stage of the loop counts a different object type, so the figures are not a single quantity flowing left to right.</p>
      <MbOwnerNote act="Stage detail and drill-down" owner={f.module} surface={window.AFB.viewLabel(f.view)} />
    </MbSheet>
  );
}

/* ═══════════════ Root ═══════════════ */

function MobileCompanion({ onClose }) {
  const [tab, setTab] = useState("brief");
  const [dir, setDir] = useState(1);
  const [sheet, setSheet] = useState(null);

  const TABS = [
    { id: "brief", icon: "book-open", label: "For you" },
    { id: "operating", icon: "gauge", label: "Operating" },
    { id: "queue", icon: "hand", label: "Needs you" },
    { id: "sources", icon: "radar", label: "Sources" },
    { id: "trace", icon: "route", label: "Trace" },
  ];
  const order = TABS.map((t) => t.id);
  const queueTotal = window.AFB.ds.query("attention", { pageSize: 1 }).total;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });

  function go(id) {
    if (id === tab) return;
    setDir(order.indexOf(id) > order.indexOf(tab) ? 1 : -1);
    setTab(id);
  }
  function refresh() { return new Promise((res) => setTimeout(res, 500)); }

  return (
    <div className="mb-scrim" onClick={onClose}>
      <div className="mb-frame" onClick={(e) => e.stopPropagation()}>
        <div className="mb-notch" />
        <div className="mb-screen">
          <div className="mb-statusbar">
            <span>9:41</span>
            <span className="mb-sb-r"><Ic name="signal" size={12} /><Ic name="wifi" size={12} /><Ic name="battery-full" size={13} /></span>
          </div>
          <div className="mb-appbar">
            <div className="mb-brand"><img src="assets/logo-mark.svg" alt="" /><span>ALLFATHER<b> BI</b></span></div>
            <div className="mb-env">OPERATOR · READ ONLY</div>
          </div>

          <div key={tab} className={`mb-page ${dir > 0 ? "fwd" : "back"}`}>
            {tab === "brief" && <MbBrief onRefresh={refresh} onGoQueue={() => go("queue")}
              onOpenArtifact={(a) => setSheet({ kind: "artifact", payload: a })} />}
            {tab === "operating" && <MbOperating onRefresh={refresh} onGoQueue={() => go("queue")} onGoSources={() => go("sources")}
              onOpenStage={(f) => setSheet({ kind: "stage", payload: f })} />}
            {tab === "queue" && <MbQueue onRefresh={refresh} onOpen={(item, section) => setSheet({ kind: "queue", payload: { item, section } })} />}
            {tab === "sources" && <MbSources onRefresh={refresh} onOpen={(s) => setSheet({ kind: "source", payload: s })} />}
            {tab === "trace" && <MbTrace onRefresh={refresh} onOpenNode={(n) => setSheet({ kind: "node", payload: n })} />}
          </div>

          <div className="mb-tabs">
            {TABS.map((tb) => (
              <button key={tb.id} className={`mb-tab ${tab === tb.id ? "on" : ""}`} onClick={() => go(tb.id)}>
                <Ic name={tb.icon} size={19} /><span>{tb.label}</span>
                {tb.id === "queue" && queueTotal > 0 && <span className="mb-badge">{queueTotal}</span>}
              </button>
            ))}
          </div>

          {sheet && sheet.kind === "queue" && <MbQueueSheet item={sheet.payload.item} section={sheet.payload.section} onClose={() => setSheet(null)} />}
          {sheet && sheet.kind === "source" && <MbSourceSheet s={sheet.payload} onClose={() => setSheet(null)} />}
          {sheet && sheet.kind === "node" && <MbNodeSheet n={sheet.payload} onClose={() => setSheet(null)} />}
          {sheet && sheet.kind === "artifact" && <MbArtifactSheet a={sheet.payload} onClose={() => setSheet(null)} />}
          {sheet && sheet.kind === "stage" && <MbStageSheet f={sheet.payload} onClose={() => setSheet(null)} />}
        </div>
      </div>
      <button className="mb-close" onClick={onClose}><Ic name="x" size={16} />Close companion</button>
    </div>
  );
}

Object.assign(window, { MobileCompanion });
