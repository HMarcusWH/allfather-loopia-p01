#!/usr/bin/env bash
# One qualification procedure for hosted CI and for local reproduction.
#
#   bash ops/ci/qualify-release.sh step <name>
#       Run exactly one qualification step in this tree. Every `run:` in .github/workflows/ci.yml
#       that decides qualification is one of these, and nothing else.
#
#   bash ops/ci/qualify-release.sh local --out=<new-dir> [--ref=<commit>] --env-10.6=<file> --env-10.11=<file>
#       Run EVERY step of EVERY job, in the workflow's order, each job from its own fresh
#       `git archive` export of one exact commit, then write <out>/release-qualification-receipt.json.
#       The receipt is written whether or not the run passed. Uncommitted changes in the checkout
#       this is started from are never part of what is qualified.
#
#   bash ops/ci/qualify-release.sh ci-receipt
#       Inside a GitHub Actions run only: assemble the same receipt from the hosted job results.
#
# THE STEP LIST IS NOT HERE. It is `ReleaseQualification::JOBS`, read through
# `verify-qualification.php job-steps`, and tests/foundation/qualification_runner_boundary.php fails
# the build if ci.yml, this file and that list disagree about which steps exist or their order.
#
# A LOCAL RUN IS A LOCAL RUN. Its receipt says `LOCAL`, `hosted_ci: false` and `self_reported: true`,
# and the verifier refuses one that says anything else. It is code qualification for one commit on
# one recorded machine — not GitHub green, not a Loopia host result, and it authorizes nothing.
#
# Each lane database named by --env-* is TRUNCATED by the suite. Never point one at a runtime.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
verifier="$root/ops/ci/verify-qualification.php"

die() {
  echo "qualify-release: $*" >&2
  exit 2
}

now_ms() {
  php -r 'echo (int) floor(microtime(true) * 1000);'
}

utc_now() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

