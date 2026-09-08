/* Allfather BI — Roadmap surfaces: Post-V1 Sequence + M13 Adaptation Review +
   M14 PromptOps (imported · runtime-inert).
   M13: INACTIVE PREVIEW per console doctrine (doc 21 §2.4). M14: read-only
   mirror of the imported src/promptops/ objects — records, bindings, resolver,
   promotion check, meta-validation. No live action anywhere. Exported. */

const RM_TONE = {
  cyan: "var(--abi-evidence-cyan)", amber: "var(--abi-ops-amber)", green: "var(--abi-success-green)",
  violet: "var(--abi-governance-violet)", gold: "var(--abi-authority-gold)", red: "var(--abi-block-red)",
  neutral: "var(--abi-text-secondary)",
};
const PROP_STATE = {
  PROPOSED:     { tone: "cyan",    icon: "circle-dot",  label: "Proposed" },
  IN_REVIEW:    { tone: "violet",  icon: "search",      label: "In review" },
  APPROVED:     { tone: "green",   icon: "check",       label: "Approved" },
  BLOCKED:      { tone: "red",     icon: "ban",         label: "Blocked" },
  AUTO_APPLIED: { tone: "neutral", icon: "zap",         label: "Auto-applied" },
};

/* Shared inert banner — the explicit non-runtime status. */
function PreviewBanner({ mod, doctrine, status }) {
  return (
    <div className="rm-preview">
      <span className="rm-preview-tag"><Ic name="flask-conical" size={13} />Preview · {mod}</span>
      <span className="rm-preview-tx">{doctrine}</span>
      <span className="rm-preview-status"><span className="d" />{status || "Not active · documented-only"}</span>
    </div>
  );
}

/* ════════════════════════ M13 — ADAPTATION REVIEW ════════════════════════ */
function TenantModelBar() {
  const t = window.AFB.m13.tenant;
  return (
    <div className="rm-tom">
      <div className="rm-tom-l">
        <span className="p01-modtag"><Ic name="boxes" size={13} />Tenant Operating Model</span>
        <span className="rm-tom-name">{t.name}</span>
        <span className="rm-tom-mod mono">{t.modality}</span>
      </div>
      <div className="rm-tom-r">
        <div className="rm-tom-cell"><span className="abi-label">Version</span><span className="rm-tom-v">{t.omVersion}</span></div>
        <div className="rm-tom-cell"><span className="abi-label">Authority ceiling</span><span className="rm-tom-v tone-gold">{t.ceiling}</span></div>
        <div className="rm-tom-cell"><span className="abi-label">Rollback</span><span className="rm-tom-v mono">{t.rollback}</span></div>
      </div>
    </div>
  );
}

function RiskLadder() {
  return (
    <div className="rm-ladder">
      {window.AFB.m13.tiers.map((t) => (
        <div key={t.t} className="rm-tier" style={{ "--tc": RM_TONE[t.tone] }}>
          <div className="rm-tier-n">T{t.t}</div>
          <div className="rm-tier-body">
            <span className="rm-tier-label">{t.label}</span>
            <span className="rm-tier-gate">{t.gate}</span>
          </div>
          <span className={`rm-tier-auto ${t.auto === true ? "yes" : t.auto === "cond" ? "cond" : "no"}`}>
            {t.auto === true ? "auto" : t.auto === "cond" ? "conditional" : t.t === 4 ? "forbidden" : "approval"}
          </span>
        </div>
      ))}
    </div>
  );
}

