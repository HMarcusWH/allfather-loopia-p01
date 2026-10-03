import { useState } from "react";
import { ReadPanel } from "../../app/read-panel.jsx";
import { Collection } from "../../collections/collection.jsx";
import { READS } from "../../data/surface-reads.js";
import { workbenchHref } from "../../data/monitor-links.js";
import { BoundaryNote, StatusBadge } from "../../primitives/index.jsx";

const AWAITING=Object.freeze({
  HUMAN_REVIEW:["amber","Waiting for a human review"],
  REQUEST_INSPECTION:["amber","Waiting for request inspection"],
  OPERATOR_STEP:["cyan","Waiting for an operator step"],
  NOTHING:["neutral","Nothing pending"]
});

function WorkbenchLink({href,children="Open in the workbench"}){
  const safe=workbenchHref(href);
  return safe?<a href={safe}>{children}</a>:null;
}

function RunsBody({data,onSelect}){
  const rows=(data?.rows??[]).map(row=>({...row,inspect:<button type="button" className="link-button" onClick={()=>onSelect(row.monitor_run_id)}>Show metadata</button>,open:<WorkbenchLink href={row.workbench_href}/>}));
  const queue=data?.scheduled_queue??[];
  return <>
    {queue.length?<div className="read-summary"><strong>Scheduled observations waiting for you</strong><p>A schedule captured and compared these sources and found a change. Nothing was sent to a model and nothing was released.</p>
      <ul className="plain-list">{queue.map(item=><li key={item.monitor_run_id}><code>{item.monitor_run_id}</code> · scheduled for {item.scheduled_for} · missed slots {item.skipped_slots} · <WorkbenchLink href={item.workbench_href}>Inspect the request</WorkbenchLink></li>)}</ul></div>:null}
    <Collection rows={rows} columns={[...(data?.columns??[]),{key:"inspect",label:"Metadata"},{key:"open",label:"Workbench"}]}/>
  </>;
}

function RunBody({data}){
  const run=data?.run??{};
  const [tone,label]=AWAITING[data?.awaiting]??AWAITING.NOTHING;
  const admission=data?.execution_admission??{};
  return <div className="monitor-run-detail">
    <p><StatusBadge tone="cyan">{run.stage??"UNKNOWN"}</StatusBadge> <StatusBadge tone={tone}>{label}</StatusBadge> <span className="mono">{run.execution_mode}</span> · journal integrity <StatusBadge tone={data?.integrity==="PASS"?"green":"red"}>{data?.integrity??"UNKNOWN"}</StatusBadge></p>
    {run.started_by_scheduler?<p>Started by an admitted schedule; every model step still needs a human.</p>:null}
    {run.reason_code?<p>Stopped with <code>{run.reason_code}</code>.</p>:null}
    {admission.required?<p>Native execution: {admission.admitted?"admitted until "+(admission.valid_until??"—"):"refused — "+(admission.reason_code??"not admitted")}</p>:null}
    <h3>History</h3>
    <ol className="trace-list">{(data?.events??[]).map(e=><li key={e.sequence_no}><StatusBadge tone={e.actor?.type==="SERVICE"?"violet":"neutral"}>{e.actor?.type==="SERVICE"?"Scheduler":"Operator"}</StatusBadge><code>{e.from_stage} → {e.to_stage}</code><span>{e.event_kind} · {e.recorded_at}</span></li>)}</ol>
    <h3>Recorded artifacts</h3>
    {(data?.artifacts??[]).length?<ul className="plain-list">{data.artifacts.map(a=><li key={a.digest}><code>{a.kind}</code> <span className="mono">{a.digest}</span></li>)}</ul>:<p>None yet.</p>}
    {Object.keys(data?.current_reliance??{}).length?<><h3>Current reliance</h3><ul className="plain-list">{Object.entries(data.current_reliance).map(([k,v])=><li key={k}>{k.replaceAll("_"," ")}: <StatusBadge tone={v==="ACTIVE_AS_RECORDED"?"green":"amber"}>{String(v)}</StatusBadge></li>)}</ul></>:null}
    <p><WorkbenchLink href={data?.workbench_href}>Inspect the exact request, draft and review in the workbench</WorkbenchLink></p>
  </div>;
}

export function MonitorRunsView({datasource}){
  const [selected,setSelected]=useState("");
  return <div className="surface-page p01-view">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="cyan">P01</StatusBadge><span>Monitor runs · metadata only</span></div><h1>Monitor runs</h1><p>Where each run is, who moved it and what is waiting for you. Exact requests, drafts and both human reviews are read and decided in the scriptless workbench.</p></div></header>
    <p><a href="/console/monitor">Open the Monitor workbench</a></p>
    <div className="owner-grid">
      <ReadPanel datasource={datasource} readId={READS.MONITOR_RUNS} title="Runs in this workspace" icon="history" className="span-2">{data=><RunsBody data={data} onSelect={setSelected}/>}</ReadPanel>
      <ReadPanel datasource={datasource} readId={READS.MONITOR_RUN} title="Selected run" icon="eye" className="span-2" query={{run:selected}} enabled={selected!==""}>{data=><RunBody data={data}/>}</ReadPanel>
    </div>
    <BoundaryNote title="Reading is not acting">Opening this page fetches no source, calls no model and releases nothing. Nothing here retries or advances a run; every step is a deliberate form in the workbench.</BoundaryNote>
  </div>;
}
