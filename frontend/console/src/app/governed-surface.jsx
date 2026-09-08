import { BoundaryNote, MetricCard, Panel, StatusBadge } from "../primitives/index.jsx";
import { Collection } from "../collections/collection.jsx";
import { PROJECTION } from "../data/response-types.js";
import { useConsoleRead } from "./use-console-read.js";

function ProjectionState({ envelope, title }) {
  const state = envelope.projection?.state;
  if (state === PROJECTION.LOADING) return <div className="state-card"><StatusBadge tone="cyan">Loading</StatusBadge><p>Loading {title} projection.</p></div>;
  if (envelope.access?.decision === "DENIED") return <div className="state-card"><StatusBadge tone="red">Denied</StatusBadge><p>The governed owner denied this read. Frontend state cannot override it.</p></div>;
  if (state === PROJECTION.UNAVAILABLE) return <div className="state-card"><StatusBadge tone="amber">Unavailable</StatusBadge><p>This Phase-1 production build has no product read adapter. No fixture fallback is permitted.</p><span className="reason-code mono">{(envelope.reason_codes ?? []).join(" · ")}</span></div>;
  if (state === PROJECTION.ERROR) return <div className="state-card"><StatusBadge tone="red">Error</StatusBadge><p>The projection failed closed.</p></div>;
  if (state === PROJECTION.EMPTY) return <div className="state-card"><StatusBadge>Empty</StatusBadge><p>The governed read succeeded and returned no rows.</p></div>;
  return null;
}

export function GovernedSurface({ datasource, surfaceId, title, subtitle, owner, plane, icon, boundary }) {
  const envelope = useConsoleRead(datasource, surfaceId);
  const blocked = envelope.projection?.state !== PROJECTION.READY;
  const data = envelope.data ?? {};
  const ownerState = envelope.owner_state;
  return <div className="surface-page">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone={plane === "SOURCES" ? "cyan" : plane === "GOVERNANCE" ? "violet" : plane === "CORE" ? "gold" : "amber"}>{plane}</StatusBadge><span className="mono">owner {owner}</span></div><h1>{title}</h1><p>{subtitle}</p></div><StatusBadge tone={envelope.projection?.stale_status === "STALE" ? "amber" : "neutral"}>{envelope.projection?.stale_status ?? "UNKNOWN"}</StatusBadge></header>
    {blocked ? <ProjectionState envelope={envelope} title={title} /> : <>
      {ownerState ? <div className="owner-state"><StatusBadge tone={["BLOCKED", "HOLD", "QUARANTINE", "APPROVAL_REQUIRED"].includes(ownerState) ? "amber" : "neutral"}>Owner state · {ownerState}</StatusBadge></div> : null}
      {data.metrics?.length ? <div className="metrics-grid">{data.metrics.map((m) => <MetricCard key={m.label} {...m} />)}</div> : null}
      {data.summary ? <Panel title={data.summary.title ?? "Projection"} eyebrow={owner} icon={icon} meta={data.summary.meta}><p>{data.summary.body}</p></Panel> : null}
      {data.rows ? <Panel title={data.tableTitle ?? "Read model"} eyebrow="Regenerable projection" icon={icon} meta={`${data.rows.length} rows`}><Collection rows={data.rows} columns={data.columns ?? []} /></Panel> : null}
      {data.trace?.length ? <Panel title="Trace" eyebrow="Evidence / authority path" icon="waypoints"><ol className="trace-list">{data.trace.map((step) => <li key={step.id}><span className="trace-node"><StatusBadge tone={step.tone ?? "neutral"}>{step.owner}</StatusBadge><strong>{step.id}</strong><span>{step.label}</span></span></li>)}</ol></Panel> : null}
    </>}
    {boundary ? <BoundaryNote title={boundary.title} tone={boundary.tone}>{boundary.body}</BoundaryNote> : null}
  </div>;
}
