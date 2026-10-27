# R05 — Source protection and pinned R5 test workflow

Prepared 27 October 2026. State: SOURCE_ONLY; owner settings NOT MODIFIED.

## Source hardening implemented

The dedicated R5 collector fixture workflow now pins both actions to exact GitHub-resolved commits:
- actions/checkout v4.4.0: 11d5960a326750d5838078e36cf38b85af677262
- actions/setup-python v5.6.0: a26af69be951a213d495a4c3e4e4022e16d87065

The displayed test step now runs the discovered fixture suite without claiming a fixed test count; follow-up R06 adds additional cases. No change to the collector's effective GraphQL queries, backed-up volume data, provider credentials, workflow permissions, or operations. The test job remains pull_request path-scoped, contents: read and network-free except standard GitHub Actions setup.

These pins were checked using the corresponding GitHub tag reference endpoints. Future upgrades require independent digest verification and a new review.

## External setting still missing

The live public GitHub repository has an active main ruleset (ID 25838159, Protect main) with PR-only changes, force-push and deletion prevention, and review-thread resolution. The ruleset does NOT currently include the required_status_checks rule for the exact GitHub Actions check named code qualification. Source CI is green but that does not make the check a mandatory merge gate.

Only a GitHub owner/admin can apply the setting. Required action:
1. Inspect Settings > Rules > Rulesets > Protect main.
2. Preserve the PR and non-fast-forward/deletion restrictions and feasible independent-review setting.
3. Add the required check code qualification from GitHub Actions. Prefer strict up-to-date checks before merge; avoid unrelated optional inert preflight workflows.
4. Re-read the actual ruleset via GitHub API/owner UI; the resulting rules must contain required_status_checks with the exact check and expected source. Do not infer the setting from this document or workflow YAML.

Railway allfather-staging/staging/allfather-php-mvp already read back source.checkSuites=true on main, which waits for CI. This is a deployment admission condition, not an authorization for historical source use or the R5 restore.

The BuildKit source-image failures in Issue #99 are a separate blocker. DO NOT change build tools/images, disable Wait for CI, retry destructive operations, alter source data, or merge this maintenance PR to force a new Railway deploy. Do not treat Railway's BUILD_IMAGE failures as PHP runtime failures without evidence.

Exit: verified code-only GitHub workflow checks; exact ruleset owner change and subsequent successful host deployment require separate evidence. 
