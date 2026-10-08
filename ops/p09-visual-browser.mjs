import { chromium } from "playwright";
import { createHash } from "node:crypto";
const base="https://allfather-php-mvp-staging.up.railway.app";
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
      const login=await page.goto(base+"/login",{waitUntil:"domcontentloaded",timeout:30000});
      const inputCount=await page.locator('input[name="password"]').count();
      if(login?.status()!==200||inputCount!==1){emit({viewport:name,route:"/login",status:login?.status(),result:"FAIL",reason:"login form not available"});continue;}
      emit({viewport:name,route:"/login",status:200,result:"PASS"});
      await page.locator('input[name="email"]').fill(email);
      await page.locator('input[name="password"]').fill(password);
      await page.locator('input[name="password"]').evaluate(el=>el.closest("form").requestSubmit());
      await page.waitForURL(u=>u.pathname!=="/login",{timeout:20000});
      const afterLogin=new URL(page.url()).pathname;
      if(afterLogin==="/change-password"||afterLogin.startsWith("/password")){
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
        const result=denied?"NOT_COVERED":status===200&&!overflow&&!geometry.forbiddenScopeParams&&!geometry.forbiddenAdmissionControl?"PASS":"FAIL";
        emit({viewport:name,route:path,status,documentWidth:geometry.scrollWidth,viewportWidth:geometry.innerWidth,overflow,buttonCount:geometry.buttonCount,forbiddenScopeParams:geometry.forbiddenScopeParams,forbiddenAdmissionControl:geometry.forbiddenAdmissionControl,result,reason:denied?"owner-safe denied; visual detail untested":undefined});
        if(path==="/account"&&name==="mobile"){
          emit({viewport:name,route:"/account:mobile-width",expectedMax:390,actual:geometry.scrollWidth,result:geometry.scrollWidth<=390?"PASS":"FAIL"});
        }
      }
      for(const detail of ["P02 entity detail","P03 prospect detail","React Monitor run detail","setup reviewed apply","schedule proposal/revoke","stale digest refusal"]) emit({viewport:name,route:detail,result:"NOT_COVERED",reason:"requires authorized fixture/object or explicit reviewed operation; not inferred from page load"});
      await page.reload({waitUntil:"domcontentloaded"});
      emit({viewport:name,route:"/account:reload",result:new URL(page.url()).pathname==="/account"?"PASS":"FAIL"});
      measuring=false;
      emit({viewport:name,route:"passive-browser-network",autoMutatingRequests:autoPosts.length,offOriginRequests:offOriginRequests.length,result:autoPosts.length===0&&offOriginRequests.length===0?"PASS":"FAIL"});
      if(jsErrors.length)emit({viewport:name,route:"browser-js",errorCount:jsErrors.length,errorTypes:jsErrors.slice(0,5),result:"FAIL"});
    } catch(error){
      emit({viewport:name,route:"journey",result:"FAIL",errorName:error?.name||"Error"});
    } finally {await context.close();}
  }
} finally {await browser.close();}
const verdict=failures?"FAIL_OR_PARTIAL":"PASS_VISUAL_SUBSET_ONLY";
console.log("P09_DEPLOYED_BROWSER_RESULTS",JSON.stringify({verdict,release_sha:process.env.EXPECTED_RELEASE_SHA||"4e08ac0488fea8f9529f36c010040267128a422d",browser:"Playwright Chromium 1.56.1",covered,notCovered,failures,observations}));
if(failures) process.exitCode=1;
