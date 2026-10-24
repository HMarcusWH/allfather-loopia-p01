import test from "node:test";
import assert from "node:assert/strict";
import { HttpConsoleDataSource, LIVE_READ_IDS } from "../src/data/http-console-data-source.js";
import { READS, readMeta } from "../src/data/surface-reads.js";
import { PROJECTION } from "../src/data/response-types.js";
import { READ_TRANSPORT, transportPolicyFor } from "../src/data/http-client.js";

function jsonResponse(status,payload){return {status,headers:{get:(name)=>name.toLowerCase()==="content-type"?"application/json; charset=utf-8":null},json:async()=>payload};}
function envelope(source,data,state="READY"){return {source,as_of:"2026-09-10T00:00:00Z",access:{decision:"VISIBLE",required_permission:null,reason_codes:["GRANTED"]},projection:{state,stale_status:"CURRENT"},owner_state:"VISIBLE",reason_codes:["GRANTED"],data};}

test("production datasource exposes only the explicit PR6 + P09 read allowlist",()=>{
  const ds=new HttpConsoleDataSource({fetchImpl:async()=>jsonResponse(503,{})});
  assert.deepEqual(new Set(LIVE_READ_IDS),new Set([READS.CONTEXT,READS.P01_BATTLECARDS,READS.P01_SOURCE_WATCH,READS.P02_ENTITY_INDEX,READS.P02_OWN_DRAFT,READS.P02_RELATION_INDEX,READS.P03_DECLARED_ICP,READS.P03_CUSTOMER_OFFERS,READS.P03_QUEUE,READS.P03_WATCH,READS.P04_DISTRIBUTIONS,READS.P05_DRAFTS,READS.LEDGER,READS.P02_ENTITY_DETAIL,READS.P03_PROSPECT_DETAIL,READS.MONITOR_RUNS,READS.MONITOR_RUN]));
  assert.equal(LIVE_READ_IDS.includes(READS.P03_BATTLECARDS),false);
  assert.equal(LIVE_READ_IDS.includes(READS.OVERVIEW_PULSE),false);
  assert.deepEqual(ds.getUsableNavigationIds(),[],"production has no unconditional usable route before context permissions are resolved");
});

test("P02 forwards only its explicit visibility lens and never caller scope",async()=>{
  const calls=[];
  const fetchImpl=async(url,options)=>{calls.push({url,options});return jsonResponse(200,envelope(READS.P02_ENTITY_INDEX,[{entity_twin_id:"e1"}]));};
  const ds=new HttpConsoleDataSource({fetchImpl});
  const result=await ds.readDescriptor(READS.P02_ENTITY_INDEX,{visibility_scope:"SALES",tenant_id:"attacker",workspace_id:"other"});
  assert.equal(calls.length,1);
  assert.equal(calls[0].url,"/console/api/v1/p02/entities?visibility_scope=SALES");
  assert.equal(calls[0].options.method,"GET");
  assert.equal(calls[0].options.credentials,"same-origin");
  assert.equal(calls[0].options.redirect,"manual");
  assert.equal(result.projection.state,PROJECTION.READY);
  assert.equal(result.data.rows[0].entity_twin_id,"e1");
});

test("P04 forwards only target_org_role",async()=>{
  let called="";
  const ds=new HttpConsoleDataSource({fetchImpl:async(url)=>{called=url;return jsonResponse(200,envelope(READS.P04_DISTRIBUTIONS,[],"EMPTY"));}});
  const result=await ds.readDescriptor(READS.P04_DISTRIBUTIONS,{target_org_role:"SALES",operator_id:"spoof"});
  assert.equal(called,"/console/api/v1/p04/distributions?target_org_role=SALES");
  assert.equal(result.projection.state,PROJECTION.EMPTY);
});

test("empty ledger preserves the server-recomputed integrity summary",async()=>{
  const ds=new HttpConsoleDataSource({fetchImpl:async()=>jsonResponse(200,envelope(READS.LEDGER,{environment_class:"TEST",rows:[],verification:"PASS"},"EMPTY"))});
  const result=await ds.readDescriptor(READS.LEDGER);
  assert.equal(result.projection.state,PROJECTION.EMPTY);
  assert.deepEqual(result.data.rows,[]);
  assert.equal(result.data.summary.title,"Ledger integrity");
  assert.equal(result.data.summary.body,"TEST · PASS");
});

