#!/usr/bin/env bash
# Disposable offline qualification adapter for the existing, exact-commit runner.
# Never connects to the deployed DB, staging host, provider, or customer source.
set -euo pipefail
umask 077

fail() { printf 'qualification setup refused: %s\n' "$*" >&2; exit 2; }
usage() {
  cat >&2 <<'HELP'
Usage: bash ops/ci/qualify-local-docker.sh --sha=<40-hex commit> --out=<new absolute directory>
Requirements: local Docker engine, PHP CLI 8.2 with pdo_mysql, Node/npm as pinned by repository, and git.
The DB containers and their databases are disposable. The output directory remains for private inspection.
HELP
  exit 2
}

SHA='' OUT=''
for arg in "$@"; do
  case "$arg" in
    --sha=*) SHA="${arg#--sha=}" ;;
    --out=*) OUT="${arg#--out=}" ;;
    *) usage ;;
  esac
done
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || usage
[[ "$OUT" = /* ]] || fail '--out must be an absolute path'
[[ ! -e "$OUT" ]] || fail 'output already exists: refuse mixed receipts'
case "/$OUT/" in */public_html/*|*/storage/*|*/vendor/*) fail 'output is under an application/storage directory' ;; esac

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
[[ -f "$root/ops/ci/qualify-release.sh" ]] || fail 'existing qualification runner not found'
[[ -f "$root/ops/ci/verify-qualification.php" ]] || fail 'qualification verifier not found'
resolved="$(git -C "$root" rev-parse --verify "${SHA}^{commit}" 2>/dev/null || true)"
[[ "$resolved" = "$SHA" ]] || fail "commit ${SHA} is missing locally; fetch this PR head before attempting qualification"

command -v docker >/dev/null || fail 'Docker CLI missing: install a local Docker engine; never substitute staging DB'
command -v openssl >/dev/null || fail 'openssl required for disposable DB credentials'
command -v php >/dev/null || fail 'PHP CLI missing'
command -v node >/dev/null || fail 'Node.js missing'
command -v npm >/dev/null || fail 'npm missing'
[[ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = '8.2' ]] || fail 'exact local qualification requires PHP 8.2 (check repo verifier; do not loosen)'
php -m | grep -iq '^pdo_mysql$' || fail 'pdo_mysql extension missing; MariaDB tests must not be skipped'

# Local socket only: a remote Docker context could accidentally run paid or shared infrastructure.
endpoint="$(docker context inspect --format '{{ (index .Endpoints "docker").Host }}' 2>/dev/null || true)"
case "$endpoint" in unix://*|npipe://*) ;; *) fail "refusing nonlocal Docker context ${endpoint:-UNKNOWN}" ;; esac
docker info >/dev/null 2>&1 || fail 'local Docker daemon is unavailable'

mkdir -p "$(dirname "$OUT")"
private="$(mktemp -d "${TMPDIR:-/tmp}/allfather-ci.XXXXXXXX")"
containers=()
cleanup() {
  local rc=$?
  trap - EXIT INT TERM
  for name in "${containers[@]}"; do
    docker rm -f "$name" >/dev/null 2>&1 || true
  done
  # Ephemeral credentials must not be persisted with test receipts.
  rm -rf -- "$private"
  if ((rc != 0)); then
    printf 'Local qualification did not pass (exit %s). See private output at %s if created.\n' "$rc" "$OUT" >&2
  fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

create_lane() {
  local lane="$1" tag="$2" suffix="$3" output_var="$4" name port server_env lane_env
  name="af-qa-${lane//./-}-${suffix}"
  server_env="$private/server-${lane}.env"
  lane_env="$private/lane-${lane}.env"
  # Only this test container uses these one-time credentials. Never an existing Railway/Loopia DB.
  {
    printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    printf 'MARIADB_DATABASE=allfather_qualification\n'
    printf 'MARIADB_USER=allfather_qa\n'
    printf 'MARIADB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
  } > "$server_env"
  chmod 600 "$server_env"
  docker run -d --rm --name "$name" \
    --env-file "$server_env" \
    --publish '127.0.0.1::3306' \
    "mariadb:${tag}" >/dev/null
  containers+=("$name")
  port="$(docker port "$name" 3306/tcp | head -1)"
  [[ "$port" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || fail "unable to establish loopback-only port for ${lane}"
  port="${BASH_REMATCH[1]}"
  {
    printf 'DB_HOST=127.0.0.1\n'
    printf 'DB_PORT=%s\n' "$port"
    printf 'DB_NAME=allfather_qualification\n'
    printf 'DB_USER=allfather_qa\n'
    # Read back the random container password without echoing it into a terminal/log.
    grep '^MARIADB_PASSWORD=' "$server_env" | sed 's/^MARIADB_PASSWORD=/DB_PASSWORD=/'
  } > "$lane_env"
  chmod 600 "$lane_env"

  local ready=false
  for ((try=1; try<=90; try++)); do
    if docker exec "$name" sh -c 'mariadb --batch --skip-column-names -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT 1" >/dev/null 2>&1'; then
      ready=true
      break
    fi
    sleep 2
  done
  [[ "$ready" = true ]] || fail "MariaDB ${lane} did not become ready"
  # Assign in the caller shell so EXIT cleanup sees container names.
  printf -v "$output_var" '%s' "$lane_env"
}

suffix="$(openssl rand -hex 5)"
printf 'Starting disposable, local-only MariaDB 10.6/10.11 for exact commit %s\n' "$SHA"
env106=""; env1011=""
create_lane '10.6' '10.6' "$suffix" env106
create_lane '10.11' '10.11' "$suffix" env1011
printf 'Executing existing exact-commit qualification (logs remain private).\n'

# The existing runner performs each job from a separate git archive of SHA, executes
# fresh install/upgrade/no-op migration and strict tests, and marks any skip as failure.
# No override for failed checks; an actual local receipt remains hosted_ci=false.
bash "$root/ops/ci/qualify-release.sh" local \
  "--ref=$SHA" "--out=$OUT" \
  "--env-10.6=$env106" "--env-10.11=$env1011"

receipt="$OUT/release-qualification-receipt.json"
[[ -f "$receipt" ]] || fail 'qualification completed without its required receipt'
php "$root/ops/ci/verify-qualification.php" verify-receipt \
  "--receipt=$receipt" \
  "--lanes-dir=$OUT/reports" \
  "--preflight=$OUT/jobs/release-preparation/work/preflight.json"
printf 'LOCAL qualification completed. Inspect %s; do not label it hosted GitHub CI.\n' "$receipt"