function ProposalCard({ p }) {
  const ps = PROP_STATE[p.status];
  const tier = window.AFB.m13.tiers[p.tier];
  const vp = p.verdict ? verdictPres(p.verdict) : null;
  return (
    <div className="rm-prop" style={{ "--tc": RM_TONE[tier.tone] }}>
      <div className="rm-prop-hd">
        <div className="rm-prop-id">
          <span className="rm-prop-tier">T{p.tier}</span>
          <span className="rm-prop-kind mono">{p.kind}</span>
        </div>
        <span className={`rm-prop-state tone-${ps.tone}`}><Ic name={ps.icon} size={11} />{ps.label}</span>
      </div>
      <div className="rm-prop-ti">{p.title}</div>
      <div className="rm-prop-spec mono"><Ic name="file-code-2" size={12} />{p.spec}</div>
      <div className="rm-prop-rat"><Ic name="info" size={12} />{p.rationale}</div>
      <div className="rm-prop-foot">
        <span className="rm-prop-cond mono">{p.cond.replace(/_/g, " ")}</span>
        {vp ? <span className={`p01-verdict tone-${VTONE_BADGE[vp.tone]}`}><Ic name={vp.icon} size={11} />{vp.short}</span>
            : p.status === "IN_REVIEW" ? <span className="p01-verdict tone-violet"><span className="d pulse" />In review</span>
            : p.status === "AUTO_APPLIED" ? <span className="p01-verdict tone-green"><Ic name="check" size={11} />Logged · M10</span> : null}
        <span className="rm-prop-rb mono"><Ic name="rotate-ccw" size={10} />{p.rollback}</span>
        <span className="rm-prop-disabled" title="Inactive in preview"><Ic name="lock" size={11} />Review disabled</span>
      </div>
    </div>
  );
}

