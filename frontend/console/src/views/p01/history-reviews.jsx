import { projectHistoryReview,projectTemporalMemory } from "../../data/temporal-memory-view-model.js";
import { BoundaryNote,StatusBadge } from "../../primitives/index.jsx";

/**
 * UI-01: unregistered, metadata-only operator preview. No active route.
 * Owner-authenticated read props must be supplied explicitly; no fetch,
 * retry, timers, model calls, source text or release actions live here.
 */
const messages={
  NO_ACTIVE_WORKSPACE:"Select an authorized workspace.",
  DENIED:"The current evidence owner denied this read.",
  NOT_FOUND:"No eligible history matches this scoped selector.",
  UNAVAILABLE:"Metadata is not available; this does not mean no change."
};
function EmptyOrDenied({view,emptyMessage}){
  return <p role="status">{view.status==="EMPTY"?emptyMessage:
    (messages[view.status]??messages.UNAVAILABLE)}</p>;
}
export function HistoricalReviewPreview({
  review=null,memory=null,onMemoryNextPage=null
}){
  const bases=projectHistoryReview(review);
  const temporal=projectTemporalMemory(memory);
  return <section className="surface-page p01-view" aria-label="Historical review and memory">
    <header className="surface-head"><div>
      <div className="surface-kicker">
        <StatusBadge tone="cyan">P01</StatusBadge>
        <span>Inert candidate preview · scoped metadata only</span>
      </div>
      <h1>Historical review</h1>
      <p>Point-in-time evidence, due decisions and derived memory candidates; source eligibility and release are not admitted.</p>
    </div></header>

    <section aria-label="Point-in-time review bases" className="surface-panel">
      <h2>Evidence and due history</h2>
      {bases.status==="READY"?
        <ol className="trace-list">{bases.rows.map(row=><li key={row.id}>
          <StatusBadge tone={row.due==="HOLD_CURRENT_RIGHTS"?"amber":"neutral"}>
            {row.due}
          </StatusBadge>
          <p>Frozen basis <code>{row.id}</code></p>
          <p>{row.selected} selected · {row.excluded} excluded references</p>
          <p>Memory head: <code>{row.head}</code>. Rights: {row.rights}.</p>
        </li>)}</ol>:
        <EmptyOrDenied view={bases} emptyMessage="No eligible review basis metadata is available. No change is not inferred."/>
      }
    </section>

    <section aria-label="Temporal memory" className="surface-panel">
      <h2>Temporal memory</h2>
      {temporal.status==="READY"?
        <>
          <ol className="trace-list">{temporal.rows.map(row=><li key={row.id}>
            <StatusBadge tone={row.head==="AMBIGUOUS_HEAD_HOLD"?"amber":"neutral"}>
              {row.head}
            </StatusBadge>
            <p><code>{row.dataClass}</code> · {row.window} · {row.from}–{row.to}</p>
            <p>Memory <code>{row.id}</code></p>
            <p>{row.references} references, including {row.contrary} contrary/conflicted.</p>
            <p>Replay: <code>{row.replay}</code>. Source rights: {row.rights}.</p>
          </li>)}</ol>
          {temporal.nextCursor&&typeof onMemoryNextPage==="function"?
            <button type="button" onClick={()=>onMemoryNextPage(temporal.nextCursor)}>
              View next memory page
            </button>:null}
        </>:
        <EmptyOrDenied view={temporal} emptyMessage="No scoped temporal memory candidate metadata is available. Missing records are not an absence-of-evidence proof."/>
      }
    </section>
    <BoundaryNote title="Metadata is not a finding or permission">
      A memory candidate is not primary evidence. A due decision is not a model grant.
      Missing or revoked source rights block reuse. No automated analysis, notification or customer release is available here.
    </BoundaryNote>
  </section>;
}

export { HistoryReviewWorkbenchPreview } from "./history-review-workbench.jsx";
