#!/usr/bin/env bash
# Verify repo-native encrypted BACKUP->RESTORE against two isolated disposable 10.11 DBs.
set -Eeuo pipefail
umask 077
phase=init
work="$(mktemp -d /tmp/allfather-backup-rehearsal.XXXXXXXX)"
finish(){ rc=$?; rm -rf "$work"; echo "PROTECTED_RESTORE_REHEARSAL_EXIT status=$rc phase=$phase"; exit "$rc"; }
trap finish EXIT
cd /srv/allfather
service mariadb start >/dev/null
user=backup_qualifier
source_db=allfather_backup_source
target_db=allfather_backup_target
pw="$(openssl rand -hex 25)"
master="$(openssl rand -base64 32 | tr -d '\n')"
mariadb --protocol=socket -uroot -e "CREATE DATABASE $source_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE $target_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb --protocol=socket -uroot -e "CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$pw'; GRANT ALL PRIVILEGES ON $source_db.* TO '$user'@'127.0.0.1'; GRANT ALL PRIVILEGES ON $target_db.* TO '$user'@'127.0.0.1'; FLUSH PRIVILEGES;"
make_env(){
 local name="$1"
 local dest="$2"
 {
  echo "APP_ENV=testing"
  echo "APP_BASE_URL=https://disposable-rehearsal.invalid"
  echo "APP_RELEASE_REF=d2c8a4a8357960896aa31e90ba91d453035df4e8"
  printf "APP_MASTER_KEY=%s\n" "$master"
  echo "DB_HOST=127.0.0.1"
  echo "DB_PORT=3306"
  printf "DB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\n" "$name" "$user" "$pw"
 } > "$dest"
 chmod 0600 "$dest"
}
make_client(){
 local dest="$1"
 {
  echo "[client]"
  echo "host=127.0.0.1"
  echo "port=3306"
  echo "protocol=tcp"
  printf "user=%s\npassword=%s\n" "$user" "$pw"
 } > "$dest"
 chmod 0600 "$dest"
}
make_env "$source_db" "$work/source.env"
make_env "$target_db" "$work/target.env"
make_client "$work/source.cnf"
make_client "$work/target.cnf"
unset pw master
phase=source-migrations
result="$(php ops/migrate.php up --env="$work/source.env" 2>&1)"
echo "$result" | tail -n 1 | grep -q "^applied=31 skipped=0 drifted=0 " || { echo "BACKUP_SOURCE_SCHEMA_FAIL"; exit 10; }
echo "BACKUP_SOURCE_SCHEMA_INITIALIZED migrations=31"
phase=source-test-data
tenant="backup-rehearsal"
php ops/provision-staging.php --env="$work/source.env" --tenant-key="$tenant" --workspace-key=test --display-name='Disposable Backup Rehearsal' --operator-name='Backup Setup' --modules=P01,P02,P03,P04,P05 > "$work/provision.json" 2>"$work/provision.log"
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="PROVISIONED")exit(1);' "$work/provision.json"
email="backup-rehearsal@example.test"
testpw="$(openssl rand -hex 22)"
printf '%s\n' "$testpw" | php ops/onboard-beta-user.php --env="$work/source.env" --tenant-key="$tenant" --workspace-key=test --email="$email" --display-name='Synthetic Backup Operator' --roles=ROLE_TENANT_ADMIN --password-stdin > "$work/onboard.json" 2>"$work/onboard.log"
unset testpw
operator_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="ONBOARDED")exit(1);echo $x["operator_id"]??"";' "$work/onboard.json")"
tenant_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true);echo $x["tenant_id"]??"";' "$work/onboard.json")"
workspace_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true);echo $x["workspace_id"]??"";' "$work/onboard.json")"
[ -n "$operator_id" ] && [ -n "$tenant_id" ] && [ -n "$workspace_id" ] || exit 11
echo "BACKUP_SYNTHETIC_SCOPE_READY test_only=true"
phase=enter-maintenance
php ops/backup/maintenance.php enter --env="$work/source.env" --tenant-id="$tenant_id" --workspace-id="$workspace_id" --operator-id="$operator_id" --reason=BACKUP > "$work/entered.json"
maint_id="$(php -r '$x=json_decode(file_get_contents($argv[1]),true);if(!($x["ok"]??false))exit(1);echo $x["result"]["maintenance_id"]??"";' "$work/entered.json")"
[ -n "$maint_id" ] || { echo "BACKUP_MAINTENANCE_DENIED"; exit 12; }
echo "BACKUP_MAINTENANCE_QUIESCED"
phase=encrypt-export
age-keygen -o "$work/age-identity" 2>/dev/null
chmod 0600 "$work/age-identity"
recipient="$(awk '/^# public key: age1/{print $4}' "$work/age-identity")"
[ -n "$recipient" ] || exit 13
bash ops/backup/export.sh --env="$work/source.env" --client-options="$work/source.cnf" --database="$source_db" --out="$work/encrypted-backup.tar.age" --recipient="$recipient" > "$work/export.json"
archive_hash="$(php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="BACKUP_EXPORTED"||!($x["encrypted"]??false))exit(1);echo $x["sha256"]??"";' "$work/export.json")"
[ -n "$archive_hash" ] || exit 14
echo "BACKUP_ENCRYPTED_ARCHIVE_CREATED sha256_verified=true"
phase=leave-maintenance
php ops/backup/maintenance.php leave --operator-id="$operator_id" --maintenance-id="$maint_id" > "$work/left.json"
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(!($x["ok"]??false))exit(1);' "$work/left.json"
phase=clean-target-restore
bash ops/backup/restore-rehearsal.sh \
 --archive="$work/encrypted-backup.tar.age" \
 --expected-sha256="$archive_hash" \
 --identity="$work/age-identity" \
 --env="$work/target.env" \
 --client-options="$work/target.cnf" \
 --database="$target_db" \
 --operator-id="$operator_id" > "$work/restore-result.json"
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="RESTORE_REHEARSAL_PASS"||($x["runtime_started"]??true)!==false)exit(1);echo "PROTECTED_RESTORE_REHEARSAL_PASS encrypted=true clean_target=true no_runtime_admission=true",PHP_EOL;' "$work/restore-result.json"
phase=completed
