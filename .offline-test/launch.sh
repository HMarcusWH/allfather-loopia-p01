#!/usr/bin/env bash
# Helper lives outside the qualified Git object. Both DBs are disposable.
set -Eeuo pipefail
umask 077
expected_commit='a04109c267eae1371b4ea1c261cdf611322d14fa'
expected_tree='c40155cf3b339919a3d967d2b4f46780824223bb'
phase='preflight'
exit_report() { code=$?; echo "OFFLINE_QUALIFIER_EXIT status=$code phase=$phase"; }
trap exit_report EXIT
[ -n "$DB_HOST_106" ] && [ -n "$DB_NAME_106" ] && [ -n "$DB_USER_106" ] && [ -n "$DB_PASSWORD_106" ] || { echo 'REFUSE: missing private disposable DB variables'; exit 2; }
case "$DB_HOST_106" in *.railway.internal) ;; *) echo "REFUSE: 10.6 host must be Railway private DNS"; exit 2;; esac
[ "$DB_NAME_106" = 'allfather_qualify_106' ] || { echo "REFUSE: test DB name mismatch"; exit 2; }
cd /srv/allfather
cp .offline-test/original-Dockerfile Dockerfile
cp .offline-test/original-dockerignore .dockerignore
rm -rf .offline-test
git init -q .
git config core.fileMode true
phase='exact-object-validation'
count=0
while IFS=$'\t' read -r mode sha path; do
  [ -n "$path" ] || continue
  [ -f "$path" ] || { echo "SOURCE_ERROR missing path $path"; exit 10; }
  actual="$(git hash-object -w "$path")"
  [ "$actual" = "$sha" ] || { echo "SOURCE_ERROR blob mismatch $path"; exit 11; }
  git update-index --add --cacheinfo "$mode,$sha,$path"
  count=$((count+1))
done < /opt/allfather-tracked-manifest.tsv
actual_tree="$(git write-tree)"
[ "$actual_tree" = "$expected_tree" ] || { echo "SOURCE_ERROR tree expected=$expected_tree actual=$actual_tree"; exit 12; }
commit_raw="$(mktemp)"
printf 'tree %s\nparent %s\nauthor Marcus Hermansson <marcus@hmwh.se> %s +0200\ncommitter Marcus Hermansson <marcus@hmwh.se> %s +0200\n\nfix(ui): keep Account tables inside mobile grid with local horizontal scroll' \
  "$expected_tree" 'd2c8a4a8357960896aa31e90ba91d453035df4e8' '1791427192' '1791427192' > "$commit_raw"
actual_commit="$(git hash-object -t commit -w "$commit_raw")"
rm -f "$commit_raw"
[ "$actual_commit" = "$expected_commit" ] || { echo "SOURCE_ERROR commit expected=$expected_commit actual=$actual_commit"; exit 13; }
git update-ref refs/heads/qualification "$expected_commit"
git symbolic-ref HEAD refs/heads/qualification
[ "$(git rev-parse HEAD)" = "$expected_commit" ] || exit 14
[ -z "$(git status --porcelain)" ] || { echo "SOURCE_ERROR recreated source tree is dirty"; git status --porcelain; exit 15; }
echo "EXACT_SOURCE_OBJECTS_VERIFIED commit=$expected_commit tree=$expected_tree blobs=$count"
phase='local-database-setup'
service mariadb start >/dev/null
local_pwd="$(openssl rand -hex 20)"
mariadb --protocol=socket -uroot -e "CREATE DATABASE allfather_qualify_1011 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb --protocol=socket -uroot -e "CREATE USER 'allfather_qualifier'@'127.0.0.1' IDENTIFIED BY '$local_pwd'; GRANT ALL PRIVILEGES ON allfather_qualify_1011.* TO 'allfather_qualifier'@'127.0.0.1'; FLUSH PRIVILEGES;"
tmpdir="$(mktemp -d)"
chmod 0700 "$tmpdir"
{
 echo 'APP_ENV=testing'
 printf 'DB_HOST=%s\n' "$DB_HOST_106"
 echo 'DB_PORT=3306'
 echo 'DB_NAME=allfather_qualify_106'
 printf 'DB_USER=%s\n' "$DB_USER_106"
 printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD_106"
} > "$tmpdir/10.6.env"
{
 echo 'APP_ENV=testing'
 echo 'DB_HOST=127.0.0.1'
 echo 'DB_PORT=3306'
 echo 'DB_NAME=allfather_qualify_1011'
 echo 'DB_USER=allfather_qualifier'
 printf 'DB_PASSWORD=%s\n' "$local_pwd"
} > "$tmpdir/10.11.env"
chmod 0600 "$tmpdir"/*.env
unset DB_PASSWORD_106 local_pwd
phase='official-local-qualification'
set +e
bash ops/ci/qualify-release.sh local \
  --ref="$expected_commit" \
  --out="$tmpdir/qualification" \
  --env-10.6="$tmpdir/10.6.env" \
  --env-10.11="$tmpdir/10.11.env"
rc=$?
set -e
if [ -s "$tmpdir/qualification/release-qualification-receipt.json" ]; then
  echo 'OFFICIAL_LOCAL_RECEIPT_BEGIN'
  cat "$tmpdir/qualification/release-qualification-receipt.json"
  echo 'OFFICIAL_LOCAL_RECEIPT_END'
fi
echo "OFFICIAL_LOCAL_RUN_EXIT=$rc"
exit "$rc"
