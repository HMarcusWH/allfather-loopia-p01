# P09 staging browser acceptance

**Status:** operational gate, not satisfied by source merge or CI.

P09 changes the operator-facing browser surface. Its source qualification proves route ownership,
authorization boundaries, escaping, setup/schedule digest binding and frontend build integrity. It
does **not** prove the deployed browser experience. This protocol is the required real-host
desktop/mobile acceptance before P09 is called operationally complete.

Nothing in this document grants execution authority, admits a schedule, enters a provider secret,
runs a model, or creates customer-visible output.

## 1. Preconditions

Run only against the exact P09 candidate that has:

1. complete hosted CI on MariaDB 10.6 and 10.11 with zero fail/skip;
2. a clean frontend production build and copied asset manifest;
3. inert staging deployment + host probe + ordinary staging smoke;
4. P07/P06 operational prerequisites recorded separately;
5. no scheduler admission and no external action surface.

Record before browsing:

- full release SHA;
- prepared source fingerprint;
- console asset manifest digest;
- staging host;
- observed PHP/MariaDB versions;
- browser name/version and viewport.

Use a TEST/DEMO workspace with synthetic/non-sensitive material only.

## 2. Desktop journey

Use a normal desktop viewport (at least 1280x720).

### Authentication and workspace

1. Load login over HTTPS.
2. Authenticate as the test operator.
3. Select the intended workspace.
4. Refresh once and confirm the workspace remains session-derived.
5. Confirm no tenant/workspace/operator scope appears as an editable URL parameter.

### Monitor workbench

Open `/console/monitor` and verify:

- missions and runs render as structured sections;
- exact JSON/request material is behind explicit expanders rather than dumped into layout;
- source/model strings render as text, not HTML;
- no horizontal overlap or unreadable clipping;
- an unknown run selector and a foreign-workspace run selector are indistinguishable at the HTTP/UI boundary where designed;
- opening the page causes no source fetch, model call, release, retry or authority mutation.

### Setup

Open `/console/monitor/setup`.

1. Confirm the page contains no field for tenant/workspace/operator scope.
2. Build one valid setup request using already-private prerequisites.
3. Submit **plan**.
4. Record the displayed plan digest.
5. Change a material field and prove the old digest cannot be applied.
6. Restore the reviewed plan and submit **apply** with its exact digest.
7. Follow the receipt and confirm only the reviewed setup objects changed.

The page must not:

- assign a role;
- subscribe a tenant;
- create/rotate credentials;
- admit an execution grant;
- approve a prompt;
- write an owner decision;
- activate source/model execution;
- render profile/credential bytes.

### Schedule administration

Open `/console/monitor/schedule`.

Verify the browser may only:

- plan;
- propose;
- suspend;
- revoke.

There must be **no browser admission action**. A proposal grants no recurrence. Use only an inert
test schedule definition; do not admit it.

Confirm:

- the reviewed plan digest equals the digest proposal records;
- proposing the same first version is idempotent;
- suspend/revoke only narrow authority;
- no model stage is reachable from the browser page.

### P02 / P03 detail reads

Open one owner-visible:

- P02 entity snapshot detail;
- P03 prospect output detail.

Verify:

- index -> detail navigation works;
- refresh/back works;
- scope still comes from session;
- sibling/foreign workspace selectors answer like nonexistent selectors where specified;
- P03 does not fabricate a battlecard list;
- source/model text remains escaped.

### Monitor runs

Open the React Monitor run list and one run detail.

Verify the API/UI exposes only metadata intended by the read adapter. It must not expose candidate
text, prompt bodies, exact request contents, review assessment text, credentials or private paths.

## 3. Mobile journey

Repeat the same major pages at a phone-sized viewport (approximately 390x844):

- login/workspace;
- Monitor workbench;
- setup plan/apply receipt;
- schedule plan/propose/suspend/revoke;
- P02 detail;
- P03 detail;
- Monitor runs.

Required visual checks:

- no horizontal page overflow except an intentionally scrollable code/data region;
- buttons remain reachable and labelled;
- digest values wrap/scroll without covering controls;
- forms do not lose labels;
- tables/cards remain readable;
- navigation remains usable after refresh/back;
- expanders do not overlay adjacent sections.

## 4. Negative HTTP/browser checks

Capture status + a minimal sanitized observation for:

- anonymous console/API request -> expected login/JSON 401 behavior;
- missing CSRF on mutating form -> refused;
- stale/forged setup digest -> refused;
- stale/forged schedule digest -> refused;
- foreign object selector -> owner-safe not-found behavior;
- unsupported browser authority attempt (admission/approval/etc.) -> no route/control exists.

Do not capture session cookies, CSRF values, profile text, candidate text or credential material in
the evidence artifact.

## 5. Evidence

Retain a small sanitized acceptance record containing:

- release SHA + source fingerprint + console manifest digest;
- staging target;
- browser/version + viewport;
- each journey/check as PASS/FAIL;
- request IDs/status codes where useful;
- screenshots only when they contain no sensitive material;
- any layout defect with page/viewport/reproduction steps.

A PASS establishes only deployed P09 browser acceptance. It does not establish live-read activation,
ROAD_TEST success, production readiness, customer visibility or owner GO.

## 6. Failure handling

Any security/authority/scope defect is a merge/deployment blocker.

A visual-only defect belongs in P09, followed by:

1. repair;
2. frontend tests/build;
3. full exact-head hosted qualification;
4. redeploy the repaired candidate;
5. repeat the affected desktop/mobile journey.

Never loosen an authority test to make a browser defect disappear.
