import { ConsoleDataSource } from "../src/data/console-data-source.js";
import { availableEnvelope, unavailableEnvelope } from "../src/data/response-types.js";
import { applyCollectionQuery } from "../src/data/collection-query.js";
import { workspace } from "./data/workspace.js";
import { descriptorFixtures, collectionFixtures } from "./descriptor-fixtures.js";
import { READ_META } from "../src/data/surface-reads.js";
import { STATIC_NAV_IDS } from "../src/data/navigation.js";
export class FixtureConsoleDataSource extends ConsoleDataSource {
 async readContext(){ return availableEnvelope("fixture.context",workspace,{asOf:workspace.as_of}); }
 async readDescriptor(readId){ const data=descriptorFixtures[readId]; return data ? availableEnvelope(`fixture.${readId}`,data,{asOf:workspace.as_of}) : unavailableEnvelope(`fixture.${readId}`,"FIXTURE_READ_NOT_DEFINED"); }
 async query(collectionName,input={}){ const page=applyCollectionQuery(collectionName,collectionFixtures[collectionName]??[],input); return Object.freeze({...page,asOf:workspace.as_of}); }
 getAdapterAvailability(){ return Object.freeze(Object.keys(READ_META)); }
 getUsableNavigationIds(){ return STATIC_NAV_IDS; }
}