/* ════════════ POST-V1 SEQUENCE (docs-only record surface) ══════════ */
const SEQ_TONE = { cyan: "var(--abi-evidence-cyan)", gold: "var(--abi-authority-gold)", amber: "var(--abi-ops-amber)", violet: "var(--abi-governance-violet)", red: "var(--abi-block-red)", green: "var(--abi-success-green)", muted: "var(--abi-text-muted)" };
function SequenceView({ onNav }) {
  const d = window.AFB.postV1;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="p01-root rm-root">
      <div className="rm-closed">
        <div className="rm-closed-l">
          <span className="rm-closed-tag"><Ic name="badge-check" size={14} />{d.closed.label}</span>
          <span className="rm-closed-date mono">{d.closed.date}</span>
          <span className="rm-closed-ceil mono">{d.closed.ceiling}</span>
        </div>
        <span className="rm-closed-base">{d.closed.baseline}</span>
      </div>
      <p className="rm-closed-note">{d.closed.note}</p>

      <div className="rm-seq-grid">
        <div className="panelwrap">
          <div className="ov-phead">
            <span className="ov-ptitle"><Ic name="map" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />The sequence</span>
            <span className="ov-pmeta">recorded order · gates are never skipped</span>
          </div>
          <div className="rm-seq">
            {d.seq.map((s) => (
              <div key={s.n} className={`rm-step ${s.now ? "now" : ""} ${s.done ? "done" : ""}`} style={{ "--st": SEQ_TONE[s.tone] }}>
                <span className="rm-step-n">{s.done ? <Ic name="check" size={13} /> : s.n}</span>
                <div className="rm-step-main">
                  <div className="rm-step-hd">
                    <span className="rm-step-id mono">{s.id}</span>
                    <span className="rm-step-name">{s.name}</span>
                    {s.schema !== "—" && <span className="rm-step-schema mono">{s.schema}</span>}
                    {s.done && <span className="rm-step-built">BUILT</span>}
                    {s.now && <span className="rm-step-now">NEXT</span>}
                    {s.link && <button className="rm-step-link" onClick={() => onNav(s.link)}>{s.linkLabel || "preview"}<Ic name="arrow-right" size={11} /></button>}
                  </div>
                  <div className="rm-step-status">{s.status}</div>
                  <div className="rm-step-gate"><Ic name="lock" size={11} />{s.gate}</div>
                  {s.note && <div className="rm-step-note">{s.note}</div>}
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="rm-side">
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="layers" size={14} style={{ color: "var(--abi-authority-gold)" }} />Activation classes</span>
              <span className="ov-pmeta">surface map v1</span>
            </div>
            <div className="rm-classes">
              {d.classes.map((c) => (
                <div key={c.key} className="rm-class" style={{ "--ct": SEQ_TONE[c.tone] }}>
                  <div className="rm-class-hd"><span className="rm-class-key">{c.key}</span><span className="rm-class-desc">{c.desc}</span></div>
                  <div className="rm-class-items">{c.items}</div>
                </div>
              ))}
            </div>
          </div>
          <div className="panelwrap">
            <div className="ov-phead">
              <span className="ov-ptitle"><Ic name="inbox" size={14} style={{ color: "var(--abi-governance-violet)" }} />Product feature intake</span>
              <span className="ov-pmeta">docs-only · not active</span>
            </div>
            <div className="rm-intake">
              {d.featureIntake.items.map((f) => (
                <div key={f.name} className="rm-int">
                  <div className="rm-int-hd"><span className="rm-int-name">{f.name}</span><span className="rm-int-state">ROADMAP CANDIDATE</span></div>
                  <div className="rm-int-fit"><Ic name="puzzle" size={11} />{f.fit}</div>
                </div>
              ))}
              <div className="rm-int-note">{d.featureIntake.note}</div>
            </div>
          </div>
          <div className="rm-law">
            <Ic name="scale" size={13} />
            <p>The interface prototype does not outrank the substrate. Product surface does not become ontology. A panel does not imply runtime capability.</p>
          </div>
        </div>
      </div>
    </div>
  );
}

function M13View() {
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="p01-root rm-root">
      <PreviewBanner mod="M13 · Tenant Adaptation"
        doctrine="The console adapts per tenant under governance — every change is a risk-tiered proposal on the review rail, never a silent edit. The system may propose; it may never promote itself." />
      <div className="rm-law">
        <Ic name="git-merge" size={13} />
        <p><b>Trajectory:</b> these per-module adaptation proposals are slated to ride one unifying substrate — <b>M18 · Adaptive Configuration Spine</b> (v0.1 proposal + second-round merge docs, still spec-only). One <span className="mono">AdaptiveConfigObject</span> envelope on the existing M04→M07→M08→M10→M11 pipeline, never a parallel authority plane. M13 stays documented-only until M18 is ratified and gated rung by rung.</p>
      </div>
      <TenantModelBar />
      <div className="rm-block">
        <div className="ov-phead p01-feed-head">
          <span className="ov-ptitle"><Ic name="bar-chart-3" size={14} style={{ color: "var(--abi-ops-amber)" }} />Adaptation risk ladder</span>
          <span className="ov-pmeta">exposure rises → governance rises</span>
        </div>
        <RiskLadder />
      </div>
      <div className="rm-block">
        <div className="ov-phead p01-feed-head">
          <span className="ov-ptitle"><Ic name="git-pull-request-arrow" size={14} style={{ color: "var(--abi-governance-violet)" }} />Adaptation proposals</span>
          <span className="ov-pmeta">desired-state changes · never active config</span>
        </div>
        <div className="rm-prop-grid">
          {window.AFB.m13.proposals.map((p) => <ProposalCard key={p.id} p={p} />)}
        </div>
      </div>
    </div>
  );
}

/* ════ M14 — PROMPTOPS (architecture IMPORTED 2026-07-07 · runtime-inert) ════ */
function M14CeilStrip() {
  const d = window.AFB.m14;
  return (
    <div className="rm-ceil">
      <div className="rm-ceil-hd"><Ic name="shield" size={13} /><span>Claim ceilings — imported, runtime-inert. Real code in <span className="mono">{d.src}</span> ({d.bugfixAudit}); none of it can execute.</span></div>
      <div className="rm-ceil-row">
        <span className="rm-ceil-tok mono" title={window.AFB.gloss(d.ceiling)}>{d.ceiling}</span>
        <span className="rm-ceil-tok mono" title={window.AFB.gloss(d.linkedCeiling)}>{d.linkedCeiling}</span>
      </div>
    </div>
  );
}

function M14Records() {
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="file-code-2" size={14} style={{ color: "var(--abi-evidence-cyan)" }} />Prompt version records</span>
        <span className="ov-pmeta">content-addressed · records, not runs</span>
      </div>
      <div className="rm-pvr-list">
        {window.AFB.m14.versionRecords.map((r) => (
          <div key={r.id} className="rm-pvr">
            <div className="rm-pvr-hd">
              <span className="rm-pvr-id mono">{r.id}</span>
              <span className="rm-pvr-fam mono">{r.family}</span>
              <span className="rm-pvr-st">{r.status}</span>
            </div>
            <div className="rm-pvr-hashes">
              <span className="rm-hash mono"><Ic name="hash" size={10} />prompt {r.promptHash}</span>
              <span className="rm-hash mono"><Ic name="braces" size={10} />schema {r.schemaHash}</span>
            </div>
            <div className="rm-pvr-note">{r.note}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

function M14Bindings() {
  const d = window.AFB.m14;
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="anchor" size={14} style={{ color: "var(--abi-governance-violet)" }} />Bindings &amp; fail-closed resolver</span>
        <span className="ov-pmeta">{d.resolver.store}</span>
      </div>
      <div className="rm-pvr-list">
        {d.bindings.map((b) => (
          <div key={b.id} className="rm-pvr">
            <div className="rm-pvr-hd">
              <span className="rm-pvr-id mono">{b.id}</span>
              <span className="rm-pvr-fam mono">{b.family}</span>
              <span className="rm-pvr-st">BOUND</span>
            </div>
            <div className="rm-pvr-note"><b>{b.role}</b> → {b.pins}. {b.note}</div>
          </div>
        ))}
        <div className="rm-resolve">
          <div className="rm-resolve-hd"><Ic name="git-merge" size={12} />Resolution — {d.resolver.law}</div>
          {d.resolver.rows.map((r, i) => (
            <div key={i} className={`rm-res ${r.tone}`}>
              <span className="rm-res-q mono">{r.q}</span>
              <span className="rm-res-out">{r.out}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function M14Promotion() {
  const d = window.AFB.m14.promotion;
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="git-compare-arrows" size={14} style={{ color: "var(--abi-authority-gold)" }} />Promotion check</span>
        <span className="ov-pmeta">a pass promotes nothing</span>
      </div>
      <div className="rm-kv-list">
        {d.checks.map((c) => (
          <div key={c.k} className={`rm-kv ${c.warn ? "warn" : ""}`}>
            <span className="rm-kv-k">{c.k}</span>
            <span className="rm-kv-v">{c.v}</span>
          </div>
        ))}
      </div>
      <div className="rm-kv-note">{d.note}</div>
    </div>
  );
}

function M14Meta() {
  const d = window.AFB.m14.metaValidation;
  return (
    <div className="panelwrap">
      <div className="ov-phead">
        <span className="ov-ptitle"><Ic name="clipboard-check" size={14} style={{ color: "var(--abi-ops-amber)" }} />Meta-validation</span>
        <span className="ov-pmeta">reads recorded heads · reports only</span>
      </div>
      <div className="rm-kv-list">
        {d.objects.map((o) => (
          <div key={o.k} className={`rm-kv ${o.warn ? "warn" : ""}`}>
            <span className="rm-kv-k">{o.k}</span>
            <span className="rm-kv-v">{o.v}</span>
          </div>
        ))}
      </div>
      <div className="rm-kv-note">{d.note}</div>
    </div>
  );
}

function M14View() {
  const d = window.AFB.m14;
  useEffect(() => { if (window.lucide) window.lucide.createIcons(); });
  return (
    <div className="p01-root rm-root">
      <PreviewBanner mod="M14 · PromptOps" status={`Runtime-inert · imported ${d.importedAt}`}
        doctrine="Governed prompt versioning as imported architecture — version records, binding records, deterministic prompt/schema hashing, a fail-closed resolver and a promotion check. Objects and proofs, not runs: no live LLM call exists behind this screen." />
      <M14CeilStrip />
      <div className="rm-doct">
        {d.doctrine.map((l) => <span key={l} className="rm-doct-item"><Ic name="minus" size={11} />{l}</span>)}
      </div>
      <div className="rm-m14-grid">
        <div className="rm-m14-col">
          <M14Records />
          <M14Bindings />
        </div>
        <div className="rm-m14-col">
          <M14Promotion />
          <M14Meta />
        </div>
      </div>
      <div className="rm-lanes">
        {d.proofLanes.map((p) => (
          <div key={p.cmd} className="rm-lane mono"><Ic name="terminal" size={11} />pnpm {p.cmd}<span className="rm-lane-g">{p.g}</span></div>
        ))}
      </div>
      <div className="rm-law">
        <Ic name="git-merge" size={13} />
        <p><b>Trajectory:</b> PromptOps remains one family in the proposed <b>M18 · Adaptive Configuration Spine</b> — the <span className="mono">PROMPT_BINDING_PROFILE</span> config family. Import does not change that sequencing: a variant still earns its own version and promotion record, and promotion remains a future owner act.</p>
      </div>
    </div>
  );
}

Object.assign(window, { M13View, M14View, SequenceView });
