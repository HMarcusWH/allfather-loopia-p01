import { test } from "node:test";
import assert from "node:assert/strict";
import { validateP09Fixtures, indistinguishableNotFound, monitorMetadataOnly } from "../ops/acceptance/p09-fixture-contract.mjs";

const releaseSha = "4e08ac0488fea8f9529f36c010040267128a422d";
const owned = "72b3c198-d187-4b51-86d5-9cb7656e7c2d";
const foreign = "6acb2398-e035-4f88-a3d5-d4163e74a6cb";
const fixture = {test_only:true,release_sha:releaseSha,p02:{owned,foreign}};

test("fixtures are TEST-only, release-bound, explicit and distinct", () => {
  assert.deepEqual(validateP09Fixtures(fixture, releaseSha).p02, {owned,foreign});
  assert.throws(() => validateP09Fixtures({...fixture,test_only:false},releaseSha), /TEST-only/);
  assert.throws(() => validateP09Fixtures(fixture,"0".repeat(40)),/release binding/);
  assert.throws(() => validateP09Fixtures({...fixture,password:"secret"},releaseSha),/unexpected field/);
  assert.throws(() => validateP09Fixtures({...fixture,p02:{owned,foreign:owned}},releaseSha),/distinct/);
  assert.throws(() => validateP09Fixtures({...fixture,p02:{owned,foreign:"abc"}},releaseSha),/UUIDv4/);
  assert.throws(() => validateP09Fixtures({test_only:true,release_sha:releaseSha},releaseSha),/no owned/);
  const reviewDigest = "a".repeat(64);
  const withReview = {...fixture, reviewed_plan_digest:reviewDigest};
  assert.deepEqual(validateP09Fixtures(withReview,releaseSha,reviewDigest).p02,{owned,foreign});
  assert.throws(() => validateP09Fixtures(withReview,releaseSha,"b".repeat(64)),/digest mismatch/);
  assert.throws(() => validateP09Fixtures(fixture,releaseSha,reviewDigest),/digest mismatch/);
});

test("foreign/unknown selector equivalence demands 404 and indistinguishable safe payload", () => {
  const response = {status:404,payload:{
    source:"p02.entityContextDetail", as_of:null, owner_state:"NOT_FOUND",data:null,
    reason_codes:["RELEASED_SNAPSHOT_NOT_FOUND"],
    access:{decision:"VISIBLE",required_permission:"entity_twin.read",reason_codes:["RELEASED_SNAPSHOT_NOT_FOUND"]},
    projection:{state:"EMPTY",stale_status:"UNKNOWN"}
  }};
  assert.equal(indistinguishableNotFound(response,structuredClone(response)),true);
  assert.equal(indistinguishableNotFound(response,{...response,status:403}),false);
  const leaked=structuredClone(response); leaked.payload.data={tenant_id:"hidden"};
  assert.equal(indistinguishableNotFound(response,leaked),false);
  const divergent=structuredClone(response); divergent.payload.reason_codes=["FOREIGN_SELECTOR"];
  assert.equal(indistinguishableNotFound(response,divergent),false);
  const extra=structuredClone(response); extra.payload.private_path="/secret";
  assert.equal(indistinguishableNotFound(extra,structuredClone(extra)),false);
  const changedAsOf=structuredClone(response); changedAsOf.payload.as_of="2026-10-08T12:00:00Z";
  assert.equal(indistinguishableNotFound(response,changedAsOf),false);
});

test("React Monitor lists/details reject extra sensitive fields", () => {
  assert.equal(monitorMetadataOnly({rows:[],scheduled_queue:[],missions:[]}),true);
  assert.equal(monitorMetadataOnly({rows:[{monitor_run_id:owned,stage:"NEW"}],scheduled_queue:[],missions:[]}),true);
  assert.equal(monitorMetadataOnly({rows:[{monitor_run_id:owned,candidate_text:"LEAK"}],scheduled_queue:[],missions:[]}),false);
  assert.equal(monitorMetadataOnly({rows:[],scheduled_queue:[{monitor_run_id:owned,request_body:"LEAK"}],missions:[]}),false);
  assert.equal(monitorMetadataOnly({rows:[],scheduled_queue:[],missions:[{mission_id:owned,model_prompt:"LEAK"}]}),false);
  assert.equal(monitorMetadataOnly({rows:[{monitor_run_id:owned,stage:{candidate_text:"LEAK"}}],scheduled_queue:[],missions:[]}),false);
  const detail={run:{monitor_run_id:owned,stage:"NEW"},integrity:"VERIFIED",events:[],
    artifacts:[],execution_admission:{admitted:false},current_reliance:{},awaiting:"NOTHING"};
  assert.equal(monitorMetadataOnly(detail,true),true);
  assert.equal(monitorMetadataOnly({...detail,prompt_body:"LEAK"},true),false);
  assert.equal(monitorMetadataOnly({...detail,artifacts:[{kind:"PROMPT",digest:"hex",raw_content:"LEAK"}]},true),false);
  assert.equal(monitorMetadataOnly({...detail,current_reliance:{password:"LEAK"}},true),false);
  assert.equal(monitorMetadataOnly({...detail,current_reliance:{p01_release:"ACTIVE_AS_RECORDED"}},true),true);
  assert.equal(monitorMetadataOnly({...detail,current_reliance:{p01_release:{candidate_text:"LEAK"}}},true),false);
  assert.equal(monitorMetadataOnly({...detail,run:{...detail.run,stage:{candidate_text:"LEAK"}}},true),false);
  assert.equal(monitorMetadataOnly({...detail,events:[{actor:{type:"OPERATOR",operator_id:owned},sequence_no:1}]},true),true);
  assert.equal(monitorMetadataOnly({...detail,events:[{actor:{type:"OPERATOR",operator_id:owned,private_path:"LEAK"}}]},true),false);
  assert.equal(monitorMetadataOnly({...detail,execution_admission:{required:true,admitted:true,model_attempts:{used:0,limit:4},source_captures:{used:0,limit:2}}},true),true);
  assert.equal(monitorMetadataOnly({...detail,execution_admission:{model_attempts:{used:0,limit:4,secret:"LEAK"}}},true),false);
  assert.equal(monitorMetadataOnly({...detail,execution_admission:{model_attempts:{used:-1,limit:4}}},true),false);
});
