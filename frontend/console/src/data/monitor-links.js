// The only link the Monitor runs view renders: the scriptless workbench for one run. Anything else a
// read might carry is ignored rather than followed — no scheme, no host, no other path.
const WORKBENCH=/^\/console\/monitor\?run=[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
export function workbenchHref(value){ return typeof value==="string"&&WORKBENCH.test(value)?value:null; }
