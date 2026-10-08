#!/usr/bin/env bash
# One-shot staging schema initialize; never qualify/truncate the runtime DB.
set -Eeuo pipefail
umask 077
stage=validate
trap 'rc=$?; echo "STAGING_INITIALIZE_EXIT status=$rc phase=$stage"' EXIT
: "${DB_HOST:?}"
: "${DB_NAME:?}"
: "${DB_USER:?}"
: "${DB_PASSWORD:?}"
[ "$DB_HOST" = "allfather-mariadb.railway.internal" ] || { echo "REFUSE: non-staging DB host"; exit 9; }
[ "$DB_NAME" = "allfather_staging" ] || { echo "REFUSE: non-staging DB identity"; exit 9; }
cd /srv/allfather
file="$(mktemp /tmp/allfather-staging-init.XXXXXXXX)"
chmod 0600 "$file"
trap 'rc=$?; rm -f "$file"; echo "STAGING_INITIALIZE_EXIT status=$rc phase=$stage"' EXIT
{
 printf 'APP_ENV=staging\nAPP_BASE_URL=https://allfather-php-mvp-staging.up.railway.app\n'
 printf 'DB_HOST=%s\nDB_PORT=3306\nDB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\n' "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASSWORD"
} > "$file"
stage=read-only-check
php -r '
  $p=new PDO("mysql:host=".getenv("DB_HOST").";port=3306;dbname=".getenv("DB_NAME").";charset=utf8mb4", getenv("DB_USER"),getenv("DB_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $counts=$p->query("SELECT
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()) AS tables_count,
    (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()) AS triggers_count,
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()) AS routines_count,
    (SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()) AS events_count")->fetch(PDO::FETCH_ASSOC);
  if(array_sum(array_map("intval",$counts))!==0){fwrite(STDERR,"STAGING_INITIALIZE_REFUSED nonempty database\n");exit(10);}
  echo "STAGING_SCHEMA_PRECHECK empty=YES server=".$p->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
'
stage=migrate-up
out="$(php ops/migrate.php up --env="$file" 2>&1)"
printf '%s\n' "$out" | tail -n 3
printf '%s\n' "$out" | tail -n 1 | grep -q '^applied=31 skipped=0 drifted=0 ' || { echo "STAGING_MIGRATIONS_NOT_CLEAN"; exit 11; }
stage=migrate-repeat
out="$(php ops/migrate.php up --env="$file" 2>&1)"
printf '%s\n' "$out" | tail -n 2
printf '%s\n' "$out" | tail -n 1 | grep -q '^applied=0 skipped=31 drifted=0 ' || { echo "STAGING_MIGRATIONS_REPEAT_FAIL"; exit 12; }
stage=migrate-status
status="$(php ops/migrate.php status --env="$file")"
good="$(printf '%s\n' "$status" | grep -c '^APPLIED ' || true)"
bad="$(printf '%s\n' "$status" | grep -cv '^APPLIED ' || true)"
[ "$good" -eq 31 ] && [ "$bad" -eq 0 ] || { echo "STAGING_MIGRATION_LEDGER_FAIL applied=$good bad=$bad"; exit 13; }
echo "STAGING_SCHEMA_INITIALIZED applied=31 pending=0 drift=0 rerun=no-op"