import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
const root = process.argv[2];
if (!root || !process.argv[3]) throw new Error('Usage: tsx generator.mts CANONICAL_REPO OUTPUT_JSON');
const src = root + '/packages/allfather-control-substrate/src/promptops/';
const {createAllfatherPromptStore,createCustomAllfatherPromptStore} = await import(pathToFileURL(src+'in-memory-prompt-store.ts').href);
const {resolveAllfatherPrompt} = await import(pathToFileURL(src+'resolver.ts').href);
const {evaluateAllfatherPromptOpsPromotion} = await import(pathToFileURL(src+'promotion-check.ts').href);
const {ALLFATHER_PROMPTOPS_REQUIRED_ROLES,hashAllfatherPromptVersion,hashAllfatherPromptSchema} = await import(pathToFileURL(src+'records.ts').href);
const now='2026-09-27T00:00:00.000Z';
function dataset(mode='typescript-fallback') {
 const store=createAllfatherPromptStore(mode);
 const bindings=ALLFATHER_PROMPTOPS_REQUIRED_ROLES.map(r=>store.getBindingByRole(r));
 const versions=bindings.map(b=>store.getPromptVersion(b.promptId,b.version));
 return {storeMode:mode,bindings,versions};
}
const makeStore=d=>createCustomAllfatherPromptStore({mode:d.storeMode,bindings:d.bindings,versions:d.versions});
const resolutions=[];
for(const mode of ['typescript-fallback','durable-store-simulator']) for(const role of ALLFATHER_PROMPTOPS_REQUIRED_ROLES) {
 const data=dataset(mode); resolutions.push({name:mode+':'+role,store:data,role,now,expected:resolveAllfatherPrompt({store:makeStore(data),role,nowUtc:now})});
}
for(const [name,mutate] of [
 ['missing_binding',d=>d.bindings.shift()],['missing_version',d=>d.versions.shift()],
 ['draft_binding',d=>d.bindings[0].approvalStatus='draft'],['revoked_prompt',d=>d.versions[0].approvalStatus='revoked'],
 ['future_binding',d=>d.bindings[0].activation={startsAt:'2027-01-01T00:00:00.000Z'}],
 ['expired_binding',d=>d.bindings[0].activation={startsAt:'2026-01-01T00:00:00.000Z',endsAt:now}],
 ['future_prompt',d=>d.versions[0].activation={startsAt:'2027-01-01T00:00:00.000Z'}],
 ['role_mismatch',d=>d.versions[0].role='source_quality_critic'],
 ['stale_prompt',d=>d.versions[0].systemPrompt+='changed'],
 ['binding_digest_mismatch',d=>d.bindings[0].expectedPromptSha256='0'.repeat(64)],
 ['schema_digest_mismatch',d=>d.bindings[0].expectedSchemaSha256='0'.repeat(64)],
]) {
 const data=dataset(); mutate(data); const role=ALLFATHER_PROMPTOPS_REQUIRED_ROLES[0];
 resolutions.push({name,store:data,role,now,expected:resolveAllfatherPrompt({store:makeStore(data),role,nowUtc:now})});
}
const promotions=[];
for(const mode of ['typescript-fallback','durable-store-simulator']) for(const strict of [false,true]) {
 const data=dataset(mode); promotions.push({name:mode+':'+strict,store:data,strict,now,requiredRoles:ALLFATHER_PROMPTOPS_REQUIRED_ROLES,expected:evaluateAllfatherPromptOpsPromotion({store:makeStore(data),strictDurableStoreMode:strict,nowUtc:now})});
}
for(const name of ['missing_role','duplicate_required_role']) {
 const data=dataset('durable-store-simulator'); const requiredRoles=[...ALLFATHER_PROMPTOPS_REQUIRED_ROLES];
 if(name==='missing_role') data.bindings.pop(); else requiredRoles.push(requiredRoles[0]);
 promotions.push({name,store:data,strict:true,now,requiredRoles,expected:evaluateAllfatherPromptOpsPromotion({store:makeStore(data),strictDurableStoreMode:true,nowUtc:now,requiredRoles})});
}
const hashes=[];
for (const schema of [{type:'object',properties:{z:{type:'string'},a:{type:'boolean'}},additionalProperties:false}, {title:'Svenska åäö 🧭\u2028rad',properties:{empty:{},array:[]}}, {'10':'tio','2':'två',nested:{b:1,a:null}}]) {
 const input={schemaVersion:'golden.v1',schema,outputContractId:'fixture.output'};
 hashes.push({...input,expected:hashAllfatherPromptSchema(input)});
}
const output={source_ref:'32c98a7191b7a4ae7a45d745917f39521f8f5112',source_scope:'Pure canonical promptops files; later current working changes outside promptops do not affect this generation.',generated_by:'Executing canonical TypeScript functions, no provider/network/database',resolutions,promotions,hashes};
writeFileSync(process.argv[3],JSON.stringify(output,null,2)+'\n');
console.log(JSON.stringify({resolutions:resolutions.length,promotions:promotions.length,hashes:hashes.length}));
