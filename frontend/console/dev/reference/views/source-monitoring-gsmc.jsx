/* Allfather BI — SOURCE MONITORING (GSMC). First-class operator surface for
   governed source monitoring: configured sources, their admitted configuration,
   observation history, trigger behaviour, the live-finding review lifecycle, and
   configuration supersession. Master → detail; nothing here fetches or acts. */

const SM_TONE = {
  cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)", green: "var(--abi-success-green)",
  violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)", muted: "var(--abi-text-muted)",
};

function smDuration(ms) {
  if (!ms) return "—";
  const h = ms / 3600000;
  if (h >= 24) return `${Math.round(h / 24)}d`;
  if (h >= 1) return `${h % 1 === 0 ? h : h.toFixed(1)}h`;
  return `${Math.round(ms / 60000)}m`;
}

/* ── the cockpit strip: counts an operator scans before anything else ───────
   Every cell derives from window.AFB.counts(), so a cell can never disagree with
   the findings table beneath it.

   Split by FUNCTION, not into an arbitrary grid: the four lifecycle statuses each
   narrow the table and are real buttons; the actionable union and the two context
   totals sit in a footer. Seven equal tiles in a four-column grid left a hole and
   implied all seven did the same thing. */
function SmSnapshot({ filter, onFilter }) {
  const k = window.AFB.counts();
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const statuses = [
    { id: "OPEN", label: "Open findings", v: k.openFindings.value, tone: "amber" },
    { id: "ACKNOWLEDGED", label: "Acknowledged", v: k.ackFindings.value, tone: "cyan" },
    { id: "RESOLVED", label: "Resolved", v: k.resolvedFindings.value, tone: "green" },
    { id: "DISMISSED", label: "Dismissed", v: k.dismissedFindings.value, tone: "muted" },
  ];
  return (
    <div className="sm-snapwrap">
      <div className="sm-snap">
        {statuses.map((c) => (
          <button key={c.id} className={`sm-snap-cell act ${filter === c.id ? "on" : ""}`} style={{ "--sc": SM_TONE[c.tone] }}
            onClick={() => onFilter(filter === c.id ? null : c.id)} aria-pressed={filter === c.id}
            title={`Filter the findings table to ${c.label.toLowerCase()}`}>
            <span className="sm-snap-v">{c.v.toLocaleString()}</span>
            <span className="sm-snap-l">{c.label}</span>
            <span className="sm-snap-act">{filter === c.id ? "filtering — click to clear" : "click to filter"}</span>
          </button>
        ))}
      </div>
      <div className="sm-snapfoot">
        <button className={`sm-snapfoot-act ${filter === "due" ? "on" : ""}`} onClick={() => onFilter(filter === "due" ? null : "due")} aria-pressed={filter === "due"}>
          <Ic name="hand" size={13} />
          <b>{k.findingsDue.value.toLocaleString()}</b>
          <span>awaiting a decision</span>
          <em>open + acknowledged</em>
        </button>
        <div className="sm-snapfoot-ctx">
          <span><b>{k.routes.value.toLocaleString()}</b> live routes<em>all statuses</em></span>
          <span><b>{k.promoted.value.toLocaleString()}</b> evaluations promoted<em>across all routes</em></span>
        </div>
      </div>
    </div>
  );
}

/* ── source list row ─────────────────────────────────────────────────────── */
function SmSourceRow({ s, sel, onClick }) {
  const ht = window.AFB.gsmcHealthTone[s.health] || "muted";
  const rt = window.AFB.gsmcRightsTone[s.rights] || "muted";
  return (
    <div className={`sm-row ${sel ? "sel" : ""}`} onClick={onClick} style={{ "--rc": SM_TONE[ht] }}>
      <span className="sm-row-rail" />
      <div className="sm-row-main">
        <div className="sm-row-top">
          <span className="sm-row-ref mono">{s.ref}</span>
          <Badge tone={ht}>{s.health.replace(/_/g, " ")}</Badge>
        </div>
        <div className="sm-row-sub">{s.entity} · {s.kind} · config v{s.version}</div>
      </div>
      <div className="sm-row-meta">
        <span className={`sm-rights tone-${rt}`}>{s.rights.replace(/_/g, " ")}</span>
        <span className="sm-row-when mono">{s.lastObserved === "—" ? "never observed" : s.lastObserved.slice(11) || s.lastObserved}</span>
      </div>
      {s.openRoutes > 0 && <span className="sm-row-open">{s.openRoutes}</span>}
    </div>
  );
}

