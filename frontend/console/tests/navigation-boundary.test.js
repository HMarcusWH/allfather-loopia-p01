import test from "node:test";
import assert from "node:assert/strict";
import { STATIC_NAV_IDS,buildNavigation,firstNavigationId,navigationIds,readUsableForPermissions,usableNavigationIdsForPermissions } from "../src/data/navigation.js";
import { READS } from "../src/data/surface-reads.js";

test("static product allowlist excludes prohibited surfaces",()=>{
  for(const f of ["p07a","p07b","m09","polymorphic","pdb","gsmc","road-test","roadmap","m13","m14","mcm","packet-trace","lineage"]) assert.equal(STATIC_NAV_IDS.includes(f),false,f);
  for(const r of ["overview","p01","p02","p03","p04","p05","source-ops","console-state"]) assert.equal(STATIC_NAV_IDS.includes(r),true,r);
});

test("production navigation is adapter-aware and fails closed",()=>{
  assert.deepEqual(buildNavigation({adapterReadIds:[]}),[]);
  const nav=buildNavigation({adapterReadIds:[READS.P01_BATTLECARDS],usableIds:["p01","p02"]});
  assert.deepEqual(navigationIds(nav),["p01"]);
});

test("first live route skips unavailable overview",()=>{
  const nav=buildNavigation({adapterReadIds:[READS.P01_BATTLECARDS,READS.LEDGER]});
  assert.equal(firstNavigationId(nav),"p01");
  assert.deepEqual(navigationIds(nav),["p01","ledger"]);
});

test("current context permissions narrow wired navigation without granting authority",()=>{
  assert.deepEqual(usableNavigationIdsForPermissions(["decision.read"]),["ledger"]);
  assert.deepEqual(usableNavigationIdsForPermissions(["battlecard.read","decision.read"]),["p01","ledger"]);
  assert.deepEqual(usableNavigationIdsForPermissions(["prospect.read"]),["p03"]);
  assert.deepEqual(usableNavigationIdsForPermissions(["priority_outreach_queue.read"]),["p03"]);
  assert.deepEqual(usableNavigationIdsForPermissions(["unknown.permission"]),[]);

  const nav=buildNavigation({
    adapterReadIds:[READS.P01_BATTLECARDS,READS.P03_QUEUE,READS.P03_WATCH,READS.LEDGER],
    usableIds:usableNavigationIdsForPermissions(["decision.read"]),
  });
  assert.deepEqual(navigationIds(nav),["ledger"]);
  assert.equal(firstNavigationId(nav),"ledger","a decision-only operator lands on the ledger rather than a denied product route");
});

test("multi-read routes gate each live read by its exact owner permission",()=>{
  assert.equal(readUsableForPermissions(READS.P03_QUEUE,["priority_outreach_queue.read"]),true);
  assert.equal(readUsableForPermissions(READS.P03_WATCH,["priority_outreach_queue.read"]),false);
  assert.equal(readUsableForPermissions(READS.P03_QUEUE,["prospect.read"]),false);
  assert.equal(readUsableForPermissions(READS.P03_WATCH,["prospect.read"]),true);
  assert.equal(readUsableForPermissions(READS.P03_BATTLECARDS,["prospect_battlecard.read"]),false,"deferred reads never become usable merely because a permission key exists");
});
