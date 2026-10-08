import { chromium } from "playwright";
import { createHash } from "node:crypto";
const base="https://allfather-php-mvp-staging.up.railway.app";
const email=process.env.TEST_LOGIN_EMAIL, password=process.env.TEST_LOGIN_PASSWORD;
if(!email||!password||!email.endsWith("@example.test"))throw new Error("synthetic test login only");
const browser=await chromium.launch({headless:true,args:["--no-sandbox"]});
const pages=["/console","/console/app","/console/monitor","/console/monitor/setup","/console/monitor/schedule","/console/briefings","/console/distributions","/console/marketing","/account"];
const out=[];let failures=0;let screenshots=0;
async function capture(name,buf){
 const encoded=buf.toString("base64"),size=1800,parts=Math.ceil(encoded.length/size);
 console.log("P09_SCREENSHOT_META",JSON.stringify({name,bytes:buf.length,sha256:createHash("sha256").update(buf).digest("hex"),chunks:parts}));
 for(let i=0;i<parts;i++){
  console.log("P09_IMAGE_CHUNK|"+name+"|"+i+"|"+parts+"|"+encoded.slice(i*size,(i+1)*size));
  await new Promise(r=>setTimeout(r,35));
 }screenshots++;
}
try{
 for(const [kind,viewport] of [["desktop",{width:1440,height:900}],["mobile",{width:390,height:844}]]){
  const context=await browser.newContext({viewport,deviceScaleFactor:1,colorScheme:"light",reducedMotion:"reduce"});
  const page=await context.newPage();
  const errors=[];page.on("pageerror",err=>errors.push(err.message.slice(0,140)));
  let res=await page.goto(base+"/login",{waitUntil:"domcontentloaded",timeout:30000});
  let loaded=(res?.status()===200), passwordField=await page.locator('input[name="password"]').count();
  out.push({viewport:kind,route:"/login",status:res?.status(),loginField:passwordField,loaded});
  if(!loaded||!passwordField){failures++;await context.close();continue}
  if(kind==="desktop"||kind==="mobile")await capture(kind+"-login",await page.screenshot({type:"jpeg",quality:42,fullPage:false}));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password"]').evaluate(el=>el.closest("form").requestSubmit());
  await page.waitForURL(u=>u.pathname!=="/login",{timeout:20000});
  if(new URL(page.url()).pathname!=="/console"){out.push({viewport:kind,stage:"sign-in",urlPath:new URL(page.url()).pathname,pass:false});failures++;await context.close();continue}
  await page.goto(base+"/console/workspaces",{waitUntil:"domcontentloaded"});
  const first=page.locator('input[name="workspace_id"]').first();
  if(await first.count()){await first.evaluate(el=>el.closest("form").requestSubmit());await page.waitForURL(u=>u.pathname==="/console",{timeout:12000});}
  else {out.push({viewport:kind,stage:"workspace-selection",pass:false});failures++;}
  for(const path of pages){
   res=await page.goto(base+path,{waitUntil:"domcontentloaded",timeout:30000});
   await page.waitForTimeout(120);
   const info=await page.evaluate(()=>({documentWidth:document.documentElement.scrollWidth,viewportWidth:window.innerWidth,bodyWidth:document.body?.scrollWidth||0,heading:document.querySelector("h1")?.textContent?.trim().slice(0,90)||"",title:document.title,hasViewportMeta:!!document.querySelector('meta[name="viewport"]')}));
   const status=res?.status()??0,overflow=info.documentWidth>viewport.width+3,ok=status===200&&!overflow;
   out.push({viewport:kind,route:path,status,overflow,documentWidth:info.documentWidth,viewportWidth:info.viewportWidth,heading:info.heading,hasViewportMeta:info.hasViewportMeta,pass:ok});
   if(!ok)failures++;
   if(path==="/console/monitor")await capture(kind+"-monitor",await page.screenshot({type:"jpeg",quality:42,fullPage:false}));
  }
  if(errors.length){out.push({viewport:kind,jsErrors:errors.slice(0,5)});failures+=errors.length}
  await context.close();
 }
}finally{await browser.close()}
console.log("P09_BROWSER_VISUAL_RESULTS",JSON.stringify({verdict:failures===0?"PASS":"PARTIAL_OR_FAIL",failures,screenshots,checks:out}));
if(failures)process.exitCode=1;
