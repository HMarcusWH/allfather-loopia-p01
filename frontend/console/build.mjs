import { build } from "esbuild";
import { mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const outdir = path.join(here, "dist");
await rm(outdir, { recursive: true, force: true });
await mkdir(outdir, { recursive: true });

const result = await build({
  absWorkingDir: here,
  entryPoints: ["src/index.jsx"],
  outdir,
  bundle: true,
  format: "esm",
  platform: "browser",
  target: ["es2022"],
  jsx: "automatic",
  minify: true,
  legalComments: "none",
  sourcemap: false,
  metafile: true,
  write: true,
  entryNames: "console.[hash]",
  assetNames: "assets/[name].[hash]",
  loader: { ".svg": "file" },
  define: {
    "process.env.NODE_ENV": '"production"'
  },
  logLevel: "info"
});

const outputs = Object.keys(result.metafile.outputs)
  .map((entry) => path.relative(here, entry).replaceAll(path.sep, "/"))
  .sort();
const script = outputs.find((entry) => /^dist\/console\.[A-Z0-9]+\.js$/.test(entry));
const style = outputs.find((entry) => /^dist\/console\.[A-Z0-9]+\.css$/.test(entry));
if (!script || !style) {
  throw new Error("Expected one content-hashed JS and CSS console entry");
}
const assets = outputs.filter((entry) => entry !== script && entry !== style && !entry.endsWith(".map"));
const manifest = {
  version: 1,
  entry: {
    script: script.replace(/^dist\//, ""),
    style: style.replace(/^dist\//, "")
  },
  assets: assets.map((entry) => entry.replace(/^dist\//, ""))
};
await writeFile(path.join(outdir, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, "utf8");
