import test from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { root, walk } from "./test-helpers.js";
async function digestDist(){ const hash=createHash("sha256"); const files=(await walk(path.join(root,"dist"))).sort(); for(const f of files){ hash.update(path.relative(path.join(root,"dist"),f)); hash.update(await readFile(f)); } return hash.digest("hex"); }
test("clean production build is deterministic and contains only reviewed local output", async()=>{
  execFileSync(process.execPath,[path.join(root,"build.mjs")],{cwd:root,stdio:"inherit"}); const first=await digestDist();
  execFileSync(process.execPath,[path.join(root,"build.mjs")],{cwd:root,stdio:"inherit"}); const second=await digestDist();
  assert.equal(first,second);
  const files=(await walk(path.join(root,"dist"))).map((f)=>path.relative(path.join(root,"dist"),f).replaceAll(path.sep,"/"));
  assert.ok(files.includes("manifest.json"));
  assert.ok(files.some((f)=>/^console\.[A-Z0-9]+\.js$/.test(f)));
  assert.ok(files.some((f)=>/^console\.[A-Z0-9]+\.css$/.test(f)));
  assert.equal(files.some((f)=>f.endsWith(".map")),false);
  assert.equal(files.some((f)=>/fixture|screenshot|\.jsx$|package-lock|package\.json/i.test(f)),false);
  const manifest = JSON.parse(await readFile(path.join(root, "dist", "manifest.json"), "utf8"));
  const bundle = await readFile(path.join(root, "dist", manifest.entry.script), "utf8");
  const logo = manifest.assets.find((asset) => /^assets\/logo-lockup\.[A-Z0-9]+\.svg$/.test(asset));
  assert.ok(logo, "the manifest includes the shell logo");
  const logoReference = bundle.match(/["']([^"']*logo-lockup\.[A-Z0-9]+\.svg)["']/)?.[1];
  assert.ok(logoReference, "the built shell references its logo");
  for (const documentPath of ["/console/app", "/console/app/"]) {
    assert.equal(
      new URL(logoReference, `https://example.test${documentPath}`).href,
      `https://example.test/assets/operator-console/${logo}`,
      "the browser resolves the logo to its published path from the console route"
    );
  }
  const forbiddenRemoteDependency = /(?:https?:)?\/\/(?:unpkg\.com|cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|esm\.sh|cdn\.skypack\.dev)/i;
  for(const f of files.filter((x)=>x.endsWith(".js"))){
    const text=await readFile(path.join(root,"dist",f),"utf8");
    assert.equal(text.includes("react.development"),false);
    // ReactDOM legitimately embeds standards namespace URIs such as
    // http://www.w3.org/2000/svg. The Phase-1 boundary is no external runtime
    // dependency/CDN/bootstrap, not "no URI-shaped standards constants".
    assert.equal(forbiddenRemoteDependency.test(text),false);
  }
});
