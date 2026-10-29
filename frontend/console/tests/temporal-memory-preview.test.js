import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { transform } from "esbuild";
import {
  projectTemporalMemory,projectHistoryReview
} from "../src/data/temporal-memory-view-model.js";

const id=(n)=>"00000000-0000-4000-8000-"+String(n).padStart(12,"0");
const safeMemory=()=>({
  decision:"VISIBLE",required_permission:"evidence.read",
  data:{
    projection_version:"ui01.inert-p01-temporal-memory.v1",
    read_only:true,candidate_only:true,source_rights:"U03_NOT_ADOPTED",
    runtime_actions_available:false,historical_analysis_available:false,
    customer_output_authorized:false,
    page_size:20,next_cursor:null,
    rows:[{
      temporal_memory_id:id(101),
      v2_run_identity_digest:"a".repeat(64),rollup_digest:"b".repeat(64),
      data_class:"SOURCE_DIFF",window_kind:"MONTH",
      period_start_utc:"2026-03-01T00:00:00Z",
      period_end_utc:"2026-04-01T00:00:00Z",
      knowledge_cutoff_utc:"2026-03-15T00:00:00Z",
      selected_ref_count:3,contrary_ref_count:1,
      head_status:"AMBIGUOUS_HEAD_HOLD",replay_status:"MATCH_METADATA_ONLY",
      rights_status:"NOT_ADMITTED",source_bytes_verified:false,
      historical_reuse_admitted:false,customer_release_authorized:false
    }]
  }
});
const safeReview=()=>({
  decision:"VISIBLE",required_permission:"evidence.read",
  data:{
    projection_version:"li16.r2.inert-temporal-readback.v1",
    read_only:true,candidate_only:true,source_rights:"U03_NOT_ADOPTED",
    runtime_actions_available:false,historical_analysis_available:false,
    rows:[{
      history_review_basis_id:id(201),basis_digest:"c".repeat(64),
      selected_count:2,excluded_count:1,
      head_status:"AMBIGUOUS_HEAD_HOLD",
      due_status:"HOLD_CURRENT_RIGHTS",rights_status:"NOT_ADOPTED"
    }]
  }
});

test("temporal memory view keeps exact P01 period and explicitly unapproved rights",()=>{
  const m=projectTemporalMemory(safeMemory());
  assert.equal(m.status,"READY");
  assert.equal(m.rows.length,1);
  assert.equal(m.rows[0].head,"AMBIGUOUS_HEAD_HOLD");
  assert.equal(m.rows[0].rights,"Not adopted");
  assert.equal(m.rows[0].contrary,1);
  assert.equal(m.liveActions,false);
  assert.equal(m.readOnly,true);
});
test("historical due and coverage candidate are not customer findings",()=>{
  const h=projectHistoryReview(safeReview());
  assert.equal(h.status,"READY");
  assert.equal(h.rows[0].due,"HOLD_CURRENT_RIGHTS");
  assert.equal(h.rows[0].excluded,1);
  assert.equal(h.liveActions,false);
});
test("empty, permission refused, not found and unavailable remain distinct",()=>{
  for(const [decision,expected] of [
    ["NO_ACTIVE_WORKSPACE","NO_ACTIVE_WORKSPACE"],
    ["DENIED","DENIED"],["NOT_FOUND","NOT_FOUND"],["UNAVAILABLE","UNAVAILABLE"]
  ]){
    assert.equal(projectTemporalMemory({decision}).status,expected);
    assert.equal(projectHistoryReview({decision}).status,expected);
  }
  const m=safeMemory();m.data.rows=[];
  assert.equal(projectTemporalMemory(m).status,"EMPTY");
  const h=safeReview();h.data.rows=[];
  assert.equal(projectHistoryReview(h).status,"EMPTY");
});
test("private model/source fields stay outside rendered metadata",()=>{
  const m=safeMemory();
  m.data.rows[0].raw_source_html="<secret>private</secret>";
  m.data.rows[0].model_candidate_text="secret-prose";
  m.data.rows[0].source_url="https://not-for-view.example";
  const v=JSON.stringify(projectTemporalMemory(m));
  for(const forbidden of ["private","secret-prose","source_url","not-for-view"]){
    assert.equal(v.includes(forbidden),false);
  }
});
test("metadata-rights and replay contract violations refuse all rows",()=>{
  const cases=[
    p=>p.data.source_rights="CURRENTLY_ADMITTED_BY_CALLER",
    p=>p.data.runtime_actions_available=true,
    p=>p.data.rows[0].source_bytes_verified=true,
    p=>p.data.rows[0].customer_release_authorized=true,
    p=>p.data.rows[0].rights_status="APPROVED",
    p=>p.data.rows[0].head_status="CURRENT_ACCEPTED",
    p=>p.data.rows[0].replay_status="MODEL_JUDGED_TRUE",
    p=>p.data.rows[0].contrary_ref_count=4,
    p=>p.data.rows[0].period_end_utc="2026-03-01T00:00:00Z",
    p=>p.data.rows[0].temporal_memory_id="foreign",
    p=>p.data.rows[0].v2_run_identity_digest="bad",
    p=>p.data.next_cursor=id(999),
  ];
  for(const mutate of cases){
    const m=safeMemory();mutate(m);
    assert.equal(projectTemporalMemory(m).status,"UNAVAILABLE");
    assert.equal(projectTemporalMemory(m).rows.length,0);
  }
});
test("exact next memory-page cursor is used only at the page boundary",()=>{
  const m=safeMemory();m.data.next_cursor=id(101);
  assert.equal(projectTemporalMemory(m).nextCursor,id(101));
  m.data.next_cursor=id(102);
  assert.equal(projectTemporalMemory(m).status,"UNAVAILABLE");
});
test("candidate React component has no active route, model call or auto-fetch",async()=>{
  const path=new URL("../src/views/p01/history-reviews.jsx",import.meta.url);
  const source=readFileSync(path,"utf8");
  const navigation=readFileSync(new URL("../src/data/navigation.js",import.meta.url),"utf8");
  for(const effect of [
    "fetch(","XMLHttpRequest","setInterval","setTimeout","useEffect",
    "dangerouslySetInnerHTML","method:","<form","navigator.sendBeacon",
    "SourceCaptureStage","ModelExecutor","provider-token",
  ]) assert.equal(source.includes(effect),false,effect);
  assert.equal(navigation.includes("history-reviews.jsx"),false,
    "preview must not activate a customer/operator route");
  assert.match(source,/projectHistoryReview/);
  assert.match(source,/projectTemporalMemory/);
  assert.match(source,/onMemoryNextPage/);
  const out=await transform(source,{loader:"jsx",jsx:"automatic",
    format:"esm",target:"es2022"});
  assert.match(out.code,/HistoricalReviewPreview/);
});
