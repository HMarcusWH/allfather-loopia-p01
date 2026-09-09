import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdtemp, mkdir, readFile, readdir, rm, symlink, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { stageAssets } from "../stage-assets.mjs";

async function fixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), "allfather-console-stage-"));
  const dist = path.join(root, "dist");
  const target = path.join(root, "public");
  await mkdir(path.join(dist, "assets"), { recursive: true });
  const manifest = {
    version: 1,
    entry: { script: "console.A1B2C3D4.js", style: "console.E5F6G7H8.css" },
    assets: ["assets/logo-lockup.Z9Y8X7W6.svg"]
  };
  await writeFile(path.join(dist, manifest.entry.script), "console.log('shell');\n", "utf8");
  await writeFile(path.join(dist, manifest.entry.style), "body{}\n", "utf8");
  await writeFile(path.join(dist, manifest.assets[0]), "<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n", "utf8");
  await writeFile(path.join(dist, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, "utf8");
  return { root, dist, target, manifest };
}

async function files(root, current = "") {
  const dir = current === "" ? root : path.join(root, ...current.split("/"));
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const relative = current === "" ? entry.name : `${current}/${entry.name}`;
    if (entry.isDirectory()) out.push(...await files(root, relative));
    else out.push(relative);
  }
  return out.sort();
}

async function digestTree(root) {
  const hash = createHash("sha256");
  for (const relative of await files(root)) {
    hash.update(relative);
    hash.update("\0");
    hash.update(await readFile(path.join(root, ...relative.split("/"))));
    hash.update("\0");
  }
  return hash.digest("hex");
}

async function withFixture(work) {
  const state = await fixture();
  try {
    await work(state);
  } finally {
    await rm(state.root, { recursive: true, force: true });
  }
}

test("stager publishes only the validated manifest set and removes stale files", async () => {
  await withFixture(async ({ dist, target, manifest }) => {
    await mkdir(target, { recursive: true });
    await writeFile(path.join(target, "stale.js"), "stale\n", "utf8");

    await stageAssets({ distDir: dist, targetDir: target });

    assert.deepEqual(await files(target), [
      manifest.assets[0],
      manifest.entry.script,
      manifest.entry.style,
      "manifest.json"
    ].sort());
    assert.equal((await readFile(path.join(target, "manifest.json"), "utf8")).includes("stale.js"), false);
  });
});

test("stager is deterministic for identical validated input", async () => {
  await withFixture(async ({ dist, target }) => {
    await stageAssets({ distDir: dist, targetDir: target });
    const first = await digestTree(target);
    await stageAssets({ distDir: dist, targetDir: target });
    const second = await digestTree(target);
    assert.equal(second, first);
  });
});

test("stager refuses unmanifested build output before replacing the existing stage", async () => {
  await withFixture(async ({ dist, target }) => {
    await mkdir(target, { recursive: true });
    await writeFile(path.join(target, "sentinel.txt"), "old-stage\n", "utf8");
    await writeFile(path.join(dist, "console.A1B2C3D4.js.map"), "{}\n", "utf8");

    await assert.rejects(stageAssets({ distDir: dist, targetDir: target }), /does not exactly match manifest/);
    assert.equal(await readFile(path.join(target, "sentinel.txt"), "utf8"), "old-stage\n");
  });
});

test("stager refuses path traversal, remote-looking paths and unsupported auxiliary types", async () => {
  await withFixture(async ({ dist, target, manifest }) => {
    const manifestPath = path.join(dist, "manifest.json");

    await writeFile(manifestPath, JSON.stringify({ ...manifest, entry: { ...manifest.entry, script: "../console.A1B2C3D4.js" } }), "utf8");
    await assert.rejects(stageAssets({ distDir: dist, targetDir: target }), /unsafe path segment/);

    await writeFile(manifestPath, JSON.stringify({ ...manifest, entry: { ...manifest.entry, script: "https:console.A1B2C3D4.js" } }), "utf8");
    await assert.rejects(stageAssets({ distDir: dist, targetDir: target }), /local POSIX-style relative path/);

    await writeFile(manifestPath, JSON.stringify({ ...manifest, assets: ["assets/logo-lockup.Z9Y8X7W6.png"] }), "utf8");
    await assert.rejects(stageAssets({ distDir: dist, targetDir: target }), /unsupported or unhashed filename/);
  });
});

test("stager refuses symlinks in build output", async (t) => {
  await withFixture(async ({ dist, target }) => {
    const link = path.join(dist, "assets", "alias.Z9Y8X7W6.svg");
    try {
      await symlink(path.join(dist, "assets", "logo-lockup.Z9Y8X7W6.svg"), link);
    } catch (error) {
      if (error?.code === "EPERM" || error?.code === "EACCES") {
        t.skip("filesystem does not permit symlink creation");
        return;
      }
      throw error;
    }
    const manifestPath = path.join(dist, "manifest.json");
    const manifest = JSON.parse(await readFile(manifestPath, "utf8"));
    manifest.assets.push("assets/alias.Z9Y8X7W6.svg");
    await writeFile(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, "utf8");

    await assert.rejects(stageAssets({ distDir: dist, targetDir: target }), /symlink is not allowed/);
  });
});

test("stager refuses a target nested inside the build output", async () => {
  await withFixture(async ({ dist }) => {
    await assert.rejects(
      stageAssets({ distDir: dist, targetDir: path.join(dist, "published") }),
      /target directory is unsafe to replace/
    );
    assert.equal((await files(dist)).includes("published/manifest.json"), false);
  });
});
