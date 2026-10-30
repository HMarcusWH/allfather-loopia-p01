import {test} from "node:test";
import assert from "node:assert/strict";
import {
  projectHistoryWorkbench,availableProductJourney,PRODUCT_READ_JOURNEY,
} from "../src/data/history-review-workbench-view-model.js";

const H="a".repeat(64),K="b".repeat(64),J="c".repeat(64);
const row=()=>({
  revision:H,review_id:"review_ui02",finding_digest:K,basis_digest:J,
  knowledge_cutoff_utc:"2026-03-15T00:00:00Z",
  status:"HUMAN_REVIEW_REQUIRED",critic_digest:H,analyst_digest:K,
  primary_references:[
    {ref_id:"ref_a",source_ref_id:"source_a",polarity:"SUPPORT",known_at_utc:"2026-03-14T00:00:00Z",cited_as_support:true},
    {ref_id:"ref_b",source_ref_id:"source_b",polarity:"CONTRARY",known_at_utc:"2026-03-15T00:00:00Z",cited_as_support:false},
  ],
  selected_count:2,contrary_count:1,
  outcome_status:"STILL_UNRESOLVED",outcome_adjudication:"NOT_ADJUDICATED",
  attention_status:"ATTENTION_CANDIDATE_INTERNAL_ONLY",
  review_due_status:"UNKNOWN_UNTIL_OWNER_READ",
  historical_source_rights:"U03_NOT_ADOPTED",
  human_factual_release_approved:false,human_delivery_approved:false,
  customer_visibility_authorized:false,
});
const valid=()=>({
  decision:"VISIBLE",required_permission:"evidence.read",
  reason_codes:["UI02_SYNTHETIC_METADATA_ONLY"],
  data:{
    version:"ui02.history-review-workbench.v1",read_only:true,
    fixture_only:true,runtime_commands_enabled:false,
    actual_source_owner_authenticated:false,
    rows:[row()],total:1,page:1,page_size:10,next_page:null,
    scope:{tenant_id:"tenant",workspace_id:"workspace",actor_id:"operator"},
  },
});
test("renders source-bound historical metadata and retains contrary refs",()=>{
  const view=projectHistoryWorkbench(valid());
  assert.equal(view.status,"READY");
  assert.equal(view.rows.length,1);
  assert.equal(view.rows[0].contrary,1);
  assert.equal(view.rows[0].refs[1].polarity,"CONTRARY");
  assert.equal(view.rows[0].humanFactualApproval,false);
  assert.equal(view.rows[0].humanDeliveryApproval,false);
});
test("unknown, denied and missing data are never presented as no change",()=>{
  assert.equal(projectHistoryWorkbench(null).status,"UNAVAILABLE");
  assert.equal(projectHistoryWorkbench({decision:"DENIED"}).status,"DENIED");
  assert.equal(projectHistoryWorkbench({decision:"NOT_FOUND"}).status,"NOT_FOUND");
  const x=valid();x.data.rows=[];x.data.total=0;
  assert.equal(projectHistoryWorkbench(x).status,"EMPTY");
});
test("forged releases and contrary source elevation refuse",()=>{
  const forged=valid();
  forged.data.rows[0].customer_visibility_authorized=true;
  assert.equal(projectHistoryWorkbench(forged).status,"UNAVAILABLE");
  const bad=valid();
  bad.data.rows[0].primary_references[1].cited_as_support=true;
  assert.equal(projectHistoryWorkbench(bad).status,"UNAVAILABLE");
  const bad2=valid();bad2.data.fixture_only=false;
  assert.equal(projectHistoryWorkbench(bad2).status,"UNAVAILABLE");
});
test("bounded pagination, IDs and reused revision refuse ambiguous rows",()=>{
  const page=valid();page.data.next_page=3;
  assert.equal(projectHistoryWorkbench(page).status,"UNAVAILABLE");
  const twice=valid();twice.data.rows=[row(),row()];
  assert.equal(projectHistoryWorkbench(twice).status,"UNAVAILABLE");
  const script=valid();script.data.rows[0].review_id="<svg onload=alert(1)>";
  assert.equal(projectHistoryWorkbench(script).status,"UNAVAILABLE");
});
test("product journey is only existing native read descriptors",()=>{
  assert.ok(PRODUCT_READ_JOURNEY.length>=7);
  const available=availableProductJourney({
    adapterReadIds:["p01.releasedBattlecards","m10.decisions"],
    permissionKeys:["battlecard.read"],
  });
  assert.equal(available.find(x=>x.id==="p01.releasedBattlecards").state,"OWNER_READ_WIRED");
  assert.equal(available.find(x=>x.id==="m10.decisions").state,"UNAVAILABLE_OR_DENIED");
  assert.equal(available.find(x=>x.id==="p03.declaredIcp").state,"UNAVAILABLE_OR_DENIED");
});
