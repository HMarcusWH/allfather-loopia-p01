import { Icon } from "./icons.jsx";
import { StatusBadge } from "./status.jsx";
export function Panel({ title, eyebrow, icon = "panels-top-left", meta, children }) {
  return <section className="panel">
    <header className="panel-header">
      <div><span className="panel-eyebrow">{eyebrow}</span><h2><Icon name={icon} />{title}</h2></div>
      {meta ? <span className="panel-meta mono">{meta}</span> : null}
    </header>
    <div className="panel-body">{children}</div>
  </section>;
}
export function MetricCard({ label, value, note, tone = "neutral", icon = "panels-top-left" }) {
  return <article className="metric-card" data-tone={tone}><div className="metric-label"><Icon name={icon} />{label}</div><div className="metric-value">{value}</div><p>{note}</p></article>;
}
export function BoundaryNote({ title, children, tone = "amber" }) {
  return <div className="boundary-note" data-tone={tone}><StatusBadge tone={tone}>Boundary</StatusBadge><strong>{title}</strong><p>{children}</p></div>;
}
