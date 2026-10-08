import { chromium } from "playwright";
import { createHash, randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import { validateP09Fixtures, indistinguishableNotFound, monitorMetadataOnly } from "./p09-fixture-contract.mjs";
const base="https://allfather-php-mvp-staging.up.railway.app"; // exact, reviewed STAGING only
const expected=process.env.P09_EXPECTED_RELEASE_SHA;
if(!/^[a-f0-9]{40}$/.test(expected||"")) throw Error("expected release SHA required");
if(process.env.P09_TEST_SCOPE_REVIEWED!=="YES") throw Error("explicit TEST-scope review acknowledgement required");
const fixtureFile=process.env.P09_FIXTURES_FILE;
const reviewDigest=process.env.P09_REVIEWED_PLAN_DIGEST;
if(Boolean(fixtureFile)!==Boolean(reviewDigest))throw Error("fixtures and reviewed membership plan digest must be provided together");
if(reviewDigest&&!/^[a-f0-9]{64}$/.test(reviewDigest))throw Error("reviewed plan digest must be SHA-256");
const fixtures=fixtureFile?validateP09Fixtures(JSON.parse(readFileSync(fixtureFile,"utf8")),expected,reviewDigest):null;

const email=process.env.TEST_LOGIN_EMAIL, password=process.env.TEST_LOGIN_PASSWORD;
if (!email || !password || !email.endsWith("@example.test")) throw Error("TEST-only credentials required");
const routes=["/console","/console/app","/console/monitor","/console/monitor/setup","/console/monitor/schedule","/console/briefings","/console/distributions","/console/marketing","/account"];
const views=[["desktop",{width:1440,height:900}],["mobile",{width:390,height:844}]];
const browser=await chromium.launch({headless:true,args:["--no-sandbox"]});
const observations=[]; let failures=0,covered=0,notCovered=0;
const emit=(value)=>{observations.push(value);if(value.result==="FAIL") failures++;if(value.result==="PASS")covered++;if(value.result==="NOT_COVERED")notCovered++;};
try {
  for(const [name,viewport] of views) {
    const context=await browser.newContext({viewport,deviceScaleFactor:1,colorScheme:"light",reducedMotion:"reduce"});
    const page=await context.newPage();
    const jsErrors=[],autoPosts=[],offOriginRequests=[];
    let measuring=false;
    page.on("pageerror",e=>jsErrors.push(e.name.slice(0,100)));
    page.on("request",request=>{
      if(!measuring)return;
      const url=new URL(request.url());
      if(url.origin!==base) offOriginRequests.push(url.hostname.slice(0,100));
      if(request.method()!=="GET" && request.method()!=="HEAD") autoPosts.push({method:request.method(),path:url.pathname.slice(0,120)});
    });
    try {
      const anonymousApi=await context.request.get(base+"/console/api/v1/monitor/runs",{maxRedirects:0});
      emit({viewport:name,route:"/console/api/v1/monitor/runs:anonymous",status:anonymousApi.status(),result:anonymousApi.status()===401?"PASS":"FAIL"});
      const login=await page.goto(base+"/login",{waitUntil:"domcontentloaded",timeout:30000});
      const inputCount=await page.locator('input[name="password"]').count();
      if(login?.status()!==200||inputCount!==1){emit({viewport:name,route:"/login",status:login?.status(),result:"FAIL",reason:"login form not available"});continue;}
      emit({viewport:name,route:"/login",status:200,result:"PASS"});
      await page.locator('input[name="email"]').fill(email);
      await page.locator('input[name="password"]').fill(password);
      await page.locator('input[name="password"]').evaluate(el=>el.closest("form").requestSubmit());
      await page.waitForURL(u=>u.pathname!=="/login",{timeout:20000});
      const afterLogin=new URL(page.url()).pathname;
      if(afterLogin==="/account/password"||afterLogin==="/change-password"||afterLogin.startsWith("/password")){
        emit({viewport:name,route:"/login",result:"FAIL",reason:"fresh password reset unexpectedly required"});continue;
      }
      emit({viewport:name,route:"/login-session",result:"PASS",redirectPath:afterLogin});
      const chooser=await page.goto(base+"/console/workspaces",{waitUntil:"domcontentloaded"});
      const ws=page.locator('input[name="workspace_id"]').first();
      if(chooser?.status()===200 && await ws.count()){
        await ws.evaluate(el=>el.closest("form").requestSubmit());
        await page.waitForURL(u=>u.pathname==="/console",{timeout:12000});
        emit({viewport:name,route:"/console/workspaces",result:"PASS",selected:"first authorized workspace"});
      } else {
        emit({viewport:name,route:"/console/workspaces",status:chooser?.status(),result:"NOT_COVERED",reason:"chooser not presented"});
      }
      const css=await context.request.get(base+"/assets/console.css");
      const cssBody=css.ok()?await css.body():Buffer.alloc(0);
      emit({viewport:name,route:"/assets/console.css",status:css.status(),sha256:createHash("sha256").update(cssBody).digest("hex"),bytes:cssBody.length,unmodified:true,result:css.ok()&&cssBody.length>0?"PASS":"FAIL"});
      measuring=true;
      for (const path of routes){
        const response=await page.goto(base+path,{waitUntil:"domcontentloaded",timeout:30000});
        await page.waitForTimeout(400);
        const status=response?.status()??0;
        // A redirect to /login, workspace chooser or another route is NOT success.
        const actualPath=new URL(page.url()).pathname;
        const unexpectedRedirect=actualPath!==path;
        const geometry=await page.evaluate(()=>({
          scrollWidth:document.documentElement.scrollWidth,
          innerWidth:window.innerWidth,
          scrollHeight:document.documentElement.scrollHeight,
          buttonCount:document.querySelectorAll("button").length,
          viewportMeta:!!document.querySelector('meta[name="viewport"]'),
          forbiddenScopeParams:[...new URL(window.location.href).searchParams.keys()].some(k=>/^(tenant|workspace|operator)(_id|_key)?$/i.test(k)),
          forbiddenAdmissionControl:[...document.querySelectorAll("button, input[type=submit], a")].some(el=>/^(admit schedule|approve prompt|admit execution|activate recurrence)$/i.test((el.textContent||el.value||"").trim()))
        }));
        const overflow=geometry.scrollWidth>geometry.innerWidth+1;
        const denied=status===403;
        const result=denied&&!unexpectedRedirect?"NOT_COVERED":status===200&&!unexpectedRedirect&&!overflow&&!geometry.forbiddenScopeParams&&!geometry.forbiddenAdmissionControl?"PASS":"FAIL";
        emit({viewport:name,route:path,status,unexpectedRedirect,actualPath,documentWidth:geometry.scrollWidth,viewportWidth:geometry.innerWidth,overflow,buttonCount:geometry.buttonCount,forbiddenScopeParams:geometry.forbiddenScopeParams,forbiddenAdmissionControl:geometry.forbiddenAdmissionControl,result,reason:denied&&!unexpectedRedirect?"owner-safe denied; visual detail untested":undefined});
        if(path==="/account"&&name==="mobile"){
          emit({viewport:name,route:"/account:mobile-width",expectedMax:390,actual:geometry.scrollWidth,result:!unexpectedRedirect&&geometry.scrollWidth<=390?"PASS":"FAIL"});
        }
      }
      for(const detail of ["setup reviewed apply","schedule proposal/revoke","stale digest refusal"]) emit({viewport:name,route:detail,result:"NOT_COVERED",reason:"requires authorized fixture/object or explicit reviewed operation; not inferred from page load"});
      await page.reload({waitUntil:"domcontentloaded"});
      emit({viewport:name,route:"/account:reload",result:new URL(page.url()).pathname==="/account"?"PASS":"FAIL"});

      measuring=false;
      emit({viewport:name,route:"passive-browser-network",autoMutatingRequests:autoPosts.length,offOriginRequests:offOriginRequests.length,result:autoPosts.length===0&&offOriginRequests.length===0?"PASS":"FAIL"});

      // Read-only metadata APIs. This empty synthetic scope must not disclose private model or evidence content.
      const runsResponse=await context.request.get(base+"/console/api/v1/monitor/runs",{failOnStatusCode:false});
      if(runsResponse.status()===200){
        const envelope=await runsResponse.json();
        const records=envelope?.data?.rows;
        const validRows=runsResponse.status()===200 && envelope?.owner_state==="VISIBLE" && monitorMetadataOnly(envelope?.data);
        emit({viewport:name,route:"/console/api/v1/monitor/runs:metadata",status:200,rowsCount:Array.isArray(records)?records.length:null,result:validRows?"PASS":"FAIL"});
      }else{
        emit({viewport:name,route:"/console/api/v1/monitor/runs:metadata",status:runsResponse.status(),result:runsResponse.status()===403?"NOT_COVERED":"FAIL",reason:"metadata read not granted for TEST role"});
      }
      for(const [nameOfRead,pathOfRead] of [
        ["P02 selector","/console/api/v1/p02/entity-detail?snapshot="],
        ["P03 selector","/console/api/v1/p03/prospect-detail?output="],
        ["Monitor run selector","/console/api/v1/monitor/run?run="],
      ]){
        const response=await context.request.get(base+pathOfRead+randomUUID(),{failOnStatusCode:false});
        const status=response.status();
        let ownerState=null;
        if([200,403,404].includes(status)){
          try {const payload=await response.json();ownerState=payload?.owner_state??null;} catch {}
        }
        emit({viewport:name,route:nameOfRead+":unknown-valid-uuid",status,ownerState,result:status===404&&ownerState==="NOT_FOUND"?"PASS":status===403?"NOT_COVERED":"FAIL"});
      }
      for(const surface of ["/console/monitor/setup","/console/monitor/schedule"]){
        await page.goto(base+surface,{waitUntil:"domcontentloaded",timeout:30000});
        const formDetails=await page.evaluate(()=>({
          scopedNames:[...document.querySelectorAll("input,select,textarea")].map(el=>el.name).filter(n=>/^(tenant|workspace|operator)(_id|_key)?$/i.test(n)),
          admissionControls:[...document.querySelectorAll("button,a,input[type=submit]")].filter(el=>/^(admit( schedule| execution)?|approve prompt|activate recurrence)$/i.test((el.textContent||el.value||"").trim())).length,
          hasCsrf:!!document.querySelector('input[name="csrf_token"]')
        }));
        emit({viewport:name,route:surface+":form-scope",scopedFieldCount:formDetails.scopedNames.length,admissionControlCount:formDetails.admissionControls,hasCsrf:formDetails.hasCsrf,result:formDetails.scopedNames.length===0&&formDetails.admissionControls===0&&formDetails.hasCsrf?"PASS":"FAIL"});
        const missing=await context.request.post(base+surface,{form:{action:"apply"},failOnStatusCode:false,maxRedirects:0});
        emit({viewport:name,route:surface+":missing-csrf",status:missing.status(),result:missing.status()===403?"PASS":"FAIL"});
        const csrf=await page.locator('input[name="csrf_token"]').first().inputValue();
        // Submit only controller-level rejection cases, never a valid proposal/apply.
        const badAction=surface.endsWith("/setup")?{action:"apply"}:{action:"admit"};
        const blocked=await context.request.post(base+surface,{form:{csrf_token:csrf,...badAction},failOnStatusCode:false,maxRedirects:0});
        emit({viewport:name,route:surface+(surface.endsWith("/setup")?":missing-plan-digest":":browser-admission-refused"),status:blocked.status(),result:blocked.status()===409?"PASS":"FAIL"});
      }
      const unregistered=await context.request.get(base+"/console/monitor/schedule/admit",{failOnStatusCode:false,maxRedirects:0});
      emit({viewport:name,route:"/console/monitor/schedule/admit:unregistered",status:unregistered.status(),result:unregistered.status()===404?"PASS":"FAIL"});

      // Optional owner-review-bound synthetic fixture reads; no write and no role assignment.
      const fixtureEndpoints={
        p02:"/console/api/v1/p02/entity-detail?snapshot=",
        p03:"/console/api/v1/p03/prospect-detail?output=",
        monitor:"/console/api/v1/monitor/run?run="
      };
      for(const [kind,endpoint] of Object.entries(fixtureEndpoints)){
        if(!fixtures?.[kind]){
          emit({viewport:name,route:kind+":owner-visible-read",result:"NOT_COVERED",reason:"reviewed owned/foreign TEST IDs not supplied"});
          emit({viewport:name,route:kind+":foreign-unknown-equivalence",result:"NOT_COVERED",reason:"reviewed cross-scope fixture not supplied"});
          continue;
        }
        async function readSelector(selector){
          const response=await context.request.get(base+endpoint+encodeURIComponent(selector),{failOnStatusCode:false});
          const status=response.status();
          const payload=[200,403,404].includes(status)?await response.json().catch(()=>null):null;
          return {status,payload};
        }
        const own=await readSelector(fixtures[kind].owned);
        const visible=own.status===200&&own.payload?.owner_state==="VISIBLE"&&
          own.payload?.data!==null&&own.payload?.data!==undefined&&
          (kind!=="monitor"||monitorMetadataOnly(own.payload.data,true));
        emit({viewport:name,route:kind+":owner-visible-read",status:own.status,ownerState:own.payload?.owner_state??null,
          result:visible?"PASS":"FAIL"});
        const absent=await readSelector(randomUUID());
        const foreign=await readSelector(fixtures[kind].foreign);
        emit({viewport:name,route:kind+":foreign-unknown-equivalence",
          unknownStatus:absent.status,foreignStatus:foreign.status,
          result:indistinguishableNotFound(absent,foreign)?"PASS":"FAIL"});
      }
      if(jsErrors.length)emit({viewport:name,route:"browser-js",errorCount:jsErrors.length,errorTypes:jsErrors.slice(0,5),result:"FAIL"});
    } catch(error){
      emit({viewport:name,route:"journey",result:"FAIL",errorName:error?.name||"Error"});
    } finally {await context.close();}
  }
} finally {await browser.close();}
const verdict=failures?"FAIL_OR_PARTIAL":fixtures?"PASS_SYNTHETIC_READ_SUBSET_ONLY":"PASS_VISUAL_SUBSET_ONLY";
console.log("P09_DEPLOYED_BROWSER_RESULTS",JSON.stringify({verdict,release_sha:expected,browser:"Playwright Chromium 1.56.1",covered,notCovered,failures,observations}));
if(failures) process.exitCode=1;
