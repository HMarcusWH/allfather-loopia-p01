# Operator Console frontend — Phase 2 shell

This directory is private repository/build-side source. It is **not** a Node service on Loopia and it is not runtime authority.

Production entry: `src/index.jsx` → `CanonicalConsoleDataSource`. No fixture fallback exists. The canonical datasource still has zero product adapters in PR5, so the authenticated React shell can render but governed production reads remain deliberately unavailable until the browser-read integration phase.

Development entry: `dev/fixture-entry.jsx` → `FixtureConsoleDataSource`. Full MVP4 fixture/reference objects are retained separately under `fixtures/reference/`, `dev/reference/`, and `deferred/mobile/` and are unreachable from the production import graph.

Build and stage: `npm ci && npm test && npm run build && npm run stage`.

- `build.mjs` writes the deterministic private build to `dist/`.
- `stage-assets.mjs` validates `dist/manifest.json`, refuses path traversal, symlinks, sourcemaps, unsupported asset types and unmanifested files, then publishes only the validated content-hashed JS/CSS/SVG set to `public_html/assets/operator-console/`.
- `manifest.json` is written last. An interrupted stage therefore leaves the authenticated PHP shell without a valid manifest and it fails closed instead of guessing an asset filename.
- The staged directory is generated/ignored and is never canonical source.

The PHP route `/console/app` serves the local staged bundle only after the normal platform session check. PR5 adds no browser API, no product adapter, no new permission, no entitlement, no live observation and no external action.

The frontend may expose governed capability later. It never invents capability, scope, tenant identity, permission, release authority, or external action.
