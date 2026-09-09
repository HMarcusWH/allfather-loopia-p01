import { copyFile, mkdir, readFile, readdir, rm, stat, lstat, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const defaultDistDir = path.join(here, "dist");
const defaultTargetDir = path.resolve(here, "../../public_html/assets/operator-console");

const SCRIPT_RE = /^console\.[A-Z0-9]+\.js$/;
const STYLE_RE = /^console\.[A-Z0-9]+\.css$/;
const ASSET_RE = /^assets\/[A-Za-z0-9_-]+\.[A-Z0-9]+\.svg$/;

function fail(message) {
  throw new Error(`operator-console staging refused: ${message}`);
}

function exactKeys(value, expected, where) {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    fail(`${where} must be an object`);
  }
  const actual = Object.keys(value).sort();
  const wanted = [...expected].sort();
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) {
    fail(`${where} keys must be exactly ${wanted.join(", ")}`);
  }
}

function validateRelativePath(candidate, pattern, where) {
  if (typeof candidate !== "string" || candidate.length === 0) {
    fail(`${where} must be a non-empty relative path`);
  }
  if (candidate.includes("\\") || candidate.includes("\0") || candidate.startsWith("/") || candidate.includes(":")) {
    fail(`${where} must be a local POSIX-style relative path`);
  }
  const pieces = candidate.split("/");
  if (pieces.some((piece) => piece === "" || piece === "." || piece === "..")) {
    fail(`${where} contains an unsafe path segment`);
  }
  if (!pattern.test(candidate)) {
    fail(`${where} has an unsupported or unhashed filename: ${candidate}`);
  }
  return candidate;
}

async function listFiles(root, current = "") {
  const absolute = current === "" ? root : path.join(root, ...current.split("/"));
  const entries = await readdir(absolute, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const relative = current === "" ? entry.name : `${current}/${entry.name}`;
    const full = path.join(absolute, entry.name);
    const info = await lstat(full);
    if (info.isSymbolicLink()) {
      fail(`symlink is not allowed in build output: ${relative}`);
    }
    if (info.isDirectory()) {
      files.push(...await listFiles(root, relative));
      continue;
    }
    if (!info.isFile()) {
      fail(`unsupported filesystem entry in build output: ${relative}`);
    }
    files.push(relative.replaceAll(path.sep, "/"));
  }
  return files.sort();
}

async function validateManifest(distDir) {
  const distInfo = await lstat(distDir).catch(() => null);
  if (distInfo === null || !distInfo.isDirectory() || distInfo.isSymbolicLink()) {
    fail("dist directory is missing, not a directory, or a symlink");
  }

  const manifestPath = path.join(distDir, "manifest.json");
  const manifestInfo = await lstat(manifestPath).catch(() => null);
  if (manifestInfo === null || !manifestInfo.isFile() || manifestInfo.isSymbolicLink()) {
    fail("manifest.json is missing or is not a regular file");
  }

  let manifest;
  try {
    manifest = JSON.parse(await readFile(manifestPath, "utf8"));
  } catch {
    fail("manifest.json is not valid JSON");
  }

  exactKeys(manifest, ["version", "entry", "assets"], "manifest");
  if (manifest.version !== 1) {
    fail(`unsupported manifest version: ${String(manifest.version)}`);
  }
  exactKeys(manifest.entry, ["script", "style"], "manifest.entry");

  const script = validateRelativePath(manifest.entry.script, SCRIPT_RE, "manifest.entry.script");
  const style = validateRelativePath(manifest.entry.style, STYLE_RE, "manifest.entry.style");
  if (!Array.isArray(manifest.assets)) {
    fail("manifest.assets must be an array");
  }
  const assets = manifest.assets.map((asset, index) => validateRelativePath(asset, ASSET_RE, `manifest.assets[${index}]`));
  if (new Set(assets).size !== assets.length) {
    fail("manifest.assets contains duplicate paths");
  }

  const expected = ["manifest.json", script, style, ...assets].sort();
  const actual = await listFiles(distDir);
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    const unmanifested = actual.filter((entry) => !expected.includes(entry));
    const missing = expected.filter((entry) => !actual.includes(entry));
    fail(`build output does not exactly match manifest; unmanifested=[${unmanifested.join(", ")}], missing=[${missing.join(", ")}]`);
  }

  for (const relative of [script, style, ...assets]) {
    const full = path.join(distDir, ...relative.split("/"));
    const info = await stat(full);
    if (!info.isFile()) {
      fail(`manifest path is not a regular file: ${relative}`);
    }
  }

  return Object.freeze({ version: 1, entry: Object.freeze({ script, style }), assets: Object.freeze([...assets]) });
}

async function assertTargetIsNotSymlink(targetDir) {
  const info = await lstat(targetDir).catch(() => null);
  if (info?.isSymbolicLink()) {
    fail("target directory is a symlink");
  }
}

export async function stageAssets({ distDir = defaultDistDir, targetDir = defaultTargetDir } = {}) {
  const manifest = await validateManifest(path.resolve(distDir));
  const absoluteDist = path.resolve(distDir);
  const absoluteTarget = path.resolve(targetDir);
  const targetRoot = path.parse(absoluteTarget).root;
  if (
    absoluteTarget === targetRoot
    || absoluteTarget === absoluteDist
    || absoluteDist.startsWith(`${absoluteTarget}${path.sep}`)
    || absoluteTarget.startsWith(`${absoluteDist}${path.sep}`)
  ) {
    fail("target directory is unsafe to replace");
  }

  // Validate EVERYTHING before touching the currently served asset set. If validation fails, the
  // previous stage survives intact. Once replacement starts, manifest.json is written LAST: an
  // interrupted copy therefore fails closed at runtime rather than pointing PHP at a partial set.
  await assertTargetIsNotSymlink(absoluteTarget);
  await rm(absoluteTarget, { recursive: true, force: true });
  await mkdir(absoluteTarget, { recursive: true });

  for (const relative of [manifest.entry.script, manifest.entry.style, ...manifest.assets]) {
    const source = path.join(absoluteDist, ...relative.split("/"));
    const destination = path.join(absoluteTarget, ...relative.split("/"));
    await mkdir(path.dirname(destination), { recursive: true });
    await copyFile(source, destination);
  }

  await writeFile(
    path.join(absoluteTarget, "manifest.json"),
    `${JSON.stringify(manifest, null, 2)}\n`,
    "utf8"
  );

  return manifest;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await stageAssets();
}
