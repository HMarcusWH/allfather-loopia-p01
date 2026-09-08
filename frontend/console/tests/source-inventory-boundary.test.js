import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
const here = path.dirname(fileURLToPath(import.meta.url));
test("deferred and needs-read-model views are not in initial production navigation", async () => {
  const nav = await readFile(path.join(here, "../src/data/navigation.js"), "utf8");
  for (const forbidden of ["packet-trace", "p07a-status", "p02-polymorphic", "gsmc", "approvals", "m13", "m14", "mcm", "road-test", "roadmap"]) {
    assert.equal(nav.includes(`id: "${forbidden}"`), false, `${forbidden} must remain absent from initial production nav`);
  }
});
