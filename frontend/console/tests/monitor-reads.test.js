import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { HttpConsoleDataSource, LIVE_READ_IDS } from "../src/data/http-console-data-source.js";
import { READS, readMeta } from "../src/data/surface-reads.js";
import { PROJECTION } from "../src/data/response-types.js";
import { READ_TRANSPORT, transportPolicyFor } from "../src/data/http-client.js";
import { usableNavigationIdsForPermissions, STATIC_NAV_IDS } from "../src/data/navigation.js";
import { workbenchHref } from "../src/data/monitor-links.js";

const RUN="0f8b3c2e-1d4a-4b6c-8e9f-0a1b2c3d4e5f";
function jsonResponse(status,payload){return {status,headers:{get:(name)=>name.toLowerCase()==="content-type"?"application/json; charset=utf-8":null},json:async()=>payload};}
function envelope(source,data,state="READY",decision="VISIBLE",owner="VISIBLE"){return {source,as_of:null,access:{decision,required_permission:"mission.read",reason_codes:["GRANTED"]},projection:{state,stale_status:"CURRENT"},owner_state:owner,reason_codes:["GRANTED"],data};}
function recorder(status,payload){const calls=[];return {calls,ds:new HttpConsoleDataSource({fetchImpl:async(url,options)=>{calls.push({url,options});return jsonResponse(status,payload);}})};}

test("P09 adds exactly four reads: two product details and two Monitor metadata reads",()=>{
  for(const id of [READS.P02_ENTITY_DETAIL,READS.P03_PROSPECT_DETAIL,READS.MONITOR_RUNS,READS.MONITOR_RUN]) assert.ok(LIVE_READ_IDS.includes(id),id);
  assert.equal(LIVE_READ_IDS.length,17,"P09 + P02 draft + P01 watchlist + P03 owned ICP/offers");
  assert.ok(LIVE_READ_IDS.includes(READS.P02_OWN_DRAFT),"unreleased first-party draft is a distinct guarded endpoint");
  assert.equal(LIVE_READ_IDS.includes(READS.P03_BATTLECARDS),false,"there is still no prospect-battlecard list read");
});

test("each detail read forwards only its own selector, never caller scope",async()=>{
  for(const [readId,key,path] of [[READS.P02_ENTITY_DETAIL,"snapshot","/console/api/v1/p02/entity-detail"],[READS.P03_PROSPECT_DETAIL,"output","/console/api/v1/p03/prospect-detail"],[READS.MONITOR_RUN,"run","/console/api/v1/monitor/run"]]){
    const {calls,ds}=recorder(200,envelope(readId,{}));
    await ds.readDescriptor(readId,{[key]:RUN,tenant_id:"attacker",workspace_id:"other",operator_id:"spoof",visibility_scope:"SALES"});
    assert.equal(calls.length,1);
    assert.equal(calls[0].url,`${path}?${key}=${RUN}`,readId);
    assert.equal(calls[0].options.method,"GET");
    assert.equal(calls[0].options.credentials,"same-origin");
    assert.equal(calls[0].options.redirect,"manual");
  }
  const {calls,ds}=recorder(200,envelope(READS.MONITOR_RUNS,{rows:[],scheduled_queue:[]},"EMPTY"));
  await ds.readDescriptor(READS.MONITOR_RUNS,{run:RUN,tenant_id:"attacker"});
  assert.equal(calls[0].url,"/console/api/v1/monitor/runs","the runs list takes no parameter at all");
});

test("a 404 detail answer is a governed NOT_FOUND, distinct from denied and from a transport failure",async()=>{
  const notFound=envelope(READS.MONITOR_RUN,null,"EMPTY","VISIBLE","NOT_FOUND");
  notFound.reason_codes=["MONITOR_RUN_NOT_FOUND"];
  const {ds}=recorder(404,notFound);
  const result=await ds.readDescriptor(READS.MONITOR_RUN,{run:RUN});
  assert.equal(result.owner_state,"NOT_FOUND");
  assert.equal(result.access.decision,"VISIBLE");
  assert.equal(result.projection.state,PROJECTION.EMPTY);
  assert.equal(result.data,null);
  const denied=recorder(403,envelope(READS.MONITOR_RUN,null,"UNAVAILABLE","DENIED","DENIED"));
  assert.equal((await denied.ds.readDescriptor(READS.MONITOR_RUN,{run:RUN})).access.decision,"DENIED");
});

test("the runs read keeps the human queue beside its rows",async()=>{
  const {ds}=recorder(200,envelope(READS.MONITOR_RUNS,{rows:[{monitor_run_id:RUN,stage:"DIFFED"}],scheduled_queue:[{monitor_run_id:RUN,scheduled_for:"2026-10-01T00:00:00.000Z"}]}));
  const result=await ds.readDescriptor(READS.MONITOR_RUNS);
  assert.equal(result.data.rows[0].stage,"DIFFED");
  assert.equal(result.data.scheduled_queue.length,1);
  assert.ok(result.data.columns.some(c=>c.key==="stage"));
});

test("Monitor and detail reads are audited display: no prefetch and never a transparent retry",()=>{
  for(const id of [READS.P02_ENTITY_DETAIL,READS.P03_PROSPECT_DETAIL,READS.MONITOR_RUNS,READS.MONITOR_RUN]){
    assert.deepEqual(transportPolicyFor(readMeta(id)),READ_TRANSPORT.AUDITED_DISPLAY,id);
    assert.equal(readMeta(id).status,"OWNER_READ_WIRED",id);
  }
});

test("the workbench link guard follows only same-origin Monitor run paths",()=>{
  assert.equal(workbenchHref(`/console/monitor?run=${RUN}`),`/console/monitor?run=${RUN}`);
  for(const bad of ["javascript:alert(1)","//evil.example/console/monitor?run="+RUN,"https://evil.example/","/console/monitor?run=x","/console/app?view=p01",`/console/monitor?run=${RUN}&action=stop`,null,42]) assert.equal(workbenchHref(bad),null,String(bad));
});

test("Monitor runs is navigable only with mission.read, and grants nothing",()=>{
  assert.ok(STATIC_NAV_IDS.includes("monitor-runs"));
  assert.deepEqual(usableNavigationIdsForPermissions(["mission.read"]),["monitor-runs"]);
  assert.deepEqual(usableNavigationIdsForPermissions(["evidence.read"]),[]);
});

test("the React Monitor view reads and links; it never mutates or retries",()=>{
  const view=readFileSync(new URL("../src/views/p01/monitor-runs.jsx",import.meta.url),"utf8");
  for(const forbidden of ["fetch(","method:","\"POST\"","<form","setInterval","setTimeout","request_digest","candidate_digest","assessment"]) assert.equal(view.includes(forbidden),false,forbidden);
  assert.match(view,/workbenchHref/);
  for(const file of ["../src/views/p02/entity-context.jsx","../src/views/p03/prospect-battlecard.jsx","../src/views/p03/prospect-queue.jsx"]){
    const source=readFileSync(new URL(file,import.meta.url),"utf8");
    assert.equal(source.includes("dangerouslySetInnerHTML"),false,file);
    assert.equal(source.includes("RelationMap"),false,`${file} draws no placeholder relationship picture`);
  }
});
