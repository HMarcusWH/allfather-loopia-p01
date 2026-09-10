import { Panel, StatusBadge, MetricCard } from "../primitives/index.jsx";
import { Collection } from "../collections/collection.jsx";
import { PROJECTION } from "../data/response-types.js";
import { readMeta } from "../data/surface-reads.js";
import { useConsoleRead } from "./use-console-read.js";

function ReadState({env,meta}){
  const state=env.projection?.state;
  const reasons=env.reason_codes??env.access?.reason_codes??[];
  const requiredPermission=env.access?.required_permission??null;

  if(state===PROJECTION.LOADING){
    return <div className="read-state"><StatusBadge tone="cyan">Loading</StatusBadge><span>{meta.owner} projection</span></div>;
  }
  if(env.access?.decision==="DENIED"){
    return <div className="read-state">
      <StatusBadge tone="red">Denied</StatusBadge>
      <span>Owner gate denied this read. Frontend state cannot override it.</span>
      {requiredPermission?<code>requires: {requiredPermission}</code>:null}
      {reasons.length?<code>{reasons.join(" · ")}</code>:null}
    </div>;
  }
  if(state===PROJECTION.UNAVAILABLE){
    return <div className="read-state"><StatusBadge tone="amber">Unavailable</StatusBadge><span>{meta.status.replaceAll("_"," ")}</span><code>{reasons.join(" · ")}</code></div>;
  }
  if(state===PROJECTION.ERROR){
    return <div className="read-state"><StatusBadge tone="red">Error</StatusBadge><span>Projection failed closed.</span></div>;
  }
  if(state===PROJECTION.EMPTY){
    return <div className="read-state"><StatusBadge>Empty</StatusBadge><span>Governed read succeeded with no rows.</span></div>;
  }
  return null;
}

function DefaultBody({data}){
  return <>
    {data?.metrics?.length?<div className="metrics-grid compact">{data.metrics.map(m=><MetricCard key={m.label}{...m}/>)}</div>:null}
    {data?.summary?<div className="read-summary"><strong>{data.summary.title}</strong><p>{data.summary.body}</p></div>:null}
    {data?.rows?<Collection rows={data.rows} columns={data.columns??[]}/>:null}
    {data?.trace?.length?<ol className="trace-list">{data.trace.map(x=><li key={x.id}><StatusBadge tone={x.tone??"neutral"}>{x.owner}</StatusBadge><code>{x.id}</code><span>{x.label}</span></li>)}</ol>:null}
  </>;
}

export function ReadPanel({datasource,readId,title,eyebrow,icon="panels-top-left",metaText,children,className="",query={},enabled=true}){
  const env=useConsoleRead(datasource,readId,query,enabled);
  const meta=readMeta(readId);
  const state=env.projection?.state;
  const renderBody=state===PROJECTION.READY || (state===PROJECTION.EMPTY && env.data?.summary);

  return <div className={className}>
    <Panel title={title} eyebrow={eyebrow??meta.owner} icon={icon} meta={metaText??meta.status}>
      <ReadState env={env} meta={meta}/>
      {renderBody?(children?children(env.data,env):<DefaultBody data={env.data}/>):null}
    </Panel>
  </div>;
}
