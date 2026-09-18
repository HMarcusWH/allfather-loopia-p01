#!/usr/bin/env bash
set -euo pipefail

# Gate E.2 — HTTP private-path refusal, mechanically enforced.
#
# WHY THIS EXISTS rather than a `curl` loop that prints a status code per line for an operator to
# read: a real-host run against staging.allfatherbi.com (2026-09-18) answered 503 for every private
# path, and a transcript of codes is exactly the artifact a skim reads as "some numbers, looks fine"
# instead of catching that 503 is neither 403 nor 404. The gate's own exit code is the verdict here,
# not a human's read of a table.
#
# THE RULE, for every private path in $PRIVATE_PATHS:
#   403 or 404                                                              -> PASS
#   anything else                                                           -> FAIL
# with exactly ONE additional accepted outcome, and only for `/.env`:
#   302 whose Location PARSES to scheme=https, host=roadblock.loopia.com,
#   and either no port or the default 443                                   -> PASS
# A redirect on any OTHER path, or a redirect on /.env that does not parse to exactly that host, is
# an arbitrary redirect and FAILS — see `docs/BETA_RUNBOOK.md` §E.2 and `docs/SECURITY_BOUNDARY.md`
# §5.1.
#
# THE HOST IS COMPARED AS A PARSED COMPONENT, NEVER BY STRING MATCHING ON THE LOCATION. An earlier
# revision of this script anchored a regex on the raw string
# (`^https://roadblock\.loopia\.com(/.*)?$`), which rejected the ACTUAL observed redirect —
# `https://roadblock.loopia.com?id=430057&host=staging.allfatherbi.com` has a `?` immediately after
# the host, not a `/`, so the anchor's `(/.*)?$` alternative never matched. A string anchor also
# cannot, by construction, distinguish a host from userinfo: `https://roadblock.loopia.com@evil.example/`
# starts with the exact right substring and is a completely different host. `parse_host_component`
# below shells out to PHP's `parse_url()` — already a first-class dependency of every other script
# in `ops/` — specifically because per-component URL parsing is a solved, tested problem and
# hand-rolled regex parsing of URLs is exactly how the previous bug happened.
#
# THE REDIRECT IS NEVER FOLLOWED. There is no `-L`/`--location` anywhere in this file, and
# `--max-redirs 0` makes that explicit rather than merely implicit in an absent flag — a later edit
# that added `-L` for some other reason would still be capped at zero redirects here.

usage() {
  echo "usage: $0 --base-url=https://<domain>" >&2
  echo "       $0 --self-test    # exercises the classifier with no network access" >&2
  exit 2
}

ROADBLOCK_HOST='roadblock.loopia.com'

PRIVATE_PATHS=(
  /.env
  /app/autoload.php
  /database/migrations/001_identity_scope.sql
  /storage/request.log
  /worker/tick.php
  /ops/migrate.php
  /frontend/console/package.json
  /tests/run.php
  /docs/SECURITY_BOUNDARY.md
  /composer.json
  /upstream.lock.json
  /deployment-evidence.json
  /BOOTSTRAP.md
  /PROJECT_BOUNDARY.md
  /UPSTREAM_AUTHORITY.md
  /README.md
)

# is_exact_roadblock_redirect LOCATION -> exit 0 if LOCATION parses to exactly
# scheme=https, host=$ROADBLOCK_HOST, and either no port or the default 443 for https; exit 1
# otherwise (including when LOCATION fails to parse as a URL at all).
#
# PARSED, NOT MATCHED. `parse_url()` separates userinfo from host (`user@host` — the host is what
# follows `@`, and a look-alike placed BEFORE the `@` is not the host at all), recognises a
# scheme-relative URL (`//host/...`) as carrying NO scheme rather than inheriting one, and reports an
# explicit port only when one was written — so a bare host is correctly treated as the default port
# for its scheme rather than as "no port restriction". Every comparison below is against a returned
# component, by `===`, never a substring or prefix test against the raw string.
is_exact_roadblock_redirect() {
  local location="$1"
  php -r '
    $parts = parse_url($argv[1] ?? "");
    if (!is_array($parts)) {
        exit(1);
    }
    $scheme = $parts["scheme"] ?? "";
    $host = $parts["host"] ?? "";
    $port = $parts["port"] ?? null;
    $ok = $scheme === "https" && $host === $argv[2] && ($port === null || $port === 443);
    exit($ok ? 0 : 1);
  ' -- "$location" "$ROADBLOCK_HOST"
}

