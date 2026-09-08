import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { root, textFiles } from "./test-helpers.js";
const forbidden = [
  "window" + "." + "AFB",
  "window" + "." + "lucide",
  "text" + "/" + "babel",
  "dangerously" + "SetInnerHTML",
  "inner" + "HTML",
  "fonts." + "googleapis.com",
  "un" + "pkg.com",
  "cdnjs." + "cloudflare.com"
];
test("production source contains no legacy globals, injection or runtime network bootstrap", async()=>{
  for(const [file,text] of await textFiles(path.join(root,"src"))){
    for(const needle of forbidden) assert.equal(text.includes(needle),false,`${file}: ${needle}`);
    assert.equal(/https?:\/\//.test(text), false, `${file}: unexpected remote URL`);
    assert.equal(text.includes("unsafe-inline"), false, `${file}: unsafe-inline`);
    assert.equal(text.includes("unsafe-eval"), false, `${file}: unsafe-eval`);
  }
});
