# CI-FIX-06 — public repository exposure and remediation boundary

**Dated:** 2026-10-26. **Repository:** `HMarcusWH/allfather-loopia-p01` (public). **Baseline before this PR:** `main@c89efe053fb458a523b9a02c874c3aef18467ce5`. This is a code/privacy audit, not a new deployment, release, execution grant or production approval.

## Verified source disclosure inventory

- Four reviewed `docs/decisions/*.json` records are in the public Git tree. They intentionally carry owner-decision and authorization metadata, including tenant/workspace identifiers, purpose, policy/prompt digests and/or bounded grant digests. These values are **publicly exposed metadata**, not automatically a provider API key. The scanner checks their contents for actual token patterns and requires explicit review for any additional decision file.
- The four decisions have runtime meaning: `OwnerDecisionRegistry` re-reads them. Deleting, editing or excluding them merely to obtain CI/deployment readiness could revoke or alter admission. This PR neither touches them nor authorizes any model or source-capture call.
- `.env.example` leaves credential-bearing fields blank. `.gitignore` and `.dockerignore` protect untracked runtime `.env*` and private-ops material, but ignore rules do **not** unpublish tracked content or remove past commits.

**Audit limitations:** Public contact email addresses, named persons, UUIDs, hashes and disclosure of business/customer association require contextual human privacy review; a heuristic token scan cannot declare them harmless or consented. Neither scanning current files nor deleting a path establishes historical secrecy. Do not claim full GDPR/privacy clearance or historical credential absence from a green CI badge.

## Mandatory current-tree qualification

`app/Ops/PublicExposureAudit.php` is a read-only, dependency-free heuristic with stable finding codes; it never outputs a matched value, source line, private key, owner-decision contents or credential digest. Its automated foundation test `tests/foundation/public_source_exposure_boundary.php` checks:
- Source-export files, including the four expected decision records; unsafe paths, unreviewed decision filenames, nonempty secret values in the environment template, high-confidence provider/token formats, private PEM markers, unreadable/binary/oversized sources.
- In a real GitHub checkout, **every indexed Git-tracked file**, including accidentally tracked private paths. In a deployment-shaped root-rsync copy (no Git index), it inspects the copy but deliberately excludes the host's untracked `.env`, preserved `storage` and private operator configuration.
- Synthetic negative token, key, path and unreadable/large-file cases, assembled at runtime so no usable token literal is stored in this source.

CI automatically runs this test under the existing `foundation` qualification job. It is a source integrity control, not the `INERT_COPY`, `MANUAL_PILOT`, customer or production authority gate.

## Separate operator historical audit

From a local repository with **all relevant refs and reachable objects fetched**:

```bash
php ops/security/audit-public-repository.php --scope=tree
php ops/security/audit-public-repository.php --scope=index
php ops/security/audit-public-repository.php --scope=history
```

`history` uses local `git rev-list --objects --all` and inspectable Git blob contents; it checks old versions of files that may no longer exist in the current tree. It exits nonzero for findings, unreadable blobs, large uninspected content or a traversal limit. It reports only finding codes and paths. It does **not** rewrite Git history or call external providers.

**Historical completeness is NOT automatically established**: shallow clones, Git objects unreachable by local refs, deleted upstream refs or refs that have not been fetched are outside this observation. The history mode now refuses a shallow or unknown local repository depth with `HISTORY_INCOMPLETE`; the JSON `complete` flag refers only to the *local traversal*, explicitly names `completeness_scope=LOCAL_REACHABLE_GIT_REFS_ONLY`, and always says `remote_ref_coverage=UNVERIFIED` until separately evidenced. The historical command is intentionally opt-in and not asserted as executed in CI. Treat a truncated or unavailable scan as `UNVERIFIED`. File the exact local ref inventory, observation date, examined-object count and redacted findings as private evidence; do not commit token values or raw log output.

A disposable local Git-history regression proved that a synthetic credential committed and subsequently deleted remains detectable; this does not prove the public repository's past is clean.

## Incident handling and owner boundaries

1. **High-confidence credential or private key:** privately identify owner and scope, owner-authorize revocation/rotation, inspect use and possible copies, then plan source/history remediation. Merely editing the latest commit cannot revoke historical exposure.
2. **Unsafe tracked path or history:** stop qualification, preserve private evidence, examine affected refs and releases. History rewriting is disruptive and must be separately reviewed; do not rewrite or force-push protected `main` in this PR.
3. **Owner-decision disclosure:** classify public metadata and, if necessary, design a canonical replacement/withdrawal authority path with explicit owner consent. Do not delete four existing decisions or invent narrower digests.
4. **Personal information:** review context of people, email, phone, domains, UUID associations and retained evidence; report only minimal, redacted findings. An identifier being visible is not proof disclosure is appropriate.

## Closure ledger

This PR qualifies **automated prevention for checked-in source at the exact tested Git tree** if and only if the foundation, strict MariaDB 10.6/10.11 and package CI jobs pass. It cannot claim that all historical refs were scanned or all public PII reviewed. Remain explicit about ``UNVERIFIED`` historical coverage, owner privacy review, any rotation, and host/runtime grants. Staging promotion still requires the protected GitHub PR and Railway Wait-for-CI flow in [CI-FIX-05](../ci/PROTECTED_SOURCE_PROMOTION.md); independent inert preflight remains fail-closed with the four decisions present.

Later work: source scan improvements after real false-positive analysis, historical evidence filing, and separate pinned GitHub Actions/Node runner warning cleanup.
