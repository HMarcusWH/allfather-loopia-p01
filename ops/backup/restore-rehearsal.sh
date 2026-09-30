#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
usage: ops/backup/restore-rehearsal.sh --archive=<backup.tar.age> --expected-sha256=<recorded-archive-sha256> \
       --identity=<age-key-file> --env=<disposable-target-env> --client-options=<0600-mariadb-client.cnf> \
       --database=<empty-target-db> --operator-id=<backup-operator-id> [--work-dir=<new-dir>]
EOF
  exit 2
}

ARCHIVE= EXPECTED_SHA256= IDENTITY= ENV_FILE= CLIENT= DATABASE= OPERATOR_ID= WORK=
for arg in "$@"; do
  case "$arg" in
    --archive=*) ARCHIVE="${arg#*=}" ;;
    --expected-sha256=*) EXPECTED_SHA256="${arg#*=}" ;;
    --identity=*) IDENTITY="${arg#*=}" ;;
    --env=*) ENV_FILE="${arg#*=}" ;;
    --client-options=*) CLIENT="${arg#*=}" ;;
    --database=*) DATABASE="${arg#*=}" ;;
    --operator-id=*) OPERATOR_ID="${arg#*=}" ;;
    --work-dir=*) WORK="${arg#*=}" ;;
    *) usage ;;
  esac
