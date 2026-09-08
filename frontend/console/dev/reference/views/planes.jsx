/* Allfather BI — M02 Access & Authority and M11 Assurance planes.
   Canonical vocabulary; exported to window. Composes ReplayView (M11 replay
   bundle) from panels.jsx. */

/* ===================== M02 · Access & Authority ===================== */
function RoleDetail({ role }) {
  return (
    <aside className="ac-detail">
      <div className="ac-d-head">
        <div className="ac-d-name">{role.name}{role.mod && <span className="ac-d-mod">{role.mod}</span>}</div>
        <div className="ac-d-key mono">{role.key}</div>
        <p className="ac-d-note">{role.note}</p>
        <div className="ac-d-count"><b>{role.perms.length}</b> permissions granted</div>
      </div>
      <div className="ac-d-perms">
        {role.perms.map((p) => <span key={p} className="ac-perm mono">{p}</span>)}
      </div>
    </aside>
  );
}

const TIER_VAR = { cyan: "var(--abi-evidence-cyan)", violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", green: "var(--abi-success-green)" };
function SubscriptionTiers() {
  return (
    <div className="panelwrap ac-tiers-wrap">
      <div className="ov-phead"><span className="ov-ptitle"><Ic name="layers" size={14} style={{ color: "var(--abi-authority-gold)" }} />Subscription tiers</span><span className="ov-pmeta">two-gate default-deny · M12 surface + M06 capability</span></div>
      <div className="ac-tiers">
        {window.AFB.tiers.map((t, i) => (
          <div key={t.key} className="ac-tier" style={{ "--tt": TIER_VAR[t.tone] }}>
            <div className="ac-tier-hd">
              <span className="ac-tier-rank">{i + 1}</span>
              <div className="ac-tier-id">
                <span className="ac-tier-name">{t.name}</span>
                <span className="ac-tier-key mono">{t.key}</span>
              </div>
              <span className="ac-tier-fee">{t.fee}</span>
            </div>
            <div className="ac-tier-mods">{t.modules.map((m, j) => <span key={j} className="ac-tier-mod">{m}</span>)}</div>
            <div className="ac-tier-note">{t.note}</div>
          </div>
        ))}
      </div>
      <div className="ac-tiers-foot"><Ic name="info" size={12} />A surface or capability is granted only when a held role AND an active tier both permit it. Absence of either is denial.</div>
    </div>
  );
}

function AccessPlane() {
  const [sel, setSel] = useState(window.AFB.roles[1].key);
  const role = window.AFB.roles.find((r) => r.key === sel) || window.AFB.roles[0];
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="ac-root">
      <div className="ac-principle">
        <div className="ac-pr-l">
          <Ic name="shield" size={18} style={{ color: "var(--abi-authority-gold)" }} />
          <div>
            <div className="ac-pr-title">Default-deny authority</div>
            <div className="ac-pr-note">A role name is never unlimited authority. Every protected operation resolves through an explicit permission key; absence of a matching active permission is never permission.</div>
          </div>
        </div>
        <div className="ac-legend">
          <span className="ac-leg"><i className="tone-green" />ALLOW</span>
          <span className="ac-leg"><i className="tone-red" />DENY</span>
          <span className="ac-leg"><i className="tone-amber" />HOLD_FOR_REVIEW</span>
        </div>
      </div>

      <div className="ac-grid">
        <div className="panelwrap">
          <div className="ov-phead"><span className="ov-ptitle"><Ic name="users" size={14} style={{ color: "var(--abi-authority-gold)" }} />Roles</span><span className="ov-pmeta">{window.AFB.roles.length} roles · substrate + module</span></div>
          <div className="ac-roles">
            {window.AFB.roles.map((r) => (
              <div key={r.key} className={`ac-role ${r.key === sel ? "sel" : ""}`} onClick={() => setSel(r.key)}>
                <div className="ac-role-main">
                  <div className="ac-role-name">{r.name}{r.mod && <span className="ac-role-mod">{r.mod}</span>}</div>
                  <div className="ac-role-key mono">{r.key}</div>
                </div>
                <span className="ac-role-count">{r.perms.length}</span>
              </div>
            ))}
          </div>
        </div>
        <RoleDetail role={role} />
      </div>

      <div className="ac-grid2">
        <div className="panelwrap">
          <div className="ov-phead"><span className="ov-ptitle"><Ic name="git-merge" size={14} style={{ color: "var(--abi-authority-gold)" }} />Access evaluation · default-deny</span><span className="ov-pmeta">server-side, UI-independent</span></div>
          <div className="ac-eval">
            {window.AFB.accessSteps.map((s, i) => (
              <div key={i} className={`ac-step ${s.fail ? "" : "allow"}`}>
                <span className="ac-step-n">{i + 1}</span>
                <div className="ac-step-main">
                  <div className="ac-step-name">{s.step}</div>
                  <div className="ac-step-detail">{s.detail}</div>
                </div>
                {s.fail ? <div className="ac-step-fail"><span className="ac-fail-code">{s.fail}</span><span className="ac-fail-sev">{s.sev}</span></div>
                  : <Ic name="check" size={15} style={{ color: "var(--abi-success-green)" }} />}
              </div>
            ))}
          </div>
        </div>
        <div className="panelwrap">
          <div className="ov-phead"><span className="ov-ptitle"><Ic name="scroll-text" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Recent access evaluations</span><span className="ov-pmeta">append-only audit</span></div>
          <div className="ac-log">
            {window.AFB.accessLog.map((l, i) => (
              <div key={i} className="ac-logrow">
                <span className="ac-log-at mono">{l.at}</span>
                <div className="ac-log-main">
                  <div className="ac-log-perm mono">{l.perm}</div>
                  <div className="ac-log-actor">{l.actor} · <span className="ac-log-role">{l.role}</span></div>
                </div>
                <div className="ac-log-dec">
                  <Badge tone={window.AFB.accessDecisionTone[l.decision]}>{l.decision.replace(/_/g, " ")}</Badge>
                  <span className={`ac-log-reason ${l.sev === "S5" ? "crit" : ""}`}>{l.reason}{l.sev ? ` · ${l.sev}` : ""}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <SubscriptionTiers />

      <div className="panelwrap ac-excluded">
        <div className="ov-phead"><span className="ov-ptitle"><Ic name="shield-x" size={14} style={{ color: "var(--abi-block-red)" }} />Excluded permissions · V1</span><span className="ov-pmeta">hard-denied (S5) before role/scope</span></div>
        <div className="ac-excluded-body">
          <p className="ac-excluded-note">Requesting any of these is a release-blocking violation regardless of role state — the guard hard-denies before any role or scope evaluation, and the seed never creates them.</p>
          <div className="ac-excluded-chips">{window.AFB.v1Excluded.map((k) => <span key={k} className="vk-excl mono">{k}</span>)}</div>
        </div>
      </div>
    </div>
  );
}

/* ===================== M11 · Assurance ===================== */
const TL_ICON = {
  OPENED: "circle-dot", TRIAGE: "filter", CONTAINMENT_RECOMMENDED: "shield-alert",
  CONTAINMENT_ACKNOWLEDGED: "shield-check", INVESTIGATION_NOTE: "search", FINDING_LINKED: "link",
  REPLAY_LINKED: "rotate-ccw", REMEDIATION_NOTE: "wrench", RESOLVED: "check-circle", CLOSED: "x-circle", ESCALATED: "arrow-up-right",
};

function IncidentCard({ inc }) {
  return (
    <div className="panelwrap inc-card">
      <div className="inc-head">
        <div className="card-ic" style={{ background: "var(--abi-red-fill)", borderColor: "var(--abi-red-line)", color: "var(--abi-block-red)" }}><Ic name="alert-triangle" size={16} /></div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="abi-label">{inc.id} · trigger {inc.trigger}</div>
          <div className="inc-cls">{inc.cls.replace(/_/g, " ")}</div>
        </div>
        <div className="inc-badges">
          <span className="inc-sev">{inc.sev}</span>
          <Badge tone={window.AFB.incidentStatusTone[inc.status]}>{inc.status.replace(/_/g, " ")}</Badge>
        </div>
      </div>
      <div className="inc-summary">{inc.summary}</div>
      <div className="inc-timeline">
        {inc.timeline.map((e, i) => (
          <div key={i} className={`inc-tl ${e.t === "RESOLVED" ? "done" : ""}`}>
            <span className="inc-tl-dot"><Ic name={TL_ICON[e.t] || "circle"} size={11} /></span>
            <div className="inc-tl-main">
              <div className="inc-tl-top"><span className="inc-tl-type">{e.t.replace(/_/g, " ")}</span><span className="inc-tl-at mono">{e.at}</span><span className="inc-tl-by">{e.by}</span></div>
              <div className="inc-tl-note">{e.note}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function AssurancePlane() {
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  const s = window.AFB.assuranceSummary;
  const a = window.AFB.auditRun;
  const tiles = [
    { label: "Replay regression", value: s.replay.replace(/_/g, " "), tone: s.replayPass ? "green" : "red", icon: "rotate-ccw" },
    { label: "Ledger audit", value: s.audit.replace(/_/g, " "), tone: "green", icon: "shield-check" },
    { label: "Open incidents", value: s.openIncidents, tone: "amber", icon: "alert-triangle" },
    { label: "Blocking gaps", value: s.blockingGaps, tone: "violet", icon: "construction" },
  ];
  const tv = { green: "var(--abi-success-green)", red: "var(--abi-block-red)", amber: "var(--abi-ops-amber)", violet: "var(--abi-governance-violet)" };
  return (
    <div className="asr-root">
      <div className="asr-summary">
        {tiles.map((t) => (
          <div key={t.label} className="asr-tile">
            <Ic name={t.icon} size={16} style={{ color: tv[t.tone] }} />
            <div className="asr-tile-v" style={{ color: tv[t.tone] }}>{t.value}</div>
            <div className="asr-tile-l">{t.label}</div>
          </div>
        ))}
      </div>

      <div className="asr-grid">
        <div className="asr-col">
          <div className="asr-h"><Ic name="alert-triangle" size={14} style={{ color: "var(--abi-block-red)" }} />Incident cases</div>
          {window.AFB.incidents.map((inc) => <IncidentCard key={inc.id} inc={inc} />)}
          <div className="asr-invnote"><Ic name="info" size={13} />M11 recommends containment; the owning module enacts and acknowledges. M11 never self-executes, approves, or mutates another plane's state.</div>
        </div>

        <div className="asr-col">
          <div className="asr-h"><Ic name="shield-check" size={14} style={{ color: "var(--abi-success-green)" }} />Ledger-integrity audit</div>
          <div className="panelwrap audit-card">
            <div className="audit-top">
              <span className="mono audit-id">{a.id}</span>
              <Badge tone="green">{a.finding.replace(/_/g, " ")}</Badge>
            </div>
            <div className="audit-check mono">{a.check}</div>
            <div className="audit-chips"><span className="audit-chip ok"><Ic name="link" size={11} />chain {a.chain}</span><span className="audit-chip ok"><Ic name="git-fork" size={11} />refs {a.refs}</span><span className="audit-chip"><Ic name="layers" size={11} />{a.records} records</span></div>
            <div className="audit-summary">{a.summary}</div>
          </div>

          <div className="asr-h"><Ic name="construction" size={14} style={{ color: "var(--abi-governance-violet)" }} />Gap register</div>
          <div className="panelwrap gap-list">
            {window.AFB.gaps.map((g) => {
              const blocking = g.effect === "BLOCK_FEATURE" || g.effect === "BLOCK_RELEASE" || g.effect === "REQUIRE_INCIDENT_REVIEW";
              return (
                <div key={g.id} className="gap-row">
                  <div className="gap-top">
                    <span className="mono gap-id">{g.id}</span>
                    <span className="gap-type">{g.type.replace(/_/g, " ")}</span>
                    <span className={`gap-effect tone-${window.AFB.gapEffectTone[g.effect]}`}>{g.effect.replace(/_/g, " ")}</span>
                    {blocking && <span className="gap-blocking">blocking</span>}
                  </div>
                  <div className="gap-summary">{g.summary}</div>
                </div>
              );
            })}
            <div className="gap-note"><Ic name="eye" size={12} />Blocking gaps stay visible — never hidden from a readiness view (INV-018).</div>
          </div>

          <div className="asr-h"><Ic name="file-diff" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Governed change proposals</div>
          <div className="panelwrap gcp-list">
            {window.AFB.changeProposals.map((c) => (
              <div key={c.id} className="gcp-row">
                <div className="gcp-top"><span className="mono gcp-id">{c.id}</span><span className="gcp-type">{c.type.replace(/_/g, " ")}</span><span className="gcp-tag">PROPOSAL · not active law</span></div>
                <div className="gcp-summary">{c.summary}</div>
                <div className="gcp-meta">status <b>{c.status}</b> · promotion <b>{c.promotion.replace(/_/g, " ")}</b> · from <span className="mono">{c.sourceGap}</span></div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="asr-replay">
        <div className="asr-h"><Ic name="history" size={14} style={{ color: "var(--abi-authority-gold)" }} />Replay bundle · no-side-effect re-derivation</div>
        <ReplayView />
      </div>
    </div>
  );
}

Object.assign(window, { AccessPlane, AssurancePlane });
