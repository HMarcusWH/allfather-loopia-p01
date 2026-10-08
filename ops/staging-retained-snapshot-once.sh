#!/usr/bin/env bash
# Snapshot of the actual inert Railway STAGING DB only. Never delete/change runtime DB.
set -Eeuo pipefail; umask 077
phase=preflight; w="$(mktemp -d /tmp/allfather-db-snapshot.XXXXXXXX)"
finish(){ rc=$?; rm -rf "$w"; echo "RAILWAY_STAGING_SNAPSHOT_EXIT status=$rc phase=$phase"; exit "$rc"; }
trap finish EXIT
: "${DB_HOST:?}"; : "${DB_NAME:?}"; : "${DB_USER:?}"; : "${DB_PASSWORD:?}"
: "${S3_ENDPOINT:?}"; : "${S3_REGION:?}"; : "${S3_BUCKET:?}"; : "${S3_ACCESS_KEY_ID:?}"; : "${S3_SECRET_ACCESS_KEY:?}"; : "${AGE_PUBLIC_RECIPIENT:?}"
[ "$DB_HOST" = "allfather-mariadb.railway.internal" ] || exit 20
[ "$DB_NAME" = "allfather_staging" ] || exit 21
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || exit 22
[[ "$AGE_PUBLIC_RECIPIENT" =~ ^age1[0-9a-z]+$ ]] || exit 23
client="$w/client.cnf"
{
 echo '[client]'
 echo "host=$DB_HOST"
 echo 'port=3306'
 echo 'protocol=tcp'
 printf 'user=%s\npassword=%s\n' "$DB_USER" "$DB_PASSWORD"
} > "$client"
chmod 600 "$client"; unset DB_PASSWORD
cd /srv/allfather
phase=runtime-preflight
version="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT VERSION()')"
[[ "$version" == 10.11.* ]] || { echo "SNAPSHOT_REFUSE version-mismatch"; exit 24; }
applied="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT COUNT(*) FROM schema_migrations')"
[ "$applied" = 31 ] || { echo "SNAPSHOT_REFUSE migration-ledger"; exit 25; }
bad="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT COUNT(*) FROM workspaces WHERE external_side_effects_allowed<>0')"
[ "$bad" = 0 ] || { echo "SNAPSHOT_REFUSE external-effects"; exit 26; }
grants="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT COUNT(*) FROM monitor_execution_grants')"
[ "$grants" = 0 ] || { echo "SNAPSHOT_REFUSE execution-grants"; exit 27; }
echo "STAGING_SNAPSHOT_SOURCE_CHECK server_line=10.11 migrations=31 unsafe_workspaces=0 grants=0"
phase=consistent-transaction-dump
mariadb-dump --defaults-extra-file="$client" --single-transaction --quick --hex-blob --triggers \
 --skip-comments --skip-lock-tables --skip-add-locks --no-tablespaces --default-character-set=utf8mb4 \
 "$DB_NAME" > "$w/database.sql" 2>"$w/dump-error.log" || { echo "STAGING_SNAPSHOT_DUMP_FAILED"; exit 28; }
test -s "$w/database.sql" || exit 29
digest="$(sha256sum "$w/database.sql" | cut -d' ' -f1)"
tables="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE"')"
triggers="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')"
cat > "$w/manifest.json" << EOF
{"kind":"STAGING_CONSISTENT_SQL_SNAPSHOT","release_tree":"dc0bee3db1d0c17607b539b7df5c7c04358c75ce","database":"allfather_staging","server_line":"10.11","schema_migrations":31,"tables":$tables,"triggers":$triggers,"plaintext_sha256":"$digest","quiesced_maintenance":false,"includes_runtime_storage":false}
EOF
phase=encrypt
age-keygen -o "$w/transient-recovery-key" 2>/dev/null
temp_recipient="$(grep -m1 '^# public key:' "$w/transient-recovery-key" | awk '{print $4}')"
test -n "$temp_recipient" || exit 30
tar -C "$w" -cf "$w/backup.tar" database.sql manifest.json
age -r "$AGE_PUBLIC_RECIPIENT" -r "$temp_recipient" -o "$w/backup.tar.age" "$w/backup.tar"
age --decrypt -i "$w/transient-recovery-key" -o "$w/verification.tar" "$w/backup.tar.age"
mkdir "$w/verification"
tar -xf "$w/verification.tar" -C "$w/verification"
test "$(sha256sum "$w/verification/database.sql" | cut -d' ' -f1)" = "$digest" || exit 31
phase=independent-restore
service mariadb start >/dev/null
localdb=allfather_snapshot_restore_$(date -u +%H%M%S)
mariadb --protocol=socket -uroot -e "CREATE DATABASE $localdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
principal="$(mariadb --defaults-extra-file="$client" "$DB_NAME" -N -B -e 'SELECT CURRENT_USER()')"
principal_user="${principal%@*}"; principal_host="${principal#*@}"
[[ "$principal_user" =~ ^[A-Za-z0-9_]+$ ]] || exit 32
[[ "$principal_host" =~ ^[A-Za-z0-9.%-]+$ ]] || exit 33
mariadb --protocol=socket -uroot -e "CREATE USER IF NOT EXISTS '$principal_user'@'$principal_host'; GRANT ALL PRIVILEGES ON $localdb.* TO '$principal_user'@'$principal_host';"
mariadb --protocol=socket -uroot "$localdb" < "$w/verification/database.sql" 2>"$w/restore-error.log" || { echo "STAGING_SNAPSHOT_RESTORE_FAILED"; exit 34; }
restored_tables="$(mariadb --protocol=socket -uroot "$localdb" -N -B -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE"')"
restored_triggers="$(mariadb --protocol=socket -uroot "$localdb" -N -B -e 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')"
restored_migrations="$(mariadb --protocol=socket -uroot "$localdb" -N -B -e 'SELECT COUNT(*) FROM schema_migrations')"
[ "$tables" = "$restored_tables" ] && [ "$triggers" = "$restored_triggers" ] && [ "$restored_migrations" = 31 ] || exit 35
phase=retain-encrypted
snapshot_key="staging/manual-$(date -u +%Y%m%dT%H%M%SZ)-$digest.tar.age"
export SNAPSHOT_FILE="$w/backup.tar.age" SNAPSHOT_KEY="$snapshot_key" SNAPSHOT_PLAINTEXT_SHA256="$digest"
node /opt/backup-upload.mjs
echo "STAGING_ENCRYPTED_SNAPSHOT_RETAINED restore_verified=YES database_storage_untouched=YES scope=DB_ONLY"
phase=complete
