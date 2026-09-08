export class ConsoleDataSource {
  async readContext() { throw new Error("ConsoleDataSource.readContext must be implemented"); }
  async readDescriptor(_readId, _query = {}) { throw new Error("ConsoleDataSource.readDescriptor must be implemented"); }
  async query(_collectionName, _query = {}) { throw new Error("ConsoleDataSource.query must be implemented"); }
  getAdapterAvailability() { return Object.freeze([]); }
  getUsableNavigationIds() { return null; }
}
