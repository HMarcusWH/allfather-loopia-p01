#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "usage: $0 <full-40-char-release-sha> <output-directory>" >&2
  exit 2
}

[ "$#" -eq 2 ] || usage
release="$1"
output="$2"

if [[ ! "$release" =~ ^[0-9a-f]{40}$ ]]; then
  echo "prepare-release: release must be a full 40-character lowercase git SHA" >&2
  exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

resolved="$(git rev-parse --verify "${release}^{commit}")"
if [ "$resolved" != "$release" ]; then
  echo "prepare-release: requested SHA does not resolve exactly" >&2
  exit 3
fi

case "$output" in
  /*) ;;
  *) output="$root/$output" ;;
esac

if [ -e "$output" ]; then
  echo "prepare-release: output already exists; refusing to mix releases: $output" >&2
  exit 4
fi

mkdir -p "$output"
cleanup=1
trap 'if [ "$cleanup" -eq 1 ]; then rm -rf "$output"; fi' EXIT

# Export TRACKED bytes from the exact Git object. Local modifications, ignored files, old build
# output and developer runtime storage are not part of this tree and therefore cannot enter the
# release package.
git archive "$release" | tar -x -C "$output"

pushd "$output/frontend/console" >/dev/null
expected_node="$(tr -d '[:space:]' < .node-version)"
actual_node="$(node --version)"
if [ "$actual_node" != "$expected_node" ]; then
  echo "prepare-release: Node mismatch: expected $expected_node, got $actual_node" >&2
  exit 5
fi
npm ci
npm test
npm run build
npm run stage
popd >/dev/null

cleanup=0
trap - EXIT

printf '{"status":"PREPARED_RELEASE","release_ref":"%s","path":"%s"}\n' "$release" "$output"