# classify STATUS LOCATION PATH -> prints exactly PASS or FAIL on stdout.
#
# `LOCATION` is whatever curl's `%{redirect_url}` reported: empty for a non-redirect response, and
# the absolute URL the response's `Location` resolves to otherwise. `PATH` decides whether the
# roadblock exception is even considered — it applies to `/.env` and nothing else. `STATUS` must be
# exactly `302`; no other 3xx is in the accepted set, to `/.env` or anywhere else.
classify() {
  local status="$1" location="$2" path="$3"
  if [[ "$status" == "403" || "$status" == "404" ]]; then
    echo PASS
    return
  fi
  if [[ "$path" == "/.env" && "$status" == "302" ]] && is_exact_roadblock_redirect "$location"; then
    echo PASS
    return
  fi
  echo FAIL
}

if [[ "${1:-}" == "--self-test" ]]; then
  failures=0
  check() {
    local status="$1" location="$2" path="$3" expected="$4" got
    got="$(classify "$status" "$location" "$path")"
    if [[ "$got" != "$expected" ]]; then
      echo "SELF-TEST FAIL: classify(status=$status, location='$location', path=$path) = $got, expected $expected" >&2
      failures=$((failures + 1))
    fi
  }

  # 403/404 pass on every path, private or not.
  check 403 '' /app/autoload.php PASS
  check 404 '' /composer.json PASS
  check 403 '' /.env PASS
  check 404 '' /.env PASS

  # 200 and 503 always fail, including on /.env — the bug this script exists to catch.
  check 200 '' /app/autoload.php FAIL
  check 503 '' /app/autoload.php FAIL
  check 200 '' /.env FAIL
  check 503 '' /.env FAIL

  # the one accepted exception: 302 to the exact roadblock host, on /.env only.
  check 302 'https://roadblock.loopia.com/block?reason=env' /.env PASS
  check 302 'https://roadblock.loopia.com' /.env PASS
  # THE ACTUAL OBSERVED REDIRECT, verbatim (2026-09-18): a query string with no path at all. The
  # string-anchor regex this script used to carry rejected exactly this, which is why the check is
  # component-parsed now rather than string-matched.
  check 302 'https://roadblock.loopia.com?id=430057&host=staging.allfatherbi.com' /.env PASS

  # arbitrary redirect target on /.env still fails.
  check 302 'https://evil.example/' /.env FAIL

  # a host that merely STARTS the same way is a different host and fails — the prefix trap.
  check 302 'https://roadblock.loopia.com.evil.example/' /.env FAIL

  # userinfo placed before the real host: the parsed HOST is evil.example, not roadblock.loopia.com.
  check 302 'https://roadblock.loopia.com@evil.example/' /.env FAIL

  # plain HTTP claiming the right host fails — scheme is part of the equality check.
  check 302 'http://roadblock.loopia.com/' /.env FAIL

  # the right host named only inside the QUERY of a different origin is not the host.
  check 302 'https://evil.example/?next=https://roadblock.loopia.com' /.env FAIL

  # scheme-relative: parse_url reports no scheme at all, which is not "https".
  check 302 '//roadblock.loopia.com/' /.env FAIL

  # a non-default port is a different endpoint even on the right host and scheme.
  check 302 'https://roadblock.loopia.com:444/' /.env FAIL
  # the default HTTPS port, written explicitly, is unaffected — this is the same endpoint.
  check 302 'https://roadblock.loopia.com:443/' /.env PASS

  # the exception is /.env ONLY — the identical redirect on any other path still fails.
  check 302 'https://roadblock.loopia.com/' /app/autoload.php FAIL
  check 302 'https://roadblock.loopia.com/' /composer.json FAIL

  # no other 3xx is accepted, even to the right host on /.env.
  check 301 'https://roadblock.loopia.com/' /.env FAIL
  check 307 'https://roadblock.loopia.com/' /.env FAIL
  check 308 'https://roadblock.loopia.com/' /.env FAIL

  if [[ "$failures" -eq 0 ]]; then
    echo "self-test: OK (24 cases)"
    exit 0
  fi
  echo "self-test: $failures case(s) failed"
  exit 1
fi

base_url=""
for arg in "$@"; do
  case "$arg" in
    --base-url=*) base_url="${arg#--base-url=}" ;;
    *) usage ;;
  esac
done

# REFUSED, NOT UPGRADED — the same posture as `ops/staging-smoke.php`. A base URL that was silently
# rewritten from http:// would report a gate result against TLS nobody confirmed.
if [[ -z "$base_url" || ! "$base_url" =~ ^https:// ]]; then
  echo "verify-e2-private-path-refusal: --base-url must be an https:// URL" >&2
  usage
fi
base_url="${base_url%/}"

overall=0
for path in "${PRIVATE_PATHS[@]}"; do
  response="$(curl -sS -o /dev/null --max-redirs 0 -w '%{http_code} %{redirect_url}' "${base_url}${path}")"
  status="${response%% *}"
  location="${response#* }"
  verdict="$(classify "$status" "$location" "$path")"
  printf '%-45s status=%-4s location=%-55s %s\n' "$path" "$status" "$location" "$verdict"
  if [[ "$verdict" == "FAIL" ]]; then
    overall=1
  fi
done

exit "$overall"
