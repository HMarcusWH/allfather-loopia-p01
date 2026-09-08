import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { root } from "./test-helpers.js";
const importRe = /(?:import|export)\s+(?:[^"']+\s+from\s+)?["']([^"']+)["']/g;
async function graph(entry) {
  const seen = new Set();
  async function visit(file) {
    const abs = path.resolve(file); if (seen.has(abs)) return; seen.add(abs);
    const text = await readFile(abs,"utf8");
    for (const m of text.matchAll(importRe)) {
      const spec=m[1]; if(!spec.startsWith(".")) continue;
      let target=path.resolve(path.dirname(abs),spec);
      if(!path.extname(target)) { for(const ext of [".js",".jsx"]) { try { await readFile(target+ext,"utf8"); target+=ext; break; } catch {} } }
      await visit(target);
    }
  }
  await visit(entry); return [...seen];
}
test("production graph cannot enter fixture, dev or deferred trees", async () => {
  const files=await graph(path.join(root,"src/index.jsx"));
  for(const file of files){ const rel=path.relative(root,file).replaceAll(path.sep,"/"); assert.ok(!rel.startsWith("fixtures/"),rel); assert.ok(!rel.startsWith("dev/"),rel); assert.ok(!rel.startsWith("deferred/"),rel); }
  assert.ok(files.some((f)=>f.endsWith("canonical-console-data-source.js")));
  assert.ok(!files.some((f)=>f.endsWith("fixture-console-data-source.js")));
});