# Where a step keeps its intermediate files. The hosted runner's temporary directory by default.
# Never under a served root: preflight refuses to read a configuration file from one.
work_dir() {
  local dir="${QUALIFY_WORK_DIR:-}"
  if [ -z "$dir" ]; then
    [ -n "${RUNNER_TEMP:-}" ] || die "set QUALIFY_WORK_DIR (a GitHub runner sets RUNNER_TEMP instead)"
    dir="${RUNNER_TEMP}/qualification"
  fi
  mkdir -p "$dir"
  dir="$(cd "$dir" && pwd)"
  case "/${dir}/" in
    */public_html/*) die "work directory must not be under a served root: $dir" ;;
  esac
  printf '%s\n' "$dir"
}

# THE EXACT COMMIT, read back from git rather than taken from the event. On a pull_request event the
# checkout is the merge commit, and that IS the tree being qualified. A local run names it explicitly.
release_sha() {
  if [ -n "${QUALIFY_RELEASE_SHA:-}" ]; then
    printf '%s\n' "$QUALIFY_RELEASE_SHA"
  else
    git rev-parse HEAD
  fi
}

lane() {
  local lane="${QUALIFY_LANE:-}"
  [[ "$lane" =~ ^[0-9]+\.[0-9]+$ ]] || die "set QUALIFY_LANE to a MariaDB line such as 10.6"
  printf '%s\n' "$lane"
}

lane_env() {
  local file="${QUALIFY_ENV_FILE:-}"
  [ -n "$file" ] || die "set QUALIFY_ENV_FILE to the lane database's configuration file"
  case "$file" in
    /*) ;;
    *) file="$root/$file" ;;
  esac
  [ -f "$file" ] || die "no such lane configuration file: $file"
  printf '%s\n' "$file"
}

report_dir() {
  local dir="${QUALIFY_REPORT_DIR:-$root}"
  mkdir -p "$dir"
  (cd "$dir" && pwd)
}

migration_count() {
  local count
  count="$(find "$root/database/migrations" -maxdepth 1 -type f -name '*.sql' | wc -l)"
  printf '%d\n' "$count"
}

# ------------------------------------------------------------------------------------ foundation

step_frontend() {
  cd "$root/frontend/console"
  npm ci
  npm test
  npm run build
  npm run stage
}

step_runtime() {
  php -v
  php -r 'echo "curl: ", curl_version()["version"], PHP_EOL;'
  php -r 'echo "CURLOPT_RESOLVE: ", defined("CURLOPT_RESOLVE") ? "yes" : "NO", PHP_EOL;'
}

step_lock() {
  php -r "json_decode(file_get_contents('upstream.lock.json'), true, 512, JSON_THROW_ON_ERROR);"
}

# `public` was renamed to `public_html` to match Loopia's fixed document-root name. The old path once
# lingered in CI and `find` merely warned, so nothing under the SERVED directory was syntax-checked —
# green by checking less. A missing directory is therefore a failure, never a warning.
step_php_syntax() {
  local dir
  for dir in app public_html worker ops tests; do
    if [ ! -d "$dir" ]; then
      echo "PHP syntax: expected directory '$dir' is missing — refusing to lint less than intended" >&2
      return 1
    fi
  done
  find app public_html worker ops tests -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null
}

# The authority boundary and the no-external-action structural proof. These need no database and
# must never be skipped.
step_foundation_layer() {
  php tests/run.php --layer=foundation --strict
}

step_unit_layer() {
  php tests/run.php --layer=unit --strict
}

# --------------------------------------------------------------------------------------- mariadb

step_db_version() {
  php "$verifier" db-version --env="$(lane_env)" --lane="$(lane)"
}

# A LANE PROVES A CLEAN INSTALL: every migration applied, in lexical order, to an empty database —
# which is what the hosted service container always is. A lane database that already held a schema
# would prove only that nothing was left to do.
step_migrate() {
  local env expected out summary
  env="$(lane_env)"
  expected="$(migration_count)"
  out="$(php ops/migrate.php up --env="$env")"
  printf '%s\n' "$out"
  summary="$(printf '%s\n' "$out" | tail -n 1)"
  case "$summary" in
    "applied=${expected} skipped=0 drifted=0 "*) ;;
    *)
      echo "expected a clean install of ${expected} migrations; got: ${summary}" >&2
      echo "a qualification lane starts from an EMPTY database" >&2
      return 1
      ;;
  esac
}

# Applying a second time must be a clean no-op, and must report no drift: a migration edited after
# being applied means the database and the repository disagree about the schema.
step_migrate_repeat() {
  local env expected out summary
  env="$(lane_env)"
  expected="$(migration_count)"
  out="$(php ops/migrate.php up --env="$env")"
  printf '%s\n' "$out"
  summary="$(printf '%s\n' "$out" | tail -n 1)"
  case "$summary" in
    "applied=0 skipped=${expected} drifted=0 "*) ;;
    *)
      echo "the second application was not a no-op: ${summary}" >&2
      return 1
      ;;
  esac
}

step_migrate_status() {
  local env expected out applied
  env="$(lane_env)"
  expected="$(migration_count)"
  out="$(php ops/migrate.php status --env="$env")"
  printf '%s\n' "$out"
  applied="$(printf '%s\n' "$out" | grep -c '^APPLIED ' || true)"
  if [ "$applied" -ne "$expected" ] || printf '%s\n' "$out" | grep -qv '^APPLIED '; then
    echo "every migration must read APPLIED after two applications (${applied}/${expected})" >&2
    return 1
  fi
}

# LOOPIA_PROBE_001, non-network. On a healthy runner: staging READY, live read NOT_QUALIFIED — the
# second because nothing was demonstrated, which is the honest answer for a run that made no
# request. `--allow-network` is deliberately NOT passed.
step_probe() {
  local lane
  lane="$(lane)"
  php ops/loopia-probe/probe.php --env="$(lane_env)" | tee "$(report_dir)/probe-${lane}.json"
}

step_evidence() {
  php ops/evidence/validate.php
}

# The WHOLE suite in one step, deliberately: a layer whose tests exist cannot be green by omission.
# `--strict` makes a skip a failure. The sanitized report carries counts and the versions READ BACK
# from the connection the suite actually used — never a credential, DSN, fixture or message.
step_full_suite() {
  local lane
  lane="$(lane)"
  TEST_ENV_FILE="$(lane_env)" php tests/run.php --strict --report-json="$(report_dir)/qualification-${lane}.json"
}

# --------------------------------------------------------------------------- release preparation

# ASSERTED, NOT INSTALLED. `prepare-release.sh` refuses a mismatched npm by design, and quietly
# installing the pinned one would hide the drift this check exists to surface.
step_npm_pin() {
  local declared expected actual
  cd "$root/frontend/console"
  declared="$(node -p "require('./package.json').packageManager || ''")"
  case "$declared" in
    npm@*) expected="${declared#npm@}" ;;
    *)
      echo "package.json must pin npm as packageManager; got: ${declared:-unset}" >&2
      return 1
      ;;
  esac
  actual="$(npm --version)"
  echo "node=$(node --version)  npm pinned=${expected}  npm in use=${actual}"
  if [ "$actual" != "$expected" ]; then
    echo "npm in use does not match the repository pin" >&2
    return 1
  fi
}

step_prepare() {
  local sha work out
  sha="$(release_sha)"
  work="$(work_dir)"
  out="${work}/prepared-release"
  echo "preparing ${sha} into ${out}"
  bash "$root/ops/deploy/prepare-release.sh" "$sha" "$out" | tee "${work}/prepare-release.out"
  tail -n 1 "${work}/prepare-release.out" > "${work}/prepare-release.verdict.json"
}

step_verify_prepared() {
  local work
  work="$(work_dir)"
  php "$verifier" prepared-release \
    --verdict="${work}/prepare-release.verdict.json" \
    --sha="$(release_sha)" \
    --dir="${work}/prepared-release"
}

# BOUND TO THE OBJECT DATABASE, NOT TO THE SCRIPT'S OWN REPORT. `release_ref` is the argument
# prepare-release.sh was handed, printed back; it says nothing about the bytes it produced. So the
# bytes are compared against a SECOND export taken here. Every tracked file must be present and
# identical, and the ONLY additions allowed are the generated build outputs, named exactly.
step_verify_export() {
  local sha work prepared independent tracked compare_status filter_status unexpected allowed
  sha="$(release_sha)"
  work="$(work_dir)"
  prepared="${work}/prepared-release"
  independent="${work}/independent-export"
  rm -rf "$independent"
  mkdir -p "$independent"
  git archive "$sha" | tar -x -C "$independent"

  tracked="$(find "$independent" -type f | wc -l)"
  echo "  tracked files in ${sha}: ${tracked}"
  if [ "$tracked" -eq 0 ]; then
    echo "the independent export is empty — nothing was compared" >&2
    return 1
  fi

  # GNU diff exits 0 when the inputs are the same, 1 when they differ and 2 ON TROUBLE. Only 1 is an
  # expected status here — the generated build outputs always differ — and 2 means files were never
  # compared at all.
  compare_status=0
  diff -r --brief "$independent" "$prepared" > "${work}/export.diff.raw" || compare_status=$?
  if [ "$compare_status" -gt 1 ]; then
    echo "diff could not complete the comparison (exit ${compare_status}) — the release is NOT verified" >&2
    return 1
  fi

  sed -e "s|${independent}|<git-object>|g" -e "s|${prepared}|<prepared>|g" \
    "${work}/export.diff.raw" > "${work}/export.diff"
  sed 's/^/  /' "${work}/export.diff"

  # Produced by `npm ci`, `npm run build` and `npm run stage` INSIDE the exported tree. Anything
  # else, in either direction, is a difference from the named commit.
  allowed='^Only in <prepared>/(frontend/console: (dist|node_modules)|public_html/assets: operator-console)$'
  # grep exits 1 on NO match — the good case here — and 2 on error. An unreadable summary must not
  # read as "nothing unexpected".
  filter_status=0
  unexpected="$(grep -Ev "$allowed" "${work}/export.diff")" || filter_status=$?
  if [ "$filter_status" -gt 1 ]; then
    echo "the diff summary could not be read (grep exit ${filter_status}) — the release is NOT verified" >&2
    return 1
  fi
  if [ -n "$unexpected" ]; then
    echo "the prepared release is not the exact Git object ${sha}:" >&2
    echo "$unexpected" >&2
    return 1
  fi
  echo "  every tracked file is byte-identical to ${sha}"
}

# THE ROOT RSYNC CONTRACT IS EXECUTED, NOT GREPPED. The real Loopia deployment exposed a gap that
# source-text assertions missed: /.env.* protected host secrets but also suppressed the tracked
# .env.example the strict suite needs. Rehearse the destructive sync against a disposable fake
# remote. The deployed foundation suite must also retain its tracked CI workflow dependency while
# all other Git/GitHub metadata stays excluded.
step_rsync_rehearsal() {
  local work prepared target excluded_source
  command -v rsync >/dev/null
  work="$(work_dir)"
  prepared="${work}/prepared-release"

  # Source qualification proves that the manual-only workflow is in the exact Git
  # object, while the deployment-shaped copy must deliberately exclude it.
  [ -f "$prepared/.github/workflows/inert-staging-preflight.yml" ] ||
    die "manual inert-copy workflow missing from prepared Git-object source"
  grep -q '^  workflow_dispatch:' "$prepared/.github/workflows/inert-staging-preflight.yml" ||
    die "inert-copy workflow must remain manually dispatched"

  target="${work}/rsync-contract-target"
  rm -rf "$target"
  mkdir -p \
    "$target/.git" \
    "$target/.github/workflows" \
    "$target/storage" \
    "$target/public_html/assets/operator-console" \
    "$target/frontend/console/node_modules" \
    "$target/frontend/console/dist"

  printf 'HOST_RUNTIME_SECRET_SENTINEL\n' > "$target/.env"
  printf 'HOST_QUALIFICATION_SECRET_SENTINEL\n' > "$target/.env.qualification"
  printf 'HOST_FUTURE_SECRET_SENTINEL\n' > "$target/.env.future"
  printf 'OLD_TEMPLATE_SENTINEL\n' > "$target/.env.example"
  printf 'OLD_WORKFLOW_SENTINEL\n' > "$target/.github/workflows/ci.yml"
  printf 'HOST_GIT_SENTINEL\n' > "$target/.git/config"
  printf 'HOST_GITHUB_SENTINEL\n' > "$target/.github/workflows/host-only.yml"
  printf 'RUNTIME_LOG_SENTINEL\n' > "$target/storage/request.log"
  printf 'OLD_HASH_SENTINEL\n' > "$target/public_html/assets/operator-console/console.OLDHASH.js"
  printf 'NODE_MODULE_SENTINEL\n' > "$target/frontend/console/node_modules/keep.txt"
  printf 'DIST_SENTINEL\n' > "$target/frontend/console/dist/keep.txt"
  printf 'DELETE_ME\n' > "$target/obsolete-release-file.txt"

  rsync -a --delete \
    --include='/.env.example' \
    --exclude-from="$prepared/ops/deploy/staging-rsync-excludes.txt" \
    "$prepared/" "$target/"

  cmp "$prepared/.env.example" "$target/.env.example"
  cmp "$prepared/.github/workflows/ci.yml" "$target/.github/workflows/ci.yml"
  [ ! -e "$target/.github/workflows/inert-staging-preflight.yml" ] ||
    die "manual-only inert workflow must never enter the staged application copy"

  # Adversarial source entries prove the narrow workflow include does not open siblings.
  excluded_source="$(mktemp -d "${work}/rsync-excluded-source.XXXXXX")"
  mkdir -p "$excluded_source/.git" "$excluded_source/.github/workflows" "$excluded_source/.github/private"
  printf 'EXCLUDED_GIT_SENTINEL\n' > "$excluded_source/.git/config"
  printf 'EXCLUDED_WORKFLOW_SENTINEL\n' > "$excluded_source/.github/workflows/other.yml"
  printf 'EXCLUDED_GITHUB_SENTINEL\n' > "$excluded_source/.github/private/other.txt"
  rsync -a --exclude-from="$prepared/ops/deploy/staging-rsync-excludes.txt" \
    "$excluded_source/" "$target/"
  grep -qx 'HOST_GIT_SENTINEL' "$target/.git/config"
  grep -qx 'HOST_GITHUB_SENTINEL' "$target/.github/workflows/host-only.yml"
  test ! -e "$target/.github/workflows/other.yml"
  test ! -e "$target/.github/private"
  cmp "$prepared/.github/workflows/ci.yml" "$target/.github/workflows/ci.yml"
  grep -qx 'HOST_RUNTIME_SECRET_SENTINEL' "$target/.env"
  grep -qx 'HOST_QUALIFICATION_SECRET_SENTINEL' "$target/.env.qualification"
  grep -qx 'HOST_FUTURE_SECRET_SENTINEL' "$target/.env.future"
  grep -qx 'RUNTIME_LOG_SENTINEL' "$target/storage/request.log"
  grep -qx 'OLD_HASH_SENTINEL' "$target/public_html/assets/operator-console/console.OLDHASH.js"
  grep -qx 'NODE_MODULE_SENTINEL' "$target/frontend/console/node_modules/keep.txt"
  grep -qx 'DIST_SENTINEL' "$target/frontend/console/dist/keep.txt"
  test ! -e "$target/obsolete-release-file.txt"
  # Runtime storage is protected from the root sync; deploy its two policy files separately as on
  # Loopia, then execute the full no-database foundation layer from the copied tree.
  cp "$prepared/storage/.htaccess" "$prepared/storage/README.md" "$target/storage/"
  (cd "$target" && php tests/run.php --layer=foundation --strict)
  echo "  root rsync contract: qualification inputs deployed; host state preserved; target foundation passed"
}

# PREFLIGHT FROM THE PREPARED TREE, which is the whole point: preflighting a checkout would validate
# bytes nobody would deploy. The configuration is disposable and deliberately worthless — a
# throwaway key generated in-process, a `.invalid` origin that can never resolve, and database
# fields whose VALUES preflight never reads.
step_preflight() {
  local sha work env_file
  sha="$(release_sha)"
  work="$(work_dir)"
  env_file="${work}/staging-preflight.env"
  (
    umask 077
    cat > "$env_file" <<ENVEOF
APP_ENV=staging
APP_BASE_URL=https://staging.invalid
APP_RELEASE_REF=${sha}
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ci_preflight_rehearsal
DB_USER=ci_preflight
DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(16));')
APP_MASTER_KEY=$(php -r 'echo base64_encode(random_bytes(32));')
SUPPORT_CONTACT_TEXT=CI preflight rehearsal only - not a real support route.
ENVEOF
  )
  php "${work}/prepared-release/ops/deploy/preflight.php" \
    --env="$env_file" \
    --release-ref="$sha" \
    --mode=staging | tee "${work}/preflight.json"
}

step_verify_preflight() {
  php "$verifier" preflight --report="$(work_dir)/preflight.json" --sha="$(release_sha)"
}

# ------------------------------------------------------------------------- release qualification

# The reports are read rather than believed: each must be a COMPLETE strict run with zero failures
# and zero skips, and together they must cover 10.6 and 10.11 exactly once each.
step_verify_lanes() {
  php "$verifier" lanes --dir="${QUALIFY_LANE_REPORTS_DIR:-$root/reports}"
}

cmd_step() {
  local name="${1:-}"
  [ -n "$name" ] || die "step needs a name"
  cd "$root"
  case "$name" in
    frontend) step_frontend ;;
    runtime) step_runtime ;;
    lock) step_lock ;;
    php-syntax) step_php_syntax ;;
    foundation-layer) step_foundation_layer ;;
    unit-layer) step_unit_layer ;;
    db-version) step_db_version ;;
    migrate) step_migrate ;;
    migrate-repeat) step_migrate_repeat ;;
    migrate-status) step_migrate_status ;;
    probe) step_probe ;;
    evidence) step_evidence ;;
    full-suite) step_full_suite ;;
    npm-pin) step_npm_pin ;;
    prepare) step_prepare ;;
    verify-prepared) step_verify_prepared ;;
    verify-export) step_verify_export ;;
    rsync-rehearsal) step_rsync_rehearsal ;;
    preflight) step_preflight ;;
    verify-preflight) step_verify_preflight ;;
    verify-lanes) step_verify_lanes ;;
    *) die "unknown step: $name" ;;
  esac
}

# ---------------------------------------------------------------------------------- local route

cmd_local() {
  local ref="HEAD" out="" arg lane file git_dir sha tree dirty=false
  local -a lane_envs=()
  for arg in "$@"; do
    case "$arg" in
      --out=*) out="${arg#--out=}" ;;
      --ref=*) ref="${arg#--ref=}" ;;
      --env-*=*)
        lane="${arg#--env-}"
        lane="${lane%%=*}"
        file="${arg#*=}"
        [[ "$lane" =~ ^[0-9]+\.[0-9]+$ ]] || die "unrecognised lane in $arg"
        case "$file" in
          /*) ;;
          *) file="$PWD/$file" ;;
        esac
        [ -f "$file" ] || die "no such lane configuration file: $file"
        lane_envs+=("QUALIFY_LANE_ENV_${lane//./_}=${file}")
        ;;
      *) die "unknown argument: $arg" ;;
    esac
  done
  [ -n "$out" ] || die "local needs --out=<new directory>"
  case "$out" in
    /*) ;;
    *) out="$PWD/$out" ;;
  esac
  [ ! -e "$out" ] || die "output already exists; refusing to mix qualifications: $out"
  case "/${out}/" in
    */public_html/*) die "output must not be under a served root: $out" ;;
  esac

  git_dir="$(git -C "$root" rev-parse --absolute-git-dir)"
  sha="$(git -C "$root" rev-parse --verify "${ref}^{commit}")"
  tree="$(git -C "$root" rev-parse --verify "${sha}^{tree}")"
  if [ -n "$(git -C "$root" status --porcelain)" ]; then
    dirty=true
    echo "qualify-release: the checkout has uncommitted changes. They are NOT qualified: ${sha} is exported and run as committed." >&2
  fi

  mkdir -p "$out/source"
  out="$(cd "$out" && pwd)"
  git -C "$root" archive "$sha" | tar -x -C "$out/source"
  [ -f "$out/source/ops/ci/qualify-release.sh" ] || die "${sha} predates this qualification runner"

  # Hand over to the COMMITTED runner. From here every command executed is the commit's own bytes;
  # only the object database is shared, through GIT_DIR, for the steps that must read Git objects.
  exec env \
    GIT_DIR="$git_dir" \
    QUALIFY_LOCAL_OUT="$out" \
    QUALIFY_RELEASE_SHA="$sha" \
    QUALIFY_RELEASE_TREE="$tree" \
    QUALIFY_REQUESTED_REF="$ref" \
    QUALIFY_INVOKING_DIRTY="$dirty" \
    ${lane_envs[@]+"${lane_envs[@]}"} \
    bash "$out/source/ops/ci/qualify-release.sh" local-run
}

cmd_code_local() {
  # The exporter, dirty-check, exact Git archive and independent job workdirs
  # remain IDENTICAL to the legacy LOCAL qualification route.
  QUALIFY_CODE_PROFILE=1 cmd_local "$@"
}

cmd_local_run() {
  local code_mode="${QUALIFY_CODE_PROFILE:-0}"
  local out="${QUALIFY_LOCAL_OUT:?}" sha="${QUALIFY_RELEASE_SHA:?}" tree="${QUALIFY_RELEASE_TREE:?}"
  local started finished tsv jobs job base lane lane_var env_file src jobwork steps step failed note status rc t0 t1 log index
  local receipt_status node_required npm_required
  started="$(utc_now)"
  tsv="${out}/steps.tsv"
  mkdir -p "${out}/reports" "${out}/logs" "${out}/jobs"
  : > "$tsv"

  # Captured before use: a failing command inside `for x in $(...)` would yield an empty list and a
  # run that silently did nothing.
  if [ "$code_mode" = 1 ]; then
    jobs="$(php "$verifier" code-local-jobs)"
  else
    jobs="$(php "$verifier" local-jobs)"
  fi
  for job in $jobs; do
    base="$job"
    lane=""
    env_file=""
    note=""
    if [[ "$job" == mariadb-* ]]; then
      base="mariadb"
      lane="${job#mariadb-}"
      lane_var="QUALIFY_LANE_ENV_${lane//./_}"
      env_file="${!lane_var:-}"
      [ -n "$env_file" ] || note="no --env-${lane} database was supplied"
    fi

    # EACH JOB FROM ITS OWN FRESH EXPORT, as each hosted job gets its own fresh checkout. The
    # foundation job's generated console assets must not be present when a database lane runs.
    src="${out}/jobs/${job}/source"
    jobwork="${out}/jobs/${job}/work"
    mkdir -p "$src" "$jobwork" "${out}/logs/${job}"
    git archive "$sha" | tar -x -C "$src"

    echo "=== ${job}"
    failed=0
    index=0
    if [ "$code_mode" = 1 ]; then
      steps="$(php "$verifier" code-job-steps "$base")"
    else
      steps="$(php "$verifier" job-steps "$base")"
    fi
    for step in $steps; do
      index=$((index + 1))
      if [ -n "$note" ]; then
        printf '%s\t%s\tNOT_RUN\t\t\t%s\n' "$job" "$step" "$note" >> "$tsv"
        echo "  - ${step}: NOT RUN (${note})"
        continue
      fi
      if [ "$failed" -ne 0 ]; then
        printf '%s\t%s\tNOT_RUN\t\t\tan earlier step in this job failed\n' "$job" "$step" >> "$tsv"
        echo "  - ${step}: NOT RUN (an earlier step failed)"
        continue
      fi
      log="${out}/logs/${job}/$(printf '%02d' "$index")-${step}.log"
      echo "  > ${step}"
      t0="$(now_ms)"
      set +e
      (
        cd "$src"
        export QUALIFY_WORK_DIR="$jobwork" QUALIFY_REPORT_DIR="${out}/reports" \
          QUALIFY_LANE_REPORTS_DIR="${out}/reports" QUALIFY_RELEASE_SHA="$sha"
        if [ -n "$lane" ]; then
          export QUALIFY_LANE="$lane" QUALIFY_ENV_FILE="$env_file"
        fi
        bash "$src/ops/ci/qualify-release.sh" step "$step"
      ) > "$log" 2>&1
      rc=$?
      set -e
      t1="$(now_ms)"
      if [ "$rc" -eq 0 ]; then
        status=PASS
      else
        status=FAIL
        failed=1
        tail -n 20 "$log" | sed 's/^/    | /'
      fi
      printf '%s\t%s\t%s\t%d\t%d\t\n' "$job" "$step" "$status" "$rc" "$((t1 - t0))" >> "$tsv"
      echo "    ${status} (exit ${rc}, $((t1 - t0)) ms)"
    done
  done
  finished="$(utc_now)"

  node_required="$(tr -d '[:space:]' < "$root/frontend/console/.node-version")"
  npm_required="$(sed -n 's/.*"packageManager": *"npm@\([^"]*\)".*/\1/p' "$root/frontend/console/package.json")"

  receipt_status=0
  local receipt_command="receipt" receipt_path="${out}/release-qualification-receipt.json"
  local report_arg="--preflight=${out}/jobs/release-preparation/work/preflight.json"
  if [ "$code_mode" = 1 ]; then
    receipt_command="code-receipt"
    receipt_path="${out}/code-qualification-receipt.json"
    report_arg="--package-verdict=${out}/jobs/package-integrity/work/prepare-release.verdict.json"
  fi
  php "$verifier" "$receipt_command" \
    --execution=local \
    --steps="$tsv" \
    --lanes-dir="${out}/reports" \
    "$report_arg" \
    --commit="$sha" \
    --tree="$tree" \
    --requested-ref="${QUALIFY_REQUESTED_REF:-}" \
    --dirty="${QUALIFY_INVOKING_DIRTY:-}" \
    --started-at="$started" \
    --finished-at="$finished" \
    --host-os="$(uname -srm)" \
    --php="$(php -r 'echo PHP_VERSION;')" \
    --node="$( (node --version 2>/dev/null || true) | sed 's/^v//')" \
    --npm="$(npm --version 2>/dev/null || true)" \
    --node-required="$node_required" \
    --npm-required="$npm_required" \
    > "$receipt_path" || receipt_status=$?

  echo "receipt: $receipt_path"
  echo "logs:    ${out}/logs (private, unsanitized — never file them as evidence)"
  return "$receipt_status"
}

