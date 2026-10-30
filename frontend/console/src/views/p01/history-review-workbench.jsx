import { projectHistoryWorkbench,availableProductJourney } from "../../data/history-review-workbench-view-model.js";
import { BoundaryNote,StatusBadge } from "../../primitives/index.jsx";

/**
 * UI-02: unregistered operator fixture view.
 * No client fetch, POST, timer or release. The server controller owns CSRF
 * and the exact revision/digest review-work proposal interface.
 */
export function HistoryReviewWorkbenchPreview({
  read=null,onNextPage=null,adapterReadIds=[],permissionKeys=[]
}){
  const view=projectHistoryWorkbench(read);
  const journey=availableProductJourney({adapterReadIds,permissionKeys});
  return <section className="surface-page p01-view" aria-label="Historical review workbench">
    <header className="surface-head">
      <div>
        <div className="surface-kicker">
          <StatusBadge tone="cyan">P01</StatusBadge>
          <span>Source-bound operator metadata preview</span>
        </div>
        <h1>Historical review</h1>
        <p>Analyst, critic, contradictory sources and review states remain unreleased.</p>
      </div>
    </header>
    <section className="surface-panel" aria-label="Review candidates">
      <h2>Human review required</h2>
      {view.status!=="READY"
        ?<p role="status">{view.status==="EMPTY"
          ?"No candidate on this scoped page; absence of change is not inferred."
          :view.reason}</p>
        :<>
          <ol className="trace-list">{view.rows.map(row=>
            <li key={row.revision}>
              <h3>Review <code>{row.reviewId}</code></h3>
              <StatusBadge tone="amber">{row.status}</StatusBadge>
              <p>As-of: <code>{row.basis}</code> · cutoff {row.cutoff}</p>
              <p>Analyst: <code>{row.analyst}</code> · critic: <code>{row.critic}</code></p>
              <p>{row.contrary} contrary refs. Outcome: {row.outcome} ({row.adjudication}).</p>
              <p>Attention: {row.attention}. Due: {row.due}.</p>
              <details>
                <summary>Inspect {row.refs.length} exact source references</summary>
                <ol>{row.refs.map(ref=><li key={ref.id}>
                  <code>{ref.id}</code> · <code>{ref.source}</code> · {ref.polarity} · {ref.knownAt}
                  {ref.support?" (cited as support)":""}
                </li>)}</ol>
              </details>
              <p>Revision <code>{row.revision}</code></p>
              <p>Factual approval: no. Delivery approval: no. No authorized actions.</p>
            </li>)}</ol>
          {view.nextPage!==null&&typeof onNextPage==="function"
            ?<button type="button" onClick={()=>onNextPage(view.nextPage)}>
              Next review page
            </button>:null}
        </>}
    </section>
    <section className="surface-panel" aria-label="Existing product read journey">
      <h2>Product read journey</h2>
      <p>Every section uses its existing owner; a missing permission is not an empty dataset.</p>
      <ul>{journey.map(p=><li key={p.id}>
        <strong>{p.label}</strong>: {p.state==="OWNER_READ_WIRED"
          ?"Owner read available under current permissions"
          :"Unavailable or denied; no substitute data"}
      </li>)}</ul>
    </section>
    <BoundaryNote title="Review does not grant release">
      These synthetic metadata candidates are not current authenticated source-use,
      factual human review, M10 reliance, P04 distribution, or customer output.
      This view is not registered as a live route.
    </BoundaryNote>
  </section>;
}
