import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { CONTEXT_STATE, classifyContextEnvelope } from "../src/data/context-state.js";
import { PROJECTION } from "../src/data/response-types.js";

function envelope({state=PROJECTION.UNAVAILABLE,decision="VISIBLE",reasons=[]}={}){
  return {projection:{state,stale_status:"UNKNOWN"},access:{decision,required_permission:null,reason_codes:reasons},reason_codes:reasons,data:null};
}

test("context presentation keeps authentication, workspace, denial and outage states distinct",()=>{
  assert.equal(classifyContextEnvelope(envelope({state:PROJECTION.LOADING})),CONTEXT_STATE.LOADING);
  assert.equal(classifyContextEnvelope(envelope({state:PROJECTION.READY})),CONTEXT_STATE.READY);
  assert.equal(classifyContextEnvelope(envelope({reasons:["AUTHENTICATION_REQUIRED"]})),CONTEXT_STATE.AUTHENTICATION_REQUIRED);
  assert.equal(classifyContextEnvelope(envelope({reasons:["NO_ACTIVE_WORKSPACE"]})),CONTEXT_STATE.NO_ACTIVE_WORKSPACE);
  assert.equal(classifyContextEnvelope(envelope({decision:"DENIED",reasons:["TENANT_NOT_ACTIVE"]})),CONTEXT_STATE.DENIED);
  assert.equal(classifyContextEnvelope(envelope({reasons:["READ_UNAVAILABLE"]})),CONTEXT_STATE.UNAVAILABLE);
});

test("a denial stays denial even when its owner reason is unfamiliar to the frontend",()=>{
  assert.equal(classifyContextEnvelope(envelope({decision:"DENIED",reasons:["FUTURE_OWNER_REASON"]})),CONTEXT_STATE.DENIED);
});

test("no-workspace recovery points at the registered workspace chooser",()=>{
  const shell=readFileSync(new URL("../src/shell/shell.jsx",import.meta.url),"utf8");
  assert.match(shell,/href="\/console\/workspaces">Open workspace selector<\/a>/);
  assert.doesNotMatch(shell,/href="\/console">Open workspace selector<\/a>/);
});
