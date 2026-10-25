# Allfather Monitor — credit-free, exact-head qualification

**Prepared 2026-10-25. Status: operator procedure, not authorization or a passing receipt.** GitHub Actions jobs may conclude `failure` while containing zero executed steps because runners/credits are unavailable. Do not relabel them as application test failures, and never mark a skipped lane PASS. The existing source-controlled `ops/ci/qualify-release.sh local` already exports a specified Git SHA and runs the hosted workflow's underlying steps without GitHub-hosted runner minutes. This wrapper only provisions disposable local MariaDB services and passes their private connection files to that existing runner.

## Preconditions

- A locally cloned, authorized `HMarcusWH/allfather-loopia-p01` Git repository, on Windows WSL/Linux with a **local Docker engine** (Docker Desktop's Linux engine is acceptable). No remote Docker context.
- PHP **8.2 CLI** and the repository-required extensions including **pdo_mysql**. This is the local runner's current pinned PHP line, **not** an excuse to change a failed PHP version check. The staging Dockerfile uses PHP **8.5.12**; real-host qualification of that version is an additional, separately evidenced step.
- Node 22.16.0 and npm 10.9.2, matching the existing frontend pins; Git, Bash, Docker, OpenSSL, curl as required by the repo. Run `php -m | grep -i pdo_mysql`, `node -v`, `npm -v`, and `docker info` before starting.
- A dedicated private output directory that **does not exist yet**, outside all web roots, persisted backups, runtime state and staging storage. The script creates local-only disposable MariaDB containers with temporary, unique credentials; it does not read runtime `.env` files or connect to Railway, Loopia, provider APIs, production or preserved restore targets.
- A reviewer has approved running these **local tests**. That authorization is not permission to merge, deploy, add source capture, call a model, publish or modify production grants.

## Fetch and qualify each exact PR commit

From the local WSL clone:

```bash
cd ~/code/allfather-loopia-p01
# Fetching PR heads does not change main or grant deploy authority.
git fetch origin refs/pull/66/head
git cat-file -e 76739121acbce3b79583f78fb5e6003c0bbc958c^{commit}

# Once the local qualification wrapper from its separate reviewed PR is present:
bash ops/ci/qualify-local-docker.sh \
  --sha=76739121acbce3b79583f78fb5e6003c0bbc958c \
  --out="$HOME/private/allfather/qa-issue65-76739121-$(date -u +%Y%m%dT%H%M%SZ)"
```

Repeat with the exact heads for PR #67 (`910b0c354d7badc551dc1b39c763837a541d1c72`) and #69 (`c8f012f65439cee1964943516f38b5d17fb0a022`), each in its **own** clean output directory. PR #68 is documentation-only but still gets a truthful exact-head receipt. If a PR moves, use its **new** head; do not transplant an old receipt.

The wrapper checks a **local** Docker context, creates only disposable loopback-bound MariaDB 10.6 and 10.11 containers, waits for SQL readiness, then invokes:

```bash
bash ops/ci/qualify-release.sh local \
  --ref=<exact-40-hex-commit> \
  --out=<fresh-private-output-directory> \
  --env-10.6=<ephemeral-credential-file> \
  --env-10.11=<ephemeral-credential-file>
```

The existing source-controlled runner executes the exact exported Git objects with independent job worktrees: foundation, unit, migrations fresh/upgrade/no-op, strict all-layer tests under both MariaDB versions, frontend clean install and deterministic release preparation, and the aggregate verifier. **Any skip, missing lane, wrong PHP/Node version or failed step makes the receipt FAIL.** Temporary containers and credentials are deleted on success or failure; logs and the sanitized receipt remain at the private output path.

## Review and gate

Inspect `release-qualification-receipt.json`, `reports/qualification-10.6.json`, `reports/qualification-10.11.json` and `jobs/release-preparation/work/preflight.json`. The authoritative tool is:

```bash
php ops/ci/verify-qualification.php verify-receipt \
  --receipt=<out>/release-qualification-receipt.json \
  --lanes-dir=<out>/reports \
  --preflight=<out>/jobs/release-preparation/work/preflight.json
```

**Receipts are LOCAL/self-reported**, bind commit/tree and versioned tool files, and may be filed as conformance evidence. They are not GitHub-hosted CI and do not replace actual PHP 8.5.12 staging runtime qualification, independent PR review, source rights/stand-down checks, appropriate human owner approvals, protected-host data policy, or controlled rollout approval. Railway staging tracks `main`; a merge can automatically trigger staging deployment. Confirm approved merge/rollout posture before merging anything, even if local qualification is green.

**Safety rules:** do not share the private `logs/` tree, container env files, API keys or runtime `.env`. No destructive DB test may touch an existing staging/production/retained-restoration database. The wrapper never modifies the repo checkout or the application's database by design. This workaround changes **where tests execute**, never **what it takes to pass**.

## Evidence of the wrapper itself (not a database qualification)

On this assistant's execution host, `bash -n` passed, and a mock-local-Docker/qualifier harness verified correct commit forwarding, creation and cleanup of two ephemeral containers, and retention of a local receipt with no credential files copied to its output. Real Docker/MariaDB/PDO MySQL and PHP 8.2 were **not available on that host**, so no exact-head MariaDB or PHP 8.5.12 pass is asserted here.
