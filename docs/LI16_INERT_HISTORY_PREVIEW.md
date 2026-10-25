# LI-16 R1 competitor-history presentation precursor

**Status:** SOURCE IMPLEMENTED — OFFLINE/INERT, 2026-10-25. **U01, U02 and U09 remain pending.** This is not a customer-facing history release or completed LI-16.

## What is now implemented

- `app/Http/ReadAdapters/CompetitorHistoryAdapter.php`: a metadata-only session-scoped adapter for the existing P01 `ObservationHistory::listSnapshots` owner. It derives tenant/workspace/operator exclusively from authenticated session fields, requires UUIDv4 competitor and cursor selectors, bounds requested pages to 50, calls the owner anew for every page, maps denied vs not-found vs unavailable, and only emits an exact allowlist of vetted capture/diff fields. The owner remains responsible for **both** `competition_monitoring.read` and `evidence.read`, commercial entitlement, source-card status, current M05 rights and cursor checks. This adapter does not query SQL directly and does not expand owner rights.
- `frontend/console/src/data/competitor-history-view-model.js`: versioned, fail-closed presentation mapping of `li16.competitor-history-metadata.v1`. Describes baseline, unchanged, structurally changed, multiple comparisons and unavailable comparisons; only the structural magnitude values NONE/MINOR/MATERIAL/UNKNOWN may be shown, as **structural**. Business materiality remains `NOT_ASSESSED`, observation assessment `NOT_IMPLEMENTED`, and release `NOT_INFERRED`. Private/candidate/source URL fields are never copied to view output.
- `frontend/console/src/views/p01/competitor-history.jsx`: a pure React component that can display only an explicitly passed result; it loads no data, initiates no network, schedules nothing, and exposes an optional **click-only** pagination callback. React escapes names and timestamps as text. No new route, navigation entry, data-source descriptor or server handler has been registered.
- `tests/unit/li16_competitor_history_adapter.php`, `tests/integration/li16_competitor_history_adapter.php`, `frontend/console/tests/history-preview.test.js`, `tests/foundation/li16_history_presentation_boundary.php`: synthetic refusal and closed-allowlist cases, real disposable P01/M05 scoped reads, rights withdrawal, pagination, JSX injection prevention and unreachable-active-surface assertions.

## What is deliberately NOT delivered

No new competitor/source identity mapping, time-cut historical rights (U03), source-body disclosure, model-extracted descriptive observation persistence, materiality or independence certification, automatic customer link, GET endpoint, mutable form, schema migration, notifications, model calls, scheduled work, human review or release. The existing `P01_SOURCE_WATCH` remains the only wired watchlist/readiness surface.

Before routing this module, settle canonical **U01/U02/U09**, independently inspect current rights and scope, register a separately reviewed HTTP reader with anonymous/cross-tenant/CSRF/negative browser checks, and add pagination/navigation only where a permitted user can access actual history. A source test passing does not authorize those changes.

**Qualification:** Full strict PHP suite against disposable MariaDB 10.6 and 10.11, hosted GitHub frontend/Node tests and target PHP 8.5.12 host separately. A hosted `release-preparation` preflight refusal due to existing `docs/decisions/*.json` is tracked separately and must not be waived to make the badge green.
