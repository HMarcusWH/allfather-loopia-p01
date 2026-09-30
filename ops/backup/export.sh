#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
usage: ops/backup/export.sh --env=<private-env> --client-options=<0600-mariadb-client.cnf> \
       --database=<db-name> --out=<backup.tar.age> --recipient=<age1...>
EOF
  exit 2
}

ENV_FILE= CLIENT= DATABASE= OUT= RECIPIENT=
for arg in "$@"; do
  case "$arg" in
    --env=*) ENV_FILE="${arg#*=}" ;;
    --client-options=*) CLIENT="${arg#*=}" ;;
    --database=*) DATABASE="${arg#*=}" ;;
    --out=*) OUT="${arg#*=}" ;;
    --recipient=*) RECIPIENT="${arg#*=}" ;;
    *) usage ;;
  esac
done
[ -n "$ENV_FILE" ] && [ -n "$CLIENT" ] && [ -n "$DATABASE" ] && [ -n "$OUT" ] && [ -n "$RECIPIENT" ] || usage
[[ "$DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || { echo "backup-export: unsafe database name" >&2; exit 2; }
[[ "$RECIPIENT" =~ ^age1[0-9a-z]+$ ]] || { echo "backup-export: recipient must be a public age recipient" >&2; exit 2; }

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[ -f "$ENV_FILE" ] || { echo "backup-export: env file not found" >&2; exit 2; }
[ -f "$CLIENT" ] || { echo "backup-export: client option file not found" >&2; exit 2; }
[ ! -L "$ENV_FILE" ] || { echo "backup-export: env file may not be a symlink" >&2; exit 2; }
[ ! -L "$CLIENT" ] || { echo "backup-export: client option file may not be a symlink" >&2; exit 2; }
for private in "$ENV_FILE" "$CLIENT"; do
  resolved="$(cd "$(dirname "$private")" && pwd -P)/$(basename "$private")"
  case "$resolved" in */public_html/*) echo "backup-export: private configuration may not live under public_html" >&2; exit 2 ;; esac
done
[ -d "$(dirname "$OUT")" ] || { echo "backup-export: output parent does not exist" >&2; exit 2; }
out_parent="$(cd "$(dirname "$OUT")" && pwd -P)"
out_abs="$out_parent/$(basename "$OUT")"
case "$out_abs" in
  */public_html/*) echo "backup-export: backup archive may not be written under public_html" >&2; exit 2 ;;
  "$root"/*) echo "backup-export: encrypted archive must be written outside the release tree" >&2; exit 2 ;;
esac
for private in "$ENV_FILE" "$CLIENT"; do
  perm="$(stat -c '%a' "$private" 2>/dev/null || stat -f '%Lp' "$private")"
  case "$perm" in
    600|400) ;;
    *) echo "backup-export: env/client option files must be mode 0600 or 0400" >&2; exit 2 ;;
  esac
done
command -v mariadb-dump >/dev/null || { echo "backup-export: mariadb-dump is required" >&2; exit 3; }
command -v mariadb >/dev/null || { echo "backup-export: mariadb client is required" >&2; exit 3; }
command -v age >/dev/null || { echo "backup-export: age is required" >&2; exit 3; }
command -v tar >/dev/null || { echo "backup-export: tar is required" >&2; exit 3; }
[ ! -e "$OUT" ] && [ ! -L "$OUT" ] || { echo "backup-export: output already exists or is a symlink" >&2; exit 4; }

work="$(mktemp -d)"
archive_cleanup=1
operation_id=
maintenance_operator=
cleanup_all() {
  if [ -n "$operation_id" ] && [ -n "$maintenance_operator" ]; then
    php "$root/ops/backup/maintenance.php" end-operation \
      --operator-id="$maintenance_operator" --operation-id="$operation_id" >/dev/null 2>&1 || true
  fi
  rm -rf "$work"
  if [ "$archive_cleanup" -eq 1 ]; then
    rm -f "$OUT"
  fi
}
trap cleanup_all EXIT
package="$work/package"
mkdir -m 700 "$package"
dump="$work/database.sql"

# Pin this export inside the exact maintenance window. leave() refuses while the operation marker
# exists, so the database cannot reopen to writers between the native snapshot and manifest reads.
status_json="$(php "$root/ops/backup/maintenance.php" status)"
maintenance_id="$(printf '%s' "$status_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["maintenance"]["maintenance_id"]??"";')"
maintenance_operator="$(printf '%s' "$status_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["maintenance"]["operator_id"]??"";')"
maintenance_reason="$(printf '%s' "$status_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["maintenance"]["reason"]??"";')"
[ -n "$maintenance_id" ] && [ -n "$maintenance_operator" ] && [ "$maintenance_reason" = "BACKUP" ] ||
  { echo "backup-export: enter BACKUP maintenance before exporting" >&2; exit 5; }

operation_json="$(php "$root/ops/backup/maintenance.php" begin-operation   --operator-id="$maintenance_operator" --maintenance-id="$maintenance_id" --operation=BACKUP_EXPORT)"
operation_id="$(printf '%s' "$operation_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["operation_id"]??"";')"
[ -n "$operation_id" ] || { echo "backup-export: could not pin backup operation" >&2; exit 5; }
identity_json="$(php "$root/ops/backup/verify-restore.php" database-identity --env="$ENV_FILE" --database="$DATABASE")"
php_identity="$(printf '%s' "$identity_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["database_identity_sha256"]??"";')"
php_principal="$(printf '%s' "$identity_json" | php -r '$v=json_decode(stream_get_contents(STDIN),true); echo $v["result"]["database_principal_sha256"]??"";')"
native_pair="$(mariadb --defaults-extra-file="$CLIENT" "$DATABASE" --batch --skip-column-names -e "SELECT SHA2(CONCAT(@@hostname,CHAR(0),@@port,CHAR(0),DATABASE()),256),SHA2(CURRENT_USER(),256)")"
read -r native_identity native_principal <<< "$native_pair"
[ -n "$php_identity" ] && [ "$php_identity" = "$native_identity" ] &&
[ -n "$php_principal" ] && [ "$php_principal" = "$native_principal" ] ||
  { echo "backup-export: native client does not match the database/principal named by --env" >&2; exit 5; }

mariadb-dump --defaults-extra-file="$CLIENT" \
  --single-transaction --quick --hex-blob --triggers --skip-comments --skip-lock-tables --skip-add-locks --no-tablespaces --default-character-set=utf8mb4 \
  "$DATABASE" > "$dump"
[ -s "$dump" ] || { echo "backup-export: native dump is empty" >&2; exit 6; }

php "$root/ops/backup/verify-restore.php" build-manifest \
  --env="$ENV_FILE" --database="$DATABASE" --dump="$dump" --package-dir="$package" >/dev/null
php "$root/ops/backup/verify-restore.php" verify-package --package-dir="$package" >/dev/null

tarball="$work/backup.tar"
tar -C "$package" -cf "$tarball" .
age -r "$RECIPIENT" -o "$OUT" "$tarball"
[ -s "$OUT" ] || { echo "backup-export: encrypted archive is empty" >&2; exit 7; }
chmod 600 "$OUT" 2>/dev/null || true
if command -v sha256sum >/dev/null; then
  digest="$(sha256sum "$OUT" | awk '{print $1}')"
elif command -v shasum >/dev/null; then
  digest="$(shasum -a 256 "$OUT" | awk '{print $1}')"
else
  echo "backup-export: sha256sum or shasum is required to identify the encrypted archive" >&2
  exit 3
fi

# The encrypted artifact is complete at this point. Preserve it even if operation cleanup fails,
# but do NOT report BACKUP_EXPORTED until the exact export pin has been closed.
archive_cleanup=0
if ! php "$root/ops/backup/maintenance.php" end-operation \
  --operator-id="$maintenance_operator" --operation-id="$operation_id" >/dev/null; then
  echo "backup-export: archive completed, but the BACKUP_EXPORT operation marker could not be closed; runtime remains fail-closed" >&2
  exit 8
fi
operation_id=
printf '{"status":"BACKUP_EXPORTED","encrypted":true,"sha256":"%s"}\n' "$digest"