# ------------------------------------------------------------------------------- hosted receipt

cmd_ci_receipt() {
  local mode="${1:-release}"
  local work tsv pair job result status receipt_status receipt_cmd receipt_file report_arg
  [ "${GITHUB_ACTIONS:-}" = "true" ] || die "ci-receipt describes a GitHub Actions run; a local run uses 'local'"
  cd "$root"
  work="$(work_dir)"
  tsv="${work}/jobs.tsv"
  : > "$tsv"
  local -a pairs=()
  if [ "$mode" = code ]; then
    pairs=(
      "foundation=${QUALIFY_NEEDS_FOUNDATION:-}"
      "mariadb=${QUALIFY_NEEDS_MARIADB:-}"
      "package-integrity=${QUALIFY_NEEDS_PACKAGE:-}"
      "code-qualification=${QUALIFY_VERIFY_LANES_OUTCOME:-}"
    )
    receipt_cmd="code-receipt"
    receipt_file="${work}/code-qualification-receipt.json"
    report_arg="--package-verdict=${QUALIFY_PACKAGE_REPORT:-}"
  else
    pairs=(
      "foundation=${QUALIFY_NEEDS_FOUNDATION:-}"
      "mariadb=${QUALIFY_NEEDS_MARIADB:-}"
      "release-preparation=${QUALIFY_NEEDS_RELEASE_PREPARATION:-}"
      "release-qualification=${QUALIFY_VERIFY_LANES_OUTCOME:-}"
    )
    receipt_cmd="receipt"
    receipt_file="${work}/release-qualification-receipt.json"
    report_arg="--preflight=${QUALIFY_PREFLIGHT_REPORT:-}"
  fi
  for pair in "${pairs[@]}"; do
    job="${pair%%=*}"
    result="${pair#*=}"
    status=FAIL
    if [ "$result" = "success" ]; then
      status=PASS
    fi
    printf '%s\t*\t%s\t\t\tgithub result: %s\n' "$job" "$status" "${result:-unknown}" >> "$tsv"
  done

  receipt_status=0
  php "$verifier" "$receipt_cmd" \
    --execution=github-actions \
    --steps="$tsv" \
    --lanes-dir="${QUALIFY_LANE_REPORTS_DIR:-$root/reports}" \
    "$report_arg" \
    --commit="$(git rev-parse HEAD)" \
    --tree="$(git rev-parse 'HEAD^{tree}')" \
    --started-at="${QUALIFY_STARTED_AT:-}" \
    --finished-at="$(utc_now)" \
    --host-os="$(uname -srm)" \
    > "$receipt_file" || receipt_status=$?
  return "$receipt_status"
}

command="${1:-}"
[ "$#" -gt 0 ] && shift
case "$command" in
  step) cmd_step "$@" ;;
  local) cmd_local "$@" ;;
  code-local) cmd_code_local "$@" ;;
  local-run) cmd_local_run ;;
  ci-receipt) cmd_ci_receipt release ;;
  code-ci-receipt) cmd_ci_receipt code ;;
  *) die "usage: $0 step <name> | local|code-local --out=<dir> [--ref=<commit>] --env-10.6=<file> --env-10.11=<file> | ci-receipt|code-ci-receipt" ;;
esac
