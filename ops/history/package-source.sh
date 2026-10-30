#!/usr/bin/env bash
# RT-02 source-only artifact; no database, network, secrets or deployment.
set -euo pipefail
umask 077

fail() { printf 'RT02_SOURCE_ARCHIVE_REFUSED: %s\n' "$1" >&2; exit 3; }
[[ $# -eq 1 && "$1" == --output=* ]] || fail "EXACT_OUTPUT_ARGUMENT_REQUIRED"
output="$(printf '%s' "$1" | sed 's/^--output=//')"
[[ "$output" == /* && "$output" != *$'\n'* && "$output" != *$'\r'* ]] ||
  fail "ABSOLUTE_OUTPUT_PATH_REQUIRED"

repo_root="$(cd "$(dirname "$0")/../.." && pwd -P)"
[[ -d "$repo_root/.git" || -f "$repo_root/.git" ]] ||
  fail "VERSIONED_GIT_WORKTREE_REQUIRED"
git -C "$repo_root" rev-parse --is-inside-work-tree 2>/dev/null | grep -qx true ||
  fail "NOT_GIT_SOURCE"
[[ -z "$(git -C "$repo_root" status --porcelain --untracked-files=no)" ]] ||
  fail "TRACKED_WORKTREE_NOT_CLEAN"

while IFS= read -r name; do
  case "$name" in
    .env.example) ;;
    .env|.env.*|*/.env|*/.env.*|*.pem|*.p12|*.key|*.age|*.sqlite|*.dump|*.db|*.bak|*.secret)
      fail "FORBIDDEN_TRACKED_SECRET_OR_BACKUP_PATH" ;;
    storage/evidence/*|storage/private/*|storage/backups/*|storage/runtime/*|*/node_modules/*|*/vendor/*)
      fail "FORBIDDEN_TRACKED_RUNTIME_PATH" ;;
  esac
done < <(git -C "$repo_root" ls-tree -r --name-only HEAD)

parent="$(dirname "$output")"
[[ -d "$parent" && ! -L "$parent" ]] || fail "PRIVATE_OUTPUT_PARENT_MISSING"
parent_real="$(cd "$parent" && pwd -P)"
[[ "$parent_real" != "$repo_root" && "$parent_real" != "$repo_root/"* ]] ||
  fail "OUTPUT_MUST_BE_OUTSIDE_REPOSITORY"
[[ "$parent_real" != */public_html && "$parent_real" != */.git ]] ||
  fail "OUTPUT_PARENT_NOT_PRIVATE"
[[ ! -e "$output" && ! -L "$output" ]] || fail "OUTPUT_ALREADY_EXISTS"
[[ ! -e "$output.sha256" && ! -L "$output.sha256" ]] ||
  fail "CHECKSUM_ALREADY_EXISTS"

mode="$(stat -c '%a' "$parent_real")" || fail "CANNOT_INSPECT_PARENT_MODE"
[[ "$mode" =~ ^[0-7]{3,4}$ ]] || fail "PARENT_MODE_INVALID"
(( (8#$mode & 077) == 0 )) || fail "OUTPUT_PARENT_NOT_OWNER_ONLY"

head="$(git -C "$repo_root" rev-parse --verify HEAD)"
tree="$(git -C "$repo_root" rev-parse --verify 'HEAD^{tree}')"
[[ "$head" =~ ^[a-f0-9]{40}$ && "$tree" =~ ^[a-f0-9]{40}$ ]] ||
  fail "GIT_IDENTITY_INVALID"

# Strict tracked-source allowlist. No runtime evidence is archived.
paths=(
  .github .dockerignore .env.example .gitignore .htaccess
  BOOTSTRAP.md Dockerfile PROJECT_BOUNDARY.md README.md UPSTREAM_AUTHORITY.md
  app composer.json database deployment-evidence.json docs frontend ops
  public_html storage/README.md storage/.htaccess tests upstream.lock.json worker
)
tmp="$parent_real/.rt02-source-archive.$$.tmp"
tmp_sha="$parent_real/.rt02-source-archive.$$.sha256.tmp"
trap 'rm -f -- "$tmp" "$tmp_sha"' EXIT
(
  set -o noclobber
  git -C "$repo_root" archive --format=tar --prefix="allfather-source/" \
    "$head" -- "${paths[@]}" | gzip -n > "$tmp"
) || fail "GIT_SOURCE_ARCHIVE_FAILED"
[[ -s "$tmp" ]] || fail "EMPTY_SOURCE_ARCHIVE"

digest="$(sha256sum "$tmp" | awk '{print $1}')"
[[ "$digest" =~ ^[a-f0-9]{64}$ ]] || fail "ARCHIVE_DIGEST_UNAVAILABLE"
printf '%s  %s\n' "$digest" "$(basename "$output")" > "$tmp_sha"
chmod 0600 "$tmp" "$tmp_sha"
[[ ! -e "$output" && ! -e "$output.sha256" ]] ||
  fail "OUTPUT_COLLISION_BEFORE_COMMIT"
# Same-directory hard-link publication is atomic and never overwrites another file.
ln -- "$tmp" "$output" || fail "OUTPUT_WRITE_FAILED"
if ! ln -- "$tmp_sha" "$output.sha256"; then
  rm -f -- "$output"
  fail "CHECKSUM_WRITE_FAILED"
fi
rm -f -- "$tmp" "$tmp_sha"
trap - EXIT
printf 'RT02_SOURCE_ARCHIVE_CREATED_ONLY git_commit=%s git_tree=%s archive_sha256=%s\n' \
  "$head" "$tree" "$digest"
printf 'RT02_NO_OWNER_GRANT NO_STAGING NO_PROVIDER NO_RUNTIME_MIGRATION NO_CUSTOMER_OUTPUT\n'
