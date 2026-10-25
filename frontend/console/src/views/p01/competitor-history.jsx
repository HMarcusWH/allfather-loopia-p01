import { projectCompetitorHistory } from "../../data/competitor-history-view-model.js";
import { BoundaryNote, StatusBadge } from "../../primitives/index.jsx";

/**
 * LI-16 inert component: NOT imported by app navigation or any live route.
 * A future authorized operator read may pass an already-scoped adapter result.
 * No effect, fetch, timer, prefetch or background retry exists in this module.
 */
export function CompetitorHistoryPreview({history=null,onNextPage=null}){
  const view=projectCompetitorHistory(history);
  const state={
    NO_ACTIVE_WORKSPACE:"Select an authorized workspace to inspect history.",
    DENIED:"History access denied by the evidence owner.",
    NOT_FOUND:"No accessible competitor history matches this identifier.",
    UNAVAILABLE:"History metadata is not available."
  };
  return <section className="surface-page p01-view" aria-label="Competitor capture history">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="cyan">P01</StatusBadge><span>Inert history preview · metadata only</span></div><h1>Competitor capture history</h1><p>Previously retained captures and structural comparisons, subject to current permission and source-rights checks.</p></div></header>
    {view.state==="READY"||view.state==="EMPTY"?<>
      <div className="read-summary"><h2>{view.competitor.name}</h2><p><StatusBadge tone="neutral">{view.competitor.status}</StatusBadge> Only permitted metadata is shown. Business materiality has not been assessed.</p></div>
      {view.state==="EMPTY"?<p>No currently eligible captured history is available. This is not evidence that the competitor was unchanged.</p>:<ol className="trace-list">{view.rows.map(row=><li key={row.id}><StatusBadge tone={row.label==="Comparison unavailable"?"amber":"neutral"}>{row.label}</StatusBadge><div><strong>{row.capturedAt}</strong> · <code>{row.sourceKind}</code> · capture #{row.sequence}</div><code>{row.id}</code><p>Business materiality: {row.materiality}. Review: {row.review}. Release: {row.release}.</p>{row.comparisons.length?<ul className="plain-list">{row.comparisons.map(c=><li key={c.id}><span>{c.label}</span> · <code>{c.id}</code></li>)}</ul>:null}</li>)}</ol>}
      {view.nextCursor&&typeof onNextPage==="function"?<button type="button" onClick={()=>onNextPage(view.nextCursor)}>View next history page</button>:null}
    </>:<p role="status">{state[view.state]??state.UNAVAILABLE}</p>}
    <BoundaryNote title="Capture history is not an intelligence release">Structural difference is not commercial materiality. Missing or revoked source access does not mean no change. This view cannot crawl, infer, approve, notify or release.</BoundaryNote>
  </section>;
}
