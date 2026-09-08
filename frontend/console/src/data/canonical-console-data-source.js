import { ConsoleDataSource } from "./console-data-source.js";
import { unavailableEnvelope } from "./response-types.js";
import { unavailableCollectionPage } from "./collection-query.js";
import { READS, readMeta } from "./surface-reads.js";
export class CanonicalConsoleDataSource extends ConsoleDataSource {
  async readContext() { return unavailableEnvelope(READS.CONTEXT, "AUTHENTICATED_SHELL_NOT_INTRODUCED"); }
  async readDescriptor(readId) { const meta=readMeta(readId); return unavailableEnvelope(meta.id, meta.status === "UNKNOWN_READ" ? "UNKNOWN_READ_DESCRIPTOR" : "GOVERNED_READ_NOT_WIRED"); }
  async query(collectionName) { return unavailableCollectionPage(`console.collection.${collectionName}`); }
  getAdapterAvailability() { return Object.freeze([]); }
  getUsableNavigationIds() { return null; }
}