/* ── detail tabs ─────────────────────────────────────────────────────────── */
const SM_TABS = [
  { id: "config", label: "Config" },
  { id: "observations", label: "Observations" },
  { id: "triggers", label: "Triggers" },
  { id: "reviews", label: "Reviews" },
  { id: "history", label: "History" },
];

function SmDetail({ s, routes, onOpenRoute }) {
  const [tab, setTab] = useState("config");
  useEffect(() => { setTab("config"); }, [s.id]);
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const mine = routes.filter((r) => r.sourceId === s.id);
  const auto = s.autonomy.mode;
  return (
    <aside className="sm-detail">
      <div className="sm-d-head">
        <div className="sm-d-idrow">
          <span className="mono sm-d-id">{s.id}</span>
          <Badge tone={window.AFB.gsmcHealthTone[s.health]}>{s.health.replace(/_/g, " ")}</Badge>
          <span className="sm-d-cfg mono">{s.configId} · {s.versionId}</span>
        </div>
        <div className="sm-d-title">{s.ref}</div>
        <div className="sm-d-sub">{s.entity} · {s.kind}</div>
      </div>

      <div className="sm-tabs">
        {SM_TABS.map((t) => (
          <button key={t.id} className={tab === t.id ? "on" : ""} onClick={() => setTab(t.id)}>{t.label}</button>
        ))}
      </div>

      <div className="sm-d-body">
        {tab === "config" && (
          <React.Fragment>
            <div className="sm-kv-grid">
              <div className="sm-kv"><span className="abi-label">Autonomy mode</span><span className="sm-kv-v">{auto}{auto !== "MANUAL" ? " · owner approval required" : ""}</span></div>
              <div className="sm-kv"><span className="abi-label">Fetch cadence</span><span className="sm-kv-v">{smDuration(s.fetchCadenceMs)}<span className="sm-kv-note">floor 5m</span></span></div>
              <div className="sm-kv"><span className="abi-label">Review cadence</span><span className="sm-kv-v">{smDuration(s.reviewCadenceMs)}{s.ownerReviewRequired ? " · owner review required" : ""}</span></div>
              <div className="sm-kv"><span className="abi-label">Manual fetch</span><span className="sm-kv-v">{s.manualFetchAllowed ? "Permitted · stored only" : "Not permitted"}</span></div>
              <div className="sm-kv"><span className="abi-label">Last observed</span><span className="sm-kv-v mono">{s.lastObserved}</span></div>
              <div className="sm-kv"><span className="abi-label">Next due</span><span className="sm-kv-v mono">{s.nextDue}</span></div>
            </div>
            <div className="sm-rights-block">
              <div className="sm-rights-top">
                <span className="abi-label">Source access policy</span>
                <span className={`sm-rights tone-${window.AFB.gsmcRightsTone[s.rights]}`}>{s.rights.replace(/_/g, " ")}</span>
              </div>
              <p>{s.rightsNote}</p>
            </div>
            <div className="sm-auth">
              <span className="abi-label">Authority basis</span>
              <div className="sm-auth-chain">
                <button className="sm-auth-chip" onClick={() => window.AFB.goTo("console-state", s.authority.proposal)}><span className="mono">{s.authority.proposal}</span>proposal</button>
                <Ic name="chevron-right" size={12} />
                <button className="sm-auth-chip" onClick={() => window.AFB.goTo("arbiter")}><span className="mono">{s.authority.verdict}</span>verdict</button>
                <Ic name="chevron-right" size={12} />
                <button className="sm-auth-chip" onClick={() => window.AFB.goTo("ledger", s.authority.record)}><span className="mono">{s.authority.record}</span>record</button>
              </div>
            </div>
            <div className="sm-unc"><Ic name="triangle-alert" size={13} /><p>{s.uncertainty}</p></div>
          </React.Fragment>
        )}

        {tab === "observations" && (
          s.observations.length === 0
            ? <div className="sm-empty">No observation run has been recorded for this source. Monitoring is held pending the source-terms review.</div>
            : <div className="sm-obs">
                {s.observations.map((o) => (
                  <div key={o.id} className="sm-obs-row" style={{ "--oc": SM_TONE[window.AFB.gsmcObsTone[o.outcome] || "muted"] }}>
                    <span className="sm-obs-dot" />
                    <div className="sm-obs-main">
                      <div className="sm-obs-top">
                        <span className="mono sm-obs-id">{o.id}</span>
                        <span className="sm-obs-out">{o.outcome.replace(/_/g, " ")}</span>
                        <span className="mono sm-obs-at">{o.at}</span>
                      </div>
                      <div className="sm-obs-note">{o.note}</div>
                      <div className="sm-obs-counts"><span>matched · <b>{o.matched}</b></span><span>promoted · <b>{o.selected}</b></span></div>
                    </div>
                  </div>
                ))}
              </div>
        )}

        {tab === "triggers" && (
          <div className="sm-trg">
            {s.triggers.map((t, i) => (
              <div key={i} className="sm-trg-row">
                <span className="mono sm-trg-kind">{t.kind}</span>
                <span className="sm-trg-detail mono">{t.detail}</span>
                <span className="sm-trg-fires">{t.fires} fire{t.fires === 1 ? "" : "s"} · 7d</span>
              </div>
            ))}
            <div className="sm-unc"><Ic name="info" size={13} /><p>A trigger condition is evaluated against the normalized capture, never against raw bytes. Unknown trigger fields are refused at admission.</p></div>
          </div>
        )}

        {tab === "reviews" && (
          mine.length === 0
            ? <div className="sm-empty">No live finding review route exists for this source.</div>
            : <div className="sm-rev">
                {mine.map((r) => (
                  <div key={r.id} className="sm-rev-row" onClick={() => onOpenRoute(r)}>
                    <div className="sm-rev-top">
                      <span className="mono sm-rev-id">{r.id}</span>
                      <Badge tone={window.AFB.gsmcRouteTone[r.status]}>{r.status}</Badge>
                      <span className="sm-rev-sev">{r.severity}</span>
                    </div>
                    <div className="sm-rev-ev">{r.evidencePreview}</div>
                    <div className="sm-rev-meta">
                      <span>promoted <b className="mono">{r.promotedAt.slice(11)}</b></span>
                      <span>due <b className="mono">{r.reviewDueAt.slice(11)}</b></span>
                      <span>{r.selectedCount}/{r.matchedCount} promoted</span>
                      {r.lastDecisionKind && <span className="sm-rev-dec">{r.lastDecisionKind} · {r.lastDecidedAt.slice(11)}</span>}
                    </div>
                  </div>
                ))}
              </div>
        )}

        {tab === "history" && (
          <div className="sm-hist">
            {s.history.map((h) => (
              <div key={h.version} className={`sm-hist-row ${h.status === "ADMITTED" ? "live" : ""}`}>
                <span className="sm-hist-v mono">v{h.version}</span>
                <div className="sm-hist-main">
                  <div className="sm-hist-top"><span className="sm-hist-status">{h.status}</span><span className="mono sm-hist-at">{h.at}</span></div>
                  <div className="sm-hist-change">{h.change}</div>
                </div>
                <button className="sm-hist-rec mono" onClick={() => window.AFB.goTo("ledger", h.record)}>{h.record}</button>
              </div>
            ))}
            <div className="sm-unc"><Ic name="info" size={13} /><p>A configuration change supersedes; it never edits. Every version keeps its own admitted authority chain.</p></div>
          </div>
        )}
      </div>
    </aside>
  );
}

