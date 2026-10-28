# CR-03 — Robots Exclusion Protocol / governed acquisition boundary

**Dated:** 28 October 2026. **Package:** BUILD-05 / CR-03, Wave 02. **Source trace:** Crawler PR-06. **Dependencies:** BF-01 (merged). **Authority state:** `BUILT_INERT`, candidate-only; **no live rights or native robots transport**.

## Decision and exact precedence

1. **Existing owner authority (unchanged):** SourcePolicy governs source-card use, licensing, current terms/robots check, allowed URL scope, cadence, retention and reason codes. M06/M01 remain the only native execution admission path. EgressGuard remains the only network security and redirect/SSRF boundary. These files are reused, not replaced.
2. **Distinct robots acquisition:** A source card that allows `https://example.org/pricing` does **not** permit an implicit `GET https://example.org/robots.txt`. Acquiring robots bytes requires its own reviewed source-card, exact root robots URI, purpose, budget, M06/M01 admission and EgressGuard scope/redirect checks. This package has no such native admission. `DisabledRobotsAcquisition` always returns `CR03_DISTINCT_ROBOTS_REQUEST_NOT_ADMITTED`.
3. **Offline interpretation only:** `RobotsPolicyReader` consumes caller-*supplied fixture* `RobotsPolicyBytes.v1` plus strictly scoped `AcquisitionScope.v1`. Its result `RobotsDisposition.v1` is a local policy *interpretation* and cannot mint source rights. Its only successful transport in this package is the CLI-only `tests/support/Cr03RobotsFixtureAcquisition.php` readback from supplied test bytes; `networkAttempts=0` in every fixture.
4. **Separate dispositions:** RFC result `ALLOW` or 4xx `UNAVAILABLE_MAY_ALLOW` still produces Allfather `HOLD_NOT_AUTHORIZED`. An RFC `DISALLOW` or unreachable 5xx produces `DENY_ROBOTS`. Unknown/not-fetched/expired stays HOLD. Robots.txt is not authentication, a source licence or a customer release.
5. **No new persistent schema, grant, provider call, crawler, scheduled retry loop, browser or external send.** Current P01 Capture and MonitorFactory are deliberately not modified.

## RFC 9309 implementation and conservative decisions

See [IETF RFC 9309](https://www.rfc-editor.org/rfc/rfc9309.html), §2.2 (groups, rules and special characters), §2.3.1 (availability, redirects) and §2.4–2.5 (caching and limits).

- Bounded UTF-8 `text/plain` input, up to **512 KiB**, which exceeds RFC 9309's **500 KiB** required minimum parser limit. At most 12,000 lines, 8,192 bytes per line, 1,024 groups, 8,192 rules, 2,048 bytes per rule, 16 wildcards/rule, and 4,096 normalized path bytes. Resource-bound breaches refuse rather than accepting a partial allow.
- Product-token match is case insensitive. Multiple groups that match the named product token are combined, with `User-agent: *` fallback only when no exact group matches. Rules before a user-agent are ignored. Unknown directives do not terminate a group. Malformed individual lines are ignored with warnings, while malformed encoding, bad source digest, invalid byte ceiling or unsupported scope refuse.
- Paths compare case sensitively, from their first octet. Percent-encoded unreserved ASCII is decoded, reserved/non-ASCII bytes are normalized to uppercase percent escapes. RFC wildcards `*` and terminal anchor `$` are implemented with deterministic literal scanning (no backtracking regex). The rule with the most literal **octets** wins. Equal-specificity ALLOW beats DISALLOW. Empty disallow does not prohibit anything; an unmatched group defaults to RFC ALLOW, still Allfather HOLD.
- `FETCHED` with 200/UTF-8 text/plain can be interpreted if the supplied receipt is fresh. A cached rule is fresh through 24 hours inclusive, but older than 24 hours yields `UNKNOWN / ROBOTS_POLICY_EXPIRED` and HOLD. **No automatic refresh or stale-allow exception** is implemented; source owner policy can be stricter.
- `UNAVAILABLE` 400–499 (including 404) has RFC status `UNAVAILABLE_MAY_ALLOW`, but Allfather holds pending native owner checks; **429** is additionally held for owner-scheduled retry. `UNREACHABLE` 500–599 or network failure has RFC complete disallow and Allfather DENY. `NOT_FETCHED` is UNKNOWN and HOLD. The 30-day RFC escape from unreachable is deliberately **not** exercised by this stricter candidate.
- Supplied source-cadence (minimum interval), retry-after deadline, remaining attempt budget and remaining byte budget are checked **deterministically**; no timer, sleeper, network fetch, budget mutation or automatic retry is created. Live enforcement of these controls remains in the existing native capture path.
- RFC recommends following five robots redirects, but following them could escape source-card/host authority. This candidate does **not fetch or follow even one**; a future native adapter must obtain separate approval for every exact redirect target under the existing EgressGuard path ceiling. A page-scoped source card cannot authorize this by inference.

### Contract v1 and evidentiary limits

`RobotsPolicyBytes.v1` contains source/tenant/workspace/card identities, exact origin + canonical HTTPS `/robots.txt` URI, response status, timestamps, UTF-8 bytes, and a matching SHA-256 (or a typed absence). The reader returns only the **policy digest**, the target path digest, agent-group/rule selection metadata, freshness, RFC disposition, owner policy holds, and a versioned deterministic decision digest. The untrusted policy body and target path are **not returned**, nor is an owner approval invented.

`AcquisitionScope.v1` contains the exact path-scoped P01 card permission, target URL, named crawler product token, purpose `OFFLINE_ROBOTS_INTERPRETATION`, deterministic evaluation clock, capture cadence, retry-after and budgets. It explicitly requires `native_execution_admitted=false`, `owner_source_use=NOT_ADMITTED`; passing a forged affirmative flag refuses. Even a syntactically valid scope never grants a real read.

`RobotsDisposition.v1` is a non-primary, local derived readback; no new table or M05 raw artifact owner is installed. A later package may persist only with explicit schema/retention/recovery/owner authority. All confidence/rights assertions remain outside this parser.

### Test and integration guide

```sh
php tests/run.php --layer=foundation --filter=cr03_robots_boundary --strict
php tests/run.php --layer=unit --filter=cr03_robots_policy --strict
php tests/run.php --layer=security --filter=cr03_robots_security --strict
```

Fixtures in `tests/fixtures/robots/cr03_golden.json` cover longest and tied rules, multiple UA groups, wildcard fallback, anchors, query strings, percent-encoded and Unicode paths, invalid lines, UTF-8 errors, 4xx/429/5xx, stale freshness, 500+ KiB parser capacity, owner throttle/budget refusal and zero network. The native default port is refused for **every** input.

**Build-hygiene merge gate:** existing five exact GitHub checks `foundation`, `mariadb 10.6`, `mariadb 10.11`, `package integrity`, `code qualification` all SUCCESS on the current PR test merge; no failure/skip/absent check is a PASS. CR-03 is `BUILT_INERT` only; final end-to-end and security qualification occur at V01/V03/V04 after RT-02. U01–U10 and customer/production approval remain separate.
