#!/usr/bin/env bash
# Dedicated inert Railway staging TEST scope. No live work, grants or providers.
set -Eeuo pipefail
umask 077
phase='validate'
account_possible=0
tenant_key=''
email=''
env_file=''
cleanup() {
 local rc=$?
 trap - EXIT
 if [ "$account_possible" -eq 1 ]; then
   echo "STAGING_TEST_ACCOUNT_CLEANUP retire-start"
   if php ops/manage-beta-user.php retire --env="$env_file" --tenant-key="$tenant_key" --email="$email" >/tmp/allfather-retire-result.json 2>/tmp/allfather-retire-error.log; then
     echo "STAGING_TEST_ACCOUNT_CLEANUP retired"
   else
     echo "STAGING_TEST_ACCOUNT_CLEANUP FAILED — account needs manual review"
     rc=20
   fi
 fi
 [ -z "$env_file" ] || rm -f "$env_file"
 echo "STAGING_ACCEPTANCE_EXIT status=$rc phase=$phase"
 exit "$rc"
}
trap cleanup EXIT
: "${DB_HOST:?}"
: "${DB_NAME:?}"
: "${DB_USER:?}"
: "${DB_PASSWORD:?}"
[ "$DB_HOST" = "allfather-mariadb.railway.internal" ] || exit 9
[ "$DB_NAME" = "allfather_staging" ] || exit 9
cd /srv/allfather
env_file="$(mktemp /tmp/allfather-acceptance.XXXXXXXX)"
chmod 0600 "$env_file"
{
 printf 'APP_ENV=staging\nAPP_BASE_URL=https://allfather-php-mvp-staging.up.railway.app\n'
 printf 'DB_HOST=%s\nDB_PORT=3306\nDB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\n' "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASSWORD"
} > "$env_file"
unset DB_PASSWORD
phase='schema-check'
count="$(php ops/migrate.php status --env="$env_file" 2>/dev/null | grep -c '^APPLIED ' || true)"
[ "$count" -eq 31 ] || { echo "STAGING_ACCEPTANCE_REFUSE migrations=$count"; exit 9; }
phase='anonymous'
php ops/staging-smoke.php --base-url=https://allfather-php-mvp-staging.up.railway.app --mode=anonymous >/tmp/allfather-anon.json
php -r '$x=json_decode(file_get_contents($argv[1]),true); if(($x["status"]??"")!=="SMOKE_PASS") exit(1); echo "STAGING_ANONYMOUS_PASS steps=",($x["step_count"]??"?")," failures=",($x["failures"]??"?"),PHP_EOL;' /tmp/allfather-anon.json
phase='test-tenant'
suffix="$(openssl rand -hex 5)"
tenant_key="railway-smoke-$suffix"
email="railway-smoke-$suffix@example.test"
php ops/provision-staging.php --env="$env_file" --dry-run \
 --tenant-key="$tenant_key" --workspace-key=sandbox --display-name='Synthetic Railway Acceptance' --operator-name='Staging QA' \
 --modules=P01,P02,P03,P04,P05 >/dev/null
php ops/provision-staging.php --env="$env_file" \
 --tenant-key="$tenant_key" --workspace-key=sandbox --display-name='Synthetic Railway Acceptance' --operator-name='Staging QA' \
 --modules=P01,P02,P03,P04,P05 >/tmp/allfather-provision.json
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="PROVISIONED") exit(1);echo "STAGING_SYNTHETIC_SCOPE_PROVISIONED environment=",($x["environment"]??"?"),PHP_EOL;' /tmp/allfather-provision.json
phase='runtime-invariants'
php ops/verify-runtime-invariants.php --env="$env_file" --tenant-key="$tenant_key" --workspace-key=sandbox >/tmp/allfather-invariants.json
php -r '$x=json_decode(file_get_contents($argv[1]),true); if(($x["status"]??"")!=="RUNTIME_INVARIANTS_VERIFIED") exit(1); echo "STAGING_RUNTIME_INVARIANTS_PASS external_effect_guard=",($x["workspace_external_effect_guard"]??"?")," append_only=",($x["access_evaluation_append_only"]??"?"),PHP_EOL;' /tmp/allfather-invariants.json
phase='onboard-test-login'
pw="$(openssl rand -hex 28)"
newpw="$(openssl rand -hex 28)"
printf '%s\n' "$pw" | php ops/onboard-beta-user.php --env="$env_file" --dry-run \
 --tenant-key="$tenant_key" --workspace-key=sandbox --email="$email" --display-name='Synthetic QA Operator' \
 --roles=ROLE_WORKSPACE_OPERATOR,ROLE_DEMO_VIEWER --password-stdin >/dev/null
account_possible=1
printf '%s\n' "$pw" | php ops/onboard-beta-user.php --env="$env_file" \
 --tenant-key="$tenant_key" --workspace-key=sandbox --email="$email" --display-name='Synthetic QA Operator' \
 --roles=ROLE_WORKSPACE_OPERATOR,ROLE_DEMO_VIEWER --password-stdin >/tmp/allfather-onboard.json
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="ONBOARDED"||!($x["must_change_password"]??false))exit(1);echo "STAGING_SYNTHETIC_ACCOUNT_ONBOARDED forced_password_change=YES",PHP_EOL;' /tmp/allfather-onboard.json
phase='beta-journey'
printf '%s\n%s\n' "$pw" "$newpw" | php ops/staging-smoke.php --base-url=https://allfather-php-mvp-staging.up.railway.app --mode=beta-journey --email="$email" --password-stdin >/tmp/allfather-beta.json
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="SMOKE_PASS")exit(1);echo "STAGING_BETA_JOURNEY_PASS steps=",($x["step_count"]??"?")," failures=",($x["failures"]??"?"),PHP_EOL;' /tmp/allfather-beta.json
phase='p09-browser-visual'
TEST_LOGIN_EMAIL="$email" TEST_LOGIN_PASSWORD="$newpw" node /opt/browser/p09-visual-browser.mjs
phase='monitor-boundary'
printf '%s\n' "$newpw" | php ops/staging-monitor-smoke.php --base-url=https://allfather-php-mvp-staging.up.railway.app --email="$email" --password-stdin >/tmp/allfather-monitor.json
php -r '$x=json_decode(file_get_contents($argv[1]),true);if(($x["status"]??"")!=="SMOKE_PASS")exit(1);echo "STAGING_MONITOR_BOUNDARY_PASS steps=",($x["step_count"]??"?")," failures=",($x["failures"]??"?"),PHP_EOL;' /tmp/allfather-monitor.json
unset pw newpw
phase=completed
echo "STAGING_AUTH_HTTP_ACCEPTANCE_PASS live_actions=NONE"
