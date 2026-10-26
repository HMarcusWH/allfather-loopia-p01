#!/usr/bin/env bash
# PR #87 — Synthetic-only MariaDB crash and independent-volume restore.
# NEVER run against Railway, a named customer DB, or an existing user volume.
set -euo pipefail
umask 077
usage() { echo 'usage: restore.sh --mariadb=10.6|10.11 --receipt=PATH' >&2; exit 2; }
VERSION='' RECEIPT=''
for arg in "$@"; do
  case "$arg" in
    --mariadb=*) VERSION="$(printf '%s' "$arg" | cut -d= -f2-)" ;;
    --receipt=*) RECEIPT="$(printf '%s' "$arg" | cut -d= -f2-)" ;;
    *) usage ;;
  esac
done
[[ "$VERSION" == '10.6' || "$VERSION" == '10.11' ]] || usage
[[ -n "$RECEIPT" && ! -e "$RECEIPT" ]] || usage
[[ "$M17_QA_RUN_ID" =~ ^[a-zA-Z0-9_.-]{8,100}$ ]] || { echo 'M17_QA_RUN_ID_REQUIRED' >&2; exit 3; }
[[ "${GITHUB_ACTIONS:-}" == 'true' && "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] \
  || [[ "${M17_QA_LOCAL_DOCKER:-}" == 'EXPLICIT_DISPOSABLE_DOCKER_ONLY' ]] \
  || { echo 'TRUSTED_EPHEMERAL_DOCKER_REQUIRED' >&2; exit 3; }
for tool in docker php tar sha256sum age age-keygen awk cut; do
  command -v "$tool" >/dev/null || { echo "M17_QA_TOOL_MISSING: $tool" >&2; exit 3; }
done
php -m | grep -qi pdo_mysql || { echo 'PDO_MYSQL_EXTENSION_REQUIRED' >&2; exit 3; }
docker info >/dev/null || { echo 'DOCKER_DAEMON_UNAVAILABLE' >&2; exit 3; }

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
cd "$ROOT"
mkdir -p "$(dirname "$RECEIPT")"
WORK="$(mktemp -d)"
chmod 0700 "$WORK"
TAG="m17qa-$(printf '%s' "$M17_QA_RUN_ID" | tr -c 'a-zA-Z0-9' '-' | cut -c1-38)-$RANDOM"
PRIMARY="$TAG-primary"
SECONDARY="$TAG-restore"
VOLUME_A="$TAG-a"
VOLUME_B="$TAG-b"
PRIMARY_CREATED=false SECONDARY_CREATED=false VOLUME_A_CREATED=false VOLUME_B_CREATED=false
STAGE='SETUP'
printf '{"status":"NOT_RUN","source":"CANDIDATE_ONLY","Railway":"NOT_TESTED","U04":"NOT_RATIFIED","U03":"NOT_ADOPTED"}\n' > "$RECEIPT"
cleanup() {
  status=$?
  trap - EXIT
  if [ "$status" -ne 0 ]; then
    printf '{"status":"FAIL","phase":"%s","source":"CANDIDATE_ONLY","Railway":"NOT_TESTED","U04":"NOT_RATIFIED","U03":"NOT_ADOPTED"}\n' "$STAGE" > "$RECEIPT"
    echo "M17_QA_RESTORE_FAIL: $STAGE" >&2
  fi
  [ "$PRIMARY_CREATED" = false ] || docker rm -f "$PRIMARY" >/dev/null 2>&1 || true
  [ "$SECONDARY_CREATED" = false ] || docker rm -f "$SECONDARY" >/dev/null 2>&1 || true
  [ "$VOLUME_A_CREATED" = false ] || docker volume rm "$VOLUME_A" >/dev/null 2>&1 || true
  [ "$VOLUME_B_CREATED" = false ] || docker volume rm "$VOLUME_B" >/dev/null 2>&1 || true
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT
docker volume create "$VOLUME_A" >/dev/null
VOLUME_A_CREATED=true
docker volume create "$VOLUME_B" >/dev/null
VOLUME_B_CREATED=true

launch_db() {
  docker run --detach --name "$1" --memory=700m --cpus=1.5 \
    -e MARIADB_ROOT_PASSWORD=m17_ephemeral_root \
    -e MARIADB_DATABASE=m17_candidate_qa \
    -e MARIADB_USER=m17_qa \
    -e MARIADB_PASSWORD=m17_ephemeral_user \
    --volume "$2:/var/lib/mysql" \
    -p '127.0.0.1::3306' "mariadb:$VERSION" >/dev/null
}
wait_db() {
  local name="$1" end=$((SECONDS+90))
  while [ "$SECONDS" -lt "$end" ]; do
    if docker exec -e MYSQL_PWD=m17_ephemeral_user "$name" \
      mariadb -u m17_qa -D m17_candidate_qa --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo 'M17_QA_CONTAINER_DATABASE_NOT_READY' >&2
  return 1
}
wait_tcp() {
  local port end
  port="$(docker port "$1" 3306/tcp | awk -F: 'NR==1 {print $NF}')"
  [[ "$port" =~ ^[0-9]+$ ]] || return 1
  end=$((SECONDS+30))
  while [ "$SECONDS" -lt "$end" ]; do
    if php -r '$s=@fsockopen("127.0.0.1",(int)$argv[1],$e,$m,1); if(!$s) exit(1); fclose($s);' "$port"; then
      return 0
    fi
    sleep 1
  done
  echo 'M17_QA_DOCKER_TCP_NOT_READY' >&2
  return 1
}
write_env() {
  local port
  port="$(docker port "$1" 3306/tcp | awk -F: 'NR==1 {print $NF}')"
  [[ "$port" =~ ^[0-9]+$ ]] || return 1
  cat > "$2" <<EOF
APP_ENV=testing
DB_HOST=127.0.0.1
DB_PORT=$port
DB_NAME=m17_candidate_qa
DB_USER=m17_qa
DB_PASSWORD=m17_ephemeral_user
EOF
  chmod 0600 "$2"
}
run_qa() { TEST_ENV_FILE="$WORK/current.env" php "$HERE/qa.php" "$@"; }

STAGE='PRIMARY_START'
launch_db "$PRIMARY" "$VOLUME_A"
PRIMARY_CREATED=true
wait_db "$PRIMARY"
wait_tcp "$PRIMARY"
write_env "$PRIMARY" "$WORK/primary.env"
cp "$WORK/primary.env" "$WORK/current.env"
chmod 0600 "$WORK/current.env"
STAGE='PARTIAL_DDL_AND_SYNTHETIC_SEED'
run_qa guard >/dev/null
run_qa init > "$WORK/init.json"
echo 'M17_QA_CHECKPOINT: partial-ddl-and-active-migrations-done'
run_qa seed "$WORK/seed.json" >/dev/null
echo 'M17_QA_CHECKPOINT: synthetic-population-done'
run_qa verify "$WORK/seed.json" > "$WORK/pre-backup-verify.json"
echo 'M17_QA_CHECKPOINT: initial-semantics-verified'
run_qa manifest "$WORK/original-manifest.json" >/dev/null
mkdir -p "$WORK/prepared/artifacts"
printf 'M17 SYNTHETIC DATA ONLY\nNo licensed sources, customer records or production evidence.\n' \
  > "$WORK/prepared/artifacts/source-fixture.txt"
cp "$WORK/seed.json" "$WORK/prepared/seed.json"
sha256sum "$WORK/prepared/artifacts/source-fixture.txt" | awk '{print $1}' > "$WORK/prepared/source-fixture.sha256"

STAGE='REAL_DATABASE_DUMP'
docker exec -e MYSQL_PWD=m17_ephemeral_user "$PRIMARY" \
  mariadb-dump --user=m17_qa --single-transaction --quick --hex-blob --triggers \
  --skip-comments --skip-lock-tables --skip-add-locks --no-tablespaces \
  m17_candidate_qa > "$WORK/prepared/database.sql"
test -s "$WORK/prepared/database.sql"
STAGE='ENCRYPTED_ARCHIVE'
age-keygen -o "$WORK/qa-restore-key" > "$WORK/qa-keygen-output" 2>/dev/null
recipient="$(age-keygen -y "$WORK/qa-restore-key")"
tar -C "$WORK/prepared" -cf - . | age -r "$recipient" -o "$WORK/m17.tar.age"
test -s "$WORK/m17.tar.age"
archive_sha="$(sha256sum "$WORK/m17.tar.age" | awk '{print $1}')"
rm -rf "$WORK/prepared"

STAGE='UNCOMMITTED_INNODB_CRASH'
TEST_ENV_FILE="$WORK/current.env" php "$HERE/qa.php" hold "$WORK/hold.ready" \
  > "$WORK/hold.stdout" 2> "$WORK/hold.stderr" &
holder_pid=$!
hold_deadline=$((SECONDS+12))
until test -f "$WORK/hold.ready"; do
  kill -0 "$holder_pid" 2>/dev/null || { echo 'CRASH_WORKER_FAILED_TO_START' >&2; exit 1; }
  [ "$SECONDS" -lt "$hold_deadline" ] || { echo 'CRASH_WORKER_TIMEOUT' >&2; exit 1; }
  sleep 0.05
done
php -r '$v=json_decode(file_get_contents($argv[1]),true); exit(($v["transaction_open"]??false)===true?0:1);' "$WORK/hold.ready"
test "$(docker exec -e MYSQL_PWD=m17_ephemeral_user "$PRIMARY" \
    mariadb -u m17_qa -D m17_candidate_qa --batch --skip-column-names \
    -e 'SELECT value_text FROM m17_qa_crash_probe WHERE id=1')" = 'COMMITTED_BEFORE_CRASH'
docker kill --signal=KILL "$PRIMARY" >/dev/null
kill -9 "$holder_pid" >/dev/null 2>&1 || true
wait "$holder_pid" >/dev/null 2>&1 || true

STAGE='SAME_VOLUME_RECOVERY'
docker start "$PRIMARY" >/dev/null
wait_db "$PRIMARY"
wait_tcp "$PRIMARY"
run_qa compare "$WORK/original-manifest.json" > "$WORK/crash-verify.json"
echo 'M17_QA_CHECKPOINT: crash-consistency-verified'
test "$(docker exec -e MYSQL_PWD=m17_ephemeral_user "$PRIMARY" \
    mariadb -u m17_qa -D m17_candidate_qa --batch --skip-column-names \
    -e 'SELECT value_text FROM m17_qa_crash_probe WHERE id=1')" = 'COMMITTED_BEFORE_CRASH'

STAGE='INDEPENDENT_VOLUME_RESTORE'
launch_db "$SECONDARY" "$VOLUME_B"
SECONDARY_CREATED=true
wait_db "$SECONDARY"
wait_tcp "$SECONDARY"
write_env "$SECONDARY" "$WORK/restore.env"
cp "$WORK/restore.env" "$WORK/current.env"
chmod 0600 "$WORK/current.env"
run_qa guard >/dev/null
test "$archive_sha" = "$(sha256sum "$WORK/m17.tar.age" | awk '{print $1}')"
mkdir -p "$WORK/decrypted"
age --decrypt -i "$WORK/qa-restore-key" -o "$WORK/decrypted/backup.tar" "$WORK/m17.tar.age"
tar -tf "$WORK/decrypted/backup.tar" | grep -q './database.sql'
tar --no-same-owner --no-same-permissions -C "$WORK/decrypted" \
  -xf "$WORK/decrypted/backup.tar"
test "$(sha256sum "$WORK/decrypted/artifacts/source-fixture.txt" | awk '{print $1}')" \
  = "$(cat "$WORK/decrypted/source-fixture.sha256")"
docker exec -i -e MYSQL_PWD=m17_ephemeral_user "$SECONDARY" \
  mariadb --user=m17_qa m17_candidate_qa < "$WORK/decrypted/database.sql"
run_qa verify "$WORK/decrypted/seed.json" > "$WORK/restore-verify.json"

STAGE='FINAL_RECEIPT'
php -r '
  $r=[
    "status"=>"PASS","engine"=>$argv[1],"archive_sha256"=>$argv[2],
    "partial_candidate_DDL_rehearsed"=>true,
    "same_volume_forced_container_KILL_recovered"=>true,
    "uncommitted_sql_update_rolled_back"=>true,
    "independent_empty_volume_restore_verified"=>true,
    "database_dump_with_triggers"=>true,
    "synthetic_evidence_digest_verified"=>true,
    "semantic_fork_hold_replay_and_sql_immutability"=>true,
    "claim_ceiling"=>[
      "U04_NOT_RATIFIED","U03_NOT_ADOPTED","NO_MIGRATION_280",
      "NO_RAILWAY_TEST","NO_REAL_M05_AUTHENTICITY","NO_SERVICE_ACTIVATION"
    ]
  ];
  file_put_contents($argv[3],json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$VERSION" "$archive_sha" "$RECEIPT"
echo "M17_QA_RESTORE_PASS engine=$VERSION"
