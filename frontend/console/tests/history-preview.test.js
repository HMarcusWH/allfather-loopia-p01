import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { transform } from "esbuild";
import { projectCompetitorHistory } from "../src/data/competitor-history-view-model.js";

const id=(n)=>"00000000-0000-4000-8000-"+String(n).padStart(12,"0");
const capture=(n,state="STRUCTURAL_CHANGE_RECORDED")=>({
  source_snapshot_id:id(n),monitored_source_id:id(10),source_kind:"OFFICIAL_SITE",
  snapshot_sequence:n,captured_at:"2026-10-25 12:00:00",
  coverage_state:state,business_materiality:"NOT_ASSESSED",
  observation_assessment:"NOT_IMPLEMENTED",release_status:"NOT_INFERRED",
  comparisons:state==="STRUCTURAL_CHANGE_RECORDED"?[
    {source_diff_id:id(30+n),structural_significance:"MINOR",detected_at:"2026-10-25 12:01:00"}
  ]:[],
});
const visible=(rows=[capture(2)])=>({
  decision:"VISIBLE",required_permission:"evidence.read",reason_codes:["GRANTED"],
  data:{
    competitor:{competitor_id:id(1),display_name:"Fictional Competitor",status:"ACTIVE"},
    rows,next_cursor:null,page_size:20,source_rights:"CURRENT_ELIGIBILITY_CHECKED",
    business_materiality_available:false,read_only:true,
    projection_version:"li16.competitor-history-metadata.v1"
  }
});

test("nonmaterial is NOT inferred from a small structural diff",()=>{
  const r=projectCompetitorHistory(visible());
  assert.equal(r.state,"READY");
  assert.equal(r.rows[0].materiality,"Not assessed");
  assert.match(r.rows[0].label,/Structural difference/);
  assert.deepEqual(r.rows[0].comparisons.map(c=>c.label),["Small structural difference"]);
  assert.equal(r.rows[0].release,"Not inferred");
  assert.equal(r.note.includes("customer release"),true);
});
test("baseline and identical capture do not become new events",()=>{
  const r=projectCompetitorHistory(visible([capture(1,"BASELINE"),capture(2,"STRUCTURALLY_UNCHANGED")]));
  assert.equal(r.state,"READY");
  assert.equal(r.rows[0].label,"Baseline capture");
  assert.equal(r.rows[1].label,"Structurally unchanged capture");
  assert.equal(r.rows.every(x=>x.materiality==="Not assessed"),true);
});
test("missing comparisons and multiple structural comparisons remain separate",()=>{
  const rows=[capture(3,"COMPARISON_UNAVAILABLE"),capture(4,"MULTIPLE_COMPARISONS")];
  const r=projectCompetitorHistory(visible(rows));
  assert.equal(r.state,"READY");
  assert.equal(r.rows[0].label,"Comparison unavailable");
  assert.equal(r.rows[1].label,"Multiple structural comparisons");
});
test("no read / no workspace / denied / unknown are distinct, without data leakage",()=>{
  assert.equal(projectCompetitorHistory(null).state,"UNAVAILABLE");
  for(const [kind,expected] of [["NO_ACTIVE_WORKSPACE","NO_ACTIVE_WORKSPACE"],["DENIED","DENIED"],["NOT_FOUND","NOT_FOUND"],["UNAVAILABLE","UNAVAILABLE"]]){
    const r=projectCompetitorHistory({decision:kind,data:visible().data});
    assert.equal(r.state,expected);
    assert.equal(r.rows.length,0);
    assert.equal(r.competitor,null);
  }
  const none=projectCompetitorHistory(visible([]));
  assert.equal(none.state,"EMPTY");
  assert.equal(none.rows.length,0);
});
test("manual cursor is carried only when it is the emitted page boundary",()=>{
  const p=visible([capture(2)]);p.data.next_cursor=p.data.rows[0].source_snapshot_id;
  assert.equal(projectCompetitorHistory(p).nextCursor,id(2));
  p.data.next_cursor=id(77);
  assert.equal(projectCompetitorHistory(p).state,"UNAVAILABLE");
});
test("unexpected private fields are not copied to the view model",()=>{
  const p=visible();
  p.data.rows[0].candidate_text="never-render-this";
  p.data.rows[0].source_url_safe="https://never.example/";
  p.data.rows[0].comparisons[0].raw_source="never-render-this";
  p.data.competitor.secret="never-render-this";
  const result=projectCompetitorHistory(p);
  assert.equal(result.state,"READY");
  const bytes=JSON.stringify(result);
  for(const forbidden of ["candidate_text","source_url_safe","raw_source","secret","https://never"])assert.equal(bytes.includes(forbidden),false);
});
test("unassessed/eligible/read-only version constraints fail closed",()=>{
  const cases=[
    p=>p.data.rows[0].business_materiality="MINOR",
    p=>p.data.rows[0].release_status="RELEASED",
    p=>p.data.rows[0].observation_assessment="MODEL_APPROVED",
    p=>p.data.read_only=false,
    p=>p.data.source_rights="NOT_CHECKED",
    p=>p.data.business_materiality_available=true,
    p=>p.data.projection_version="li16.unknown",
    p=>p.data.rows[0].coverage_state="toString",
    p=>p.data.rows[0].comparisons[0].structural_significance="__proto__",
    p=>p.data.rows[0].source_kind="javascript:alert(1)",
    p=>p.data.rows[0].captured_at="<img onerror=alert(1)>",
    p=>p.data.competitor.competitor_id="someone_else",
    p=>p.data.page_size=0,
  ];
  for(const mutate of cases){const p=visible();mutate(p);assert.equal(projectCompetitorHistory(p).state,"UNAVAILABLE");}
});
test("the inert view has no route, GET side effect, HTML injection, timer or autofetch",()=>{
  const src=readFileSync(new URL("../src/views/p01/competitor-history.jsx",import.meta.url),"utf8");
  const nav=readFileSync(new URL("../src/data/navigation.js",import.meta.url),"utf8");
  for(const forbidden of ["fetch(","XMLHttpRequest","method:","POST","setInterval","setTimeout","useEffect","dangerouslySetInnerHTML","<form","navigator.sendBeacon","request_digest","source_url_safe"])assert.equal(src.includes(forbidden),false,forbidden);
  assert.equal(nav.includes("competitor-history.jsx"),false,"not imported in active navigation");
  assert.match(src,/onNextPage/,"any future pagination must be explicit caller action");
  assert.match(src,/projectCompetitorHistory/);
});

test("inactive React history module compiles without registering in the live app",async()=>{
  const src=readFileSync(new URL("../src/views/p01/competitor-history.jsx",import.meta.url),"utf8");
  const bundle=await transform(src,{loader:"jsx",jsx:"automatic",format:"esm",target:"es2022"});
  assert.match(bundle.code,/CompetitorHistoryPreview/);
  assert.equal(bundle.code.includes("dangerouslySetInnerHTML"),false);
});
