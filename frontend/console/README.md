# Operator Console frontend — Phase 1

This directory is private repository/build-side source. It is **not** a Node service on Loopia and it is not runtime authority.

Production entry: `src/index.jsx` → `CanonicalConsoleDataSource`. No fixture fallback exists. The canonical datasource has zero product adapters in Phase 1 and therefore adapter-aware production navigation fails closed.

Development entry: `dev/fixture-entry.jsx` → `FixtureConsoleDataSource`. Full MVP4 fixture/reference objects are retained separately under `fixtures/reference/`, `dev/reference/`, and `deferred/mobile/` and are unreachable from the production import graph.

Build: `npm ci && npm test && npm run build`. `dist/` is private/ignored in Phase 1. No public assets are staged until Phase 2.

The frontend may expose governed capability later. It never invents capability, scope, tenant identity, permission, release authority, or external action.
