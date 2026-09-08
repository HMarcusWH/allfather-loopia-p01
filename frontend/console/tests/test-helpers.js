import { readFile, readdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
export const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
export async function walk(dir) { const out=[]; for (const ent of await readdir(dir,{withFileTypes:true})) { const p=path.join(dir,ent.name); if(ent.isDirectory()) out.push(...await walk(p)); else out.push(p); } return out; }
export async function textFiles(dir, exts=[".js",".jsx",".css",".json",".mjs"]) { const files=await walk(dir); const pairs=[]; for(const f of files) if(exts.includes(path.extname(f))) pairs.push([f,await readFile(f,"utf8")]); return pairs; }
