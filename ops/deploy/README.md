# Deployment tooling

This directory does **not** deploy to Loopia. It prepares and validates a release; the actual copy
remains an explicit operator `rsync`/SSH act described in `docs/BETA_RUNBOOK.md` and
`docs/DEPLOYMENT_RUNBOOK.md`.

## `prepare-release.sh`

```bash
./ops/deploy/prepare-release.sh <full-40-char-sha> <output-directory>
```

Purpose: make the source of a deployment an exact Git object rather than whatever bytes happen to be
in a developer checkout.

It:

1. verifies the full commit SHA;
2. refuses an existing output directory so releases cannot be mixed;
3. exports tracked bytes with `git archive`;
4. runs the pinned frontend clean install, tests, production build and public staging inside that
   fresh exported tree;
5. prints only release ref + prepared local path.

It never calls `ssh` or `rsync`, never reads host secrets, and never changes a branch.

## `staging-rsync-excludes.txt`

The root staging sync is destructive (`--delete`), so the exclusions are part of the deployment
contract rather than copy/paste suggestions.

The broad env rules protect private host configuration, while the tracked non-secret template is
release-owned. The root sync therefore MUST order the rules this way:

```text
--include='/.env.example'
--exclude-from=<prepared-release>/ops/deploy/staging-rsync-excludes.txt
```

Rsync evaluates these filter rules in order. The explicit include is what lets `.env.example`
arrive while the file's `/.env` and `/.env.*` rules keep the real runtime, qualification and any
future host env files outside destructive `--delete` ownership.

After a real root sync, use the runbook's `rsync -naci` checksum-only comparison and require empty
output for `.env.example`. A separate SCP/copy is not an acceptable repair because it would hide a
broken deployment filter.

In particular:

```text
/storage/***
```

means root deployment never owns runtime storage. It cannot delete `storage/request.log`, runtime
evidence or future private state, and it cannot upload ignored runtime files from a developer
machine. The committed `storage/.htaccess` and `storage/README.md` are copied separately without
`--delete`.

The Operator Console generated stage is also excluded because it has its own manifest-last publish
protocol and deliberately retains older content hashes during a release.

## `preflight.php`

Run it from the **prepared release tree**:

```bash
php <prepared-release>/ops/deploy/preflight.php \
  --env=<local untracked staging-preflight env> \
  --release-ref=<full SHA> \
  --mode=staging
```

The local preflight env is not permission to install secrets on Loopia. It stays on the operator
machine until the non-waivable web-PHP isolation gate closes.

Preflight checks:

- staging environment and bare HTTPS origin;
- exact `APP_RELEASE_REF`;
- Loopia staging DB fields, including `DB_PASSWORD`;
- valid 32-byte `APP_MASTER_KEY` without printing it;
- monitored support contact;
- scheduler credentials absent;
- strict Operator Console manifest/assets;
- closed public/private layout;
- inert authority/evidence posture;
- empty scheduler handler registries;
- no production live transport/resolver wiring;
- source fingerprint of the prepared running tree.

`READY_FOR_INERT_STAGING_COPY` means the release is internally consistent. It authorizes neither
secret placement nor host deployment.

### Source fingerprint

`SourceFingerprint` hashes runtime code using POSIX-normalized relative paths, so a tree preflighted
on Windows compares correctly with the same bytes on Linux/Loopia.

For `public_html/assets/operator-console`, release identity is the current `manifest.json` plus the
files that manifest references. Safe historical hashed JS/CSS/SVG assets deliberately retained by
the manifest-last protocol do not alter the fingerprint. `verify-stand-down.php` separately rejects
unsafe unmanifested public entries.

## After copy

Real-host proof belongs to the later gates:

- cross-site isolation;
- host capability probe;
- migrations;
- strict suite on the **qualification database**;
- `ops/verify-runtime-invariants.php` on the explicit internal TEST runtime scope;
- staging smoke;
- request-ID lookup;
- restore/rollback drill.

None of these is production launch authority.
