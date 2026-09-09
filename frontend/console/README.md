# Operator Console frontend — governed browser reads

This directory is private repository/build-side source. It is **not** a Node service on Loopia and it is not runtime authority.

Production entry: `src/index.jsx` → `HttpConsoleDataSource`. The datasource can call only the explicit same-origin PR6 read allowlist: M12 context, P01 released battlecards, P02 released entity/relationship snapshots, P03 priority/monitored outputs, P04 role distributions, P05 internal marketing drafts, and the environment-pinned M10 decision ledger. There is no generic collection endpoint and no fixture fallback.

P02 requires an explicit governed `visibility_scope`; P04 requires an explicit `target_org_role`. The browser supplies only those presentation lenses. Tenant, workspace and operator scope always come from the authenticated PHP session, and the existing owner read model re-runs its own permission, entitlement and release gates.

Development entry: `dev/fixture-entry.jsx` → `FixtureConsoleDataSource`. Full MVP4 fixture/reference objects remain separately under `fixtures/reference/`, `dev/reference/`, and `deferred/mobile/` and are unreachable from the production import graph.

Build and stage: `npm ci && npm test && npm run build && npm run stage`.

- `build.mjs` writes the deterministic private build to `dist/`.
- `stage-assets.mjs` validates `dist/manifest.json`, refuses path traversal, symlinks, sourcemaps, unsupported asset types and unmanifested files, then publishes only the validated content-hashed JS/CSS/SVG set to `public_html/assets/operator-console/`.
- The staged directory is generated/ignored and is never canonical source.

The PHP route `/console/app` remains authenticated and the server-rendered `/console` remains the fallback. Browser reads are GET-only presentation transport. This phase adds no beta entitlement bundle, no account lifecycle, no live observation, no scheduler handler, no customer-visible output and no external action.

The frontend may present governed capability. It never invents capability, scope, tenant identity, permission, release authority, or external action.