test("authentication failure, malformed transport and unexpected status fail visibly closed",async()=>{
  const unauth=new HttpConsoleDataSource({fetchImpl:async()=>jsonResponse(401,{status:"UNAUTHENTICATED",reason_codes:["AUTHENTICATION_REQUIRED"]})});
  const r=await unauth.readDescriptor(READS.P01_BATTLECARDS);
  assert.equal(r.projection.state,PROJECTION.UNAVAILABLE);
  assert.deepEqual(r.reason_codes,["AUTHENTICATION_REQUIRED"]);
  const html=new HttpConsoleDataSource({fetchImpl:async()=>({status:200,headers:{get:()=>"text/html"},json:async()=>({})})});
  const bad=await html.readDescriptor(READS.P01_BATTLECARDS);
  assert.deepEqual(bad.reason_codes,["NON_JSON_READ_RESPONSE"]);
  const odd=new HttpConsoleDataSource({fetchImpl:async()=>jsonResponse(418,envelope(READS.P01_BATTLECARDS,[]))});
  const unexpected=await odd.readDescriptor(READS.P01_BATTLECARDS);
  assert.deepEqual(unexpected.reason_codes,["UNEXPECTED_READ_STATUS"]);
});

test("unknown/deferred descriptors never become generic endpoints",async()=>{
  let calls=0;
  const ds=new HttpConsoleDataSource({fetchImpl:async()=>{calls++;return jsonResponse(200,{});}});
  const result=await ds.readDescriptor(READS.P03_BATTLECARDS);
  assert.equal(calls,0);
  assert.equal(result.projection.state,PROJECTION.UNAVAILABLE);
});

test("audited display policy remains no-prefetch and no-transparent-retry",()=>{
  assert.deepEqual(transportPolicyFor(readMeta(READS.LEDGER)),READ_TRANSPORT.AUDITED_DISPLAY);
  assert.equal(READ_TRANSPORT.AUDITED_DISPLAY.allowPrefetch,false);
  assert.equal(READ_TRANSPORT.AUDITED_DISPLAY.transparentRetry,"NEVER");
});

test("P02 own-company drafts use fixed session scope without user-supplied selectors",async()=>{
  let called="";
  const ds=new HttpConsoleDataSource({fetchImpl:async(url)=>{called=url;return jsonResponse(200,envelope(READS.P02_OWN_DRAFT,[{display_name:"Customer owned",claim_ceiling:"INTERNAL_DRAFT"}]));}});
  const result=await ds.readDescriptor(READS.P02_OWN_DRAFT,{tenant_id:"other",workspace_id:"foreign",snapshot:"s"});
  assert.equal(called,"/console/api/v1/p02/own-company-drafts");
  assert.equal(result.projection.state,PROJECTION.READY);
  assert.equal(result.data.rows[0].claim_ceiling,"INTERNAL_DRAFT");
});

test("unreleased own-company drafts disable prefetch and transparent retry",()=>{
  const policy=transportPolicyFor(readMeta(READS.P02_OWN_DRAFT));
  assert.equal(policy.allowPrefetch,false);
  assert.equal(policy.transparentRetry,"NEVER");
});


test("customer draft reads use only fixed session-owned endpoints and audited transport",async()=>{
  for(const [id,url] of [
    [READS.P01_SOURCE_WATCH,"/console/api/v1/p01/source-watch"],
    [READS.P03_DECLARED_ICP,"/console/api/v1/p03/declared-icp"],
    [READS.P03_CUSTOMER_OFFERS,"/console/api/v1/p03/customer-offers"]
  ]){
    let called="";
    const ds=new HttpConsoleDataSource({fetchImpl:async(path)=>{
      called=path;return jsonResponse(200,envelope(id,[{display_name:"Customer declaration"}]));
    }});
    const r=await ds.readDescriptor(id,{tenant_id:"attacker",workspace_id:"foreign",operator_id:"spoof",record:"other"});
    assert.equal(called,url,"client cannot select another tenant or a record");
    assert.equal(r.projection.state,PROJECTION.READY);
    assert.equal(r.data.rows[0].display_name,"Customer declaration");
    const policy=transportPolicyFor(readMeta(id));
    assert.equal(policy.allowPrefetch,false,id);
    assert.equal(policy.transparentRetry,"NEVER",id);
  }
});