/* ── the findings queue (cross-source, paged) ────────────────────────────── */
const SM_FIND_FACETS = [
  { key: "status", label: "Status", options: [
    { value: "OPEN", label: "Open", tone: "ops-amber" }, { value: "ACKNOWLEDGED", label: "Acknowledged", tone: "evidence-cyan" },
    { value: "RESOLVED", label: "Resolved", tone: "success-green" }, { value: "DISMISSED", label: "Dismissed", tone: "text-muted" }] },
  { key: "severity", label: "Severity", options: [
    { value: "HIGH", label: "High", tone: "block-red" }, { value: "MEDIUM", label: "Medium", tone: "ops-amber" }, { value: "LOW", label: "Low", tone: "text-muted" }] },
];
const SM_FIND_SORTS = [
  { key: "promoted", label: "Promoted" }, { key: "due", label: "Review due" },
  { key: "severity", label: "Severity" }, { key: "entity", label: "Entity" },
];

function SmFindings({ onPick, preset }) {
  const c = useCollection("findings", { sort: "promoted", dir: "desc", filters: preset || {} });
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const r = c.result;
  return (
    <div className="panelwrap sm-find">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="flag" size={14} style={{ color: "var(--abi-ops-amber)" }} />Live finding review routes</span>
        <span className="ov-pmeta"><CollectionMeta c={c} noun="routes" /></span>
      </div>
      <CollectionToolbar c={c} facets={SM_FIND_FACETS} sorts={SM_FIND_SORTS} label="routes" />
      {r.rows.length === 0 ? <CollectionEmpty c={c} noun="routes" /> : (
        <table className="tbl sm-find-tbl">
          <thead><tr><th>Route</th><th>Source</th><th>Triggers</th><th>Status</th><th>Severity</th><th>Due</th><th>Packet</th></tr></thead>
          <tbody>
            {r.rows.map((x) => (
              <tr key={x.id} onClick={() => onPick(x)} className="sm-find-tr">
                <td><b className="mono">{x.id}</b><div className="sm-find-ev">{x.evidencePreview}</div></td>
                <td><span className="mono sm-find-src">{x.sourceRef}</span><div className="sm-find-ent">{x.entity}</div></td>
                <td>{x.triggerKinds.map((k) => <span key={k} className="sm-trg-chip mono">{k}</span>)}</td>
                <td><Badge tone={window.AFB.gsmcRouteTone[x.status]}>{x.status}</Badge></td>
                <td><span className={`sm-sev sev-${x.severity.toLowerCase()}`}>{x.severity}</span></td>
                <td><span className="mono">{x.reviewDueAt.slice(5, 16)}</span></td>
                <td>{x.packet
                  ? <button className="sm-find-pkt mono" onClick={(e) => { e.stopPropagation(); window.AFB.goTo("trace", x.packet); }}>{x.packet}<Ic name="arrow-up-right" size={11} /></button>
                  : <span className="sm-find-nopkt">no packet</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <CollectionPager c={c} noun="routes" />
      <div className="cx-src">Read model <b className="mono">{r.source}</b> · as of <b className="mono">{r.asOf}</b></div>
    </div>
  );
}

/* ── the view ────────────────────────────────────────────────────────────── */
const SM_SRC_FACETS = [
  { key: "health", label: "Health", options: [
    { value: "MONITORING", label: "Monitoring", tone: "success-green" }, { value: "REVIEW_DUE", label: "Review due", tone: "ops-amber" },
    { value: "DEGRADED", label: "Degraded", tone: "block-red" }, { value: "PAUSED", label: "Paused", tone: "text-muted" }] },
  { key: "rights", label: "Source rights", options: [
    { value: "APPROVED", label: "Approved", tone: "success-green" }, { value: "PENDING_REVIEW", label: "Pending review", tone: "ops-amber" },
    { value: "TERMS_RECHECK_OVERDUE", label: "Recheck overdue", tone: "ops-amber" }] },
];
const SM_SRC_SORTS = [{ key: "observed", label: "Last observed" }, { key: "ref", label: "Reference" }, { key: "health", label: "Health" }];

function SourceMonitoringView() {
  const g = window.AFB.ds.getSourceMonitoringStatus().gsmc;
  const sc = useCollection("sources", { sort: "observed", dir: "desc", pageSize: 25 });
  const [sel, setSel] = useState(g.sources[0].id);
  const [findPreset, setFindPreset] = useState(null);
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const s = g.sources.find((x) => x.id === sel) || g.sources[0];
  const openRoute = (r) => { setSel(r.sourceId); };

  return (
    <div className="sm-root">
      <div className="sm-env">
        <Ic name="satellite-dish" size={14} />
        <div>
          <p>{g.env.note}</p>
          <div className="sm-env-pins">
            <span className="mono">LIVE_READ_ACTIVATED · {String(g.env.liveReadActivated).toUpperCase()}</span>
            <span className="mono">CAPTURE CLASS · {g.env.captureClass}</span>
            <span className="mono">REGISTERED CLASS · {g.env.registeredClass}</span>
          </div>
        </div>
      </div>

      <SmSnapshot filter={findPreset && (findPreset.status || (findPreset.due ? "due" : null))}
        onFilter={(key) => setFindPreset(!key ? null : key === "due" ? { due: true } : { status: key })} />

      <div className="sm-grid">
        <div className="panelwrap sm-list">
          <div className="ov-phead">
            <span className="ov-ptitle"><Ic name="radar" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Configured sources</span>
            <span className="ov-pmeta"><CollectionMeta c={sc} noun="sources" /></span>
          </div>
          <CollectionToolbar c={sc} facets={SM_SRC_FACETS} sorts={SM_SRC_SORTS} label="sources" />
          {sc.result.rows.length === 0 ? <CollectionEmpty c={sc} noun="sources" /> : (
            <div className="sm-rows">
              {sc.result.rows.map((x) => <SmSourceRow key={x.id} s={x} sel={x.id === sel} onClick={() => setSel(x.id)} />)}
            </div>
          )}
          <CollectionPager c={sc} noun="sources" />
        </div>
        <SmDetail s={s} routes={g.routes} onOpenRoute={openRoute} />
      </div>

      <SmFindings onPick={openRoute} preset={findPreset || {}} />

      <div className="sm-bottom">
        <div className="panelwrap sm-cmd">
          <div className="ov-phead"><span className="ov-ptitle"><Ic name="terminal" size={14} style={{ color: "var(--abi-authority-gold)" }} />Read commands this surface may issue</span></div>
          <div className="sm-cmd-list">
            {g.commands.map((c) => (
              <div key={c.name} className="sm-cmd-row">
                <span className="mono sm-cmd-name">{c.name}</span>
                <span className="sm-cmd-label">{c.label}</span>
                <span className={`sm-cmd-mut ${c.mutation ? "append" : ""}`}>{c.mutation ? c.mutation.replace(/_/g, " ").toLowerCase() : "read only"}</span>
              </div>
            ))}
          </div>
          <div className="sm-cmd-foot">Plane Ops · access INTERNAL_OPERATOR_READ · no dispatch, no CRM writeback, no customer output, no scheduler, no live fetch.</div>
        </div>
        <div className="panelwrap sm-law">
          <div className="ov-phead"><span className="ov-ptitle"><Ic name="scale" size={14} style={{ color: "var(--abi-governance-violet)" }} />What this surface owns</span></div>
          <ul className="sm-law-list">{g.law.map((l, i) => <li key={i}>{l}</li>)}</ul>
          <div className="sm-law-sep">
            GSMC is not M15. Governed source monitoring decides when and whether a source is observed and what happens to a finding. The
            <button className="sm-law-link" onClick={() => window.AFB.goTo("ingestion")}>Ingestion</button>
            surface owns what an observation may lawfully become.
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { SourceMonitoringView, SmSnapshot, SmDetail, SmFindings });
