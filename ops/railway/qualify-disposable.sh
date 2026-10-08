#!/usr/bin/env bash
# Standalone Railway qualification smoke with DISPOSABLE isolated MariaDB.
# This is NOT the official exact-git-object qualification receipt.
set -euo pipefail
phase='preflight'
trap 'code=$?; echo "RAILWAY_DISPOSABLE_QUALIFICATION finished exit=${code} phase=${phase}" >&2' EXIT
cd /srv/allfather
: "${DB_HOST:?}"
: "${DB_NAME:?}"
: "${DB_USER:?}"
: "${DB_PASSWORD:?}"
case "$DB_HOST" in *.railway.internal) ;; *) echo 'REFUSE: DB_HOST is not private Railway DNS' >&2; exit 2;; esac
case "$DB_NAME" in allfather_qualify_106|allfather_qualify_1011) ;; *) echo 'REFUSE: not an approved disposable qualification DB name' >&2; exit 2;; esac
env_file="$(mktemp /tmp/allfather-qualify.XXXXXXXX)"
chmod 0600 "$env_file"
trap 'code=$?; rm -f "$env_file"; echo "RAILWAY_DISPOSABLE_QUALIFICATION finished exit=${code} phase=${phase}" >&2' EXIT
{
 printf 'APP_ENV=testing\n'
 printf 'DB_HOST=%s\n' "$DB_HOST"
 printf 'DB_PORT=3306\n'
 printf 'DB_NAME=%s\n' "$DB_NAME"
 printf 'DB_USER=%s\n' "$DB_USER"
 printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD"
} > "$env_file"
echo 'QUALIFICATION_CONTEXT=DISPOSABLE_RAILWAY_NO_GIT_RECEIPT'
echo "MariaDB lane database: $DB_NAME"
phase='toolchain'
php -v | head -2
echo "Node: $(node --version) npm: $(npm --version)"
test "$(node --version)" = "v22.16.0"
test "$(npm --version)" = "10.9.2"
phase='frontend'
(cd frontend/console && npm ci && npm test && npm run build && npm run stage)
phase='php-syntax'
for dir in app public_html worker ops tests; do test -d "$dir"; done
find app public_html worker ops tests -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null
phase='foundation'
php tests/run.php --layer=foundation --strict
phase='unit'
php tests/run.php --layer=unit --strict
phase='migrations-up'
php ops/migrate.php up --env="$env_file"
phase='migrations-repeat'
php ops/migrate.php up --env="$env_file"
phase='migration-status'
php ops/migrate.php status --env="$env_file"
phase='evidence'
php ops/evidence/validate.php
phase='full-suite'
TEST_ENV_FILE="$env_file" php tests/run.php --strict --report-json=/tmp/railway-disposable-report.json
phase='report'
php -r '$r=json_decode(file_get_contents("/tmp/railway-disposable-report.json"),true,512,JSON_THROW_ON_ERROR); echo "DISPOSABLE_TEST_REPORT ",json_encode(["complete"=>$r["complete"],"strict"=>$r["strict"],"php"=>$r["php_version"],"db"=>$r["database_server_version"],"total"=>$r["total"]]),PHP_EOL;'
echo 'RAILWAY_DISPOSABLE_QUALIFICATION_PASSED'