done
[ -n "$ARCHIVE" ] && [ -n "$EXPECTED_SHA256" ] && [ -n "$IDENTITY" ] && [ -n "$ENV_FILE" ] && [ -n "$CLIENT" ] && [ -n "$DATABASE" ] && [ -n "$OPERATOR_ID" ] || usage
[[ "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || { echo "restore-rehearsal: unsafe database name" >&2; exit 2; }
[[ "$EXPECTED_SHA256" =~ ^[a-f0-9]{64}$ ]] || { echo "restore-rehearsal: expected-sha256 must be 64 lowercase hex characters" >&2; exit 2; }
[[ "$OPERATOR_ID" =~ ^[a-f0-9-]{36}$ ]] || { echo "restore-rehearsal: operator-id must be a lowercase UUID" >&2; exit 2; }
for f in "$ARCHIVE" "$IDENTITY" "$ENV_FILE" "$CLIENT"; do [ -f "$f" ] || { echo "restore-rehearsal: missing input file" >&2; exit 2; }; done
[ ! -L "$ARCHIVE" ] || { echo "restore-rehearsal: encrypted archive may not be a symlink" >&2; exit 2; }
[ ! -L "$IDENTITY" ] && [ ! -L "$CLIENT" ] && [ ! -L "$ENV_FILE" ] || { echo "restore-rehearsal: private input files may not be symlinks" >&2; exit 2; }
for private in "$IDENTITY" "$ENV_FILE" "$CLIENT"; do
  resolved="$(cd "$(dirname "$private")" && pwd -P)/$(basename "$private")"
  case "$resolved" in */public_html/*) echo "restore-rehearsal: private recovery material may not live under public_html" >&2; exit 2 ;; esac
done
for f in "$IDENTITY" "$ENV_FILE" "$CLIENT"; do
  perm="$(stat -c '%a' "$f" 2>/dev/null || stat -f '%Lp' "$f")"
  case "$perm" in 600|400) ;; *) echo "restore-rehearsal: identity/env/client files must be mode 0600 or 0400" >&2; exit 2 ;; esac
done
command -v age >/dev/null || { echo "restore-rehearsal: age is required" >&2; exit 3; }
command -v mariadb >/dev/null || { echo "restore-rehearsal: mariadb client is required" >&2; exit 3; }
command -v tar >/dev/null || { echo "restore-rehearsal: tar is required" >&2; exit 3; }

if command -v sha256sum >/dev/null; then
  archive_sha256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
elif command -v shasum >/dev/null; then
  archive_sha256="$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')"
else
  echo "restore-rehearsal: sha256sum or shasum is required" >&2
  exit 3
fi
[ "$archive_sha256" = "$EXPECTED_SHA256" ] ||
  { echo "restore-rehearsal: encrypted archive sha256 does not match the independently recorded digest" >&2; exit 4; }

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [ -z "$WORK" ]; then
  WORK="$(mktemp -d)"
else
  [ ! -e "$WORK" ] && [ ! -L "$WORK" ] || { echo "restore-rehearsal: work dir already exists or is a symlink" >&2; exit 4; }
  [ -d "$(dirname "$WORK")" ] || { echo "restore-rehearsal: work-dir parent does not exist" >&2; exit 4; }
  work_parent="$(cd "$(dirname "$WORK")" && pwd -P)"
  case "$work_parent/$(basename "$WORK")" in */public_html/*) echo "restore-rehearsal: decrypted work dir may not be under public_html" >&2; exit 4 ;; esac
  mkdir -m 700 "$WORK"
fi
# Decrypted dump/package/recovery bytes are temporary even when --work-dir chooses their private
# location. There is deliberately no implicit retain-plaintext mode.
trap 'rm -rf "$WORK"' EXIT

age --decrypt -i "$IDENTITY" -o "$WORK/backup.tar" "$ARCHIVE"
tar -tf "$WORK/backup.tar" > "$WORK/members.txt"
while IFS= read -r member; do
  case "$member" in
    .|..|/*|../*|*/../*|*/..|*\\*) echo "restore-rehearsal: archive contains unsafe path" >&2; exit 4 ;;
  esac
done < "$WORK/members.txt"
if tar -tvf "$WORK/backup.tar" | grep -Ev '^[-d]' >/dev/null; then
  echo "restore-rehearsal: archive contains a non-file/non-directory entry" >&2
  exit 4
fi
mkdir -m 700 "$WORK/package"
tar --no-same-owner --no-same-permissions -xf "$WORK/backup.tar" -C "$WORK/package"
php "$root/ops/backup/verify-restore.php" verify-package --package-dir="$WORK/package" >/dev/null
php "$root/ops/backup/verify-restore.php" check-target --env="$ENV_FILE" --database="$DATABASE" --operator-id="$OPERATOR_ID" --package-dir="$WORK/package" >/dev/null
identity_json="$(php "$root/ops/backup/verify-restore.php" database-identity --env="$ENV_FILE" --database="$DATABASE")"
php_identity="$(printf '%s' "$identity_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["database_identity_sha256"]??"";')"
php_principal="$(printf '%s' "$identity_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["database_principal_sha256"]??"";')"
native_pair="$(mariadb --defaults-extra-file="$CLIENT" "$DATABASE" --batch --skip-column-names -e "SELECT SHA2(CONCAT(@@hostname,CHAR(0),@@port,CHAR(0),DATABASE()),256),SHA2(CURRENT_USER(),256)")"
read -r native_identity native_principal <<< "$native_pair"
[ -n "$php_identity" ] && [ "$php_identity" = "$native_identity" ] &&
[ -n "$php_principal" ] && [ "$php_principal" = "$native_principal" ] ||
  { echo "restore-rehearsal: native client does not match the database/principal named by --env" >&2; exit 5; }

objects="$(mariadb --defaults-extra-file="$CLIENT" "$DATABASE" --batch --skip-column-names -e "
  SELECT
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DATABASE') +
    (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$DATABASE') +
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='$DATABASE') +
    (SELECT COUNT(*) FROM information_schema.events WHERE event_schema='$DATABASE')
")"
[ "$objects" = "0" ] || { echo "restore-rehearsal: target database is not empty" >&2; exit 5; }

mariadb --defaults-extra-file="$CLIENT" "$DATABASE" < "$WORK/package/database.sql"

mkdir -p "$WORK/restored-storage/evidence"
if [ -d "$WORK/package/evidence" ]; then
  cp -R "$WORK/package/evidence/." "$WORK/restored-storage/evidence/"
fi
if [ -f "$WORK/package/request-log.prefix" ]; then
  cp "$WORK/package/request-log.prefix" "$WORK/restored-storage/request.log"
  chmod 600 "$WORK/restored-storage/request.log"
fi

php "$root/ops/backup/verify-restore.php" verify-database \
  --env="$ENV_FILE" --database="$DATABASE" --operator-id="$OPERATOR_ID" --package-dir="$WORK/package" --storage-root="$WORK/restored-storage" > "$WORK/verification.json"

printf '{"status":"RESTORE_REHEARSAL_PASS","target_database":"%s","runtime_started":false,"native_execution_requires_separate_re_admission":true}\n' \
  "$DATABASE"
