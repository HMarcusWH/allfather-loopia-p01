import test from "node:test";
import assert from "node:assert/strict";
import path from "node:path";
import { root, textFiles } from "./test-helpers.js";
test("production React source does not require inline style attributes", async()=>{
  const needle="style"+"={";
  for(const [file,text] of await textFiles(path.join(root,"src"),[".jsx"])) assert.equal(text.includes(needle),false,file);
});
