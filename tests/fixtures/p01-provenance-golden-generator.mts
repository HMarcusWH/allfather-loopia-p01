// Run from canonical PR366 checkout with tsx. Inputs are synthetic; expected values come from canonical code.
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const { capturedTextProvenance, classifyCapturedChange, capturedChangeDigest } = await import(pathToFileURL(resolve('packages/allfather-control-substrate/src/p01/text-provenance.ts')).href);
const rows = [
 ['unicode-and-duplicates', "\u00a0Priser\ufeff\nÅtgärd\nPriser\n\n\0literal\0", "Priser\nÅtgärd ny\n\0literal\0", 0.25],
 ['small-decimal-threshold', 'A', 'B', 0.000001],
 ['small-exponent-threshold', 'A', 'B', 0.0000001],
 ['fractional-exponent-threshold', 'A', 'B', 0.000000123],
 ['same-normalized-lines', 'A\nB', ' B \nA\nA', 0],
 ['empty', '   ', '\n\ufeff', 0],
 ['half-tie-1-of-16', Array.from({length:15},(_,i)=>`line${i}`).join('\n'), Array.from({length:16},(_,i)=>`line${i}`).join('\n'), 0.25],
 ['binary-round-1-of-2000', Array.from({length:1999},(_,i)=>`line${i}`).join('\n'), Array.from({length:2000},(_,i)=>`line${i}`).join('\n'), 0.0005],
 ['binary-round-249-of-2000', Array.from({length:1751},(_,i)=>`line${i}`).join('\n'), Array.from({length:2000},(_,i)=>`line${i}`).join('\n'), 0.1245],
 ['below-round-boundary', Array.from({length:2000},(_,i)=>`line${i}`).join('\n'), Array.from({length:2001},(_,i)=>`line${i}`).join('\n'), 1],
];
const cases=rows.map(([name,b,a,threshold])=>{const beforeHash='a'.repeat(64),afterHash='b'.repeat(64); const before=capturedTextProvenance(beforeHash,b),after=capturedTextProvenance(afterHash,a);const classified=classifyCapturedChange(beforeHash,afterHash,before,after,threshold);return {name,beforeText:b,afterText:a,input:{beforeSnapshotId:'snapshot-before',afterSnapshotId:'snapshot-after',beforeContentHash:beforeHash,afterContentHash:afterHash,before,after,threshold,...classified},expectedDigest:capturedChangeDigest({beforeSnapshotId:'snapshot-before',afterSnapshotId:'snapshot-after',beforeContentHash:beforeHash,afterContentHash:afterHash,before,after,threshold,...classified})};});
for (const [name,bh,ah] of [['identical-hash','c','c'],['no-retained-proof','a','b']]) {const input={beforeSnapshotId:'snapshot-before',afterSnapshotId:'snapshot-after',beforeContentHash:bh,afterContentHash:ah,before:null,after:null,threshold:0.25,...classifyCapturedChange(bh,ah,null,null,0.25)};cases.push({name,beforeText:null,afterText:null,input,expectedDigest:capturedChangeDigest(input)});}
writeFileSync(process.argv[2],JSON.stringify({canonical_commit:'2096c7dac38565c78a3e306bea9af19299ffbeb5',source:'packages/allfather-control-substrate/src/p01/text-provenance.ts',cases},null,2)+'\n');
